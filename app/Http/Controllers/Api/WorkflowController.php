<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class WorkflowController extends Controller
{
    use HandlesTableQuery;

    /**
     * List workflows.
     */
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

    /**
     * Aggregate counts for dashboard widgets.
     */
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

    /**
     * Create a workflow.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        Department::query()
            ->where('facility_id', $this->facilityId())
            ->findOrFail($validated['department_id']);

        $workflow = Workflow::query()->create([
            'department_id' => $validated['department_id'],
            'name' => $validated['name'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Workflow created successfully.',
            'data' => $workflow->load('department'),
        ], 201);
    }

    /**
     * Show a workflow with its steps.
     */
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

    /**
     * Add a step to a workflow.
     */
    public function addStep(Request $request, int $id): JsonResponse
    {
        $workflow = Workflow::query()
            ->whereHas('department', fn($q) => $q->where('facility_id', $this->facilityId()))
            ->findOrFail($id);

        $validated = $request->validate([
            'station_id' => ['required', 'integer', $this->stationInFacilityRule()],
            'name' => ['required', 'string', 'max:255'],
            'sequence' => ['required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'is_optional' => ['nullable', 'boolean'],
            'can_skip' => ['nullable', 'boolean'],
        ]);

        $step = $workflow->steps()->create([
            'station_id' => $validated['station_id'],
            'name' => $validated['name'],
            'sequence' => $validated['sequence'],
            'is_active' => $validated['is_active'] ?? true,
            'is_optional' => $validated['is_optional'] ?? false,
            'can_skip' => $validated['can_skip'] ?? false,
        ]);

        return response()->json([
            'message' => 'Workflow step added successfully.',
            'data' => $step->load('station'),
        ], 201);
    }

    /**
     * Update a workflow step.
     */
    public function updateStep(Request $request, int $id): JsonResponse
    {
        $step = WorkflowStep::query()
            ->whereHas('workflow.department', fn($q) => $q->where('facility_id', $this->facilityId()))
            ->findOrFail($id);

        $validated = $request->validate([
            'station_id' => ['sometimes', 'required', 'integer', $this->stationInFacilityRule()],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sequence' => ['sometimes', 'required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'is_optional' => ['nullable', 'boolean'],
            'can_skip' => ['nullable', 'boolean'],
        ]);

        $step->update($validated);

        return response()->json([
            'message' => 'Workflow step updated successfully.',
            'data' => $step->load('station'),
        ]);
    }

    /**
     * Delete a workflow step.
     */
    public function deleteStep(int $id): JsonResponse
    {
        $step = WorkflowStep::query()
            ->whereHas('workflow.department', fn($q) => $q->where('facility_id', $this->facilityId()))
            ->findOrFail($id);
        $step->delete();

        return response()->json([
            'message' => 'Workflow step deleted successfully.',
        ]);
    }

    /**
     * A rule that only accepts a station inside the current facility.
     *
     * `exists:stations,id` alone would accept another tenant's station and
     * let a step reference it, so the tenant's facility is part of the rule.
     */
    private function stationInFacilityRule(): Exists
    {
        return Rule::exists('stations', 'id')->where(
            fn($query) => $query->whereIn(
                'department_id',
                Department::query()
                    ->where('facility_id', $this->facilityId())
                    ->select('id'),
            ),
        );
    }
}
