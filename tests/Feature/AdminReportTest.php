<?php

namespace Tests\Feature;

use App\Clients\PixelforgeClient;
use App\Models\Generation;
use App\Models\User;
use App\Repositories\ReportSummaryRepository;
use App\Services\GenerationStateService;
use App\Services\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
        config(['services.pixelforge.key' => 'report-test-key', 'services.pixelforge.url' => 'https://provider.test/v1']);
        Http::preventStrayRequests();
    }

    public function test_only_admins_can_read_reports_and_rejected_requests_never_call_provider(): void
    {
        $this->get('/admin/reports')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/reports')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_date_validation_is_inclusive_defaults_to_thirty_utc_days_and_rejects_invalid_filters(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Http::fake(['*/usage*' => fn (Request $request) => Http::response($this->usage($request['start_date'], $request['end_date']))]);
        $props = $this->report();
        $this->assertSame(['start_date' => '2026-10-19', 'end_date' => '2026-11-17', 'scope' => 'current'], $props['filters']);
        $this->assertCount(30, $props['report']['days']);
        $this->assertSame('matched', $props['reconciliation']['status']);
        $props = $this->report(['start_date' => '2026-08-18', 'end_date' => '2026-11-17']);
        $this->assertCount(92, $props['report']['days']);

        foreach ([
            ['start_date' => '2026-08-17', 'end_date' => '2026-11-17'],
            ['start_date' => '2026-11-18', 'end_date' => '2026-11-17'],
            ['start_date' => '2026-02-30', 'end_date' => '2026-03-02'],
            ['scope' => 'other'], ['page' => 0],
        ] as $invalid) {
            $this->getJson('/admin/reports?'.http_build_query($invalid))->assertUnprocessable();
        }
        Http::assertSentCount(2);
    }

    public function test_current_scope_uses_completion_boundaries_and_exact_costs_and_separates_old_keys(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $this->generation($user, '2026-11-15 00:00:00', '0.100000001');
        $this->generation($user, '2026-11-17 23:59:59', '0.200000002');
        $this->generation($user, '2026-11-14 23:59:59', '9');
        $this->generation($user, '2026-11-18 00:00:00', '9');
        $this->generation($user, '2026-11-15 10:00:00', '9', ['status' => 'failed']);
        $this->generation($user, '2026-11-15 10:00:00', '9', ['status' => 'processing']);
        $this->generation($user, '2026-11-15 10:00:00', '0.4', ['provider_key_fingerprint' => hash('sha256', 'old-key')]);
        $this->generation($user, '2026-11-15 10:00:00', '0.5', ['provider_key_fingerprint' => null]);
        Http::fake(['*/usage*' => Http::response($this->usage('2026-11-15', '2026-11-17', [
            '2026-11-15' => ['cost_usd' => '0.100000001', 'generations' => 1],
            '2026-11-17' => ['cost_usd' => '0.200000002', 'generations' => 1],
        ]))]);

        $props = $this->report(['start_date' => '2026-11-15', 'end_date' => '2026-11-17']);
        $this->assertSame([
            'generations' => 2, 'credit_spend' => '0.375000003750000000',
            'provider_cost' => '0.300000003000000000', 'profit' => '0.075000000750000000',
        ], $props['report']['totals']);
        $this->assertSame(0, $props['report']['days'][1]['generations']);
        $this->assertSame(PricingService::ZERO, $props['report']['days'][1]['credit_spend']);
        $this->assertSame(2, $props['report']['entries']['total']);
        $this->assertSame('matched', $props['reconciliation']['status']);
        $this->assertSame('0.500000000000000000', $props['reconciliation']['unscoped_provider_cost']);
        $this->assertSame('0.400000000000000000', $props['reconciliation']['other_key_provider_cost']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer report-test-key')
            && $request['start_date'] === '2026-11-15' && $request['end_date'] === '2026-11-17');

        $all = $this->report(['start_date' => '2026-11-15', 'end_date' => '2026-11-17', 'scope' => 'all']);
        $this->assertSame(4, $all['report']['totals']['generations']);
        $this->assertSame('1.200000003000000000', $all['report']['totals']['provider_cost']);
        $this->assertSame('0.300000003000000000', $all['reconciliation']['recorded_provider_cost']);
        $this->assertSame('matched', $all['reconciliation']['status']);
    }

    public function test_user_day_pagination_does_not_change_overall_totals_and_preserves_filters(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        foreach (User::factory()->count(51)->create() as $user) {
            $this->generation($user, '2026-11-17 12:00:00', '0.1');
        }
        Http::fake(['*/usage*' => Http::response($this->usage('2026-11-17', '2026-11-17', [
            '2026-11-17' => ['cost_usd' => '5.100000000', 'generations' => 51],
        ]))]);
        $filters = ['start_date' => '2026-11-17', 'end_date' => '2026-11-17', 'scope' => 'all'];
        $first = $this->report($filters);
        $second = $this->report($filters + ['page' => 2]);
        $this->assertCount(50, $first['report']['entries']['data']);
        $this->assertCount(1, $second['report']['entries']['data']);
        $this->assertSame(51, $second['report']['entries']['total']);
        $this->assertSame(2, $second['report']['entries']['last_page']);
        $this->assertSame($first['report']['totals'], $second['report']['totals']);
        $this->assertSame('6.375000000000000000', $second['report']['totals']['credit_spend']);
        $this->assertStringContainsString('scope=all', $first['report']['entries']['next_page_url']);
        $this->assertStringContainsString('start_date=2026-11-17', $first['report']['entries']['next_page_url']);
        $this->assertSame('matched', $second['reconciliation']['status']);
    }

    public function test_offsetting_daily_discrepancies_are_not_reported_as_a_match(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $this->generation($user, '2026-11-16 12:00:00', '0.1');
        $this->generation($user, '2026-11-17 12:00:00', '0.2');
        Http::fake(['*/usage*' => Http::response($this->usage('2026-11-16', '2026-11-17', [
            '2026-11-16' => ['cost_usd' => '0.200000000', 'generations' => 1],
            '2026-11-17' => ['cost_usd' => '0.100000000', 'generations' => 1],
        ]))]);
        $props = $this->report(['start_date' => '2026-11-16', 'end_date' => '2026-11-17']);
        $this->assertSame('mismatch', $props['reconciliation']['status']);
        $this->assertSame(PricingService::ZERO, $props['reconciliation']['difference']);
        $this->assertSame('0.100000000000000000', $props['reconciliation']['days'][0]['difference']);
        $this->assertSame('-0.100000000000000000', $props['reconciliation']['days'][1]['difference']);
    }

    public function test_a_large_user_day_is_aggregated_once_without_losing_precision(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        for ($index = 0; $index < 501; $index++) {
            $this->generation($user, '2026-11-17 12:00:00', '0.000000001');
        }
        Http::fake(['*/usage*' => Http::response($this->usage('2026-11-17', '2026-11-17', [
            '2026-11-17' => ['cost_usd' => '0.000000501', 'generations' => 501],
        ]))]);
        $this->assertDatabaseCount('report_summaries', 1);
        DB::enableQueryLog();
        $props = $this->report(['start_date' => '2026-11-17', 'end_date' => '2026-11-17']);
        $queries = collect(DB::getQueryLog())->pluck('query')
            ->map(fn (string $sql): string => str_replace(['"', '`'], '', $sql));
        DB::disableQueryLog();
        $this->assertSame(1, $props['report']['entries']['total']);
        $this->assertSame(501, $props['report']['entries']['data'][0]['generations']);
        $this->assertSame('0.000000626250000000', $props['report']['entries']['data'][0]['credit_spend']);
        $this->assertSame('matched', $props['reconciliation']['status']);
        $this->assertCount(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from report_summaries')));
        $this->assertFalse($queries->contains(fn (string $sql): bool => str_contains($sql, 'from generations')));
    }

    public function test_first_success_updates_summary_once_across_webhooks_and_polling(): void
    {
        $user = User::factory()->create();
        $generation = $this->generation($user, '2026-11-16 12:00:00', '0', ['status' => 'processing']);
        $object = $this->successfulObject($generation);
        $event = ['id' => 'evt_summary', 'type' => 'generation.succeeded', 'data' => ['object' => $object]];
        $state = app(GenerationStateService::class);
        $state->webhook($event);
        $state->webhook($event);
        $state->webhook(array_replace($event, ['id' => 'evt_summary_duplicate']));
        $state->apply($object, $generation->id);
        $state->apply(array_replace($object, ['status' => 'failed']), $generation->id);

        $this->assertDatabaseCount('report_summaries', 1);
        $this->assertDatabaseHas('report_summaries', [
            'date' => '2026-11-17', 'user_id' => $user->id,
            'provider_key_fingerprint' => hash('sha256', 'report-test-key'), 'generations' => 1,
            'provider_cost' => '0.000000000000001000', 'credit_spend' => '0.000000000000001250',
        ]);
        $failed = $this->generation($user, '2026-11-16 12:00:00', '0', ['status' => 'processing']);
        $state->apply(array_replace($this->successfulObject($failed), ['status' => 'failed']), $failed->id);
        $this->assertSame(1, (int) DB::table('report_summaries')->value('generations'));
    }

    public function test_summary_failure_rolls_back_the_generation_wallet_and_summary_together(): void
    {
        $user = User::factory()->create(['credit_balance' => '5.000000000000000000', 'credit_reserved' => '1.000000000000000000']);
        $generation = $this->generation($user, '2026-11-16 12:00:00', '0', [
            'status' => 'processing', 'reserved_cost' => '1.000000000000000000',
        ]);
        $this->app->instance(ReportSummaryRepository::class, new class extends ReportSummaryRepository
        {
            public function recordSuccess(Generation $generation): void
            {
                parent::recordSuccess($generation);
                throw new RuntimeException('Summary write interrupted.');
            }
        });
        try {
            app(GenerationStateService::class)->apply($this->successfulObject($generation), $generation->id);
            $this->fail('The injected summary error should abort the terminal transition.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Summary write interrupted.', $exception->getMessage());
        }

        $this->assertSame('processing', $generation->fresh()->status);
        $this->assertNull($generation->fresh()->wallet_settled_at);
        $this->assertSame('5.000000000000000000', $user->fresh()->credit_balance);
        $this->assertSame('1.000000000000000000', $user->fresh()->credit_reserved);
        $this->assertDatabaseCount('report_summaries', 0);
    }

    public function test_migration_backfills_exact_history_in_batches_without_guessing_keys_and_can_retry(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        for ($index = 0; $index < 501; $index++) {
            $this->generation($user, '2026-11-17 12:00:00', '0.000000000000000001', [
                'provider_key_fingerprint' => null, 'actual_cost' => '0.000000000000000002',
            ], summarize: false);
        }
        $this->generation($user, '2026-11-17 13:00:00', '0.2', summarize: false);
        $this->generation($user, '2026-11-17 14:00:00', '0.3', ['provider_key_fingerprint' => hash('sha256', 'old-key')], summarize: false);
        $this->generation($user, '2026-11-17 15:00:00', '9', ['status' => 'failed'], summarize: false);
        $this->generation($user, '2026-11-17 16:00:00', '9', ['status' => 'processing'], summarize: false);

        $migration = require database_path('migrations/2026_11_17_000006_create_report_summaries.php');
        $migration->down();
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('report_summaries', 3);
        $this->assertSame(501, Generation::query()->whereNull('provider_key_fingerprint')->count());
        $this->assertDatabaseHas('report_summaries', [
            'date' => '2026-11-17', 'user_id' => $user->id, 'provider_key_fingerprint' => '',
            'generations' => 501, 'provider_cost' => '0.000000000000000501', 'credit_spend' => '0.000000000000001002',
        ]);
        Http::fake(['*/usage*' => Http::response($this->usage('2026-11-17', '2026-11-17', [
            '2026-11-17' => ['cost_usd' => '0.200000000', 'generations' => 1],
        ]))]);
        $filters = ['start_date' => '2026-11-17', 'end_date' => '2026-11-17'];
        $current = $this->report($filters);
        $this->assertSame(1, $current['report']['totals']['generations']);
        $this->assertSame('matched', $current['reconciliation']['status']);
        $all = $this->report($filters + ['scope' => 'all']);
        $this->assertSame([
            'generations' => 503, 'credit_spend' => '0.625000000000001002',
            'provider_cost' => '0.500000000000000501', 'profit' => '0.125000000000000501',
        ], $all['report']['totals']);
        $this->assertSame(1, $all['report']['entries']['total']);
        $this->assertSame($all['report']['totals'], array_intersect_key($all['report']['entries']['data'][0], $all['report']['totals']));
        $this->assertSame('0.000000000000000501', $all['reconciliation']['unscoped_provider_cost']);
        $this->assertSame('0.300000000000000000', $all['reconciliation']['other_key_provider_cost']);
        $this->assertSame('matched', $all['reconciliation']['status']);
    }

    public function test_provider_outages_and_invalid_usage_preserve_local_totals_without_claiming_a_match(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $this->generation($user, '2026-11-17 12:00:00', '0.1');
        $valid = $this->usage('2026-11-17', '2026-11-17', ['2026-11-17' => ['cost_usd' => '0.100000000', 'generations' => 1]]);
        $wrongTotal = $valid;
        $wrongTotal['total']['cost_usd'] = '0.200000000';
        $wrongDate = $valid;
        $wrongDate['data'][0]['date'] = '2026-11-16';
        $floatCost = $valid;
        $floatCost['data'][0]['cost_usd'] = 0.1;
        Http::fake(['*/usage*' => Http::sequence()->push([], 503)->push($wrongTotal)->push($wrongDate)->push($floatCost)]);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $props = $this->report(['start_date' => '2026-11-17', 'end_date' => '2026-11-17']);
            $this->assertSame('unavailable', $props['reconciliation']['status']);
            $this->assertNull($props['reconciliation']['provider_cost']);
            $this->assertNull($props['reconciliation']['difference']);
            $this->assertSame('0.100000000000000000', $props['report']['totals']['provider_cost']);
        }
    }

    public function test_brief_provider_request_contention_waits_then_compares_usage_without_holding_a_transaction(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $transactionLevel = DB::transactionLevel();
        Sleep::whenFakingSleep(fn () => $this->assertSame($transactionLevel, DB::transactionLevel()));
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $this->generation($user, '2026-11-17 12:00:00', '0.1');
        Http::fake(['*/usage*' => Http::response($this->usage('2026-11-17', '2026-11-17', [
            '2026-11-17' => ['cost_usd' => '0.100000000', 'generations' => 1],
        ]))]);
        $filters = ['start_date' => '2026-11-17', 'end_date' => '2026-11-17'];
        $first = $this->report($filters);
        $second = $this->get('/admin/reports?'.http_build_query($filters))->assertOk()->viewData('page')['props'];
        $this->assertSame('matched', $first['reconciliation']['status']);
        $this->assertSame('matched', $second['reconciliation']['status']);
        $this->assertSame($first['report']['totals'], $second['report']['totals']);
        Sleep::assertSequence([Sleep::for(1000)->milliseconds()]);
        Http::assertSentCount(2);
    }

    public function test_long_provider_cooldown_skips_the_usage_wait_and_preserves_local_summary_totals(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $this->generation($user, '2026-11-17 12:00:00', '0.1');
        Http::fake([
            '*/generations/gen_cooldown' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '45']),
            '*/usage*' => Http::response($this->usage('2026-11-17', '2026-11-17', [
                '2026-11-17' => ['cost_usd' => '0.100000000', 'generations' => 1],
            ])),
        ]);
        app(PixelforgeClient::class)->retrieve('gen_cooldown');
        $props = $this->report(['start_date' => '2026-11-17', 'end_date' => '2026-11-17']);
        $this->assertSame('unavailable', $props['reconciliation']['status']);
        $this->assertNull($props['reconciliation']['provider_cost']);
        $this->assertSame('0.100000000000000000', $props['reconciliation']['recorded_provider_cost']);
        $this->assertSame([
            'generations' => 1, 'credit_spend' => '0.125000000000000000',
            'provider_cost' => '0.100000000000000000', 'profit' => '0.025000000000000000',
        ], $props['report']['totals']);
        Sleep::assertNeverSlept();
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/usage'));
    }

    public function test_missing_key_never_matches_unscoped_history_or_calls_provider(): void
    {
        config(['services.pixelforge.key' => null]);
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $this->generation($user, '2026-11-17 12:00:00', '0.1', ['provider_key_fingerprint' => null]);
        $props = $this->report();
        $this->assertSame(0, $props['report']['totals']['generations']);
        $this->assertSame('unconfigured', $props['reconciliation']['status']);
        $this->assertSame(PricingService::ZERO, $props['reconciliation']['recorded_provider_cost']);
        $all = $this->report(['scope' => 'all']);
        $this->assertSame(1, $all['report']['totals']['generations']);
        $this->assertSame('0.100000000000000000', $all['reconciliation']['unscoped_provider_cost']);
        Http::assertNothingSent();
    }

    private function generation(User $user, string $completedAt, string $cost, array $overrides = [], bool $summarize = true): Generation
    {
        $generation = Generation::forceCreate($overrides + [
            'user_id' => $user->id, 'request_id' => (string) Str::uuid(), 'prompt' => 'Report fixture',
            'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => [], 'estimate' => [],
            'status' => 'succeeded', 'completed_at' => $completedAt, 'created_at' => '2026-01-01 00:00:00',
            'provider_cost' => bcadd($cost, '0', 18), 'actual_cost' => bcmul($cost, '1.25', 18),
            'provider_key_fingerprint' => hash('sha256', 'report-test-key'),
        ]);
        if ($summarize && $generation->status === 'succeeded') {
            DB::transaction(fn () => app(ReportSummaryRepository::class)->recordSuccess($generation));
        }

        return $generation;
    }

    private function successfulObject(Generation $generation): array
    {
        return [
            'id' => 'gen_'.$generation->id, 'status' => 'succeeded',
            'metadata' => ['generation_id' => $generation->id], 'completed_at' => '2026-11-17T00:00:00Z',
            'usage' => ['input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 0],
            'rates' => array_fill_keys(PricingService::BUCKETS, '0.000000001'),
        ];
    }

    private function report(array $filters = []): array
    {
        // Separate report snapshots respect the account-wide provider request cadence.
        $this->travel(1)->seconds();
        $response = $this->get('/admin/reports?'.http_build_query($filters))->assertOk();
        $this->assertTrue($response->viewData('page')['encryptHistory']);

        return $response->viewData('page')['props'];
    }

    private function usage(string $start, string $end, array $days = []): array
    {
        $data = [];
        $cost = '0.000000000';
        $count = 0;
        for ($date = CarbonImmutable::parse($start, 'UTC'); $date->lte(CarbonImmutable::parse($end, 'UTC')); $date = $date->addDay()) {
            $key = $date->toDateString();
            $row = ['date' => $key] + ($days[$key] ?? ['cost_usd' => '0.000000000', 'generations' => 0]);
            $data[] = $row;
            $cost = bcadd($cost, $row['cost_usd'], 9);
            $count += $row['generations'];
        }

        return ['object' => 'usage', 'start_date' => $start, 'end_date' => $end, 'data' => $data,
            'total' => ['cost_usd' => $cost, 'generations' => $count]];
    }
}
