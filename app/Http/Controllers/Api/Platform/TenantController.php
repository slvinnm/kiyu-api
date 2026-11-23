<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-only tenant administration.
 *
 * Mounted under the `platform` middleware group: only explicitly typed PLATFORM
 * accounts reach these methods. These routes never run inside a tenant context.
 */
class TenantController extends Controller
{
    public function __construct(
        private readonly TenantOnboardingService $onboarding,
    ) {}

    /**
     * List every tenant with its facility and tenant count.
     */
    public function index(): JsonResponse
    {
        $tenants = Tenant::query()
            ->withCount(['facilities', 'users', 'clients'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $tenants]);
    }

    /**
     * Show one tenant with its facilities.
     */
    public function show(int $id): JsonResponse
    {
        $tenant = Tenant::query()
            ->with(['facilities'])
            ->withCount(['users', 'clients', 'visits'])
            ->findOrFail($id);

        return response()->json(['data' => $tenant]);
    }

    /**
     * Provision a new tenant, its facility, its first admin and a default
     * queue template.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'facility_name' => ['required', 'string', 'max:255'],
            'facility_code' => ['nullable', 'string', 'max:50'],
            'facility_address' => ['nullable', 'string', 'max:255'],
            'facility_phone' => ['nullable', 'string', 'max:30'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8'],
            'department_code' => ['nullable', 'string', 'max:50'],
            'department_name' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->onboarding->provision($validated);

        return response()->json([
            'message' => 'Tenant provisioned successfully.',
            'data' => [
                'tenant' => $result['tenant'],
                'facility' => $result['facility'],
                'admin' => [
                    'id' => $result['admin']->id,
                    'name' => $result['admin']->name,
                    'email' => $result['admin']->email,
                ],
            ],
        ], 201);
    }
}
