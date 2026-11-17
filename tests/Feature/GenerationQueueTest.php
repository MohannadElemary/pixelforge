<?php

namespace Tests\Feature;

use App\Clients\PixelforgeClient;
use App\Exceptions\ProviderRateLimitException;
use App\Jobs\ProcessGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationQueueService;
use App\Services\GenerationStateService;
use App\Services\GenerationWorkerService;
use App\Services\ProviderRateLimitService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class GenerationQueueTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['logging.default' => 'null', 'services.pixelforge.key' => 'queue-test-key', 'queue.default' => 'database']);
        Http::preventStrayRequests();
        $this->freezeTime();
    }

    public function test_dispatch_waits_for_commit_and_rollback_does_not_enqueue(): void
    {
        DB::beginTransaction();
        $rolledBack = $this->generation();
        app(GenerationQueueService::class)->dispatch($rolledBack);
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseMissing('generations', ['id' => $rolledBack->id]);

        DB::beginTransaction();
        $generation = $this->generation();
        app(GenerationQueueService::class)->dispatch($generation);
        $this->assertDatabaseCount('jobs', 0);
        DB::commit();
        $this->assertDatabaseCount('jobs', 1);
        app(GenerationQueueService::class)->dispatch($generation);
        $this->assertDatabaseCount('jobs', 1);
        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertSame(45, $payload['timeout']);
        $this->assertSame('generations', DB::table('jobs')->value('queue'));
    }

    public function test_real_database_job_releases_until_poll_due_then_finishes_without_duplicate_submission(): void
    {
        $generation = $this->generation();
        Http::fake(['*' => Http::sequence()->push($this->object($generation, 'processing'), 202)->push($this->object($generation, 'failed'))]);
        app(GenerationQueueService::class)->dispatch($generation);
        Queue::pop('generations')->fire();
        $this->assertSame('processing', $generation->fresh()->status);
        $this->assertTrue($generation->fresh()->provider_slot_reserved);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertNull(Queue::pop('generations'));
        $this->travel(9)->seconds();
        Queue::pop('generations')->fire();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertFalse($generation->fresh()->provider_slot_reserved);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_local_rate_waits_do_not_send_mark_uncertain_or_consume_provider_attempts(): void
    {
        Http::fake(['*' => Http::response([])]);
        app(PixelforgeClient::class)->usage('2026-11-01', '2026-11-17');
        $generation = $this->generation(['attempts' => 3]);
        $job = (new ProcessGeneration($generation->id))->withFakeQueueInteractions();
        $job->handle(app(GenerationWorkerService::class));
        $job->assertReleased(1);
        $generation->refresh();
        $this->assertSame(3, $generation->attempts);
        $this->assertFalse($generation->uncertain);
        $this->assertFalse($generation->provider_slot_reserved);
        $this->assertNull($generation->actual_cost);
        Http::assertSentCount(1);
    }

    public function test_all_authenticated_operations_share_the_budget_across_api_keys(): void
    {
        Http::fake(['*' => Http::response([])]);
        $client = app(PixelforgeClient::class);
        $client->usage('2026-11-01', '2026-11-17');
        config(['services.pixelforge.key' => 'second-account-key']);
        $generation = $this->generation();
        $beforeSending = false;
        foreach ([
            fn () => $client->retrieve('gen_existing'),
            fn () => $client->list(null),
            fn () => $client->submit($generation, function () use (&$beforeSending): void {
                $beforeSending = true;
            }),
        ] as $operation) {
            try {
                $operation();
                $this->fail('An authenticated request bypassed the shared budget.');
            } catch (ProviderRateLimitException $exception) {
                $this->assertSame(1, $exception->retryAfter);
            }
        }
        $this->assertFalse($beforeSending);
        Http::assertSentCount(1);
        Sleep::fake(syncWithCarbon: true);
        $client->usage('2026-11-01', '2026-11-17');
        Sleep::assertSleptTimes(1);
        Http::assertSentCount(2);
        $this->travel(1)->seconds();
        $client->retrieve('gen_existing');
        Http::assertSentCount(3);
    }

    public function test_retry_after_http_date_from_usage_blocks_generation_requests_on_every_key(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, [
            'Retry-After' => now()->addSeconds(90)->toRfc7231String(),
        ])]);
        try {
            app(PixelforgeClient::class)->usage('2026-11-01', '2026-11-17');
            $this->fail('The usage response should fail.');
        } catch (RequestException) {
            // The account-wide cooldown must still be recorded before throw().
        }
        config(['services.pixelforge.key' => 'rotated-key']);
        $generation = $this->generation();
        $job = (new ProcessGeneration($generation->id))->withFakeQueueInteractions();
        $job->handle(app(GenerationWorkerService::class));
        $job->assertReleased(90);
        $this->assertSame(0, $generation->fresh()->attempts);
        $this->assertFalse($generation->fresh()->uncertain);
        Http::assertSentCount(1);
    }

    public function test_unknown_and_paused_submissions_retain_capacity_until_confirmed_terminal(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('Lost reply')]);
        $worker = app(GenerationWorkerService::class);
        $active = [];
        for ($index = 0; $index < 3; $index++) {
            $active[] = $generation = $this->generation();
            $worker->process($generation->id);
            $this->assertTrue($generation->fresh()->provider_slot_reserved);
            $generation->refresh()->update(['needs_attention' => true]);
            $this->travel(1)->seconds();
        }
        $waiting = $this->generation();
        $this->assertFalse($worker->process($waiting->id));
        $this->assertSame(0, $waiting->fresh()->attempts);
        $this->assertFalse($waiting->fresh()->provider_slot_reserved);
        $this->assertSame(3, Generation::where('provider_slot_reserved', true)->count());
        app(GenerationStateService::class)->apply($this->object($active[0], 'failed'));
        $this->assertSame(2, Generation::where('provider_slot_reserved', true)->count());
        $this->travel(6)->seconds();
        $this->assertTrue($worker->process($waiting->id));
        $this->assertTrue($waiting->fresh()->provider_slot_reserved);
        $this->assertSame(3, Generation::where('provider_slot_reserved', true)->count());
    }

    public function test_known_rejection_releases_slot_but_unknown_rejection_keeps_it(): void
    {
        $known = $this->generation();
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 'overloaded']], 503)
            ->push(['error' => ['code' => 'unexpected_error']], 500)]);
        app(GenerationWorkerService::class)->process($known->id);
        $this->assertFalse($known->fresh()->provider_slot_reserved);
        $this->assertFalse($known->fresh()->uncertain);
        $this->travel(1)->seconds();
        $unknown = $this->generation();
        app(GenerationWorkerService::class)->process($unknown->id);
        $this->assertTrue($unknown->fresh()->provider_slot_reserved);
        $this->assertTrue($unknown->fresh()->uncertain);
    }

    public function test_duplicate_early_and_stale_jobs_do_not_issue_extra_requests(): void
    {
        $generation = $this->generation();
        Http::fake(['*' => Http::response($this->object($generation, 'processing'), 202)]);
        $worker = app(GenerationWorkerService::class);
        (new ProcessGeneration($generation->id))->withFakeQueueInteractions()->handle($worker);
        $early = (new ProcessGeneration($generation->id))->withFakeQueueInteractions();
        $early->handle($worker);
        $early->assertReleased(8);
        Http::assertSentCount(1);
        app(GenerationStateService::class)->apply($this->object($generation, 'failed'));
        $stale = (new ProcessGeneration($generation->id))->withFakeQueueInteractions();
        $stale->handle($worker);
        $stale->assertNotReleased();
        $generation->delete();
        (new ProcessGeneration($generation->id))->withFakeQueueInteractions()->handle($worker);
        Http::assertSentCount(1);
    }

    public function test_overlap_contention_releases_without_claiming_a_generation(): void
    {
        $generation = $this->generation();
        $job = (new ProcessGeneration($generation->id))->withFakeQueueInteractions();
        $middleware = $job->middleware()[0];
        $lock = Cache::lock($middleware->getLockKey($job), 120);
        $this->assertTrue($lock->get());
        $middleware->handle($job, fn ($job) => $job->handle(app(GenerationWorkerService::class)));
        $job->assertReleased(5);
        $this->assertSame(0, $generation->fresh()->attempts);
        Http::assertNothingSent();
        $lock->release();
    }

    public function test_recovery_rotates_bounded_batches_and_ignores_terminal_paused_and_future_rows(): void
    {
        $user = User::factory()->create();
        for ($index = 0; $index < 101; $index++) {
            $this->generation(['user_id' => $user->id, 'next_attempt_at' => now()->subMinutes(5)]);
        }
        $this->generation(['status' => 'failed', 'next_attempt_at' => now()->subMinutes(5)]);
        $this->generation(['needs_attention' => true, 'next_attempt_at' => now()->subMinutes(5)]);
        $this->generation(['next_attempt_at' => now()->addMinute()]);
        $queue = app(GenerationQueueService::class);
        $this->assertSame(100, $queue->recover());
        $this->assertSame(1, $queue->recover());
        $this->assertSame(0, $queue->recover());
        $this->assertDatabaseCount('jobs', 101);
    }

    public function test_recovery_repairs_a_lost_dispatch_and_a_crashed_claim_without_releasing_unknown_slot(): void
    {
        $generation = $this->generation();
        app(GenerationQueueService::class)->dispatch($generation);
        DB::table('jobs')->delete();
        $this->assertSame(0, app(GenerationQueueService::class)->recover());
        $generation->update(['uncertain' => true, 'provider_slot_reserved' => true, 'attempts' => 1, 'next_attempt_at' => now()->addSeconds(120)]);
        $this->travel(181)->seconds();
        $this->assertSame(1, app(GenerationQueueService::class)->recover());
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(0, app(GenerationQueueService::class)->recover());
        $this->assertTrue($generation->fresh()->provider_slot_reserved);
        $this->assertTrue($generation->fresh()->uncertain);
    }

    public function test_database_queue_attempt_counter_survives_more_than_255_releases(): void
    {
        $generation = $this->generation(['next_attempt_at' => now()->addMinute()]);
        app(GenerationQueueService::class)->dispatch($generation);
        DB::table('jobs')->update(['attempts' => 255]);
        $job = Queue::pop('generations');
        $this->assertSame(256, $job->attempts());
        $job->fire();
        $this->assertSame(256, (int) DB::table('jobs')->value('attempts'));
        Http::assertNothingSent();
    }

    public function test_recovery_never_duplicates_live_jobs_after_repeated_releases_and_long_worker_downtime(): void
    {
        $generation = $this->generation();
        Http::fake(['*' => Http::response($this->object($generation, 'processing'), 202)]);
        $queue = app(GenerationQueueService::class);
        $queue->dispatch($generation);
        $uuid = $generation->fresh()->queue_job_uuid;
        $firstJobId = DB::table('jobs')->value('id');
        for ($index = 0; $index < 4; $index++) {
            Queue::pop('generations')->fire();
            $this->travel(70)->seconds();
            $this->assertSame(0, $queue->recover());
            $this->assertDatabaseCount('jobs', 1);
            $this->assertSame($uuid, DB::table('jobs')->value('uuid'));
        }
        $this->assertNotSame($firstJobId, DB::table('jobs')->value('id'));
        // The worker is now stopped beyond many former uniqueness/recovery leases.
        for ($index = 0; $index < 5; $index++) {
            $this->travel(15)->minutes();
            $this->assertSame(0, $queue->recover());
            $this->assertDatabaseCount('jobs', 1);
        }
        $reserved = Queue::pop('generations');
        $this->travel(15)->minutes();
        $this->assertSame(0, $queue->recover());
        $this->assertDatabaseCount('jobs', 1);
        $reserved->release(5);
        $this->assertSame($uuid, DB::table('jobs')->value('uuid'));
        $this->assertSame(0, $queue->recover());
        $this->assertTrue($generation->fresh()->provider_slot_reserved);
    }

    public function test_failed_receipt_write_rolls_back_queue_insert_and_recovery_repairs_the_gap(): void
    {
        $generation = $this->generation(['next_attempt_at' => now()->subMinutes(2)]);
        Generation::updating(function (Generation $model): void {
            if ($model->isDirty('queue_job_uuid')) {
                throw new RuntimeException('Simulated receipt write failure');
            }
        });
        try {
            app(GenerationQueueService::class)->dispatch($generation);
            $this->assertDatabaseCount('jobs', 0);
            $this->assertNull($generation->fresh()->queue_job_uuid);
        } finally {
            Generation::flushEventListeners();
        }
        $this->assertSame(1, app(GenerationQueueService::class)->recover());
        $this->assertDatabaseCount('jobs', 1);
        $this->assertNotNull($generation->fresh()->queue_job_uuid);
    }

    public function test_replayed_job_with_obsolete_receipt_is_discarded_without_releasing_or_sending(): void
    {
        $generation = $this->generation();
        ProcessGeneration::dispatch($generation->id);
        app(GenerationQueueService::class)->dispatch($generation);
        $this->assertDatabaseCount('jobs', 2);
        Queue::pop('generations')->fire();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(0, $generation->fresh()->attempts);
        Http::assertNothingSent();
    }

    public function test_webhook_resuming_paused_generation_enqueues_once_but_terminal_webhook_does_not(): void
    {
        $generation = $this->generation(['needs_attention' => true, 'uncertain' => true, 'provider_slot_reserved' => true]);
        $state = app(GenerationStateService::class);
        $state->webhook(['id' => 'evt_resume', 'type' => 'generation.processing', 'data' => ['object' => $this->object($generation, 'processing')]]);
        $state->webhook(['id' => 'evt_resume_duplicate', 'type' => 'generation.processing', 'data' => ['object' => $this->object($generation, 'processing')]]);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertFalse($generation->fresh()->needs_attention);
        $this->assertSame($generation->fresh()->queue_job_uuid, DB::table('jobs')->value('uuid'));
        $terminal = $this->generation(['needs_attention' => true, 'provider_slot_reserved' => true]);
        $state->webhook(['id' => 'evt_terminal', 'type' => 'generation.failed', 'data' => ['object' => $this->object($terminal, 'failed')]]);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertNull($terminal->fresh()->queue_job_uuid);
    }

    public function test_retry_after_is_bounded_for_integer_and_date_headers_before_persisting_cooldowns(): void
    {
        $limiter = app(ProviderRateLimitService::class);
        foreach ([
            ['0', 1], ['1', 1], ['3600', 3600], ['3601', 3600],
            [str_repeat('9', 100), 3600], ['not a date', 1],
            [now()->subDay()->toRfc7231String(), 1],
            [now()->addYears(100)->toRfc7231String(), 3600],
        ] as [$header, $expected]) {
            $response = Http::response([], 429, ['Retry-After' => $header])->wait();
            $response = new Response($response);
            $this->assertSame($expected, $limiter->retryAfter($response));
            $limiter->observe($response);
            $this->assertLessThanOrEqual(now()->addHour()->getTimestampMs(), (int) DB::table('provider_controls')->value('next_request_at_ms'));
        }
    }

    public function test_recovery_schedule_is_registered_every_minute_with_a_bounded_overlap_lock(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'pixelforge:recover'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertSame(2, $events->first()->expiresAt);
    }

    public function test_queue_migration_can_resume_after_partial_ddl_without_losing_live_jobs(): void
    {
        $generation = $this->generation(['uncertain' => true]);
        app(GenerationQueueService::class)->dispatch($generation);
        $uuid = $generation->fresh()->queue_job_uuid;
        Schema::table('jobs', fn ($table) => $table->dropUnique('jobs_uuid_unique'));
        Schema::table('generations', fn ($table) => $table->dropIndex('generations_provider_slot_reserved_index'));
        $migration = require database_path('migrations/2026_11_17_000005_add_generation_queue_controls.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame($uuid, DB::table('jobs')->value('uuid'));
        $this->assertTrue($generation->fresh()->provider_slot_reserved);
        $this->assertTrue(Schema::hasIndex('jobs', 'jobs_uuid_unique'));
        $this->assertTrue(Schema::hasIndex('generations', 'generations_provider_slot_reserved_index'));
    }

    private function generation(array $values = []): Generation
    {
        return Generation::create($values + [
            'user_id' => User::factory()->create()->id, 'request_id' => (string) Str::uuid(),
            'prompt' => 'Quiet forest', 'size' => '1024x1024', 'quality' => 'medium',
            'reference_images' => [], 'estimate' => ['typical' => '0.07'], 'next_attempt_at' => now(),
        ]);
    }

    private function object(Generation $generation, string $status): array
    {
        return ['id' => 'gen_'.$generation->id, 'status' => $status, 'metadata' => ['generation_id' => $generation->id]];
    }
}
