<?php

namespace App\Repositories;

use App\Models\Generation;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;

class ReportSummaryRepository
{
    /** Called once for the first success, inside the locked generation's transaction. */
    public function recordSuccess(Generation $generation): void
    {
        $key = [
            'date' => $generation->completed_at->toImmutable()->utc()->toDateString(),
            'user_id' => $generation->user_id,
            // A non-null sentinel makes the unique key protect unattributed history too.
            'provider_key_fingerprint' => $generation->provider_key_fingerprint ?? '',
        ];
        DB::table('report_summaries')->insertOrIgnore($key + [
            'generations' => 0, 'credit_spend' => PricingService::ZERO, 'provider_cost' => PricingService::ZERO,
        ]);
        $summary = DB::table('report_summaries')->where($key)->lockForUpdate()->firstOrFail();
        DB::table('report_summaries')->where($key)->update([
            'generations' => $summary->generations + 1,
            'credit_spend' => bcadd($summary->credit_spend, $generation->actual_cost, 18),
            'provider_cost' => bcadd($summary->provider_cost, $generation->provider_cost, 18),
        ]);
    }
}
