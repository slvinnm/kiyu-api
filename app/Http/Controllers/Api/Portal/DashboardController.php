<?php

namespace App\Http\Controllers\Api\Portal;

use App\Enums\Feature;
use App\Enums\QueueSessionStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Facility;
use App\Models\QueueSession;
use App\Models\Station;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TenantAdmin organization overview in the Platform Portal (PRD §8.2).
 *
 * Organization-wide monitoring, not queue calling: TenantAdmin watches every
 * Facility of its Tenant with Facility filters. Operational calling stays in the
 * tenant workspace.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
            'date' => ['nullable', 'date'],
        ]);

        $tenant = $this->tenant();
        $date = $validated['date'] ?? now()->toDateString();

        $facilityQuery = Facility::query()->where('tenant_id', $tenant->id);
        if (! empty($validated['facility_id'])) {
            $facilityQuery->whereKey($validated['facility_id']);
        }
        $facilityIds = $facilityQuery->pluck('id');

        $sessionsToday = QueueSession::query()
            ->whereIn('facility_id', $facilityIds)
            ->whereDate('created_at', $date);

        $statusCounts = (clone $sessionsToday)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $subscription = $this->subscriptions->effectiveSubscription($tenant);

        return response()->json([
            'data' => [
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status?->value,
                ],
                'counts' => [
                    'facilities' => $facilityIds->count(),
                    'departments' => Department::query()->whereIn('facility_id', $facilityIds)->count(),
                    'stations' => Station::query()
                        ->whereIn('department_id', Department::query()->whereIn('facility_id', $facilityIds)->select('id'))
                        ->count(),
                    'customers' => Customer::query()->where('tenant_id', $tenant->id)->count(),
                    'sessions_today' => (clone $sessionsToday)->count(),
                    'waiting' => (int) ($statusCounts[QueueSessionStatus::Waiting->value] ?? 0),
                    'in_progress' => (int) ($statusCounts[QueueSessionStatus::InProgress->value] ?? 0),
                    'completed' => (int) ($statusCounts[QueueSessionStatus::Completed->value] ?? 0),
                ],
                'channels' => (clone $sessionsToday)
                    ->selectRaw('intake_channel, COUNT(*) as total')
                    ->groupBy('intake_channel')
                    ->pluck('total', 'intake_channel'),
                'subscription' => $subscription === null ? null : [
                    'id' => $subscription->id,
                    'status' => $subscription->status?->value,
                    'plan' => $subscription->plan?->only(['id', 'code', 'name']),
                    'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                    'is_effective' => $subscription->isEffective(),
                ],
                'usage' => [
                    'facilities' => $facilityIds->count(),
                    'max_facilities' => $this->subscriptions->limit($tenant, Feature::MaxFacilities),
                    'kiosk_devices' => $this->subscriptions->usage($tenant, Feature::MaxKioskDevices),
                    'max_kiosk_devices' => $this->subscriptions->limit($tenant, Feature::MaxKioskDevices),
                ],
            ],
        ]);
    }
}
