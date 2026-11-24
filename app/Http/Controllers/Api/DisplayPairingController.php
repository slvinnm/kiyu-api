<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DisplayDevice;
use App\Services\DevicePairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Redeem a display pairing code and hand the screen its credential.
 *
 * Same flow as kiosk pairing: an admin registers the display, an operator
 * types the short code on the screen, and the screen trades it for the
 * long-lived credential it stores locally. The code is single-use.
 */
class DisplayPairingController extends Controller
{
    public function __construct(
        private readonly DevicePairingService $pairing,
    ) {}

    /**
     * Redeem a pairing code for a display credential.
     */
    public function pair(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pairing_code' => ['required', 'string', 'max:32'],
        ]);

        $code = $this->pairing->normaliseCode($validated['pairing_code']);

        abort_if($code === '', 422, 'A pairing code is required.');

        $result = $this->pairing->redeem(DisplayDevice::class, $code, 'display device');

        return response()->json([
            'message' => 'Display device paired.',
            'data' => $this->pairing->pairedPayload($result['device'], $result['credential']),
        ]);
    }
}
