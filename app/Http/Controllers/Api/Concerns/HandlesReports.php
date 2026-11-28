<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Services\ReportService;
use App\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The shared report endpoints (PRD §12).
 *
 * Both the App and Tenant report controllers use this trait so the report
 * queries and response shapes are identical; the route decides entitlement and
 * export gates. The facility id is the resolved facility for the request.
 */
trait HandlesReports
{
    abstract protected function reports(): ReportService;

    /**
     * The facility id the report is scoped to.
     */
    abstract protected function reportFacilityId(Request $request): int;

    private function reportMeta(Request $request, ReportService $reports): array
    {
        $range = ReportRange::resolve($request, $this->tenant());

        return [
            'range' => $range,
            'tenantId' => $this->tenantId(),
            'facilityId' => $this->reportFacilityId($request),
            'timezone' => $this->tenant()->timezone,
        ];
    }

    public function queueOverview(Request $request): JsonResponse
    {
        $r = $this->reportMeta($request, $this->reports());

        return response()->json(['data' => [
            'period' => $this->period($r['range']),
            'queue_sessions' => $this->reports()->sessionSummary($r['tenantId'], $r['facilityId'], $r['range']['start'], $r['range']['end'], $this->period($r['range'])),
            'queue' => $this->reports()->queueSummary($r['tenantId'], $r['facilityId'], $r['range']['start'], $r['range']['end'], $this->period($r['range'])),
        ]]);
    }

    public function dailyQueue(Request $request): JsonResponse
    {
        $r = $this->reportMeta($request, $this->reports());

        return response()->json(['data' => [
            'period' => $this->period($r['range']),
            'days' => $this->reports()->dailyQueue($r['tenantId'], $r['facilityId'], $r['range']['start'], $r['range']['end'], $r['timezone']),
        ]]);
    }

    public function visitDetails(Request $request): JsonResponse
    {
        $r = $this->reportMeta($request, $this->reports());

        // Bounded list; export exists for large ranges.
        $rows = $this->reports()->visitDetails($r['tenantId'], $r['facilityId'], $r['range']['start'], $r['range']['end'])
            ->take(500)
            ->values();

        return response()->json(['data' => [
            'period' => $this->period($r['range']),
            'visits' => $rows,
        ]]);
    }

    public function departmentPerformance(Request $request): JsonResponse
    {
        $r = $this->reportMeta($request, $this->reports());

        return response()->json(['data' => [
            'period' => $this->period($r['range']),
            'departments' => $this->reports()->departmentPerformance($r['tenantId'], $r['facilityId'], $r['range']['start'], $r['range']['end']),
        ]]);
    }

    public function waitingTime(Request $request): JsonResponse
    {
        $r = $this->reportMeta($request, $this->reports());
        $summary = $this->reports()->sessionSummary($r['tenantId'], $r['facilityId'], $r['range']['start'], $r['range']['end'], $this->period($r['range']));

        return response()->json(['data' => [
            'period' => $this->period($r['range']),
            'average_wait_minutes' => $summary['average_wait_minutes'],
            'served_tickets' => $summary['served_tickets'],
            'never_called_tickets' => $summary['never_called_tickets'],
        ]]);
    }

    public function serviceDuration(Request $request): JsonResponse
    {
        $r = $this->reportMeta($request, $this->reports());

        return response()->json(['data' => [
            'period' => $this->period($r['range']),
            ...$this->reports()->serviceDuration($r['tenantId'], $r['facilityId'], $r['range']['start'], $r['range']['end']),
        ]]);
    }

    public function queueTrends(Request $request): JsonResponse
    {
        $r = $this->reportMeta($request, $this->reports());

        return response()->json(['data' => [
            'period' => $this->period($r['range']),
            'days' => $this->reports()->queueTrends($r['tenantId'], $r['facilityId'], $r['range']['start'], $r['range']['end'], $r['timezone']),
        ]]);
    }

    public function peakHours(Request $request): JsonResponse
    {
        $r = $this->reportMeta($request, $this->reports());

        return response()->json(['data' => [
            'period' => $this->period($r['range']),
            'hours' => $this->reports()->peakHours($r['tenantId'], $r['facilityId'], $r['range']['start'], $r['range']['end'], $r['timezone']),
        ]]);
    }

    public function cancellationNoShow(Request $request): JsonResponse
    {
        $r = $this->reportMeta($request, $this->reports());

        return response()->json(['data' => [
            'period' => $this->period($r['range']),
            ...$this->reports()->cancellationNoShow($r['tenantId'], $r['facilityId'], $r['range']['start'], $r['range']['end']),
        ]]);
    }

    public function staffPerformance(Request $request): JsonResponse
    {
        $r = $this->reportMeta($request, $this->reports());

        return response()->json(['data' => [
            'period' => $this->period($r['range']),
            'staff' => $this->reports()->staffPerformance($r['tenantId'], $r['facilityId'], $r['range']['start'], $r['range']['end']),
        ]]);
    }

    public function facilityComparison(Request $request): JsonResponse
    {
        $range = ReportRange::resolve($request, $this->tenant());
        $tenantId = $this->tenantId();

        // All active facilities of the tenant (never another tenant's).
        $facilityIds = $this->tenant()->facilities()->where('is_active', true)->pluck('id')->all();

        return response()->json(['data' => [
            'period' => $this->period($range),
            'facilities' => $this->reports()->facilityComparison($tenantId, $facilityIds, $range['start'], $range['end']),
        ]]);
    }

    /**
     * @param  array{start: Carbon, end: Carbon, from: string, to: string}  $range
     * @return array{from: string, to: string}
     */
    private function period(array $range): array
    {
        return ['from' => $range['from'], 'to' => $range['to']];
    }
}
