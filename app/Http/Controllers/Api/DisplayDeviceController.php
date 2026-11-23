<?php

namespace App\Http\Controllers\Api;

use App\Enums\Feature;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Controller;
use App\Models\DisplayDevice;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Manage the tenant's display devices.
 *
 * Creating a display is gated by the max_display_devices limit. Registration
 * returns a one-time pairing code, not the credential.
 */
class DisplayDeviceController extends Controller
{
    private const PAIRING_TTL_MINUTES = 30;

    private const PAIRING_CODE_LENGTH = 8;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * List the tenant's display devices (credentials are never exposed).
     */
    public function index(): JsonResponse
    {
        $devices = DisplayDevice::query()
            ->where('tenant_id', $this->tenantId())
            ->orderBy('name')
            ->get()
            ->map(fn(DisplayDevice $device): array => [
                'id' => $device->id,
                'name' => $device->name,
                'facility_id' => $device->facility_id,
                'is_active' => $device->is_active,
                'is_paired' => $device->isPaired(),
                'is_awaiting_pairing' => $device->isAwaitingPairing(),
                'paired_at' => $device->paired_at?->toIso8601String(),
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'expires_at' => $device->expires_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $devices]);
    }

    /**
     * Register a display device and return its one-time pairing code.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $tenant = $this->tenant();

        $this->subscriptions->ensureCapacity($tenant, Feature::MaxDisplayDevices);

        $pairingCode = $this->generatePairingCode();

        $device = DisplayDevice::query()->create([
            'tenant_id' => $tenant->id,
            'facility_id' => $this->facilityId(),
            'name' => $validated['name'],
            // Placeholder credential; unusable until the code is redeemed.
            'credential_hash' => hash('sha256', Str::random(64)),
            'pairing_code_hash' => hash('sha256', $pairingCode),
            'pairing_expires_at' => now()->addMinutes(self::PAIRING_TTL_MINUTES),
            'is_active' => true,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Display device registered. Enter the pairing code on the screen; it is shown only once.',
            'data' => [
                'device' => $device,
                'pairing_code' => $pairingCode,
                'pairing_expires_at' => $device->pairing_expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Reissue a pairing code for a display that never completed onboarding.
     */
    public function reissuePairingCode(int $id): JsonResponse
    {
        $device = DisplayDevice::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        abort_unless($device->is_active, 409, 'The display device is revoked.');

        abort_if(
            $device->isPaired(),
            409,
            'This display is already paired. Revoke and re-register it to change its credential.',
        );

        $pairingCode = $this->generatePairingCode();

        $device->forceFill([
            'pairing_code_hash' => hash('sha256', $pairingCode),
            'pairing_expires_at' => now()->addMinutes(self::PAIRING_TTL_MINUTES),
        ])->save();

        return response()->json([
            'message' => 'Pairing code reissued. Enter it on the screen; it is shown only once.',
            'data' => [
                'device' => $device,
                'pairing_code' => $pairingCode,
                'pairing_expires_at' => $device->pairing_expires_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Revoke a display device.
     */
    public function destroy(int $id): JsonResponse
    {
        $device = DisplayDevice::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        $device->update(['is_active' => false]);

        return response()->json([
            'message' => 'Display device revoked.',
        ]);
    }

    /**
     * Generate a short, unambiguous pairing code with no hash collision.
     */
    private function generatePairingCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';

            for ($i = 0; $i < self::PAIRING_CODE_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $taken = DisplayDevice::query()
                ->where('pairing_code_hash', hash('sha256', $code))
                ->exists();

            if (! $taken) {
                return $code;
            }
        }

        throw new InvalidStateException('Could not generate a unique pairing code; please retry.');
    }
}
