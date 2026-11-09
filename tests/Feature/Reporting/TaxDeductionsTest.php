<?php

use App\Domains\Accounts\Contracts\CompanyDataPurger;
use App\Domains\Accounts\Contracts\MemberReferencesCleaner;
use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use App\Domains\Purchases\Models\Expense;
use App\Domains\Purchases\Models\ExpenseCategory;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Models\TaxDeduction;
use App\Domains\Taxation\Models\TaxType;
use Laravel\Sanctum\Sanctum;
use Silber\Bouncer\BouncerFacade;

/**
 * A financial year's deductions: deductible expenses at their business
 * share without GST, and deductions recorded by hand.
 */
beforeEach(function () {
    purchaseFixtures($this);
    $this->company = Company::findOrFail($this->companyId);
    CompanySetting::setSettings(['language' => 'en', 'carbon_date_format' => 'Y-m-d', 'time_zone' => 'UTC'], $this->companyId);
    config(['pdf.driver' => 'dompdf']);
    $this->year = ['from_date' => '2026-07-01', 'to_date' => '2027-06-30'];
    $this->gst = TaxType::factory()->create([
        'company_id' => $this->companyId, 'name' => 'GST', 'percent' => 10, 'calculation_type' => 'percentage',
        'fixed_amount' => null, 'compound_tax' => false, 'transaction_type' => TaxType::TRANSACTION_TYPE_PURCHASES,
    ]);
});

function deductionPayload(array $overrides = []): array
{
    return [
        'deduction_date' => '2027-06-30',
        'category' => 'home_office',
        'description' => 'Home office, 312 hours at 70c',
        'amount' => 21840,
        'notes' => 'Diary of hours in the drawer',
        ...$overrides,
    ];
}

function deductionExpense($test, array $attributes, int $tax = 0): Expense
{
    $expense = Expense::factory()->create([
        'company_id' => $test->companyId,
        'expense_category_id' => $test->category->id,
        'currency_id' => $test->currencyId,
        'exchange_rate' => 1,
        'expense_date' => '2026-09-01',
        ...$attributes,
        'base_amount' => $attributes['amount'],
    ]);

    if ($tax) {
        Tax::factory()->create([
            'company_id' => $test->companyId, 'expense_id' => $expense->id, 'tax_type_id' => $test->gst->id,
            'name' => 'GST', 'percent' => 10, 'amount' => $tax, 'base_amount' => $tax,
        ]);
    }

    return $expense;
}

test('deductions are recorded, listed by year, changed and removed', function () {
    $id = $this->postJson('/api/v1/tax-deductions', deductionPayload())
        ->assertCreated()
        ->assertJsonPath('data.amount', 21840)
        ->assertJsonPath('data.category', 'home_office')
        ->json('data.id');
    $this->postJson('/api/v1/tax-deductions', deductionPayload(['deduction_date' => '2027-07-01']))->assertCreated();

    $this->getJson('/api/v1/tax-deductions?'.http_build_query($this->year))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->putJson("/api/v1/tax-deductions/{$id}", deductionPayload(['amount' => 25000, 'category' => 'depreciation']))
        ->assertOk()
        ->assertJsonPath('data.amount', 25000)
        ->assertJsonPath('data.category', 'depreciation');

    $this->deleteJson("/api/v1/tax-deductions/{$id}")->assertOk();
    $this->assertDatabaseMissing('tax_deductions', ['id' => $id]);
});

test('a deduction needs a known category and an amount', function (array $overrides, string $field) {
    $this->postJson('/api/v1/tax-deductions', deductionPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    [['category' => 'holiday'], 'category'],
    [['amount' => 0], 'amount'],
    [['description' => ''], 'description'],
    [['deduction_date' => '30/06/2027'], 'deduction_date'],
]);

test('the year counts the business share of deductible expenses without GST, and the deductions recorded', function () {
    // $110 with $10 GST at 60% business use deducts $60
    deductionExpense($this, ['amount' => 11000, 'business_use_percent' => 60, 'deduction_note' => 'Headset'], tax: 1000);
    // A fine: not deductible, left out but counted
    deductionExpense($this, ['amount' => 5000, 'tax_deductible' => false]);
    // Outside the year
    deductionExpense($this, ['amount' => 9900, 'expense_date' => '2026-06-30'], tax: 900);
    $travel = ExpenseCategory::create(['company_id' => $this->companyId, 'name' => 'Travel']);
    deductionExpense($this, ['amount' => 2000, 'expense_category_id' => $travel->id]);
    TaxDeduction::create([...deductionPayload(), 'company_id' => $this->companyId]);

    $report = $this->getJson('/api/v1/reports/tax-deductions?'.http_build_query($this->year))->assertOk()->json('data');

    expect($report['totals'])->toBe(['expenses' => 8000, 'other' => 21840, 'total' => 29840])
        ->and(array_column($report['expenses'], 'name'))->toBe(['Hosting', 'Travel'])
        ->and($report['expenses'][0]['lines'][0]['deductible'])->toBe(6000)
        ->and($report['expenses'][0]['lines'][0]['deduction_note'])->toBe('Headset')
        ->and($report['other'][0]['category'])->toBe('home_office')
        ->and($report['not_deductible'])->toBe(['count' => 1, 'amount' => 5000]);
});

test('the year is served as a PDF and a CSV', function () {
    deductionExpense($this, ['amount' => 11000], tax: 1000);
    TaxDeduction::create([...deductionPayload(), 'company_id' => $this->companyId]);
    $url = '/reports/tax-deductions/'.$this->company->unique_hash.'?'.http_build_query($this->year);

    $preview = $this->get($url.'&preview=true')->assertOk()->assertSee('Home office, 312 hours at 70c');
    expect($preview->viewData('report')['totals']['total'])->toBe(31840);

    $csv = $this->get($url.'&format=csv')->assertOk();
    expect($csv->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($csv->streamedContent())->toContain('318.40')->toContain('Diary of hours in the drawer');
});

test('read only members see deductions but cannot record them', function () {
    $user = User::factory()->create(['role' => 'customer']);
    $user->companies()->attach($this->companyId);
    BouncerFacade::scope()->to($this->companyId);
    BouncerFacade::allow($user)->to('view-tax-deduction', TaxDeduction::class);
    BouncerFacade::refresh();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/tax-deductions')->assertOk();
    $this->getJson('/api/v1/reports/tax-deductions?'.http_build_query($this->year))->assertOk();
    $this->postJson('/api/v1/tax-deductions', deductionPayload())->assertForbidden();

    BouncerFacade::disallow($user)->to('view-tax-deduction', TaxDeduction::class);
    BouncerFacade::refresh();

    $this->getJson('/api/v1/tax-deductions')->assertForbidden();
    $this->get('/reports/tax-deductions/'.$this->company->unique_hash.'?'.http_build_query($this->year).'&preview=true')->assertForbidden();
});

test('another company\'s deductions cannot be reached', function () {
    $other = Company::factory()->create();
    $foreign = TaxDeduction::create([...deductionPayload(), 'company_id' => $other->id]);

    $this->getJson("/api/v1/tax-deductions/{$foreign->id}")->assertNotFound();
    $this->putJson("/api/v1/tax-deductions/{$foreign->id}", deductionPayload())->assertNotFound();
    $this->getJson('/api/v1/tax-deductions')->assertJsonCount(0, 'data');
    $this->get('/reports/tax-deductions/'.$other->unique_hash.'?'.http_build_query($this->year).'&preview=true')->assertForbidden();
});

test('deductions go with their company and forget a departing member', function () {
    $id = $this->postJson('/api/v1/tax-deductions', deductionPayload())->assertCreated()->json('data.id');

    app(MemberReferencesCleaner::class)->clear($this->user);
    expect(TaxDeduction::findOrFail($id)->creator_id)->toBeNull();

    app(CompanyDataPurger::class)->purge(Company::findOrFail($this->companyId));
    expect(TaxDeduction::query()->count())->toBe(0);
});
