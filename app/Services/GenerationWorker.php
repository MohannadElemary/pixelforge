<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GenerationWorker
{
    public function __construct(private GenerationState $state) {}

    public function tick(): bool
    {
        $generation = DB::transaction(function () {
            $pending = Generation::whereIn('status', ['queued', 'processing']);
            $capacityFull = (clone $pending)->whereNotNull('provider_id')->where('needs_attention', false)->count() >= 3;
            $generation = $pending
                ->when($capacityFull, fn ($query) => $query->where(fn ($query) => $query
                    ->whereNotNull('provider_id')->orWhere('uncertain', true)->orWhere('reconciling', true)))
                ->where('needs_attention', false)->where('next_attempt_at', '<=', now())
                ->orderBy('next_attempt_at')->lockForUpdate()->first();
            if ($generation) {
                // Persist the attempt before I/O; a process crash still consumes an attempt.
                $generation->increment('attempts');
                $generation->update(['next_attempt_at' => now()->addSeconds(60)]);
            }
            return $generation;
        }, 5);
        if (!$generation) return false;
        if (!config('services.pixelforge.key')) {
            $this->update($generation, ['needs_attention' => true, 'message' => 'Generation is paused while the service connection is configured.']);
            return true;
        }
        if ($generation->attempts > 5) {
            $this->retry($generation, 'The previous check was interrupted. Confirming the result safely.', $generation->uncertain);
            return true;
        }
        try {
            $client = Http::baseUrl(config('services.pixelforge.url'))->acceptJson()
                ->withToken(config('services.pixelforge.key'))->connectTimeout(5)->timeout(20);
            if ($generation->reconciling) {
                $response = $client->get('/generations', array_filter(['limit' => 100, 'cursor' => $generation->reconcile_cursor]));
            } elseif ($generation->provider_id) {
                $response = $client->get('/generations/'.$generation->provider_id);
            } else {
                // A crash or lost response after this write must never be treated as a free rejection.
                $this->update($generation, ['uncertain' => true]);
                $response = $client->withHeader('Idempotency-Key', $generation->id)
                    ->post('/generations', $generation->providerPayload());
            }
            if (!$response->successful()) {
                $this->failure($generation, $response);
            } elseif ($generation->reconciling) {
                $this->reconcile($generation, $response->json());
            } else {
                $this->state->apply($response->json(), $generation->id);
                $this->state->pendingEvents($generation->fresh());
            }
        } catch (ConnectionException $e) {
            $this->retry($generation, 'The provider is taking longer to respond. Checking safely for your image.', true);
        } catch (\UnexpectedValueException|\TypeError $e) {
            report($e);
            $this->retry($generation, 'The provider returned an incomplete response. Checking again safely.', true);
        }
        return true;
    }

    private function failure(Generation $generation, Response $response): void
    {
        $code = $response->json('error.code');
        $knownRejection = in_array($code, [
            'overloaded', 'rate_limit_exceeded', 'concurrency_limit_exceeded', 'daily_limit_exceeded',
            'invalid_api_key', 'validation_failed', 'content_policy_violation',
        ], true);
        $uncertain = $generation->uncertain || !$knownRejection;
        $retryable = $code !== 'daily_limit_exceeded' && (
            in_array($code, ['overloaded', 'rate_limit_exceeded', 'concurrency_limit_exceeded'], true)
            || $response->serverError() || $response->status() === 429
        );
        if ($retryable) {
            $this->retry($generation, 'The provider is busy. Your request will retry automatically.', $uncertain, (int) $response->header('Retry-After'));
        } elseif (!$generation->provider_id && !$generation->reconciling && !$uncertain) {
            $this->update($generation, [
                'status' => 'failed', 'provider_cost' => '0.000000000000000000', 'actual_cost' => '0.000000000000000000',
                'uncertain' => false, 'completed_at' => now(), 'next_attempt_at' => null,
                'message' => match ($code) {
                    'content_policy_violation' => 'The provider could not accept this prompt. Try a different description. You were not charged.',
                    'daily_limit_exceeded' => 'The daily generation limit has been reached. Please try tomorrow. You were not charged.',
                    default => 'The provider could not accept this request. You were not charged.',
                },
            ]);
        } else {
            $this->update($generation, ['needs_attention' => true, 'uncertain' => $uncertain,
                'message' => 'We could not confirm the final result. Check again to resume safely; no duplicate image will be ordered.']);
        }
    }

    private function retry(Generation $generation, string $message, bool $uncertain, int $retryAfter = 0): void
    {
        $delay = max($retryAfter, min(120, 2 ** min($generation->attempts, 7)));
        $updates = ['uncertain' => $uncertain, 'message' => $message, 'next_attempt_at' => now()->addSeconds($delay)];
        if ($generation->attempts >= 5) {
            if (!$generation->provider_id && !$generation->reconciling && $uncertain) {
                $updates += ['reconciling' => true, 'attempts' => 0, 'reconcile_cursor' => null, 'reconcile_pages' => 0];
                $updates['message'] = 'Checking the provider’s history to confirm whether your image was accepted.';
            } elseif (!$generation->provider_id && !$uncertain) {
                $updates += ['status' => 'failed', 'provider_cost' => '0.000000000000000000',
                    'actual_cost' => '0.000000000000000000', 'completed_at' => now()];
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
        if (!isset($response['data'], $response['has_more']) || !is_array($response['data'])) {
            throw new \UnexpectedValueException('Invalid generation list.');
        }
        foreach ($response['data'] as $data) {
            if (($data['metadata']['generation_id'] ?? null) === $generation->id) {
                $this->state->apply($data, $generation->id);
                $this->state->pendingEvents($generation->fresh());
                return;
            }
        }
        if ($response['has_more'] && !empty($response['next_cursor']) && $generation->reconcile_pages < 19) {
            $this->update($generation, ['reconcile_cursor' => $response['next_cursor'],
                'reconcile_pages' => $generation->reconcile_pages + 1, 'attempts' => 0, 'next_attempt_at' => now()->addSeconds(5)]);
        } else {
            $this->update($generation, ['needs_attention' => true,
                'message' => 'Acceptance is still unconfirmed. Check again to safely resend the same request without creating a duplicate.']);
        }
    }

    private function update(Generation $generation, array $values): void
    {
        DB::transaction(function () use ($generation, $values) {
            $current = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();
            if ($current->terminal()) return;
            // An early webhook may already have established a provider ID during this request.
            if (!$generation->provider_id && $current->provider_id) return;
            $current->update($values);
        }, 5);
    }
}
