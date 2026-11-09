<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Metadata\Models\CustomField;
use App\Domains\Metadata\Models\CustomFieldValue;
use App\Domains\Purchases\Models\Expense;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Models\TaxType;
use Illuminate\Database\Eloquent\Model;

/**
 * Income and costs per project, matched across invoices and expenses by the
 * answer in a custom field.
 */
beforeEach(function () {
    purchaseFixtures($this);
    $this->company = Company::findOrFail($this->companyId);
    CompanySetting::setSettings(['language' => 'en', 'carbon_date_format' => 'Y-m-d', 'time_zone' => 'UTC'], $this->companyId);
    config(['pdf.driver' => 'dompdf']);
    $this->customer = Customer::factory()->create(['company_id' => $this->companyId]);
    $this->invoiceField = projectField($this, 'Invoice');
    $this->expenseField = projectField($this, 'Expense');
    $this->gst = TaxType::factory()->create([
        'company_id' => $this->companyId, 'name' => 'GST', 'percent' => 10, 'calculation_type' => 'percentage',
        'fixed_amount' => null, 'compound_tax' => false, 'transaction_type' => TaxType::TRANSACTION_TYPE_PURCHASES,
    ]);
    $this->period = ['from_date' => '2026-07-01', 'to_date' => '2026-09-30'];
});

function projectField($test, string $model, string $label = 'Project number'): CustomField
{
    return CustomField::create([
        'company_id' => $test->companyId, 'name' => $label, 'label' => $label, 'slug' => 'CUSTOM_'.strtoupper($model).'_PROJECT_'.uniqid(),
        'model_type' => $model, 'type' => 'Input', 'is_required' => false, 'order' => 1,
    ]);
}

function answerProject(CustomField $field, Model $record, string $answer): void
{
    CustomFieldValue::create([
        'custom_field_id' => $field->id, 'company_id' => $field->company_id, 'type' => 'Input', 'string_answer' => $answer,
        'custom_field_valuable_type' => $record->getMorphClass(), 'custom_field_valuable_id' => $record->id,
    ]);
}

function projectInvoice($test, int $total, int $tax, array $attributes = []): Invoice
{
    return Invoice::factory()->create([
        'company_id' => $test->companyId, 'customer_id' => $test->customer->id, 'currency_id' => $test->currencyId,
        'status' => Invoice::STATUS_SENT, 'type' => Invoice::TYPE_INVOICE, 'exchange_rate' => 1, 'invoice_date' => '2026-08-01',
        'total' => $total, 'tax' => $tax, 'base_total' => $total, 'base_tax' => $tax, ...$attributes,
    ]);
}

function projectExpense($test, int $amount, int $tax = 0): Expense
{
    $expense = Expense::factory()->create([
        'company_id' => $test->companyId, 'expense_category_id' => $test->category->id, 'currency_id' => $test->currencyId,
        'exchange_rate' => 1, 'expense_date' => '2026-08-10', 'amount' => $amount, 'base_amount' => $amount,
    ]);

    if ($tax) {
        Tax::factory()->create([
            'company_id' => $test->companyId, 'expense_id' => $expense->id, 'tax_type_id' => $test->gst->id,
            'name' => 'GST', 'percent' => 10, 'amount' => $tax, 'base_amount' => $tax,
        ]);
    }

    return $expense;
}

test('income and costs are matched per project without GST', function () {
    $first = projectInvoice($this, 110000, 10000);
    answerProject($this->invoiceField, $first, 'P-100');
    // The same project however it is typed
    answerProject($this->invoiceField, projectInvoice($this, 22000, 2000), ' p-100 ');
    // A credit note answers for its invoice's project
    projectInvoice($this, -11000, -1000, ['type' => Invoice::TYPE_CREDIT_NOTE, 'status' => Invoice::STATUS_DRAFT, 'related_invoice_id' => $first->id]);
    answerProject($this->invoiceField, projectInvoice($this, 5500, 500), 'P-200');
    projectInvoice($this, 9900, 900);
    // Drafts and other periods stay out
    answerProject($this->invoiceField, projectInvoice($this, 99000, 9000, ['status' => Invoice::STATUS_DRAFT]), 'P-100');
    answerProject($this->invoiceField, projectInvoice($this, 99000, 9000, ['invoice_date' => '2026-10-01']), 'P-100');

    answerProject($this->expenseField, projectExpense($this, 33000, 3000), 'P-100');
    projectExpense($this, 1100, 100);

    $report = $this->getJson('/api/v1/reports/projects?'.http_build_query($this->period))->assertOk()->json('data');
    $rows = collect($report['projects'])->keyBy('key');

    expect($report['invoice_field_id'])->toBe($this->invoiceField->id)
        ->and($report['expense_field_id'])->toBe($this->expenseField->id)
        ->and(array_column($report['projects'], 'key'))->toBe(['p-100', 'p-200', ''])
        // $1,000 + $200 - $100 invoiced; $300 spent
        ->and($rows['p-100']['project'])->toBe('P-100')
        ->and($rows['p-100']['income'])->toBe(110000)
        ->and($rows['p-100']['costs'])->toBe(30000)
        ->and($rows['p-100']['profit'])->toBe(80000)
        ->and($rows['p-200']['income'])->toBe(5000)
        ->and($rows['']['income'])->toBe(9000)
        ->and($rows['']['costs'])->toBe(1000)
        ->and($report['totals'])->toBe(['income' => 124000, 'costs' => 31000, 'profit' => 93000]);
});

test('fields with other labels can be paired by hand', function () {
    $job = projectField($this, 'Expense', 'Job');
    $invoice = projectInvoice($this, 11000, 1000);
    answerProject($this->invoiceField, $invoice, 'P-1');
    answerProject($job, projectExpense($this, 2200, 200), 'P-1');

    $report = $this->getJson('/api/v1/reports/projects?'.http_build_query([...$this->period, 'expense_field_id' => $job->id]))
        ->assertOk()
        ->json('data');

    expect($report['expense_field_id'])->toBe($job->id)
        ->and($report['projects'][0]['costs'])->toBe(2000);

    $this->getJson('/api/v1/reports/projects?'.http_build_query([...$this->period, 'expense_field_id' => $this->invoiceField->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expense_field_id');
});

test('the project report is served as a PDF and a CSV', function () {
    answerProject($this->invoiceField, projectInvoice($this, 11000, 1000), 'P-100');
    $url = '/reports/projects/'.$this->company->unique_hash.'?'.http_build_query($this->period);

    $this->get($url.'&preview=true')->assertOk()->assertSee('P-100');

    $csv = $this->get($url.'&format=csv')->assertOk();
    expect($csv->streamedContent())->toContain('P-100')->toContain('100.00');
});
