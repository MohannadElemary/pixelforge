<?php

namespace App\Http\Controllers;

use App\Services\GenerationState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WebhookController extends Controller
{
    public function __invoke(Request $request, GenerationState $state)
    {
        $secret = config('services.pixelforge.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503);
        $parts = [];
        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }
        $timestamp = $parts['t'] ?? '';
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $parts['v1'] ?? ''), 400);
        $event = json_decode($request->getContent(), true);
        abort_unless(is_array($event), 422);
        Validator::make($event, ['id' => 'required|string|max:255', 'type' => 'required|in:ping,generation.processing,generation.succeeded,generation.failed'])->validate();
        if ($event['type'] !== 'ping') {
            Validator::make($event, ['data.object' => 'required|array', 'data.object.id' => 'required|string|max:255',
                'data.object.status' => 'required|in:processing,succeeded,failed'])->validate();
            abort_unless($event['type'] === 'generation.'.$event['data']['object']['status'], 422);
        }
        $state->webhook($event);
        return response()->noContent();
    }
}
