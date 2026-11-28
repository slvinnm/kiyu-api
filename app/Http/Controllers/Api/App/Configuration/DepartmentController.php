<?php

namespace App\Http\Controllers\Api\App\Configuration;

use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\Configuration\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Department configuration from the TenantAdmin App.
 *
 * The TenantAdmin names the facility with `facility_id` (validated against the
 * tenant and the caller's access); this controller only ever acts inside that
 * facility.
 */
class DepartmentController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        private readonly DepartmentService $departments,
    ) {}

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

    public function options(): JsonResponse
    {
        return response()->json([
            'data' => $this->departments->optionsForFacility($this->facilityId()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'display_in_kiosk' => ['nullable', 'boolean'],
        ]);

        $department = $this->departments->create($this->facilityId(), $validated);

        return response()->json([
            'message' => 'Department created successfully.',
            'data' => $department,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->departments->find($this->facilityId(), $id),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'display_in_kiosk' => ['nullable', 'boolean'],
        ]);

        $department = $this->departments->update($this->facilityId(), $id, $validated);

        return response()->json([
            'message' => 'Department updated successfully.',
            'data' => $department,
        ]);
    }

    public function stations(int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->departments->find($this->facilityId(), $id)->stations,
        ]);
    }

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
