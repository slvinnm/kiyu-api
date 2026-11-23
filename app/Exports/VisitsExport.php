<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * A flat visits export. Rows are pre-collected by the caller so the export
 * and the on-screen report share one query shape.
 */
class VisitsExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function __construct(private readonly Collection $rows) {}

    /**
     * {@inheritDoc}
     */
    public function collection(): Collection
    {
        return $this->rows;
    }

    /**
     * {@inheritDoc}
     */
    public function headings(): array
    {
        return [
            'Visit Number',
            'Status',
            'Channel',
            'Client MRN',
            'Client Name',
            'Department',
            'Created At',
        ];
    }
}
