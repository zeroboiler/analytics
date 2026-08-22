<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user reaches a feature limit (rate limit, quota, storage, etc.).
 *
 * Useful for understanding usage patterns, identifying upsell opportunities,
 * and detecting potential abuse. Maps to GA4 custom event and Meta custom event.
 *
 * GA4: feature_limit_reached (custom)
 * Meta: FeatureLimitReached (custom)
 *
 * @since 1.0.0
 */
final readonly class FeatureLimitReachedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $featureName  Feature identifier (e.g. 'api_requests', 'storage', 'team_members')
     * @param  string  $limitType  Type of limit ('rate_limit', 'quota', 'storage', 'seats')
     * @param  int|null  $currentUsage  Current usage value
     * @param  int|null  $maxLimit  Maximum allowed value
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(
        string $featureName,
        string $limitType,
        ?int $currentUsage = null,
        ?int $maxLimit = null,
        array $metadata = [],
    ){
        parent::__construct('feature_limit_reached', array_filter([
            'feature_name' => $featureName,
            'limit_type' => $limitType,
            'current_usage' => $currentUsage,
            'max_limit' => $maxLimit,
            ...$metadata,
        ], static fn ($v): bool => $v !== null));
    }
}
