<?php

namespace App\Services;

use App\Exceptions\WalletException;
use App\Models\Generation;
use App\Models\User;
use App\Repositories\WalletRepository;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public const PURCHASE_AMOUNTS = ['5.00', '10.00', '25.00'];

    private const MAX_BALANCE = '1000.00';

    public function __construct(private readonly WalletRepository $walletRepository) {}

    public function options(): array
    {
        return array_map(fn (string $amount): array => ['amount' => $amount, 'label' => '$'.rtrim(rtrim($amount, '0'), '.')], self::PURCHASE_AMOUNTS);
    }

    public function summary(User $user): array
    {
        return [
            'balance' => $user->credit_balance,
            'reserved' => $user->credit_reserved,
            'available' => bcsub($user->credit_balance, $user->credit_reserved, 18),
        ];
    }

    public function canAfford(int $userId, string $amount): bool
    {
        return bccomp($this->summary($this->walletRepository->find($userId))['available'], $amount, 18) >= 0;
    }

    public function purchase(int $userId, string $requestId, string $amount): void
    {
        if (! in_array($amount, self::PURCHASE_AMOUNTS, true)) {
            throw new WalletException('Choose one of the available credit amounts.');
        }

        DB::transaction(function () use ($userId, $requestId, $amount): void {
            $user = $this->walletRepository->findForUpdate($userId);
            $existing = $this->walletRepository->purchasedAmount($userId, $requestId);
            if ($existing !== null) {
                if (bccomp($existing, $amount, 18) !== 0) {
                    throw new WalletException('This purchase request was already used for a different amount.');
                }

                return;
            }

            $balance = bcadd($user->credit_balance, $amount, 18);
            if (bccomp($balance, self::MAX_BALANCE, 18) > 0) {
                throw new WalletException('Simulated credits are limited to a $1,000 balance.');
            }
            $this->walletRepository->recordPurchase($userId, $requestId, bcadd($amount, '0', 18));
            $this->walletRepository->save($user, $balance, $user->credit_reserved);
        }, 5);
    }

    /** The caller holds the user row lock and commits the new generation in the same transaction. */
    public function reserve(User $user, string $amount): void
    {
        $this->assertAvailable($user, $amount);
        $this->walletRepository->save($user, $user->credit_balance, bcadd($user->credit_reserved, $amount, 18));
    }

    /** Existing-generation transitions always lock the generation before its user. */
    public function resizeReservation(Generation $generation, string $amount, bool $accepted = false): void
    {
        if ($generation->reserved_cost === null || $generation->wallet_settled_at !== null) {
            return;
        }
        $difference = bcsub($amount, $generation->reserved_cost, 18);
        if (bccomp($difference, '0', 18) === 0) {
            return;
        }
        $user = $this->walletRepository->findForUpdate($generation->user_id);
        if (! $accepted && bccomp($difference, '0', 18) > 0) {
            $this->assertAvailable($user, $difference);
        }
        $this->walletRepository->save($user, $user->credit_balance, bcadd($user->credit_reserved, $difference, 18));
        $generation->reserved_cost = $amount;
    }

    /** The locked generation is the durable, unique settlement record. Its caller saves it atomically. */
    public function settle(Generation $generation, string $actualCost): void
    {
        if ($generation->reserved_cost === null || $generation->wallet_settled_at !== null) {
            return;
        }
        $user = $this->walletRepository->findForUpdate($generation->user_id);
        $this->walletRepository->save(
            $user,
            bcsub($user->credit_balance, $actualCost, 18),
            bcsub($user->credit_reserved, $generation->reserved_cost, 18),
        );
        $generation->wallet_settled_at = now();
    }

    private function assertAvailable(User $user, string $amount): void
    {
        if (bccomp($this->summary($user)['available'], $amount, 18) < 0) {
            throw new WalletException('Not enough available credits for this image. Add simulated credits or choose a smaller image.');
        }
    }
}
