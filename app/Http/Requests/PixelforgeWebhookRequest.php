<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class PixelforgeWebhookRequest extends FormRequest
{
    private ?array $event = null;

    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        // Signature middleware has authenticated these exact bytes. Preserve the entire provider object.
        $decoded = $this->event ?? json_decode($this->getContent(), true);
        $this->event = is_array($decoded) ? $decoded : [];

        return $this->event;
    }

    public function rules(): array
    {
        $rules = [
            'id' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['ping', 'generation.processing', 'generation.succeeded', 'generation.failed'])],
        ];

        if (($this->validationData()['type'] ?? null) !== 'ping') {
            $rules += [
                'data.object' => ['required', 'array'],
                'data.object.id' => ['required', 'string', 'starts_with:gen_', 'max:255'],
                'data.object.status' => ['required', Rule::in(['processing', 'succeeded', 'failed'])],
            ];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $event = $this->validationData();

            if (($event['type'] ?? null) !== 'ping'
                && ($event['type'] ?? null) !== 'generation.'.($event['data']['object']['status'] ?? '')) {
                $validator->errors()->add('type', 'The event type does not match its generation status.');
            }
        }];
    }

    public function event(): array
    {
        return $this->validationData();
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Invalid webhook payload.'], 422));
    }
}
