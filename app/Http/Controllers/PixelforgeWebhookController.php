<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\Pixelforge\GenerationSync;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationSync $sync): Response
    {
        $payload = $request->getContent();

        if (! $this->validSignature($payload, (string) $request->header('Pixelforge-Signature'))) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || empty($event['id'])) {
            abort(400, 'Malformed event.');
        }

        $object = $event['data']['object'] ?? null;

        try {
            DB::transaction(function () use ($event, $object, $sync) {
                WebhookEvent::create([
                    'event_id' => $event['id'],
                    'type' => $event['type'] ?? 'unknown',
                    'generation_provider_id' => $object['id'] ?? null,
                    'payload' => $event,
                ]);

                if (is_array($object) && ($object['object'] ?? null) === 'generation' && $generation = $sync->find($object)) {
                    $sync->apply($generation, $object);
                }
            });
        } catch (UniqueConstraintViolationException) {
        }

        return response()->noContent();
    }

    private function validSignature(string $payload, string $header): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $secret = (string) config('pixelforge.webhook_secret');

        if ($secret === '' || $timestamp === 0 || empty($parts['v1'])) {
            return false;
        }

        if (abs(time() - $timestamp) > config('pixelforge.webhook_tolerance_seconds')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', "{$timestamp}.{$payload}", $secret), $parts['v1']);
    }
}
