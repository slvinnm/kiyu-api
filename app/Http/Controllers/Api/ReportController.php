<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExportService;
use App\Services\ReportService;
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
        [$from, $to] = $this->range($request);
        $tenantId = $this->tenantId();
        $facilityId = $this->facilityId();

        return response()->json([
            'data' => [
                'visits' => $this->reports->visitSummary($tenantId, $facilityId, $from, $to),
                'queue' => $this->reports->queueSummary($tenantId, $facilityId, $from, $to),
            ],
        ]);
    }

    /**
     * Stream the visit rows for the range as CSV.
     */
    public function exportVisits(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $tenantId = $this->tenantId();
        $facilityId = $this->facilityId();

        $filename = sprintf('visits-%s-%s.csv', $from->toDateString(), $to->toDateString());

        return response()->streamDownload(function () use ($tenantId, $facilityId, $from, $to): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['visit_number', 'status', 'intake_channel', 'client_mrn', 'client_name', 'department', 'created_at']);

            foreach ($this->reports->visitRows($tenantId, $facilityId, $from, $to) as $visit) {
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

        [$from, $to] = $this->range($request);
        $tenantId = $this->tenantId();
        $facilityId = $this->facilityId();

        $path = $validated['format'] === 'xlsx'
            ? $this->exports->visitsToExcel($tenantId, $facilityId, $from, $to)
            : $this->exports->visitsToPdf($tenantId, $facilityId, $from, $to);

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
     * Resolve the requested date range, defaulting to today.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : Carbon::today();
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : Carbon::today();

        return [$from, $to];
    }
}
