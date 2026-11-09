<?php

namespace App\Domains\Reporting\Http\Controllers\Company;

use App\Domains\Accounts\Models\Company;
use App\Domains\Reporting\Http\Requests\BasReportRequest;
use App\Domains\Reporting\Queries\BasQuery;
use App\Platform\Http\Controller;
use Illuminate\Http\JsonResponse;

class BasReportController extends Controller
{
    /**
     * The BAS worksheet for the current company as JSON, for the report page.
     */
    public function __invoke(BasReportRequest $request, BasQuery $query): JsonResponse
    {
        $company = Company::findOrFail($request->header('company'));

        $this->authorize('view report', $company);

        $report = $query->report(
            $company->id,
            $request->validated('from_date'),
            $request->validated('to_date'),
            $request->overrides(),
        );

        return response()->json(['data' => $report]);
    }
}
