<?php

namespace App\Http\Controllers;

use App\Support\DateScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Restrict a query to the calendar day of `$date` (index-friendly).
     */
    protected function whereOnDate(
        Builder|QueryBuilder $query,
        mixed $date,
        string $column = 'created_at'
    ): void {
        DateScope::apply($query, $date, $column);
    }

    /**
     * Whether the authenticated user is a patient account.
     *
     * The spec separates authentication from authorization: a patient may only
     * touch resources belonging to their own profile. Staff roles are handled
     * by their own operational rules and are not restricted by this check.
     */
    protected function isPatient(Request $request): bool
    {
        return $request->user()?->role === 'patient';
    }

    /**
     * The authenticated patient's own `patients.id`, or `null` when the user is
     * not a patient or has not completed onboarding.
     */
    protected function patientId(Request $request): ?int
    {
        if (! $this->isPatient($request)) {
            return null;
        }

        return $request->user()?->patient?->id;
    }
}
