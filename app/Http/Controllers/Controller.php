<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Tenant;
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
     * Whether the authenticated user is a client account.
     *
     * A client may only touch resources belonging to their own profile. Staff
     * roles are handled by their own operational rules and are not restricted
     * by this check.
     */
    protected function isClient(Request $request): bool
    {
        return $request->user()?->role === UserRole::Client;
    }

    /**
     * The authenticated client's own `clients.id`, or `null` when the user is
     * not a client or has not completed onboarding.
     */
    protected function clientId(Request $request): ?int
    {
        if (! $this->isClient($request)) {
            return null;
        }

        return $request->user()?->client?->id;
    }

    /**
     * The tenant resolved for this request by the tenant middleware.
     */
    protected function tenant(): Tenant
    {
        $tenant = request()->attributes->get('tenant');

        abort_unless($tenant instanceof Tenant, 403, 'No tenant is resolved for this request.');

        return $tenant;
    }

    /**
     * The current tenant's id.
     */
    protected function tenantId(): int
    {
        return $this->tenant()->id;
    }

    /**
     * The facility resolved for this request. V1 tenants have exactly one
     * active facility, so it is always available to tenant routes.
     */
    protected function facility(): Facility
    {
        $facility = request()->attributes->get('facility');

        abort_unless($facility instanceof Facility, 403, 'No facility is resolved for this request.');

        return $facility;
    }

    /**
     * The current facility's id.
     */
    protected function facilityId(): int
    {
        return $this->facility()->id;
    }
}
