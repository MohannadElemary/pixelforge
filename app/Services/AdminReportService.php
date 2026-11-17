<?php

namespace App\Services;

use App\Clients\PixelforgeClient;
use App\Repositories\AdminReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;
use UnexpectedValueException;

class AdminReportService
{
    public function __construct(
        private readonly AdminReportRepository $adminReportRepository,
        private readonly PixelforgeClient $pixelforgeClient,
    ) {}

    public function report(array $filters, int $page = 1): array
    {
        $days = [];
        $currentDays = [];
        $end = CarbonImmutable::parse($filters['end_date'], 'UTC');
        for ($date = CarbonImmutable::parse($filters['start_date'], 'UTC'); $date->lte($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $days[$key] = ['date' => $key] + $this->emptyTotals();
            $currentDays[$key] = ['generations' => 0, 'provider_cost' => PricingService::ZERO];
        }
        $fingerprint = $this->pixelforgeClient->keyFingerprint();
        $totals = $this->emptyTotals();
        $unscoped = PricingService::ZERO;
        $otherKeys = PricingService::ZERO;
        $entries = [];
        $entry = null;
        $entryCount = 0;
        $offset = ($page - 1) * 50;

        foreach ($this->adminReportRepository->completedBetween($filters['start_date'], $filters['end_date']) as $generation) {
            $date = substr($generation->completed_at, 0, 10);
            $isCurrent = $fingerprint !== null && $generation->provider_key_fingerprint === $fingerprint;
            if ($isCurrent) {
                $currentDays[$date]['generations']++;
                $currentDays[$date]['provider_cost'] = bcadd($currentDays[$date]['provider_cost'], $generation->provider_cost, 18);
            } elseif ($generation->provider_key_fingerprint === null) {
                $unscoped = bcadd($unscoped, $generation->provider_cost, 18);
            } else {
                $otherKeys = bcadd($otherKeys, $generation->provider_cost, 18);
            }
            if ($filters['scope'] === 'current' && ! $isCurrent) {
                continue;
            }

            $this->addGeneration($totals, $generation);
            $this->addGeneration($days[$date], $generation);
            if ($entry === null || $entry['date'] !== $date || $entry['user_id'] !== (int) $generation->user_id) {
                if ($entry !== null) {
                    $this->collectEntry($entries, $entry, $entryCount, $offset);
                }
                $entry = [
                    'date' => $date, 'user_id' => (int) $generation->user_id,
                    'name' => $generation->name, 'email' => $generation->email,
                ] + $this->emptyTotals();
            }
            $this->addGeneration($entry, $generation);
        }
        if ($entry !== null) {
            $this->collectEntry($entries, $entry, $entryCount, $offset);
        }

        $paginator = new LengthAwarePaginator($entries, $entryCount, 50, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => $filters,
        ]);

        return [
            'filters' => $filters,
            'report' => ['totals' => $totals, 'days' => array_values($days), 'entries' => $paginator->toArray()],
            'reconciliation' => $this->reconcile($filters, $currentDays, $unscoped, $otherKeys, $fingerprint !== null),
        ];
    }

    private function emptyTotals(): array
    {
        return ['generations' => 0, 'credit_spend' => PricingService::ZERO, 'provider_cost' => PricingService::ZERO, 'profit' => PricingService::ZERO];
    }

    private function addGeneration(array &$totals, object $generation): void
    {
        $totals['generations']++;
        $totals['credit_spend'] = bcadd($totals['credit_spend'], $generation->actual_cost, 18);
        $totals['provider_cost'] = bcadd($totals['provider_cost'], $generation->provider_cost, 18);
        $totals['profit'] = bcsub($totals['credit_spend'], $totals['provider_cost'], 18);
    }

    private function collectEntry(array &$entries, array $entry, int &$count, int $offset): void
    {
        if ($count >= $offset && $count < $offset + 50) {
            $entries[] = $entry;
        }
        $count++;
    }

    private function reconcile(array $filters, array $currentDays, string $unscoped, string $otherKeys, bool $configured): array
    {
        $recorded = PricingService::ZERO;
        foreach ($currentDays as $day) {
            $recorded = bcadd($recorded, $day['provider_cost'], 18);
        }
        $result = [
            'status' => 'unconfigured', 'provider_cost' => null, 'recorded_provider_cost' => $recorded,
            'difference' => null, 'unscoped_provider_cost' => $unscoped, 'other_key_provider_cost' => $otherKeys,
            'checked_at' => null, 'message' => 'Configure a provider API key to compare its usage. Current-key reports are empty until a key is configured.',
            'days' => [],
        ];
        if (! $configured) {
            return $result;
        }

        try {
            $usage = $this->pixelforgeClient->usage($filters['start_date'], $filters['end_date']);
            $providerDays = $this->usageDays($usage, $filters, array_keys($currentDays));
        } catch (Throwable) {
            $result['status'] = 'unavailable';
            $result['message'] = 'Provider usage could not be verified. Recorded totals are available; try the usage check again later.';

            return $result;
        }

        $matched = true;
        foreach ($currentDays as $date => $day) {
            $provider = $providerDays[$date];
            $difference = bcsub($provider['cost_usd'], $day['provider_cost'], 18);
            $matched = $matched && bccomp($difference, '0', 18) === 0 && $provider['generations'] === $day['generations'];
            $result['days'][] = [
                'date' => $date, 'provider_cost' => bcadd($provider['cost_usd'], '0', 18),
                'recorded_provider_cost' => $day['provider_cost'], 'difference' => $difference,
            ];
        }
        $result['status'] = $matched ? 'matched' : 'mismatch';
        $result['provider_cost'] = bcadd($usage['total']['cost_usd'], '0', 18);
        $result['difference'] = bcsub($result['provider_cost'], $recorded, 18);
        $result['checked_at'] = CarbonImmutable::now('UTC')->toISOString();
        $result['message'] = $matched
            ? 'Daily costs and successful generation counts match provider usage for the current API key.'
            : 'Daily costs or successful generation counts differ for the current API key. In-flight completions or other applications using this key may explain the difference; refresh after processing finishes.';

        return $result;
    }

    private function usageDays(array $usage, array $filters, array $dates): array
    {
        if (($usage['object'] ?? null) !== 'usage' || ($usage['start_date'] ?? null) !== $filters['start_date']
            || ($usage['end_date'] ?? null) !== $filters['end_date'] || ! is_array($usage['data'] ?? null)
            || count($usage['data']) !== count($dates) || ! $this->validUsageRow($usage['total'] ?? null)) {
            throw new UnexpectedValueException('Invalid provider usage.');
        }
        $days = [];
        $sum = PricingService::ZERO;
        $count = 0;
        foreach ($usage['data'] as $row) {
            if (! $this->validUsageRow($row) || ! in_array($row['date'] ?? null, $dates, true) || isset($days[$row['date']])) {
                throw new UnexpectedValueException('Invalid provider usage day.');
            }
            $days[$row['date']] = $row;
            $sum = bcadd($sum, $row['cost_usd'], 18);
            $count += $row['generations'];
        }
        if (bccomp($sum, $usage['total']['cost_usd'], 18) !== 0 || $count !== $usage['total']['generations']) {
            throw new UnexpectedValueException('Provider usage totals do not match its days.');
        }

        return $days;
    }

    private function validUsageRow(mixed $row): bool
    {
        return is_array($row) && is_int($row['generations'] ?? null) && $row['generations'] >= 0
            && is_string($row['cost_usd'] ?? null) && preg_match('/^\d{1,24}\.\d{9}$/D', $row['cost_usd']) === 1;
    }
}
