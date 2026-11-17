<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Generation extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'request_id', 'provider_id', 'prompt', 'size', 'quality', 'reference_images',
        'status', 'estimate', 'rates', 'usage', 'provider_cost', 'actual_cost', 'image_url',
        'image_url_expires_at', 'message', 'attempts', 'uncertain', 'needs_attention',
        'reconciling', 'reconcile_cursor', 'reconcile_pages', 'next_attempt_at', 'completed_at',
        'reserved_cost', 'reservation_tokens', 'wallet_settled_at', 'provider_key_fingerprint',
    ];

    protected $hidden = ['request_id', 'user_id', 'reconcile_cursor', 'provider_key_fingerprint'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'attempts' => 'integer', 'reconcile_pages' => 'integer',
            'reservation_tokens' => 'array', 'wallet_settled_at' => 'datetime',
            'reference_images' => 'array', 'estimate' => 'array', 'rates' => 'array', 'usage' => 'array',
            'uncertain' => 'boolean', 'needs_attention' => 'boolean', 'reconciling' => 'boolean',
            'next_attempt_at' => 'datetime', 'completed_at' => 'datetime', 'image_url_expires_at' => 'datetime',
        ];
    }

    public function terminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }
}
