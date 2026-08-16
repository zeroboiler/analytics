<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Analytics Composite Health Index — unified health score across all analytics dimensions.
 *
 * Combines multiple signals into a single 0-100 score with weighted dimensions:
 *
 * 1. **Provider Coverage** (20%): What % of enabled providers are healthy
 * 2. **Event Catalog Completeness** (15%): How many recommended events are tracked
 * 3. **Data Quality** (20%): Average quality score from DataQualityScoringEngine
 * 4. **Dispatch Reliability** (20%): Provider failure rates and success rates
 * 5. **Event Volume Health** (10%): Anomaly detection on event volumes
 * 6. **Consent Compliance** (15%): GDPR consent readiness and consent log health
 *
 * Produces a letter grade (A+ through F) and dimension-level breakdowns
 * for dashboard rendering and alerting.
 *
 * Inspired by Datadog Composite Health Score, New Relic Apdex, and
 * Segment Observability Score.
 *
 * Configuration: `zeroboiler.analytics.composite_health`
 *
 * @see \ZeroBoiler\Analytics\Services\DataQualityScoringEngine
 * @see \ZeroBoiler\Analytics\Services\AnalyticsEventReliabilityService
 * @see \ZeroBoiler\Analytics\Services\EventSignalIntelligenceService
 *
 * @phpstan-type DimensionScore array{name: string, weight: float, score: float, grade: string, status: 'healthy'|'warning'|'critical', details: string}
 * @phpstan-type HealthReport array{score: float, grade: string, dimensions: array<string, DimensionScore>, recommendations: list<string>, computed_at: string, cache_key: string}
 *
 * @since 204.0.0
 */
final class AnalyticsCompositeHealthIndex
{
    private const CACHE_PREFIX = 'zb_composite_health_';

    private const DEFAULT_CACHE_TTL = 300; // 5 minutes

    private const GRADE_THRESHOLDS = [
        'A+' => 97.0,
        'A' => 93.0,
        'A-' => 90.0,
        'B+' => 87.0,
        'B' => 83.0,
        'B-' => 80.0,
        'C+' => 75.0,
        'C' => 70.0,
        'C-' => 65.0,
        'D' => 55.0,
        'F' => 0.0,
    ];

    /** @var array<string, float> Configurable dimension weights (must sum to 1.0) */
    private readonly array $dimensionWeights;

    private readonly int $cacheTtl;

    private readonly bool $enabled;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     * @param  AnalyticsMetrics  $metrics
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        private readonly AnalyticsMetrics $metrics,
    ): void {
        $healthConfig = $config->get('zeroboiler.analytics.composite_health', []);
        /** @var array{enabled?: bool, cache_ttl?: int, weights?: array<string, float>} $healthConfig */

        $this->enabled = (bool) ($healthConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($healthConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);

        $defaultWeights = [
            'provider_coverage' => 0.20,
            'catalog_completeness' => 0.15,
            'data_quality' => 0.20,
            'dispatch_reliability' => 0.20,
            'event_volume_health' => 0.10,
            'consent_compliance' => 0.15,
        ];

        $customWeights = $healthConfig['weights'] ?? [];
        $this->dimensionWeights = array_merge($defaultWeights, $customWeights);
    }

    /**
     * Compute the composite health index.
     *
     * Evaluates all dimensions and produces a weighted score.
     * Results are cached for the configured TTL.
     *
     * @return HealthReport
     */
    public function compute(): array
    {
        if (! $this->enabled) {
            return $this->disabledReport();
        }

        $cacheKey = self::CACHE_PREFIX . 'report';

        return $this->cache->remember($cacheKey, $this->cacheTtl, function (): array {
            return $this->computeFresh();
        });
    }

    /**
     * Force-compute the health index bypassing cache.
     *
     * @return HealthReport
     */
    public function computeFresh(): array
    {
        $dimensions = [
            'provider_coverage' => $this->evaluateProviderCoverage(),
            'catalog_completeness' => $this->evaluateCatalogCompleteness(),
            'data_quality' => $this->evaluateDataQuality(),
            'dispatch_reliability' => $this->evaluateDispatchReliability(),
            'event_volume_health' => $this->evaluateEventVolumeHealth(),
            'consent_compliance' => $this->evaluateConsentCompliance(),
        ];

        $totalScore = 0.0;
        foreach ($dimensions as $key => $dimension) {
            $weight = $this->dimensionWeights[$key] ?? 0.0;
            $totalScore += $dimension['score'] * $weight;
        }

        $score = min(100.0, max(0.0, round($totalScore, 2)));
        $grade = $this->scoreToGrade($score);
        $recommendations = $this->generateRecommendations($dimensions, $score, $grade);

        return [
            'score' => $score,
            'grade' => $grade,
            'dimensions' => $dimensions,
            'recommendations' => $recommendations,
            'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'cache_key' => self::CACHE_PREFIX . 'report',
        ];
    }

    /**
     * Invalidate the cached health report.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'report');
    }

    /**
     * Get a single dimension score without computing the full index.
     *
     * @param  string  $dimension  Dimension name (provider_coverage, catalog_completeness, etc.)
     * @return DimensionScore|null
     */
    public function getDimensionScore(string $dimension): ?array
    {
        $map = [
            'provider_coverage' => fn (): array => $this->evaluateProviderCoverage(),
            'catalog_completeness' => fn (): array => $this->evaluateCatalogCompleteness(),
            'data_quality' => fn (): array => $this->evaluateDataQuality(),
            'dispatch_reliability' => fn (): array => $this->evaluateDispatchReliability(),
            'event_volume_health' => fn (): array => $this->evaluateEventVolumeHealth(),
            'consent_compliance' => fn (): array => $this->evaluateConsentCompliance(),
        ];

        $evaluator = $map[$dimension] ?? null;

        if ($evaluator === null) {
            return null;
        }

        return $evaluator();
    }

    /**
     * Get the health trend over time (comparing current vs. previous score).
     *
     * @return array{current: float, previous: float|null, delta: float, direction: 'improving'|'stable'|'declining'}
     */
    public function trend(): array
    {
        $current = $this->compute();
        $previousKey = self::CACHE_PREFIX . 'previous';

        /** @var array{score: float}|null $previous */
        $previous = $this->cache->get($previousKey);

        // Rotate current to previous for next comparison
        $this->cache->put($previousKey, ['score' => $current['score']], $this->cacheTtl * 12);

        $previousScore = $previous['score'] ?? null;
        $delta = $previousScore !== null ? round($current['score'] - $previousScore, 2) : 0.0;

        return [
            'current' => $current['score'],
            'previous' => $previousScore,
            'delta' => $delta,
            'direction' => $delta > 0.5 ? 'improving' : ($delta < -0.5 ? 'declining' : 'stable'),
        ];
    }

    /**
     * Convert a numeric score to a letter grade.
     *
     * @return 'A+'|'A'|'A-'|'B+'|'B'|'B-'|'C+'|'C'|'C-'|'D'|'F'
     */
    public function scoreToGrade(float $score): string
    {
        foreach (self::GRADE_THRESHOLDS as $grade => $threshold) {
            if ($score >= $threshold) {
                return $grade;
            }
        }

        return 'F';
    }

    /**
     * Check if the health index is currently in a degraded state.
     */
    public function isDegraded(): bool
    {
        $report = $this->compute();

        return $report['score'] < 70.0;
    }

    /**
     * Check if any dimension is in critical state.
     */
    public function hasCriticalDimension(): bool
    {
        $report = $this->compute();

        foreach ($report['dimensions'] as $dimension) {
            if (($dimension['status'] ?? '') === 'critical') {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate provider coverage dimension.
     *
     * Checks how many enabled providers are reporting successful dispatches.
     *
     * @return DimensionScore
     */
    private function evaluateProviderCoverage(): array
    {
        $providers = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

        $enabledCount = 0;
        $healthyCount = 0;

        foreach ($providers as $provider) {
            $enabled = $this->config->get("zeroboiler.analytics.{$provider}.enabled", false);

            if (! $enabled) {
                continue;
            }

            $enabledCount++;
            $failureRate = $this->metrics->failureRate($provider);

            if ($failureRate < 0.10) {
                $healthyCount++;
            }
        }

        if ($enabledCount === 0) {
            return $this->buildDimension('Provider Coverage', 0.20, 0.0, 'No providers enabled');
        }

        $score = round(($healthyCount / $enabledCount) * 100.0, 2);
        $details = sprintf('%d/%d providers healthy (%.1f%% coverage)', $healthyCount, $enabledCount, $score);

        return $this->buildDimension('Provider Coverage', 0.20, $score, $details);
    }

    /**
     * Evaluate catalog completeness dimension.
     *
     * Measures how many recommended events from the catalog are being tracked.
     *
     * @return DimensionScore
     */
    private function evaluateCatalogCompleteness(): array
    {
        try {
            $recommended = EventCatalog::recommendedInstrumentation('starter');
            $totalRecommended = count($recommended['events'] ?? []);

            if ($totalRecommended === 0) {
                return $this->buildDimension('Catalog Completeness', 0.15, 100.0, 'No events recommended — catalog empty');
            }

            $gaps = $recommended['summary']['gaps'] ?? [];
            $gapCount = is_array($gaps) ? count($gaps) : 0;
            $trackedCount = $totalRecommended - $gapCount;

            $score = round(($trackedCount / $totalRecommended) * 100.0, 2);
            $details = sprintf('%d/%d recommended events tracked (%.1f%%)', $trackedCount, $totalRecommended, $score);

            return $this->buildDimension('Catalog Completeness', 0.15, $score, $details);
        } catch (\Throwable) {
            return $this->buildDimension('Catalog Completeness', 0.15, 50.0, 'Unable to evaluate catalog completeness');
        }
    }

    /**
     * Evaluate data quality dimension.
     *
     * Samples recent events and computes average quality score.
     *
     * @return DimensionScore
     */
    private function evaluateDataQuality(): array
    {
        try {
            $events = $this->metrics->recentEvents(10);
            $totalScore = 0.0;
            $count = 0;

            foreach ($events as $event) {
                $engine = new DataQualityScoringEngine(freshnessThreshold: 60);
                $result = $engine->scoreEvent($event);
                $totalScore += $result['score'];
                $count++;
            }

            if ($count === 0) {
                return $this->buildDimension('Data Quality', 0.20, 0.0, 'No events to evaluate');
            }

            $avgScore = round($totalScore / $count, 2);
            $details = sprintf('Average quality score: %.1f/100 (%d events sampled)', $avgScore, $count);

            return $this->buildDimension('Data Quality', 0.20, $avgScore, $details);
        } catch (\Throwable) {
            return $this->buildDimension('Data Quality', 0.20, 50.0, 'Quality scoring unavailable');
        }
    }

    /**
     * Evaluate dispatch reliability dimension.
     *
     * Measures overall provider success/failure rates.
     *
     * @return DimensionScore
     */
    private function evaluateDispatchReliability(): array
    {
        try {
            $providers = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

            $totalSuccess = 0;
            $totalFailure = 0;

            foreach ($providers as $provider) {
                $totalSuccess += $this->metrics->dispatchCount($provider);
                $totalFailure += $this->metrics->failureCount($provider);
            }

            $totalDispatched = $totalSuccess + $totalFailure;

            if ($totalDispatched === 0) {
                return $this->buildDimension('Dispatch Reliability', 0.20, 0.0, 'No events dispatched yet');
            }

            $score = round(($totalSuccess / $totalDispatched) * 100.0, 2);
            $details = sprintf(
                '%d/%d events delivered successfully (%.1f%% reliability)',
                $totalSuccess,
                $totalDispatched,
                $score
            );

            return $this->buildDimension('Dispatch Reliability', 0.20, $score, $details);
        } catch (\Throwable) {
            return $this->buildDimension('Dispatch Reliability', 0.20, 50.0, 'Dispatch reliability unavailable');
        }
    }

    /**
     * Evaluate event volume health dimension.
     *
     * Checks for abnormal volume patterns (spikes or drops).
     *
     * @return DimensionScore
     */
    private function evaluateEventVolumeHealth(): array
    {
        try {
            $currentVolume = $this->metrics->totalDispatches();

            if ($currentVolume === 0) {
                return $this->buildDimension('Event Volume Health', 0.10, 0.0, 'No event volume recorded');
            }

            // Compare against cached baseline
            $baselineKey = self::CACHE_PREFIX . 'volume_baseline';
            /** @var int|null $baseline */
            $baseline = $this->cache->get($baselineKey);

            if ($baseline === null || $baseline === 0) {
                $this->cache->put($baselineKey, $currentVolume, 3600);

                return $this->buildDimension('Event Volume Health', 0.10, 90.0, 'Baseline established — next evaluation will compare');
            }

            // Compute deviation from baseline
            $deviation = abs($currentVolume - $baseline) / max(1, $baseline);
            $score = max(0.0, min(100.0, round(100.0 - ($deviation * 50.0), 2)));

            $details = sprintf(
                '%d events (baseline: %d, deviation: %.1f%%)',
                $currentVolume,
                $baseline,
                $deviation * 100.0
            );

            // Update baseline slowly (exponential moving average)
            $newBaseline = (int) round($baseline * 0.8 + $currentVolume * 0.2);
            $this->cache->put($baselineKey, $newBaseline, 3600);

            return $this->buildDimension('Event Volume Health', 0.10, $score, $details);
        } catch (\Throwable) {
            return $this->buildDimension('Event Volume Health', 0.10, 50.0, 'Volume health unavailable');
        }
    }

    /**
     * Evaluate consent compliance dimension.
     *
     * Checks GDPR consent configuration and consent log health.
     *
     * @return DimensionScore
     */
    private function evaluateConsentCompliance(): array
    {
        try {
            $consentConfig = $this->config->get('zeroboiler.analytics.consent', []);
            /** @var array{default?: string, log_enabled?: bool, purposes?: array<string, array{required: bool}>} $consentConfig */

            $score = 100.0;
            $issues = [];

            // Check if consent purposes are defined
            $purposes = $consentConfig['purposes'] ?? [];
            if (empty($purposes)) {
                $score -= 30.0;
                $issues[] = 'No consent purposes defined';
            }

            // Check if consent logging is enabled
            $logEnabled = $consentConfig['log_enabled'] ?? false;
            if (! $logEnabled) {
                $score -= 20.0;
                $issues[] = 'Consent logging is disabled';
            }

            // Check if default is GDPR-safe (denied for EU)
            $defaultConsent = $consentConfig['default'] ?? 'granted';
            if ($defaultConsent === 'granted') {
                $score -= 15.0;
                $issues[] = 'Default consent is "granted" (GDPR risk for EU users)';
            }

            // Check if necessary purpose is required
            $hasNecessary = false;
            foreach ($purposes as $key => $purpose) {
                if (($purpose['required'] ?? false) === true) {
                    $hasNecessary = true;
                    break;
                }
            }
            if (! $hasNecessary && ! empty($purposes)) {
                $score -= 10.0;
                $issues[] = 'No required consent purpose defined';
            }

            $score = max(0.0, round($score, 2));
            $details = empty($issues)
                ? 'Consent configuration is GDPR-ready'
                : implode('; ', $issues);

            return $this->buildDimension('Consent Compliance', 0.15, $score, $details);
        } catch (\Throwable) {
            return $this->buildDimension('Consent Compliance', 0.15, 50.0, 'Consent evaluation unavailable');
        }
    }

    /**
     * Build a dimension score array.
     *
     * @return DimensionScore
     */
    private function buildDimension(string $name, float $weight, float $score, string $details): array
    {
        return [
            'name' => $name,
            'weight' => $weight,
            'score' => max(0.0, min(100.0, $score)),
            'grade' => $this->scoreToGrade($score),
            'status' => $score >= 80.0 ? 'healthy' : ($score >= 55.0 ? 'warning' : 'critical'),
            'details' => $details,
        ];
    }

    /**
     * Generate actionable recommendations based on dimension scores.
     *
     * @param  array<string, DimensionScore>  $dimensions
     * @return list<string>
     */
    private function generateRecommendations(array $dimensions, float $score, string $grade): array
    {
        $recommendations = [];

        // Provider coverage
        $pc = $dimensions['provider_coverage'] ?? null;
        if ($pc !== null && $pc['score'] < 80.0) {
            $recommendations[] = '[Provider Coverage] Check provider API keys and connectivity — some enabled providers are failing.';
        }

        // Catalog completeness
        $cc = $dimensions['catalog_completeness'] ?? null;
        if ($cc !== null && $cc['score'] < 80.0) {
            $recommendations[] = '[Catalog] Track more recommended events to improve analytics coverage. Run `php artisan analytics:overview` for gaps.';
        }

        // Data quality
        $dq = $dimensions['data_quality'] ?? null;
        if ($dq !== null && $dq['score'] < 80.0) {
            $recommendations[] = '[Data Quality] Enrich events with required parameters (transaction_id, value, currency) to improve quality scores.';
        }

        // Dispatch reliability
        $dr = $dimensions['dispatch_reliability'] ?? null;
        if ($dr !== null && $dr['score'] < 90.0) {
            $recommendations[] = '[Reliability] Some events are failing to dispatch. Check provider rate limits and API quotas.';
        }

        // Consent compliance
        $csc = $dimensions['consent_compliance'] ?? null;
        if ($csc !== null && $csc['score'] < 80.0) {
            $recommendations[] = '[GDPR] Review consent configuration — enable consent logging and set default to "denied" for GDPR compliance.';
        }

        // Overall grade
        if ($grade === 'F' || $grade === 'D') {
            $recommendations[] = sprintf('[Critical] Overall health score is %s (%.1f/100). Immediate action required.', $grade, $score);
        }

        if ($score >= 90.0) {
            $recommendations[] = '[Excellent] Analytics pipeline health is in top tier. Consider enabling advanced features like predictive scoring.';
        }

        return $recommendations;
    }

    /**
     * Generate a disabled report when the index is turned off.
     *
     * @return HealthReport
     */
    private function disabledReport(): array
    {
        return [
            'score' => 0.0,
            'grade' => 'N/A',
            'dimensions' => [],
            'recommendations' => ['Composite Health Index is disabled. Enable via config or set ANALYTICS_COMPOSITE_HEALTH_ENABLED=true.'],
            'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'cache_key' => self::CACHE_PREFIX . 'disabled',
        ];
    }
}
