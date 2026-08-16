<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Infrastructure;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a feature flag is evaluated.
 *
 * Tracks feature flag evaluations for experiment analysis and rollout monitoring.
 * Links flag evaluation results to user segments and contexts.
 *
 * @since 46.0.0
 */
final class FeatureFlagEvaluatedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $flagName  Feature flag name/identifier
     * @param  bool  $enabled  Whether the flag evaluated to true
     * @param  string|null  $variant  Selected variant (if multivariate)
     * @param  string|null  $reason  Evaluation reason (default, segment, override, rollout)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $flagName,
        bool $enabled,
        ?string $variant = null,
        ?string $reason = null,
        array $params = [],
    ): void {
        parent::__construct('feature_flag_evaluated', array_merge($params, array_filter([
            'flag_name' => $flagName,
            'enabled' => $enabled,
            'variant' => $variant,
            'reason' => $reason,
        ], fn (mixed $v): bool => $v !== null)));
    }
}
