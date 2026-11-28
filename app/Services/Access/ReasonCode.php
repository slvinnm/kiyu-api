<?php

namespace App\Services\Access;

/**
 * Stable reason codes for a feature-access decision.
 *
 * A denial always carries one of these so logs, metrics and API error types can
 * be produced without leaking internals.
 */
enum ReasonCode: string
{
    case Allowed = 'ALLOWED';
    case FeatureUnknown = 'FEATURE_UNKNOWN';
    case FeatureNotRegistered = 'FEATURE_NOT_REGISTERED';
    case FeatureNotReleased = 'FEATURE_NOT_RELEASED';
    case TenantInactive = 'TENANT_INACTIVE';
    case SubscriptionRequired = 'SUBSCRIPTION_REQUIRED';
    case SubscriptionExpired = 'SUBSCRIPTION_EXPIRED';
    case SubscriptionPastDue = 'SUBSCRIPTION_PAST_DUE';
    case EntitlementRequired = 'ENTITLEMENT_REQUIRED';
    case FeatureDependencyMissing = 'FEATURE_DEPENDENCY_MISSING';
    case AddonNotEligible = 'ADDON_NOT_ELIGIBLE';
    case AddonInactive = 'ADDON_INACTIVE';
    case CapacityExceeded = 'CAPACITY_EXCEEDED';
    case QuotaExceeded = 'QUOTA_EXCEEDED';
    case ContextNotAllowed = 'CONTEXT_NOT_ALLOWED';
}
