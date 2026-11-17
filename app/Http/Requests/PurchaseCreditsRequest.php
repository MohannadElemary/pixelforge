<?php

namespace App\Http\Requests;

use App\Services\WalletService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'amount' => ['required', 'string', Rule::in(WalletService::PURCHASE_AMOUNTS)]];
    }
}
