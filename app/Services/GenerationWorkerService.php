<?php

namespace App\Services;

use App\Clients\PixelforgeClient;
use App\Exceptions\WalletException;
use App\Models\Generation;
use App\Repositories\GenerationRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Throwable;
use TypeError;
use UnexpectedValueException;

class GenerationWorkerService
{
    private const MAX_ATTEMPTS = 5;

    private const CONCURRENCY_LIMIT = 3;

    public function __construct(
        private readonly GenerationStateService $generationStateService,
        private readonly GenerationRepository $generationRepository,
        private readonly PixelforgeClient $pixelforgeClient,
        private readonly PricingService $pricingService,
        private readonly WalletService $walletService,
    ) {}

    public function tick(): bool
    {
        $generation = $this->claim();

        if ($generation === null) {
            return false;
        }

        if (! $this->pixelforgeClient->configured()) {
            $this->update($generation, [
                'needs_attention' => true,
                'message' => 'Generation is paused while the service connection is configured.',
            ]);

            return true;
        }

        if ($generation->attempts > self::MAX_ATTEMPTS) {
            $this->retry($generation, 'The previous check was interrupted. Confirming the result safely.', $generation->uncertain);

            return true;
        }

        if (($generation->uncertain || $generation->provider_id !== null || $generation->reconciling) && $generation->provider_key_fingerprint !== null
            && $generation->provider_key_fingerprint !== $this->pixelforgeClient->keyFingerprint()) {
            $this->update($generation, [
                'needs_attention' => true,
                'message' => 'This request is awaiting confirmation with its original provider connection. Its credit hold is retained.',
            ]);

            return true;
        }

        if (! $generation->provider_id && ! $generation->uncertain && ! $generation->reconciling && $generation->reserved_cost !== null) {
            // Pricing I/O happens before submission: its failure cannot imply an accepted image.
            try {
                $estimate = $this->pricingService->estimate([
                    'prompt' => $generation->prompt, 'size' => $generation->size,
                    'quality' => $generation->quality, 'reference_images' => $generation->reference_images,
                ], $this->pricingService->fresh());
            } catch (Throwable $exception) {
                report($exception);
                $this->retry($generation, 'Current prices are unavailable. Checking again before ordering your image.', false);

                return true;
            }
            if (! $this->prepareReservation($generation, $estimate)) {
                return true;
            }
        }

        try {
            if ($generation->reconciling) {
                $response = $this->pixelforgeClient->list($generation->reconcile_cursor);
            } elseif ($generation->provider_id) {
                $response = $this->pixelforgeClient->retrieve($generation->provider_id);
            } else {
                // Persist uncertainty without changing the claim's previous outcome knowledge.
                $this->update($generation, ['uncertain' => true]);
                $response = $this->pixelforgeClient->submit($generation);
            }

            if (! $response->successful()) {
                $this->failure($generation, $response);
            } elseif ($generation->reconciling) {
                $this->reconcile($generation, $response->json());
            } else {
                $this->generationStateService->apply($response->json(), $generation->id);
                $this->generationStateService->pendingEvents($this->generationRepository->find($generation->id));
            }
        } catch (ConnectionException) {
            $this->retry($generation, 'The provider is taking longer to respond. Checking safely for your image.', true);
        } catch (UnexpectedValueException|TypeError $exception) {
            report($exception);
            $this->retry($generation, 'The provider returned an incomplete response. Checking again safely.', true);
        }

        return true;
    }

    private function prepareReservation(Generation $generation, array $estimate): bool
    {
        return DB::transaction(function () use ($generation, $estimate): bool {
            $current = $this->generationRepository->findForUpdate($generation->id);
            if ($current->terminal() || $current->provider_id !== null) {
                return false;
            }
            try {
                $this->walletService->resizeReservation($current, $estimate['max']);
            } catch (WalletException) {
                $this->walletService->settle($current, PricingService::ZERO);
                $this->generationRepository->save($current, [
                    'status' => 'failed', 'provider_cost' => PricingService::ZERO, 'actual_cost' => PricingService::ZERO,
                    'completed_at' => now(), 'next_attempt_at' => null,
                    'message' => 'Prices changed and your available credits no longer cover this image. You were not charged.',
                ]);

                return false;
            }
            $this->generationRepository->save($current, [
                'reservation_tokens' => $estimate['max_tokens'],
                'provider_key_fingerprint' => $this->pixelforgeClient->keyFingerprint(),
            ]);

            return true;
        }, 5);
    }

    private function claim(): ?Generation
    {
        return DB::transaction(function (): ?Generation {
            $capacityFull = $this->generationRepository->capacityIsFull(self::CONCURRENCY_LIMIT);
            $generation = $this->generationRepository->nextDueForUpdate($capacityFull);

            if ($generation !== null) {
                // Persist the attempt before I/O; an interrupted process still consumes an attempt.
                $this->generationRepository->save($generation, [
                    'attempts' => $generation->attempts + 1,
                    'next_attempt_at' => now()->addSeconds(60),
                ]);
            }

            return $generation;
        }, 5);
    }

    private function failure(Generation $generation, Response $response): void
    {
        $code = $response->json('error.code');
        $knownRejection = in_array($code, [
            'overloaded',
            'rate_limit_exceeded',
            'concurrency_limit_exceeded',
            'daily_limit_exceeded',
            'invalid_api_key',
            'validation_failed',
            'content_policy_violation',
        ], true);
        $uncertain = $generation->uncertain || ! $knownRejection;
        $retryable = $code !== 'daily_limit_exceeded' && (
            in_array($code, ['overloaded', 'rate_limit_exceeded', 'concurrency_limit_exceeded'], true)
            || $response->serverError()
            || $response->status() === 429
        );

        if ($retryable) {
            $this->retry($generation, 'The provider is busy. Your request will retry automatically.', $uncertain, (int) $response->header('Retry-After'));
        } elseif (! $generation->provider_id && ! $generation->reconciling && ! $uncertain) {
            $this->update($generation, [
                'status' => 'failed',
                'provider_cost' => PricingService::ZERO,
                'actual_cost' => PricingService::ZERO,
                'uncertain' => false,
                'completed_at' => now(),
                'next_attempt_at' => null,
                'message' => match ($code) {
                    'content_policy_violation' => 'The provider could not accept this prompt. Try a different description. You were not charged.',
                    'daily_limit_exceeded' => 'The daily generation limit has been reached. Please try tomorrow. You were not charged.',
                    default => 'The provider could not accept this request. You were not charged.',
                },
            ]);
        } else {
            $this->update($generation, [
                'needs_attention' => true,
                'uncertain' => $uncertain,
                'message' => 'We could not confirm the final result. Check again to resume safely; no duplicate image will be ordered.',
            ]);
        }
    }

    private function retry(Generation $generation, string $message, bool $uncertain, int $retryAfter = 0): void
    {
        $delay = max($retryAfter, min(120, 2 ** min($generation->attempts, 7)));
        $updates = [
            'uncertain' => $uncertain,
            'message' => $message,
            'next_attempt_at' => now()->addSeconds($delay),
        ];

        if ($generation->attempts >= self::MAX_ATTEMPTS) {
            if (! $generation->provider_id && ! $generation->reconciling && $uncertain) {
                $updates += [
                    'reconciling' => true,
                    'attempts' => 0,
                    'reconcile_cursor' => null,
                    'reconcile_pages' => 0,
                ];
                $updates['message'] = 'Checking the provider’s history to confirm whether your image was accepted.';
            } elseif (! $generation->provider_id && ! $uncertain) {
                $updates += [
                    'status' => 'failed',
                    'provider_cost' => PricingService::ZERO,
                    'actual_cost' => PricingService::ZERO,
                    'completed_at' => now(),
                ];
                $updates['next_attempt_at'] = null;
                $updates['message'] = 'The provider is still busy after several attempts. You were not charged. Please try again later.';
            } else {
                $updates['needs_attention'] = true;
                $updates['message'] = 'Automatic checks are paused after several attempts. Check again to safely resume confirming this image.';
            }
        }

        $this->update($generation, $updates);
    }

    private function reconcile(Generation $generation, array $response): void
    {
        if (! isset($response['data'], $response['has_more']) || ! is_array($response['data'])) {
            throw new UnexpectedValueException('Invalid generation list.');
        }

        foreach ($response['data'] as $data) {
            if (($data['metadata']['generation_id'] ?? null) === $generation->id) {
                $this->generationStateService->apply($data, $generation->id);
                $this->generationStateService->pendingEvents($this->generationRepository->find($generation->id));

                return;
            }
        }

        if ($response['has_more'] && ! empty($response['next_cursor']) && $generation->reconcile_pages < 19) {
            $this->update($generation, [
                'reconcile_cursor' => $response['next_cursor'],
                'reconcile_pages' => $generation->reconcile_pages + 1,
                'attempts' => 0,
                'next_attempt_at' => now()->addSeconds(5),
            ]);
        } else {
            $this->update($generation, [
                'needs_attention' => true,
                'message' => 'Acceptance is still unconfirmed. Check again to safely resend the same request without creating a duplicate.',
            ]);
        }
    }

    private function update(Generation $generation, array $values): void
    {
        DB::transaction(function () use ($generation, $values): void {
            $current = $this->generationRepository->findForUpdate($generation->id);

            if ($current->terminal()) {
                return;
            }

            // An early webhook may have established a provider ID during this request.
            if (! $generation->provider_id && $current->provider_id) {
                return;
            }

            $current->fill($values);
            if ($current->status === 'failed') {
                $this->walletService->settle($current, PricingService::ZERO);
            }
            $this->generationRepository->save($current);
        }, 5);
    }
}
