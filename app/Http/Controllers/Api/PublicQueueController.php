<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StationQueueSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicQueueController extends Controller
{
    /**
     * Display public queue status without revealing any PII.
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
