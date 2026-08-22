<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\SaaS\RevenueEvent;

/**
 * Revenue analytics service for SaaS applications.
 *
 * Provides convenience methods for tracking revenue metrics including
 * MRR (Monthly Recurring Revenue), ARR, one-time charges, upgrades/downgrades,
 * and churn-related revenue changes.
 *
 * All revenue events include structured parameters for segmentation
 * by plan, user, and revenue type.
 *
 * @since 1.0.0
 */
final class RevenueAnalyticsService
{
    private AnalyticsManager $manager;

    private string $defaultCurrency;

    /**
     * @param  AnalyticsManager  $manager
     * @param  string  $defaultCurrency  Default currency code (ISO 4217)
     */
    public function __construct(AnalyticsManager $manager, string $defaultCurrency = 'USD'){
        $this->manager = $manager;
        $this->defaultCurrency = $defaultCurrency;
    }

    /**
     * Track MRR (Monthly Recurring Revenue).
     *
     * @param  float  $amount  Total MRR amount
     * @param  int  $subscriberCount  Number of active subscribers
     * @param  string|null  $currency  Currency code
     */
    public function trackMRR(float $amount, int $subscriberCount, ?string $currency = null): void
    {
        $this->manager->trackEvent(new RevenueEvent(
            amount: $amount,
            currency: $currency ?? $this->defaultCurrency,
            revenueType: 'mrr',
            extra: ['subscriber_count' => $subscriberCount],
        ));
    }

    /**
     * Track ARR (Annual Recurring Revenue).
     *
     * @param  float  $amount  Total ARR amount
     * @param  int  $subscriberCount  Number of active annual subscribers
     * @param  string|null  $currency  Currency code
     */
    public function trackARR(float $amount, int $subscriberCount, ?string $currency = null): void
    {
        $this->manager->trackEvent(new RevenueEvent(
            amount: $amount,
            currency: $currency ?? $this->defaultCurrency,
            revenueType: 'arr',
            extra: ['subscriber_count' => $subscriberCount],
        ));
    }

    /**
     * Track a one-time revenue event (non-recurring).
     *
     * @param  float  $amount  Revenue amount
     * @param  string  $description  Description of the revenue source
     * @param  string|null  $currency  Currency code
     */
    public function trackOneTime(float $amount, string $description = '', ?string $currency = null): void
    {
        $this->manager->trackEvent(new RevenueEvent(
            amount: $amount,
            currency: $currency ?? $this->defaultCurrency,
            revenueType: 'one_time',
            extra: ['description' => $description],
        ));
    }

    /**
     * Track an add-on revenue event.
     *
     * @param  float  $amount  Add-on price
     * @param  string  $addonName  Name of the add-on
     * @param  string|null  $planName  Base plan name
     * @param  string|null  $currency  Currency code
     */
    public function trackAddon(
        float $amount,
        string $addonName,
        ?string $planName = null,
        ?string $currency = null,
    ): void {
        $this->manager->trackEvent(new RevenueEvent(
            amount: $amount,
            currency: $currency ?? $this->defaultCurrency,
            revenueType: 'addon',
            planName: $planName,
            extra: ['addon_name' => $addonName],
        ));
    }

    /**
     * Track revenue impact of a plan upgrade.
     *
     * @param  float  $newAmount  New plan price
     * @param  float  $previousAmount  Previous plan price
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New plan name
     * @param  string|null  $currency  Currency code
     */
    public function trackUpgradeRevenue(
        float $newAmount,
        float $previousAmount,
        string $fromPlan,
        string $toPlan,
        ?string $currency = null,
    ): void {
        $this->manager->trackEvent(new RevenueEvent(
            amount: $newAmount - $previousAmount,
            currency: $currency ?? $this->defaultCurrency,
            revenueType: 'upgrade',
            planName: $toPlan,
            extra: [
                'previous_plan' => $fromPlan,
                'new_amount' => $newAmount,
                'previous_amount' => $previousAmount,
            ],
        ));
    }

    /**
     * Track revenue impact of churn / cancellation.
     *
     * @param  float  $lostAmount  Revenue lost from the churned subscription
     * @param  string  $planName  Cancelled plan name
     * @param  string  $reason  Cancellation reason
     * @param  string|null  $currency  Currency code
     */
    public function trackChurnRevenue(
        float $lostAmount,
        string $planName,
        string $reason = '',
        ?string $currency = null,
    ): void {
        $this->manager->trackEvent(new RevenueEvent(
            amount: $lostAmount,
            currency: $currency ?? $this->defaultCurrency,
            revenueType: 'churn',
            planName: $planName,
            extra: ['churn_reason' => $reason],
        ));
    }

    /**
     * Track revenue impact of a plan downgrade.
     *
     * @param  float  $newAmount  New (lower) plan price
     * @param  float  $previousAmount  Previous (higher) plan price
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New plan name
     * @param  string|null  $currency  Currency code
     */
    public function trackDowngradeRevenue(
        float $newAmount,
        float $previousAmount,
        string $fromPlan,
        string $toPlan,
        ?string $currency = null,
    ): void {
        $this->manager->trackEvent(new RevenueEvent(
            amount: $previousAmount - $newAmount,
            currency: $currency ?? $this->defaultCurrency,
            revenueType: 'downgrade',
            planName: $toPlan,
            extra: [
                'previous_plan' => $fromPlan,
                'new_amount' => $newAmount,
                'previous_amount' => $previousAmount,
            ],
        ));
    }

    /**
     * Track a generic revenue event with custom parameters.
     *
     * @param  float  $amount  Revenue amount
     * @param  string  $revenueType  Custom revenue type identifier
     * @param  string|null  $planName  Associated plan name
     * @param  array<string, mixed>  $extra  Additional custom parameters
     * @param  string|null  $currency  Currency code
     */
    public function trackCustom(
        float $amount,
        string $revenueType,
        ?string $planName = null,
        array $extra = [],
        ?string $currency = null,
    ): void {
        $this->manager->trackEvent(new RevenueEvent(
            amount: $amount,
            currency: $currency ?? $this->defaultCurrency,
            revenueType: $revenueType,
            planName: $planName,
            extra: $extra,
        ));
    }

    /**
     * Get the underlying analytics manager.
     */
    public function getManager(): AnalyticsManager
    {
        return $this->manager;
    }
}
