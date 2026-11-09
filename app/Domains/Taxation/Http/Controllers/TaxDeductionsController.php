<?php

namespace App\Domains\Taxation\Http\Controllers;

use App\Domains\Taxation\Application\TaxDeductionService;
use App\Domains\Taxation\Http\Requests\PeriodFilterRequest;
use App\Domains\Taxation\Http\Requests\TaxDeductionRequest;
use App\Domains\Taxation\Http\Resources\TaxDeductionResource;
use App\Domains\Taxation\Models\TaxDeduction;
use App\Platform\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaxDeductionsController extends Controller
{
    public function __construct(private readonly TaxDeductionService $deductions) {}

    /**
     * The company's hand-recorded deductions, oldest first, optionally only
     * those dated in a range (a financial year, say).
     */
    public function index(PeriodFilterRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TaxDeduction::class);

        $period = $request->period();

        $deductions = TaxDeduction::query()
            ->forCompany((int) $request->header('company'))
            ->when($period, fn ($query) => $query->datedBetween(...$period))
            ->with('creator')
            ->orderBy('deduction_date')
            ->orderBy('id')
            ->get();

        return TaxDeductionResource::collection($deductions);
    }

    public function store(TaxDeductionRequest $request): TaxDeductionResource
    {
        $this->authorize('create', TaxDeduction::class);

        $deduction = $this->deductions->create(
            (int) $request->header('company'),
            $request->user()?->id,
            $request->validated(),
        );

        return new TaxDeductionResource($deduction->load('creator'));
    }

    public function show(TaxDeduction $taxDeduction): TaxDeductionResource
    {
        $this->authorize('view', $taxDeduction);

        return new TaxDeductionResource($taxDeduction->load('creator'));
    }

    public function update(TaxDeductionRequest $request, TaxDeduction $taxDeduction): TaxDeductionResource
    {
        $this->authorize('update', $taxDeduction);

        return new TaxDeductionResource(
            $this->deductions->update($taxDeduction, $request->validated())->load('creator'),
        );
    }

    public function destroy(TaxDeduction $taxDeduction): JsonResponse
    {
        $this->authorize('delete', $taxDeduction);

        $this->deductions->delete($taxDeduction);

        return response()->json(['success' => true]);
    }
}
