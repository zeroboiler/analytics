<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * Cohort analytics service for SaaS applications.
 *
 * Tracks user cohort membership, retention, and lifecycle metrics.
 * Cohorts are time-based groups (e.g. "users who signed up in Week 32")
 * used to measure retention curves, activation rates, and engagement over time.
 *
 * Designed to work with GA4 user properties, PostHog cohort events,
 * and any downstream data warehouse for cohort analysis.
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 *
 * @since 1.0.0
 */
final class CohortAnalyticsService
{
    private AnalyticsManager $manager;

    private QueuedAnalyticsDispatcher $queue;

    private bool $useAsync;

    /**
     * @param  AnalyticsManager  $manager
     * @param  QueuedAnalyticsDispatcher  $queue
     * @param  bool  $useAsync  Whether to dispatch events asynchronously
     */
    public function __construct(
        AnalyticsManager $manager,
        QueuedAnalyticsDispatcher $queue,
        bool $useAsync = true,
    ){
        $this->manager = $manager;
        $this->queue = $queue;
        $this->useAsync = $useAsync;
    }

    /**
     * Assign a user to a cohort.
     *
     * Call this when a user signs up, starts a trial, or meets cohort criteria.
     *
     * @param  string  $userId  User identifier
     * @param  string  $cohortName  Cohort identifier (e.g. '2026-W32', 'trial_january')
     * @param  string  $cohortType  Type of cohort (signup, trial, plan, feature, custom)
     * @param  array<string, mixed>  $params  Additional cohort metadata
     */
    public function assignCohort(string $userId, string $cohortName, string $cohortType = 'signup', array $params = []): void
    {
        $this->dispatch('cohort_assigned', array_merge([
            'user_id' => $userId,
            'cohort_name' => $cohortName,
            'cohort_type' => $cohortType,
        ], $params));
    }

    /**
     * Track a user's return within a cohort (retention event).
     *
     * Call this when a previously cohort-assigned user returns after N days.
     *
     * @param  string  $userId  User identifier
     * @param  string  $cohortName  Cohort identifier
     * @param  int  $dayNumber  Day since cohort assignment (e.g. 1, 7, 30)
     * @param  array<string, mixed>  $params  Additional retention metadata
     */
    public function trackRetention(string $userId, string $cohortName, int $dayNumber, array $params = []): void
    {
        $this->dispatch('cohort_retention', array_merge([
            'user_id' => $userId,
            'cohort_name' => $cohortName,
            'retention_day' => $dayNumber,
            'retention_period' => $this->classifyPeriod($dayNumber),
        ], $params));
    }

    /**
     * Track a user dropping out of a cohort (churn).
     *
     * @param  string  $userId  User identifier
     * @param  string  $cohortName  Cohort identifier
     * @param  int|null  $dayNumber  Day since cohort assignment (null if unknown)
     * @param  string|null  $reason  Churn reason
     */
    public function trackChurn(string $userId, string $cohortName, ?int $dayNumber = null, ?string $reason = null): void
    {
        $params = array_filter([
            'user_id' => $userId,
            'cohort_name' => $cohortName,
            'churn_day' => $dayNumber,
            'churn_period' => $dayNumber !== null ? $this->classifyPeriod($dayNumber) : null,
            'churn_reason' => $reason,
        ]);

        $this->dispatch('cohort_churn', $params);
    }

    /**
     * Track a cohort conversion (e.g. trial-to-paid within cohort).
     *
     * @param  string  $userId  User identifier
     * @param  string  $cohortName  Cohort identifier
     * @param  string  $conversionType  Type of conversion (trial_to_paid, activation, upsell)
     * @param  array<string, mixed>  $params  Additional conversion metadata
     */
    public function trackConversion(string $userId, string $cohortName, string $conversionType = 'trial_to_paid', array $params = []): void
    {
        $this->dispatch('cohort_conversion', array_merge([
            'user_id' => $userId,
            'cohort_name' => $cohortName,
            'conversion_type' => $conversionType,
        ], $params));
    }

    /**
     * Track a cohort migration event (e.g. user moved from one plan cohort to another).
     *
     * @param  string  $userId  User identifier
     * @param  string  $fromCohort  Previous cohort name
     * @param  string  $toCohort  New cohort name
     * @param  array<string, mixed>  $params  Additional migration metadata
     */
    public function trackMigration(string $userId, string $fromCohort, string $toCohort, array $params = []): void
    {
        $this->dispatch('cohort_migration', array_merge([
            'user_id' => $userId,
            'from_cohort' => $fromCohort,
            'to_cohort' => $toCohort,
        ], $params));
    }

    /**
     * Track a cohort engagement summary event.
     *
     * Use this for periodic (daily/weekly) cohort engagement snapshots.
     *
     * @param  string  $cohortName  Cohort identifier
     * @param  int  $activeUsers  Number of active users in this period
     * @param  int  $totalUsers  Total users in the cohort
     * @param  string  $period  Period (daily, weekly, monthly)
     * @param  array<string, mixed>  $params  Additional engagement metadata
     */
    public function trackEngagementSummary(string $cohortName, int $activeUsers, int $totalUsers, string $period = 'weekly', array $params = []): void
    {
        $engagementRate = $totalUsers > 0
            ? round(($activeUsers / $totalUsers) * 100, 2)
            : 0.0;

        $this->dispatch('cohort_engagement', array_merge([
            'cohort_name' => $cohortName,
            'active_users' => $activeUsers,
            'total_users' => $totalUsers,
            'engagement_rate' => $engagementRate,
            'period' => $period,
        ], $params));
    }

    /**
     * Generate a time-based cohort name for a given date.
     *
     * @param  string  $type  Cohort type (weekly, monthly, quarterly)
     * @param  string|null  $date  Date string (Y-m-d), defaults to today
     * @return string Cohort name (e.g. '2026-W32', '2026-08')
     */
    public static function generateCohortName(string $type = 'weekly', ?string $date = null): string
    {
        $timestamp = $date !== null ? strtotime($date) : time();

        if ($timestamp === false) {
            $timestamp = time();
        }

        return match ($type) {
            'weekly' => date('Y-\WW', $timestamp),
            'monthly' => date('Y-m', $timestamp),
            'quarterly' => 'Q' . ceil((int) date('n', $timestamp) / 3) . '-' . date('Y', $timestamp),
            'daily' => date('Y-m-d', $timestamp),
            default => date('Y-W', $timestamp),
        };
    }

    /**
     * Classify a retention day number into a period bucket.
     *
     * @return string Period classification (d1, d7, d14, d30, d60, d90, d180, d365)
     */
    public static function classifyPeriod(int $dayNumber): string
    {
        return match (true) {
            $dayNumber <= 1 => 'd1',
            $dayNumber <= 7 => 'd7',
            $dayNumber <= 14 => 'd14',
            $dayNumber <= 30 => 'd30',
            $dayNumber <= 60 => 'd60',
            $dayNumber <= 90 => 'd90',
            $dayNumber <= 180 => 'd180',
            $dayNumber <= 365 => 'd365',
            default => 'd365+',
        };
    }

    /**
     * Dispatch a cohort event via the appropriate channel.
     *
     * @param  array<string, mixed>  $params
     */
    private function dispatch(string $eventName, array $params = []): void
    {
        $event = new AnalyticsEvent(name: $eventName, params: $params);

        if ($this->useAsync) {
            $this->queue->dispatch($event);
        } else {
            $this->manager->trackEvent($event);
        }
    }

    /**
     * Get the underlying analytics manager.
     */
    public function getManager(): AnalyticsManager
    {
        return $this->manager;
    }
}
