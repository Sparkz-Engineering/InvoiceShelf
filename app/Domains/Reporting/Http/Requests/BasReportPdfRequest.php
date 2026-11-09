<?php

namespace App\Domains\Reporting\Http\Requests;

use App\Domains\Accounts\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Silber\Bouncer\BouncerFacade;

/**
 * A BAS worksheet opened by the hash of the company it covers, as a PDF or
 * a CSV. The hash only names the company: the caller must still be allowed
 * to see its reports.
 */
class BasReportPdfRequest extends FormRequest
{
    private ?Company $reportCompany = null;

    /**
     * The company named by the hash in the report URL, looked up once.
     */
    public function company(): Company
    {
        return $this->reportCompany ??= Company::query()
            ->where('unique_hash', $this->route('hash'))
            ->firstOrFail();
    }

    public function authorize(): bool
    {
        BouncerFacade::scope()->to($this->company()->id);

        return Gate::allows('view report', $this->company());
    }

    public function rules(): array
    {
        return [
            ...BasReportRequest::periodRules(),
            'format' => ['nullable', Rule::in(['pdf', 'csv'])],
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
