<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\OriginContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce that a tenant-bound request belongs to the tenant of its Origin.
 *
 * The tenant slug is read from the validated tenant frontend Origin (for
 * browsers) or from a documented locator header (for scoped non-browser
 * clients) and compared with the authenticated user's tenant or the device's
 * registered tenant. It runs after context authentication, so the actor is
 * known; it never grants access on its own.
 *
 * Runs before {@see ResolveTenant}.
 */
class EnsureTenantOriginBinding
{
    public function __construct(
        private readonly OriginContextResolver $origins,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolved = $request->attributes->get('origin_tenant_slug');

        if ($resolved === null) {
            // No browser Origin: a scoped non-browser client may select the
            // tenant with the locator header, which is still compared below.
            $header = trim((string) $request->header('X-Tenant-Slug', ''));
            $resolved = $header !== '' ? $header : null;
        }

        $deviceTenant = $request->attributes->get('tenant');

        if ($deviceTenant instanceof Tenant) {
            $this->assertSlugMatches($resolved, $deviceTenant->slug);

            return $next($request);
        }

        /** @var User|null $user */
        $user = $request->user();

        abort_if($user === null, 401, 'Unauthenticated.');

        $tenant = $user->tenant;

        abort_if($tenant === null, 403, 'This account has no tenant.');

        $this->assertSlugMatches($resolved, $tenant->slug);

        return $next($request);
    }

    /**
     * Reject a known mismatch; a missing locator cannot prove membership.
     */
    private function assertSlugMatches(?string $resolved, ?string $actual): void
    {
        abort_if($resolved === null || $actual === null, 403, 'A tenant origin is required for this application.');

        abort_unless(
            strcasecmp($resolved, $actual) === 0,
            403,
            'This session does not belong to this tenant.',
        );
    }
}
