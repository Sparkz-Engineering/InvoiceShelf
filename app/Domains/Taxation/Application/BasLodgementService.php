<?php

namespace App\Domains\Taxation\Application;

use App\Domains\Taxation\Models\BasLodgement;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Recording that a BAS was lodged, with the figures as they stood.
 */
class BasLodgementService
{
    /**
     * Record a lodgement for a period. A period is lodged once; to lodge it
     * again (an amended statement), the earlier record is removed first.
     *
     * @param  array<string, mixed>  $report  the worksheet, as BasQuery builds it
     * @param  array{lodged_on: string, reference?: string|null, notes?: string|null}  $details
     */
    public function record(int $companyId, ?int $creatorId, array $report, array $details): BasLodgement
    {
        $exists = BasLodgement::query()
            ->forCompany($companyId)
            ->whereDate('period_start', $report['from_date'])
            ->whereDate('period_end', $report['to_date'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'from_date' => 'This period is already marked as lodged. Remove that record first to record an amended statement.',
            ]);
        }

        return BasLodgement::create([
            'company_id' => $companyId,
            'creator_id' => $creatorId,
            'period_start' => $report['from_date'],
            'period_end' => $report['to_date'],
            'basis' => $report['basis'],
            'figures' => self::snapshot($report),
            'lodged_on' => $details['lodged_on'],
            'reference' => $details['reference'] ?? null,
            'notes' => $details['notes'] ?? null,
        ]);
    }

    public function remove(BasLodgement $lodgement): void
    {
        $lodgement->delete();
    }

    /**
     * The parts of a worksheet worth keeping: the figures on the form.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function snapshot(array $report): array
    {
        return [
            'basis' => $report['basis'],
            ...Arr::only($report, ['labels', 'payg', 'totals']),
        ];
    }
}
