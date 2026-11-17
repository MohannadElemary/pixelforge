<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationStateService;
use App\Services\GenerationWorkerService;
use App\Services\PricingService;
use App\Services\WalletService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GenerationTest extends TestCase
{
    use RefreshDatabase;

    private array $rates = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['logging.default' => 'null']);
        config(['services.pixelforge.key' => 'test-key', 'services.pixelforge.webhook_secret' => 'whsec_test']);
        Http::preventStrayRequests();
    }

    private function generation(array $values = []): Generation
    {
        return Generation::create($values + ['user_id' => User::factory()->create()->id, 'request_id' => (string) Str::uuid(),
            'prompt' => 'Quiet forest', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => [],
            'estimate' => ['typical' => '0.07'], 'next_attempt_at' => now(),
            'provider_slot_reserved' => isset($values['provider_id']) || ($values['uncertain'] ?? false) || ($values['reconciling'] ?? false)]);
    }

    private function object(Generation $generation, string $status = 'succeeded'): array
    {
        return ['id' => 'gen_'.$generation->id, 'status' => $status, 'metadata' => ['generation_id' => $generation->id],
            'rates' => $this->rates, 'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411],
            'image_url' => 'https://example.com/image.png', 'image_url_expires_at' => now()->addDay()->toISOString(),
            'completed_at' => now()->toISOString()];
    }

    private function webhook(array $event, ?int $timestamp = null, bool $valid = true)
    {
        $body = json_encode($event);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $valid ? 'whsec_test' : 'wrong');

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t=$timestamp,v1=$signature",
        ], $body);
    }

    public function test_exact_provider_cost_markup_and_fractional_nanodollars(): void
    {
        $pricingService = app(PricingService::class);
        $result = $pricingService->cost(['input_text_tokens' => 115, 'input_image_tokens' => 1530, 'output_tokens' => 6450, 'reasoning_tokens' => 8000], $this->rates);
        $this->assertSame('0.373875000000000000', $result['provider']);
        $this->assertSame('0.467343750000000000', $result['user']);
        $fraction = $pricingService->cost(['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0], array_fill_keys(PricingService::BUCKETS, '0.000000001'));
        $this->assertSame('0.000000000000001250', $fraction['user']);
    }

    public function test_estimate_uses_dynamic_pricing_unicode_and_reference_tokens(): void
    {
        $pricing = $this->pricing();
        $pricing['rates']['output_tokens'] = '80.00';
        Http::fake(['*/pricing' => Http::response($pricing)]);
        $estimate = app(PricingService::class)->estimate(['prompt' => str_repeat('森', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.com/1', 'https://example.com/2']]);
        $this->assertSame('0.789843750000000000', $estimate['max']);
        $this->assertSame('80.00', $estimate['rates']['output_tokens']);
    }

    public function test_signatures_are_verified_before_recording_and_fresh_pings_are_deduplicated(): void
    {
        $event = ['id' => 'evt_ping', 'type' => 'ping'];
        $this->webhook($event, valid: false)->assertStatus(400);
        $this->webhook($event, time() - 301)->assertStatus(400);
        $this->webhook($event, time() + 301)->assertStatus(400);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->webhook($event)->assertNoContent();
        $this->webhook($event)->assertNoContent();
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_early_duplicate_and_out_of_order_events_bill_once_at_frozen_rates(): void
    {
        $generation = $this->generation();
        $data = $this->object($generation);
        $data['rates']['output_tokens'] = '80.00';
        $event = ['id' => 'evt_success', 'type' => 'generation.succeeded', 'data' => ['object' => $data]];
        $this->webhook($event)->assertNoContent();
        $this->webhook($event)->assertNoContent();
        $this->webhook(['id' => 'evt_processing', 'type' => 'generation.processing', 'data' => ['object' => $this->object($generation, 'processing')]])->assertNoContent();
        app(GenerationStateService::class)->apply($this->object($generation, 'queued'), $generation->id);
        $generation->refresh();
        $this->assertSame('succeeded', $generation->status);
        $this->assertSame('0.563510000000000000', $generation->provider_cost);
        $this->assertSame('0.704387500000000000', $generation->actual_cost);
        $this->assertDatabaseCount('webhook_events', 2);
    }

    public function test_failed_generations_are_free_and_final(): void
    {
        $generation = $this->generation();
        $generationStateService = app(GenerationStateService::class);
        $generationStateService->apply($this->object($generation, 'failed'));
        $generationStateService->apply($this->object($generation, 'succeeded'));
        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame('0.000000000000000000', $generation->fresh()->actual_cost);
    }

    public function test_invalid_success_is_not_acknowledged_or_partially_recorded(): void
    {
        $generation = $this->generation();
        $data = $this->object($generation);
        $data['usage'] = null;
        $this->webhook(['id' => 'evt_bad', 'type' => 'generation.succeeded', 'data' => ['object' => $data]])->assertStatus(500);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertNull($generation->fresh()->actual_cost);
    }

    public function test_provider_response_cannot_regress_an_early_successful_webhook(): void
    {
        $generation = $this->generation();
        Http::fake(function ($request) use ($generation) {
            $this->webhook(['id' => 'evt_fast', 'type' => 'generation.succeeded', 'data' => ['object' => $this->object($generation)]])->assertNoContent();

            return Http::response($this->object($generation, 'queued'), 202);
        });
        app(GenerationWorkerService::class)->process($generation->id);
        $this->assertSame('succeeded', $generation->fresh()->status);
        $this->assertSame('0.381887500000000000', $generation->fresh()->actual_cost);
    }

    public function test_network_ambiguity_reuses_the_exact_key_and_payload_then_polls_to_completion(): void
    {
        $generation = $this->generation();
        $sent = [];
        Http::fake(function ($request) use (&$sent, $generation) {
            $sent[] = ['method' => $request->method(), 'key' => $request->header('Idempotency-Key'), 'body' => $request->data()];
            if (count($sent) === 1) {
                throw new ConnectionException('Lost reply');
            }

            return Http::response($this->object($generation, count($sent) === 2 ? 'queued' : 'succeeded'), 202);
        });
        $generationWorkerService = app(GenerationWorkerService::class);
        $generationWorkerService->process($generation->id);
        $this->assertTrue($generation->fresh()->uncertain);
        $this->assertNull($generation->fresh()->actual_cost);
        $this->travel(3)->seconds();
        $generationWorkerService->process($generation->id);
        $this->assertSame($sent[0], $sent[1]);
        $this->assertSame([$generation->id], $sent[0]['key']);
        $this->travel(9)->seconds();
        $generationWorkerService->process($generation->id);
        $this->assertSame('GET', $sent[2]['method']);
        $this->assertSame('succeeded', $generation->fresh()->status);
    }

    public function test_busy_provider_respects_retry_after_and_stops_after_five_rejections(): void
    {
        $this->freezeTime();
        $generation = $this->generation();
        Http::fake(['*' => Http::response(['error' => ['code' => 'concurrency_limit_exceeded']], 429, ['Retry-After' => '45'])]);
        $generationWorkerService = app(GenerationWorkerService::class);
        $generationWorkerService->process($generation->id);
        $this->assertGreaterThanOrEqual(44, now()->diffInSeconds($generation->fresh()->next_attempt_at));
        $this->assertFalse($generationWorkerService->process($generation->id));
        for ($i = 0; $i < 4; $i++) {
            $this->travel(46)->seconds();
            $generationWorkerService->process($generation->id);
        }
        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame('0.000000000000000000', $generation->fresh()->actual_cost);
        Http::assertSentCount(5);
    }

    public function test_exhausted_ambiguous_submission_reconciles_provider_history_without_new_orders(): void
    {
        $generation = $this->generation(['attempts' => 4, 'uncertain' => true]);
        $requests = 0;
        Http::fake(function ($request) use ($generation, &$requests) {
            $requests++;
            if ($request->method() === 'POST') {
                throw new ConnectionException('Lost reply');
            }

            return Http::response(['data' => [$this->object($generation)], 'has_more' => false]);
        });
        $generationWorkerService = app(GenerationWorkerService::class);
        $generationWorkerService->process($generation->id);
        $this->assertTrue($generation->fresh()->reconciling);
        $this->assertNull($generation->fresh()->actual_cost);
        $this->travel(33)->seconds();
        $generationWorkerService->process($generation->id);
        $this->assertSame('succeeded', $generation->fresh()->status);
        $this->assertSame(2, $requests);
    }

    public function test_repeated_client_submit_is_one_record_and_history_retry_are_owner_scoped(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricing())]);
        $user = User::factory()->create();
        app(WalletService::class)->purchase($user->id, (string) Str::uuid(), '5.00');
        $input = ['prompt' => 'Test forest', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => [], 'request_id' => (string) Str::uuid()];
        $this->actingAs($user)->post('/generations', $input)->assertRedirect('/studio');
        $this->post('/generations', $input)->assertRedirect('/studio');
        $this->assertDatabaseCount('generations', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(Generation::sole()->queue_job_uuid, DB::table('jobs')->value('uuid'));
        $this->post('/generations', array_replace($input, ['prompt' => 'Changed']))->assertSessionHasErrors('prompt');
        $other = $this->generation(['needs_attention' => true]);
        $this->post('/generations/'.$other->id.'/retry')->assertNotFound();
        $this->get('/studio')->assertInertia(fn ($page) => $page->has('generations.data', 1)->where('generations.data.0.prompt', 'Test forest'));
    }

    public function test_unconfigured_service_blocks_creation_and_registration_authenticates(): void
    {
        $this->post('/register', ['name' => 'Alex', 'email' => 'alex@example.test', 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123'])->assertRedirect('/studio');
        $this->assertAuthenticated();
        config(['services.pixelforge.key' => '']);
        $this->post('/generations', ['prompt' => 'Forest', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => [], 'request_id' => (string) Str::uuid()])->assertSessionHasErrors('prompt');
        $this->assertDatabaseCount('generations', 0);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_daily_quota_stops_immediately_but_keeps_prior_ambiguity_unpriced(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'daily_limit_exceeded']], 429)]);
        $known = $this->generation();
        $generationWorkerService = app(GenerationWorkerService::class);
        $generationWorkerService->process($known->id);
        $known->refresh();
        $this->assertSame('failed', $known->status);
        $this->assertSame('0.000000000000000000', $known->actual_cost);
        $this->assertStringContainsString('daily generation limit', $known->message);
        $this->assertSame(1, $known->attempts);
        $this->travel(2)->seconds();
        $ambiguous = $this->generation(['uncertain' => true]);
        $generationWorkerService->process($ambiguous->id);
        $this->assertNull($ambiguous->fresh()->actual_cost);
        $this->assertSame('queued', $ambiguous->fresh()->status);
        $this->assertTrue($ambiguous->fresh()->needs_attention);
        Http::assertSentCount(2);
    }

    public function test_requests_wait_for_a_known_provider_slot_without_spending_retry_attempts(): void
    {
        $active = [];
        for ($i = 0; $i < 3; $i++) {
            $active[] = $this->generation(['provider_id' => 'gen_active_'.$i, 'status' => 'processing', 'next_attempt_at' => now()->addMinute()]);
        }
        $waiting = $this->generation(['attempts' => 2]);
        Http::fake(['*' => Http::response($this->object($waiting, 'queued'), 202)]);
        $generationWorkerService = app(GenerationWorkerService::class);
        $this->assertFalse($generationWorkerService->process($waiting->id));
        $this->assertSame(2, $waiting->fresh()->attempts);
        $this->assertSame('queued', $waiting->fresh()->status);
        Http::assertNothingSent();
        $completed = $this->object($active[0]);
        $completed['id'] = $active[0]->provider_id;
        app(GenerationStateService::class)->apply($completed);
        $this->travel(6)->seconds();
        $this->assertTrue($generationWorkerService->process($waiting->id));
        $this->assertNotNull($waiting->fresh()->provider_id);
        Http::assertSentCount(1);
    }

    public function test_paused_provider_requests_keep_their_slots_and_remain_resumable(): void
    {
        $paused = [];
        for ($i = 0; $i < 3; $i++) {
            $paused[] = $this->generation(['provider_id' => 'gen_paused_'.$i, 'status' => 'processing', 'needs_attention' => true]);
        }
        $waiting = $this->generation();
        Http::fake(['*' => Http::response($this->object($waiting, 'queued'), 202)]);
        $this->assertFalse(app(GenerationWorkerService::class)->process($waiting->id));
        $this->assertNull($waiting->fresh()->provider_id);
        Http::assertNothingSent();
        foreach ($paused as $generation) {
            $this->assertTrue($generation->fresh()->needs_attention);
            $this->assertSame('processing', $generation->fresh()->status);
            $this->assertNull($generation->fresh()->actual_cost);
        }
        $this->actingAs(User::findOrFail($paused[0]->user_id))
            ->post('/generations/'.$paused[0]->id.'/retry')->assertRedirect();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame($paused[0]->fresh()->queue_job_uuid, DB::table('jobs')->value('uuid'));
        $this->assertFalse($paused[0]->fresh()->needs_attention);
        $this->assertSame('processing', $paused[0]->fresh()->status);
        $this->assertNull($paused[0]->fresh()->actual_cost);
    }

    public function test_generation_requests_validate_options_and_ignore_forged_ownership_and_billing(): void
    {
        $user = User::factory()->create();
        app(WalletService::class)->purchase($user->id, (string) Str::uuid(), '5.00');
        $other = User::factory()->create();
        $this->actingAs($user)->postJson('/generations', [
            'prompt' => str_repeat('x', 4001), 'size' => 'tiny', 'quality' => 'invalid',
            'reference_images' => array_fill(0, 5, 'file:///private/image.png'), 'request_id' => 'not-a-uuid',
        ])->assertUnprocessable()->assertJsonValidationErrors(['prompt', 'size', 'quality', 'reference_images', 'request_id']);
        Http::assertNothingSent();
        Http::fake(['*/pricing' => Http::response($this->pricing())]);
        $this->post('/generations', [
            'prompt' => 'A quiet forest', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => [],
            'request_id' => (string) Str::uuid(), 'user_id' => $other->id, 'status' => 'succeeded',
            'provider_id' => 'gen_forged', 'actual_cost' => '999.00', 'provider_cost' => '888.00',
        ])->assertRedirect('/studio');
        $generation = Generation::sole();
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame('queued', $generation->status);
        $this->assertNull($generation->provider_id);
        $this->assertNull($generation->actual_cost);
        $this->assertNull($generation->provider_cost);
    }

    public function test_history_exposes_only_public_fields_and_safe_image_links(): void
    {
        $generation = $this->generation(['image_url' => 'javascript:alert(1)', 'provider_id' => 'gen_private', 'attempts' => 3]);
        $this->actingAs(User::findOrFail($generation->user_id))->get('/studio')->assertInertia(fn ($page) => $page
            ->has('generations.data', 1)
            ->where('generations.data.0.image_url', null)
            ->where('generations.total', 1)
            ->where('generations.current_page', 1)
            ->where('generations.last_page', 1)
            ->missing('generations.data.0.provider_id')
            ->missing('generations.data.0.user_id')
            ->missing('generations.data.0.request_id')
            ->missing('generations.data.0.attempts')
            ->missing('generations.data.0.uncertain')
            ->missing('generations.data.0.reconcile_cursor'));
    }

    public function test_webhook_authenticates_raw_json_and_preserves_the_complete_provider_object(): void
    {
        $generation = $this->generation();
        $data = $this->object($generation);
        $data['metadata']['note'] = '  keep these spaces  ';
        $data['extra_provider_field'] = '';
        $event = ['id' => 'evt_raw', 'type' => 'generation.succeeded', 'data' => ['object' => $data]];
        $body = json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test');
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => "t=$timestamp,v1=$signature"];
        $this->call('POST', '/webhooks/pixelforge', [], [], [], $headers, $body)->assertNoContent();
        $stored = json_decode(DB::table('webhook_events')->where('id', 'evt_raw')->value('payload'), true);
        $this->assertSame('  keep these spaces  ', $stored['data']['object']['metadata']['note']);
        $this->assertSame('', $stored['data']['object']['extra_provider_field']);
        $this->assertSame('0.381887500000000000', $generation->fresh()->actual_cost);
        $this->call('POST', '/webhooks/pixelforge', [], [], [], $headers, json_encode($event))->assertStatus(400);
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_malformed_provider_pricing_gives_a_friendly_error_without_accepting_an_order(): void
    {
        $pricing = $this->pricing();
        $pricing['tokens']['reasoning'] = 'invalid';
        Http::fake(['*/pricing' => Http::response($pricing)]);
        $input = ['prompt' => 'A forest', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => []];
        $this->actingAs(User::factory()->create())->postJson('/estimate', $input)
            ->assertStatus(503)->assertJsonPath('message', 'Live pricing is temporarily unavailable. Please try again shortly.');
        $this->post('/generations', $input + ['request_id' => (string) Str::uuid()])->assertSessionHasErrors('prompt');
        $this->assertDatabaseCount('generations', 0);
    }

    public function test_pricing_cache_failure_returns_a_friendly_error_without_provider_io(): void
    {
        // Keep request throttling on the real cache before mocking a pricing-cache failure.
        $this->app->make(RateLimiter::class);
        Cache::partialMock()->shouldReceive('remember')->once()
            ->andThrow(new \RuntimeException('Pricing cache unavailable.'));

        $this->actingAs(User::factory()->create())->postJson('/estimate', [
            'prompt' => 'A forest', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => [],
        ])->assertStatus(503)->assertJsonPath('message', 'Live pricing is temporarily unavailable. Please try again shortly.');
        Http::assertNothingSent();
        $this->assertDatabaseCount('generations', 0);
    }

    private function pricing(): array
    {
        return ['currency' => 'usd', 'unit' => 'per_1m_tokens', 'rates' => $this->rates, 'tokens' => [
            'characters_per_text_token' => 4, 'text_token_variance' => 0.15, 'per_reference_image' => 765,
            'output' => ['medium' => ['1024x1024' => 1100], 'high' => ['1536x1024' => 6450]],
            'reasoning' => ['medium' => ['min' => 200, 'max' => 2000], 'high' => ['min' => 1000, 'max' => 8000]],
        ]];
    }
}
