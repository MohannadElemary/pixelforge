<?php

namespace App\Http\Controllers;

use App\Exceptions\GenerationRequestException;
use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Requests\RetryGenerationRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationHistoryResource;
use App\Models\Generation;
use App\Services\GenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StudioController extends Controller
{
    public function __construct(private readonly GenerationService $generations) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Studio', [
            'generations' => (new GenerationHistoryResource($this->generations->history($request->user()->id)))->resolve($request),
            'configured' => $this->generations->configured(),
        ]);
    }

    public function estimate(EstimateGenerationRequest $request): JsonResponse
    {
        try {
            return response()->json($this->generations->estimate($request->generationInput()));
        } catch (GenerationRequestException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }

    public function store(StoreGenerationRequest $request): RedirectResponse
    {
        try {
            $this->generations->submit($request->user()->id, $request->validated('request_id'), $request->generationInput());
        } catch (GenerationRequestException $exception) {
            throw ValidationException::withMessages(['prompt' => $exception->getMessage()]);
        }

        return redirect('/studio');
    }

    public function retry(RetryGenerationRequest $request, Generation $generation): RedirectResponse
    {
        $this->generations->retry($generation->id);

        return back();
    }
}
