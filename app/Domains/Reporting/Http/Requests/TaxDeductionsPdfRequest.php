<?php

namespace App\Domains\Reporting\Http\Requests;

use App\Domains\Accounts\Models\Company;
use App\Domains\Taxation\Models\TaxDeduction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Silber\Bouncer\BouncerFacade;

/**
 * A financial year's deductions opened by the hash of the company they
 * belong to. The hash only names the company: the caller must still belong
 * to it and be allowed to see its deductions.
 */
class TaxDeductionsPdfRequest extends FormRequest
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

        return (bool) $this->user()?->hasCompany($this->company()->id)
            && Gate::allows('viewAny', TaxDeduction::class);
    }

    public function rules(): array
    {
        return [
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'format' => ['nullable', Rule::in(['pdf', 'csv'])],
        ];
    }
}
