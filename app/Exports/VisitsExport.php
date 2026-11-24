<?php

namespace App\Exports;

use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * A flat visits export. Rows are pre-collected by the caller so the export
 * and the on-screen report share one query shape.
 */
class VisitsExport implements FromGenerator, ShouldAutoSize, WithHeadings
{
    /**
     * @param  iterable<int, array<int, string|null>>  $rows
     */
    public function __construct(private readonly iterable $rows) {}

    /**
     * Stream the rows instead of materialising the whole range: a year-long
     * export must not need the entire visit table in memory.
     *
     * {@inheritDoc}
     */
    public function generator(): Generator
    {
        yield from $this->rows;
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
