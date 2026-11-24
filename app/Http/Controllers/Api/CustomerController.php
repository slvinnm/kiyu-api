<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidStateException;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use HandlesTableQuery;

    /**
     * Search and list customers.
     */
    public function index(Request $request): JsonResponse
    {
        abort_if($this->isClient($request), 403, 'Forbidden.');

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

    /**
     * Aggregate counts for dashboard widgets.
     */
    public function statistics(Request $request): JsonResponse
    {
        abort_if($this->isClient($request), 403, 'Forbidden.');

        return response()->json([
            'data' => [
                'total' => Customer::query()->where('tenant_id', $this->tenantId())->count(),
            ],
        ]);
    }

    /**
     * Create a customer.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if ($this->isClient($request)) {
            $userId = $request->user()->id;

            if (! empty($validated['user_id']) && (int) $validated['user_id'] !== $userId) {
                abort(403, 'Forbidden.');
            }

            if (Customer::query()->where('user_id', $userId)->exists()) {
                throw new InvalidStateException('A client profile already exists for this account.');
            }

            $validated['user_id'] = $userId;
        }

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

    /**
     * Show a customer.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeClientRecord($request, $id);

        $customer = Customer::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        return response()->json([
            'data' => $customer,
        ]);
    }

    /**
     * Update a customer.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeClientRecord($request, $id);

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

    /**
     * Get queue sessions for a customer.
     */
    public function queueSessions(Request $request, int $id): JsonResponse
    {
        $this->authorizeClientRecord($request, $id);

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
     * Deny a client account access to another client's record.
     *
     * Staff operations (reception, admin) are unrestricted here and keep their
     * existing behaviour; only the client role is scoped to its own record.
     */
    private function authorizeClientRecord(Request $request, int $customerId): void
    {
        if (! $this->isClient($request)) {
            return;
        }

        abort_unless($this->customerId($request) === $customerId, 403, 'Forbidden.');
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
