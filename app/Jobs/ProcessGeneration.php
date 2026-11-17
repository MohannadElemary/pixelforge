<?php

namespace App\Jobs;

use App\Services\GenerationWorkerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessGeneration implements ShouldQueue
{
    use Queueable;

    public int $timeout = 45;

    public int $tries = 0;

    public int $backoff = 60;

    public function __construct(public readonly string $generationId)
    {
        $this->onQueue('generations')->afterCommit();
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->generationId))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(GenerationWorkerService $generationWorkerService): void
    {
        $jobUuid = $this->job?->uuid();
        $generationWorkerService->process($this->generationId, $jobUuid);
        $delay = $generationWorkerService->nextDelay($this->generationId, $jobUuid);
        if ($delay !== null) {
            $this->release($delay);
        }
    }
}
