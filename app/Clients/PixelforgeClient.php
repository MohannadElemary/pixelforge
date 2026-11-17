<?php

namespace App\Clients;

use App\Models\Generation;
use App\Services\ProviderRateLimitService;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class PixelforgeClient
{
    public function __construct(private readonly ProviderRateLimitService $providerRateLimitService) {}

    public function configured(): bool
    {
        return filled(config('services.pixelforge.key'));
    }

    public function keyFingerprint(): ?string
    {
        return $this->configured() ? hash('sha256', config('services.pixelforge.key')) : null;
    }

    public function usage(string $startDate, string $endDate): array
    {
        $data = $this->request('GET', '/usage', ['query' => ['start_date' => $startDate, 'end_date' => $endDate], 'timeout' => 8], waitForPermit: true)->throw()->json();
        if (! is_array($data)) {
            throw new UnexpectedValueException('Invalid usage response.');
        }

        return $data;
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

    public function submit(Generation $generation, Closure $beforeSending): Response
    {
        return $this->request('POST', '/generations', [
            'headers' => ['Idempotency-Key' => $generation->id],
            'json' => [
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_id' => $generation->id],
            ],
        ], $beforeSending);
    }

    public function retrieve(string $providerId): Response
    {
        return $this->request('GET', '/generations/'.rawurlencode($providerId));
    }

    public function list(?string $cursor): Response
    {
        return $this->request('GET', '/generations', ['query' => array_filter([
            'limit' => 100,
            'cursor' => $cursor,
        ])]);
    }

    private function request(string $method, string $path, array $options = [], ?Closure $beforeSending = null, bool $waitForPermit = false): Response
    {
        $this->providerRateLimitService->reserve($waitForPermit);
        $beforeSending?->__invoke();
        $response = $this->authenticated()->send($method, $path, $options);
        $this->providerRateLimitService->observe($response);

        return $response;
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
