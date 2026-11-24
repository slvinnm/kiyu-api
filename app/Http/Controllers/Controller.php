<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

abstract class Controller
{
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
     * The authenticated client's own `customers.id`, or `null` when the user is
     * not a client or has not completed onboarding.
     */
    protected function customerId(Request $request): ?int
    {
        if (! $this->isClient($request)) {
            return null;
        }

        return $request->user()?->customer?->id;
    }

    /**
     * The actor recorded on an operation, taken from the authenticated user.
     *
     * Never read from the request body: a caller must not be able to attribute
     * their work to a colleague in the audit trail or on a ticket.
     */
    protected function actor(Request $request): ?int
    {
        return $request->user()?->id;
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

    /**
     * A validation rule that only accepts a department inside this facility.
     *
     * `exists:departments,id` alone accepts another tenant's department; the
     * services re-check ownership, but a scoped rule turns the mistake into a
     * clear 422 instead of a later 404 or a cross-tenant write attempt.
     */
    protected function departmentRule(): Exists
    {
        return Rule::exists('departments', 'id')
            ->where('facility_id', $this->facilityId());
    }

    /**
     * A validation rule that only accepts a station inside this facility.
     */
    protected function stationRule(): Exists
    {
        return Rule::exists('stations', 'id')->where(
            fn($query) => $query->whereIn(
                'department_id',
                Department::query()->where('facility_id', $this->facilityId())->select('id'),
            ),
        );
    }

    /**
     * A validation rule that only accepts a client of the current tenant.
     */
    protected function customerRule(): Exists
    {
        return Rule::exists('customers', 'id')->where('tenant_id', $this->tenantId());
    }

    /**
     * A validation rule that only accepts a queue session of the current tenant.
     */
    protected function queueSessionRule(): Exists
    {
        return Rule::exists('queue_sessions', 'id')->where('tenant_id', $this->tenantId());
    }
}
