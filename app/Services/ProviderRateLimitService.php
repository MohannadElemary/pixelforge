<?php

namespace App\Services;

use App\Exceptions\ProviderRateLimitException;
use App\Repositories\ProviderControlRepository;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

class ProviderRateLimitService
{
    public function __construct(private readonly ProviderControlRepository $providerControlRepository) {}

    public function reserve(bool $waitForPermit = false): void
    {
        $delay = DB::transaction(function () use ($waitForPermit): int {
            $control = $this->providerControlRepository->lock();
            $milliseconds = now()->getTimestampMs();
            $interval = max(1000, (int) config('services.pixelforge.request_interval_ms', 1000));
            $delay = max(0, $control->next_request_at_ms - $milliseconds);
            if ($delay > ($waitForPermit ? min(1000, $interval) : 0)) {
                throw new ProviderRateLimitException((int) ceil($delay / 1000));
            }
            // Interactive usage may reserve the next slot; workers always release immediately.
            $this->providerControlRepository->scheduleRequest($milliseconds + $delay + $interval);

            return $delay;
        }, 5);

        if ($delay > 0) {
            Sleep::usleep($delay * 1000);
        }
    }

    public function observe(Response $response): void
    {
        if ($response->status() !== 429) {
            return;
        }
        $until = now()->addSeconds($this->retryAfter($response))->getTimestampMs();
        DB::transaction(function () use ($until): void {
            $control = $this->providerControlRepository->lock();
            $this->providerControlRepository->scheduleRequest(max($control->next_request_at_ms, $until));
        }, 5);
    }

    public function retryAfter(Response $response): int
    {
        $value = $response->header('Retry-After');
        if (ctype_digit((string) $value)) {
            return min(3600, max(1, (int) $value));
        }
        $timestamp = $value ? strtotime($value) : false;

        return $timestamp === false ? 1 : min(3600, max(1, $timestamp - now()->timestamp));
    }
}
