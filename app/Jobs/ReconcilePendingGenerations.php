<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge\GenerationSync;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcilePendingGenerations implements ShouldQueue, ShouldBeUnique {
    use Queueable;

    public function handle(PixelforgeClient $client, GenerationSync $sync): void {
        Generation::query()
            ->whereNotNull('provider_id')
            ->whereIn('status', [Generation::SUBMITTING, Generation::QUEUED, Generation::PROCESSING])
            ->where('updated_at', '<', now()->subSeconds(90))
            ->orderBy('id')
            ->limit(30)
            ->get()
            ->each(function (Generation $gen) use ($client, $sync) {
                try {
                    $sync->apply($gen, $client->getGeneration($gen->provider_id));
                } catch (PixelforgeException $e) {
                    report($e);
                }
            });
    }
}
