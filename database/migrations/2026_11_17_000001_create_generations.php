<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('provider_id')->nullable()->unique();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');
            $table->string('status')->default('queued');
            $table->json('estimate');
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->string('provider_cost')->nullable();
            $table->string('actual_cost')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->boolean('uncertain')->default(false);
            $table->boolean('needs_attention')->default(false);
            $table->boolean('reconciling')->default(false);
            $table->text('reconcile_cursor')->nullable();
            $table->unsignedInteger('reconcile_pages')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_id']);
        });
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('provider_id')->nullable()->index();
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('generations');
    }
};
