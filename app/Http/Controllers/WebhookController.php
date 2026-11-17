<?php

namespace App\Http\Controllers;

use App\Http\Requests\PixelforgeWebhookRequest;
use App\Services\GenerationStateService;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    public function __invoke(PixelforgeWebhookRequest $request, GenerationStateService $generationStateService): Response
    {
        $generationStateService->webhook($request->event());

        return response()->noContent();
    }
}
