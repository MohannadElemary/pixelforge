<?php

namespace App\Repositories;

use App\Models\Generation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class GenerationRepository
{
    public function historyForUser(int $userId): LengthAwarePaginator
    {
        return Generation::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(12);
    }

    public function findRequest(int $userId, string $requestId): ?Generation
    {
        return Generation::query()
            ->where('user_id', $userId)
            ->where('request_id', $requestId)
            ->first();
    }

    public function createRequest(int $userId, string $requestId, array $input, array $estimate, ?string $keyFingerprint): Generation
    {
        // Admission holds the user lock and reserves funds in this same transaction.
        return Generation::create([
            'user_id' => $userId, 'request_id' => $requestId,
            'prompt' => $input['prompt'], 'size' => $input['size'], 'quality' => $input['quality'],
            'reference_images' => $input['reference_images'], 'estimate' => $estimate,
            'reserved_cost' => $estimate['max'], 'reservation_tokens' => $estimate['max_tokens'],
            'provider_key_fingerprint' => $keyFingerprint, 'next_attempt_at' => now(),
        ]);
    }

    public function capacityIsFull(int $limit): bool
    {
        return $this->pending()
            ->whereNotNull('provider_id')
            ->where('needs_attention', false)
            ->limit($limit)
            ->pluck('id')
            ->count() >= $limit;
    }

    public function nextDueForUpdate(bool $existingOnly): ?Generation
    {
        return $this->pending()
            ->when($existingOnly, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNotNull('provider_id')
                ->orWhere('uncertain', true)
                ->orWhere('reconciling', true)))
            ->where('needs_attention', false)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
    }

    public function findForUpdate(string $id): Generation
    {
        return Generation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function findProviderForUpdate(string $providerId, ?string $localId): ?Generation
    {
        $generation = Generation::query()->where('provider_id', $providerId)->lockForUpdate()->first();

        if ($generation === null && $localId !== null) {
            $generation = Generation::query()->whereKey($localId)->lockForUpdate()->first();
        }

        return $generation;
    }

    public function find(string $id): Generation
    {
        return Generation::query()->findOrFail($id);
    }

    public function save(Generation $generation, array $attributes = []): void
    {
        $generation->fill($attributes)->save();
    }

    private function pending(): Builder
    {
        return Generation::query()->whereIn('status', ['queued', 'processing']);
    }
}
