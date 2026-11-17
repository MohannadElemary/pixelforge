<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GenerationHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'data' => GenerationResource::collection($this->resource->getCollection())->resolve($request),
            'total' => $this->resource->total(),
            'last_page' => $this->resource->lastPage(),
            'current_page' => $this->resource->currentPage(),
            'prev_page_url' => $this->resource->previousPageUrl(),
            'next_page_url' => $this->resource->nextPageUrl(),
        ];
    }
}
