<?php

namespace App\Http\Controllers\Api;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage the tenant's facilities.
 *
 * Creating a facility is gated by the max_facilities limit. V1 onboarding
 * creates one facility; additional ones are added here on higher plans.
 */
class FacilityController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * List the tenant's facilities.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Facility::query()
                ->where('tenant_id', $this->tenantId())
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Create a facility.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tenant = $this->tenant();

        $this->subscriptions->ensureCapacity($tenant, Feature::MaxFacilities);

        $facility = Facility::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'address' => $validated['address'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'timezone' => $validated['timezone'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Facility created successfully.',
            'data' => $facility,
        ], 201);
    }

    /**
     * Update a facility.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $facility = Facility::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $facility->update($validated);

        return response()->json([
            'message' => 'Facility updated successfully.',
            'data' => $facility,
        ]);
    }
}
