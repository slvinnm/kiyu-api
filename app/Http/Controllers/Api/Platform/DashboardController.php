<?php

namespace App\Http\Controllers\Api\Platform;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\BillingWebhookEvent;
use App\Models\KioskDevice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform dashboard metrics (PRD §10.1).
 *
 * Every figure is computed from real rows with a documented definition. MRR is
 * derived from effective recurring subscriptions normalised to a monthly value;
 * it is deliberately NOT cash collected (that is `collection`).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $request->date('from')?->startOfDay() ?? now()->subDays(29)->startOfDay();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();

        $tenantCounts = Tenant::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $newTenants = Tenant::query()
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $subscriptionsByStatus = Subscription::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $mrr = $this->monthlyRecurringRevenue();

        $collection = (int) Payment::query()
            ->where('status', PaymentStatus::Paid->value)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');

        $failedPayments = Payment::query()
            ->where('status', PaymentStatus::Failed->value)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $webhookFailures = BillingWebhookEvent::query()
            ->where('status', 'FAILED')
            ->whereBetween('received_at', [$from, $to])
            ->count();

        $deviceUsage = KioskDevice::query()
            ->selectRaw('tenant_id, COUNT(*) as total')
            ->where('is_active', true)
            ->groupBy('tenant_id')
            ->with('tenant:id,name,slug')
            ->get()
            ->map(fn($row): array => [
                'tenant_id' => $row->tenant_id,
                'tenant' => $row->tenant?->name,
                'slug' => $row->tenant?->slug,
                'kiosk_devices' => (int) $row->total,
            ]);

        return response()->json([
            'data' => [
                'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
                'tenants' => [
                    'total' => (int) $tenantCounts->sum(),
                    'by_status' => $tenantCounts,
                    'new_in_range' => $newTenants,
                ],
                'subscriptions' => [
                    'by_status' => $subscriptionsByStatus,
                    'effective' => (int) $subscriptionsByStatus->only(SubscriptionStatus::effectiveValues())->sum(),
                ],
                // Monthly recurring revenue in the platform's minor currency
                // unit, normalised per billing interval.
                'mrr' => $mrr,
                'collection_in_range' => $collection,
                'failed_payments_in_range' => $failedPayments,
                'failed_webhooks_in_range' => $webhookFailures,
                'active_plans' => Plan::query()->where('is_active', true)->count(),
                'device_usage' => $deviceUsage,
            ],
        ]);
    }

    /**
     * MRR: each effective subscription's plan price normalised to a month.
     *
     * @return array{amount: int, currency: string|null, subscriptions: int}
     */
    private function monthlyRecurringRevenue(): array
    {
        $subscriptions = Subscription::query()
            ->with(['planPrice'])
            ->whereIn('status', [SubscriptionStatus::Active->value])
            ->get();

        $amount = 0;
        $currency = null;

        foreach ($subscriptions as $subscription) {
            $price = $subscription->planPrice;

            if ($price === null) {
                continue;
            }

            $monthly = $price->billing_interval->value === 'YEARLY'
                ? intdiv($price->amount, 12)
                : $price->amount;

            $amount += $monthly;
            $currency ??= $price->currency;
        }

        return [
            'amount' => $amount,
            'currency' => $currency,
            'subscriptions' => $subscriptions->count(),
        ];
    }
}
