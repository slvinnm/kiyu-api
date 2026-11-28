<?php

namespace App\Http\Controllers\Api\App\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Support\StationQueueSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization-wide queue monitoring for the TenantAdmin App.
 *
 * The tenant admin watches every facility; a facility is selected explicitly
 * with `X-Facility-Id` (authorized by the facility middleware) or one is used
 * when the tenant has only a single active facility.
 */
class QueueMonitorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $facility = $request->attributes->get('facility');

        if (! $facility instanceof Facility) {
            $facility = Facility::query()
                ->where('tenant_id', $this->tenantId())
                ->where('is_active', true)
                ->orderBy('id')
                ->firstOrFail();
        }

        return response()->json([
            'data' => StationQueueSnapshot::forFacility($facility, $validated),
        ]);
    }
}
