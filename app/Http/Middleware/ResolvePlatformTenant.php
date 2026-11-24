<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bind a target tenant for a platform operator acting on a tenant.
 *
 * Access is a deliberate, separate path: only the platform role may reach the
 * `/platform/tenants/{tenant}/...` routes, and the tenant is chosen by the
 * route parameter, then verified to exist. From there the tenant-scoped
 * controllers run exactly as they do for that tenant's own admin, so a platform
 * operator can support any tenant without bypassing tenant scoping.
 *
 * Every support request is audited with the acting platform user and the
 * target tenant, regardless of endpoint.
 */
class ResolvePlatformTenant
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless($user !== null && $user->isPlatformUser(), 403, 'Forbidden.');

        $tenantId = $request->route('tenant');

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);

        abort_if($tenant === null, 404, 'Tenant not found.');

        $active = $tenant->facilities()->where('is_active', true)->limit(2)->get();

        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('facility', $active->count() === 1 ? $active->first() : null);
        $request->attributes->set('acting_as_platform', true);
        $request->attributes->set('platform_actor_id', $user->id);

        // Drop the `{tenant}` route parameter once resolved. Otherwise it is
        // passed positionally to controller scalar arguments ahead of `{id}`,
        // so a method like `destroy(int $id)` would receive the tenant id.
        $request->route()?->forgetParameter('tenant');

        $this->audit->log(
            action: 'PLATFORM_SUPPORT_ACCESS',
            entityType: 'Tenant',
            entityId: $tenant->id,
            metadata: [
                'method' => $request->method(),
                'path' => $request->path(),
            ],
            userId: null,
            ipAddress: $request->ip(),
            platformUserId: $user->id,
            tenant: $tenant,
        );

        return $next($request);
    }
}
