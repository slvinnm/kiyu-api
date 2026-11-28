<?php

namespace App\Http\Controllers\Api\App\Facilities;

use App\Http\Controllers\Controller;
use App\Services\Facilities\FacilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization-wide facility management for the TenantAdmin App.
 *
 * The tenant is always the authenticated account's tenant; the body can never
 * select another. Capacity (`max_facilities`) is enforced by the service.
 */
class FacilityController extends Controller
{
    public function __construct(
        private readonly FacilityService $facilities,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->facilities->listForTenant($this->tenant()),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->facilities->find($this->tenant(), $id),
        ]);
    }

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

        $facility = $this->facilities->create($this->tenant(), $validated);

        return response()->json([
            'message' => 'Facility created successfully.',
            'data' => $facility,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $facility = $this->facilities->update($this->tenant(), $id, $validated);

        return response()->json([
            'message' => 'Facility updated successfully.',
            'data' => $facility,
        ]);
    }
}
