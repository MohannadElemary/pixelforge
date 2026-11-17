<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Generation extends Model
{
    use HasUuids;

    protected $guarded = [];
    protected $hidden = ['request_id', 'user_id', 'reconcile_cursor'];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array', 'estimate' => 'array', 'rates' => 'array', 'usage' => 'array',
            'uncertain' => 'boolean', 'needs_attention' => 'boolean', 'reconciling' => 'boolean',
            'next_attempt_at' => 'datetime', 'completed_at' => 'datetime', 'image_url_expires_at' => 'datetime',
        ];
    }

    public function terminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }

    public function providerPayload(): array
    {
        return [
            'prompt' => $this->prompt, 'size' => $this->size, 'quality' => $this->quality,
            'reference_images' => $this->reference_images, 'metadata' => ['generation_id' => $this->id],
        ];
    }
}
