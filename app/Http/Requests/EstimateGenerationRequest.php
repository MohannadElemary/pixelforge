<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstimateGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'reference_images' => ['present', 'array', 'list', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    public function generationInput(): array
    {
        return $this->safe()->only(['prompt', 'size', 'quality', 'reference_images']);
    }
}
