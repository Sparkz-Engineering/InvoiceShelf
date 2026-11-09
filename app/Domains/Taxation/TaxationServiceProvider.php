<?php

namespace App\Domains\Taxation;

use App\Domains\Taxation\Models\BasLodgement;
use App\Domains\Taxation\Models\TaxDeduction;
use App\Domains\Taxation\Models\TaxType;
use App\Domains\Taxation\Policies\BasLodgementPolicy;
use App\Domains\Taxation\Policies\TaxDeductionPolicy;
use App\Domains\Taxation\Policies\TaxTypePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TaxationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(TaxType::class, TaxTypePolicy::class);
        Gate::policy(BasLodgement::class, BasLodgementPolicy::class);
        Gate::policy(TaxDeduction::class, TaxDeductionPolicy::class);
    }
}
