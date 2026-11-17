<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at', 'id'], 'generations_owner_history_index');
            $table->index(['needs_attention', 'status', 'next_attempt_at'], 'generations_due_index');
        });
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->index(['provider_id', 'processed_at', 'id'], 'webhook_events_pending_index');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table): void {
            $table->dropIndex('webhook_events_pending_index');
        });
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropIndex('generations_owner_history_index');
            $table->dropIndex('generations_due_index');
        });
    }
};
