<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Purchases\Application\PurchaseDocumentService;
use App\Domains\Purchases\Application\SupplierSettlementService;
use App\Domains\Purchases\Contracts\ExpenseReceiptManager;
use App\Domains\Purchases\Data\PendingExpenseReceipt;
use App\Domains\Purchases\Models\Expense;
use App\Domains\Receivables\Models\Payment;
use App\Domains\Receivables\Models\PaymentAllocation;
use App\Domains\Reporting\Queries\BasQuery;
use App\Domains\Reporting\Queries\TaxSummaryQuery;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Models\TaxType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Silber\Bouncer\BouncerFacade;

/**
 * The BAS worksheet: GST on sales and purchases for a period, on a cash or an
 * accrual basis, with the PAYG instalment and the checks before lodging.
 * Every figure here is worked out by hand in the test's comments.
 */
beforeEach(function () {
    purchaseFixtures($this);
    $this->company = Company::findOrFail($this->companyId);
    CompanySetting::setSettings(['language' => 'en', 'carbon_date_format' => 'Y-m-d', 'time_zone' => 'UTC'], $this->companyId);
    config(['pdf.driver' => 'dompdf']);
    $this->customer = Customer::factory()->create(['company_id' => $this->companyId]);
    $this->gst = TaxType::factory()->create([
        'company_id' => $this->companyId, 'name' => 'GST', 'percent' => 10, 'calculation_type' => 'percentage',
        'fixed_amount' => null, 'compound_tax' => false, 'transaction_type' => TaxType::TRANSACTION_TYPE_PURCHASES,
    ]);
    $this->bas = app(BasQuery::class);
});

function basInvoice($test, array $attributes): Invoice
{
    return Invoice::factory()->create([
        'company_id' => $test->companyId,
        'customer_id' => $test->customer->id,
        'currency_id' => $test->currencyId,
        'status' => Invoice::STATUS_SENT,
        'type' => Invoice::TYPE_INVOICE,
        'exchange_rate' => 1,
        'invoice_date' => '2026-07-01',
        ...$attributes,
        'base_total' => $attributes['base_total'] ?? $attributes['total'],
        'base_tax' => $attributes['base_tax'] ?? $attributes['tax'],
    ]);
}

/** A customer payment set against invoices: [invoice, amount, base amount]. */
function basPayment($test, string $date, array $slices, ?int $unallocated = 0): Payment
{
    $amount = array_sum(array_column($slices, 1)) + $unallocated;
    $base = array_sum(array_map(fn ($slice) => $slice[2] ?? $slice[1], $slices)) + $unallocated;

    $payment = Payment::factory()->create([
        'company_id' => $test->companyId,
        'customer_id' => $test->customer->id,
        'currency_id' => $test->currencyId,
        'payment_date' => $date,
        'amount' => $amount,
        'base_amount' => $base,
        'exchange_rate' => 1,
    ]);

    foreach ($slices as $slice) {
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $slice[0]->id,
            'amount' => $slice[1],
            'base_amount' => $slice[2] ?? $slice[1],
        ]);
    }

    return $payment;
}

function basExpense($test, array $attributes, int $tax = 0): Expense
{
    $expense = Expense::factory()->create([
        'company_id' => $test->companyId,
        'expense_category_id' => $test->category->id,
        'currency_id' => $test->currencyId,
        'exchange_rate' => 1,
        'expense_date' => '2026-08-01',
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

test('on a cash basis an invoice\'s GST is counted as each payment arrives', function () {
    // $110 with $10 GST, paid half in each quarter
    $invoice = basInvoice($this, ['total' => 11000, 'tax' => 1000]);
    basPayment($this, '2026-08-20', [[$invoice, 5500]]);
    basPayment($this, '2026-10-05', [[$invoice, 5500]]);

    $first = $this->bas->report($this->companyId, '2026-07-01', '2026-09-30');
    $second = $this->bas->report($this->companyId, '2026-10-01', '2026-12-31');

    expect($first['labels']['G1'])->toBe(['cents' => 5500, 'dollars' => 55])
        ->and($first['labels']['1A'])->toBe(['cents' => 500, 'dollars' => 5])
        ->and($second['labels']['G1']['cents'])->toBe(5500)
        ->and($second['labels']['1A']['cents'])->toBe(500);
});

test('the GST in uneven payments adds up to the invoice\'s GST exactly', function () {
    $invoice = basInvoice($this, ['total' => 10001, 'tax' => 909]);
    basPayment($this, '2026-07-10', [[$invoice, 3333]]);
    basPayment($this, '2026-09-10', [[$invoice, 3333]]);
    basPayment($this, '2026-11-10', [[$invoice, 3335]]);

    $q1 = $this->bas->report($this->companyId, '2026-07-01', '2026-09-30')['labels']['1A']['cents'];
    $q2 = $this->bas->report($this->companyId, '2026-10-01', '2026-12-31')['labels']['1A']['cents'];

    expect($q1 + $q2)->toBe(909);
});

test('a credit note on a GST-free line leaves all the GST in what is paid', function () {
    // $110 taxable (GST $10) and $50 GST-free; the $50 line is credited, so
    // the $110 paid carries the whole $10. The invoice's own ratio would
    // have said $6.87.
    $invoice = basInvoice($this, ['total' => 16000, 'tax' => 1000]);
    basInvoice($this, [
        'type' => Invoice::TYPE_CREDIT_NOTE, 'status' => Invoice::STATUS_DRAFT,
        'related_invoice_id' => $invoice->id, 'total' => -5000, 'tax' => 0,
    ]);
    basPayment($this, '2026-08-01', [[$invoice, 11000]]);

    expect($this->bas->report($this->companyId, '2026-07-01', '2026-09-30')['labels']['1A']['cents'])->toBe(1000);
});

test('GST on a foreign currency invoice is restated at the payment\'s rate', function () {
    // USD 11.00 with USD 1.00 GST, received as 16.50 in the company currency
    $invoice = basInvoice($this, ['total' => 1100, 'tax' => 100, 'base_total' => 1650, 'base_tax' => 150]);
    basPayment($this, '2026-08-01', [[$invoice, 1100, 1650]]);

    $report = $this->bas->report($this->companyId, '2026-07-01', '2026-09-30');

    expect($report['labels']['G1']['cents'])->toBe(1650)
        ->and($report['labels']['1A']['cents'])->toBe(150);
});

test('money received but not set against an invoice is flagged, not guessed at', function () {
    $invoice = basInvoice($this, ['total' => 11000, 'tax' => 1000]);
    basPayment($this, '2026-08-01', [[$invoice, 11000]], unallocated: 3000);

    $report = $this->bas->report($this->companyId, '2026-07-01', '2026-09-30');
    $check = collect($report['checks'])->firstWhere('key', 'unallocated_payments');

    expect($report['labels']['G1']['cents'])->toBe(11000)
        ->and($check['amount'])->toBe(3000)
        ->and($check['count'])->toBe(1);
});

test('only the business share of an expense\'s GST is claimed', function () {
    // $110 with $10 GST at 60% business use: $6 claimed, $4 private
    basExpense($this, ['amount' => 11000, 'business_use_percent' => 60], tax: 1000);

    $report = $this->bas->report($this->companyId, '2026-07-01', '2026-09-30');

    expect($report['labels']['1B']['cents'])->toBe(600)
        ->and($report['purchases']['tax'])->toBe(1000)
        ->and($report['purchases']['private_use'])->toBe(400);
});

test('a GST purchase over the tax invoice threshold without a receipt is flagged', function () {
    Storage::fake('local');
    basExpense($this, ['amount' => 8250], tax: 750);
    $flagged = basExpense($this, ['amount' => 8251], tax: 750);
    $withReceipt = basExpense($this, ['amount' => 22000], tax: 2000);
    $upload = UploadedFile::fake()->createWithContent('receipt.pdf', '%PDF-1.4');
    app(ExpenseReceiptManager::class)->attach($withReceipt, new PendingExpenseReceipt($upload->getPathname(), 'receipt.pdf'));
    basExpense($this, ['amount' => 1500]);

    $checks = collect($this->bas->report($this->companyId, '2026-07-01', '2026-09-30')['checks'])->keyBy('key');

    expect(array_column($checks['receipt_missing']['items'], 'expense_id'))->toBe([$flagged->id])
        ->and($checks['expenses_without_tax']['count'])->toBe(1);
});

test('payments against bills carry their share of the bill\'s GST', function () {
    $tax = TaxType::factory()->create(['company_id' => $this->companyId, 'type' => 'GENERAL', 'transaction_type' => 'purchases', 'percent' => 20, 'calculation_type' => 'percentage', 'compound_tax' => false]);
    $payload = purchaseBillPayload($this, 10000);
    $payload['items'][0]['tax_type_ids'] = [$tax->id];
    $bill = app(PurchaseDocumentService::class)->saveBill(null, $this->companyId, $this->user->id, $payload);
    // $120 with $20 tax; $60 paid in the quarter carries $10
    app(SupplierSettlementService::class)->recordPayment($this->companyId, $this->user->id, [
        'supplier_id' => $this->supplier->id, 'currency_id' => $this->currencyId, 'exchange_rate' => 1,
        'amount' => 6000, 'payment_date' => '2026-09-02', 'allocations' => [['bill_id' => $bill->id, 'amount' => 6000]],
    ]);

    $report = $this->bas->report($this->companyId, '2026-07-01', '2026-09-30');

    expect($report['labels']['1B']['cents'])->toBe(1000)
        ->and($report['purchases']['bills'][0]['amount'])->toBe(6000);
});

test('a refund of a supplier credit gives its GST back', function () {
    $tax = TaxType::factory()->create(['company_id' => $this->companyId, 'type' => 'GENERAL', 'transaction_type' => 'purchases', 'percent' => 20, 'calculation_type' => 'percentage', 'compound_tax' => false]);
    $payload = purchaseBillPayload($this, 10000);
    $payload['items'][0]['tax_type_ids'] = [$tax->id];
    $documents = app(PurchaseDocumentService::class);
    $bill = $documents->saveBill(null, $this->companyId, $this->user->id, $payload);
    // A tenth of the bill credited: $12 with $2 tax, all of it refunded
    $credit = $documents->createCredit($this->companyId, $this->user->id, [...$payload, 'source_bill_id' => $bill->id, 'items' => [[...$payload['items'][0], 'source_bill_item_id' => $bill->items[0]->id, 'quantity' => 0.1]]]);
    app(SupplierSettlementService::class)->recordRefund($this->companyId, $this->user->id, ['supplier_credit_id' => $credit->id, 'amount' => 1200, 'exchange_rate' => 1, 'payment_date' => '2026-09-03']);

    $report = $this->bas->report($this->companyId, '2026-09-01', '2026-09-30');

    expect($report['labels']['1B']['cents'])->toBe(-200)
        ->and(collect($report['checks'])->pluck('key'))->toContain('supplier_credits');
});

test('on an accrual basis GST follows the document dates and counts credit notes', function () {
    basInvoice($this, ['total' => 11000, 'tax' => 1000, 'invoice_date' => '2026-08-01']);
    basInvoice($this, ['total' => 99000, 'tax' => 9000, 'invoice_date' => '2026-08-01', 'status' => Invoice::STATUS_DRAFT]);
    basInvoice($this, ['type' => Invoice::TYPE_CREDIT_NOTE, 'status' => Invoice::STATUS_DRAFT, 'total' => -2200, 'tax' => -200, 'invoice_date' => '2026-08-02']);
    basInvoice($this, ['total' => 5500, 'tax' => 500, 'invoice_date' => '2026-10-01']);

    $report = $this->bas->report($this->companyId, '2026-07-01', '2026-09-30', ['basis' => BasQuery::BASIS_ACCRUAL]);

    expect($report['basis'])->toBe('accrual')
        ->and($report['labels']['G1']['cents'])->toBe(8800)
        ->and($report['labels']['1A']['cents'])->toBe(800);
});

test('the PAYG instalment is the amount on the notice or income times the rate', function () {
    $invoice = basInvoice($this, ['total' => 1100099, 'tax' => 100009]);
    basPayment($this, '2026-08-01', [[$invoice, 1100099]]);
    basExpense($this, ['amount' => 33000], tax: 3000);

    $byAmount = $this->bas->report($this->companyId, '2026-07-01', '2026-09-30', ['payg_method' => 'amount', 'payg_amount' => 123456]);
    // T1 = $11,000.99 - $1,000.09 = $10,000.90, so 10000 whole dollars; at 4.5% T11 = 450
    $byRate = $this->bas->report($this->companyId, '2026-07-01', '2026-09-30', ['payg_method' => 'rate', 'payg_rate' => 4.5]);

    expect($byAmount['payg']['T7'])->toBe(1234)
        ->and($byAmount['payg']['5A'])->toBe(1234)
        ->and($byRate['payg']['T1'])->toBe(10000)
        ->and($byRate['payg']['T11'])->toBe(450)
        // 1A $1,000.09 -> 1000, 1B $30 -> 30
        ->and($byRate['totals'])->toBe(['8A' => 1450, '8B' => 30, '9' => 1420]);
});

test('the stored settings choose the basis and the PAYG method', function () {
    CompanySetting::setSettings(['bas_gst_basis' => 'accrual', 'bas_payg_method' => 'amount', 'bas_payg_amount' => '50000'], $this->companyId);

    $report = $this->bas->report($this->companyId, '2026-07-01', '2026-09-30');

    expect($report['basis'])->toBe('accrual')->and($report['payg']['5A'])->toBe(500);

    CompanySetting::setSettings(['bas_gst_basis' => 'nonsense', 'bas_payg_method' => 'nonsense'], $this->companyId);

    $report = $this->bas->report($this->companyId, '2026-07-01', '2026-09-30');

    expect($report['basis'])->toBe('cash')->and($report['payg']['method'])->toBe('none');
});

test('the worksheet is served as JSON, a PDF and a CSV', function () {
    $invoice = basInvoice($this, ['total' => 11000, 'tax' => 1000]);
    basPayment($this, '2026-08-01', [[$invoice, 11000]]);
    $params = 'from_date=2026-07-01&to_date=2026-09-30';

    $this->getJson('/api/v1/reports/bas?'.$params)
        ->assertOk()
        ->assertJsonPath('data.labels.G1.cents', 11000)
        ->assertJsonPath('data.labels.1A.dollars', 10);

    $preview = $this->get('/reports/bas/'.$this->company->unique_hash.'?'.$params.'&preview=true')
        ->assertOk()
        ->assertSee(__('pdf_bas_title'));
    expect($preview->viewData('report')['labels']['G1']['cents'])->toBe(11000);

    $csv = $this->get('/reports/bas/'.$this->company->unique_hash.'?'.$params.'&format=csv')->assertOk();
    expect($csv->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($csv->streamedContent())->toContain('G1')->toContain('110.00');
});

test('a worksheet covers at most a year', function () {
    $this->getJson('/api/v1/reports/bas?from_date=2026-07-01&to_date=2027-07-01')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to_date');
    $this->getJson('/api/v1/reports/bas?from_date=2026-07-01&to_date=2027-06-30')->assertOk();
});

test('the worksheet needs the report ability and membership of the company', function () {
    $foreign = Company::factory()->create();
    $user = User::factory()->create(['role' => 'customer']);
    $user->companies()->attach($this->companyId);
    BouncerFacade::scope()->to($this->companyId);
    BouncerFacade::refresh();
    Sanctum::actingAs($user);
    $params = '?from_date=2026-07-01&to_date=2026-09-30';

    $this->getJson('/api/v1/reports/bas'.$params)->assertForbidden();

    BouncerFacade::allow($user)->to('view-financial-reports', null);
    BouncerFacade::refresh();

    $this->getJson('/api/v1/reports/bas'.$params)->assertOk();
    $this->get('/reports/bas/'.$this->company->unique_hash.$params.'&preview=true')->assertOk();
    $this->get('/reports/bas/'.$foreign->unique_hash.$params.'&preview=true')->assertForbidden();
});

test('the tax summary counts credit notes, which are kept as drafts', function () {
    $type = TaxType::factory()->create(['company_id' => $this->companyId, 'name' => 'GST']);
    $invoice = basInvoice($this, ['total' => 1100, 'tax' => 100, 'invoice_date' => '2026-08-01']);
    $credit = basInvoice($this, ['type' => Invoice::TYPE_CREDIT_NOTE, 'status' => Invoice::STATUS_DRAFT, 'related_invoice_id' => $invoice->id, 'total' => -440, 'tax' => -40, 'invoice_date' => '2026-08-02']);
    $draft = basInvoice($this, ['total' => 9900, 'tax' => 900, 'invoice_date' => '2026-08-03', 'status' => Invoice::STATUS_DRAFT]);

    foreach ([[$invoice, 100], [$credit, -40], [$draft, 900]] as [$document, $amount]) {
        Tax::factory()->create(['company_id' => $this->companyId, 'invoice_id' => $document->id, 'tax_type_id' => $type->id, 'name' => 'GST', 'percent' => 10, 'amount' => $amount, 'base_amount' => $amount]);
    }

    $sales = app(TaxSummaryQuery::class)->report($this->companyId, '2026-08-01', '2026-08-31')['sales'];

    expect((int) $sales->sum('total_tax_amount'))->toBe(60);
});
