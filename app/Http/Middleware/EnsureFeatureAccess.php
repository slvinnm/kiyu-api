<?php

namespace App\Http\Middleware;

use App\Enums\Feature;
use App\Models\Tenant;
use App\Services\Access\FeatureAccessDenied;
use App\Services\Access\FeatureAccessEngine;
use App\Services\Access\ReasonCode;
use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to tenants whose entitlement grants a feature and whose
 * feature is globally released.
 *
 * Delegates to the central {@see FeatureAccessEngine}. The tenant comes from the
 * resolved context, never from the request. An unknown feature code is a
 * programming error and is logged, not treated as a free pass.
 *
 * Usage: `->middleware('feature:report_queue_overview')`.
 */
class EnsureFeatureAccess
{
    public function __construct(
        private readonly FeatureAccessEngine $engine,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenant = $request->attributes->get('tenant');

        abort_unless($tenant instanceof Tenant, 403, 'Forbidden.');

        $resolved = Feature::tryFrom($feature);

        abort_if($resolved === null, 500, "Unknown plan feature '{$feature}'.");

        try {
            $decision = $this->engine->canAccess($tenant, $resolved, [
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            if (! $decision->allowed) {
                // Transitional: while existing plans have not yet been
                // snapshotted and the registry is not yet seeded everywhere,
                // fall back to the plan-only check rather than denying a
                // feature the plan genuinely grants. This fallback is removed
                // once entitlements are backfilled and the registry is seeded.
                $transitional = in_array($decision->reasonCode, [
                    ReasonCode::EntitlementRequired,
                    ReasonCode::FeatureNotRegistered,
                ], true);

                if ($transitional && $this->legacyHasFeature($tenant, $resolved)) {
                    return $next($request);
                }

                throw new FeatureAccessDenied($decision);
            }
        } catch (FeatureAccessDenied $denied) {
            return $denied->toResponse();
        }

        return $next($request);
    }

    /**
     * Transitional plan-entitlement check while snapshots are being backfilled.
     */
    private function legacyHasFeature(Tenant $tenant, Feature $feature): bool
    {
        return app(SubscriptionService::class)->hasFeature($tenant, $feature);
    }
}
