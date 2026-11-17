<?php

namespace App\Console\Commands;

use App\Services\GenerationQueueService;
use Illuminate\Console\Command;

class RecoverGenerations extends Command
{
    protected $signature = 'pixelforge:recover';

    protected $description = 'Requeue a bounded batch of overdue generation checks';

    public function handle(GenerationQueueService $generationQueueService): int
    {
        $this->info('Scheduled '.$generationQueueService->recover().' overdue generation checks.');

        return self::SUCCESS;
    }
}
