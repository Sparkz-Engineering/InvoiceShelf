<?php

namespace App\Domains\Taxation\Policies;

use App\Domains\Accounts\Models\User;
use App\Domains\Taxation\Models\TaxDeduction;
use Silber\Bouncer\BouncerFacade;

/**
 * Who may work with hand-recorded tax deductions: the Bouncer ability, and
 * for an existing deduction, membership of its company.
 */
class TaxDeductionPolicy
{
    public function viewAny(User $user): bool
    {
        return BouncerFacade::can('view-tax-deduction', TaxDeduction::class);
    }

    public function view(User $user, TaxDeduction $deduction): bool
    {
        return $this->allows($user, $deduction, 'view');
    }

    public function create(User $user): bool
    {
        return BouncerFacade::can('create-tax-deduction', TaxDeduction::class);
    }

    public function update(User $user, TaxDeduction $deduction): bool
    {
        return $this->allows($user, $deduction, 'edit');
    }

    public function delete(User $user, TaxDeduction $deduction): bool
    {
        return $this->allows($user, $deduction, 'delete');
    }

    private function allows(User $user, TaxDeduction $deduction, string $verb): bool
    {
        return (int) request()->header('company') === (int) $deduction->company_id
            && $user->hasCompany($deduction->company_id)
            && BouncerFacade::can($verb.'-tax-deduction', $deduction);
    }
}
