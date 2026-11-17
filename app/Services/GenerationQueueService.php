<?php

namespace App\Services;

use App\Jobs\ProcessGeneration;
use App\Models\Generation;
use App\Repositories\GenerationQueueRepository;
use App\Repositories\GenerationRepository;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Throwable;

class GenerationQueueService
{
    public function __construct(
        private readonly GenerationRepository $generationRepository,
        private readonly GenerationQueueRepository $generationQueueRepository,
    ) {}

    public function dispatch(Generation $generation): void
    {
        DB::afterCommit(function () use ($generation): void {
            try {
                $this->enqueue($generation->id);
            } catch (Throwable $exception) {
                // Admission is committed; recovery can repair a missing queue receipt.
                report($exception);
            }
        });
    }

    public function recover(): int
    {
        $count = 0;
        foreach ($this->generationRepository->overdueForDispatch() as $generation) {
            $count += $this->enqueue($generation->id) ? 1 : 0;
        }

        return $count;
    }

    private function enqueue(string $generationId): bool
    {
        $queue = Queue::connection();
        if (! $queue instanceof DatabaseQueue || $queue->getDatabase() !== DB::connection()
            || config('queue.default') !== 'database') {
            throw new LogicException('Generation queue receipts require the database queue on the application database.');
        }

        return DB::transaction(function () use ($generationId, $queue): bool {
            $generation = $this->generationRepository->findOptionalForUpdate($generationId);
            if ($generation === null || $generation->terminal() || $generation->needs_attention
                || $generation->next_attempt_at === null || $this->generationQueueRepository->contains($generation->queue_job_uuid)) {
                return false;
            }
            // This transaction starts after admission commits; queue insertion and ownership commit together.
            $jobId = $queue->push((new ProcessGeneration($generationId))->beforeCommit(), '', 'generations');
            $this->generationRepository->save($generation, ['queue_job_uuid' => $this->generationQueueRepository->uuid($jobId)]);

            return true;
        }, 5);
    }
}
