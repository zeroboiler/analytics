<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * A/B test exposure event.
 *
 * Track when a user is exposed to a specific experiment variant.
 * Use this to feed experiment data into GA4, PostHog, or any A/B testing platform.
 */
final class AbTestExposureEvent extends AnalyticsEvent
{
    /**
     * @param  string  $experimentId  The experiment identifier (e.g. 'pricing_redesign_v2')
     * @param  string  $variantId  The variant assigned to this user (e.g. 'control', 'variant_a')
     * @param  array<string, mixed>  $params  Additional parameters (e.g. experiment_name, source)
     */
    public function __construct(
        string $experimentId,
        string $variantId,
        array $params = [],
    ): void {
        parent::__construct(
            name: 'ab_test_exposure',
            params: array_merge([
                'experiment_id' => $experimentId,
                'variant_id' => $variantId,
            ], $params),
        );
    }
}
