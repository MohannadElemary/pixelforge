<?php
namespace App\Http\Controllers;
use App\Models\Generation;
use App\Services\Pricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
class StudioController extends Controller
{
    private function input(Request $request): array
    {
        return $request->validate([
            'prompt' => 'required|string|max:4000', 'size' => ['required', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'reference_images' => 'present|array|max:4', 'reference_images.*' => 'required|url:http,https|max:2048',
        ]);
    }
    public function index(Request $request)
    {
        return Inertia::render('Studio', [
            'generations' => Generation::where('user_id', $request->user()->id)->latest()->paginate(12)->withQueryString(),
            'configured' => (bool) config('services.pixelforge.key'),
        ]);
    }
    public function estimate(Request $request, Pricing $pricing)
    {
        $input = $this->input($request);
        try { return response()->json($pricing->estimate($input)); }
        catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Live pricing is temporarily unavailable. Please try again shortly.'], 503);
        }
    }
    public function store(Request $request, Pricing $pricing)
    {
        $input = $this->input($request);
        $request->validate(['request_id' => 'required|uuid']);
        if (!config('services.pixelforge.key')) throw ValidationException::withMessages(['prompt' => 'Generation is unavailable until the service is connected.']);
        $existing = Generation::where('user_id', $request->user()->id)->where('request_id', $request->request_id)->first();
        if ($existing) return $this->replay($existing, $input);
        try { $estimate = $pricing->estimate($input); }
        catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['prompt' => 'We could not confirm current pricing. Please try again shortly.']);
        }
        $generation = Generation::firstOrCreate([
            'user_id' => $request->user()->id, 'request_id' => $request->request_id,
        ], $input + ['estimate' => $estimate, 'next_attempt_at' => now()]);
        return $this->replay($generation, $input);
    }
    private function replay(Generation $generation, array $input)
    {
        foreach ($input as $key => $value) {
            if ($generation->$key !== $value) throw ValidationException::withMessages(['prompt' => 'This request has already been submitted with different options. Refresh before starting another image.']);
        }
        return redirect('/studio');
    }
    public function retry(Request $request, Generation $generation)
    {
        abort_unless($generation->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($generation) {
            $current = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();
            if ($current->terminal() || !$current->needs_attention) return;
            $current->update(['needs_attention' => false, 'reconciling' => false, 'reconcile_pages' => 0,
                'reconcile_cursor' => null, 'attempts' => 0, 'next_attempt_at' => now(), 'message' => 'Checking again safely.']);
        }, 5);
        return back();
    }
}
