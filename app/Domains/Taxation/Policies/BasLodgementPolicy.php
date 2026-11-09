<?php

namespace App\Domains\Taxation\Policies;

use App\Domains\Accounts\Models\User;
use App\Domains\Taxation\Models\BasLodgement;
use Silber\Bouncer\BouncerFacade;

/**
 * Who may see and record BAS lodgements.
 *
 * Seeing them rides on the financial reports ability, since a lodgement is a
 * snapshot of a report. Recording or removing one needs its own ability, and
 * for an existing lodgement the caller must belong to its company.
 */
class BasLodgementPolicy
{
    public const MANAGE = 'manage-bas-lodgement';

    public function viewAny(User $user): bool
    {
        return BouncerFacade::can('view-financial-reports');
    }

    public function create(User $user): bool
    {
        return BouncerFacade::can(self::MANAGE);
    }

    public function delete(User $user, BasLodgement $lodgement): bool
    {
        return (int) request()->header('company') === (int) $lodgement->company_id
            && $user->hasCompany($lodgement->company_id)
            && BouncerFacade::can(self::MANAGE);
    }
}
