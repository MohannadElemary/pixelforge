<?php

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'status' => $this->status,
            'estimate' => $this->estimate,
            'rates' => $this->rates,
            'usage' => $this->usage,
            'provider_cost' => $this->provider_cost,
            'actual_cost' => $this->actual_cost,
            'image_url' => $this->imageUrl(),
            'image_url_expires_at' => $this->image_url_expires_at?->toISOString(),
            'message' => $this->message,
            'needs_attention' => $this->needs_attention,
            'created_at' => $this->created_at->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }

    private function imageUrl(): ?string
    {
        $url = $this->image_url;

        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
                ? $url
                : null;
    }
}
