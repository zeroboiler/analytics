<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

/**
 * Fired when a trial user converts to a paid subscription.
 *
 * Captures the trial-to-paid conversion moment with trial duration,
 * plan details, and conversion source for cohort analysis.
 */
final class TrialConvertedEvent extends \ZeroBoiler\Analytics\DTO\AnalyticsEvent
{
    /**
     * @param  string  $plan  The plan the user converted to (e.g. 'pro', 'enterprise')
     * @param  string|null  $trialPlan  The trial plan the user was on
     * @param  int|null  $trialDurationDays  How many days the trial lasted
     * @param  string|null  $conversionSource  Where the conversion happened (e.g. 'pricing_page', 'in_app_prompt')
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $plan,
        ?string $trialPlan = null,
        ?int $trialDurationDays = null,
        ?string $conversionSource = null,
        ?string $userId = null,
        ?string $clientId = null,
        array $params = [],
    ) {
        parent::__construct(
            name: 'trial_converted',
            params: array_filter([
                'plan' => $plan,
                'trial_plan' => $trialPlan,
                'trial_duration_days' => $trialDurationDays,
                'conversion_source' => $conversionSource,
                ...$params,
            ], fn (mixed $v): bool => $v !== null),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
