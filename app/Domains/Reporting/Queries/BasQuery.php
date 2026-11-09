<?php

namespace App\Domains\Reporting\Queries;

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Money\Models\Currency;
use App\Domains\Purchases\Application\ExpenseTaxPosition;
use App\Domains\Purchases\Models\Bill;
use App\Domains\Purchases\Models\SupplierCredit;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Taxation\Models\BasLodgement;
use App\Support\ProportionalAmount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The figures an Australian Business Activity Statement asks for, worked out
 * for one period: G1, 1A and 1B of the GST section, the PAYG instalment, and
 * what is owed or refunded.
 *
 * GST is counted on the company's accounting basis. On a cash basis sales
 * are what customers paid against invoices in the period and purchases what
 * was paid out (CashTaxQuery). On an accrual basis they are the invoices,
 * credit notes, expenses and bills dated in the period. Either way an
 * expense's GST credit is only its business share (ExpenseTaxPosition).
 *
 * The form takes whole dollars: each label is shown exactly and with its
 * cents dropped, and the totals are worked out from the whole-dollar figures
 * as the form itself does.
 *
 * Alongside the figures come the rows behind them and a list of checks:
 * things worth fixing before the statement is lodged. Once a period is marked
 * lodged, its figures as lodged come back too, with any label that has moved
 * since, because an invoice, payment or expense in the period was changed
 * after the statement went in.
 */
class BasQuery
{
    public const BASIS_CASH = 'cash';

    public const BASIS_ACCRUAL = 'accrual';

    public const PAYG_NONE = 'none';

    public const PAYG_AMOUNT = 'amount';

    public const PAYG_RATE = 'rate';

    /**
     * A GST credit over this much, tax included, needs a tax invoice to be
     * claimed ($82.50 in minor units).
     */
    public const TAX_INVOICE_THRESHOLD = 8250;

    public function __construct(private readonly CashTaxQuery $cash) {}

    /**
     * The company's own BAS settings, read defensively: the settings endpoint
     * stores whatever it is sent.
     *
     * @return array{basis: string, payg_method: string, payg_amount: int, payg_rate: float}
     */
    public function settings(int $companyId): array
    {
        $stored = CompanySetting::getSettings(
            ['bas_gst_basis', 'bas_payg_method', 'bas_payg_amount', 'bas_payg_rate'],
            $companyId,
        );

        $basis = $stored['bas_gst_basis'] ?? null;
        $method = $stored['bas_payg_method'] ?? null;

        return [
            'basis' => in_array($basis, [self::BASIS_CASH, self::BASIS_ACCRUAL], true) ? $basis : self::BASIS_CASH,
            'payg_method' => in_array($method, [self::PAYG_AMOUNT, self::PAYG_RATE], true) ? $method : self::PAYG_NONE,
            'payg_amount' => max(0, (int) ($stored['bas_payg_amount'] ?? 0)),
            'payg_rate' => max(0.0, min(100.0, (float) ($stored['bas_payg_rate'] ?? 0))),
        ];
    }

    /**
     * The statement for a period.
     *
     * `$overrides` may replace any of the stored settings for this one
     * worksheet (the PAYG figures change from notice to notice).
     *
     * @param  array{basis?: string|null, payg_method?: string|null, payg_amount?: int|null, payg_rate?: float|null}  $overrides
     */
    public function report(int $companyId, string $from, string $to, array $overrides = []): array
    {
        $settings = array_merge(
            $this->settings($companyId),
            array_filter($overrides, fn ($value) => $value !== null),
        );

        $basis = $settings['basis'];

        [$sales, $purchases] = $basis === self::BASIS_ACCRUAL
            ? [$this->accrualSales($companyId, $from, $to), $this->accrualPurchases($companyId, $from, $to)]
            : [$this->cash->received($companyId, $from, $to), $this->cash->paid($companyId, $from, $to)];

        $g1 = (int) $sales['total'];
        $a1 = (int) $sales['tax'];
        $b1 = (int) $purchases['claimable'];

        $payg = $this->payg($settings, $g1 - $a1);

        $labels = [
            'G1' => $this->figure($g1),
            '1A' => $this->figure($a1),
            '1B' => $this->figure($b1),
        ];

        $owed = $labels['1A']['dollars'] + $payg['5A'];
        $credits = $labels['1B']['dollars'];

        $lodgement = $this->lodgement($companyId, $from, $to, $labels);
        $checks = $this->checks($basis, $sales, $purchases);

        if ($lodgement && $lodgement['changes']) {
            array_unshift($checks, [
                'key' => 'lodged_figures_changed',
                'severity' => 'warning',
                'count' => count($lodgement['changes']),
                'amount' => 0,
                'items' => $lodgement['changes'],
            ]);
        }

        return [
            'from_date' => $from,
            'to_date' => $to,
            'basis' => $basis,
            'currency' => $this->currency($companyId),
            'labels' => $labels,
            'payg' => $payg,
            'totals' => [
                '8A' => $owed,
                '8B' => $credits,
                '9' => $owed - $credits,
            ],
            'sales' => $sales,
            'purchases' => [
                ...$purchases,
                'private_use' => (int) $purchases['tax'] - (int) $purchases['claimable'],
            ],
            'checks' => $checks,
            'lodgement' => $lodgement,
        ];
    }

    /**
     * The lodgement recorded for exactly this period, if any, and the GST
     * labels that no longer match it.
     *
     * Only G1, 1A and 1B are compared: they come from the records, while the
     * PAYG figures come from the notice and may be entered differently.
     *
     * @param  array<string, array{cents: int, dollars: int}>  $labels
     */
    private function lodgement(int $companyId, string $from, string $to, array $labels): ?array
    {
        $lodgement = BasLodgement::query()
            ->forCompany($companyId)
            ->whereDate('period_start', $from)
            ->whereDate('period_end', $to)
            ->first();

        if (! $lodgement) {
            return null;
        }

        $lodged = $lodgement->figures['labels'] ?? [];
        $changes = [];

        foreach ($labels as $label => $figure) {
            $was = (int) ($lodged[$label]['dollars'] ?? 0);

            if ($was !== $figure['dollars']) {
                $changes[] = ['label' => $label, 'lodged' => $was, 'current' => $figure['dollars']];
            }
        }

        return [
            'id' => (int) $lodgement->id,
            'lodged_on' => $lodgement->lodged_on?->toDateString(),
            'reference' => $lodgement->reference,
            'notes' => $lodgement->notes,
            'figures' => $lodgement->figures,
            'changes' => $changes,
        ];
    }

    /**
     * One label: the exact figure and the whole dollars the form takes.
     *
     * @return array{cents: int, dollars: int}
     */
    private function figure(int $cents): array
    {
        return ['cents' => $cents, 'dollars' => intdiv($cents, 100)];
    }

    /**
     * The PAYG instalment, by amount (T7, from the ATO's notice) or by rate
     * (T1 instalment income times the T2 rate gives T11). Instalment income
     * is taken as the period's sales without GST, in whole dollars.
     *
     * @return array{method: string, T1: int|null, T2: float|null, T7: int|null, T11: int|null, '5A': int}
     */
    private function payg(array $settings, int $incomeCents): array
    {
        $method = $settings['payg_method'] ?? self::PAYG_NONE;

        if ($method === self::PAYG_AMOUNT) {
            $t7 = intdiv(max(0, (int) $settings['payg_amount']), 100);

            return ['method' => $method, 'T1' => null, 'T2' => null, 'T7' => $t7, 'T11' => null, '5A' => $t7];
        }

        if ($method === self::PAYG_RATE) {
            $t1 = intdiv(max(0, $incomeCents), 100);
            $rate = (float) $settings['payg_rate'];
            $t11 = (int) floor($t1 * $rate / 100);

            return ['method' => $method, 'T1' => $t1, 'T2' => $rate, 'T7' => null, 'T11' => $t11, '5A' => $t11];
        }

        return ['method' => self::PAYG_NONE, 'T1' => null, 'T2' => null, 'T7' => null, 'T11' => null, '5A' => 0];
    }

    /**
     * Invoices issued and credit notes raised in the period, by document date.
     * A credit note is effective from the moment it exists, whatever its
     * status, and carries negative amounts.
     *
     * @return array{total: int, tax: int, lines: list<array<string, mixed>>, unallocated: list<array<string, mixed>>}
     */
    private function accrualSales(int $companyId, string $from, string $to): array
    {
        $lines = Invoice::query()
            ->where('company_id', $companyId)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $invoices) => $invoices
                    ->where('type', Invoice::TYPE_INVOICE)
                    ->where('status', '!=', Invoice::STATUS_DRAFT))
                ->orWhere('type', Invoice::TYPE_CREDIT_NOTE))
            ->where('invoice_date', '>=', $from)
            ->where('invoice_date', '<', $this->dayAfter($to))
            ->with('customer:id,name')
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'date' => substr((string) $invoice->getRawOriginal('invoice_date'), 0, 10),
                'type' => $invoice->type,
                'customer' => $invoice->customer?->name,
                'amount' => (int) $invoice->base_total,
                'tax' => (int) $invoice->base_tax,
            ])
            ->values()
            ->all();

        return [
            'total' => array_sum(array_column($lines, 'amount')),
            'tax' => array_sum(array_column($lines, 'tax')),
            'lines' => $lines,
            'unallocated' => [],
        ];
    }

    /**
     * Expenses, open bills and open supplier credits dated in the period. A
     * credit raised from an expense gives back only the business share that
     * was claimed.
     */
    private function accrualPurchases(int $companyId, string $from, string $to): array
    {
        $expenses = $this->cash->expenseLines($companyId, $from, $to);

        $bills = Bill::query()
            ->forCompany($companyId)
            ->where('status', 'OPEN')
            ->whereBetween('document_date', [$from, $to])
            ->with(['items', 'supplier:id,name'])
            ->orderBy('document_date')
            ->orderBy('id')
            ->get()
            ->map(function (Bill $bill): array {
                $tax = $this->itemTax($bill->items);

                return [
                    'bill_id' => (int) $bill->id,
                    'bill_number' => $bill->number,
                    'date' => substr((string) $bill->document_date, 0, 10),
                    'supplier' => $bill->supplier?->name,
                    'amount' => (int) $bill->base_total,
                    'tax' => $tax,
                    'claimable' => $tax,
                ];
            })
            ->values()
            ->all();

        $credits = SupplierCredit::query()
            ->forCompany($companyId)
            ->where('status', 'OPEN')
            ->whereBetween('document_date', [$from, $to])
            ->with(['items', 'supplier:id,name', 'sourceExpense'])
            ->orderBy('document_date')
            ->orderBy('id')
            ->get()
            ->map(function (SupplierCredit $credit): array {
                $tax = $this->itemTax($credit->items);
                $share = ExpenseTaxPosition::basisPoints($credit->sourceExpense?->business_use_percent);

                return [
                    'credit_id' => (int) $credit->id,
                    'number' => $credit->number,
                    'date' => substr((string) $credit->document_date, 0, 10),
                    'supplier' => $credit->supplier?->name,
                    'amount' => -(int) $credit->base_total,
                    'tax' => -$tax,
                    'claimable' => -ProportionalAmount::floor($tax, $share, ExpenseTaxPosition::WHOLE),
                ];
            })
            ->values()
            ->all();

        $all = [...$expenses, ...$bills, ...$credits];

        return [
            'total' => array_sum(array_column($all, 'amount')),
            'tax' => array_sum(array_column($all, 'tax')),
            'claimable' => array_sum(array_column($all, 'claimable')),
            'expenses' => $expenses,
            'bills' => $bills,
            'refunds' => $credits,
        ];
    }

    /**
     * The tax on a purchase document's lines, in the company's currency.
     */
    private function itemTax(iterable $items): int
    {
        $tax = 0;

        foreach ($items as $item) {
            foreach ($item->taxes ?? [] as $row) {
                $tax += (int) ($row['base_amount'] ?? 0);
            }
        }

        return $tax;
    }

    /**
     * Things worth sorting out before lodging.
     *
     * @return list<array{key: string, severity: string, count: int, amount: int, items: list<array<string, mixed>>}>
     */
    private function checks(string $basis, array $sales, array $purchases): array
    {
        $checks = [];

        $withoutReceipt = array_values(array_filter(
            $purchases['expenses'],
            fn (array $line): bool => $line['claimable'] > 0
                && $line['amount'] > self::TAX_INVOICE_THRESHOLD
                && ! $line['has_receipt'],
        ));

        if ($withoutReceipt) {
            $checks[] = $this->check('receipt_missing', 'warning', $withoutReceipt, 'claimable');
        }

        if ($basis === self::BASIS_CASH && $sales['unallocated']) {
            $checks[] = $this->check('unallocated_payments', 'warning', $sales['unallocated'], 'amount');
        }

        $untaxed = array_values(array_filter($purchases['expenses'], fn (array $line): bool => $line['tax'] === 0));

        if ($untaxed) {
            $checks[] = $this->check('expenses_without_tax', 'info', $untaxed, 'amount');
        }

        if ($purchases['refunds']) {
            $checks[] = $this->check('supplier_credits', 'info', $purchases['refunds'], 'claimable');
        }

        return $checks;
    }

    private function check(string $key, string $severity, array $items, string $column): array
    {
        return [
            'key' => $key,
            'severity' => $severity,
            'count' => count($items),
            'amount' => array_sum(array_column($items, $column)),
            'items' => $items,
        ];
    }

    private function currency(int $companyId): ?Currency
    {
        $id = CompanySetting::getSetting('currency', $companyId);

        return $id ? Currency::query()->find($id) : null;
    }

    private function dayAfter(string $date): string
    {
        return CarbonImmutable::parse($date)->addDay()->toDateString();
    }
}
