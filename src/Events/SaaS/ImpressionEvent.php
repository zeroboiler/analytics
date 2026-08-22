<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Feature impression event for tracking feature discovery and exposure.
 *
 * Tracks when a user views or is exposed to a product feature, UI element,
 * or upgrade prompt. Useful for feature adoption funnels and A/B testing
 * baseline measurements.
 *
 * @since 1.0.0
 */
final readonly class ImpressionEvent extends AnalyticsEvent
{
    /**
     * @param  non-empty-string  $featureName  Feature or element name
     * @param  non-empty-string  $location  Where the impression occurred (dashboard, sidebar, modal, banner)
     * @param  string|null  $source  Trigger source (auto, navigation, recommendation, a_b_test)
     * @param  string|null  $variant  A/B test variant identifier (if applicable)
     * @param  string|null  $context  Additional context (e.g. page URL, section name)
     */
    public function __construct(
        string $featureName,
        string $location,
        ?string $source = null,
        ?string $variant = null,
        ?string $context = null,
    ){
        parent::__construct(
            name: 'feature_impression',
            params: array_filter([
                'feature_name' => $featureName,
                'location' => $location,
                'source' => $source,
                'variant' => $variant,
                'context' => $context,
            ], fn (mixed $v): bool => $v !== null),
        );
    }
}
