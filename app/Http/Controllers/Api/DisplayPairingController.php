<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DisplayDevice;
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
    /**
     * Redeem a pairing code for a display credential.
     */
    public function pair(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pairing_code' => ['required', 'string', 'max:32'],
        ]);

        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $validated['pairing_code']) ?? '');

        abort_if($code === '', 422, 'A pairing code is required.');

        /** @var DisplayDevice|null $device */
        $device = DisplayDevice::query()
            ->with(['tenant', 'facility'])
            ->where('pairing_code_hash', hash('sha256', $code))
            ->first();

        abort_if($device === null, 404, 'The pairing code is not recognised.');

        abort_unless($device->isAwaitingPairing(), 403, 'The pairing code has expired or was already used.');

        abort_unless($device->is_active, 403, 'The display device is inactive or expired.');

        abort_unless(
            $device->tenant?->status->isOperational() ?? false,
            403,
            'The display device belongs to a tenant that is not active.',
        );

        abort_if(
            $device->facility === null || $device->facility->tenant_id !== $device->tenant_id,
            403,
            'The display device is not bound to a valid facility.',
        );

        $credential = bin2hex(random_bytes(32));

        // Burn the code and install the credential atomically so two screens
        // racing on the same code cannot both complete.
        $burned = DisplayDevice::query()
            ->whereKey($device->id)
            ->where('pairing_code_hash', hash('sha256', $code))
            ->update([
                'credential_hash' => hash('sha256', $credential),
                'pairing_code_hash' => null,
                'pairing_expires_at' => null,
                'paired_at' => now(),
                'last_seen_at' => now(),
            ]);

        abort_if($burned === 0, 409, 'The pairing code was used by another device.');

        $device->refresh()->load(['tenant', 'facility']);

        return response()->json([
            'message' => 'Display device paired.',
            'data' => [
                'credential' => $credential,
                'device' => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'tenant' => [
                        'id' => $device->tenant->id,
                        'name' => $device->tenant->name,
                        'slug' => $device->tenant->slug,
                    ],
                    'facility' => [
                        'id' => $device->facility->id,
                        'name' => $device->facility->name,
                        'code' => $device->facility->code,
                    ],
                ],
            ],
        ]);
    }
}
