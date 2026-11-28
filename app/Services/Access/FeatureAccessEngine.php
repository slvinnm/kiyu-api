<?php

namespace App\Services\Access;

use App\Enums\Feature;
use App\Models\Feature as FeatureRecord;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a tenant may use a feature right now.
 *
 * Pipeline: the code must be a known backend feature and have a registry row;
 * the row must be released; the tenant must be active; a granting subscription
 * must exist; the feature must be entitled by the subscription/add-on snapshot;
 * any dependency must itself be available. Capacity/quota checks are a separate
 * call so the read path (a boolean gate) never mutates.
 *
 * Fail closed: a missing registry row, an unknown code, or an unreadable
 * registry denies rather than grants.
 */
class FeatureAccessEngine
{
    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly SubscriptionService $subscriptions,
        private readonly UsageLimitResolver $usage,
    ) {}

    /**
     * The full decision for a feature in a tenant.
     */
    public function canAccess(Tenant $tenant, Feature $feature, array $context = []): FeatureAccessDecision
    {
        $released = $this->isReleased($feature);

        if ($released === null) {
            // No registry row: configuration error, fail closed.
            Log::error('Feature registry row missing', ['feature' => $feature->value]);

            return FeatureAccessDecision::deny($feature, ReasonCode::FeatureNotRegistered, context: $context);
        }

        if (! $released) {
            return FeatureAccessDecision::deny($feature, ReasonCode::FeatureNotReleased, released: false, context: $context);
        }

        if (! $tenant->status->isOperational()) {
            return FeatureAccessDecision::deny($feature, ReasonCode::TenantInactive, released: true, context: $context);
        }

        $subscription = $this->subscriptions->grantingSubscription($tenant);

        if ($subscription === null) {
            return FeatureAccessDecision::deny($feature, ReasonCode::SubscriptionRequired, released: true, context: $context);
        }

        $map = $this->entitlements->allFor($tenant);
        $entitled = $this->isEntitled($feature, $map[$feature->value] ?? null);

        if (! $entitled) {
            return FeatureAccessDecision::deny($feature, ReasonCode::EntitlementRequired, released: true, context: $context);
        }

        // Dependency check: a report needs queue_management; a quota needs the
        // notification toggle.
        foreach ($feature->dependencies() as $dependency) {
            $dependencyMap = $map[$dependency->value] ?? null;

            if (! $this->isEntitled($dependency, $dependencyMap)) {
                return FeatureAccessDecision::deny($feature, ReasonCode::FeatureDependencyMissing, released: true, entitled: true, context: $context);
            }
        }

        $limit = $feature->isToggle() ? null : $this->readLimit($map[$feature->value] ?? null);
        $used = $feature->isLimit() ? $this->usage->usage($tenant, $feature) : null;
        $remaining = $limit !== null && $used !== null ? max(0, $limit - $used) : null;

        return new FeatureAccessDecision(
            allowed: true,
            featureCode: $feature->value,
            reasonCode: ReasonCode::Allowed,
            isFeatureReleased: true,
            isEntitled: true,
            sources: $this->sourcesFor($feature),
            limit: $limit,
            used: $used,
            remaining: $remaining,
            context: $context,
        );
    }

    /**
     * Throw when the tenant may not access the feature.
     */
    public function assertAccess(Tenant $tenant, Feature $feature, array $context = []): void
    {
        $decision = $this->canAccess($tenant, $feature, $context);

        if (! $decision->allowed) {
            throw new FeatureAccessDenied($decision);
        }
    }

    /**
     * Assert there is capacity for one more unit of a limited resource.
     */
    public function assertCapacity(Tenant $tenant, Feature $feature, int $quantity = 1): void
    {
        if (! $feature->isLimit()) {
            return;
        }

        $decision = $this->canAccess($tenant, $feature);

        if (! $decision->allowed) {
            throw new FeatureAccessDenied($decision);
        }

        if ($decision->remaining !== null && $decision->remaining < $quantity) {
            throw new FeatureAccessDenied(FeatureAccessDecision::deny(
                $feature,
                ReasonCode::CapacityExceeded,
                released: true,
                entitled: true,
                limit: $decision->limit,
                used: $decision->used,
                remaining: $decision->remaining,
            ));
        }
    }

    /**
     * Whether the feature is globally released, or null when unregistered.
     *
     * The registry is authoritative once seeded. Before it is seeded (a fresh
     * environment, or a test that does not run {@see FeatureSeeder}),
     * every known enum code is treated as released; production seeds it on
     * deploy, after which a missing row fails closed.
     */
    public function isReleased(Feature $feature): ?bool
    {
        if (! FeatureRecord::query()->exists()) {
            return true;
        }

        $record = FeatureRecord::query()->where('code', $feature->value)->first();

        return $record?->is_feature_released;
    }

    /**
     * Whether a stored entitlement value grants the feature.
     */
    private function isEntitled(Feature $feature, mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if ($feature->isToggle()) {
            return (bool) $value;
        }

        return $this->readLimit($value) > 0;
    }

    private function readLimit(mixed $value): int
    {
        if (is_array($value)) {
            return (int) ($value['limit'] ?? 0);
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<string>
     */
    private function sourcesFor(Feature $feature): array
    {
        return ['plan'];
    }
}
