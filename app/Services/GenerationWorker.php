<?php

namespace App\Services;

use App\Clients\PixelforgeClient;
use App\Models\Generation;
use App\Repositories\GenerationRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use TypeError;
use UnexpectedValueException;

class GenerationWorker
{
    private const MAX_ATTEMPTS = 5;

    private const CONCURRENCY_LIMIT = 3;

    public function __construct(
        private readonly GenerationState $state,
        private readonly GenerationRepository $generations,
        private readonly PixelforgeClient $provider,
    ) {}

    public function tick(): bool
    {
        $generation = $this->claim();

        if ($generation === null) {
            return false;
        }

        if (! $this->provider->configured()) {
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

        try {
            if ($generation->reconciling) {
                $response = $this->provider->list($generation->reconcile_cursor);
            } elseif ($generation->provider_id) {
                $response = $this->provider->retrieve($generation->provider_id);
            } else {
                // Persist uncertainty without changing the claim's previous outcome knowledge.
                $this->update($generation, ['uncertain' => true]);
                $response = $this->provider->submit($generation);
            }

            if (! $response->successful()) {
                $this->failure($generation, $response);
            } elseif ($generation->reconciling) {
                $this->reconcile($generation, $response->json());
            } else {
                $this->state->apply($response->json(), $generation->id);
                $this->state->pendingEvents($this->generations->find($generation->id));
            }
        } catch (ConnectionException) {
            $this->retry($generation, 'The provider is taking longer to respond. Checking safely for your image.', true);
        } catch (UnexpectedValueException|TypeError $exception) {
            report($exception);
            $this->retry($generation, 'The provider returned an incomplete response. Checking again safely.', true);
        }

        return true;
    }

    private function claim(): ?Generation
    {
        return DB::transaction(function (): ?Generation {
            $capacityFull = $this->generations->capacityIsFull(self::CONCURRENCY_LIMIT);
            $generation = $this->generations->nextDueForUpdate($capacityFull);

            if ($generation !== null) {
                // Persist the attempt before I/O; an interrupted process still consumes an attempt.
                $this->generations->save($generation, [
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
                'provider_cost' => Pricing::ZERO,
                'actual_cost' => Pricing::ZERO,
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
                    'provider_cost' => Pricing::ZERO,
                    'actual_cost' => Pricing::ZERO,
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
                $this->state->apply($data, $generation->id);
                $this->state->pendingEvents($this->generations->find($generation->id));

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
            $current = $this->generations->findForUpdate($generation->id);

            if ($current->terminal()) {
                return;
            }

            // An early webhook may have established a provider ID during this request.
            if (! $generation->provider_id && $current->provider_id) {
                return;
            }

            $this->generations->save($current, $values);
        }, 5);
    }
}
