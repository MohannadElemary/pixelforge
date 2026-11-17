<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deploy in maintenance mode with workers stopped. MySQL DDL may survive a
        // failed backfill, so a retry rebuilds the same table instead of duplicating it.
        if (! Schema::hasTable('report_summaries')) {
            Schema::create('report_summaries', function (Blueprint $table): void {
                $table->date('date');
                $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
                $table->string('provider_key_fingerprint', 64);
                $table->unsignedBigInteger('generations');
                // Store decimal strings: SQLite numeric SUM would lose precision.
                $table->string('credit_spend');
                $table->string('provider_cost');
                $table->primary(['date', 'user_id', 'provider_key_fingerprint'], 'report_summaries_group_primary');
            });
        }

        DB::transaction(function (): void {
            DB::table('report_summaries')->delete();
            DB::table('generations')
                ->where('status', 'succeeded')
                ->whereNotNull('completed_at')
                ->select(['id', 'user_id', 'completed_at', 'provider_key_fingerprint', 'actual_cost', 'provider_cost'])
                ->chunkById(500, function ($generations): void {
                    $groups = [];
                    foreach ($generations as $generation) {
                        // Existing completion timestamps are stored in UTC. Never infer
                        // attribution for rows created before key tracking was introduced.
                        $key = [
                            'date' => substr($generation->completed_at, 0, 10),
                            'user_id' => $generation->user_id,
                            'provider_key_fingerprint' => $generation->provider_key_fingerprint ?? '',
                        ];
                        $id = implode(':', $key);
                        $groups[$id] ??= $key + [
                            'generations' => 0,
                            'credit_spend' => '0.000000000000000000',
                            'provider_cost' => '0.000000000000000000',
                        ];
                        $groups[$id]['generations']++;
                        $groups[$id]['credit_spend'] = bcadd($groups[$id]['credit_spend'], $generation->actual_cost, 18);
                        $groups[$id]['provider_cost'] = bcadd($groups[$id]['provider_cost'], $generation->provider_cost, 18);
                    }

                    foreach ($groups as $group) {
                        $key = array_intersect_key($group, array_flip(['date', 'user_id', 'provider_key_fingerprint']));
                        $existing = DB::table('report_summaries')->where($key)->first();
                        if ($existing === null) {
                            DB::table('report_summaries')->insert($group);
                        } else {
                            DB::table('report_summaries')->where($key)->update([
                                'generations' => $existing->generations + $group['generations'],
                                'credit_spend' => bcadd($existing->credit_spend, $group['credit_spend'], 18),
                                'provider_cost' => bcadd($existing->provider_cost, $group['provider_cost'], 18),
                            ]);
                        }
                    }
                });
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_summaries');
    }
};
