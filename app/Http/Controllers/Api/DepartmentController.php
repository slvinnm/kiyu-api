<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    use HandlesTableQuery;

    /**
     * List active departments with stations (for kiosk display).
     */
    public function active(): JsonResponse
    {
        return response()->json([
            'data' => Department::query()
                ->where('facility_id', $this->facilityId())
                ->with('stations')
                ->where('is_active', true)
                ->where('display_in_kiosk', true)
                ->get(),
        ]);
    }

    /**
     * List all departments (paginated table data).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request);

        $query = Department::query()
            ->where('facility_id', $this->facilityId())
            ->with('stations');

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $this->applyTableSort($query, $validated, ['code', 'name', 'is_active', 'created_at'], null);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    /**
     * Lightweight department choices for select inputs.
     *
     * Kept separate from `index` so dropdowns never depend on the paginated
     * table contract (and vice versa).
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => Department::query()
                ->where('facility_id', $this->facilityId())
                ->withCount('stations')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'is_active', 'display_in_kiosk']),
        ]);
    }

    /**
     * Create a department.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'display_in_kiosk' => ['nullable', 'boolean'],
        ]);

        $department = new Department([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'is_active' => $validated['is_active'] ?? true,
            'display_in_kiosk' => $validated['display_in_kiosk'] ?? true,
        ]);

        $department->facility_id = $this->facilityId();
        $department->save();

        return response()->json([
            'message' => 'Department created successfully.',
            'data' => $department,
        ], 201);
    }

    /**
     * Show a department.
     */
    public function show(int $id): JsonResponse
    {
        $department = Department::query()
            ->where('facility_id', $this->facilityId())
            ->with(['stations', 'workflows.steps.station'])
            ->findOrFail($id);

        return response()->json([
            'data' => $department,
        ]);
    }

    /**
     * Update a department.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $department = Department::query()
            ->where('facility_id', $this->facilityId())
            ->findOrFail($id);

        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'display_in_kiosk' => ['nullable', 'boolean'],
        ]);

        $department->update($validated);

        return response()->json([
            'message' => 'Department updated successfully.',
            'data' => $department,
        ]);
    }

    /**
     * List stations for a department.
     */
    public function stations(int $id): JsonResponse
    {
        $department = Department::query()
            ->where('facility_id', $this->facilityId())
            ->findOrFail($id);

        return response()->json([
            'data' => $department->stations,
        ]);
    }

    /**
     * List workflows for a department.
     */
    public function workflows(int $id): JsonResponse
    {
        $department = Department::query()
            ->where('facility_id', $this->facilityId())
            ->findOrFail($id);

        return response()->json([
            'data' => $department->workflows()->with('steps.station')->get(),
        ]);
    }
}
