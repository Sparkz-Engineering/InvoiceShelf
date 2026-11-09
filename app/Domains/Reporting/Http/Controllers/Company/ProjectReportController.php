<?php

namespace App\Domains\Reporting\Http\Controllers\Company;

use App\Domains\Accounts\Models\Company;
use App\Domains\Reporting\Http\Requests\ProjectReportRequest;
use App\Domains\Reporting\Queries\ProjectReportQuery;
use App\Platform\Http\Controller;
use Illuminate\Http\JsonResponse;

class ProjectReportController extends Controller
{
    /**
     * Income and costs per project for the current company as JSON.
     */
    public function __invoke(ProjectReportRequest $request, ProjectReportQuery $query): JsonResponse
    {
        $company = Company::findOrFail($request->header('company'));

        $this->authorize('view report', $company);

        return response()->json(['data' => $query->report(
            $company->id,
            $request->validated('from_date'),
            $request->validated('to_date'),
            $request->integer('invoice_field_id') ?: null,
            $request->integer('expense_field_id') ?: null,
        )]);
    }
}
