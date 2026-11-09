<?php

use App\Domains\Accounts\Contracts\CompanyDataPurger;
use App\Domains\Accounts\Contracts\MemberReferencesCleaner;
use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use App\Domains\Purchases\Models\Expense;
use App\Domains\Taxation\Models\BasLodgement;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Models\TaxType;
use Laravel\Sanctum\Sanctum;
use Silber\Bouncer\BouncerFacade;

/**
 * Marking a BAS as lodged keeps its figures, and a later change to the
 * records behind a lodged period shows up as a warning on the worksheet.
 */
beforeEach(function () {
    purchaseFixtures($this);
    CompanySetting::setSettings(['language' => 'en', 'carbon_date_format' => 'Y-m-d', 'time_zone' => 'UTC'], $this->companyId);
    $this->gst = TaxType::factory()->create([
        'company_id' => $this->companyId, 'name' => 'GST', 'percent' => 10, 'calculation_type' => 'percentage',
        'fixed_amount' => null, 'compound_tax' => false, 'transaction_type' => TaxType::TRANSACTION_TYPE_PURCHASES,
    ]);
    $this->expense = Expense::factory()->create([
        'company_id' => $this->companyId, 'expense_category_id' => $this->category->id, 'currency_id' => $this->currencyId,
        'exchange_rate' => 1, 'expense_date' => '2026-08-01', 'amount' => 11000, 'base_amount' => 11000,
    ]);
    $this->tax = Tax::factory()->create([
        'company_id' => $this->companyId, 'expense_id' => $this->expense->id, 'tax_type_id' => $this->gst->id,
        'name' => 'GST', 'percent' => 10, 'amount' => 1000, 'base_amount' => 1000,
    ]);
    $this->quarter = ['from_date' => '2026-07-01', 'to_date' => '2026-09-30'];
});

function lodgeQuarter($test, array $extra = [])
{
    return $test->postJson('/api/v1/bas-lodgements', [
        ...$test->quarter,
        'lodged_on' => '2026-10-20',
        'reference' => 'ATO-1234',
        ...$extra,
    ]);
}

test('marking a quarter lodged keeps its figures', function () {
    lodgeQuarter($this)
        ->assertCreated()
        ->assertJsonPath('data.from_date', '2026-07-01')
        ->assertJsonPath('data.reference', 'ATO-1234')
        ->assertJsonPath('data.figures.labels.1B.dollars', 10)
        ->assertJsonPath('data.figures.basis', 'cash');

    $this->getJson('/api/v1/bas-lodgements?from_date=2026-07-01&to_date=2027-06-30')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/reports/bas?'.http_build_query($this->quarter))
        ->assertOk()
        ->assertJsonPath('data.lodgement.reference', 'ATO-1234')
        ->assertJsonPath('data.lodgement.changes', []);
});

test('a period is lodged once until its record is removed', function () {
    $id = lodgeQuarter($this)->assertCreated()->json('data.id');

    lodgeQuarter($this)->assertUnprocessable()->assertJsonValidationErrors('from_date');

    $this->deleteJson("/api/v1/bas-lodgements/{$id}")->assertOk();
    lodgeQuarter($this, ['reference' => 'ATO-AMENDED'])->assertCreated();
});

test('a change after lodging is flagged on the worksheet', function () {
    lodgeQuarter($this)->assertCreated();

    // The receipt is corrected after the statement went in: $20 of GST, not $10
    $this->tax->update(['amount' => 2000, 'base_amount' => 2000]);

    $report = $this->getJson('/api/v1/reports/bas?'.http_build_query($this->quarter))->assertOk()->json('data');

    expect($report['lodgement']['changes'])->toBe([['label' => '1B', 'lodged' => 10, 'current' => 20]])
        ->and($report['checks'][0]['key'])->toBe('lodged_figures_changed');
});

test('recording a lodgement needs its own ability, seeing them only the report one', function () {
    $user = User::factory()->create(['role' => 'customer']);
    $user->companies()->attach($this->companyId);
    BouncerFacade::scope()->to($this->companyId);
    BouncerFacade::allow($user)->to('view-financial-reports', null);
    BouncerFacade::refresh();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/bas-lodgements')->assertOk();
    lodgeQuarter($this)->assertForbidden();

    BouncerFacade::allow($user)->to('manage-bas-lodgement', null);
    BouncerFacade::refresh();

    lodgeQuarter($this)->assertCreated();
});

test('another company\'s lodgement cannot be reached', function () {
    $other = Company::factory()->create();
    $foreign = BasLodgement::create([
        'company_id' => $other->id, 'period_start' => '2026-07-01', 'period_end' => '2026-09-30', 'basis' => 'cash',
        'figures' => [], 'lodged_on' => '2026-10-20',
    ]);

    $this->deleteJson("/api/v1/bas-lodgements/{$foreign->id}")->assertNotFound();
    $this->getJson('/api/v1/bas-lodgements')->assertJsonCount(0, 'data');
});

test('lodgements go with their company and forget a departing member', function () {
    $id = lodgeQuarter($this)->assertCreated()->json('data.id');

    app(MemberReferencesCleaner::class)->clear($this->user);
    expect(BasLodgement::findOrFail($id)->creator_id)->toBeNull();

    app(CompanyDataPurger::class)->purge(Company::findOrFail($this->companyId));
    expect(BasLodgement::query()->count())->toBe(0);
});
