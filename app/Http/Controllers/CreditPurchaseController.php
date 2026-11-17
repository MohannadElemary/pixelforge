<?php

namespace App\Http\Controllers;

use App\Exceptions\WalletException;
use App\Http\Requests\PurchaseCreditsRequest;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class CreditPurchaseController extends Controller
{
    public function __construct(private readonly WalletService $walletService) {}

    public function store(PurchaseCreditsRequest $request): RedirectResponse
    {
        try {
            $this->walletService->purchase($request->user()->id, $request->validated('request_id'), $request->validated('amount'));
        } catch (WalletException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        return back()->with('success', 'Simulated credits added. No payment was taken.');
    }
}
