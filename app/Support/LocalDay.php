<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * The clinic's calendar day.
 *
 * Timestamps are stored in the app timezone (UTC), but a clinic operates in
 * its own timezone. Counting a "day" in UTC would roll the visit and queue
 * counters over at 07:00 in Jakarta, putting the first seven hours of every
 * morning into the previous day's sequence. Everything that asks "today"
 * goes through this class so the answer is the clinic's day, converted to a
 * UTC range for the stored timestamps.
 */
final class LocalDay
{
    /**
     * The current time in the given timezone.
     */
    public static function now(?string $timezone): Carbon
    {
        return Carbon::now($timezone ?: (string) config('app.timezone'));
    }

    /**
     * The UTC instant range covering the clinic's current calendar day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function bounds(?string $timezone): array
    {
        $start = self::now($timezone)->startOfDay()->setTimezone((string) config('app.timezone'));
        $end = $start->copy()->addDay()->subSecond();

        return [$start, $end];
    }

    /**
     * The clinic-local date as Y-m-d (used as the counter key).
     */
    public static function date(?string $timezone): string
    {
        return self::now($timezone)->format('Y-m-d');
    }

    /**
     * The clinic-local date as Ymd (used inside generated identifiers).
     */
    public static function compactDate(?string $timezone): string
    {
        return self::now($timezone)->format('Ymd');
    }

    /**
     * Resolve the timezone a tenant operates in.
     */
    public static function forTenant(?Tenant $tenant): ?string
    {
        return $tenant?->timezone ?: null;
    }

    /**
     * Convert a clinic-local calendar date to the UTC start/end instant pair.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function boundsForDate(string $date, ?string $timezone): array
    {
        $appTimezone = (string) config('app.timezone');

        $start = Carbon::createFromFormat('Y-m-d', $date, $timezone ?: $appTimezone)
            ->startOfDay()
            ->setTimezone($appTimezone);

        return [$start, $start->copy()->addDay()->subSecond()];
    }
}
