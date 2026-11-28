<?php

namespace App\Http\Controllers\Api\App\Users;

use App\Enums\UserRole;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Staff\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tenant-wide user administration for the TenantAdmin App.
 */
class UserController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        private readonly StaffService $staff,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'role' => ['nullable', 'string', Rule::in(UserRole::tenantValues())],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $query = User::query()
            ->where('tenant_id', $this->tenantId())
            ->with(['station', 'facilities:id,tenant_id,name,code']);

        if (! empty($validated['facility_id'])) {
            $query->whereHas('facilityAssignments', fn($q) => $q->where('facility_id', (int) $validated['facility_id']));
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        foreach (['role', 'station_id'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['department_id'])) {
            $query->whereHas('station', fn($q) => $q->where('department_id', (int) $validated['department_id']));
        }

        $this->applyTableSort($query, $validated, ['name', 'email', 'role', 'created_at', 'id']);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in($this->staff->assignableRoles($request->user()))],
            'station_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'facility_ids' => ['nullable', 'array'],
            'facility_ids.*' => ['integer'],
        ]);

        $user = $this->staff->create($this->tenant(), $request->user(), $validated);

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $user,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $user = User::query()
            ->where('tenant_id', $this->tenantId())
            ->with(['station', 'facilities:id,tenant_id,name,code'])
            ->findOrFail($id);

        return response()->json([
            'data' => $user,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'required', 'string', Rule::in($this->staff->assignableRoles($request->user()))],
            'station_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'facility_ids' => ['nullable', 'array'],
            'facility_ids.*' => ['integer'],
        ]);

        $user = $this->staff->update($this->tenant(), $request->user(), $user, $validated);

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $user,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        $this->staff->delete($this->tenant(), $request->user(), $user);

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }
}
