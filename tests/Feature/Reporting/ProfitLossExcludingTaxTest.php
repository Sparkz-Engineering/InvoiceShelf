<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Purchases\Models\Expense;
use App\Domains\Receivables\Models\Payment;
use App\Domains\Receivables\Models\PaymentAllocation;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Models\TaxType;

/**
 * Profit and loss without GST, as an income tax return needs it.
 */
beforeEach(function () {
    purchaseFixtures($this);
    $this->company = Company::findOrFail($this->companyId);
    CompanySetting::setSettings(['language' => 'en', 'carbon_date_format' => 'Y-m-d', 'time_zone' => 'UTC'], $this->companyId);
    config(['pdf.driver' => 'dompdf']);

    $customer = Customer::factory()->create(['company_id' => $this->companyId]);
    // $110 invoiced with $10 GST, all of it paid, plus $5 received and not
    // yet set against anything
    $invoice = Invoice::factory()->create([
        'company_id' => $this->companyId, 'customer_id' => $customer->id, 'currency_id' => $this->currencyId,
        'status' => Invoice::STATUS_SENT, 'type' => Invoice::TYPE_INVOICE, 'exchange_rate' => 1, 'invoice_date' => '2026-08-01',
        'total' => 11000, 'tax' => 1000, 'base_total' => 11000, 'base_tax' => 1000,
    ]);
    $payment = Payment::factory()->create([
        'company_id' => $this->companyId, 'customer_id' => $customer->id, 'currency_id' => $this->currencyId,
        'payment_date' => '2026-08-05', 'amount' => 11500, 'base_amount' => 11500, 'exchange_rate' => 1,
    ]);
    PaymentAllocation::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => 11000, 'base_amount' => 11000]);

    // $55 spent with $5 GST on it
    $gst = TaxType::factory()->create([
        'company_id' => $this->companyId, 'name' => 'GST', 'percent' => 10, 'calculation_type' => 'percentage',
        'fixed_amount' => null, 'compound_tax' => false, 'transaction_type' => TaxType::TRANSACTION_TYPE_PURCHASES,
    ]);
    $expense = Expense::factory()->create([
        'company_id' => $this->companyId, 'expense_category_id' => $this->category->id, 'currency_id' => $this->currencyId,
        'exchange_rate' => 1, 'expense_date' => '2026-08-10', 'amount' => 5500, 'base_amount' => 5500,
    ]);
    Tax::factory()->create([
        'company_id' => $this->companyId, 'expense_id' => $expense->id, 'tax_type_id' => $gst->id,
        'name' => 'GST', 'percent' => 10, 'amount' => 500, 'base_amount' => 500,
    ]);

    $this->url = '/reports/profit-loss/'.$this->company->unique_hash.'?from_date=2026-07-01&to_date=2026-09-30&preview=true';
});

test('the profit and loss includes GST unless asked to leave it out', function () {
    $this->get($this->url)
        ->assertOk()
        ->assertViewHas('income', 11500)
        ->assertViewHas('totalExpense', 5500)
        ->assertDontSee(__('pdf_profit_loss_excludes_tax_note'));
});

test('without GST, income loses the tax in payments and expenses the tax on receipts', function () {
    $this->get($this->url.'&exclude_tax=1')
        ->assertOk()
        // $115 received less the $10 GST in the invoice payment; the $5 not
        // yet allocated stays as received
        ->assertViewHas('income', 10500)
        ->assertViewHas('totalExpense', 5000)
        ->assertSee(__('pdf_profit_loss_excludes_tax_note'));
});
