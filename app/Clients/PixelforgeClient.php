<?php

namespace App\Clients;

use App\Models\Generation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class PixelforgeClient
{
    public function configured(): bool
    {
        return filled(config('services.pixelforge.key'));
    }

    public function pricing(): array
    {
        $data = Http::baseUrl(config('services.pixelforge.url'))
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(8)
            ->get('/pricing')
            ->throw()
            ->json();

        if (! is_array($data)) {
            throw new UnexpectedValueException('Invalid pricing response.');
        }

        return $data;
    }

    public function submit(Generation $generation): Response
    {
        return $this->authenticated()
            ->withHeader('Idempotency-Key', $generation->id)
            ->post('/generations', [
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_id' => $generation->id],
            ]);
    }

    public function retrieve(string $providerId): Response
    {
        return $this->authenticated()->get('/generations/'.rawurlencode($providerId));
    }

    public function list(?string $cursor): Response
    {
        return $this->authenticated()->get('/generations', array_filter([
            'limit' => 100,
            'cursor' => $cursor,
        ]));
    }

    private function authenticated(): PendingRequest
    {
        return Http::baseUrl(config('services.pixelforge.url'))
            ->acceptJson()
            ->withToken(config('services.pixelforge.key'))
            ->connectTimeout(5)
            ->timeout(20);
    }
}
