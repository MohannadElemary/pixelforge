<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
        if (in_array(Schema::getColumnType('jobs', 'attempts'), ['tinyint', 'smallint', 'mediumint'], true)) {
            Schema::table('jobs', fn (Blueprint $table) => $table->unsignedInteger('attempts')->change());
        }
        if (! Schema::hasColumn('jobs', 'uuid')) {
            // Laravel preserves payload.uuid when releasing a job, but replaces its numeric ID.
            $expression = DB::getDriverName() === 'sqlite'
                ? "json_extract(payload, '$.uuid')"
                : "json_unquote(json_extract(payload, '$.uuid'))";
            Schema::table('jobs', fn (Blueprint $table) => $table->string('uuid', 36)->nullable()->virtualAs($expression));
        }
        if (! Schema::hasIndex('jobs', 'jobs_uuid_unique')) {
            Schema::table('jobs', fn (Blueprint $table) => $table->unique('uuid'));
        }
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
        if (! Schema::hasTable('provider_controls')) {
            Schema::create('provider_controls', function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->unsignedBigInteger('next_request_at_ms')->default(0);
            });
        }
        // One provider account across all API keys used by this installation.
        DB::table('provider_controls')->insertOrIgnore(['id' => 1]);
        if (! Schema::hasColumn('generations', 'provider_slot_reserved')) {
            Schema::table('generations', fn (Blueprint $table) => $table->boolean('provider_slot_reserved')->default(false));
        }
        if (! Schema::hasIndex('generations', 'generations_provider_slot_reserved_index')) {
            Schema::table('generations', fn (Blueprint $table) => $table->index('provider_slot_reserved'));
        }
        if (! Schema::hasColumn('generations', 'queue_job_uuid')) {
            Schema::table('generations', fn (Blueprint $table) => $table->uuid('queue_job_uuid')->nullable());
        }
        DB::table('generations')->whereIn('status', ['queued', 'processing'])
            ->where(fn ($query) => $query->whereNotNull('provider_id')->orWhere('uncertain', true)->orWhere('reconciling', true))
            ->update(['provider_slot_reserved' => true]);
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropIndex(['provider_slot_reserved']);
            $table->dropColumn(['provider_slot_reserved', 'queue_job_uuid']);
        });
        Schema::dropIfExists('provider_controls');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
    }
};
