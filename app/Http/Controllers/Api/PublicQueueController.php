<?php

namespace App\Http\Controllers\Api;

use App\Enums\QueueTicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Support\LocalDay;
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

        $query = Station::query()
            ->whereHas('department', fn($q) => $q->where('facility_id', $this->facilityId()))
            ->where('is_active', true)
            ->with(['department:id,name,code']);

        if (! empty($validated['station_id'])) {
            $query->where('id', $validated['station_id']);
        }

        if (! empty($validated['department_id'])) {
            $query->where('department_id', $validated['department_id']);
        }

        [$dayStart, $dayEnd] = LocalDay::bounds($this->tenant()->timezone);

        $stations = $query->get()->map(function (Station $station) use ($dayStart, $dayEnd): array {
            // Get currently active ticket (CALLED or IN_PROGRESS)
            $currentTicket = $station->queueTickets()
                ->whereIn('status', [QueueTicketStatus::Called, QueueTicketStatus::InProgress])
                ->latest('updated_at')
                ->select(['id', 'station_id', 'queue_number', 'status', 'called_at', 'started_at'])
                ->first();

            // Get count of waiting tickets
            $waitingCount = $station->queueTickets()
                ->where('status', QueueTicketStatus::Created)
                ->count();

            // Next 5 upcoming tickets (scope to station and today for clean numbering)
            $upcomingTickets = $station->queueTickets()
                ->where('status', QueueTicketStatus::Created)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
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
