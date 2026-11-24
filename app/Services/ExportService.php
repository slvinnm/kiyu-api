<?php

namespace App\Services;

use App\Exports\VisitsExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Generates report exports on disk.
 *
 * Files live under the private exports disk, one directory per tenant, so a
 * tenant can only ever reach its own files. A daily scheduled command purges
 * old files so the server does not fill up.
 */
class ExportService
{
    private const DISK = 'local';

    private const DIRECTORY = 'exports';

    public function __construct(
        private readonly ReportService $reports,
    ) {}

    /**
     * Export the visit rows for the range to Excel, returning the stored path.
     */
    public function visitsToExcel(
        int $tenantId,
        int $facilityId,
        Carbon $from,
        Carbon $to,
        ?string $reportFrom = null,
        ?string $reportTo = null,
    ): string {
        $rows = $this->visitRows($tenantId, $facilityId, $from, $to);
        $path = $this->pathFor($tenantId, 'xlsx');

        Excel::store(
            new VisitsExport($rows),
            $path,
            self::DISK,
            \Maatwebsite\Excel\Excel::XLSX,
        );

        return $path;
    }

    /**
     * Export the visit summary to PDF, returning the stored path.
     */
    public function visitsToPdf(
        int $tenantId,
        int $facilityId,
        Carbon $from,
        Carbon $to,
        ?string $reportFrom = null,
        ?string $reportTo = null,
    ): string {
        $period = [
            'from' => $reportFrom ?? $from->toDateString(),
            'to' => $reportTo ?? $to->toDateString(),
        ];

        $summary = $this->reports->visitSummary($tenantId, $facilityId, $from, $to, $period);
        $rows = $this->visitRows($tenantId, $facilityId, $from->copy(), $to->copy());

        $pdf = Pdf::loadView('reports.visits', [
            'summary' => $summary,
            'rows' => $rows,
            'from' => $period['from'],
            'to' => $period['to'],
        ]);

        $path = $this->pathFor($tenantId, 'pdf');

        Storage::disk(self::DISK)->put($path, $pdf->output());

        return $path;
    }

    /**
     * The absolute path of a stored export, scoped to the tenant.
     */
    public function absolutePath(int $tenantId, string $path): string
    {
        // The trailing separator is required: without it `exports/1` is also a
        // prefix of `exports/12`, so tenant 1 could read tenant 12's files.
        abort_unless(
            str_starts_with($path, $this->tenantDirectory($tenantId) . '/'),
            403,
            'Forbidden.',
        );

        abort_if(str_contains($path, '..'), 403, 'Forbidden.');

        abort_unless(Storage::disk(self::DISK)->exists($path), 404, 'Export not found.');

        return Storage::disk(self::DISK)->path($path);
    }

    /**
     * Delete every export older than the given number of days.
     *
     * @return int Number of files removed.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = now()->subDays($days)->getTimestamp();
        $disk = Storage::disk(self::DISK);
        $removed = 0;

        foreach ($disk->allDirectories(self::DIRECTORY) as $directory) {
            foreach ($disk->files($directory) as $file) {
                if ($disk->lastModified($file) < $cutoff) {
                    $disk->delete($file);
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * Normalised rows shared by the Excel and PDF exports.
     *
     * @return Collection<int, array<int, string|null>>
     */
    private function visitRows(int $tenantId, int $facilityId, Carbon $from, Carbon $to): Collection
    {
        return collect($this->reports->visitRows($tenantId, $facilityId, $from, $to))
            ->map(fn($visit): array => [
                $visit->visit_number,
                $visit->status?->value,
                $visit->intake_channel?->value,
                $visit->client?->mrn,
                $visit->client?->name,
                $visit->department?->name,
                $visit->created_at?->toIso8601String(),
            ]);
    }

    /**
     * A unique stored path for a new export.
     */
    private function pathFor(int $tenantId, string $extension): string
    {
        return sprintf(
            '%s/%s-%d.%s',
            $this->tenantDirectory($tenantId),
            now()->format('Ymd-His'),
            random_int(1000, 9999),
            $extension,
        );
    }

    /**
     * The per-tenant export directory.
     */
    private function tenantDirectory(int $tenantId): string
    {
        return self::DIRECTORY . '/' . $tenantId;
    }
}
