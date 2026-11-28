<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Exceptions\InvalidStateException;
use App\Models\Facility;
use App\Models\Tenant;
use App\Services\Auth\OriginContextResolver;
use App\Services\KioskDeviceResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the public tenant context of a guest tenant request.
 *
 * Used by the tenant-origin-bound public endpoints (public queue, active
 * departments, tracking, kiosk intake, pairing). The tenant is decided by the
 * validated origin slug, a scoped `X-Tenant-Slug` locator, or a paired device
 * credential — never by `Request::getHost()`, which is the API host when the
 * API is central, and never by a body `tenant_id`.
 */
class ResolveTenantFromOrigin
{
    public function __construct(
        private readonly KioskDeviceResolver $kioskDevices,
        private readonly OriginContextResolver $origins,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // A paired device is the strongest locator: it pins tenant + facility.
        try {
            $device = $this->kioskDevices->resolveKiosk((string) $request->header('X-Kiosk-Token', ''))
                ?? $this->kioskDevices->resolveDisplay((string) $request->header('X-Display-Token', ''));
        } catch (InvalidStateException $e) {
            abort(403, $e->getMessage());
        }

        if ($device !== null) {
            $request->attributes->set('tenant', $device->tenant);
            $request->attributes->set('facility', $device->facility);
            $request->attributes->set('origin_tenant_slug', $device->tenant->slug);
            // A paired device is the authoritative identity for device endpoints;
            // controllers that require a device assert this attribute (no separate
            // kiosk middleware).
            $request->attributes->set('device', $device);

            return $next($request);
        }

        $slug = $this->origins->tenantSlugForOrigin($request->headers->get('Origin'))
            ?? $this->headerSlug($request);

        abort_if($slug === null, 404, 'Tenant not specified.');

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->where('slug', $slug)->first();

        abort_if($tenant === null, 404, 'Tenant not found.');

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
        $request->attributes->set('origin_tenant_slug', $tenant->slug);

        return $next($request);
    }

    /**
     * An explicit locator header, for non-browser clients.
     *
     * Less trusted than an Origin: the caller is still anonymous here, so this
     * only selects which public tenant is addressed. It never proves identity.
     */
    private function headerSlug(Request $request): ?string
    {
        $header = trim((string) $request->header('X-Tenant-Slug', ''));

        return $header !== '' ? $header : null;
    }
}
