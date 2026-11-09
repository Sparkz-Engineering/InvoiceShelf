<?php

namespace App\Domains\Taxation\Application;

use App\Domains\Taxation\Models\TaxDeduction;

/**
 * Recording, changing and removing hand-recorded tax deductions.
 */
class TaxDeductionService
{
    /**
     * @param  array{deduction_date: string, category: string, description: string, amount: int, notes?: string|null}  $attributes
     */
    public function create(int $companyId, ?int $creatorId, array $attributes): TaxDeduction
    {
        return TaxDeduction::create([
            ...$attributes,
            'company_id' => $companyId,
            'creator_id' => $creatorId,
        ]);
    }

    /**
     * @param  array{deduction_date: string, category: string, description: string, amount: int, notes?: string|null}  $attributes
     */
    public function update(TaxDeduction $deduction, array $attributes): TaxDeduction
    {
        $deduction->update($attributes);

        return $deduction->fresh();
    }

    public function delete(TaxDeduction $deduction): void
    {
        $deduction->delete();
    }
}
