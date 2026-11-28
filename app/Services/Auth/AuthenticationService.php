<?php

namespace App\Services\Auth;

use App\Enums\ApplicationContext;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\User;

/**
 * Verifies credentials and decides whether an account may use a context.
 *
 * This service owns the credential half of authentication only; token issuance
 * lives in {@see ContextTokenIssuer}. Login failures are deliberately
 * indistinguishable (unknown email, wrong password, inactive account, wrong
 * context) so the endpoint cannot be used to enumerate accounts.
 */
class AuthenticationService
{
    /**
     * Resolve and verify the account for a login attempt.
     *
     * Returns null for every failure mode so the caller can emit one uniform
     * response.
     */
    public function attempt(string $email, string $password, ApplicationContext $context): ?User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return null;
        }

        if (! password_verify($password, $user->password)) {
            return null;
        }

        if (! $this->accountCanAuthenticate($user, $context)) {
            return null;
        }

        return $user;
    }

    /**
     * Whether the account may authenticate into the given application context.
     */
    public function accountCanAuthenticate(User $user, ApplicationContext $context): bool
    {
        $role = $user->role;

        if ($role === null || ! $role->allowedIn($context)) {
            return false;
        }

        if ($context === ApplicationContext::Portal) {
            return $role === UserRole::PlatformAdmin && $user->tenant_id === null;
        }

        if ($user->tenant_id === null) {
            return false;
        }

        return $user->tenant?->status === TenantStatus::Active;
    }
}
