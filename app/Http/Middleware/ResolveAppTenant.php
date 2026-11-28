<?php

namespace App\Http\Middleware;

use App\Models\Facility;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the tenant for an authenticated App request from the account itself.
 *
 * The App origin is not tenant-specific, so the tenant is always the one linked
 * to the authenticated TenantAdmin. The tenant admin is organization-wide, so a
 * facility is bound only when `X-Facility-Id` names one (validated later by the
 * facility middleware); it is never guessed.
 */
class ResolveAppTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_if($user === null, 401, 'Unauthenticated.');

        abort_unless($user->isTenantAdmin(), 403, 'Forbidden.');

        $tenant = $user->tenant;

        abort_if($tenant === null, 403, 'This account has no tenant.');

        abort_unless($tenant->status->isOperational(), 403, "Tenant '{$tenant->slug}' is not active.");

        $request->attributes->set('tenant', $tenant);

        // A facility is only bound implicitly when the tenant has exactly one
        // active facility; otherwise the caller must choose one.
        $facilities = $tenant->facilities()->where('is_active', true)->limit(2)->get();

        if ($facilities->count() === 1) {
            $request->attributes->set('facility', $facilities->first());
        }

        return $next($request);
    }
}
