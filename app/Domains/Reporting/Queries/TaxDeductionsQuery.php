<?php

namespace App\Domains\Reporting\Queries;

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Money\Models\Currency;
use App\Domains\Purchases\Application\ExpenseTaxPosition;
use App\Domains\Purchases\Models\Expense;
use App\Domains\Taxation\Models\TaxDeduction;
use Carbon\CarbonImmutable;

/**
 * A financial year's tax deductions, for the tax return: the deductible
 * expenses, grouped by category, and the deductions recorded by hand.
 *
 * An expense deducts the business share of its cost without GST, since the
 * GST is claimed back through the BAS (ExpenseTaxPosition). Expenses marked
 * as not deductible are counted separately, so nothing goes missing without
 * a trace.
 *
 * Every figure is whole minor units of the company's currency.
 */
class TaxDeductionsQuery
{
    public function report(int $companyId, string $from, string $to): array
    {
        $expenses = Expense::query()
            ->where('company_id', $companyId)
            ->where('expense_date', '>=', $from)
            ->where('expense_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString())
            ->with(['taxes', 'category:id,name', 'supplier:id,name'])
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get();

        $categories = [];
        $excluded = ['count' => 0, 'amount' => 0];

        foreach ($expenses as $expense) {
            $position = ExpenseTaxPosition::inBaseCurrency($expense);

            if (! $expense->tax_deductible) {
                $excluded['count']++;
                $excluded['amount'] += $position->net();

                continue;
            }

            $key = (int) $expense->expense_category_id;
            $categories[$key] ??= [
                'expense_category_id' => $key,
                'name' => $expense->category?->name,
                'total' => 0,
                'lines' => [],
            ];

            $categories[$key]['total'] += $position->deductibleAmount();
            $categories[$key]['lines'][] = [
                'expense_id' => (int) $expense->id,
                'date' => substr((string) $expense->expense_date, 0, 10),
                'number' => $expense->expense_number,
                'supplier' => $expense->supplier?->name,
                'notes' => $expense->notes,
                'gross' => $position->gross,
                'tax' => $position->tax,
                'net' => $position->net(),
                'business_use_percent' => (float) $expense->business_use_percent,
                'deductible' => $position->deductibleAmount(),
                'deduction_note' => $expense->deduction_note,
            ];
        }

        $categories = array_values($categories);
        usort($categories, fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        $other = TaxDeduction::query()
            ->forCompany($companyId)
            ->datedBetween($from, $to)
            ->orderBy('deduction_date')
            ->orderBy('id')
            ->get();

        $otherGroups = $other
            ->groupBy('category')
            ->map(fn ($rows, string $category): array => [
                'category' => $category,
                'total' => (int) $rows->sum('amount'),
                'lines' => $rows->map(fn (TaxDeduction $deduction): array => [
                    'id' => (int) $deduction->id,
                    'date' => $deduction->deduction_date?->toDateString(),
                    'description' => $deduction->description,
                    'amount' => (int) $deduction->amount,
                    'notes' => $deduction->notes,
                ])->values()->all(),
            ])
            ->sortBy(fn (array $group): int => array_search($group['category'], TaxDeduction::CATEGORIES, true))
            ->values()
            ->all();

        $expensesTotal = array_sum(array_column($categories, 'total'));
        $otherTotal = (int) $other->sum('amount');

        return [
            'from_date' => $from,
            'to_date' => $to,
            'currency' => $this->currency($companyId),
            'totals' => [
                'expenses' => $expensesTotal,
                'other' => $otherTotal,
                'total' => $expensesTotal + $otherTotal,
            ],
            'expenses' => $categories,
            'other' => $otherGroups,
            'not_deductible' => $excluded,
        ];
    }

    private function currency(int $companyId): ?Currency
    {
        $id = CompanySetting::getSetting('currency', $companyId);

        return $id ? Currency::query()->find($id) : null;
    }
}
