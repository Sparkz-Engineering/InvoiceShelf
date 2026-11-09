<?php

use App\Domains\Contacts\Models\Customer;
use App\Domains\Purchases\Contracts\ExpenseReceiptManager;
use App\Domains\Purchases\Data\PendingExpenseReceipt;
use App\Domains\Purchases\Models\Expense;
use App\Domains\Purchases\Models\ExpenseCategory;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Models\TaxType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * How an expense records the way its tax was entered, the business share of
 * the spend and whether it is deductible, and what the API reports from them.
 */
beforeEach(function () {
    purchaseFixtures($this);

    $this->gst = TaxType::factory()->create([
        'company_id' => $this->companyId,
        'name' => 'GST',
        'percent' => 10,
        'calculation_type' => 'percentage',
        'fixed_amount' => null,
        'compound_tax' => false,
        'transaction_type' => TaxType::TRANSACTION_TYPE_PURCHASES,
    ]);
});

function gstExpensePayload($test, array $overrides = []): array
{
    return [
        'expense_date' => '2026-08-14',
        'expense_category_id' => $test->category->id,
        'amount' => 11000,
        'currency_id' => $test->currencyId,
        'exchange_rate' => 1,
        'taxes' => [['tax_type_id' => $test->gst->id, 'amount' => 1000]],
        ...$overrides,
    ];
}

test('a new expense is tax inclusive, wholly business and deductible unless told otherwise', function () {
    $this->postJson('/api/v1/expenses', gstExpensePayload($this))
        ->assertCreated()
        ->assertJsonPath('data.tax_included', true)
        ->assertJsonPath('data.business_use_percent', 100)
        ->assertJsonPath('data.tax_deductible', true)
        ->assertJsonPath('data.deduction_note', null)
        ->assertJsonPath('data.tax_total', 1000)
        ->assertJsonPath('data.net_amount', 10000)
        ->assertJsonPath('data.claimable_tax', 1000)
        ->assertJsonPath('data.deductible_amount', 10000);
});

test('the multipart form sends its switches as words', function () {
    $payload = gstExpensePayload($this, [
        'tax_included' => 'false',
        'tax_deductible' => 'false',
        'business_use_percent' => '60',
        'deduction_note' => 'Phone, 60% work use per diary',
        'taxes' => json_encode([['tax_type_id' => $this->gst->id, 'amount' => 1000]]),
    ]);

    $id = $this->post('/api/v1/expenses', $payload, ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('data.id');

    $expense = Expense::findOrFail($id);

    expect($expense->tax_included)->toBeFalse()
        ->and($expense->tax_deductible)->toBeFalse()
        ->and($expense->business_use_percent)->toBe(60.0)
        ->and($expense->deduction_note)->toBe('Phone, 60% work use per diary')
        ->and((int) $expense->amount)->toBe(11000);
});

test('business use reduces the claimable tax and the deduction, rounding down', function () {
    $this->postJson('/api/v1/expenses', gstExpensePayload($this, [
        'amount' => 11001,
        'taxes' => [['tax_type_id' => $this->gst->id, 'amount' => 1001]],
        'business_use_percent' => 33.33,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.claimable_tax', 333)
        ->assertJsonPath('data.deductible_amount', 3333);
});

test('a non-deductible expense still claims its tax but deducts nothing', function () {
    $this->postJson('/api/v1/expenses', gstExpensePayload($this, ['tax_deductible' => false]))
        ->assertCreated()
        ->assertJsonPath('data.claimable_tax', 1000)
        ->assertJsonPath('data.deductible_amount', 0);
});

test('business use must be a percentage', function (mixed $percent) {
    $this->postJson('/api/v1/expenses', gstExpensePayload($this, ['business_use_percent' => $percent]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('business_use_percent');
})->with([-1, 100.5, 'half']);

test('a new expense takes the deductible answer of its category', function () {
    $fines = ExpenseCategory::create(['company_id' => $this->companyId, 'name' => 'Fines', 'tax_deductible' => false]);

    $this->postJson('/api/v1/expenses', gstExpensePayload($this, ['expense_category_id' => $fines->id]))
        ->assertCreated()
        ->assertJsonPath('data.tax_deductible', false);

    $this->postJson('/api/v1/expenses', gstExpensePayload($this, ['expense_category_id' => $fines->id, 'tax_deductible' => true]))
        ->assertCreated()
        ->assertJsonPath('data.tax_deductible', true);
});

test('an update that leaves the fields out keeps what the expense says', function () {
    $id = $this->postJson('/api/v1/expenses', gstExpensePayload($this, [
        'tax_included' => false,
        'business_use_percent' => 50,
        'tax_deductible' => false,
        'deduction_note' => 'Half private',
    ]))->assertCreated()->json('data.id');

    $this->putJson("/api/v1/expenses/{$id}", gstExpensePayload($this, ['amount' => 22000, 'taxes' => [['tax_type_id' => $this->gst->id, 'amount' => 2000]]]))
        ->assertOk()
        ->assertJsonPath('data.tax_included', false)
        ->assertJsonPath('data.business_use_percent', 50)
        ->assertJsonPath('data.tax_deductible', false)
        ->assertJsonPath('data.deduction_note', 'Half private');

    $this->putJson("/api/v1/expenses/{$id}", gstExpensePayload($this, ['deduction_note' => '']))
        ->assertOk()
        ->assertJsonPath('data.deduction_note', null);
});

test('the list reports each expense\'s tax without loading the rows', function () {
    $id = $this->postJson('/api/v1/expenses', gstExpensePayload($this))->assertCreated()->json('data.id');
    $this->postJson('/api/v1/expenses', gstExpensePayload($this, ['amount' => 500, 'taxes' => []]))->assertCreated();

    $rows = collect($this->getJson('/api/v1/expenses?limit=all')->assertOk()->json('data'))->keyBy('id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$id]['tax_total'])->toBe(1000)
        ->and($rows[$id])->not->toHaveKey('taxes')
        ->and($rows->first(fn ($row) => $row['id'] !== $id)['tax_total'])->toBe(0);
});

test('the detail response names the supplier', function () {
    $id = $this->postJson('/api/v1/expenses', gstExpensePayload($this, ['supplier_id' => $this->supplier->id]))
        ->assertCreated()
        ->json('data.id');

    $this->getJson("/api/v1/expenses/{$id}")
        ->assertOk()
        ->assertJsonPath('data.supplier.id', $this->supplier->id)
        ->assertJsonPath('data.supplier.name', 'Acme supplier');
});

test('a category records its starting tax treatment and deductible answer', function () {
    $id = $this->postJson('/api/v1/categories', [
        'name' => 'Bank fees',
        'tax_deductible' => true,
        'default_tax_treatment' => ExpenseCategory::TAX_TREATMENT_NONE,
    ])->assertSuccessful()->json('data.id');

    $this->getJson("/api/v1/categories/{$id}")
        ->assertOk()
        ->assertJsonPath('data.default_tax_treatment', 'none')
        ->assertJsonPath('data.tax_deductible', true);

    $this->postJson('/api/v1/categories', ['name' => 'Odd', 'default_tax_treatment' => 'sometimes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('default_tax_treatment');
});

test('a PDF receipt is served for viewing in place, not as a download', function () {
    Storage::fake('local');

    $expense = Expense::factory()->create(['company_id' => $this->companyId]);
    $upload = UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    app(ExpenseReceiptManager::class)->attach($expense, new PendingExpenseReceipt($upload->getPathname(), 'receipt.pdf'));

    $response = $this->get("/reports/expenses/{$expense->id}/receipt")->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/pdf')
        ->and((string) $response->headers->get('Content-Disposition'))->not->toContain('attachment');
});

test('saving an expense from the form keeps its receipt unless it was removed', function () {
    Storage::fake('local');

    $receipt = UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    $multipart = fn (array $extra = []) => [
        ...gstExpensePayload($this),
        'taxes' => json_encode([['tax_type_id' => $this->gst->id, 'amount' => 1000]]),
        ...$extra,
    ];

    $id = $this->post('/api/v1/expenses', $multipart(['attachment_receipt' => $receipt]), ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('data.id');

    // The form always sends the flag, as the word "false" when nothing was removed
    $this->post("/api/v1/expenses/{$id}", $multipart(['_method' => 'PUT', 'notes' => 'Edited', 'is_attachment_receipt_removed' => 'false']), ['Accept' => 'application/json'])
        ->assertOk();

    expect(Expense::findOrFail($id)->getMedia('receipts'))->toHaveCount(1);

    $this->post("/api/v1/expenses/{$id}", $multipart(['_method' => 'PUT', 'is_attachment_receipt_removed' => 'true']), ['Accept' => 'application/json'])
        ->assertOk();

    expect(Expense::findOrFail($id)->getMedia('receipts'))->toHaveCount(0);
});

test('the customer portal does not show how an expense is claimed', function () {
    $customer = Customer::factory()->create(['company_id' => $this->companyId]);
    $expense = Expense::factory()->create([
        'company_id' => $this->companyId,
        'customer_id' => $customer->id,
        'business_use_percent' => 40,
        'deduction_note' => 'Private note',
    ]);
    Tax::factory()->create(['expense_id' => $expense->id, 'company_id' => $this->companyId, 'tax_type_id' => $this->gst->id]);

    Sanctum::actingAs($customer, ['*'], 'customer');

    $data = $this->getJson("/api/v1/{$customer->company->slug}/customer/expenses/{$expense->id}")
        ->assertOk()
        ->json('data');

    expect($data)->not->toHaveKeys(['business_use_percent', 'tax_deductible', 'deduction_note', 'claimable_tax', 'deductible_amount']);
});
