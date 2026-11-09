<?php

namespace App\Domains\Reporting\Queries;

use App\Domains\Purchases\Application\ExpenseTaxPosition;
use App\Domains\Purchases\Models\Expense;
use App\Domains\Purchases\Models\SupplierPaymentAllocation;
use App\Domains\Purchases\Models\SupplierRefund;
use App\Domains\Receivables\Models\Payment;
use App\Domains\Receivables\Models\PaymentAllocation;
use App\Support\ProportionalAmount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The tax inside the money that actually moved in a period, as cash
 * accounting counts it: what customers paid against invoices, and what was
 * paid out on expenses and bills.
 *
 * A payment carries no tax of its own. The tax in each slice of a payment is
 * the invoice's share: across the invoice's life the slices add up to exactly
 * the invoice's tax (less any credit notes), whichever order and size the
 * payments came in. The share is worked out in the document's currency and
 * restated at the payment's own exchange rate, as the money itself is.
 *
 * Every figure is whole minor units of the company's currency.
 */
class CashTaxQuery
{
    /**
     * Money received from customers in the period against invoices.
     *
     * @return array{total: int, tax: int, lines: list<array<string, mixed>>, unallocated: list<array<string, mixed>>}
     */
    public function received(int $companyId, string $from, string $to): array
    {
        $allocations = $this->customerAllocations($companyId)
            ->where('customer_payments.payment_date', '>=', $from)
            ->where('customer_payments.payment_date', '<', $this->dayAfter($to))
            ->with([
                'invoice' => fn ($query) => $query
                    ->select(['id', 'invoice_number', 'customer_id', 'tax', 'total'])
                    ->withSum('creditNotes as credited_tax', 'tax')
                    ->withSum('creditNotes as credited_total', 'total'),
                'invoice.customer:id,name',
            ])
            ->orderBy('customer_payments.payment_date')
            ->orderBy('customer_payments.id')
            ->orderBy('customer_payment_allocations.id')
            ->get();

        $paidBefore = $this->customerAllocations($companyId)
            ->whereIn('customer_payment_allocations.invoice_id', $allocations->pluck('invoice_id')->unique())
            ->where('customer_payments.payment_date', '<', $from)
            ->get()
            ->groupBy('invoice_id')
            ->map(fn (Collection $rows): int => (int) $rows->sum('amount'));

        $lines = [];

        foreach ($allocations as $allocation) {
            $invoice = $allocation->invoice;

            if (! $invoice) {
                continue;
            }

            $before = (int) ($paidBefore[$invoice->id] ?? 0);
            $paidBefore[$invoice->id] = $before + (int) $allocation->amount;

            $tax = $this->taxInSlice(
                (int) $invoice->tax + (int) $invoice->credited_tax,
                (int) $invoice->total + (int) $invoice->credited_total,
                $before,
                (int) $allocation->amount,
                (int) $allocation->base_amount,
            );

            $lines[] = [
                'payment_id' => (int) $allocation->payment_id,
                'payment_number' => $allocation->payment_number,
                'payment_date' => substr((string) $allocation->payment_date, 0, 10),
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer' => $invoice->customer?->name,
                'amount' => (int) $allocation->base_amount,
                'tax' => $tax,
            ];
        }

        return [
            'total' => array_sum(array_column($lines, 'amount')),
            'tax' => array_sum(array_column($lines, 'tax')),
            'lines' => $lines,
            'unallocated' => $this->unallocatedPayments($companyId, $from, $to),
        ];
    }

    /**
     * Money paid out in the period: expenses (paid on their date), payments
     * against bills, and refunds of supplier credits coming back.
     *
     * `tax` is everything on the receipts; `claimable` is the business share
     * of it, which is what can be claimed. A refund gives tax back, so its
     * lines are negative.
     *
     * @return array{total: int, tax: int, claimable: int, expenses: list<array<string, mixed>>, bills: list<array<string, mixed>>, refunds: list<array<string, mixed>>}
     */
    public function paid(int $companyId, string $from, string $to): array
    {
        $expenses = $this->expenseLines($companyId, $from, $to);
        $bills = $this->billLines($companyId, $from, $to);
        $refunds = $this->refundLines($companyId, $from, $to);

        $all = [...$expenses, ...$bills, ...$refunds];

        return [
            'total' => array_sum(array_column($all, 'amount')),
            'tax' => array_sum(array_column($all, 'tax')),
            'claimable' => array_sum(array_column($all, 'claimable')),
            'expenses' => $expenses,
            'bills' => $bills,
            'refunds' => $refunds,
        ];
    }

    /**
     * Expenses dated in the period, each with its tax and the claimable part.
     *
     * @return list<array<string, mixed>>
     */
    public function expenseLines(int $companyId, string $from, string $to): array
    {
        return Expense::query()
            ->where('company_id', $companyId)
            ->where('expense_date', '>=', $from)
            ->where('expense_date', '<', $this->dayAfter($to))
            ->with(['taxes', 'category:id,name', 'supplier:id,name', 'media'])
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get()
            ->map(function (Expense $expense): array {
                $position = ExpenseTaxPosition::inBaseCurrency($expense);

                return [
                    'expense_id' => (int) $expense->id,
                    'date' => substr((string) $expense->expense_date, 0, 10),
                    'number' => $expense->expense_number,
                    'category' => $expense->category?->name,
                    'supplier' => $expense->supplier?->name,
                    'amount' => $position->gross,
                    'tax' => $position->tax,
                    'claimable' => $position->claimableTax(),
                    'business_use_percent' => (float) $expense->business_use_percent,
                    'has_receipt' => $expense->getFirstMedia('receipts') !== null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Payments against bills made in the period, each slice carrying its
     * share of the bill's tax (less any supplier credits raised from it).
     *
     * @return list<array<string, mixed>>
     */
    private function billLines(int $companyId, string $from, string $to): array
    {
        $allocations = $this->supplierAllocations($companyId)
            ->where('supplier_payments.payment_date', '>=', $from)
            ->where('supplier_payments.payment_date', '<', $this->dayAfter($to))
            ->with([
                'bill' => fn ($query) => $query
                    ->select(['id', 'number', 'supplier_id', 'tax', 'total'])
                    ->withSum(['credits as credited_tax' => fn ($credits) => $credits->where('status', 'OPEN')], 'tax')
                    ->withSum(['credits as credited_total' => fn ($credits) => $credits->where('status', 'OPEN')], 'total'),
                'bill.supplier:id,name',
            ])
            ->orderBy('supplier_payments.payment_date')
            ->orderBy('supplier_payments.id')
            ->orderBy('supplier_payment_allocations.id')
            ->get();

        $paidBefore = $this->supplierAllocations($companyId)
            ->whereIn('supplier_payment_allocations.bill_id', $allocations->pluck('bill_id')->unique())
            ->where('supplier_payments.payment_date', '<', $from)
            ->get()
            ->groupBy('bill_id')
            ->map(fn (Collection $rows): int => (int) $rows->sum('amount'));

        $lines = [];

        foreach ($allocations as $allocation) {
            $bill = $allocation->bill;

            if (! $bill) {
                continue;
            }

            $before = (int) ($paidBefore[$bill->id] ?? 0);
            $paidBefore[$bill->id] = $before + (int) $allocation->amount;

            $tax = $this->taxInSlice(
                (int) $bill->tax - (int) $bill->credited_tax,
                (int) $bill->total - (int) $bill->credited_total,
                $before,
                (int) $allocation->amount,
                (int) $allocation->base_amount,
            );

            $lines[] = [
                'payment_id' => (int) $allocation->supplier_payment_id,
                'payment_number' => $allocation->payment_number,
                'date' => substr((string) $allocation->payment_date, 0, 10),
                'bill_id' => (int) $bill->id,
                'bill_number' => $bill->number,
                'supplier' => $bill->supplier?->name,
                'amount' => (int) $allocation->base_amount,
                'tax' => $tax,
                'claimable' => $tax,
            ];
        }

        return $lines;
    }

    /**
     * Refunds of supplier credits received in the period. The tax inside a
     * refund is the credit's share; for a credit raised from an expense only
     * the business share was ever claimed, so only that much comes back.
     *
     * @return list<array<string, mixed>>
     */
    private function refundLines(int $companyId, string $from, string $to): array
    {
        return SupplierRefund::query()
            ->where('company_id', $companyId)
            ->where('status', 'OPEN')
            ->whereNotNull('supplier_credit_id')
            ->where('payment_date', '>=', $from)
            ->where('payment_date', '<', $this->dayAfter($to))
            ->with(['credit.sourceExpense', 'supplier:id,name'])
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get()
            ->map(function (SupplierRefund $refund): array {
                $credit = $refund->credit;
                $amount = (int) $refund->base_amount;
                $tax = $credit && (int) $credit->tax > 0
                    ? ProportionalAmount::floor($amount, (int) $credit->tax, (int) $credit->total)
                    : 0;
                $share = ExpenseTaxPosition::basisPoints($credit?->sourceExpense?->business_use_percent);

                return [
                    'refund_id' => (int) $refund->id,
                    'number' => $refund->number,
                    'date' => substr((string) $refund->payment_date, 0, 10),
                    'supplier' => $refund->supplier?->name,
                    'amount' => -$amount,
                    'tax' => -$tax,
                    'claimable' => -ProportionalAmount::floor($tax, $share, ExpenseTaxPosition::WHOLE),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Customer payments in the period with money not yet set against any
     * invoice. Cash accounting counts tax when money arrives, so this money
     * belongs in the period too, but until it is allocated nobody can say
     * how much of it is tax.
     *
     * @return list<array<string, mixed>>
     */
    private function unallocatedPayments(int $companyId, string $from, string $to): array
    {
        return Payment::query()
            ->where('company_id', $companyId)
            ->where('payment_date', '>=', $from)
            ->where('payment_date', '<', $this->dayAfter($to))
            ->withSum('allocations as allocated', 'base_amount')
            ->with('customer:id,name')
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Payment $payment): array => [
                'payment_id' => (int) $payment->id,
                'payment_number' => $payment->payment_number,
                'date' => substr((string) $payment->payment_date, 0, 10),
                'customer' => $payment->customer?->name,
                'amount' => (int) $payment->base_amount - (int) $payment->allocated,
            ])
            ->filter(fn (array $line): bool => $line['amount'] > 0)
            ->values()
            ->all();
    }

    /**
     * The tax inside one slice of a document's settlement, in the company's
     * currency.
     *
     * `$before` is what had already been settled; the slice runs from there to
     * `$before + $amount`. The document's tax is cut in proportion, so the
     * slices of a fully settled document add up to its tax exactly, and the
     * result is restated at the slice's own rate (`$baseAmount / $amount`).
     */
    private function taxInSlice(int $tax, int $total, int $before, int $amount, int $baseAmount): int
    {
        if ($tax <= 0 || $total <= 0 || $amount <= 0) {
            return 0;
        }

        $from = min($before, $total);
        $until = min($before + $amount, $total);
        $taxInDocumentCurrency = ProportionalAmount::slice($tax, $from, $until, $total);

        return ProportionalAmount::floor($baseAmount, $taxInDocumentCurrency, $amount);
    }

    /**
     * One company's customer allocations, each with its payment's date and
     * number alongside.
     */
    private function customerAllocations(int $companyId): Builder
    {
        return PaymentAllocation::query()
            ->select([
                'customer_payment_allocations.*',
                'customer_payments.payment_date as payment_date',
                'customer_payments.payment_number as payment_number',
            ])
            ->join('customer_payments', 'customer_payments.id', '=', 'customer_payment_allocations.payment_id')
            ->where('customer_payments.company_id', $companyId);
    }

    /**
     * One company's allocations of open supplier payments to bills, each with
     * its payment's date and number alongside.
     */
    private function supplierAllocations(int $companyId): Builder
    {
        return SupplierPaymentAllocation::query()
            ->select([
                'supplier_payment_allocations.*',
                'supplier_payments.payment_date as payment_date',
                'supplier_payments.number as payment_number',
            ])
            ->join('supplier_payments', 'supplier_payments.id', '=', 'supplier_payment_allocations.supplier_payment_id')
            ->where('supplier_payments.company_id', $companyId)
            ->where('supplier_payments.status', 'OPEN');
    }

    private function dayAfter(string $date): string
    {
        return CarbonImmutable::parse($date)->addDay()->toDateString();
    }
}
