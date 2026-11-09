<?php

namespace App\Domains\Taxation\Http\Requests;

use App\Domains\Reporting\Http\Requests\BasReportRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Marking a period's BAS as lodged: the worksheet's period and settings, as
 * on the report page, plus when it was lodged and the ATO's reference.
 */
class BasLodgementRequest extends FormRequest
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
            ...BasReportRequest::periodRules(),
            'lodged_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        BasReportRequest::limitPeriod($validator);
    }

    /**
     * @return array{basis: string|null, payg_method: string|null, payg_amount: int|null, payg_rate: float|null}
     */
    public function overrides(): array
    {
        return BasReportRequest::overridesFrom($this->validated());
    }
}
