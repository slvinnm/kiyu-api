<?php

namespace App\Http\Controllers\Api\Tenant\Customers;

use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer records inside the tenant workspace.
 *
 * Every caller is internal staff now, so this controller has no
 * customer-self-service branches; a customer manages their own record through
 * the Customer context.
 */
class CustomerController extends Controller
{
    use HandlesTableQuery;

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $query = Customer::query()->where('tenant_id', $this->tenantId());

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (! empty($validated['phone'])) {
            $query->where('phone', $validated['phone']);
        }

        $this->applyTableSort($query, $validated, ['name', 'phone', 'created_at', 'id']);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated)),
        ]);
    }

    public function statistics(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'total' => Customer::query()->where('tenant_id', $this->tenantId())->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (! empty($validated['user_id'])) {
            $this->assertUserInTenant((int) $validated['user_id']);
        }

        $customer = new Customer([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
        ]);

        $customer->tenant_id = $this->tenantId();
        $customer->save();

        return response()->json([
            'message' => 'Customer created successfully.',
            'data' => $customer,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $customer = Customer::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        return response()->json([
            'data' => $customer,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $customer = Customer::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
        ]);

        $customer->update($validated);

        return response()->json([
            'message' => 'Customer updated successfully.',
            'data' => $customer,
        ]);
    }

    public function queueSessions(Request $request, int $id): JsonResponse
    {
        $customer = Customer::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        $validated = $this->validateTableQuery($request);

        return response()->json([
            'data' => $customer->queueSessions()
                ->where('tenant_id', $this->tenantId())
                ->with(['department', 'workflow', 'activeQueueTicket'])
                ->latest('id')
                ->paginate($this->tablePerPage($validated)),
        ]);
    }

    /**
     * Abort unless the given user belongs to the current tenant.
     */
    private function assertUserInTenant(int $userId): void
    {
        $belongs = User::query()
            ->whereKey($userId)
            ->where('tenant_id', $this->tenantId())
            ->exists();

        abort_unless($belongs, 422, 'The linked user does not belong to this tenant.');
    }
}
