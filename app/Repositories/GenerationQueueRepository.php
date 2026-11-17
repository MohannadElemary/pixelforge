<?php

namespace App\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class GenerationQueueRepository
{
    public function jobs(): Builder
    {
        return DB::table(config('queue.connections.database.table', 'jobs'));
    }

    public function contains(?string $uuid): bool
    {
        return $uuid !== null && $this->jobs()->where('uuid', $uuid)->lockForUpdate()->first() !== null;
    }

    public function uuid(int $jobId): string
    {
        return $this->jobs()->where('id', $jobId)->value('uuid');
    }
}
