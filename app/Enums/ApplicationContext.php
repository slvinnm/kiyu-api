<?php

namespace App\Enums;

/**
 * The frontend applications a request can belong to.
 *
 * A route group is bound to exactly one context. A session is issued for
 * exactly one context, recorded as a Sanctum ability (`ctx:*`). Webhook traffic
 * is deliberately not a user context; it authenticates by provider signature.
 */
enum ApplicationContext: string
{
    case Public = 'public';
    case Customer = 'customer';
    case App = 'app';
    case Tenant = 'tenant';
    case Portal = 'portal';

    /**
     * The token ability that proves a session was issued by this context.
     *
     * The public context has none: it is unauthenticated by design.
     */
    public function tokenAbility(): ?string
    {
        return match ($this) {
            self::Public => null,
            self::Customer => 'ctx:customer',
            self::App => 'ctx:app',
            self::Tenant => 'ctx:tenant',
            self::Portal => 'ctx:portal',
        };
    }

    /**
     * Whether the context requires a validated browser Origin.
     *
     * Public endpoints are origin-optional; every protected context is bound to
     * one origin so a token minted for another application cannot be replayed.
     */
    public function requiresOrigin(): bool
    {
        return $this !== self::Public;
    }
}
