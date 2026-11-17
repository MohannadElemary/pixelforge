<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    protected function prepareForValidation(): void
    {
        $today = CarbonImmutable::now('UTC');
        $this->mergeIfMissing([
            'start_date' => $today->subDays(29)->toDateString(),
            'end_date' => $today->toDateString(),
            'scope' => 'current',
        ]);
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'scope' => ['required', Rule::in(['current', 'all'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('start_date'), 'UTC');
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('end_date'), 'UTC');
            if ($start->diffInDays($end) > 91) {
                $validator->errors()->add('end_date', 'Choose a period of at most 92 days, including both dates.');
            }
        }];
    }

    public function reportFilters(): array
    {
        return $this->safe()->only(['start_date', 'end_date', 'scope']);
    }
}
