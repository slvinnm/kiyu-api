<?php

namespace App\Http\Controllers\Api\Portal;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Api\Concerns\HandlesTableQuery;
use App\Http\Controllers\Controller;
use App\Models\BillingWebhookEvent;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform payments and webhook event history (PRD §10.4).
 *
 * Financial records are never cascade-deleted with a subscription, so this is a
 * faithful history. Retrying a webhook is a separate, audited action.
 */
class PaymentController extends Controller
{
    use HandlesTableQuery;

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'status' => ['nullable', 'string', Rule::in(array_column(PaymentStatus::cases(), 'value'))],
            'provider' => ['nullable', 'string', 'max:50'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = Payment::query()
            ->with(['subscription:id,tenant_id,plan_id', 'subscription.tenant:id,name,slug']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['provider'])) {
            $query->where('provider', $validated['provider']);
        }

        if (! empty($validated['tenant_id'])) {
            $query->whereHas('subscription', fn($q) => $q->where('tenant_id', $validated['tenant_id']));
        }

        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('provider_transaction_id', 'like', "%{$search}%")
                    ->orWhereHas('subscription.tenant', fn($t) => $t
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%"));
            });
        }

        $this->applyTableSort($query, $validated, ['status', 'amount', 'paid_at', 'created_at', 'id']);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated, 25)),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $payment = Payment::query()
            ->with(['subscription.tenant:id,name,slug', 'subscription.plan:id,code,name'])
            ->findOrFail($id);

        return response()->json(['data' => $payment]);
    }

    /**
     * Webhook event history with processing status (PRD §10.4).
     */
    public function webhooks(Request $request): JsonResponse
    {
        $validated = $this->validateTableQuery($request, [
            'status' => ['nullable', 'string', Rule::in(['RECEIVED', 'PROCESSED', 'FAILED'])],
            'provider' => ['nullable', 'string', 'max:50'],
        ]);

        $query = BillingWebhookEvent::query();

        foreach (['status', 'provider'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('event_id', 'like', "%{$search}%")
                    ->orWhere('event_type', 'like', "%{$search}%");
            });
        }

        $this->applyTableSort($query, $validated, ['status', 'event_type', 'received_at', 'id']);

        return response()->json([
            'data' => $query->paginate($this->tablePerPage($validated, 25)),
        ]);
    }
}
