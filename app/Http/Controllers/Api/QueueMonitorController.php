<?php

namespace App\Http\Controllers\Api;

use App\Enums\QueueTicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Station;
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

        $query = Station::query()
            ->whereHas('department', fn ($q) => $q->where('facility_id', $this->facilityId()))
            ->where('is_active', true)
            ->with(['department:id,name,code']);

        if (! empty($validated['station_id'])) {
            $query->where('id', $validated['station_id']);
        }

        if (! empty($validated['department_id'])) {
            $query->where('department_id', $validated['department_id']);
        }

        $stations = $query->get()->map(function (Station $station): array {
            $currentTicket = $station->queueTickets()
                ->whereIn('status', [QueueTicketStatus::Called, QueueTicketStatus::InProgress])
                ->latest('updated_at')
                ->select(['id', 'station_id', 'queue_number', 'status', 'called_at', 'started_at'])
                ->first();

            $waitingCount = $station->queueTickets()
                ->where('status', QueueTicketStatus::Created)
                ->count();

            $upcomingTickets = $station->queueTickets()
                ->where('status', QueueTicketStatus::Created)
                ->whereDate('created_at', now()->toDateString())
                ->orderBy('priority', 'desc')
                ->orderBy('internal_sequence', 'asc')
                ->limit(5)
                ->pluck('queue_number');

            return [
                'station_id' => $station->id,
                'station_name' => $station->name,
                'station_code' => $station->code,
                'station_type' => $station->type->value,
                'department_id' => $station->department_id,
                'department_name' => $station->department?->name,
                'department_code' => $station->department?->code,
                'current_calling' => $currentTicket ? [
                    'queue_number' => $currentTicket->queue_number,
                    'status' => $currentTicket->status->value,
                    'called_at' => $currentTicket->called_at?->toIso8601String(),
                ] : null,
                'waiting_count' => $waitingCount,
                'upcoming_queue_numbers' => $upcomingTickets,
            ];
        });

        return response()->json([
            'data' => $stations,
        ]);
    }
}
