<?php

namespace App\Http\Controllers;

use App\Http\Requests\PixelforgeWebhookRequest;
use App\Services\GenerationState;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    public function __invoke(PixelforgeWebhookRequest $request, GenerationState $state): Response
    {
        $state->webhook($request->event());

        return response()->noContent();
    }
}
