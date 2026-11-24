<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
use App\Services\DevicePairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Redeem a kiosk pairing code and hand the device its credential.
 *
 * This is the only unauthenticated kiosk endpoint that issues a credential.
 * The admin dashboard never needs to be open when the device is paired: the
 * operator types the short code printed by the admin, and the device trades
 * it for the long-lived credential it stores locally.
 *
 * The code is single-use and short-lived, so a leaked screenshot is not a
 * durable credential.
 */
class KioskPairingController extends Controller
{
    public function __construct(
        private readonly DevicePairingService $pairing,
    ) {}

    /**
     * Redeem a pairing code for a device credential.
     */
    public function pair(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pairing_code' => ['required', 'string', 'max:32'],
        ]);

        $code = $this->pairing->normaliseCode($validated['pairing_code']);

        abort_if($code === '', 422, 'A pairing code is required.');

        $result = $this->pairing->redeem(KioskDevice::class, $code, 'kiosk device');

        return response()->json([
            'message' => 'Kiosk device paired.',
            'data' => $this->pairing->pairedPayload($result['device'], $result['credential']),
        ]);
    }
}
