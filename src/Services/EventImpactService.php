<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Event impact scoring service for SaaS analytics.
 *
 * Measures the correlation between specific events and key business outcomes
 * (conversion, retention, revenue). Identifies which events are most predictive
 * of success so teams can optimize for the behaviors that matter most.
 *
 * Uses a simple statistical correlation approach suitable for production use
 * without requiring machine learning infrastructure.
 *
 * Configuration is read from `zeroboiler.analytics.event_impact`.
 *
 * @phpstan-type ImpactScore array{event_name: string, impact_score: float, correlation: float, sample_size: int, category: string, label: string}
 * @phpstan-type UserBehavior array{user_id: string, events: list<string>, converted: bool, retained: bool, revenue: float}
 */
final class EventImpactService
{
    private readonly bool $enabled;

    private readonly int $minSampleSize;

    /** @var list<string> Events considered as conversion signals */
    private readonly array $conversionEvents;

    /** @var list<string> Events considered as retention signals */
    private readonly array $retentionEvents;

    private const CACHE_PREFIX = 'zb_impact_';

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
    {
        $impactConfig = $config->get('zeroboiler.analytics.event_impact', []);
        /** @var array{enabled?: bool, min_sample_size?: int, conversion_events?: list<string>, retention_events?: list<string>} $impactConfig */

        $this->enabled = (bool) ($impactConfig['enabled'] ?? true);
        $this->minSampleSize = (int) ($impactConfig['min_sample_size'] ?? 30);
        $this->conversionEvents = $impactConfig['conversion_events'] ?? [
            'subscribe', 'purchase', 'trial_converted', 'plan_upgrade',
        ];
        $this->retentionEvents = $impactConfig['retention_events'] ?? [
            'feature_used', 'login', 'form_submit', 'page_view',
        ];
    }

    /**
     * Calculate impact scores for events based on user behavior data.
     *
     * For each event type, measures how strongly its presence correlates
     * with conversion, retention, and revenue outcomes.
     *
     * @param  list<UserBehavior>  $userBehaviors  Per-user event history and outcomes
     * @return array{scores: list<ImpactScore>, top_conversion: string|null, top_retention: string|null, summary: array{users_analyzed: int, events_evaluated: int}}
     */
    public function calculateImpacts(array $userBehaviors): array
    {
        if (! $this->enabled || count($userBehaviors) < $this->minSampleSize) {
            return [
                'scores' => [],
                'top_conversion' => null,
                'top_retention' => null,
                'summary' => [
                    'users_analyzed' => count($userBehaviors),
                    'events_evaluated' => 0,
                ],
            ];
        }

        // Collect all unique events
        $allEvents = [];
        foreach ($userBehaviors as $user) {
            foreach ($user['events'] as $event) {
                $allEvents[$event] = true;
            }
        }
        $eventNames = array_keys($allEvents);

        $scores = [];
        foreach ($eventNames as $eventName) {
            $conversionCorrelation = $this->correlationWithOutcome(
                $userBehaviors,
                $eventName,
                fn (array $u): bool => (bool) ($u['converted'] ?? false),
            );

            $retentionCorrelation = $this->correlationWithOutcome(
                $userBehaviors,
                $eventName,
                fn (array $u): bool => (bool) ($u['retained'] ?? false),
            );

            $revenueCorrelation = $this->correlationWithOutcome(
                $userBehaviors,
                $eventName,
                fn (array $u): bool => ($u['revenue'] ?? 0) > 0,
            );

            // Composite impact score (weighted average)
            $impactScore = ($conversionCorrelation * 0.5)
                + ($retentionCorrelation * 0.3)
                + ($revenueCorrelation * 0.2);

            // Determine category
            $category = in_array($eventName, $this->conversionEvents, true) ? 'conversion'
                : (in_array($eventName, $this->retentionEvents, true) ? 'retention' : 'engagement');

            // Label based on impact
            $label = match (true) {
                $impactScore >= 0.7 => 'high_impact',
                $impactScore >= 0.4 => 'moderate_impact',
                $impactScore >= 0.2 => 'low_impact',
                default => 'minimal_impact',
            };

            $sampleSize = count(array_filter(
                $userBehaviors,
                fn (array $u): bool => in_array($eventName, $u['events'], true),
            ));

            $scores[] = [
                'event_name' => $eventName,
                'impact_score' => round($impactScore, 4),
                'correlation' => round($conversionCorrelation, 4),
                'retention_correlation' => round($retentionCorrelation, 4),
                'revenue_correlation' => round($revenueCorrelation, 4),
                'sample_size' => $sampleSize,
                'category' => $category,
                'label' => $label,
            ];
        }

        // Sort by impact score descending
        usort($scores, fn (array $a, array $b): int => $b['impact_score'] <=> $a['impact_score']);

        // Find top events for conversion and retention
        $topConversion = null;
        $topRetention = null;

        foreach ($scores as $score) {
            if ($topConversion === null && $score['retention_correlation'] > 0) {
                $topConversion = $score['event_name'];
            }
            if ($topRetention === null && in_array($score['event_name'], $this->retentionEvents, true)) {
                $topRetention = $score['event_name'];
            }
        }

        return [
            'scores' => $scores,
            'top_conversion' => $topConversion,
            'top_retention' => $topRetention,
            'summary' => [
                'users_analyzed' => count($userBehaviors),
                'events_evaluated' => count($eventNames),
            ],
        ];
    }

    /**
     * Get a ranked list of events by their impact on conversion.
     *
     * Convenience method that filters and sorts the full impact analysis
     * to focus on conversion-related insights.
     *
     * @param  list<UserBehavior>  $userBehaviors
     * @param  int  $limit  Maximum number of events to return
     * @return list<ImpactScore>
     */
    public function conversionDrivers(array $userBehaviors, int $limit = 10): array
    {
        $result = $this->calculateImpacts($userBehaviors);

        return array_slice(
            array_filter($result['scores'], fn (array $s): bool => $s['correlation'] > 0),
            0,
            $limit,
        );
    }

    /**
     * Get a ranked list of events by their impact on retention.
     *
     * @param  list<UserBehavior>  $userBehaviors
     * @param  int  $limit
     * @return list<ImpactScore>
     */
    public function retentionDrivers(array $userBehaviors, int $limit = 10): array
    {
        $result = $this->calculateImpacts($userBehaviors);

        return array_slice(
            array_filter($result['scores'], fn (array $s): bool => $s['retention_correlation'] > 0),
            0,
            $limit,
        );
    }

    /**
     * Calculate point-biserial correlation between event presence and a binary outcome.
     *
     * Measures how strongly the presence of a specific event correlates
     * with a given outcome (conversion, retention, revenue > 0).
     *
     * @param  list<UserBehavior>  $users
     * @param  string  $eventName
     * @param  callable(array): bool  $outcomeFn
     * @return float Correlation coefficient (-1 to 1)
     */
    private function correlationWithOutcome(array $users, string $eventName, callable $outcomeFn): float
    {
        $withEvent = [];
        $withoutEvent = [];

        foreach ($users as $user) {
            $hasEvent = in_array($eventName, $user['events'], true);
            $outcome = $outcomeFn($user) ? 1 : 0;

            if ($hasEvent) {
                $withEvent[] = $outcome;
            } else {
                $withoutEvent[] = $outcome;
            }
        }

        $n1 = count($withEvent);
        $n0 = count($withoutEvent);
        $n = $n1 + $n0;

        if ($n < $this->minSampleSize || $n1 === 0 || $n0 === 0) {
            return 0.0;
        }

        $mean1 = array_sum($withEvent) / $n1;
        $mean0 = array_sum($withoutEvent) / $n0;
        $mean = ($n1 * $mean1 + $n0 * $mean0) / $n;

        $p = $n1 / $n;
        $q = $n0 / $n;

        // Point-biserial correlation formula
        $numerator = $mean1 - $mean0;
        $sumSquared = 0.0;

        foreach ($withEvent as $val) {
            $sumSquared += pow($val - $mean, 2);
        }
        foreach ($withoutEvent as $val) {
            $sumSquared += pow($val - $mean, 2);
        }

        $denominator = sqrt(($sumSquared / $n) * ($p * $q));

        if ($denominator <= 0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
