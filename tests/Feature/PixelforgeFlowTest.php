<?php

namespace Tests\Feature;

use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PixelforgeFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    private const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pixelforge.api_key' => 'pk_test',
            'pixelforge.webhook_secret' => self::SECRET,
            'pixelforge.base_url' => 'https://pixelforge.test/v1',
        ]);

        Http::fake([
            'pixelforge.test/v1/pricing' => Http::response($this->pricing()),
            'pixelforge.test/v1/generations' => fn ($request) => $this->createResponse
                ? ($this->createResponse)($request)
                : Http::response(
                    $this->object('gen_1', 'queued', $request->data()['metadata']['generation_uuid']),
                    202,
                ),
        ]);
    }

    private ?\Closure $createResponse = null;

    public function test_full_success_flow_charges_exact_cost_plus_markup_once(): void
    {
        $user = $this->userWithCredits(1);
        $gen = $this->createGeneration($user);

        $this->assertSame(Generation::QUEUED, $gen->fresh()->status);

        $usage = ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950, 'total_tokens' => 6291];
        $succeeded = $this->object('gen_1', 'succeeded', $gen->uuid, $usage);

        $this->webhook('evt_1', 'generation.succeeded', $succeeded)->assertNoContent();
        $this->webhook('evt_1', 'generation.succeeded', $succeeded)->assertNoContent();

        $gen->refresh();
        $this->assertSame(169_080_000, $gen->provider_cost_nanos);
        $this->assertSame(211_350_000, $gen->charge_nanos);
        $this->assertSame(1_000_000_000 - 211_350_000, $user->fresh()->balance_nanos);
        $this->assertSame(1, CreditTransaction::where('type', 'charge')->count());
    }

    public function test_late_processing_event_never_moves_generation_backwards(): void
    {
        $user = $this->userWithCredits(1);
        $gen = $this->createGeneration($user);
        $usage = ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 500, 'total_tokens' => 1610];

        $this->webhook('evt_2', 'generation.succeeded', $this->object('gen_1', 'succeeded', $gen->uuid, $usage));
        $this->webhook('evt_1', 'generation.processing', $this->object('gen_1', 'processing', $gen->uuid));

        $this->assertSame(Generation::SUCCEEDED, $gen->fresh()->status);
    }

    public function test_webhook_before_create_response_is_matched_by_metadata(): void
    {
        $user = $this->userWithCredits(1);
        Queue::fake();
        $this->actingAs($user)->post('/generations', ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low']);
        $gen = Generation::first();
        $this->assertNull($gen->provider_id);

        $this->webhook('evt_1', 'generation.processing', $this->object('gen_1', 'processing', $gen->uuid));

        $this->assertSame('gen_1', $gen->fresh()->provider_id);
        $this->assertSame(Generation::PROCESSING, $gen->fresh()->status);
    }

    public function test_failed_generation_is_free_and_releases_the_hold(): void
    {
        $user = $this->userWithCredits(1);
        $gen = $this->createGeneration($user);
        $failed = $this->object('gen_1', 'failed', $gen->uuid);
        $failed['error'] = ['code' => 'generation_failed', 'message' => 'Could not draw it.'];

        $this->webhook('evt_1', 'generation.failed', $failed);
        $this->webhook('evt_2', 'generation.failed', $failed);

        $this->assertSame(0, $gen->fresh()->charge_nanos);
        $this->assertSame(1_000_000_000, $user->fresh()->balance_nanos);
    }

    public function test_cannot_generate_without_enough_credits(): void
    {
        $user = User::factory()->create(['balance_nanos' => 1000]);

        $this->actingAs($user)
            ->post('/generations', ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'high'])
            ->assertSessionHasErrors('prompt');

        $this->assertSame(0, Generation::count());
    }

    public function test_overload_is_retried_with_the_same_idempotency_key(): void
    {
        $this->createResponse = fn () => Http::response(
            ['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Busy']],
            503,
            ['Retry-After' => '2'],
        );
        $user = $this->userWithCredits(1);
        Queue::fake();
        $this->actingAs($user)->post('/generations', ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low']);
        $gen = Generation::first();

        $job = (new SubmitGeneration($gen))->withFakeQueueInteractions();
        $job->handle(app(\App\Services\Pixelforge\PixelforgeClient::class), app(\App\Services\Pixelforge\GenerationSync::class));

        $job->assertReleased(delay: 2);
        $this->assertSame(Generation::SUBMITTING, $gen->fresh()->status);
        Http::assertSent(fn ($r) => $r->hasHeader('Idempotency-Key', $gen->uuid));
    }

    public function test_overload_gives_up_for_free_after_the_last_attempt(): void
    {
        $this->createResponse = fn () => Http::response(
            ['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Busy']],
            503,
            ['Retry-After' => '2'],
        );
        $user = $this->userWithCredits(1);
        Queue::fake();
        $this->actingAs($user)->post('/generations', ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low']);
        $gen = Generation::first();

        $job = (new SubmitGeneration($gen))->withFakeQueueInteractions();
        $job->job->attempts = 8;
        $job->handle(app(\App\Services\Pixelforge\PixelforgeClient::class), app(\App\Services\Pixelforge\GenerationSync::class));

        $job->assertNotReleased();
        $gen->refresh();
        $this->assertSame(Generation::FAILED, $gen->status);
        $this->assertSame(0, $gen->charge_nanos);
        $this->assertSame(1_000_000_000, $user->fresh()->balance_nanos);
    }

    public function test_rejected_prompt_fails_for_free(): void
    {
        $this->createResponse = fn () => Http::response(
            ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected.']],
            400,
        );
        $user = $this->userWithCredits(1);
        $gen = $this->createGeneration($user, '[blocked] a cat');

        $this->assertSame(Generation::FAILED, $gen->fresh()->status);
        $this->assertSame('content_policy_violation', $gen->fresh()->error_code);
        $this->assertSame(1_000_000_000, $user->fresh()->balance_nanos);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.time().',v1=deadbeef',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['id' => 'evt_x']))->assertStatus(400);
    }

    public function test_old_signature_timestamp_is_rejected(): void
    {
        $body = json_encode(['id' => 'evt_x', 'type' => 'ping', 'data' => ['object' => []]]);
        $old = time() - 3600;
        $sig = hash_hmac('sha256', "{$old}.{$body}", self::SECRET);

        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$old},v1={$sig}",
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);
    }

    public function test_money_helpers_are_exact(): void
    {
        $this->assertSame(46_125_000, Money::toNanos('0.046125000'));
        $this->assertSame('0.046125000', Money::format(46_125_000));
        $this->assertSame('$0.046125', Money::display(46_125_000));
    }

    private function userWithCredits(int $dollars): User
    {
        return User::factory()->create(['balance_nanos' => $dollars * Money::NANOS_PER_DOLLAR]);
    }

    private function createGeneration(User $user, string $prompt = 'A lighthouse on a cliff'): Generation
    {
        $this->actingAs($user)
            ->post('/generations', ['prompt' => $prompt, 'size' => '1024x1024', 'quality' => 'medium'])
            ->assertSessionHasNoErrors();

        return Generation::latest('id')->first();
    }

    private function webhook(string $eventId, string $type, array $object)
    {
        $body = json_encode(['id' => $eventId, 'object' => 'event', 'type' => $type, 'created_at' => now()->toIso8601String(), 'data' => ['object' => $object]]);
        $t = time();

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", self::SECRET),
            'HTTP_PIXELFORGE_EVENT_ID' => $eventId,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function object(string $id, string $status, string $uuid, ?array $usage = null): array
    {
        return [
            'id' => $id,
            'object' => 'generation',
            'status' => $status,
            'prompt' => 'A lighthouse on a cliff',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => ['generation_uuid' => $uuid],
            'image_url' => $status === 'succeeded' ? 'https://pixelforge.test/img.png' : null,
            'image_url_expires_at' => $status === 'succeeded' ? now()->addDay()->toIso8601String() : null,
            'usage' => $usage,
            'rates' => self::RATES,
            'error' => null,
            'created_at' => now()->toIso8601String(),
            'started_at' => null,
            'completed_at' => in_array($status, ['succeeded', 'failed']) ? now()->toIso8601String() : null,
        ];
    }

    private function pricing(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => self::RATES,
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }
}
