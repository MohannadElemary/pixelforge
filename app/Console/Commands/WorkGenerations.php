<?php

namespace App\Console\Commands;

use App\Services\GenerationWorkerService;
use Illuminate\Console\Command;

class WorkGenerations extends Command
{
    protected $signature = 'pixelforge:work {--once : Process one due generation and exit}';

    protected $description = 'Submit and reconcile durable image requests';

    public function handle(GenerationWorkerService $generationWorkerService): int
    {
        do {
            try {
                $worked = $generationWorkerService->tick();
            } catch (\Throwable $e) {
                report($e);
                $worked = false;
            }
            if ($this->option('once')) {
                break;
            }
            // Bound provider traffic even when many requests are due together.
            sleep($worked ? 2 : 1);
        } while (true);

        return self::SUCCESS;
    }
}
