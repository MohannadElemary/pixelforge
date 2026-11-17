<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class WebhookEventRepository
{
    public function record(array $event): bool
    {
        return DB::table('webhook_events')->insertOrIgnore([
            'id' => $event['id'],
            'provider_id' => $event['data']['object']['id'] ?? null,
            'payload' => json_encode($event, JSON_THROW_ON_ERROR),
            'received_at' => now(),
        ]) === 1;
    }

    public function markProcessed(string $eventId): void
    {
        DB::table('webhook_events')->where('id', $eventId)->update(['processed_at' => now()]);
    }

    public function pendingForProvider(string $providerId): LazyCollection
    {
        return DB::table('webhook_events')
            ->where('provider_id', $providerId)
            ->whereNull('processed_at')
            ->lazyById(100);
    }
}
