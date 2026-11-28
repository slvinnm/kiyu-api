<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Resolve a report's requested date range into UTC instants.
 *
 * Shared by the App and Tenant report controllers so "today" always means the
 * tenant's local day, converted to the stored UTC timestamps, identically in
 * both contexts. `from`/`to` are tenant-local calendar dates; `start`/`end` are
 * the UTC instants queries compare against.
 */
class ReportRange
{
    /**
     * @return array{start: Carbon, end: Carbon, from: string, to: string}
     */
    public static function resolve(Request $request, Tenant $tenant): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $timezone = $tenant->timezone;
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
