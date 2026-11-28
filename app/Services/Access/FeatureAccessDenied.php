<?php

namespace App\Services\Access;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown when the Feature Access Engine denies a request.
 *
 * Maps the reason code to the API error contract (HTTP status + stable
 * `error_type`) so every denied path returns a consistent shape.
 */
class FeatureAccessDenied extends RuntimeException
{
    public function __construct(public readonly FeatureAccessDecision $decision)
    {
        parent::__construct("Feature '{$decision->featureCode}' is not available ({$decision->reasonCode->value}).");
    }

    /**
     * The HTTP status and error type for this denial.
     *
     * @return array{status: int, error_type: string}
     */
    public function httpStatus(): array
    {
        return match ($this->decision->reasonCode) {
            ReasonCode::FeatureNotReleased => ['status' => 404, 'error_type' => 'FEATURE_NOT_AVAILABLE'],
            ReasonCode::EntitlementRequired,
            ReasonCode::FeatureDependencyMissing,
            ReasonCode::TenantInactive,
            ReasonCode::SubscriptionRequired,
            ReasonCode::SubscriptionExpired,
            ReasonCode::SubscriptionPastDue => ['status' => 403, 'error_type' => 'ENTITLEMENT_REQUIRED'],
            ReasonCode::CapacityExceeded => ['status' => 409, 'error_type' => 'CAPACITY_EXCEEDED'],
            ReasonCode::QuotaExceeded => ['status' => 429, 'error_type' => 'QUOTA_EXCEEDED'],
            ReasonCode::AddonNotEligible => ['status' => 422, 'error_type' => 'ADDON_NOT_ELIGIBLE'],
            default => ['status' => 500, 'error_type' => 'FEATURE_ACCESS_ERROR'],
        };
    }

    /**
     * Render the denial as a JSON error response.
     */
    public function toResponse(): JsonResponse
    {
        ['status' => $status, 'error_type' => $type] = $this->httpStatus();

        return response()->json([
            'message' => $this->getMessage(),
            'error_type' => $type,
        ], $status);
    }
}
