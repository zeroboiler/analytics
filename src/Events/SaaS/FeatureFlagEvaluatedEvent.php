<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Feature flag evaluation event.
 *
 * Tracks when a feature flag is evaluated for a user/session,
 * including the flag key, variant assigned, evaluation context,
 * and whether this is a first exposure. Used by FeatureFlagAnalyticsService
 * for A/B test variant tracking and feature adoption measurement.
 *
 * @since 78.0.0
 */
final readonly class FeatureFlagEvaluatedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $flagKey  Feature flag key (e.g. 'new_dashboard_v2')
     * @param  string  $variant  Assigned variant (e.g. 'control', 'treatment', 'on', 'off')
     * @param  bool  $isFirstExposure  Whether this is the user's first exposure to this flag
     * @param  string|null  $evaluationReason  Why the flag was evaluated (e.g. 'page_load', 'api_call')
     * @param  string|null  $experimentId  Associated experiment ID
     * @param  string|null  $flagType  Flag type (boolean, multivariate, rollout)
     */
    public function __construct(
        string $flagKey,
        string $variant,
        bool $isFirstExposure = false,
        ?string $evaluationReason = null,
        ?string $experimentId = null,
        ?string $flagType = null,
    ): void {
        parent::__construct(
            'feature_flag_evaluated',
            array_filter([
                'flag_key' => $flagKey,
                'variant' => $variant,
                'is_first_exposure' => $isFirstExposure,
                'evaluation_reason' => $evaluationReason,
                'experiment_id' => $experimentId,
                'flag_type' => $flagType,
            ], fn ($v) => $v !== null),
        );
    }
}
