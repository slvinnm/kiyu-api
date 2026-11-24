<?php

namespace App\Http\Middleware;

use App\Models\Facility;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantee that the request has an authorised facility.
 *
 * {@see ResolveTenant} binds a facility implicitly only when the user can reach
 * exactly one. This middleware covers the remaining cases:
 *
 *   - the user can reach several facilities → the caller must name one with
 *     `X-Facility-Id`, and must be allowed to reach it;
 *   - the user can reach none → 403 (they have no operational context at all).
 *
 * It never picks a facility on the user's behalf: silently choosing "the first"
 * is how a multi-location operator ends up calling tickets at the wrong branch.
 *
 * Runs after {@see ResolveTenant}, so the tenant is already on the request.
 */
class ResolveFacility
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant) {
            return $next($request);
        }

        $requested = $request->header('X-Facility-Id');

        // An explicit selection is authoritative: it is validated against the
        // tenant and the user's reach, never silently replaced by the facility
        // the context resolved on its own. Falling back would hide a stale
        // selector in the browser and act on the wrong location.
        if ($requested !== null && $requested !== '') {
            abort_unless(
                ctype_digit((string) $requested),
                400,
                'X-Facility-Id must be a facility id.',
            );

            $selected = Facility::query()
                ->whereKey((int) $requested)
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->first();

            abort_if($selected === null, 404, 'Facility not found.');

            $user = $request->user();

            abort_unless($user !== null, 401, 'Unauthenticated.');

            abort_unless(
                $user->canAccessFacility($selected->id),
                403,
                'You do not have access to this facility.',
            );

            $request->attributes->set('facility', $selected);

            return $next($request);
        }

        if ($request->attributes->get('facility') instanceof Facility) {
            return $next($request);
        }

        $user = $request->user();

        abort_unless($user !== null, 401, 'Unauthenticated.');

        // No reachable facility is an authorization failure, not a bad request:
        // there is nothing the caller could send to fix it.
        abort_if(
            $this->reachesNoFacility($user, $tenant),
            403,
            'You have no facility to operate at.',
        );

        abort(
            400,
            'This tenant has several facilities. Send X-Facility-Id to choose one.',
        );
    }

    /**
     * Whether the user has no active facility in this tenant at all.
     */
    private function reachesNoFacility(User $user, Tenant $tenant): bool
    {
        $query = $tenant->facilities()->where('is_active', true);

        if ($user->role?->isFacilityScoped()) {
            $query->whereIn('id', $user->facilityAssignments()->select('facility_id'));
        }

        return ! $query->exists();
    }
}
