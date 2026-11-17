<?php

namespace App\Services;

use App\Clients\PixelforgeClient;
use App\Exceptions\GenerationRequestException;
use App\Models\Generation;
use App\Repositories\GenerationRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerationService
{
    public function __construct(
        private readonly GenerationRepository $generations,
        private readonly Pricing $pricing,
        private readonly PixelforgeClient $provider,
    ) {}

    public function configured(): bool
    {
        return $this->provider->configured();
    }

    public function history(int $userId): LengthAwarePaginator
    {
        return $this->generations->historyForUser($userId);
    }

    public function estimate(array $input): array
    {
        try {
            return $this->pricing->estimate($input);
        } catch (Throwable $exception) {
            report($exception);

            throw new GenerationRequestException('Live pricing is temporarily unavailable. Please try again shortly.', previous: $exception);
        }
    }

    public function submit(int $userId, string $requestId, array $input): Generation
    {
        if (! $this->configured()) {
            throw new GenerationRequestException('Generation is unavailable until the service is connected.');
        }

        $generation = $this->generations->findRequest($userId, $requestId)
            ?? $this->generations->createRequest($userId, $requestId, $input, $this->estimate($input));

        foreach ($input as $attribute => $value) {
            if ($generation->getAttribute($attribute) !== $value) {
                throw new GenerationRequestException('This request has already been submitted with different options. Refresh before starting another image.');
            }
        }

        return $generation;
    }

    public function retry(string $generationId): void
    {
        DB::transaction(function () use ($generationId): void {
            $generation = $this->generations->findForUpdate($generationId);

            if ($generation->terminal() || ! $generation->needs_attention) {
                return;
            }

            $this->generations->save($generation, [
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
