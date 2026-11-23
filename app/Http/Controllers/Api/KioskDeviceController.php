<?php

namespace App\Http\Controllers\Api;

use App\Enums\Feature;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Manage the tenant's kiosk devices.
 *
 * Creating a device is gated by the max_kiosk_devices limit. Registration
 * returns a short pairing code, not the credential: the operator types the code
 * on the kiosk and the device trades it for its credential through the public
 * pairing endpoint. Neither the code nor the credential is recoverable later.
 */
class KioskDeviceController extends Controller
{
    /** How long an operator has to enter the pairing code. */
    private const PAIRING_TTL_MINUTES = 30;

    /** Length of the generated pairing code. */
    private const PAIRING_CODE_LENGTH = 8;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * List the tenant's kiosk devices (credentials are never exposed).
     */
    public function index(): JsonResponse
    {
        $devices = KioskDevice::query()
            ->where('tenant_id', $this->tenantId())
            ->orderBy('name')
            ->get()
            ->map(fn(KioskDevice $device): array => [
                'id' => $device->id,
                'name' => $device->name,
                'facility_id' => $device->facility_id,
                'is_active' => $device->is_active,
                // Lets the admin dashboard show "awaiting pairing" vs "live".
                'is_paired' => $device->isPaired(),
                'is_awaiting_pairing' => $device->isAwaitingPairing(),
                'paired_at' => $device->paired_at?->toIso8601String(),
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'expires_at' => $device->expires_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $devices]);
    }

    /**
     * Register a kiosk device and return its one-time pairing code.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $tenant = $this->tenant();

        $this->subscriptions->ensureCapacity($tenant, Feature::MaxKioskDevices);

        $pairingCode = $this->generatePairingCode();

        $device = KioskDevice::query()->create([
            'tenant_id' => $tenant->id,
            'facility_id' => $this->facilityId(),
            'name' => $validated['name'],
            // A placeholder credential: the device cannot call the kiosk API
            // until it redeems the pairing code.
            'credential_hash' => hash('sha256', Str::random(64)),
            'pairing_code_hash' => hash('sha256', $pairingCode),
            'pairing_expires_at' => now()->addMinutes(self::PAIRING_TTL_MINUTES),
            'is_active' => true,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Kiosk device registered. Enter the pairing code on the device; it is shown only once.',
            'data' => [
                'device' => $device,
                'pairing_code' => $pairingCode,
                'pairing_expires_at' => $device->pairing_expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Reissue a pairing code for a device that never completed onboarding.
     */
    public function reissuePairingCode(int $id): JsonResponse
    {
        $device = KioskDevice::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        abort_unless($device->is_active, 409, 'The kiosk device is revoked.');

        // Only an unpaired device may receive a new code. Reissuing for an
        // already-paired device would hand out a code that, when redeemed,
        // silently replaces the credential of a live device.
        abort_if(
            $device->isPaired(),
            409,
            'This device is already paired. Revoke and re-register it to change its credential.',
        );

        $pairingCode = $this->generatePairingCode();

        $device->forceFill([
            'pairing_code_hash' => hash('sha256', $pairingCode),
            'pairing_expires_at' => now()->addMinutes(self::PAIRING_TTL_MINUTES),
        ])->save();

        return response()->json([
            'message' => 'Pairing code reissued. Enter it on the device; it is shown only once.',
            'data' => [
                'device' => $device,
                'pairing_code' => $pairingCode,
                'pairing_expires_at' => $device->pairing_expires_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Generate a short, unambiguous pairing code with no hash collision.
     *
     * The alphabet omits characters that are easy to confuse when read off a
     * screen (0/O, 1/I/L). A unique index backs the hash, so a (very unlikely)
     * collision is retried rather than failing the request.
     */
    private function generatePairingCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';

            for ($i = 0; $i < self::PAIRING_CODE_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $taken = KioskDevice::query()
                ->where('pairing_code_hash', hash('sha256', $code))
                ->exists();

            if (! $taken) {
                return $code;
            }
        }

        throw new InvalidStateException('Could not generate a unique pairing code; please retry.');
    }

    /**
     * Revoke a kiosk device.
     */
    public function destroy(int $id): JsonResponse
    {
        $device = KioskDevice::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        $device->update(['is_active' => false]);

        return response()->json([
            'message' => 'Kiosk device revoked.',
        ]);
    }
}
