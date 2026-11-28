<?php

namespace App\Services\Auth;

use App\Enums\ApplicationContext;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Mints Sanctum personal access tokens bound to exactly one application.
 *
 * The context is recorded as a single `ctx:*` ability. A wildcard `*` token is
 * never issued: it would satisfy every context check and let a token from one
 * frontend be replayed against another.
 */
class ContextTokenIssuer
{
    /**
     * Issue a scoped token for the given context.
     */
    public function issue(User $user, ApplicationContext $context): string
    {
        $ability = $context->tokenAbility();

        if ($ability === null) {
            throw new \LogicException("The '{$context->value}' context cannot issue a token.");
        }

        $ttlMinutes = (int) config('auth.token_ttl_minutes', 480);

        return $user->createToken(
            name: 'web',
            abilities: [$ability],
            expiresAt: now()->addMinutes($ttlMinutes),
        )->plainTextToken;
    }

    /**
     * The application context a token was issued for, or null when it is not a
     * single-context token (legacy wildcard, ability-less, or transient).
     */
    public function contextForToken(?PersonalAccessToken $token): ?ApplicationContext
    {
        if ($token === null) {
            return null;
        }

        foreach (ApplicationContext::cases() as $context) {
            $ability = $context->tokenAbility();

            if ($ability !== null && $token->can($ability) && $this->isSingleContext($token)) {
                return $context;
            }
        }

        return null;
    }

    /**
     * Whether the token carries exactly one `ctx:*` ability and no wildcard.
     */
    private function isSingleContext(PersonalAccessToken $token): bool
    {
        $abilities = (array) $token->abilities;

        if ($abilities === ['*']) {
            return false;
        }

        $contextAbilities = array_filter(
            $abilities,
            fn($ability): bool => is_string($ability) && str_starts_with($ability, 'ctx:'),
        );

        return count($contextAbilities) === 1;
    }
}
