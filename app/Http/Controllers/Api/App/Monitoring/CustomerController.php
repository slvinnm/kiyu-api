<?php

namespace App\Http\Controllers\Api\App\Monitoring;

use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only organization-wide customer view for the TenantAdmin App.
 *
 * The App does not create or edit customer records; that happens through the
 * Tenant workspace (reception) or the Customer context. Only safe read models
 * are exposed here.
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

    public function statistics(): JsonResponse
    {
        return response()->json([
            'data' => [
                'total' => Customer::query()->where('tenant_id', $this->tenantId())->count(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'data' => Customer::query()
                ->where('tenant_id', $this->tenantId())
                ->findOrFail($id),
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
}
