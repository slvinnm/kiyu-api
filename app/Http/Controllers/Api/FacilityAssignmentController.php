<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\User;
use App\Models\UserFacilityAssignment;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Manage which facilities a tenant user may act at.
 *
 * A tenant admin may assign any facility in the tenant. A facility admin may
 * only add or remove assignments for facilities they themselves administer.
 */
class FacilityAssignmentController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * List the facilities assigned to a user.
     */
    public function index(Request $request, int $userId): JsonResponse
    {
        $user = $this->tenantUser($userId);

        abort_unless(
            $user->tenant_id === $this->tenantId(),
            404,
            'User not found.',
        );

        $assignments = UserFacilityAssignment::query()
            ->where('tenant_id', $this->tenantId())
            ->where('user_id', $user->id)
            ->with('facility:id,tenant_id,name,code')
            ->get();

        return response()->json(['data' => $assignments]);
    }

    /**
     * Assign one facility to a user.
     */
    public function store(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'facility_id' => ['required', 'integer'],
        ]);

        $user = $this->tenantUser($userId);
        abort_unless($user->tenant_id === $this->tenantId(), 404, 'User not found.');

        $facility = Facility::query()
            ->whereKey($validated['facility_id'])
            ->where('tenant_id', $this->tenantId())
            ->first();

        if ($facility === null) {
            throw ValidationException::withMessages([
                'facility_id' => ['Facility does not belong to your tenant.'],
            ]);
        }

        /** @var User $actor */
        $actor = $request->user();
        abort_unless(
            $actor->canAccessFacility($facility->id),
            403,
            'You cannot grant access to a facility you do not administer.',
        );

        abort_if(
            $user->isTenantAdmin(),
            422,
            'A tenant admin already has implicit access to every facility.',
        );

        $assignment = UserFacilityAssignment::query()->firstOrCreate([
            'tenant_id' => $this->tenantId(),
            'user_id' => $user->id,
            'facility_id' => $facility->id,
        ], [
            'assigned_by_user_id' => $actor->id,
        ]);

        $this->audit->log(
            action: 'FACILITY_ASSIGNED',
            entityType: 'User',
            entityId: $user->id,
            metadata: ['facility_id' => $facility->id],
            userId: $actor->id,
            tenant: $this->tenant(),
        );

        return response()->json([
            'message' => 'Facility assigned.',
            'data' => $assignment->load('facility:id,tenant_id,name,code'),
        ], 201);
    }

    /**
     * Remove a facility assignment.
     */
    public function destroy(Request $request, int $userId, int $facilityId): JsonResponse
    {
        $user = $this->tenantUser($userId);

        $assignment = UserFacilityAssignment::query()
            ->where('tenant_id', $this->tenantId())
            ->where('user_id', $user->id)
            ->where('facility_id', $facilityId)
            ->firstOrFail();

        /** @var User $actor */
        $actor = $request->user();
        abort_unless(
            $actor->canAccessFacility($facilityId),
            403,
            'You cannot revoke access to a facility you do not administer.',
        );

        $assignment->delete();

        $this->audit->log(
            action: 'FACILITY_UNASSIGNED',
            entityType: 'User',
            entityId: $user->id,
            metadata: ['facility_id' => $facilityId],
            userId: $actor->id,
            tenant: $this->tenant(),
        );

        return response()->json(['message' => 'Facility assignment removed.']);
    }

    /**
     * A user of the current tenant, or 404.
     */
    private function tenantUser(int $userId): User
    {
        return User::query()
            ->whereKey($userId)
            ->where('tenant_id', $this->tenantId())
            ->firstOrFail();
    }
}
