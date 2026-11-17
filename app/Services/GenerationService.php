<?php

namespace App\Services;

use App\Clients\PixelforgeClient;
use App\Exceptions\GenerationRequestException;
use App\Exceptions\WalletException;
use App\Models\Generation;
use App\Repositories\GenerationRepository;
use App\Repositories\WalletRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerationService
{
    public function __construct(
        private readonly GenerationRepository $generationRepository,
        private readonly PricingService $pricingService,
        private readonly PixelforgeClient $pixelforgeClient,
        private readonly WalletService $walletService,
        private readonly WalletRepository $walletRepository,
    ) {}

    public function configured(): bool
    {
        return $this->pixelforgeClient->configured();
    }

    public function history(int $userId): LengthAwarePaginator
    {
        return $this->generationRepository->historyForUser($userId);
    }

    public function estimate(array $input, ?int $userId = null): array
    {
        try {
            $estimate = $this->pricingService->estimate($input);

        } catch (Throwable $exception) {
            report($exception);

            throw new GenerationRequestException('Live pricing is temporarily unavailable. Please try again shortly.', previous: $exception);
        }

        return $estimate + [
            'reservation' => $estimate['max'],
            'can_afford' => $userId !== null && $this->walletService->canAfford($userId, $estimate['max']),
        ];
    }

    public function submit(int $userId, string $requestId, array $input): Generation
    {
        if (! $this->configured()) {
            throw new GenerationRequestException('Generation is unavailable until the service is connected.');
        }

        $existing = $this->generationRepository->findRequest($userId, $requestId);
        if ($existing !== null) {
            $this->assertSameRequest($existing, $input);

            return $existing;
        }
        $estimate = $this->estimate($input);

        try {
            return DB::transaction(function () use ($userId, $requestId, $input, $estimate): Generation {
                $user = $this->walletRepository->findForUpdate($userId);
                // No generation lock here: existing-row transitions lock generation then user.
                // The user lock serializes admission; this fresh snapshot sees a concurrent winner.
                $existing = $this->generationRepository->findRequest($userId, $requestId);
                if ($existing !== null) {
                    $this->assertSameRequest($existing, $input);

                    return $existing;
                }
                $this->walletService->reserve($user, $estimate['max']);

                return $this->generationRepository->createRequest($userId, $requestId, $input, $estimate, $this->pixelforgeClient->keyFingerprint());
            }, 5);
        } catch (WalletException $exception) {
            throw new GenerationRequestException($exception->getMessage(), previous: $exception);
        }
    }

    private function assertSameRequest(Generation $generation, array $input): void
    {
        foreach ($input as $attribute => $value) {
            if ($generation->getAttribute($attribute) !== $value) {
                throw new GenerationRequestException('This request has already been submitted with different options. Refresh before starting another image.');
            }
        }
    }

    public function retry(string $generationId): void
    {
        DB::transaction(function () use ($generationId): void {
            $generation = $this->generationRepository->findForUpdate($generationId);

            if ($generation->terminal() || ! $generation->needs_attention) {
                return;
            }

            $this->generationRepository->save($generation, [
                'needs_attention' => false,
                'reconciling' => false,
                'reconcile_pages' => 0,
                'reconcile_cursor' => null,
                'attempts' => 0,
                'next_attempt_at' => now(),
                'message' => 'Checking again safely.',
            ]);
        }, 5);
    }
}
