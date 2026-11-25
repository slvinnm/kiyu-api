<?php

namespace App\Http\Controllers\Api\Platform;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Platform SaaS reports (PRD §10.6).
 *
 * Every figure has a documented definition and a real data source. Aggregates
 * are computed per month over the requested window; amounts are in the payment
 * currency's minor unit.
 */
class ReportController extends Controller
{
    public function revenue(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        // Paid collections grouped by month (cash received, not MRR).
        $series = Payment::query()
            ->where('status', PaymentStatus::Paid->value)
            ->whereBetween('paid_at', [$from, $to])
            ->get(['amount', 'currency', 'paid_at'])
            ->groupBy(fn(Payment $payment): string => $payment->paid_at?->format('Y-m') ?? 'unknown')
            ->map(fn($rows, string $month): array => [
                'month' => $month,
                'amount' => (int) $rows->sum('amount'),
                'currency' => $rows->first()?->currency,
                'payments' => $rows->count(),
            ])
            ->sortKeys()
            ->values();

        return response()->json([
            'data' => [
                'range' => $this->rangePayload($from, $to),
                'series' => $series,
                'total' => (int) Payment::query()
                    ->where('status', PaymentStatus::Paid->value)
                    ->whereBetween('paid_at', [$from, $to])
                    ->sum('amount'),
            ],
        ]);
    }

    public function growth(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $series = Tenant::query()
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'created_at'])
            ->groupBy(fn(Tenant $tenant): string => $tenant->created_at?->format('Y-m') ?? 'unknown')
            ->map(fn($rows, string $month): array => ['month' => $month, 'tenants' => $rows->count()])
            ->sortKeys()
            ->values();

        return response()->json([
            'data' => [
                'range' => $this->rangePayload($from, $to),
                'series' => $series,
                'total_tenants' => Tenant::query()->count(),
            ],
        ]);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $byPlan = Plan::query()
            ->withCount(['subscriptions as active_subscriptions' => function ($query): void {
                $query->whereIn('status', SubscriptionStatus::effectiveValues());
            }])
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn(Plan $plan): array => [
                'plan_id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'active_subscriptions' => (int) $plan->active_subscriptions,
            ]);

        $churn = Subscription::query()
            ->whereNotNull('cancelled_at')
            ->whereBetween('cancelled_at', [$from, $to])
            ->count();

        return response()->json([
            'data' => [
                'range' => $this->rangePayload($from, $to),
                'by_status' => Subscription::query()
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
                'by_plan' => $byPlan,
                'churned_in_range' => $churn,
                'tenant_status' => Tenant::query()
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
            ],
        ]);
    }

    /**
     * Default window: the last 12 months.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $request->date('from')?->startOfDay() ?? now()->subMonths(11)->startOfMonth();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();

        return [$from, $to];
    }

    /**
     * @return array{from: string, to: string}
     */
    private function rangePayload(Carbon $from, Carbon $to): array
    {
        return ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()];
    }
}
