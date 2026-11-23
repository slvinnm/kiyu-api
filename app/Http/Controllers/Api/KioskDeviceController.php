<?php

namespace App\Http\Controllers\Api;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Manage the tenant's kiosk devices.
 *
 * Creating a device is gated by the max_kiosk_devices limit. The raw device
 * credential is returned exactly once, on creation; only its hash is stored.
 */
class KioskDeviceController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * List the tenant's kiosk devices (credentials are never exposed).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => KioskDevice::query()
                ->where('tenant_id', $this->tenantId())
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Register a kiosk device and return its one-time credential.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $tenant = $this->tenant();

        $this->subscriptions->ensureCapacity($tenant, Feature::MaxKioskDevices);

        $credential = Str::random(64);

        $device = KioskDevice::query()->create([
            'tenant_id' => $tenant->id,
            'facility_id' => $this->facilityId(),
            'name' => $validated['name'],
            'credential_hash' => hash('sha256', $credential),
            'is_active' => true,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Kiosk device registered. Store the credential now; it is shown only once.',
            'data' => [
                'device' => $device,
                'credential' => $credential,
            ],
        ], 201);
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
