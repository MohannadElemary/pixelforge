<?php

namespace App\Services;

use App\Clients\PixelforgeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use UnexpectedValueException;

class PricingService
{
    public const ZERO = '0.000000000000000000';

    public const BUCKETS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(private readonly PixelforgeClient $pixelforgeClient) {}

    public function current(): array
    {
        return Cache::remember('pixelforge.pricing', 60, fn (): array => $this->fresh());
    }

    public function fresh(): array
    {
        $pricing = $this->pixelforgeClient->pricing();
        if (! is_array($pricing['rates'] ?? null)) {
            throw new UnexpectedValueException('Invalid pricing rates.');
        }
        $this->cost(array_fill_keys(self::BUCKETS, 1), $pricing['rates']);
        if (($pricing['currency'] ?? null) !== 'usd' || ($pricing['unit'] ?? null) !== 'per_1m_tokens') {
            throw new UnexpectedValueException('Unsupported pricing currency or unit.');
        }

        return $pricing;
    }

    // Rates allow up to 9 decimals; /1M then ×1.25 needs at most 17. No floats or cent rounding.
    public function cost(array $usage, array $rates): array
    {
        $provider = '0';
        foreach (self::BUCKETS as $bucket) {
            $tokens = $usage[$bucket] ?? null;
            $rate = $rates[$bucket] ?? null;
            if (! is_int($tokens) || $tokens < 0 || ! is_string($rate)
                || ! preg_match('/^\d{1,9}(?:\.\d{1,9})?$/D', $rate)) {
                throw new UnexpectedValueException('Invalid token usage or rate.');
            }
            $provider = bcadd($provider, bcdiv(bcmul((string) $tokens, $rate, 18), '1000000', 18), 18);
        }

        return ['provider' => $provider, 'user' => bcmul($provider, '1.25', 18)];
    }

    public function estimate(array $input, ?array $pricing = null): array
    {
        $pricing ??= $this->current();
        $this->validateEstimateTokens($pricing, $input['quality'], $input['size']);
        $tokens = $pricing['tokens'];
        $characters = mb_strlen($input['prompt']);
        $perToken = (int) $tokens['characters_per_text_token'];
        $text = max(1, intdiv($characters + $perToken - 1, $perToken));
        $range = $tokens['reasoning'][$input['quality']];
        $usage = [
            'input_text_tokens' => $text,
            'input_image_tokens' => count($input['reference_images']) * (int) $tokens['per_reference_image'],
            'output_tokens' => (int) $tokens['output'][$input['quality']][$input['size']],
            'reasoning_tokens' => intdiv((int) $range['min'] + (int) $range['max'], 2),
        ];
        $typical = $this->cost($usage, $pricing['rates'])['user'];
        $variance = (string) $tokens['text_token_variance'];
        $usage['input_text_tokens'] = max(1, (int) bcmul((string) $text, bcsub('1', $variance, 9), 0));
        $usage['reasoning_tokens'] = (int) $range['min'];
        $minimum = $this->cost($usage, $pricing['rates'])['user'];
        $usage['input_text_tokens'] = (int) bcadd(bcmul((string) $text, bcadd('1', $variance, 9), 9), '0.999999999', 0);
        $usage['reasoning_tokens'] = (int) $range['max'];

        return ['typical' => $typical, 'min' => $minimum, 'max' => $this->cost($usage, $pricing['rates'])['user'], 'rates' => $pricing['rates'], 'max_tokens' => $usage];
    }

    private function validateEstimateTokens(array $pricing, string $quality, string $size): void
    {
        $rules = [
            'tokens' => ['required', 'array'],
            'tokens.characters_per_text_token' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'tokens.text_token_variance' => ['required', 'regex:/^(?:0(?:\.\d{1,9})?|1(?:\.0{1,9})?)$/D'],
            'tokens.per_reference_image' => ['required', 'integer', 'min:0', 'max:1000000000'],
            "tokens.output.{$quality}.{$size}" => ['required', 'integer', 'min:0', 'max:1000000000'],
            "tokens.reasoning.{$quality}.min" => ['required', 'integer', 'min:0', 'max:1000000000'],
            "tokens.reasoning.{$quality}.max" => ['required', 'integer', "gte:tokens.reasoning.{$quality}.min", 'max:1000000000'],
        ];

        if (Validator::make($pricing, $rules)->fails()) {
            throw new UnexpectedValueException('Invalid pricing token configuration.');
        }
    }
}
