<?php

namespace App\Domains\Taxation\Models;

use App\Domains\Accounts\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deduction recorded by hand rather than through an expense: the kind that
 * is worked out (a home office by the hour, a car by the kilometre, the
 * year's depreciation of equipment) instead of paid on a receipt.
 *
 * `amount` is whole minor units of the company's currency. The date decides
 * which financial year the deduction belongs to.
 */
class TaxDeduction extends Model
{
    protected $table = 'tax_deductions';

    public const CATEGORIES = [
        'home_office',
        'vehicle',
        'depreciation',
        'travel',
        'professional_fees',
        'education',
        'insurance',
        'phone_internet',
        'other',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'deduction_date' => 'date:Y-m-d',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), $companyId);
    }

    /**
     * Deductions dated inside the inclusive range.
     */
    public function scopeDatedBetween(Builder $query, string $from, string $to): Builder
    {
        return $query
            ->whereDate($this->qualifyColumn('deduction_date'), '>=', $from)
            ->whereDate($this->qualifyColumn('deduction_date'), '<=', $to);
    }

    /**
     * Route binding only ever finds a deduction of the company the request
     * acts for.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)
            ->where($this->qualifyColumn('company_id'), (int) request()->header('company'));
    }
}
