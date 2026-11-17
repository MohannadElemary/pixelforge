<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class Pricing
{
    public const BUCKETS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function current(): array
    {
        return Cache::remember('pixelforge.pricing', 60, function () {
            $pricing = Http::acceptJson()->connectTimeout(3)->timeout(8)
                ->get(config('services.pixelforge.url').'/pricing')->throw()->json();
            $this->cost(array_fill_keys(self::BUCKETS, 1), $pricing['rates'] ?? []);
            if (($pricing['currency'] ?? null) !== 'usd' || ($pricing['unit'] ?? null) !== 'per_1m_tokens') {
                throw new UnexpectedValueException('Unsupported pricing currency or unit.');
            }
            return $pricing;
        });
    }

    // Rates allow up to 9 decimals; /1M then ×1.25 needs at most 17. No floats or cent rounding.
    public function cost(array $usage, array $rates): array
    {
        $provider = '0';
        foreach (self::BUCKETS as $bucket) {
            $tokens = $usage[$bucket] ?? null;
            $rate = $rates[$bucket] ?? null;
            if (!is_int($tokens) || $tokens < 0 || !is_string($rate)
                || !preg_match('/^\d{1,9}(?:\.\d{1,9})?$/D', $rate)) {
                throw new UnexpectedValueException('Invalid token usage or rate.');
            }
            $provider = bcadd($provider, bcdiv(bcmul((string) $tokens, $rate, 18), '1000000', 18), 18);
        }
        return ['provider' => $provider, 'user' => bcmul($provider, '1.25', 18)];
    }

    public function estimate(array $input, ?array $pricing = null): array
    {
        $pricing ??= $this->current();
        $tokens = $pricing['tokens'];
        $characters = mb_strlen($input['prompt']);
        $perToken = (int) $tokens['characters_per_text_token'];
        if ($perToken < 1) throw new UnexpectedValueException('Invalid text token divisor.');
        $text = max(1, intdiv($characters + $perToken - 1, $perToken));
        $range = $tokens['reasoning'][$input['quality']];
        $usage = [
            'input_text_tokens' => $text,
            'input_image_tokens' => count($input['reference_images']) * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$input['quality']][$input['size']],
            'reasoning_tokens' => intdiv($range['min'] + $range['max'], 2),
        ];
        $typical = $this->cost($usage, $pricing['rates'])['user'];
        $variance = (string) $tokens['text_token_variance'];
        $usage['input_text_tokens'] = max(1, (int) bcmul((string) $text, bcsub('1', $variance, 9), 0));
        $usage['reasoning_tokens'] = $range['min'];
        $minimum = $this->cost($usage, $pricing['rates'])['user'];
        $usage['input_text_tokens'] = (int) bcadd(bcmul((string) $text, bcadd('1', $variance, 9), 9), '0.999999999', 0);
        $usage['reasoning_tokens'] = $range['max'];
        return ['typical' => $typical, 'min' => $minimum, 'max' => $this->cost($usage, $pricing['rates'])['user'], 'rates' => $pricing['rates']];
    }
}
