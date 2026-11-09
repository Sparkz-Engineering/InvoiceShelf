<?php

namespace App\Domains\Purchases\Application;

use App\Domains\Purchases\Models\Expense;
use App\Support\ProportionalAmount;

/**
 * What an expense means for tax: the gross that left the account, the tax on
 * the receipt, what is left without it, and the parts that can be claimed.
 *
 * The stored figures are the receipt's. The business-use share is applied
 * here and only here, so the expense page, the BAS and the deductions
 * register all arrive at the same claim: the tax credit is the business share
 * of the tax, and the deduction is the business share of the cost without the
 * tax, because a tax credit already recovers that part. Both are rounded down,
 * so nothing is ever over-claimed by a cent.
 *
 * All figures are whole minor units in one currency: the expense's own
 * currency or the company's, depending on which constructor was used.
 */
final class ExpenseTaxPosition
{
    /** A whole share in basis points: 100% */
    public const WHOLE = 10000;

    private function __construct(
        public readonly int $gross,
        public readonly int $tax,
        public readonly int $businessShare,
        public readonly bool $deductible,
    ) {}

    /**
     * Build a position from raw figures.
     *
     * @param  int  $gross  the amount paid, tax included
     * @param  int  $tax  the tax on the receipt
     * @param  float|int|string|null  $businessUsePercent  0 to 100; null means wholly business
     */
    public static function from(int $gross, int $tax, float|int|string|null $businessUsePercent, bool $deductible): self
    {
        return new self($gross, $tax, self::basisPoints($businessUsePercent), $deductible);
    }

    /**
     * The expense in the company's currency, as reports count it.
     *
     * The tax rows are summed from the loaded relation when there is one, so a
     * report that eager loads taxes pays no extra query per expense.
     */
    public static function inBaseCurrency(Expense $expense): self
    {
        return self::from(
            (int) $expense->base_amount,
            self::taxTotal($expense, 'base_amount'),
            $expense->business_use_percent,
            (bool) $expense->tax_deductible,
        );
    }

    /**
     * The expense in the currency it was entered in, as its own page shows it.
     */
    public static function inExpenseCurrency(Expense $expense): self
    {
        return self::from(
            (int) $expense->amount,
            self::taxTotal($expense, 'amount'),
            $expense->business_use_percent,
            (bool) $expense->tax_deductible,
        );
    }

    /**
     * A percentage as whole basis points, kept between none and all.
     */
    public static function basisPoints(float|int|string|null $percent): int
    {
        if ($percent === null || $percent === '') {
            return self::WHOLE;
        }

        return max(0, min(self::WHOLE, (int) round(((float) $percent) * 100)));
    }

    /**
     * The cost without the tax on the receipt. Never below zero, even when
     * separately rounded base figures disagree by a cent.
     */
    public function net(): int
    {
        return max(0, $this->gross - $this->tax);
    }

    /**
     * The tax the business can claim back: its share of the receipt's tax.
     */
    public function claimableTax(): int
    {
        return ProportionalAmount::floor($this->tax, $this->businessShare, self::WHOLE);
    }

    /**
     * The tax that stays a private cost because of the business-use share.
     */
    public function privateTax(): int
    {
        return $this->tax - $this->claimableTax();
    }

    /**
     * The deduction the expense gives: the business share of the cost without
     * tax, or nothing when it is not deductible.
     */
    public function deductibleAmount(): int
    {
        if (! $this->deductible) {
            return 0;
        }

        return ProportionalAmount::floor($this->net(), $this->businessShare, self::WHOLE);
    }

    private static function taxTotal(Expense $expense, string $column): int
    {
        if ($expense->relationLoaded('taxes')) {
            return (int) $expense->taxes->sum($column);
        }

        return (int) $expense->taxes()->sum($column);
    }
}
