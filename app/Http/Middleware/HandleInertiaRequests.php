<?php

namespace App\Http\Middleware;

use App\Services\WalletService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(private readonly WalletService $walletService) {}

    public function share(Request $request): array
    {
        Inertia::encryptHistory($request->user() !== null);

        return [
            ...parent::share($request),
            'user' => $request->user()?->only('id', 'name', 'email', 'is_admin'),
            'wallet' => fn (): ?array => $request->user() ? $this->walletService->summary($request->user()) : null,
            'flash' => ['success' => fn (): ?string => $request->session()->get('success')],
        ];
    }
}
