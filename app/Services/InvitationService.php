<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\BusinessConflictException;
use App\Exceptions\InvalidStateException;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Models\UserFacilityAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Invitation lifecycle: issue a hashed, single-use, expiring token; redeem it
 * into an account; carry the invitation's facility grants across.
 */
class InvitationService
{
    /** How long an invitation stays redeemable. */
    public const TTL_DAYS = 7;

    /**
     * Issue an invitation for a tenant.
     *
     * @param  list<int>  $facilityIds
     * @return array{invitation: TenantInvitation, token: string}
     */
    public function invite(
        Tenant $tenant,
        string $email,
        UserRole $role,
        User $invitedBy,
        array $facilityIds = [],
    ): array {
        $token = Str::random(48);

        return DB::transaction(function () use ($tenant, $email, $role, $invitedBy, $facilityIds, $token): array {
            // Re-issuing for the same address and role replaces the old token,
            // so only the newest invitation can be redeemed.
            TenantInvitation::query()
                ->where('tenant_id', $tenant->id)
                ->where('email', $email)
                ->where('role', $role->value)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            /** @var TenantInvitation $invitation */
            $invitation = TenantInvitation::query()->create([
                'tenant_id' => $tenant->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'invited_by_user_id' => $invitedBy->id,
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ]);

            // The token itself carries no facility list, so the grants are
            // recorded now against the pre-created (not yet existing) user by
            // email; they are materialised on acceptance.
            $invitation->forceFill([
                'facility_ids' => array_values(array_map('intval', $facilityIds)),
            ])->save();

            return [
                'invitation' => $invitation,
                'token' => $token,
            ];
        });
    }

    /**
     * Redeem an invitation token into a new tenant account.
     */
    public function accept(string $token, string $name, string $password): User
    {
        $invitation = TenantInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($invitation === null) {
            throw new InvalidStateException('The invitation token is not recognised.');
        }

        if (! $invitation->isPending()) {
            throw new InvalidStateException('The invitation has expired or was already used.');
        }

        if (User::query()->where('email', $invitation->email)->exists()) {
            throw new BusinessConflictException('An account with that email already exists.');
        }

        return DB::transaction(function () use ($invitation, $name, $password): User {
            $user = new User([
                'name' => $name,
                'email' => $invitation->email,
                'password' => Hash::make($password),
                'role' => $invitation->role,
            ]);
            $user->tenant_id = $invitation->tenant_id;
            $user->save();

            $facilityIds = (array) ($invitation->facility_ids ?? []);

            foreach ($facilityIds as $facilityId) {
                UserFacilityAssignment::query()->firstOrCreate([
                    'tenant_id' => $invitation->tenant_id,
                    'user_id' => $user->id,
                    'facility_id' => (int) $facilityId,
                ], [
                    'assigned_by_user_id' => $invitation->invited_by_user_id,
                ]);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });
    }
}
