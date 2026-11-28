<?php

namespace App\Services\Facilities;

use App\Enums\Feature;
use App\Models\Facility;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Facility lifecycle operations shared by the App (organization-wide) and
 * Tenant (assigned-facility) HTTP controllers.
 *
 * The controllers own validation and authorization; this service owns the
 * tenant scoping, capacity check and persistence so the two contexts cannot
 * drift apart.
 */
class FacilityService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Every facility of the tenant, ordered by name.
     *
     * @return Collection<int, Facility>
     */
    public function listForTenant(Tenant $tenant): Collection
    {
        return Facility::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * One facility of the tenant with its departments and stations.
     */
    public function find(Tenant $tenant, int $id): Facility
    {
        return Facility::query()
            ->where('tenant_id', $tenant->id)
            ->withCount('departments')
            ->findOrFail($id)
            ->load('departments.stations');
    }

    /**
     * Create a facility, respecting the max_facilities capacity.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Tenant $tenant, array $data): Facility
    {
        $this->subscriptions->ensureCapacity($tenant, Feature::MaxFacilities);

        return Facility::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'code' => $data['code'],
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * Update a facility of the tenant.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Tenant $tenant, int $id, array $data): Facility
    {
        $facility = Facility::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $facility->update($data);

        return $facility;
    }
}
