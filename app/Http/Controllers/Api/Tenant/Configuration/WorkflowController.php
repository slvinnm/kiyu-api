<?php

namespace App\Http\Controllers\Api\Tenant\Configuration;

use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Services\Configuration\StationService;
use App\Services\Configuration\WorkflowAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workflow configuration inside a FacilityAdmin's assigned facility.
 */
class WorkflowController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        private readonly WorkflowAdminService $workflows,
        private readonly StationService $stations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $query = Workflow::query()
            ->whereHas('department', fn($q) => $q->where('facility_id', $this->facilityId()))
            ->with(['department', 'steps.station']);

        if (! empty($validated['department_id'])) {
            $query->where('department_id', $validated['department_id']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('department', fn($q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        $this->applyTableSort($query, $validated, ['name', 'is_active', 'department_id', 'created_at'], null);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    public function statistics(): JsonResponse
    {
        return response()->json([
            'data' => [
                'total' => Workflow::query()
                    ->whereHas('department', fn($q) => $q->where('facility_id', $this->facilityId()))
                    ->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $workflow = $this->workflows->create($this->facilityId(), $validated);

        return response()->json([
            'message' => 'Workflow created successfully.',
            'data' => $workflow,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $workflow = Workflow::query()
            ->whereHas('department', fn($q) => $q->where('facility_id', $this->facilityId()))
            ->with(['department', 'steps.station'])
            ->findOrFail($id);

        return response()->json([
            'data' => $workflow,
        ]);
    }

    public function addStep(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['required', 'integer', $this->stations->inFacilityRule($this->facilityId())],
            'name' => ['required', 'string', 'max:255'],
            'sequence' => ['required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'is_optional' => ['nullable', 'boolean'],
            'can_skip' => ['nullable', 'boolean'],
        ]);

        $step = $this->workflows->addStep($this->facilityId(), $id, $validated);

        return response()->json([
            'message' => 'Workflow step added successfully.',
            'data' => $step,
        ], 201);
    }

    public function updateStep(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['sometimes', 'required', 'integer', $this->stations->inFacilityRule($this->facilityId())],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sequence' => ['sometimes', 'required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'is_optional' => ['nullable', 'boolean'],
            'can_skip' => ['nullable', 'boolean'],
        ]);

        $step = $this->workflows->updateStep($this->facilityId(), $id, $validated);

        return response()->json([
            'message' => 'Workflow step updated successfully.',
            'data' => $step,
        ]);
    }

    public function deleteStep(int $id): JsonResponse
    {
        $this->workflows->deleteStep($this->facilityId(), $id);

        return response()->json([
            'message' => 'Workflow step deleted successfully.',
        ]);
    }
}
