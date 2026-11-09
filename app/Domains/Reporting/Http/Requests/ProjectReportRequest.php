<?php

namespace App\Domains\Reporting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The period of a project report and, optionally, the invoice and expense
 * custom fields that name the project.
 */
class ProjectReportRequest extends FormRequest
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
        return self::rulesFor((int) $this->header('company'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function rulesFor(int $companyId): array
    {
        $field = fn (string $model) => Rule::exists('custom_fields', 'id')
            ->where('company_id', $companyId)
            ->where('model_type', $model);

        return [
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'invoice_field_id' => ['nullable', 'integer', $field('Invoice')],
            'expense_field_id' => ['nullable', 'integer', $field('Expense')],
        ];
    }
}
