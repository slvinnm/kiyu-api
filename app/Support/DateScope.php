<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Calendar-day filtering that stays index-friendly.
 *
 * `WHERE DATE(created_at) = ?` wraps the column in a function, so MySQL cannot
 * use an index on it and every filtered request becomes a full scan. The
 * half-open range below returns the same rows while remaining sargable.
 */
class DateScope
{
    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function apply(
        Builder|QueryBuilder $query,
        mixed $date,
        string $column = 'created_at'
    ): void {
        $day = Carbon::parse($date)->startOfDay();

        $query->where($column, '>=', $day)
            ->where($column, '<', $day->copy()->addDay());
    }
}
