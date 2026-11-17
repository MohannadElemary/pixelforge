<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class AdminReportRepository
{
    public function summariesBetween(string $startDate, string $endDate): LazyCollection
    {
        return DB::table('report_summaries')
            ->join('users', 'users.id', '=', 'report_summaries.user_id')
            ->whereBetween('report_summaries.date', [$startDate, $endDate])
            ->select([
                'report_summaries.date', 'report_summaries.user_id', 'users.name', 'users.email',
                'report_summaries.generations', 'report_summaries.credit_spend', 'report_summaries.provider_cost',
                'report_summaries.provider_key_fingerprint',
            ])
            ->orderByDesc('report_summaries.date')
            ->orderBy('report_summaries.user_id')
            ->orderBy('report_summaries.provider_key_fingerprint')
            // One snapshot supplies totals and pagination; reads scale by groups, not images.
            ->cursor();
    }
}
