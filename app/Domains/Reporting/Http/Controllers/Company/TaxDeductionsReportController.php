<?php

namespace App\Domains\Reporting\Http\Controllers\Company;

use App\Domains\Reporting\Http\Requests\ReportPeriodRequest;
use App\Domains\Reporting\Queries\TaxDeductionsQuery;
use App\Domains\Taxation\Models\TaxDeduction;
use App\Platform\Http\Controller;
use Illuminate\Http\JsonResponse;

class TaxDeductionsReportController extends Controller
{
    /**
     * A period's deductions for the current company as JSON, for the
     * deductions page.
     */
    public function __invoke(ReportPeriodRequest $request, TaxDeductionsQuery $query): JsonResponse
    {
        $this->authorize('viewAny', TaxDeduction::class);

        return response()->json(['data' => $query->report(
            (int) $request->header('company'),
            $request->validated('from_date'),
            $request->validated('to_date'),
        )]);
    }
}
