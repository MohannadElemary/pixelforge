<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('credit_balance', 48)->default('0.000000000000000000');
            $table->string('credit_reserved', 48)->default('0.000000000000000000');
            $table->boolean('is_admin')->default(false);
        });
        Schema::create('credit_purchases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('amount', 48);
            $table->timestamp('created_at');
            $table->unique(['user_id', 'request_id']);
        });
        Schema::table('generations', function (Blueprint $table): void {
            // Null means this pre-wallet generation must never debit a new wallet.
            $table->string('reserved_cost', 48)->nullable();
            $table->json('reservation_tokens')->nullable();
            $table->timestamp('wallet_settled_at')->nullable();
            $table->string('provider_key_fingerprint', 64)->nullable();
            $table->index(['status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropIndex(['status', 'completed_at']);
            $table->dropColumn(['reserved_cost', 'reservation_tokens', 'wallet_settled_at', 'provider_key_fingerprint']);
        });
        Schema::dropIfExists('credit_purchases');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['credit_balance', 'credit_reserved', 'is_admin']);
        });
    }
};
