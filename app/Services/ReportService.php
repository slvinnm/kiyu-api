<?php

namespace App\Services;

use App\Models\QueueSession;
use App\Models\QueueTicket;
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
    public function sessionSummary(int $tenantId, int $facilityId, Carbon $from, Carbon $to, ?array $period = null): array
    {
        $sessions = QueueSession::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->whereBetween('created_at', [$from, $to]);

        $byStatus = (clone $sessions)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byChannel = (clone $sessions)
            ->selectRaw('intake_channel, COUNT(*) AS total')
            ->groupBy('intake_channel')
            ->pluck('total', 'intake_channel');

        $total = (clone $sessions)->count();
        $completed = (int) ($byStatus['COMPLETED'] ?? 0);

        $wait = $this->averageWaitMinutes($tenantId, $facilityId, $from, $to);

        return [
            'period' => $period ?? ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_sessions' => $total,
            'completed_sessions' => $completed,
            'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : 0.0,
            'by_status' => $byStatus,
            'by_channel' => $byChannel,
            // Waiting time is ticket-created → first-called. Tickets never called
            // are excluded from the average and counted separately.
            'average_wait_minutes' => $wait['average_minutes'],
            'served_tickets' => $wait['served_tickets'],
            'never_called_tickets' => $wait['never_called'],
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
            ->whereHas('queueSession', fn($query) => $query
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
     * @return LazyCollection<int, QueueSession>
     */
    public function sessionRows(int $tenantId, int $facilityId, Carbon $from, Carbon $to): LazyCollection
    {
        return QueueSession::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->whereBetween('created_at', [$from, $to])
            ->with(['customer:id,name,phone', 'department:id,code,name'])
            ->orderBy('id')
            ->lazyById();
    }

    /**
     * Mean waiting time: ticket created until it was first called.
     *
     * This is the real queue wait — time a customer spent waiting to be served,
     * not the check-in latency. Tickets that were never called are excluded from
     * the average; their count is reported separately so a long tail of never-
     * served tickets is not hidden inside a small average.
     *
     * @return array{average_minutes: float|null, served_tickets: int, never_called: int}
     */
    private function averageWaitMinutes(int $tenantId, int $facilityId, Carbon $from, Carbon $to): array
    {
        $tickets = QueueTicket::query()
            ->whereHas('queueSession', fn($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId))
            ->whereBetween('created_at', [$from, $to]);

        $called = (clone $tickets)->whereNotNull('called_at');

        $average = (clone $called)
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, called_at)) AS minutes')
            ->value('minutes');

        return [
            'average_minutes' => $average === null ? null : round((float) $average, 1),
            'served_tickets' => (clone $called)->count(),
            'never_called' => (clone $tickets)->whereNull('called_at')->count(),
        ];
    }
}
