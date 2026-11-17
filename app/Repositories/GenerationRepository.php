<?php

namespace App\Repositories;

use App\Models\Generation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GenerationRepository
{
    public function __construct(private readonly GenerationQueueRepository $generationQueueRepository) {}

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
        // Current locking read follows the shared admission lock, including on MySQL.
        return Generation::query()->where('provider_slot_reserved', true)
            ->limit($limit)->lockForUpdate()->pluck('id')->count() >= $limit;
    }

    public function overdueForDispatch(): Collection
    {
        return $this->pending()->where('needs_attention', false)
            ->where('next_attempt_at', '<=', now()->subMinute())
            ->whereNotExists($this->generationQueueRepository->jobs()->selectRaw('1')
                ->whereColumn('uuid', 'generations.queue_job_uuid'))
            ->orderBy('next_attempt_at')->orderBy('id')
            ->limit(100)->get();
    }

    public function findOptionalForUpdate(string $id): ?Generation
    {
        return Generation::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findOptional(string $id): ?Generation
    {
        return Generation::query()->find($id);
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
