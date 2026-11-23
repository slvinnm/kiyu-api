<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establish the tenant (and its single active facility) for an authenticated
 * clinic user, storing them on the request for controllers and services.
 *
 * The authenticated user record is authoritative: nothing in the request body,
 * query string or headers can choose the tenant. A platform role has no tenant
 * and is rejected here.
 */
class ResolveTenant
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        abort_unless($user->isTenantUser(), 403, 'Forbidden.');

        $tenant = $user->tenant;

        abort_if($tenant === null, 403, 'This account has no tenant.');

        abort_unless(
            $tenant->status->isOperational(),
            403,
            "Tenant '{$tenant->slug}' is not active.",
        );

        // Bind the tenant's single active facility. A multi-facility tenant
        // would need an explicit facility locator instead.
        $active = $tenant->facilities()->where('is_active', true)->limit(2)->get();

        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('facility', $active->count() === 1 ? $active->first() : null);

        return $next($request);
    }
}
