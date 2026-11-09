<?php

namespace App\Domains\Reporting\Queries;

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Metadata\Models\CustomField;
use App\Domains\Metadata\Models\CustomFieldValue;
use App\Domains\Money\Models\Currency;
use App\Domains\Purchases\Models\Expense;
use App\Domains\Sales\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Income and costs per project over a period, where a project is whatever
 * an invoice and an expense answer in a custom field such as "Project
 * number".
 *
 * Invoices and expenses each have their own custom fields, so a project is
 * matched across the two by the answer itself, trimmed and regardless of
 * case. By default the invoice field and the expense field with the same
 * label are paired; either can be chosen instead.
 *
 * Income is invoices issued and credit notes raised in the period, by
 * document date and without GST. Costs are expenses dated in the period,
 * without GST. Every figure is whole minor units of the company's currency.
 */
class ProjectReportQuery
{
    /** The key of the row for records with no answer. */
    public const NO_PROJECT = '';

    public function report(int $companyId, string $from, string $to, ?int $invoiceFieldId = null, ?int $expenseFieldId = null): array
    {
        $fields = $this->fields($companyId);
        [$invoiceField, $expenseField] = $this->pair($fields, $invoiceFieldId, $expenseFieldId);

        $projects = [];
        $toExclusive = CarbonImmutable::parse($to)->addDay()->toDateString();

        $invoices = Invoice::query()
            ->where('company_id', $companyId)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $invoices) => $invoices
                    ->where('type', Invoice::TYPE_INVOICE)
                    ->where('status', '!=', Invoice::STATUS_DRAFT))
                ->orWhere('type', Invoice::TYPE_CREDIT_NOTE))
            ->where('invoice_date', '>=', $from)
            ->where('invoice_date', '<', $toExclusive)
            ->with('customer:id,name')
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        // A credit note answers for its invoice's project unless it has an answer of its own
        $invoiceAnswers = $this->answers($invoiceField, (new Invoice)->getMorphClass(), $invoices->pluck('id')
            ->merge($invoices->pluck('related_invoice_id')->filter())->unique());

        foreach ($invoices as $invoice) {
            $key = $this->open($projects, $invoiceAnswers[$invoice->id] ?? $invoiceAnswers[$invoice->related_invoice_id] ?? null);
            $amount = (int) $invoice->base_total - (int) $invoice->base_tax;
            $projects[$key]['income'] += $amount;
            $projects[$key]['invoices'][] = [
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'date' => substr((string) $invoice->getRawOriginal('invoice_date'), 0, 10),
                'type' => $invoice->type,
                'customer' => $invoice->customer?->name,
                'amount' => $amount,
            ];
        }

        $expenses = Expense::query()
            ->where('company_id', $companyId)
            ->where('expense_date', '>=', $from)
            ->where('expense_date', '<', $toExclusive)
            ->with(['taxes', 'category:id,name'])
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get();

        $expenseAnswers = $this->answers($expenseField, (new Expense)->getMorphClass(), $expenses->pluck('id'));

        foreach ($expenses as $expense) {
            $key = $this->open($projects, $expenseAnswers[$expense->id] ?? null);
            $amount = (int) $expense->base_amount - (int) $expense->taxes->sum('base_amount');
            $projects[$key]['costs'] += $amount;
            $projects[$key]['expenses'][] = [
                'expense_id' => (int) $expense->id,
                'number' => $expense->expense_number,
                'date' => substr((string) $expense->expense_date, 0, 10),
                'category' => $expense->category?->name,
                'notes' => $expense->notes,
                'amount' => $amount,
            ];
        }

        $rows = collect($projects)
            ->map(fn (array $row): array => [...$row, 'profit' => $row['income'] - $row['costs']])
            ->sortBy(fn (array $row): string => $row['key'] === self::NO_PROJECT ? "\u{10FFFF}" : $row['key'], SORT_NATURAL)
            ->values()
            ->all();

        return [
            'from_date' => $from,
            'to_date' => $to,
            'currency' => $this->currency($companyId),
            'fields' => [
                'invoice' => $fields['invoice']->map(fn (CustomField $field) => ['id' => (int) $field->id, 'label' => $field->label])->values()->all(),
                'expense' => $fields['expense']->map(fn (CustomField $field) => ['id' => (int) $field->id, 'label' => $field->label])->values()->all(),
            ],
            'invoice_field_id' => $invoiceField?->id,
            'expense_field_id' => $expenseField?->id,
            'projects' => $rows,
            'totals' => [
                'income' => array_sum(array_column($rows, 'income')),
                'costs' => array_sum(array_column($rows, 'costs')),
                'profit' => array_sum(array_column($rows, 'profit')),
            ],
        ];
    }

    /**
     * The company's custom fields on invoices and on expenses.
     *
     * @return array{invoice: Collection<int, CustomField>, expense: Collection<int, CustomField>}
     */
    private function fields(int $companyId): array
    {
        $fields = CustomField::query()
            ->where('company_id', $companyId)
            ->whereIn('model_type', ['Invoice', 'Expense'])
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return [
            'invoice' => $fields->where('model_type', 'Invoice')->values(),
            'expense' => $fields->where('model_type', 'Expense')->values(),
        ];
    }

    /**
     * The invoice and expense fields to match on: the ones asked for, else
     * the first two sharing a label.
     *
     * @return array{0: CustomField|null, 1: CustomField|null}
     */
    private function pair(array $fields, ?int $invoiceFieldId, ?int $expenseFieldId): array
    {
        $invoiceField = $invoiceFieldId ? $fields['invoice']->firstWhere('id', $invoiceFieldId) : null;
        $expenseField = $expenseFieldId ? $fields['expense']->firstWhere('id', $expenseFieldId) : null;

        if ($invoiceField && $expenseField) {
            return [$invoiceField, $expenseField];
        }

        foreach ($fields['invoice'] as $candidate) {
            if ($invoiceField && $candidate->id !== $invoiceField->id) {
                continue;
            }

            $match = $expenseField ?? $fields['expense']->first(
                fn (CustomField $field): bool => $this->normalise($field->label) === $this->normalise($candidate->label),
            );

            if ($match) {
                return [$candidate, $match];
            }
        }

        return [$invoiceField, $expenseField];
    }

    /**
     * Each record's answer to the field, keyed by record id.
     *
     * @return array<int, string>
     */
    private function answers(?CustomField $field, string $morphType, Collection $ids): array
    {
        if (! $field || $ids->isEmpty()) {
            return [];
        }

        $answers = [];

        CustomFieldValue::query()
            ->where('custom_field_id', $field->id)
            ->where('custom_field_valuable_type', $morphType)
            ->whereIn('custom_field_valuable_id', $ids)
            ->get()
            ->each(function (CustomFieldValue $value) use (&$answers): void {
                $answer = trim((string) $value->default_answer);

                if ($answer !== '') {
                    $answers[(int) $value->custom_field_valuable_id] = $answer;
                }
            });

        return $answers;
    }

    /**
     * The key of the project row an answer belongs to, making the row on
     * first sight. The first spelling seen is the one shown.
     */
    private function open(array &$projects, ?string $answer): string
    {
        $key = $answer === null ? self::NO_PROJECT : $this->normalise($answer);

        $projects[$key] ??= [
            'key' => $key,
            'project' => $answer,
            'income' => 0,
            'costs' => 0,
            'invoices' => [],
            'expenses' => [],
        ];

        return $key;
    }

    private function normalise(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function currency(int $companyId): ?Currency
    {
        $id = CompanySetting::getSetting('currency', $companyId);

        return $id ? Currency::query()->find($id) : null;
    }
}
