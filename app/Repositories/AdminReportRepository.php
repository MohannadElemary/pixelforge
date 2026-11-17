<?php

namespace App\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class AdminReportRepository
{
    public function completedBetween(string $startDate, string $endDate): LazyCollection
    {
        return DB::table('generations')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', 'succeeded')
            ->where('generations.completed_at', '>=', $startDate.' 00:00:00')
            ->where('generations.completed_at', '<', CarbonImmutable::parse($endDate, 'UTC')->addDay()->format('Y-m-d 00:00:00'))
            ->select([
                'generations.id', 'generations.user_id', 'users.name', 'users.email',
                'generations.completed_at', 'generations.actual_cost', 'generations.provider_cost',
                'generations.provider_key_fingerprint',
            ])
            // Completion timestamps are stored in UTC. Both SQLite and MySQL support DATE().
            ->orderByRaw('DATE(generations.completed_at) DESC')
            ->orderBy('generations.user_id')
            ->orderBy('generations.id')
            // One SELECT keeps concurrent completions from shifting offset-based batches.
            ->cursor();
    }
}
