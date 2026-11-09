<?php

namespace App\Domains\Taxation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An optional date range narrowing a list, such as a financial year. Both
 * ends or neither: half a range filters nothing.
 */
class PeriodFilterRequest extends FormRequest
{
    /**
     * Gatekeeping happens in the controller, so let every caller through here.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ];
    }

    /**
     * The range, when both ends were given.
     *
     * @return array{0: string, 1: string}|null
     */
    public function period(): ?array
    {
        $from = $this->validated('from_date');
        $to = $this->validated('to_date');

        return $from && $to ? [$from, $to] : null;
    }
}
