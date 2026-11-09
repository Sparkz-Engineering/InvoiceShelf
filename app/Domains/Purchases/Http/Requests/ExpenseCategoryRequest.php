<?php

namespace App\Domains\Purchases\Http\Requests;

use App\Domains\Purchases\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseCategoryRequest extends FormRequest
{
    /**
     * Gatekeeping happens in the controller, so let every caller through here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rules for creating or editing an expense category.
     */
    public function rules(): array
    {
        return [
            'name' => ['required'],
            'description' => ['nullable'],
            'tax_deductible' => ['sometimes', 'boolean'],
            'default_tax_treatment' => ['sometimes', 'nullable', Rule::in(ExpenseCategory::TAX_TREATMENTS)],
        ];
    }

    public function getExpenseCategoryPayload()
    {
        return array_merge($this->validated(), [
            'company_id' => $this->header('company'),
        ]);
    }
}
