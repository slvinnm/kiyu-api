<?php

namespace App\Http\Controllers\Api\App\Monitoring;

use App\Http\Controllers\Controller;
use App\Support\StationQueueSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization-wide queue monitoring for the TenantAdmin App.
 *
 * The tenant admin watches every facility; a facility is selected with
 * `facility_id` (query/body) or resolved implicitly when the tenant has only a
 * single active facility. There is no facility middleware/header.
 */
class QueueMonitorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        return response()->json([
            'data' => StationQueueSnapshot::forFacility($this->facility($request), $validated),
        ]);
    }
}
