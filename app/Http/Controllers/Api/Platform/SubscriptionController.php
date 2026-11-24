<?php

namespace App\Http\Controllers\Api\Platform;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform subscription listing and detail (PRD §10.3).
 *
 * Read-only by design: activation follows payment-provider webhooks, so the
 * platform console cannot silently flip a subscription to ACTIVE and hand out a
 * paid plan for free. Cancellation continues through the tenant's own billing
 * endpoint.
 */
class SubscriptionController extends Controller
{
    use HandlesTableQuery;

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'status' => ['nullable', 'string', Rule::in(array_column(SubscriptionStatus::cases(), 'value'))],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ]);

        $query = Subscription::query()
            ->with(['tenant:id,name,slug', 'plan:id,code,name']);

        foreach (['status', 'plan_id', 'tenant_id'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->whereHas('tenant', fn($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%"));
        }

        $this->applyTableSort($query, $validated, ['status', 'current_period_end', 'created_at', 'id']);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated, 25)),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $subscription = Subscription::query()
            ->with(['tenant:id,name,slug', 'plan.prices', 'plan.entitlements'])
            ->findOrFail($id);

        return response()->json(['data' => $subscription]);
    }
}
