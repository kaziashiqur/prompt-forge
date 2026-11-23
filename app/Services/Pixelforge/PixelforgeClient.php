<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('pixelforge.base_url'), config('pixelforge.api_key'));
    }

    public function pricing(): array
    {
        return Cache::remember('pixelforge.pricing', now()->addMinutes(5), function () {
            return $this->send(fn (PendingRequest $http) => $http->get('/pricing'), auth: false);
        });
    }

    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $params));
    }

    public function getGeneration(string $id): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/generations/'.$id));
    }

    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    private function send(callable $call, bool $auth = true): array
    {
        $http = Http::baseUrl($this->baseUrl)->acceptJson()->timeout(15);

        if ($auth) {
            $http = $http->withToken((string) $this->apiKey);
        }

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            throw new PixelforgeException(0, 'connection_error', $e->getMessage());
        }

        if ($response->successful()) {
            return $response->json();
        }

        $error = $response->json('error') ?? [];

        throw new PixelforgeException(
            status: $response->status(),
            errorCode: $error['code'] ?? null,
            message: $error['message'] ?? 'Pixelforge request failed with status '.$response->status(),
            retryAfter: $response->header('Retry-After') !== '' ? (int) $response->header('Retry-After') : null,
            body: $response->json() ?? [],
        );
    }
}
