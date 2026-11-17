<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletRepository
{
    public function findForUpdate(int $userId): User
    {
        return User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    public function find(int $userId): User
    {
        return User::query()->findOrFail($userId);
    }

    public function save(User $user, string $balance, string $reserved): void
    {
        $user->forceFill(['credit_balance' => $balance, 'credit_reserved' => $reserved])->save();
    }

    public function purchasedAmount(int $userId, string $requestId): ?string
    {
        return DB::table('credit_purchases')->where('user_id', $userId)->where('request_id', $requestId)->value('amount');
    }

    public function recordPurchase(int $userId, string $requestId, string $amount): void
    {
        DB::table('credit_purchases')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'request_id' => $requestId,
            'amount' => $amount, 'created_at' => now(),
        ]);
    }
}
