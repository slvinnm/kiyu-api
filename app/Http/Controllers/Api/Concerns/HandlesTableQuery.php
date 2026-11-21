<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared validation and ordering for the list endpoints behind the frontend
 * DataTable. Every table sends search/sort/direction/page/per_page, so the
 * rules live here once instead of drifting between controllers.
 */
trait HandlesTableQuery
{
    /**
     * Rules for the params every table sends. Endpoint-specific filters are
     * merged in by the caller.
     *
     * `per_page` is validated for type only and clamped afterwards: it is a
     * presentation hint, so a value outside 1..100 is normalised rather than
     * rejected. `search` is capped because an unbounded `LIKE` pattern is not
     * useful and can be used to force full scans.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function validateTableQuery(Request $request, array $extra = []): array
    {
        return $request->validate(array_merge([
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer'],
        ], $extra));
    }

    /**
     * Applies `sort`/`direction` when the column is allowlisted, otherwise the
     * endpoint's default ordering. Unknown columns are ignored on purpose.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $allowed
     */
    protected function applyTableSort(
        Builder $query,
        array $validated,
        array $allowed,
        ?string $default = 'id'
    ): void {
        $sort = $validated['sort'] ?? null;

        if (is_string($sort) && in_array($sort, $allowed, true)) {
            $direction = strtolower((string) ($validated['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

            $query->orderBy($sort, $direction);

            return;
        }

        if ($default !== null) {
            $query->latest($default);
        }
    }

    /**
     * Clamps `per_page` to the range the API guarantees.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function tablePerPage(array $validated, int $default = 10): int
    {
        return max(1, min(100, (int) ($validated['per_page'] ?? $default)));
    }
}
