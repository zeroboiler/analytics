<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks usage of a specific feature.
 *
 * GA4: feature_used (custom)
 * Meta: (custom event)
 */
final readonly class FeatureUsedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $featureName  Feature identifier (e.g. 'export_csv', 'api_keys', 'webhooks')
     * @param  array<string, mixed>  $metadata  Additional context about the feature usage
     */
    public function __construct(string $featureName, array $metadata = []): void
: void {
        parent::__construct('feature_used', array_filter([
            'feature_name' => $featureName,
            ...$metadata,
        ]));
    }
}
