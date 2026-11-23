<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Models\Facility;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve a public tenant context from a trusted locator.
 *
 * The locator is a public identifier (slug) and never grants membership or
 * staff access. Resolution order:
 *   1. `X-Tenant-Slug` header (used by the public frontend and kiosk shell)
 *   2. `tenant` route parameter
 *   3. the request host's first label (tenant subdomain)
 *
 * A body `tenant_id` is deliberately never accepted. The facility is the
 * tenant's single active facility in V1.
 */
class ResolvePublicTenant
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->resolveSlug($request);

        if ($slug === null) {
            abort(404, 'Tenant not specified.');
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            abort(404, 'Tenant not found.');
        }

        if ($tenant->status !== TenantStatus::Active) {
            abort(403, 'This tenant is not active.');
        }

        /** @var Facility|null $facility */
        $facility = $tenant->facilities()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('facility', $facility);

        return $next($request);
    }

    /**
     * Determine the public tenant slug from a trusted locator.
     */
    private function resolveSlug(Request $request): ?string
    {
        $header = (string) $request->header('X-Tenant-Slug', '');

        if ($header !== '') {
            return $header;
        }

        $routeParam = $request->route('tenant');

        if (is_string($routeParam) && $routeParam !== '') {
            return $routeParam;
        }

        $host = $request->getHost();
        $label = explode('.', $host)[0];

        return $label !== '' && $label !== $host ? $label : null;
    }
}
