<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class GenerationState
{
    public function __construct(private Pricing $pricing) {}

    public function apply(array $data, ?string $localId = null): bool
    {
        return DB::transaction(function () use ($data, $localId) {
            $providerId = $data['id'] ?? '';
            $status = $data['status'] ?? '';
            if (!is_string($providerId) || !str_starts_with($providerId, 'gen_')
                || !in_array($status, ['queued', 'processing', 'succeeded', 'failed'], true)) {
                throw new UnexpectedValueException('Invalid generation response.');
            }
            $localId ??= $data['metadata']['generation_id'] ?? null;
            $generation = Generation::where('provider_id', $providerId)->lockForUpdate()->first();
            if (!$generation && $localId) $generation = Generation::whereKey($localId)->lockForUpdate()->first();
            if (!$generation) return false;
            if ($generation->provider_id && $generation->provider_id !== $providerId) {
                throw new UnexpectedValueException('Provider generation mismatch.');
            }
            if ($generation->terminal()) return true;
            $generation->provider_id = $providerId;
            $generation->uncertain = false;
            $generation->reconciling = false;
            $generation->needs_attention = false;
            $generation->attempts = 0;
            $generation->message = null;
            $generation->rates = $data['rates'] ?? $generation->rates;
            // All transports share this monotonic transition. Terminal billing is written once.
            if ($status !== 'queued' || $generation->status === 'queued') $generation->status = $status;
            $generation->next_attempt_at = now()->addSeconds(8);
            if ($status === 'succeeded') {
                $cost = $this->pricing->cost($data['usage'] ?? [], $data['rates'] ?? []);
                $generation->usage = $data['usage'];
                $generation->provider_cost = $cost['provider'];
                $generation->actual_cost = $cost['user'];
                $generation->image_url = $data['image_url'] ?? null;
                $generation->image_url_expires_at = $data['image_url_expires_at'] ?? null;
            } elseif ($status === 'failed') {
                $generation->provider_cost = '0.000000000000000000';
                $generation->actual_cost = '0.000000000000000000';
                $generation->message = $data['error']['message'] ?? 'The image could not be generated. You were not charged.';
            }
            if ($generation->terminal()) {
                $generation->completed_at = $data['completed_at'] ?? now();
                $generation->next_attempt_at = null;
            }
            $generation->save();
            return true;
        }, 5);
    }

    public function webhook(array $event): void
    {
        DB::transaction(function () use ($event) {
            if (!DB::table('webhook_events')->insertOrIgnore([
                'id' => $event['id'], 'provider_id' => $event['data']['object']['id'] ?? null,
                'payload' => json_encode($event, JSON_THROW_ON_ERROR), 'received_at' => now(),
            ])) return;
            if ($event['type'] === 'ping' || $this->apply($event['data']['object'])) {
                DB::table('webhook_events')->where('id', $event['id'])->update(['processed_at' => now()]);
            }
        }, 5);
    }

    public function pendingEvents(Generation $generation): void
    {
        foreach (DB::table('webhook_events')->where('provider_id', $generation->provider_id)->whereNull('processed_at')->get() as $event) {
            DB::transaction(function () use ($event, $generation) {
                $this->apply(json_decode($event->payload, true)['data']['object'], $generation->id);
                DB::table('webhook_events')->where('id', $event->id)->update(['processed_at' => now()]);
            }, 5);
        }
    }
}
