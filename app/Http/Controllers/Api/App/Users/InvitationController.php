<?php

namespace App\Http\Controllers\Api\App\Users;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Invite users into the current tenant.
 *
 * A tenant admin may invite any tenant role. A facility admin is limited to
 * non-privileged roles and must grant at least one of their own facilities.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly AuditService $audit,
    ) {}

    /**
     * List the tenant's invitations.
     */
    public function index(Request $request): JsonResponse
    {
        $invitations = TenantInvitation::query()
            ->where('tenant_id', $this->tenantId())
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $invitations]);
    }

    /**
     * Create an invitation and return its one-time token.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(UserRole::tenantValues())],
            'facility_ids' => ['nullable', 'array'],
            'facility_ids.*' => ['integer'],
        ]);

        $role = UserRole::from($validated['role']);

        abort_if($role->isPlatform(), 422, 'A platform role cannot be invited.');

        /** @var User $inviter */
        $inviter = $request->user();

        $this->guardRoleEscalation($inviter, $role);

        $facilityIds = $validated['facility_ids'] ?? [];

        if ($role->isFacilityScoped() && $facilityIds === []) {
            throw ValidationException::withMessages([
                'facility_ids' => ['This role needs at least one facility assignment.'],
            ]);
        }

        $this->guardFacilityScope($inviter, $facilityIds);

        if (User::query()->where('email', $validated['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['That email already has an account.'],
            ]);
        }

        $result = $this->invitations->invite(
            tenant: $this->tenant(),
            email: $validated['email'],
            role: $role,
            invitedBy: $inviter,
            facilityIds: $facilityIds,
        );

        $this->audit->log(
            action: 'INVITATION_CREATED',
            entityType: 'TenantInvitation',
            entityId: $result['invitation']->id,
            metadata: ['email' => $validated['email'], 'role' => $role->value],
            userId: $inviter->id,
            tenant: $this->tenant(),
        );

        return response()->json([
            'message' => 'Invitation created. Share the token with the invitee; it is shown only once.',
            'data' => [
                'invitation' => $result['invitation'],
                'token' => $result['token'],
            ],
        ], 201);
    }

    /**
     * Revoke an invitation.
     */
    public function revoke(Request $request, int $id): JsonResponse
    {
        $invitation = TenantInvitation::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        abort_unless($invitation->isPending(), 409, 'This invitation is no longer pending.');

        $inviter = $request->user();
        $this->guardRoleEscalation($inviter, $invitation->role);

        $invitation->forceFill(['revoked_at' => now()])->save();

        return response()->json(['message' => 'Invitation revoked.']);
    }

    /**
     * Redeem an invitation token and create the invited account.
     */
    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $this->invitations->accept(
            token: $validated['token'],
            name: $validated['name'],
            password: $validated['password'],
        );

        return response()->json([
            'message' => 'Invitation accepted.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value,
                'tenant_id' => $user->tenant_id,
            ],
        ], 201);
    }

    /**
     * A facility admin cannot invite a role above their own authority.
     */
    private function guardRoleEscalation(User $inviter, UserRole $role): void
    {
        if ($inviter->isTenantAdmin()) {
            return;
        }

        abort_unless(
            in_array($role, [UserRole::Operator, UserRole::Staff, UserRole::Customer], true),
            403,
            'You may only invite operator, staff or client accounts.',
        );
    }

    /**
     * Every facility granted must be one the inviter can reach.
     *
     * @param  list<int>  $facilityIds
     */
    private function guardFacilityScope(User $inviter, array $facilityIds): void
    {
        foreach ($facilityIds as $facilityId) {
            abort_unless(
                $inviter->canAccessFacility((int) $facilityId),
                403,
                'You cannot grant access to a facility you do not administer.',
            );
        }
    }
}
