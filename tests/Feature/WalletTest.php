<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\GenerationStateService;
use App\Services\GenerationWorkerService;
use App\Services\PricingService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['logging.default' => 'null', 'services.pixelforge.key' => 'wallet-test-key']);
        Http::preventStrayRequests();
    }

    public function test_simulated_purchases_are_fixed_idempotent_owner_scoped_and_capped(): void
    {
        $this->postJson('/credits/purchases', [])->assertUnauthorized();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $requestId = (string) Str::uuid();
        $this->actingAs($user)->postJson('/credits/purchases', ['request_id' => $requestId, 'amount' => '5000.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $input = ['request_id' => $requestId, 'amount' => '5.00', 'user_id' => $other->id, 'is_admin' => true, 'credit_balance' => '1000.00'];
        $this->post('/credits/purchases', $input)->assertRedirect();
        $this->post('/credits/purchases', $input)->assertRedirect();
        $this->assertSame('5.000000000000000000', $user->fresh()->credit_balance);
        $this->assertSame(PricingService::ZERO, $other->fresh()->credit_balance);
        $this->assertFalse($user->fresh()->is_admin);
        $this->assertDatabaseCount('credit_purchases', 1);
        $this->postJson('/credits/purchases', ['request_id' => $requestId, 'amount' => '10.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $user->forceFill(['credit_balance' => '999.000000000000000000'])->save();
        $this->postJson('/credits/purchases', ['request_id' => (string) Str::uuid(), 'amount' => '5.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('credit_purchases', 1);
        Http::assertNothingSent();
    }

    public function test_admission_reserves_once_and_rejects_unaffordable_or_changed_requests(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricing())]);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/generations', $this->input() + ['request_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('prompt');
        $this->assertDatabaseCount('generations', 0);
        $this->fund($user);
        $this->postJson('/estimate', $this->input())->assertOk()->assertJsonPath('reservation', '3.750000000000000000')->assertJsonPath('can_afford', true);
        $requestId = (string) Str::uuid();
        $input = $this->input() + ['request_id' => $requestId];
        $this->post('/generations', $input)->assertRedirect('/studio');
        $this->post('/generations', $input)->assertRedirect('/studio');
        $this->assertSame('3.750000000000000000', $user->fresh()->credit_reserved);
        $this->assertDatabaseCount('generations', 1);
        $this->assertSame(hash('sha256', 'wallet-test-key'), Generation::sole()->provider_key_fingerprint);
        $this->postJson('/generations', array_replace($input, ['request_id' => (string) Str::uuid()]))
            ->assertUnprocessable()->assertJsonValidationErrors('prompt');
        $this->postJson('/generations', array_replace($input, ['prompt' => 'Changed']))->assertUnprocessable();
        $this->postJson('/estimate', $this->input())->assertJsonPath('can_afford', false);
        $this->assertDatabaseCount('generations', 1);
        $this->assertSame('5.000000000000000000', $user->fresh()->credit_balance);
    }

    public function test_duplicate_terminal_updates_settle_actual_once_and_release_the_full_hold(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricing())]);
        $user = User::factory()->create();
        $this->fund($user);
        $generation = $this->submit($user);
        $object = $this->object($generation, 'succeeded');
        $object['usage']['output_tokens'] = 500001;
        $generationStateService = app(GenerationStateService::class);
        $generationStateService->apply($object);
        $generationStateService->apply($object);
        $generationStateService->apply($this->object($generation, 'processing'));
        $this->assertSame('1.875003750000000000', $generation->fresh()->actual_cost);
        $this->assertSame('3.124996250000000000', $user->fresh()->credit_balance);
        $this->assertSame(PricingService::ZERO, $user->fresh()->credit_reserved);
        $this->assertNotNull($generation->fresh()->wallet_settled_at);
        $this->assertSame('succeeded', $generation->fresh()->status);
    }

    public function test_confirmed_failure_releases_funds_but_unknown_outcomes_keep_them_reserved(): void
    {
        $user = User::factory()->create();
        $this->fund($user);
        Http::fake(['*/pricing' => Http::response($this->pricing())]);
        $generation = $this->submit($user);
        Http::fake(['*/pricing' => Http::response($this->pricing()), '*/generations' => fn () => throw new ConnectionException('Lost response')]);
        app(GenerationWorkerService::class)->process($generation->id);
        $this->assertTrue($generation->fresh()->uncertain);
        $this->assertSame('3.750000000000000000', $user->fresh()->credit_reserved);
        $this->assertNull($generation->fresh()->wallet_settled_at);
        $this->assertNull($generation->fresh()->actual_cost);
        app(GenerationStateService::class)->apply($this->object($generation, 'failed'));
        $this->assertSame('5.000000000000000000', $user->fresh()->credit_balance);
        $this->assertSame(PricingService::ZERO, $user->fresh()->credit_reserved);
        $this->assertSame(PricingService::ZERO, $generation->fresh()->actual_cost);
    }

    public function test_fresh_price_increase_is_rechecked_before_post_and_releases_an_unaffordable_order(): void
    {
        Http::fake(['*/pricing' => Http::sequence()->push($this->pricing())->push($this->pricing('5.00'))]);
        $user = User::factory()->create();
        $this->fund($user);
        $generation = $this->submit($user);
        app(GenerationWorkerService::class)->process($generation->id);
        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertSame(PricingService::ZERO, $generation->fresh()->actual_cost);
        $this->assertSame(PricingService::ZERO, $user->fresh()->credit_reserved);
        $this->assertSame('5.000000000000000000', $user->fresh()->credit_balance);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_first_definitive_daily_rejection_releases_the_funded_reservation(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricing()), '*/generations' => Http::response(['error' => ['code' => 'daily_limit_exceeded']], 429)]);
        $user = User::factory()->create();
        $this->fund($user);
        $generation = $this->submit($user);
        app(GenerationWorkerService::class)->process($generation->id);
        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertFalse($generation->fresh()->uncertain);
        $this->assertNotNull($generation->fresh()->wallet_settled_at);
        $this->assertSame(PricingService::ZERO, $user->fresh()->credit_reserved);
        $this->assertSame('5.000000000000000000', $user->fresh()->credit_balance);
    }

    public function test_accepted_frozen_rates_resize_hold_and_actual_overrun_is_recorded_as_debt(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricing())]);
        $user = User::factory()->create();
        $this->fund($user);
        $generation = $this->submit($user);
        $object = $this->object($generation, 'processing');
        $object['rates']['output_tokens'] = '5.00';
        $generationStateService = app(GenerationStateService::class);
        $generationStateService->apply($object);
        $this->assertSame('6.250000000000000000', $user->fresh()->credit_reserved);
        $this->assertSame('6.250000000000000000', $generation->fresh()->reserved_cost);
        $object['status'] = 'succeeded';
        $generationStateService->apply($object);
        $this->assertSame('6.250000000000000000', $generation->fresh()->actual_cost);
        $this->assertSame('-1.250000000000000000', $user->fresh()->credit_balance);
        $this->assertSame(PricingService::ZERO, $user->fresh()->credit_reserved);
        $this->actingAs($user)->postJson('/generations', $this->input() + ['request_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_old_unreserved_generations_remain_wallet_exempt(): void
    {
        $user = User::factory()->create();
        $generation = Generation::create($this->input() + ['user_id' => $user->id, 'request_id' => (string) Str::uuid(), 'estimate' => ['typical' => '3.75']]);
        app(GenerationStateService::class)->apply($this->object($generation, 'succeeded'));
        $this->assertSame('3.750000000000000000', $generation->fresh()->actual_cost);
        $this->assertNull($generation->fresh()->wallet_settled_at);
        $this->assertSame(PricingService::ZERO, $user->fresh()->credit_balance);
        $this->assertSame(PricingService::ZERO, $user->fresh()->credit_reserved);
    }

    public function test_unknown_submission_is_never_repriced_or_replayed_under_a_different_key(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricing())]);
        $user = User::factory()->create();
        $this->fund($user);
        $generation = $this->submit($user);
        $generation->fill(['uncertain' => true])->save();
        config(['services.pixelforge.key' => 'different-account-key']);
        Http::fake();
        app(GenerationWorkerService::class)->process($generation->id);
        Http::assertNothingSent();
        $this->assertTrue($generation->fresh()->needs_attention);
        $this->assertNull($generation->fresh()->actual_cost);
        $this->assertSame('3.750000000000000000', $user->fresh()->credit_reserved);
        $generation->refresh()->fill([
            'provider_id' => 'gen_accepted', 'uncertain' => false, 'needs_attention' => false,
            'next_attempt_at' => now(), 'attempts' => 0,
        ])->save();
        app(GenerationWorkerService::class)->process($generation->id);
        Http::assertNothingSent();
        $this->assertTrue($generation->fresh()->needs_attention);
        $this->assertNull($generation->fresh()->actual_cost);
    }

    public function test_admin_access_requires_an_explicit_existing_account_and_can_be_revoked(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->is_admin);
        $this->artisan('pixelforge:admin', ['email' => 'missing@example.test'])->assertFailed();
        $this->assertDatabaseCount('users', 1);
        $this->artisan('pixelforge:admin', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);
        $this->artisan('pixelforge:admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_admin);
    }

    private function fund(User $user): void
    {
        app(WalletService::class)->purchase($user->id, (string) Str::uuid(), '5.00');
    }

    private function submit(User $user): Generation
    {
        return app(GenerationService::class)->submit($user->id, (string) Str::uuid(), $this->input());
    }

    private function input(): array
    {
        return ['prompt' => 'A quiet forest', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => []];
    }

    private function pricing(string $outputRate = '3.00'): array
    {
        return ['currency' => 'usd', 'unit' => 'per_1m_tokens',
            'rates' => ['input_text_tokens' => '0', 'input_image_tokens' => '0', 'output_tokens' => $outputRate, 'reasoning_tokens' => '0'],
            'tokens' => ['characters_per_text_token' => 4, 'text_token_variance' => '0.15', 'per_reference_image' => 765,
                'output' => ['medium' => ['1024x1024' => 1000000]], 'reasoning' => ['medium' => ['min' => 200, 'max' => 2000]]]];
    }

    private function object(Generation $generation, string $status): array
    {
        return ['id' => 'gen_'.$generation->id, 'status' => $status, 'metadata' => ['generation_id' => $generation->id],
            'rates' => $this->pricing()['rates'], 'usage' => ['input_text_tokens' => 4, 'input_image_tokens' => 0, 'output_tokens' => 1000000, 'reasoning_tokens' => 200],
            'completed_at' => now()->toISOString()];
    }
}
