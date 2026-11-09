<?php

namespace App\Domains\Taxation\Models;

use App\Domains\Accounts\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Business Activity Statement lodged for one period.
 *
 * `figures` is the worksheet as it stood when it was marked lodged: the
 * labels in exact and whole-dollar form, the PAYG instalment and the totals.
 * Nothing about the period is locked by it; it is what later figures are
 * compared against, so a change made after lodging can be noticed.
 */
class BasLodgement extends Model
{
    protected $table = 'bas_lodgements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'figures' => 'array',
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'lodged_on' => 'date:Y-m-d',
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
     * Lodgements whose period overlaps the range.
     */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query
            ->where($this->qualifyColumn('period_start'), '<=', $to)
            ->where($this->qualifyColumn('period_end'), '>=', $from);
    }

    /**
     * Route binding only ever finds a lodgement of the company the request
     * acts for.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)
            ->where($this->qualifyColumn('company_id'), (int) request()->header('company'));
    }
}
