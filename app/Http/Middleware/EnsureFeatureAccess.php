<?php

namespace App\Http\Middleware;

use App\Enums\Feature;
use App\Models\Tenant;
use App\Services\Access\FeatureAccessDenied;
use App\Services\Access\FeatureAccessEngine;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to tenants whose entitlement grants a feature and whose
 * feature is globally released.
 *
 * Delegates entirely to the central {@see FeatureAccessEngine}; there is no
 * aggregate-code fallback or plan-only shortcut. The tenant comes from the
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

        $decision = $this->engine->canAccess($tenant, $resolved, [
            'path' => $request->path(),
            'method' => $request->method(),
        ]);

        if (! $decision->allowed) {
            return (new FeatureAccessDenied($decision))->toResponse();
        }

        return $next($request);
    }
}
