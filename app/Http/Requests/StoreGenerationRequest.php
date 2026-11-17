<?php

namespace App\Http\Requests;

class StoreGenerationRequest extends EstimateGenerationRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'request_id' => ['required', 'uuid'],
        ];
    }
}
