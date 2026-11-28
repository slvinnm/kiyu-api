<?php

namespace App\Services\Access;

use App\Enums\Feature;
use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the effective entitlements of a tenant for the engine.
 *
 * It reads the subscription's snapshot (`subscription_entitlements`) plus the
 * active add-on snapshots (`subscription_addon_entitlements`), never the mutable
 * catalogue. The merge is per feature kind: booleans union, capacity sums,
 * quotas sum. Results are cached under a versioned key that is invalidated when
 * the tenant's subscription or add-ons change.
 */
class EntitlementResolver
{
    /**
     * The effective entitlement value for a feature, or null when absent.
     *
     * Boolean → true/false; capacity/quota → int. Callers that need the full
     * picture should use {@see self::allFor()}.
     */
    public function resolve(Tenant $tenant, Feature $feature): mixed
    {
        $all = $this->allFor($tenant);

        return $all[$feature->value] ?? null;
    }

    /**
     * The full effective entitlement map for the tenant's access subscription.
     *
     * @return array<string, bool|int>
     */
    public function allFor(Tenant $tenant): array
    {
        $subscription = $this->accessSubscription($tenant);

        if ($subscription === null) {
            return [];
        }

        $key = $this->cacheKey($tenant);

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $map = $this->compute($subscription);

        Cache::put($key, $map, (int) config('kiyu.cache.ttl_seconds', 300));

        return $map;
    }

    /**
     * Forget the cached entitlements for a tenant.
     */
    public function forget(Tenant $tenant): void
    {
        Cache::forget($this->cacheKey($tenant));
    }

    /**
     * @return array<string, bool|int>
     */
    private function compute(Subscription $subscription): array
    {
        $map = [];

        // 1. Subscription snapshot.
        $snapshot = $subscription->entitlements()->get();
        $sources = [];

        foreach ($snapshot as $row) {
            $map[$row->feature_code] = $row->value;
            $sources[$row->feature_code] = 'plan';
        }

        // 2. Active add-on snapshots.
        $addons = $this->activeAddons($subscription);

        foreach ($addons as $addon) {
            foreach ($addon->entitlements()->get() as $row) {
                $feature = Feature::tryFrom($row->feature_code);

                if ($feature === null) {
                    continue;
                }

                $map[$row->feature_code] = $this->merge($feature, $map[$row->feature_code] ?? null, $row->value);
                $sources[$row->feature_code] = ($sources[$row->feature_code] ?? '') === 'plan' ? 'plan+addon' : 'addon';
            }
        }

        return $map;
    }

    /**
     * Merge a snapshot value with an add-on value for the feature's kind.
     */
    private function merge(Feature $feature, mixed $existing, mixed $incoming): mixed
    {
        if ($feature->isToggle()) {
            return true;
        }

        $existingLimit = $this->readLimit($existing);
        $incomingLimit = $this->readLimit($incoming);

        // Capacity and quota are additive.
        return ['limit' => $existingLimit + $incomingLimit];
    }

    /**
     * The tenant's access subscription (the one that grants features).
     */
    private function accessSubscription(Tenant $tenant): ?Subscription
    {
        return app(SubscriptionService::class)->grantingSubscription($tenant);
    }

    /**
     * @return Collection<int, SubscriptionAddon>
     */
    private function activeAddons(Subscription $subscription): Collection
    {
        return $subscription->addons()
            ->where('status', 'ACTIVE')
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->get();
    }

    private function readLimit(mixed $value): int
    {
        if (is_array($value)) {
            return (int) ($value['limit'] ?? 0);
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Cache key for a tenant's resolved entitlements.
     *
     * Keyed by tenant only (not by a subscription version) so activation of an
     * add-on or a subscription change is reflected as soon as the cache is
     * forgotten; every mutation that changes entitlements calls {@see self::forget()}.
     */
    private function cacheKey(Tenant $tenant): string
    {
        return 'kiyu:tenant:' . $tenant->id . ':entitlements';
    }
}
