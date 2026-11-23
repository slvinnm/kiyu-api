<?php

namespace App\Http\Middleware;

use App\Exceptions\InvalidStateException;
use App\Services\KioskDeviceResolver;
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
    public function __construct(
        private readonly KioskDeviceResolver $resolver,
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->header('X-Kiosk-Token', '');

        abort_if($token === '', 403, 'A kiosk device credential is required.');

        try {
            $device = $this->resolver->resolveKiosk($token);
        } catch (InvalidStateException $e) {
            abort(403, $e->getMessage());
        }

        $request->attributes->set('tenant', $device->tenant);
        $request->attributes->set('facility', $device->facility);

        return $next($request);
    }
}
