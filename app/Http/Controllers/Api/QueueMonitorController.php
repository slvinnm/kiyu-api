<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StationQueueSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticated queue monitoring for the admin console.
 *
 * `/public/queue` serves signage screens and resolves its tenant from a device
 * credential or a public slug header, so it cannot be called with a Sanctum
 * bearer token. This endpoint returns the same read model from the tenant the
 * `tenant` middleware resolved from the authenticated user.
 */
class QueueMonitorController extends Controller
{
    /**
     * Queue status per active station in the current facility.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        return response()->json([
            'data' => StationQueueSnapshot::forFacility($this->facility(), $validated),
        ]);
    }
}
