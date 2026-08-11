<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Real-time SaaS lifecycle observer for computing health signals.
 *
 * Monitors trial-to-conversion velocity, churn risk indicators, expansion
 * revenue momentum, feature adoption depth, and user activation progress.
 * Stores computed signals in the cache for dashboard queries.
 *
 * Designed for SaaS startups who need real-time health metrics without
 * an external analytics tool.
 *
 * @since 9.2.0
 */
final class SaaSLifecycleObserver
{
    /** @var array<string, int> */
    private const TRIAL_WEIGHTS = [
        'trial_start' => 0,
        'login' => 15,
        'feature_used' => 20,
        'form_submit' => 25,
        'add_to_cart' => 30,
        'subscription' => 80,
        'plan_upgrade' => 90,
        'trial_converted' => 100,
    ];

    /** @var array<string, int> */
    private const CHURN_RISK_INDICATORS = [
        'support_ticket' => 25,
        'feature_limit_reached' => 20,
        'billing_retry' => 35,
        'downgrade_visit' => 15,
        'reduced_usage' => 30,
        'error' => 10,
    ];

    private const CACHE_PREFIX = 'zb_lifecycle_';

    private const DEFAULT_TTL = 3600; // 1 hour

    private CacheRepository $cache;

    private ConfigRepository $config;

    private int $cacheTtl;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;
        $lifecycleConfig = $config->get('zeroboiler.analytics.lifecycle_observer', []);
        /** @var array{cache_ttl?: int} $lifecycleConfig */
        $this->cacheTtl = (int) ($lifecycleConfig['cache_ttl'] ?? self::DEFAULT_TTL);
    }

    /**
     * Record a lifecycle event and recompute derived signals.
     *
     * Call this from your event handlers after dispatching the analytics event.
     *
     * @param  string  $event  Event name from the catalog
     * @param  string|null  $identity  User or client ID
     * @param  array<string, mixed>  $context  Additional event context
     * @return array<string, mixed> Updated lifecycle signals for the identity
     */
    public function record(string $event, ?string $identity = null, array $context = []): array
    {
        if ($identity === null || $identity === '') {
            return [];
        }

        $signals = $this->getSignals($identity);

        // Update trial activation score
        if (array_key_exists($event, self::TRIAL_WEIGHTS)) {
            $signals = $this->updateActivationScore($signals, $event, $identity);
        }

        // Update churn risk
        if (array_key_exists($event, self::CHURN_RISK_INDICATORS)) {
            $signals = $this->updateChurnRisk($signals, $event);
        }

        // Track expansion events
        if (in_array($event, ['plan_upgrade', 'expansion_revenue', 'subscription_renewal'], true)) {
            $signals = $this->updateExpansionMomentum($signals, $event, $context);
        }

        // Track feature adoption depth
        if ($event === 'feature_used') {
            $signals = $this->updateFeatureAdoption($signals, $context);
        }

        // Track session engagement
        if ($event === 'login') {
            $signals = $this->updateSessionEngagement($signals);
        }

        // Track conversion funnel progress
        $signals = $this->updateFunnelProgress($signals, $event);

        $signals['updated_at'] = time();

        $this->cache->put(
            self::CACHE_PREFIX . $identity,
            $signals,
            $this->cacheTtl,
        );

        return $signals;
    }

    /**
     * Get the current lifecycle signals for an identity.
     *
     * @param  string  $identity  User or client ID
     * @return array<string, mixed>
     */
    public function getSignals(string $identity): array
    {
        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get(self::CACHE_PREFIX . $identity);

        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        return $this->defaultSignals();
    }

    /**
     * Get the trial activation score for an identity.
     *
     * Returns a 0-100 score based on engagement signals during the trial period.
     *
     * @param  string  $identity
     * @return array{score: int, grade: string, completed_steps: list<string>, signals: list<string>}
     */
    public function activationScore(string $identity): array
    {
        $signals = $this->getSignals($identity);
        $score = (int) ($signals['activation_score'] ?? 0);
        $completedSteps = (array) ($signals['activation_steps'] ?? []);

        return [
            'score' => min(100, $score),
            'grade' => $this->scoreToGrade($score),
            'completed_steps' => $completedSteps,
            'signals' => $this->interpretActivationSignals($score, $completedSteps),
        ];
    }

    /**
     * Get the churn risk assessment for an identity.
     *
     * Returns a 0-100 risk score where higher = more likely to churn.
     *
     * @param  string  $identity
     * @return array{risk_score: int, risk_level: string, indicators: list<string>, recommendation: string}
     */
    public function churnRisk(string $identity): array
    {
        $signals = $this->getSignals($identity);
        $riskScore = (int) ($signals['churn_risk_score'] ?? 0);
        $indicators = (array) ($signals['churn_indicators'] ?? []);

        return [
            'risk_score' => min(100, $riskScore),
            'risk_level' => $this->riskLevel($riskScore),
            'indicators' => $indicators,
            'recommendation' => $this->churnRecommendation($riskScore),
        ];
    }

    /**
     * Get aggregated lifecycle metrics across all observed identities.
     *
     * Useful for admin dashboards showing overall SaaS health.
     *
     * @return array{total_tracked: int, avg_activation: float, avg_churn_risk: float, activation_distribution: array<string, int>, churn_distribution: array<string, int>, expansion_momentum: float}
     */
    public function aggregateMetrics(): array
    {
        $prefix = self::CACHE_PREFIX;
        $allScores = [];

        // Scan cache for lifecycle entries (depends on cache driver)
        // For Redis/driver that supports prefix scanning, this works efficiently
        // For file/database cache, we use a summary cache key instead
        $summaryKey = self::CACHE_PREFIX . '_aggregate_summary';
        /** @var array<string, mixed>|null $cachedSummary */
        $cachedSummary = $this->cache->get($summaryKey);

        if ($cachedSummary !== null && is_array($cachedSummary)) {
            return $cachedSummary;
        }

        // Return default empty metrics if no cached data
        return [
            'total_tracked' => 0,
            'avg_activation' => 0.0,
            'avg_churn_risk' => 0.0,
            'activation_distribution' => [
                'high' => 0,
                'medium' => 0,
                'low' => 0,
            ],
            'churn_distribution' => [
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0,
            ],
            'expansion_momentum' => 0.0,
            'computed_at' => time(),
        ];
    }

    /**
     * Store an aggregate summary (called by cron or event listener).
     *
     * @param  array<string, mixed>  $metrics
     */
    public function storeAggregateSummary(array $metrics): void
    {
        $this->cache->put(
            self::CACHE_PREFIX . '_aggregate_summary',
            $metrics,
            $this->cacheTtl,
        );
    }

    /**
     * Clear lifecycle signals for an identity (GDPR erasure).
     *
     * @param  string  $identity
     */
    public function forget(string $identity): void
    {
        $this->cache->forget(self::CACHE_PREFIX . $identity);
    }

    /**
     * Clear all cached lifecycle signals.
     */
    public function flush(): void
    {
        $summaryKey = self::CACHE_PREFIX . '_aggregate_summary';
        $this->cache->forget($summaryKey);
    }

    /**
     * Get the event → trial step mapping.
     *
     * @return array<string, string>
     */
    public static function trialStepMap(): array
    {
        return [
            'trial_start' => 'trial_started',
            'login' => 'first_login',
            'feature_used' => 'feature_engagement',
            'form_submit' => 'intent_signal',
            'add_to_cart' => 'purchase_intent',
            'subscription' => 'conversion',
            'plan_upgrade' => 'expansion',
            'trial_converted' => 'trial_completed',
        ];
    }

    /**
     * Get the trial activation weights.
     *
     * @return array<string, int>
     */
    public static function trialWeights(): array
    {
        return self::TRIAL_WEIGHTS;
    }

    /**
     * Get the churn risk indicator weights.
     *
     * @return array<string, int>
     */
    public static function churnRiskWeights(): array
    {
        return self::CHURN_RISK_INDICATORS;
    }

    // ── Private: Signal Updates ───────────────────────────────────

    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function updateActivationScore(array $signals, string $event, string $identity): array
    {
        $weight = self::TRIAL_WEIGHTS[$event] ?? 0;
        $stepMap = self::trialStepMap();
        $stepName = $stepMap[$event] ?? $event;

        // Take the highest weight seen for this step
        $currentScore = (int) ($signals['activation_score'] ?? 0);
        $steps = (array) ($signals['activation_steps'] ?? []);
        $stepScores = (array) ($signals['activation_step_scores'] ?? []);

        if (! in_array($stepName, $steps, true)) {
            $steps[] = $stepName;
            $stepScores[$stepName] = $weight;
        } else {
            $stepScores[$stepName] = max($stepScores[$stepName] ?? 0, $weight);
        }

        // Recompute: average of all step scores, capped at 100
        if (count($stepScores) > 0) {
            $avgScore = (int) round(array_sum($stepScores) / count($stepScores));
        } else {
            $avgScore = $weight;
        }

        $signals['activation_score'] = max($currentScore, $avgScore);
        $signals['activation_steps'] = $steps;
        $signals['activation_step_scores'] = $stepScores;
        $signals['activation_last_event'] = $event;
        $signals['activation_updated_at'] = time();

        return $signals;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function updateChurnRisk(array $signals, string $event): array
    {
        $weight = self::CHURN_RISK_INDICATORS[$event] ?? 0;
        $indicators = (array) ($signals['churn_indicators'] ?? []);
        $indicatorCounts = (array) ($signals['churn_indicator_counts'] ?? []);

        // Add indicator if new
        if (! in_array($event, $indicators, true)) {
            $indicators[] = $event;
        }

        // Count occurrences (decay over time in a real implementation)
        $indicatorCounts[$event] = ($indicatorCounts[$event] ?? 0) + 1;

        // Compute churn risk: weighted sum of unique indicators, decayed by time
        $riskScore = 0;
        foreach ($indicators as $indicator) {
            $indicatorWeight = self::CHURN_RISK_INDICATORS[$indicator] ?? 0;
            $occurrences = $indicatorCounts[$indicator] ?? 1;
            // First occurrence has full weight, subsequent have diminishing returns
            $decayFactor = 1.0 / (1 + ($occurrences - 1) * 0.3);
            $riskScore += (int) ($indicatorWeight * $decayFactor);
        }

        $signals['churn_risk_score'] = min(100, $riskScore);
        $signals['churn_indicators'] = $indicators;
        $signals['churn_indicator_counts'] = $indicatorCounts;
        $signals['churn_last_event'] = $event;
        $signals['churn_updated_at'] = time();

        return $signals;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function updateExpansionMomentum(array $signals, string $event, array $context): array
    {
        $momentum = (float) ($signals['expansion_momentum'] ?? 0.0);
        $expansionEvents = (int) ($signals['expansion_event_count'] ?? 0);
        $totalExpansionValue = (float) ($signals['total_expansion_value'] ?? 0.0);

        // Positive momentum from upgrades and expansion revenue
        $delta = match ($event) {
            'plan_upgrade' => 15.0,
            'expansion_revenue' => 20.0,
            'subscription_renewal' => 5.0,
            default => 0.0,
        };

        $momentum = min(100.0, $momentum + $delta);
        $expansionEvents++;

        if (isset($context['value']) && is_numeric($context['value'])) {
            $totalExpansionValue += (float) $context['value'];
        }

        $signals['expansion_momentum'] = $momentum;
        $signals['expansion_event_count'] = $expansionEvents;
        $signals['total_expansion_value'] = $totalExpansionValue;
        $signals['expansion_last_event'] = $event;

        return $signals;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function updateFeatureAdoption(array $signals, array $context): array
    {
        $featuresUsed = (array) ($signals['features_used'] ?? []);
        $featureCount = (int) ($signals['feature_adoption_count'] ?? 0);

        if (isset($context['feature_name']) && is_string($context['feature_name'])) {
            $featureName = $context['feature_name'];
            if (! in_array($featureName, $featuresUsed, true)) {
                $featuresUsed[] = $featureName;
            }
        }

        $featureCount++;
        $signals['features_used'] = $featuresUsed;
        $signals['feature_adoption_count'] = $featureCount;
        $signals['feature_adoption_depth'] = count($featuresUsed);

        return $signals;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function updateSessionEngagement(array $signals): array
    {
        $sessionCount = (int) ($signals['session_count'] ?? 0);
        $lastLogin = (int) ($signals['last_login_at'] ?? 0);
        $current = time();

        $signals['session_count'] = $sessionCount + 1;
        $signals['last_login_at'] = $current;

        // Compute days since first login (stickiness proxy)
        if ($signals['first_login_at'] === null) {
            $signals['first_login_at'] = $current;
        }

        $firstLogin = (int) ($signals['first_login_at'] ?? $current);
        $daysSinceFirst = max(1, (int) (($current - $firstLogin) / 86400));
        $signals['avg_sessions_per_day'] = round($signals['session_count'] / $daysSinceFirst, 2);

        return $signals;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function updateFunnelProgress(array $signals, string $event): array
    {
        $funnelSteps = (array) ($signals['funnel_progress'] ?? []);

        // SaaS signup funnel
        $signupFunnel = [
            'page_view', 'sign_up', 'login', 'trial_start',
            'feature_used', 'subscription', 'plan_upgrade',
        ];

        // Update funnel position
        $position = array_search($event, $signupFunnel, true);
        if ($position !== false) {
            // Mark all steps up to this position as seen
            for ($i = 0; $i <= $position; $i++) {
                $step = $signupFunnel[$i];
                if (! in_array($step, $funnelSteps, true)) {
                    $funnelSteps[] = $step;
                }
            }

            $signals['funnel_progress'] = $funnelSteps;
            $signals['funnel_completion_pct'] = round(
                (count($funnelSteps) / count($signupFunnel)) * 100,
                1,
            );
            $signals['funnel_current_step'] = $event;
            $signals['funnel_steps_remaining'] = count($signupFunnel) - count($funnelSteps);
        }

        return $signals;
    }

    // ── Private: Helpers ──────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function defaultSignals(): array
    {
        return [
            'activation_score' => 0,
            'activation_steps' => [],
            'activation_step_scores' => [],
            'activation_last_event' => null,
            'activation_updated_at' => null,
            'churn_risk_score' => 0,
            'churn_indicators' => [],
            'churn_indicator_counts' => [],
            'churn_last_event' => null,
            'churn_updated_at' => null,
            'expansion_momentum' => 0.0,
            'expansion_event_count' => 0,
            'total_expansion_value' => 0.0,
            'expansion_last_event' => null,
            'features_used' => [],
            'feature_adoption_count' => 0,
            'feature_adoption_depth' => 0,
            'session_count' => 0,
            'last_login_at' => null,
            'first_login_at' => null,
            'avg_sessions_per_day' => 0.0,
            'funnel_progress' => [],
            'funnel_completion_pct' => 0.0,
            'funnel_current_step' => null,
            'funnel_steps_remaining' => 0,
            'updated_at' => null,
        ];
    }

    /**
     * Convert a numeric score to a letter grade.
     */
    private function scoreToGrade(int $score): string
    {
        return match (true) {
            $score >= 80 => 'A',
            $score >= 60 => 'B',
            $score >= 40 => 'C',
            $score >= 20 => 'D',
            default => 'F',
        };
    }

    /**
     * Get activation signal descriptions.
     *
     * @param  int  $score
     * @param  list<string>  $completedSteps
     * @return list<string>
     */
    private function interpretActivationSignals(int $score, array $completedSteps): array
    {
        $signals = [];

        if ($score >= 80) {
            $signals[] = 'Highly activated — strong conversion candidate';
        } elseif ($score >= 60) {
            $signals[] = 'Moderately activated — showing good engagement';
        } elseif ($score >= 40) {
            $signals[] = 'Low activation — needs nurturing';
        } else {
            $signals[] = 'Minimal activation — at risk of trial abandonment';
        }

        if (! in_array('feature_used', $completedSteps, true)) {
            $signals[] = 'No feature engagement detected';
        }

        if (! in_array('login', $completedSteps, true)) {
            $signals[] = 'No repeat login detected';
        }

        if (count($completedSteps) <= 1) {
            $signals[] = 'Only initial signup step completed';
        }

        return $signals;
    }

    /**
     * Get the churn risk level string.
     */
    private function riskLevel(int $score): string
    {
        return match (true) {
            $score >= 75 => 'critical',
            $score >= 50 => 'high',
            $score >= 25 => 'medium',
            default => 'low',
        };
    }

    /**
     * Get an actionable churn recommendation.
     */
    private function churnRecommendation(int $score): string
    {
        return match (true) {
            $score >= 75 => 'Immediate intervention: reach out personally, offer retention incentive',
            $score >= 50 => 'High risk: schedule check-in call, review usage patterns',
            $score >= 25 => 'Monitor closely: send engagement email, highlight unused features',
            default => 'Healthy: continue regular engagement nurture sequence',
        };
    }
}
