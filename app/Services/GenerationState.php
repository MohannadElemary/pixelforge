<?php

namespace App\Services;

use App\Models\Generation;
use App\Repositories\GenerationRepository;
use App\Repositories\WebhookEventRepository;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class GenerationState
{
    public function __construct(
        private readonly Pricing $pricing,
        private readonly GenerationRepository $generations,
        private readonly WebhookEventRepository $events,
    ) {}

    public function apply(array $data, ?string $localId = null): bool
    {
        return DB::transaction(function () use ($data, $localId): bool {
            $providerId = $data['id'] ?? '';
            $status = $data['status'] ?? '';

            if (! is_string($providerId) || ! str_starts_with($providerId, 'gen_')
                || ! in_array($status, ['queued', 'processing', 'succeeded', 'failed'], true)) {
                throw new UnexpectedValueException('Invalid generation response.');
            }

            $localId ??= $data['metadata']['generation_id'] ?? null;
            $generation = $this->generations->findProviderForUpdate($providerId, $localId);

            if ($generation === null) {
                return false;
            }

            if ($generation->provider_id && $generation->provider_id !== $providerId) {
                throw new UnexpectedValueException('Provider generation mismatch.');
            }

            if ($generation->terminal()) {
                return true;
            }

            $generation->fill([
                'provider_id' => $providerId,
                'uncertain' => false,
                'reconciling' => false,
                'needs_attention' => false,
                'attempts' => 0,
                'message' => null,
                'rates' => $data['rates'] ?? $generation->rates,
                'next_attempt_at' => now()->addSeconds(8),
            ]);

            // Every transport uses this monotonic transition; terminal billing is written once.
            if ($status !== 'queued' || $generation->status === 'queued') {
                $generation->status = $status;
            }

            if ($status === 'succeeded') {
                $cost = $this->pricing->cost($data['usage'] ?? [], $data['rates'] ?? []);
                $generation->fill([
                    'usage' => $data['usage'],
                    'provider_cost' => $cost['provider'],
                    'actual_cost' => $cost['user'],
                    'image_url' => $data['image_url'] ?? null,
                    'image_url_expires_at' => $data['image_url_expires_at'] ?? null,
                ]);
            } elseif ($status === 'failed') {
                $generation->fill([
                    'provider_cost' => Pricing::ZERO,
                    'actual_cost' => Pricing::ZERO,
                    'message' => $data['error']['message'] ?? 'The image could not be generated. You were not charged.',
                ]);
            }

            if ($generation->terminal()) {
                $generation->completed_at = $data['completed_at'] ?? now();
                $generation->next_attempt_at = null;
            }

            $this->generations->save($generation);

            return true;
        }, 5);
    }

    public function webhook(array $event): void
    {
        DB::transaction(function () use ($event): void {
            if (! $this->events->record($event)) {
                return;
            }

            if ($event['type'] === 'ping' || $this->apply($event['data']['object'])) {
                $this->events->markProcessed($event['id']);
            }
        }, 5);
    }

    public function pendingEvents(Generation $generation): void
    {
        if ($generation->provider_id === null) {
            return;
        }

        foreach ($this->events->pendingForProvider($generation->provider_id) as $event) {
            DB::transaction(function () use ($event, $generation): void {
                $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
                $this->apply($payload['data']['object'], $generation->id);
                $this->events->markProcessed($event->id);
            }, 5);
        }
    }
}
