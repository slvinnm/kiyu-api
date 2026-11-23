<?php

namespace App\Http\Middleware;

use App\Models\KioskDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establish the tenant and facility from a registered kiosk device.
 *
 * The credential is read from the `X-Kiosk-Token` header; only its hash is
 * stored. The device - not the request body - determines the tenant and
 * facility for kiosk intake.
 */
class ResolveKioskDevice
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->header('X-Kiosk-Token', '');

        abort_if($token === '', 403, 'A kiosk device credential is required.');

        /** @var KioskDevice|null $device */
        $device = KioskDevice::query()
            ->with(['tenant', 'facility'])
            ->where('credential_hash', hash('sha256', $token))
            ->first();

        abort_if($device === null || ! $device->isUsable(), 403, 'The kiosk device credential is invalid or revoked.');

        abort_if(
            $device->facility === null || $device->facility->tenant_id !== $device->tenant_id,
            403,
            'The kiosk device is not bound to a valid facility.',
        );

        $request->attributes->set('tenant', $device->tenant);
        $request->attributes->set('facility', $device->facility);

        return $next($request);
    }
}
