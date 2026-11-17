<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RetryGenerationRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('retry', $this->route('generation'));
    }

    public function rules(): array
    {
        return [];
    }
}
