<?php

namespace App\Services\Access;

use App\Enums\Feature;

/**
 * The outcome of a feature-access check.
 *
 * Distinguishes the three facts a caller must never conflate: whether the
 * feature is globally released, whether the tenant is entitled to it, and
 * whether the request may proceed right now (`allowed`).
 */
final class FeatureAccessDecision
{
    /**
     * @param  list<string>  $sources
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $featureCode,
        public readonly ReasonCode $reasonCode,
        public readonly bool $isFeatureReleased,
        public readonly bool $isEntitled,
        public readonly array $sources = [],
        public readonly ?int $limit = null,
        public readonly ?int $used = null,
        public readonly ?int $remaining = null,
        public readonly array $context = [],
    ) {}

    /**
     * Whether the feature is both released and granted (before quota/capacity).
     */
    public function isAvailable(): bool
    {
        return $this->isFeatureReleased && $this->isEntitled;
    }

    /**
     * Build a denial that also records the release/entitlement facts.
     */
    public static function deny(
        Feature $feature,
        ReasonCode $reason,
        bool $released = false,
        bool $entitled = false,
        array $sources = [],
        ?int $limit = null,
        ?int $used = null,
        ?int $remaining = null,
        array $context = [],
    ): self {
        return new self(
            allowed: false,
            featureCode: $feature->value,
            reasonCode: $reason,
            isFeatureReleased: $released,
            isEntitled: $entitled,
            sources: $sources,
            limit: $limit,
            used: $used,
            remaining: $remaining,
            context: $context,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'feature_code' => $this->featureCode,
            'reason_code' => $this->reasonCode->value,
            'is_feature_released' => $this->isFeatureReleased,
            'is_entitled' => $this->isEntitled,
            'entitlement_source' => $this->sources,
            'limit' => $this->limit,
            'used' => $this->used,
            'remaining' => $this->remaining,
            'context' => $this->context,
        ];
    }
}
