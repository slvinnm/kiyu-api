<?php

namespace App\Support;

use App\Enums\QueueTicketStatus;
use App\Models\Facility;
use App\Models\Station;
use Illuminate\Support\Collection;

/**
 * The per-station queue snapshot used by both queue displays.
 *
 * The public signage screen and the authenticated admin monitor show the same
 * thing for the same facility; one implementation keeps them from drifting.
 * Nothing personal is included: station names, queue numbers and counts only.
 */
final class StationQueueSnapshot
{
    /**
     * Build the snapshot for every active station in a facility.
     *
     * @param  array{station_id?: int|null, department_id?: int|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public static function forFacility(Facility $facility, array $filters = []): Collection
    {
        $query = Station::query()
            ->whereHas('department', fn($query) => $query->where('facility_id', $facility->id))
            ->where('is_active', true)
            ->with(['department:id,name,code']);

        if (! empty($filters['station_id'])) {
            $query->whereKey($filters['station_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        [$dayStart, $dayEnd] = LocalDay::bounds($facility->timezone ?: $facility->tenant?->timezone);

        return $query->get()->map(
            fn(Station $station): array => self::forStation($station, $dayStart, $dayEnd),
        );
    }

    /**
     * Snapshot for a single station within the given local day bounds.
     *
     * @return array<string, mixed>
     */
    private static function forStation(Station $station, mixed $dayStart, mixed $dayEnd): array
    {
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
    }
}
