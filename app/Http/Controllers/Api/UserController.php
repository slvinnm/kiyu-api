<?php

namespace App\Http\Controllers\Api;

use App\Enums\Feature;
use App\Enums\UserRole;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\User;
use App\Models\UserFacilityAssignment;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * List staff users.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $this->validateTableQuery($request, [
            'role' => ['nullable', 'string', Rule::in(UserRole::tenantValues())],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
        ]);

        $query = User::query()
            ->where('tenant_id', $this->tenantId())
            ->with(['station', 'facilities:id,tenant_id,name,code']);

        // A facility admin only sees users who can act at their facilities.
        $scope = $this->facilityScope($request);
        if ($scope !== null) {
            $query->whereHas('facilityAssignments', fn($q) => $q->whereIn('facility_id', $scope));
        }

        if (! empty($validated['facility_id'])) {
            abort_unless($this->canAdministerFacility($request, (int) $validated['facility_id']), 403, 'Forbidden.');
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

        $this->applyTableSort($query, $validated, ['name', 'email', 'role', 'created_at', 'id']);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    /**
     * Create a staff user.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in($this->assignableRoles($request))],
            'station_id' => ['nullable', 'integer'],
            'facility_ids' => ['nullable', 'array'],
            'facility_ids.*' => ['integer'],
        ]);

        $this->validateAssignedOrganisation($validated);
        $this->guardFacilityGrants($request, $validated);

        // Only staff roles count against the seat limit; customers do not.
        if ($validated['role'] !== UserRole::Client->value) {
            $this->subscriptions->ensureCapacity($this->tenant(), Feature::MaxStaff);
        }

        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'station_id' => $validated['station_id'] ?? null,
        ]);

        $user->tenant_id = $this->tenantId();
        $user->save();

        $this->syncFacilityAssignments($request, $user, $validated['facility_ids'] ?? []);

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $user->load(['station', 'facilities:id,tenant_id,name,code']),
        ], 201);
    }

    /**
     * Show a staff user.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = User::query()
            ->where('tenant_id', $this->tenantId())
            ->with(['station', 'facilities:id,tenant_id,name,code'])
            ->findOrFail($id);

        return response()->json([
            'data' => $user,
        ]);
    }

    /**
     * Update a staff user.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

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
            'role' => ['sometimes', 'required', 'string', Rule::in($this->assignableRoles($request))],
            'station_id' => ['nullable', 'integer'],
            'facility_ids' => ['nullable', 'array'],
            'facility_ids.*' => ['integer'],
        ]);

        $this->validateAssignedOrganisation($validated, $user);
        $this->guardFacilityGrants($request, $validated);

        if (array_key_exists('password', $validated) && $validated['password'] === null) {
            unset($validated['password']);
        }

        $user->update($validated);

        if (array_key_exists('facility_ids', $validated)) {
            $this->syncFacilityAssignments($request, $user, $validated['facility_ids'] ?? []);
        }

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $user->fresh(['station', 'facilities:id,tenant_id,name,code']),
        ]);
    }

    /**
     * Delete a staff user.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = User::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        if ($request->user()->id === $user->id) {
            throw new InvalidStateException('You cannot delete your own account.');
        }

        if ($user->role === UserRole::TenantAdmin
            && User::query()
                ->where('tenant_id', $this->tenantId())
                ->where('role', UserRole::TenantAdmin->value)
                ->count() <= 1) {
            throw new InvalidStateException('Cannot delete the last administrator.');
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * Only a tenant admin, a facility admin, or a platform support operator may
     * manage users. A facility admin is additionally constrained by scope.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user !== null && ($user->isTenantAdmin() || $user->role === UserRole::FacilityAdmin || $user->isPlatformUser()),
            403,
            'Forbidden.',
        );
    }

    /**
     * The roles the caller may assign. A facility admin cannot grant tenant
     * admin, facility admin or the platform role.
     *
     * @return list<string>
     */
    private function assignableRoles(Request $request): array
    {
        if ($request->user()?->isTenantAdmin()) {
            return UserRole::tenantValues();
        }

        return [
            UserRole::Operator->value,
            UserRole::Staff->value,
            UserRole::Client->value,
        ];
    }

    /**
     * The facilities the caller may administer, or null for tenant-wide access.
     *
     * @return list<int>|null
     */
    private function facilityScope(Request $request): ?array
    {
        $actor = $request->user();

        if ($actor === null || $actor->hasTenantWideAccess() || $actor->isPlatformUser()) {
            return null;
        }

        return $actor->facilityAssignments()->pluck('facility_id')->all();
    }

    /**
     * Whether the caller may administer the given facility.
     */
    private function canAdministerFacility(Request $request, int $facilityId): bool
    {
        return $request->user()?->canAccessFacility($facilityId) ?? false;
    }

    /**
     * Validate every requested facility grant is within the caller's authority.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardFacilityGrants(Request $request, array $data): void
    {
        foreach ((array) ($data['facility_ids'] ?? []) as $facilityId) {
            abort_unless(
                $this->canAdministerFacility($request, (int) $facilityId),
                403,
                'You cannot grant access to a facility you do not administer.',
            );
        }
    }

    /**
     * Add the supplied facility assignments to a user.
     *
     * @param  list<int>  $facilityIds
     */
    private function syncFacilityAssignments(Request $request, User $user, array $facilityIds): void
    {
        abort_if(
            $user->hasTenantWideAccess(),
            422,
            'A tenant admin already has implicit access to every facility.',
        );

        $tenantId = $this->tenantId();

        foreach ($facilityIds as $facilityId) {
            UserFacilityAssignment::query()->firstOrCreate([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'facility_id' => (int) $facilityId,
            ], [
                'assigned_by_user_id' => $request->user()?->id,
            ]);
        }
    }

    /**
     * Ensure the assigned station belongs to the current tenant.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateAssignedOrganisation(array $data, ?User $user = null): void
    {
        $tenantId = $this->tenantId();
        $stationId = $data['station_id'] ?? null;

        if ($stationId === null) {
            return;
        }

        $station = Station::query()
            ->whereKey($stationId)
            ->whereHas('department.facility', fn($query) => $query->where('tenant_id', $tenantId))
            ->first();

        if (! $station) {
            throw ValidationException::withMessages([
                'station_id' => ['Station does not belong to your tenant.'],
            ]);
        }
    }
}
