<?php

namespace App\Domains\Taxation\Http\Requests;

use App\Domains\Taxation\Models\TaxDeduction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaxDeductionRequest extends FormRequest
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
            'deduction_date' => ['required', 'date_format:Y-m-d'],
            'category' => ['required', Rule::in(TaxDeduction::CATEGORIES)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
