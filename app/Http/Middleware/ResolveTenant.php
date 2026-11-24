<?php

namespace App\Http\Middleware;

use App\Models\Facility;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establish the tenant (and, when unambiguous, the facility) for an
 * authenticated tenant user.
 *
 * The tenant always comes from the authenticated user record. The facility is
 * only bound automatically when the user can reach exactly one facility:
 *   - a tenant admin with a single active facility, or
 *   - a facility-scoped user with exactly one assignment.
 *
 * When several facilities are reachable the facility is left unbound; a route
 * that needs one must accept and authorise an explicit facility selector.
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

        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('facility', $this->resolveFacility($user, $tenant));

        return $next($request);
    }

    /**
     * The single facility the user can act at without choosing one, if any.
     *
     * A facility-scoped role is limited to its assignments. A tenant admin and
     * a client account are tenant-level, so their scope is every active
     * facility of the tenant.
     */
    private function resolveFacility(User $user, Tenant $tenant): ?Facility
    {
        $query = $tenant->facilities()->where('is_active', true);

        if ($user->role?->isFacilityScoped()) {
            $query->whereIn('id', $user->facilityAssignments()->select('facility_id'));
        }

        $facilities = $query->limit(2)->get();

        return $facilities->count() === 1 ? $facilities->first() : null;
    }
}
