<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\UtmAttribution;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * Revenue attribution service for SaaS applications.
 *
 * Tracks revenue events with UTM attribution, user metadata, and
 * subscription lifecycle data. Provides convenience methods for
 * MRR, LTV estimates, and revenue breakdowns by source.
 *
 * Designed for subscription SaaS revenue tracking with both
 * GA4 and Meta Pixel formatting support.
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 */
class RevenueAttributionService
{
    private AnalyticsManager $manager;

    private QueuedAnalyticsDispatcher $queue;

    private bool $useAsync;

    private string $defaultCurrency;

    /**
     * @param  AnalyticsManager  $manager
     * @param  QueuedAnalyticsDispatcher  $queue
     * @param  string  $defaultCurrency  ISO 4217 currency code
     * @param  bool  $useAsync  Whether to dispatch events asynchronously
     */
    public function __construct(
        AnalyticsManager $manager,
        QueuedAnalyticsDispatcher $queue,
        string $defaultCurrency = 'USD',
        bool $useAsync = true,
    ) {
        $this->manager = $manager;
        $this->queue = $queue;
        $this->defaultCurrency = $defaultCurrency;
        $this->useAsync = $useAsync;
    }

    /**
     * Track a revenue event with attribution data.
     *
     * @param  string  $eventId  Unique event identifier
     * @param  float  $amount  Revenue amount
     * @param  array<string, mixed>  $params  Additional parameters (plan, billing_cycle, etc.)
     * @param  UtmAttribution|null  $attribution  UTM attribution data
     * @param  string|null  $userId  Authenticated user ID
     */
    public function trackRevenue(
        string $eventId,
        float $amount,
        array $params = [],
        ?UtmAttribution $attribution = null,
        ?string $userId = null,
    ): void {
        $eventParams = array_merge([
            'revenue_event_id' => $eventId,
            'revenue_amount' => $amount,
            'currency' => $params['currency'] ?? $this->defaultCurrency,
        ], $params);

        if ($attribution !== null && $attribution->hasAttribution()) {
            $eventParams = array_merge($eventParams, $attribution->toArray());
        }

        $event = new AnalyticsEvent(
            name: 'revenue_tracked',
            params: $eventParams,
            userId: $userId,
        );

        $this->dispatch($event);
    }

    /**
     * Track MRR (Monthly Recurring Revenue) change.
     *
     * @param  string  $userId  User ID
     * @param  float  $mrrAmount  New MRR amount
     * @param  float  $previousMrr  Previous MRR amount
     * @param  string  $planName  Plan name (e.g. 'pro', 'enterprise')
     * @param  string  $changeType  'new', 'upgrade', 'downgrade', 'churn'
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackMrrChange(
        string $userId,
        float $mrrAmount,
        float $previousMrr,
        string $planName,
        string $changeType,
        array $params = [],
    ): void {
        $mrrDelta = $mrrAmount - $previousMrr;

        $event = new AnalyticsEvent(
            name: 'mrr_change',
            params: array_merge([
                'user_id' => $userId,
                'mrr_amount' => $mrrAmount,
                'mrr_previous' => $previousMrr,
                'mrr_delta' => $mrrDelta,
                'plan_name' => $planName,
                'change_type' => $changeType,
                'currency' => $this->defaultCurrency,
            ], $params),
            userId: $userId,
        );

        $this->dispatch($event);
    }

    /**
     * Track a customer LTV (Lifetime Value) estimate.
     *
     * LTV is typically calculated as: Average Revenue Per User × Average Customer Lifespan
     * This event records the calculated LTV for analytics providers.
     *
     * @param  string  $userId  User ID
     * @param  float  $ltv  Calculated lifetime value
     * @param  float  $totalRevenue  Total revenue from this customer
     * @param  int  $daysActive  Days since signup
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackLtv(
        string $userId,
        float $ltv,
        float $totalRevenue,
        int $daysActive,
        array $params = [],
    ): void {
        $event = new AnalyticsEvent(
            name: 'ltv_update',
            params: array_merge([
                'user_id' => $userId,
                'ltv' => $ltv,
                'total_revenue' => $totalRevenue,
                'days_active' => $daysActive,
                'avg_daily_revenue' => $daysActive > 0 ? round($totalRevenue / $daysActive, 2) : 0,
                'currency' => $this->defaultCurrency,
            ], $params),
            userId: $userId,
        );

        $this->dispatch($event);
    }

    /**
     * Track a cohort revenue summary.
     *
     * Useful for aggregating revenue data by signup cohort, UTM source,
     * or plan tier for dashboarding.
     *
     * @param  string  $cohortName  Cohort identifier (e.g. '2026-01', 'google_cpc')
     * @param  float  $totalRevenue  Total revenue for this cohort
     * @param  int  $customerCount  Number of paying customers in cohort
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackCohortRevenue(
        string $cohortName,
        float $totalRevenue,
        int $customerCount,
        array $params = [],
    ): void {
        $event = new AnalyticsEvent(
            name: 'cohort_revenue',
            params: array_merge([
                'cohort_name' => $cohortName,
                'cohort_revenue' => $totalRevenue,
                'cohort_customers' => $customerCount,
                'cohort_arpu' => $customerCount > 0
                    ? round($totalRevenue / $customerCount, 2)
                    : 0,
                'currency' => $this->defaultCurrency,
            ], $params),
        );

        $this->dispatch($event);
    }

    /**
     * Track revenue breakdown by source/channel.
     *
     * @param  string  $source  Revenue source (e.g. 'stripe', 'paypal')
     * @param  string  $channel  Marketing channel (e.g. 'organic', 'paid_search')
     * @param  float  $amount  Revenue amount
     * @param  int  $transactionCount  Number of transactions
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackRevenueBreakdown(
        string $source,
        string $channel,
        float $amount,
        int $transactionCount,
        array $params = [],
    ): void {
        $event = new AnalyticsEvent(
            name: 'revenue_breakdown',
            params: array_merge([
                'revenue_source' => $source,
                'revenue_channel' => $channel,
                'revenue_amount' => $amount,
                'transaction_count' => $transactionCount,
                'avg_transaction_value' => $transactionCount > 0
                    ? round($amount / $transactionCount, 2)
                    : 0,
                'currency' => $this->defaultCurrency,
            ], $params),
        );

        $this->dispatch($event);
    }

    /**
     * Get the default currency.
     */
    public function getDefaultCurrency(): string
    {
        return $this->defaultCurrency;
    }

    /**
     * Set the default currency.
     */
    public function setDefaultCurrency(string $currency): self
    {
        $this->defaultCurrency = $currency;

        return $this;
    }

    /**
     * Get the underlying analytics manager.
     */
    public function getManager(): AnalyticsManager
    {
        return $this->manager;
    }

    /**
     * Dispatch an event synchronously or asynchronously.
     */
    private function dispatch(AnalyticsEvent $event): void
    {
        if ($this->useAsync) {
            $this->queue->dispatch($event);
        } else {
            $this->manager->trackEvent($event);
        }
    }
}
