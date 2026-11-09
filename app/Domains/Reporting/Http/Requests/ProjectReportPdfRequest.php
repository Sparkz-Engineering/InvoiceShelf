<?php

namespace App\Domains\Reporting\Http\Requests;

use App\Domains\Accounts\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Silber\Bouncer\BouncerFacade;

/**
 * A project report opened by the hash of the company it covers, as a PDF or
 * a CSV. The hash only names the company: the caller must still be allowed
 * to see its reports.
 */
class ProjectReportPdfRequest extends FormRequest
{
    private ?Company $reportCompany = null;

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
            ...ProjectReportRequest::rulesFor($this->company()->id),
            'format' => ['nullable', Rule::in(['pdf', 'csv'])],
        ];
    }
}
