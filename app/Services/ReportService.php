<?php

namespace App\Services;

use App\Models\QueueEvent;
use App\Models\QueueSession;
use App\Models\QueueTicket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
     * Daily queue volume by tenant-local day (PRD §12 Daily Queue).
     *
     * Groups by the tenant timezone's calendar day, not the UTC timestamp day.
     *
     * @return array<int, array{date: string, total: int, completed: int}>
     */
    public function dailyQueue(int $tenantId, int $facilityId, Carbon $from, Carbon $to, string $timezone): array
    {
        $sessions = QueueSession::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->whereBetween('created_at', [$from, $to])
            ->get(['created_at', 'status']);

        return $sessions
            ->groupBy(fn(QueueSession $s): string => $s->created_at->copy()->setTimezone($timezone)->toDateString())
            ->map(fn(Collection $group, string $date): array => [
                'date' => $date,
                'total' => $group->count(),
                'completed' => $group->where('status', 'COMPLETED')->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * Visit detail rows (PRD §12 Visit Details).
     *
     * @return LazyCollection<int, QueueSession>
     */
    public function visitDetails(int $tenantId, int $facilityId, Carbon $from, Carbon $to): LazyCollection
    {
        return QueueSession::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->whereBetween('created_at', [$from, $to])
            ->with(['customer:id,name,phone,email', 'department:id,code,name'])
            ->orderByDesc('created_at')
            ->lazyById();
    }

    /**
     * Department performance: volume and completion per department (PRD §12).
     *
     * @return array<int, array{department_id: int, department: string, total: int, completed: int, completion_rate: float}>
     */
    public function departmentPerformance(int $tenantId, int $facilityId, Carbon $from, Carbon $to): array
    {
        $sessions = QueueSession::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->whereBetween('created_at', [$from, $to])
            ->with('department:id,name')
            ->get(['id', 'department_id', 'status']);

        return $sessions
            ->groupBy('department_id')
            ->map(function (Collection $group, $departmentId): array {
                $total = $group->count();
                $completed = $group->where('status', 'COMPLETED')->count();

                return [
                    'department_id' => (int) $departmentId,
                    'department' => optional($group->first()->department)->name,
                    'total' => $total,
                    'completed' => $completed,
                    'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * Service duration: mean started→completed per completed ticket (PRD §12).
     *
     * @return array{average_minutes: float|null, measured_tickets: int}
     */
    public function serviceDuration(int $tenantId, int $facilityId, Carbon $from, Carbon $to): array
    {
        $tickets = QueueTicket::query()
            ->whereHas('queueSession', fn($q) => $q
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId))
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at');

        $average = (clone $tickets)
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at)) AS minutes')
            ->value('minutes');

        return [
            'average_minutes' => $average === null ? null : round((float) $average, 1),
            'measured_tickets' => (clone $tickets)->count(),
        ];
    }

    /**
     * Queue trends: daily totals over the range (PRD §12 Queue Trends).
     *
     * @return array<int, array{date: string, tickets: int}>
     */
    public function queueTrends(int $tenantId, int $facilityId, Carbon $from, Carbon $to, string $timezone): array
    {
        $tickets = QueueTicket::query()
            ->whereHas('queueSession', fn($q) => $q
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId))
            ->whereBetween('created_at', [$from, $to])
            ->get(['created_at']);

        return $tickets
            ->groupBy(fn(QueueTicket $t): string => $t->created_at->copy()->setTimezone($timezone)->toDateString())
            ->map(fn(Collection $group, string $date): array => [
                'date' => $date,
                'tickets' => $group->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * Peak hours: ticket volume by tenant-local hour (PRD §12 Peak Hours).
     *
     * @return array<int, array{hour: int, tickets: int}>
     */
    public function peakHours(int $tenantId, int $facilityId, Carbon $from, Carbon $to, string $timezone): array
    {
        $tickets = QueueTicket::query()
            ->whereHas('queueSession', fn($q) => $q
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId))
            ->whereBetween('created_at', [$from, $to])
            ->get(['created_at']);

        $byHour = $tickets
            ->groupBy(fn(QueueTicket $t): int => (int) $t->created_at->copy()->setTimezone($timezone)->format('G'))
            ->map(fn(Collection $group, $hour): array => [
                'hour' => (int) $hour,
                'tickets' => $group->count(),
            ]);

        // Fill every hour so a chart has a contiguous 0..23 series.
        $series = [];
        for ($h = 0; $h < 24; $h++) {
            $series[] = $byHour->get($h, ['hour' => $h, 'tickets' => 0]);
        }

        return $series;
    }

    /**
     * Cancellation and no-show, with separately reported categories (PRD §12).
     *
     * @return array{cancelled: int, skipped: int, no_show: int, completed: int, denominator: int, cancellation_rate: float}
     */
    public function cancellationNoShow(int $tenantId, int $facilityId, Carbon $from, Carbon $to): array
    {
        $tickets = QueueTicket::query()
            ->whereHas('queueSession', fn($q) => $q
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId))
            ->whereBetween('created_at', [$from, $to]);

        $counts = (clone $tickets)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $skipped = (int) ($counts['SKIPPED'] ?? 0);
        $noShow = (int) ($counts['NO_SHOW'] ?? 0);
        $cancelled = (int) ($counts['CANCELLED'] ?? 0);
        $completed = (int) ($counts['COMPLETED'] ?? 0);

        // Denominator: every ticket that reached a terminal outcome.
        $denominator = $skipped + $noShow + $cancelled + $completed;
        $bad = $skipped + $noShow + $cancelled;

        return [
            'cancelled' => $cancelled,
            'skipped' => $skipped,
            'no_show' => $noShow,
            'completed' => $completed,
            'denominator' => $denominator,
            'cancellation_rate' => $denominator > 0 ? round($bad / $denominator * 100, 1) : 0.0,
        ];
    }

    /**
     * Staff performance: completed tickets attributed to the acting user (PRD §12).
     *
     * Attribution uses the user recorded on the ticket's completion event, not
     * `assigned_to_user_id` (assignment is not proof of service).
     *
     * @return array<int, array{user_id: int|null, user: string|null, completed: int}>
     */
    public function staffPerformance(int $tenantId, int $facilityId, Carbon $from, Carbon $to): array
    {
        $events = QueueEvent::query()
            ->where('to_status', 'COMPLETED')
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('queueTicket.queueSession', fn($q) => $q
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId))
            ->with('queueTicket.queueSession', 'queueTicket')
            ->get(['id', 'queue_ticket_id', 'user_id']);

        // Names for the recorded actors.
        $names = User::query()
            ->whereIn('id', $events->pluck('user_id')->filter()->unique())
            ->pluck('name', 'id');

        return $events
            ->groupBy('user_id')
            ->map(fn(Collection $group, $userId): array => [
                'user_id' => $userId === '' ? null : (int) $userId,
                'user' => $userId !== '' ? $names[$userId] ?? null : null,
                'completed' => $group->count(),
            ])
            ->sortByDesc('completed')
            ->values()
            ->all();
    }

    /**
     * Facility comparison across the tenant's facilities (PRD §12).
     *
     * Never crosses the tenant boundary. Requires at least two facilities with
     * data; a single facility still returns its own row.
     *
     * @param  list<int>  $facilityIds
     * @return array<int, array{facility_id: int, facility: string, total: int, completed: int, average_wait_minutes: float|null}>
     */
    public function facilityComparison(int $tenantId, array $facilityIds, Carbon $from, Carbon $to): array
    {
        return QueueSession::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('facility_id', $facilityIds)
            ->whereBetween('created_at', [$from, $to])
            ->with('facility:id,name')
            ->get(['id', 'facility_id', 'status'])
            ->groupBy('facility_id')
            ->map(function (Collection $group, $facilityId) use ($tenantId, $from, $to): array {
                $total = $group->count();
                $completed = $group->where('status', 'COMPLETED')->count();
                $wait = $this->averageWaitMinutes($tenantId, (int) $facilityId, $from, $to);

                return [
                    'facility_id' => (int) $facilityId,
                    'facility' => optional($group->first()->facility)->name,
                    'total' => $total,
                    'completed' => $completed,
                    'average_wait_minutes' => $wait['average_minutes'],
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
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
