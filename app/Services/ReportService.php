<?php

namespace App\Services;

use App\Models\QueueTicket;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;

/**
 * Operational reports for a tenant, scoped by facility and date range.
 *
 * One query shape backs both the JSON summary and the CSV export so the two
 * can never disagree.
 */
class ReportService
{
    /**
     * A visit-flow summary for the range.
     *
     * @return array<string, mixed>
     */
    public function visitSummary(int $tenantId, int $facilityId, Carbon $from, Carbon $to, ?array $period = null): array
    {
        $visits = Visit::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->whereBetween('created_at', [$from, $to]);

        $byStatus = (clone $visits)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byChannel = (clone $visits)
            ->selectRaw('intake_channel, COUNT(*) AS total')
            ->groupBy('intake_channel')
            ->pluck('total', 'intake_channel');

        $total = (clone $visits)->count();
        $completed = (int) ($byStatus['COMPLETED'] ?? 0);

        return [
            'period' => $period ?? ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_visits' => $total,
            'completed_visits' => $completed,
            'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : 0.0,
            'by_status' => $byStatus,
            'by_channel' => $byChannel,
            'average_wait_minutes' => $this->averageWaitMinutes($tenantId, $facilityId, $from, $to),
        ];
    }

    /**
     * A queue-performance summary for the range.
     *
     * @return array<string, mixed>
     */
    public function queueSummary(int $tenantId, int $facilityId, Carbon $from, Carbon $to, ?array $period = null): array
    {
        $tickets = QueueTicket::query()
            ->whereHas('visit', fn($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId))
            ->whereBetween('created_at', [$from, $to]);

        $byStatus = (clone $tickets)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $served = (clone $tickets)->whereIn('status', ['COMPLETED', 'SKIPPED', 'NO_SHOW'])->count();
        $skipped = (clone $tickets)->where('status', 'SKIPPED')->count();
        $noShow = (clone $tickets)->where('status', 'NO_SHOW')->count();

        return [
            'period' => $period ?? ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_tickets' => (clone $tickets)->count(),
            'served' => $served,
            'skipped' => $skipped,
            'no_show' => $noShow,
            'no_show_rate' => $served > 0 ? round($noShow / $served * 100, 1) : 0.0,
            'by_status' => $byStatus,
            // Group by the ticket's actual station, not the workflow step's
            // configured station: a transferred ticket serves at a different
            // station while keeping its original step.
            'by_station' => (clone $tickets)
                ->selectRaw('station_id, COUNT(*) AS total')
                ->groupBy('station_id')
                ->pluck('total', 'station_id'),
        ];
    }

    /**
     * Rows for the visit CSV export.
     *
     * @return LazyCollection<int, Visit>
     */
    public function visitRows(int $tenantId, int $facilityId, Carbon $from, Carbon $to): LazyCollection
    {
        return Visit::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->whereBetween('created_at', [$from, $to])
            ->with(['client:id,name,mrn', 'department:id,code,name'])
            ->orderBy('id')
            ->lazyById();
    }

    /**
     * Mean minutes between visit creation and check-in, where known.
     */
    private function averageWaitMinutes(int $tenantId, int $facilityId, Carbon $from, Carbon $to): ?float
    {
        // Aggregated in SQL: loading every visit just to average two columns
        // grows linearly with the reporting range.
        $average = Visit::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->whereNotNull('checked_in_at')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, checked_in_at)) AS minutes')
            ->value('minutes');

        return $average === null ? null : round((float) $average, 1);
    }
}
