<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge\GenerationSync;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SubmitGeneration implements ShouldQueue {
    use Queueable;

    public int $tries = 60;

    public int $maxExceptions = 5;

    public function __construct(public Generation $generation) {
    }

    public function retryUntil(): \DateTimeInterface {
        return $this->generation->created_at->copy()->addMinutes(30);
    }

    public function handle(PixelforgeClient $client, GenerationSync $sync): void {
        $gen = $this->generation->fresh();

        if (! $gen || $gen->status !== Generation::SUBMITTING) {
            return;
        }

        $params = [
            'prompt' => $gen->prompt,
            'size' => $gen->size,
            'quality' => $gen->quality,
            'metadata' => ['generation_uuid' => $gen->uuid, 'user_id' => (string) $gen->user_id],
        ];
        if (! empty($gen->reference_images)) {
            $params['reference_images'] = $gen->reference_images;
        }

        try {
            $object = $client->createGeneration($params, $gen->uuid);
        } catch (PixelforgeException $e) {
            if ($e->isRetryable() && ! $e->isDailyLimit()) {
                $this->release(max(1, $e->retryAfter ?? 5));
                return;
            }

            $sync->failBeforeSubmit($gen, $e->errorCode ?? 'submit_failed', $e->getMessage());
            return;
        }

        $sync->apply($gen, $object);
    }

    public function failed(?Throwable $e): void {
        $gen = $this->generation->fresh();
        if ($gen && $gen->status === Generation::SUBMITTING) {
            app(GenerationSync::class)->failBeforeSubmit(
                $gen,
                'submit_failed',
                'Pixelforge was busy for too long. You were not charged; please try again.',
            );
        }
    }
}
