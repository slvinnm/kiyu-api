<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to tenants whose effective subscription grants a feature.
 *
 * Usage: `->middleware('feature:multi_facility')`. The current tenant comes
 * from the resolved context, never from the request.
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

        if (! $tenant instanceof Tenant) {
            abort(403, 'Forbidden.');
        }

        abort_unless(
            $this->subscriptions->hasFeature($tenant, $feature),
            403,
            "Your plan does not include the '{$feature}' feature.",
        );

        return $next($request);
    }
}
