<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    /**
     * Whether the authenticated user is a customer account.
     */
    protected function isCustomer(Request $request): bool
    {
        return $request->user()?->role === UserRole::Customer;
    }

    /**
     * The authenticated customer's own `customers.id`, or `null` when the user
     * is not a customer or has not completed onboarding.
     */
    protected function customerId(Request $request): ?int
    {
        if (! $this->isCustomer($request)) {
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
     * Assert the request was authenticated by a paired kiosk/display device.
     *
     * The device credential is resolved by the `tenant.origin` middleware and
     * placed on the request as the `device` attribute. Device-only endpoints
     * (kiosk intake, online check-in, pairing lookup) call this so a request
     * without a valid device credential is refused rather than silently falling
     * back to a slug tenant. There is no separate kiosk middleware.
     */
    protected function assertDevice(?Request $request = null): void
    {
        $request ??= request();

        abort_unless(
            $request->attributes->get('device') !== null,
            403,
            'A device credential is required.',
        );
    }

    /**
     * The facility this request operates on.
     *
     * There is no facility middleware: the facility is taken from the request
     * (`facility_id` in the body or query), or resolved implicitly when the
     * caller can reach exactly one active facility. A device or public-tenant
     * middleware may already have bound it. An ambiguous request with no
     * `facility_id` fails validation rather than guessing, and the selected
     * facility is always checked against the tenant and the caller's access.
     */
    protected function facility(?Request $request = null): Facility
    {
        $request ??= request();

        // An explicit selection is authoritative and is validated regardless of
        // any facility the middleware bound implicitly. This prevents a stale or
        // wrong selector from being silently replaced by the implicit facility.
        $requested = $this->requestedFacilityId($request);

        if ($requested !== null) {
            return $this->authorizedFacility($request, $requested);
        }

        $bound = $request->attributes->get('facility');

        if ($bound instanceof Facility) {
            return $bound;
        }

        $implicit = $this->singleReachableFacility($request);

        if ($implicit !== null) {
            return $implicit;
        }

        throw ValidationException::withMessages([
            'facility_id' => ['Choose the facility for this request.'],
        ]);
    }

    /**
     * The current facility's id.
     */
    protected function facilityId(): int
    {
        return $this->facility()->id;
    }

    /**
     * The facility id named on the request, if any.
     */
    protected function requestedFacilityId(Request $request): ?int
    {
        $value = $request->input('facility_id', $request->query('facility_id'));

        if ($value === null || $value === '') {
            return null;
        }

        abort_unless(
            is_numeric($value) && (int) $value > 0 && (string) (int) $value === (string) $value,
            422,
            'The facility_id must be a positive integer.',
        );

        return (int) $value;
    }

    /**
     * A facility of the current tenant the caller may act at, or 404/403.
     */
    protected function authorizedFacility(Request $request, int $facilityId): Facility
    {
        $facility = Facility::query()
            ->whereKey($facilityId)
            ->where('tenant_id', $this->tenantId())
            ->where('is_active', true)
            ->first();

        abort_if($facility === null, 404, 'Facility not found.');

        $user = $request->user();

        abort_if($user === null, 401, 'Unauthenticated.');

        abort_unless(
            $user->canAccessFacility($facility->id),
            403,
            'You do not have access to this facility.',
        );

        return $facility;
    }

    /**
     * The single active facility the caller can reach, or null when zero/many.
     */
    private function singleReachableFacility(Request $request): ?Facility
    {
        $tenant = $this->tenant();
        $user = $request->user();

        $query = $tenant->facilities()->where('is_active', true);

        if ($user !== null && $user->role?->isFacilityScoped()) {
            $query->whereIn('id', $user->facilityAssignments()->select('facility_id'));
        }

        $facilities = $query->limit(2)->get();

        if ($facilities->isEmpty()) {
            abort(403, 'You have no facility to operate at.');
        }

        return $facilities->count() === 1 ? $facilities->first() : null;
    }

    /**
     * The facility a device record belongs to.
     *
     * A caller may name a facility explicitly (`facility_id`); otherwise it is
     * the request-context facility. It never guesses among several.
     *
     * @throws ValidationException when no facility can be determined
     */
    protected function resolveDeviceFacility(Request $request, ?int $requested): int
    {
        if ($requested !== null) {
            return $this->authorizedFacility($request, $requested)->id;
        }

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
     * A validation rule that only accepts a customer of the current tenant.
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
