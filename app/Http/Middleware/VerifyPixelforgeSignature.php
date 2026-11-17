<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPixelforgeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.pixelforge.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503);

        $signature = [];
        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $signature[$key] = $value;
        }

        $timestamp = $signature['t'] ?? '';
        abort_unless(
            preg_match('/^\d{1,11}$/D', $timestamp)
            && abs(time() - (int) $timestamp) <= 300
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $signature['v1'] ?? ''),
            400,
        );

        return $next($request);
    }
}
