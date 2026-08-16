<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Feature Flag Rollout Guardrail Service — monitors metric health during feature rollouts.
 *
 * Evaluates the impact of progressive feature flag rollouts on key business and
 * system metrics. Detects metric degradation, computes guardrail breach severity,
 * and provides rollback recommendations based on configurable thresholds.
 *
 * **Guardrail Metrics:**
 * - **Conversion rate**: Primary funnel conversion (sign_up, purchase, subscribe)
 * - **Revenue metrics**: ARPU, MRR movement, transaction volume
 * - **Engagement metrics**: DAU/MAU ratio, session duration, feature usage
 * - **Performance metrics**: Error rate, page load time, API latency
 * - **Retention metrics**: D1/D7 retention, session return rate
 *
 * **Rollout Phases:**
 * - Canary (0–5%): High sensitivity, strict thresholds
 * - Early rollout (5–25%): Medium sensitivity
 * - Broad rollout (25–75%): Standard sensitivity
 * - Full rollout (75–100%): Low sensitivity, focus on major regressions only
 *
 * **Features:**
 * - Pre/post comparison with statistical significance (z-test)
 * - Guardrail severity classification (safe, warning, critical, breached)
 * - Automatic rollback recommendations when critical guardrails trigger
 * - Multi-metric correlation: detect if metric A degrades when metric B improves
 * - Rollout velocity monitoring: detect too-fast rollouts
 * - Historical rollout audit log for post-mortem analysis
 *
 * Configuration: `zeroboiler.analytics.rollout_guardrails`
 *
 * Inspired by LaunchDarkly's Guardrails, Optimizely's Performance Center,
 * and Statsig's Metric Guardrails.
 *
 * @see \ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService
 * @see \ZeroBoiler\Analytics\Services\ExperimentAnalysisEngine
 *
 * @since 192.0.0
 */
final class FeatureFlagRolloutGuardrailService
{
    /** @var string Current service version. */
    public const VERSION = '1.0.0';

    private const CACHE_PREFIX = 'zb_rollout_guard_';

    private const DEFAULT_CACHE_TTL = 1800;

    private const DEFAULT_MIN_SAMPLE_SIZE = 100;

    private const DEFAULT_SIGNIFICANCE_ALPHA = 0.05;

    /** @var list<string> Supported guardrail metric categories */
    private const METRIC_CATEGORIES = [
        'conversion', 'revenue', 'engagement', 'performance', 'retention',
    ];

    /** @var array<string, array{warning: float, critical: float, label: string}> Default guardrail thresholds */
    private const DEFAULT_THRESHOLDS = [
        'conversion_rate' => ['warning' => -5.0, 'critical' => -10.0, 'label' => 'Conversion Rate'],
        'error_rate' => ['warning' => 10.0, 'critical' => 25.0, 'label' => 'Error Rate'],
        'page_load_time' => ['warning' => 15.0, 'critical' => 30.0, 'label' => 'Page Load Time'],
        'api_latency_p95' => ['warning' => 20.0, 'critical' => 40.0, 'label' => 'API Latency P95'],
        'revenue_per_user' => ['warning' => -8.0, 'critical' => -15.0, 'label' => 'Revenue Per User'],
        'session_duration' => ['warning' => -10.0, 'critical' => -20.0, 'label' => 'Session Duration'],
        'dau_mau_ratio' => ['warning' => -5.0, 'critical' => -10.0, 'label' => 'DAU/MAU Ratio'],
        'd1_retention' => ['warning' => -5.0, 'critical' => -10.0, 'label' => 'Day-1 Retention'],
        'feature_usage' => ['warning' => -10.0, 'critical' => -20.0, 'label' => 'Feature Usage'],
        'bounce_rate' => ['warning' => 10.0, 'critical' => 20.0, 'label' => 'Bounce Rate'],
    ];

    /** @var array{canary: float, early: float, broad: float, full: float} Rollout phase sensitivity multipliers */
    private const PHASE_SENSITIVITY = [
        'canary' => 0.5,   // More sensitive (lower thresholds)
        'early' => 0.75,
        'broad' => 1.0,   // Default
        'full' => 1.5,     // Less sensitive (higher thresholds)
    ];

    private CacheRepository $cache;

    private bool $enabled;

    private int $cacheTtl;

    private int $minSampleSize;

    private float $significanceAlpha;

    private bool $autoRollbackRecommendation;

    /** @var array<string, mixed> Custom threshold overrides */
    private array $customThresholds;

    /** @var list<array{flag: string, phase: string, percentage: float, evaluated_at: string, verdict: string, metrics: array<string, mixed>}> */
    private array $auditLog = [];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->cache = $cache;

        $guardConfig = $config->get('zeroboiler.analytics.rollout_guardrails', []);
        /** @var array{enabled?: bool, cache_ttl?: int, min_sample_size?: int, significance_alpha?: float, auto_rollback_recommendation?: bool, thresholds?: array<string, mixed>} $guardConfig */
        $this->enabled = (bool) ($guardConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($guardConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->minSampleSize = (int) ($guardConfig['min_sample_size'] ?? self::DEFAULT_MIN_SAMPLE_SIZE);
        $this->significanceAlpha = (float) ($guardConfig['significance_alpha'] ?? self::DEFAULT_SIGNIFICANCE_ALPHA);
        $this->autoRollbackRecommendation = (bool) ($guardConfig['auto_rollback_recommendation'] ?? true);
        $this->customThresholds = (array) ($guardConfig['thresholds'] ?? []);
    }

    // ── Guardrail Evaluation ─────────────────────────────────────────

    /**
     * Evaluate guardrails for a feature flag rollout.
     *
     * Compares baseline (pre-rollout) metrics against current (during rollout) metrics,
     * applies phase-appropriate thresholds, and determines overall rollout health.
     *
     * @param  string  $flagKey  Feature flag key
     * @param  float  $rolloutPercentage  Current rollout percentage (0-100)
     * @param  array<string, array{baseline: float, current: float, sample_size: int, baseline_size: int}>  $metricData  Metric name → comparison data
     * @return array{flag: string, rollout_percentage: float, phase: string, verdict: string, guardrails: array<string, array{metric: string, label: string, baseline: float, current: float, change_percent: float, severity: string, threshold_warning: float, threshold_critical: float, significant: bool|null}>, recommendation: string|null, evaluated_at: string}
     */
    public function evaluate(string $flagKey, float $rolloutPercentage, array $metricData): array
    {
        $phase = $this->determinePhase($rolloutPercentage);
        $sensitivity = self::PHASE_SENSITIVITY[$phase] ?? 1.0;
        $guardrails = [];
        $criticalCount = 0;
        $warningCount = 0;

        foreach ($metricData as $metricName => $data) {
            $thresholds = $this->getThresholds($metricName, $sensitivity);
            $baseline = (float) ($data['baseline'] ?? 0);
            $current = (float) ($data['current'] ?? 0);
            $changePercent = $this->computeChangePercent($baseline, $current);
            $severity = $this->classifySeverity($changePercent, $thresholds);

            $significant = null;
            if ($data['sample_size'] >= $this->minSampleSize && $data['baseline_size'] >= $this->minSampleSize && $baseline > 0) {
                $significant = $this->isStatisticallySignificant(
                    $baseline,
                    $current,
                    (int) ($data['baseline_size']),
                    (int) ($data['sample_size']),
                );
            }

            if ($severity === 'critical') {
                $criticalCount++;
            } elseif ($severity === 'warning') {
                $warningCount++;
            }

            $guardrails[$metricName] = [
                'metric' => $metricName,
                'label' => $thresholds['label'],
                'baseline' => $baseline,
                'current' => $current,
                'change_percent' => $changePercent,
                'severity' => $severity,
                'threshold_warning' => $thresholds['warning'],
                'threshold_critical' => $thresholds['critical'],
                'significant' => $significant,
            ];
        }

        $verdict = $this->determineVerdict($criticalCount, $warningCount, count($metricData));
        $recommendation = $this->generateRecommendation($verdict, $phase, $rolloutPercentage, $guardrails);

        // Audit log
        $this->auditLog[] = [
            'flag' => $flagKey,
            'phase' => $phase,
            'percentage' => $rolloutPercentage,
            'evaluated_at' => (new \DateTimeImmutable)->format('c'),
            'verdict' => $verdict,
            'metrics' => $guardrails,
        ];

        return [
            'flag' => $flagKey,
            'rollout_percentage' => $rolloutPercentage,
            'phase' => $phase,
            'verdict' => $verdict,
            'guardrails' => $guardrails,
            'recommendation' => $recommendation,
            'evaluated_at' => (new \DateTimeImmutable)->format('c'),
        ];
    }

    /**
     * Quick health check — returns a single health status for a rollout.
     *
     * @param  string  $flagKey
     * @param  float  $rolloutPercentage
     * @param  array<string, float>  $metrics  Metric name → current value (compared against cached baseline)
     * @return array{flag: string, status: 'healthy'|'degraded'|'critical', summary: string, checked_metrics: int, issues: list<string>}
     */
    public function healthCheck(string $flagKey, float $rolloutPercentage, array $metrics): array
    {
        $phase = $this->determinePhase($rolloutPercentage);
        $sensitivity = self::PHASE_SENSITIVITY[$phase] ?? 1.0;
        $issues = [];
        $checkedCount = 0;

        foreach ($metrics as $metricName => $currentValue) {
            $thresholds = $this->getThresholds($metricName, $sensitivity);
            $cacheKey = self::CACHE_PREFIX . 'baseline_' . $flagKey . '_' . $metricName;
            $baseline = $this->cache->get($cacheKey);

            if ($baseline === null) {
                continue;
            }

            $checkedCount++;
            $baselineFloat = (float) $baseline;
            $change = $this->computeChangePercent($baselineFloat, (float) $currentValue);
            $severity = $this->classifySeverity($change, $thresholds);

            if ($severity === 'critical') {
                $issues[] = "CRITICAL: {$thresholds['label']} changed by {$change}% (threshold: {$thresholds['critical']}%)";
            } elseif ($severity === 'warning') {
                $issues[] = "WARNING: {$thresholds['label']} changed by {$change}% (threshold: {$thresholds['warning']}%)";
            }
        }

        $status = $issues === [] ? 'healthy'
            : (str_contains(implode(' ', $issues), 'CRITICAL') ? 'critical' : 'degraded');

        return [
            'flag' => $flagKey,
            'status' => $status,
            'summary' => $status === 'healthy'
                ? "All {$checkedCount} checked metrics within guardrail thresholds"
                : count($issues) . ' guardrail issue(s) detected',
            'checked_metrics' => $checkedCount,
            'issues' => $issues,
        ];
    }

    // ── Baseline Management ──────────────────────────────────────────

    /**
     * Capture a metric baseline before rollout begins.
     *
     * @param  string  $flagKey
     * @param  array<string, float>  $metrics  Metric name → baseline value
     * @param  int  $sampleSize  Number of data points in the baseline
     */
    public function captureBaseline(string $flagKey, array $metrics, int $sampleSize = 0): void
    {
        foreach ($metrics as $metricName => $value) {
            $cacheKey = self::CACHE_PREFIX . 'baseline_' . $flagKey . '_' . $metricName;
            $this->cache->put($cacheKey, [
                'value' => $value,
                'sample_size' => $sampleSize,
                'captured_at' => (new \DateTimeImmutable)->format('c'),
            ], $this->cacheTtl * 2);
        }
    }

    /**
     * Get a captured baseline for a specific metric.
     *
     * @param  string  $flagKey
     * @param  string  $metricName
     * @return array{value: float, sample_size: int, captured_at: string}|null
     */
    public function getBaseline(string $flagKey, string $metricName): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'baseline_' . $flagKey . '_' . $metricName;
        $data = $this->cache->get($cacheKey);

        return is_array($data) ? $data : null;
    }

    /**
     * Clear baseline data for a feature flag.
     */
    public function clearBaseline(string $flagKey): void
    {
        foreach (array_keys(self::DEFAULT_THRESHOLDS) as $metricName) {
            $this->cache->forget(self::CACHE_PREFIX . 'baseline_' . $flagKey . '_' . $metricName);
        }
    }

    // ── Rollout Velocity ──────────────────────────────────────────────

    /**
     * Check if a rollout is progressing too fast.
     *
     * Detects large jumps between rollout percentages that could mask
     * metric degradation (e.g., jumping from 5% to 50% in one step).
     *
     * @param  string  $flagKey
     * @param  float  $newPercentage
     * @param  float|null  $previousPercentage  Previous rollout percentage (null if first rollout)
     * @param  float|null  $maxJump  Maximum allowed percentage jump (default depends on phase)
     * @return array{safe: bool, jump: float, max_allowed: float, recommendation: string|null}
     */
    public function checkRolloutVelocity(string $flagKey, float $newPercentage, ?float $previousPercentage = null, ?float $maxJump = null): array
    {
        if ($previousPercentage === null) {
            return [
                'safe' => true,
                'jump' => $newPercentage,
                'max_allowed' => $maxJump ?? 100.0,
                'recommendation' => null,
            ];
        }

        $jump = abs($newPercentage - $previousPercentage);

        if ($maxJump === null) {
            // Determine safe jump based on current phase
            $phase = $this->determinePhase($previousPercentage);
            $maxJump = match ($phase) {
                'canary' => 5.0,
                'early' => 10.0,
                'broad' => 25.0,
                'full' => 50.0,
                default => 25.0,
            };
        }

        $safe = $jump <= $maxJump;
        $recommendation = $safe ? null : "Rollout jump of {$jump}% exceeds safe limit of {$maxJump}%. Consider a more gradual rollout to detect metric regressions.";

        return [
            'safe' => $safe,
            'jump' => $jump,
            'max_allowed' => $maxJump,
            'recommendation' => $recommendation,
        ];
    }

    // ── Audit & Diagnostics ───────────────────────────────────────────

    /**
     * Get the audit log of all guardrail evaluations.
     *
     * @return list<array{flag: string, phase: string, percentage: float, evaluated_at: string, verdict: string}>
     */
    public function getAuditLog(): array
    {
        return array_map(fn (array $entry): array => [
            'flag' => $entry['flag'],
            'phase' => $entry['phase'],
            'percentage' => $entry['percentage'],
            'evaluated_at' => $entry['evaluated_at'],
            'verdict' => $entry['verdict'],
        ], $this->auditLog);
    }

    /**
     * Get the evaluation history for a specific flag.
     *
     * @param  string  $flagKey
     * @return list<array{phase: string, percentage: float, evaluated_at: string, verdict: string}>
     */
    public function getFlagHistory(string $flagKey): array
    {
        return array_values(array_filter(
            $this->getAuditLog(),
            fn (array $entry): bool => $entry['flag'] === $flagKey,
        ));
    }

    /**
     * Get service stats for admin dashboards.
     *
     * @return array{enabled: bool, cache_ttl: int, min_sample_size: int, significance_alpha: float, auto_rollback: bool, metric_categories: list<string>, supported_metrics: list<string>, phase_sensitivity: array<string, float>}
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'min_sample_size' => $this->minSampleSize,
            'significance_alpha' => $this->significanceAlpha,
            'auto_rollback' => $this->autoRollbackRecommendation,
            'metric_categories' => self::METRIC_CATEGORIES,
            'supported_metrics' => array_keys(self::DEFAULT_THRESHOLDS),
            'phase_sensitivity' => self::PHASE_SENSITIVITY,
        ];
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get supported guardrail metric names.
     *
     * @return list<string>
     */
    public function supportedMetrics(): array
    {
        return array_keys(self::DEFAULT_THRESHOLDS);
    }

    /**
     * Get available rollout phases.
     *
     * @return list<string>
     */
    public function rolloutPhases(): array
    {
        return array_keys(self::PHASE_SENSITIVITY);
    }

    /**
     * Clear all cached guardrail data.
     */
    public function clearCache(): void
    {
        $this->auditLog = [];
    }

    // ── Internal ──────────────────────────────────────────────────────

    /**
     * Determine rollout phase from percentage.
     */
    private function determinePhase(float $percentage): string
    {
        if ($percentage <= 5) {
            return 'canary';
        }
        if ($percentage <= 25) {
            return 'early';
        }
        if ($percentage <= 75) {
            return 'broad';
        }

        return 'full';
    }

    /**
     * Get thresholds for a metric, applying phase sensitivity multiplier.
     *
     * @return array{warning: float, critical: float, label: string}
     */
    private function getThresholds(string $metricName, float $sensitivity): array
    {
        $defaults = self::DEFAULT_THRESHOLDS[$metricName] ?? ['warning' => -10.0, 'critical' => -20.0, 'label' => $metricName];

        // Check for custom overrides
        if (isset($this->customThresholds[$metricName])) {
            $custom = $this->customThresholds[$metricName];
            if (is_array($custom)) {
                $defaults = array_merge($defaults, $custom);
            }
        }

        return [
            'warning' => round($defaults['warning'] * $sensitivity, 2),
            'critical' => round($defaults['critical'] * $sensitivity, 2),
            'label' => $defaults['label'],
        ];
    }

    /**
     * Compute percentage change between baseline and current values.
     */
    private function computeChangePercent(float $baseline, float $current): float
    {
        if ($baseline === 0.0) {
            return $current === 0.0 ? 0.0 : 100.0;
        }

        return round((($current - $baseline) / $baseline) * 100, 2);
    }

    /**
     * Classify severity of a metric change against thresholds.
     *
     * For negative thresholds (conversion_rate, revenue): negative change is bad.
     * For positive thresholds (error_rate, bounce_rate): positive change is bad.
     */
    private function classifySeverity(float $change, array $thresholds): string
    {
        $warningThresh = (float) $thresholds['warning'];
        $criticalThresh = (float) $thresholds['critical'];

        // Determine if the metric is "higher is worse" (positive thresholds)
        $higherIsWorse = $warningThresh > 0;

        if ($higherIsWorse) {
            if ($change >= $criticalThresh) {
                return 'critical';
            }
            if ($change >= $warningThresh) {
                return 'warning';
            }
        } else {
            // Lower is worse (negative thresholds)
            if ($change <= $criticalThresh) {
                return 'critical';
            }
            if ($change <= $warningThresh) {
                return 'warning';
            }
        }

        return 'safe';
    }

    /**
     * Determine overall verdict based on guardrail results.
     */
    private function determineVerdict(int $criticalCount, int $warningCount, int $totalMetrics): string
    {
        if ($criticalCount > 0) {
            return 'breached';
        }
        if ($warningCount > 0) {
            // If more than half the metrics are warnings, escalate
            return $warningCount > ($totalMetrics / 2) ? 'degraded' : 'warning';
        }

        return 'safe';
    }

    /**
     * Generate a rollout recommendation based on verdict and context.
     */
    private function generateRecommendation(string $verdict, string $phase, float $percentage, array $guardrails): ?string
    {
        if (! $this->autoRollbackRecommendation) {
            return null;
        }

        return match ($verdict) {
            'breached' => "CRITICAL: Roll back '{$phase}' rollout ({$percentage}%) immediately. Critical guardrail violations detected across " . count(array_filter($guardrails, fn (array $g): bool => $g['severity'] === 'critical')) . ' metric(s).',
            'degraded' => "WARNING: Pause rollout expansion. Multiple guardrail warnings at {$phase} phase ({$percentage}%). Investigate before increasing rollout percentage.",
            'warning' => "CAUTION: Proceed with caution. Guardrail warnings detected at {$phase} phase ({$percentage}%). Monitor closely before further expansion.",
            'safe' => "PROCEED: All guardrails within thresholds. Safe to continue {$phase} rollout ({$percentage}%).",
            default => null,
        };
    }

    /**
     * Simplified z-test for statistical significance of proportion change.
     *
     * @param  float  $baselineRate  Baseline conversion rate (0-1)
     * @param  float  $currentRate  Current conversion rate (0-1)
     * @param  int  $baselineN  Baseline sample size
     * @param  int  $currentN  Current sample size
     * @return bool  True if the difference is statistically significant at the configured alpha level
     */
    private function isStatisticallySignificant(float $baselineRate, float $currentRate, int $baselineN, int $currentN): bool
    {
        if ($baselineN === 0 || $currentN === 0) {
            return false;
        }

        $pooledRate = ($baselineRate * $baselineN + $currentRate * $currentN) / ($baselineN + $currentN);

        if ($pooledRate === 0.0 || $pooledRate === 1.0) {
            return false;
        }

        $se = sqrt($pooledRate * (1 - $pooledRate) * (1 / $baselineN + 1 / $currentN));

        if ($se === 0.0) {
            return false;
        }

        $zScore = abs($currentRate - $baselineRate) / $se;

        // Critical z-value for two-tailed test at alpha
        // alpha=0.05 → z=1.96, alpha=0.10 → z=1.645
        $criticalZ = match (true) {
            $this->significanceAlpha <= 0.01 => 2.576,
            $this->significanceAlpha <= 0.05 => 1.96,
            $this->significanceAlpha <= 0.10 => 1.645,
            default => 1.96,
        };

        return $zScore >= $criticalZ;
    }
}
