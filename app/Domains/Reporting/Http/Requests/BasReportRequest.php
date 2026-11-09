<?php

namespace App\Domains\Reporting\Http\Requests;

use App\Domains\Reporting\Queries\BasQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The period of a BAS worksheet and the settings it may override for this
 * one statement.
 */
class BasReportRequest extends FormRequest
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
        return self::periodRules();
    }

    public function withValidator(Validator $validator): void
    {
        self::limitPeriod($validator);
    }

    /**
     * The settings this request overrides, in the shape BasQuery takes.
     *
     * @return array{basis: string|null, payg_method: string|null, payg_amount: int|null, payg_rate: float|null}
     */
    public function overrides(): array
    {
        return self::overridesFrom($this->validated());
    }

    /**
     * @return array<string, mixed>
     */
    public static function periodRules(): array
    {
        return [
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'basis' => ['nullable', Rule::in([BasQuery::BASIS_CASH, BasQuery::BASIS_ACCRUAL])],
            'payg_method' => ['nullable', Rule::in([BasQuery::PAYG_NONE, BasQuery::PAYG_AMOUNT, BasQuery::PAYG_RATE])],
            'payg_amount' => ['nullable', 'integer', 'min:0', 'max:99999999999'],
            'payg_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * A statement covers at most a year, which is what a BAS ever covers.
     */
    public static function limitPeriod(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->getData();
            $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from_date']);
            $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to_date']);

            if ($to->greaterThanOrEqualTo($from->addYear())) {
                $validator->errors()->add('to_date', 'A statement can cover at most one year.');
            }
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{basis: string|null, payg_method: string|null, payg_amount: int|null, payg_rate: float|null}
     */
    public static function overridesFrom(array $validated): array
    {
        return [
            'basis' => $validated['basis'] ?? null,
            'payg_method' => $validated['payg_method'] ?? null,
            'payg_amount' => isset($validated['payg_amount']) ? (int) $validated['payg_amount'] : null,
            'payg_rate' => isset($validated['payg_rate']) ? (float) $validated['payg_rate'] : null,
        ];
    }
}
