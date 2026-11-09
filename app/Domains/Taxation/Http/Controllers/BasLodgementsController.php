<?php

namespace App\Domains\Taxation\Http\Controllers;

use App\Domains\Reporting\Queries\BasQuery;
use App\Domains\Taxation\Application\BasLodgementService;
use App\Domains\Taxation\Http\Requests\BasLodgementRequest;
use App\Domains\Taxation\Http\Requests\PeriodFilterRequest;
use App\Domains\Taxation\Http\Resources\BasLodgementResource;
use App\Domains\Taxation\Models\BasLodgement;
use App\Platform\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BasLodgementsController extends Controller
{
    public function __construct(private readonly BasLodgementService $lodgements) {}

    /**
     * The company's lodgements, newest period first, optionally only those
     * overlapping a range (a financial year, say).
     */
    public function index(PeriodFilterRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BasLodgement::class);

        $period = $request->period();

        $lodgements = BasLodgement::query()
            ->forCompany((int) $request->header('company'))
            ->when($period, fn ($query) => $query->overlapping(...$period))
            ->with('creator')
            ->orderByDesc('period_start')
            ->get();

        return BasLodgementResource::collection($lodgements);
    }

    /**
     * Mark a period as lodged, keeping the worksheet's figures as they are now.
     */
    public function store(BasLodgementRequest $request, BasQuery $query): BasLodgementResource
    {
        $this->authorize('create', BasLodgement::class);

        $companyId = (int) $request->header('company');

        $report = $query->report(
            $companyId,
            $request->validated('from_date'),
            $request->validated('to_date'),
            $request->overrides(),
        );

        $lodgement = $this->lodgements->record($companyId, $request->user()?->id, $report, $request->validated());

        return new BasLodgementResource($lodgement->load('creator'));
    }

    /**
     * Forget a lodgement, to record an amended statement in its place.
     */
    public function destroy(BasLodgement $basLodgement): JsonResponse
    {
        $this->authorize('delete', $basLodgement);

        $this->lodgements->remove($basLodgement);

        return response()->json(['success' => true]);
    }
}
