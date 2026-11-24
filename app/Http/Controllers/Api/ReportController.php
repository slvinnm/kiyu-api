<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\LocalDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Operational reports. JSON summaries are gated by basic_reports; the exports
 * (CSV / Excel / PDF) are an advanced feature (advanced_reports), enforced in
 * routes.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ExportService $exports,
    ) {}

    /**
     * A JSON summary of visits and queue performance for the range.
     */
    public function summary(Request $request): JsonResponse
    {
        $range = $this->range($request);
        $tenantId = $this->tenantId();
        $facilityId = $this->facilityId();
        $period = ['from' => $range['from'], 'to' => $range['to']];

        return response()->json([
            'data' => [
                'visits' => $this->reports->visitSummary($tenantId, $facilityId, $range['start'], $range['end'], $period),
                'queue' => $this->reports->queueSummary($tenantId, $facilityId, $range['start'], $range['end'], $period),
            ],
        ]);
    }

    /**
     * Stream the visit rows for the range as CSV.
     */
    public function exportVisits(Request $request): StreamedResponse
    {
        $range = $this->range($request);
        $tenantId = $this->tenantId();
        $facilityId = $this->facilityId();

        $filename = sprintf('visits-%s-%s.csv', $range['from'], $range['to']);

        return response()->streamDownload(function () use ($tenantId, $facilityId, $range): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['visit_number', 'status', 'intake_channel', 'client_mrn', 'client_name', 'department', 'created_at']);

            foreach ($this->reports->visitRows($tenantId, $facilityId, $range['start'], $range['end']) as $visit) {
                fputcsv($handle, [
                    $visit->visit_number,
                    $visit->status?->value,
                    $visit->intake_channel?->value,
                    $visit->client?->mrn,
                    $visit->client?->name,
                    $visit->department?->name,
                    $visit->created_at?->toIso8601String(),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Generate an Excel or PDF export, store it, and return a one-time
     * download URL. The file is deleted by the daily purge command.
     */
    public function exportFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => ['required', 'string', 'in:xlsx,pdf'],
        ]);

        $range = $this->range($request);
        $tenantId = $this->tenantId();
        $facilityId = $this->facilityId();

        $path = $validated['format'] === 'xlsx'
            ? $this->exports->visitsToExcel($tenantId, $facilityId, $range['start'], $range['end'], $range['from'], $range['to'])
            : $this->exports->visitsToPdf($tenantId, $facilityId, $range['start'], $range['end'], $range['from'], $range['to']);

        return response()->json([
            'message' => 'Export generated.',
            'data' => [
                'path' => $path,
                'format' => $validated['format'],
                'download_url' => route('reports.download', ['path' => $path]),
                'expires_in_days' => 7,
            ],
        ], 201);
    }

    /**
     * Download a previously generated export, scoped to the tenant.
     */
    public function download(Request $request): BinaryFileResponse
    {
        $path = (string) $request->query('path', '');

        abort_if($path === '', 404, 'Export not found.');

        $absolute = $this->exports->absolutePath($this->tenantId(), $path);

        return response()->download($absolute);
    }

    /**
     * Resolve the requested date range, defaulting to the clinic's today.
     *
     * `from`/`to` are the clinic-local calendar dates the caller asked for;
     * `start`/`end` are the UTC instants the queries compare against.
     *
     * @return array{start: Carbon, end: Carbon, from: string, to: string}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $timezone = $this->tenant()->timezone;
        $today = LocalDay::date($timezone);

        $from = $validated['from'] ?? $today;
        $to = $validated['to'] ?? $today;

        return [
            'start' => LocalDay::boundsForDate($from, $timezone)[0],
            'end' => LocalDay::boundsForDate($to, $timezone)[1],
            'from' => Carbon::parse($from)->toDateString(),
            'to' => Carbon::parse($to)->toDateString(),
        ];
    }
}
