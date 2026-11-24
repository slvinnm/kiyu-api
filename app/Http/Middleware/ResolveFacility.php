<?php

namespace App\Http\Middleware;

use App\Models\Facility;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantee that the request has an authorised facility.
 *
 * Requirement for acceptance: assume the tenant has exactly one facility in
 * V1, so a facility-scoped route resolves it implicitly. When a tenant has
 * several, the caller must name one with `X-Facility-Id` and the user must be
 * allowed to reach it (tenant admin, or an explicit assignment).
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

        if ($request->attributes->get('facility') === null) {
            $requested = $request->header('X-Facility-Id');

            abort_if(
                $requested === null || $requested === '',
                400,
                'This tenant has several facilities. Send X-Facility-Id to choose one.',
            );

            abort_unless(
                ctype_digit((string) $requested),
                400,
                'X-Facility-Id must be a facility id.',
            );

            $facility = Facility::query()
                ->whereKey((int) $requested)
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->first();

            abort_if($facility === null, 404, 'Facility not found.');

            $user = $request->user();

            abort_unless(
                $user !== null && $user->canAccessFacility($facility->id),
                403,
                'You do not have access to this facility.',
            );

            $request->attributes->set('facility', $facility);
        }

        return $next($request);
    }
}
