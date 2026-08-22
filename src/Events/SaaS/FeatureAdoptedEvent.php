<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Feature adoption event — tracks when a user adopts a key product feature.
 *
 * Different from FeatureUsedEvent (which tracks every usage), this event
 * fires once per user when they first engage with a high-value feature
 * that correlates with activation, retention, or expansion revenue.
 *
 * Use for tracking product-led growth activation milestones.
 *
 * @since 1.0.0
 */
final readonly class FeatureAdoptedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $featureName  The feature identifier (e.g. 'export', 'api_access', 'team_collaboration')
     * @param  string|null  $category  Feature category (e.g. 'core', 'premium', 'integration')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $featureName,
        ?string $category = null,
        array $params = [],
    ){
        $merged = array_merge($params, [
            'feature_name' => $featureName,
            'feature_category' => $category,
        ]);

        parent::__construct(name: 'feature_adopted', params: $merged);
    }
}
