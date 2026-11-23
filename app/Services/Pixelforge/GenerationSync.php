<?php

namespace App\Services\Pixelforge;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Pricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GenerationSync {
    public function find(array $object): ?Generation {
        $uuid = $object['metadata']['generation_uuid'] ?? null;
        if ($uuid) {
            return Generation::where('uuid', $uuid)->first();
        }
        return isset($object['id']) ? Generation::where('provider_id', $object['id'])->first() : null;
    }

    public function apply(Generation $generation, array $object): Generation {
        return DB::transaction(function () use ($generation, $object) {
            $gen = Generation::whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if (! $gen->provider_id && isset($object['id'])) {
                $gen->provider_id = $object['id'];
            }
            if (! $gen->rates && isset($object['rates'])) {
                $gen->rates = $object['rates'];
            }
            $gen->provider_created_at ??= $this->time($object['created_at'] ?? null);
            $gen->started_at ??= $this->time($object['started_at'] ?? null);

            $incoming = $object['status'] ?? null;

            if ($gen->isFinal() || ! isset(Generation::RANK[$incoming]) || Generation::RANK[$incoming] <= Generation::RANK[$gen->status]) {
                if ($gen->status === Generation::SUCCEEDED && $incoming === Generation::SUCCEEDED && ! empty($object['image_url'])) {
                    $gen->image_url = $object['image_url'];
                    $gen->image_url_expires_at = $this->time($object['image_url_expires_at'] ?? null);
                }
                $gen->save();

                return $gen;
            }

            $gen->status = $incoming;

            if ($incoming === Generation::SUCCEEDED) {
                $gen->usage = $object['usage'];
                $gen->image_url = $object['image_url'] ?? null;
                $gen->image_url_expires_at = $this->time($object['image_url_expires_at'] ?? null);
                $gen->completed_at = $this->time($object['completed_at'] ?? null) ?? now();

                $cost = Pricing::providerCost($object['usage'], $object['rates'] ?? $gen->rates);
                $this->settle($gen, $cost, Pricing::userCharge($cost));
            }

            if ($incoming === Generation::FAILED) {
                $gen->error_code = $object['error']['code'] ?? 'generation_failed';
                $gen->error_message = $object['error']['message'] ?? null;
                $gen->completed_at = $this->time($object['completed_at'] ?? null) ?? now();
                $this->settle($gen, 0, 0);
            }

            $gen->save();

            return $gen;
        });
    }

    public function failBeforeSubmit(Generation $generation, string $code, string $message): Generation {
        return DB::transaction(function () use ($generation, $code, $message) {
            $gen = Generation::whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($gen->isFinal()) {
                return $gen;
            }

            $gen->status = Generation::FAILED;
            $gen->error_code = $code;
            $gen->error_message = $message;
            $this->settle($gen, 0, 0);
            $gen->save();

            return $gen;
        });
    }

    private function settle(Generation $gen, int $providerCost, int $charge): void {
        if ($gen->settled_at) {
            return;
        }

        $user = User::whereKey($gen->user_id)->lockForUpdate()->firstOrFail();

        CreditTransaction::create(['user_id' => $user->id, 'generation_id' => $gen->id, 'type' => CreditTransaction::RELEASE, 'amount_nanos' => $gen->hold_nanos]);
        if ($charge > 0) {
            CreditTransaction::create(['user_id' => $user->id, 'generation_id' => $gen->id, 'type' => CreditTransaction::CHARGE, 'amount_nanos' => -$charge]);
        }

        $user->balance_nanos += $gen->hold_nanos - $charge;
        $user->save();

        $gen->provider_cost_nanos = $providerCost;
        $gen->charge_nanos = $charge;
        $gen->settled_at = now();
    }

    private function time(?string $value): ?Carbon {
        return $value ? Carbon::parse($value)->utc() : null;
    }
}
