<?php

namespace App\Http\Controllers\Api\Portal;

use App\Enums\TenantStatus;
use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AuditService;
use App\Services\TenantOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform-only tenant administration (PRD §10.2).
 *
 * Mounted under the `platform` middleware group: only explicitly typed PLATFORM
 * accounts reach these methods. These routes never run inside a tenant context.
 */
class TenantController extends Controller
{
    use HandlesTableQuery;

    public function __construct(
        private readonly TenantOnboardingService $onboarding,
        private readonly AuditService $audit,
    ) {}

    /**
     * Paginated, searchable, filterable tenant list.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'status' => ['nullable', 'string', Rule::in(array_column(TenantStatus::cases(), 'value'))],
        ]);

        $query = Tenant::query()
            ->withCount(['facilities', 'users', 'customers', 'queueSessions', 'kioskDevices', 'displayDevices']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('billing_email', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%");
            });
        }

        $this->applyTableSort($query, $validated, ['name', 'slug', 'status', 'created_at', 'id'], null);
        $query->orderBy('name');

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated, 25)),
        ]);
    }

    /**
     * Tenant detail with owner, facilities, device usage and subscription.
     */
    public function show(int $id): JsonResponse
    {
        $tenant = Tenant::query()
            ->with([
                'facilities',
                'owner:id,name,email',
                'subscriptions' => fn($q) => $q->latest()->limit(1)->with('plan.prices', 'plan.entitlements'),
            ])
            ->withCount(['users', 'customers', 'queueSessions', 'kioskDevices', 'displayDevices'])
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

        $this->audit->log(
            action: 'platform.tenant.provisioned',
            entityType: 'tenant',
            entityId: $result['tenant']->id,
            metadata: ['slug' => $result['tenant']->slug],
            platformUserId: $request->user()?->id,
            tenant: $result['tenant'],
        );

        return response()->json([
            'message' => 'Tenant provisioned successfully.',
            'data' => [
                'tenant' => $result['tenant'],
                'facility' => $result['facility'],
                'tenant_admin' => [
                    'id' => $result['tenant_admin']->id,
                    'name' => $result['tenant_admin']->name,
                    'email' => $result['tenant_admin']->email,
                ],
            ],
        ], 201);
    }

    /**
     * Suspend a tenant with a recorded reason (PRD §10.2).
     */
    public function suspend(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $tenant = Tenant::query()->findOrFail($id);

        if ($tenant->status === TenantStatus::Suspended) {
            throw new InvalidStateException('This tenant is already suspended.');
        }

        $previous = $tenant->status;

        $tenant->update(['status' => TenantStatus::Suspended]);

        $this->audit->log(
            action: 'platform.tenant.suspended',
            entityType: 'tenant',
            entityId: $tenant->id,
            metadata: ['reason' => $validated['reason'], 'from' => $previous?->value],
            platformUserId: $request->user()?->id,
            tenant: $tenant,
        );

        return response()->json([
            'message' => 'Tenant suspended.',
            'data' => $tenant->fresh(),
        ]);
    }

    /**
     * Reactivate a suspended tenant.
     */
    public function reactivate(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($id);

        if ($tenant->status !== TenantStatus::Suspended) {
            throw new InvalidStateException('Only a suspended tenant can be reactivated.');
        }

        $tenant->update(['status' => TenantStatus::Active]);

        $this->audit->log(
            action: 'platform.tenant.reactivated',
            entityType: 'tenant',
            entityId: $tenant->id,
            metadata: null,
            platformUserId: $request->user()?->id,
            tenant: $tenant,
        );

        return response()->json([
            'message' => 'Tenant reactivated.',
            'data' => $tenant->fresh(),
        ]);
    }
}
