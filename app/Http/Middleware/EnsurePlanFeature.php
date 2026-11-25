<?php

namespace App\Http\Middleware;

use App\Enums\Feature;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to tenants whose effective subscription grants a feature.
 *
 * Usage: `->middleware('feature:multi_facility')`. The current tenant comes
 * from the resolved context, never from the request. An unknown feature code
 * aborts so a typo is caught at request time rather than silently denying.
 */
class EnsurePlanFeature
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenant = $request->attributes->get('tenant');

        abort_unless($tenant instanceof Tenant, 403, 'Forbidden.');

        $resolved = Feature::tryFrom($feature);

        abort_if(
            $resolved === null,
            500,
            "Unknown plan feature '{$feature}'.",
        );

        abort_unless(
            $this->subscriptions->hasFeature($tenant, $resolved)
                // Advanced reporting implies the basic reports menu, so a plan
                // that grants only the advanced tier is never denied the basic
                // one (PRD §10.6 hierarchy).
                || ($resolved === Feature::BasicReports
                    && $this->subscriptions->hasFeature($tenant, Feature::AdvancedReports)),
            403,
            "Your plan does not include the '{$feature}' feature.",
        );

        return $next($request);
    }
}
