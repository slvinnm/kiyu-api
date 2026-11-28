<?php

namespace App\Http\Controllers\Api\Tenant\Reports;

use App\Http\Controllers\Api\Concerns\HandlesReports;
use App\Http\Controllers\Controller;
use App\Services\ExportService;
use App\Services\ReportService;
use App\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Operational reports inside the tenant workspace.
 *
 * Only staff roles reach this controller. The route decides which report
 * entitlement and `report_export` gate each endpoint; the controller owns the
 * query and the response shape. The individual report endpoints live in the
 * shared {@see HandlesReports} trait.
 */
class ReportController extends Controller
{
    use HandlesReports;

    public function __construct(
        private readonly ReportService $reports,
        private readonly ExportService $exports,
    ) {}

    protected function reports(): ReportService
    {
        return $this->reports;
    }

    protected function reportFacilityId(Request $request): int
    {
        return $this->facilityId();
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->queueOverview($request);
    }

    public function exportQueueSessions(Request $request): StreamedResponse
    {
        $range = ReportRange::resolve($request, $this->tenant());
        $tenantId = $this->tenantId();
        $facilityId = $this->facilityId();

        $filename = sprintf('queue_sessions-%s-%s.csv', $range['from'], $range['to']);

        return response()->streamDownload(function () use ($tenantId, $facilityId, $range): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['session_number', 'status', 'intake_channel', 'customer_name', 'customer_phone', 'department', 'created_at']);

            foreach ($this->reports->sessionRows($tenantId, $facilityId, $range['start'], $range['end']) as $session) {
                fputcsv($handle, [
                    $session->session_number,
                    $session->status?->value,
                    $session->intake_channel?->value,
                    $session->customer?->name,
                    $session->customer?->phone,
                    $session->department?->name,
                    $session->created_at?->toIso8601String(),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => ['required', 'string', 'in:xlsx,pdf'],
        ]);

        $range = ReportRange::resolve($request, $this->tenant());
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
                'download_url' => route('tenant.reports.download', ['path' => $path]),
                'expires_in_days' => 7,
            ],
        ], 201);
    }

    public function download(Request $request): BinaryFileResponse
    {
        $path = (string) $request->query('path', '');

        abort_if($path === '', 404, 'Export not found.');

        $absolute = $this->exports->absolutePath($this->tenantId(), $path);

        return response()->download($absolute);
    }
}
