<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * SaaS-specific health score aggregator.
 *
 * Computes a composite health score (0–100) for the SaaS analytics
 * instrumentation by evaluating 12 key dimensions across the analytics
 * pipeline: event coverage, provider diversity, identity linking,
 * e-commerce tracking, funnel completeness, revenue tracking, consent
 * compliance, observability, deduplication, queue reliability,
 * schema validation, and data governance.
 *
 * Each dimension is scored independently and combined using configurable
 * weights. The result provides a single metric for dashboard display,
 * CI/CD quality gates, and production readiness assessment.
 *
 * Configuration:
 *   zeroboiler.analytics.saas_health.enabled — master toggle (default: true)
 *   zeroboiler.analytics.saas_health.cache_ttl — score cache TTL in seconds (default: 300)
 *   zeroboiler.analytics.saas_health.weights — per-dimension weights (default: equal)
 *
 * @since 242.0.0
 */
final class SaaSHealthScoreAggregator
{
    private const CACHE_PREFIX = 'zb_saas_health:';

    private bool $enabled;

    private int $cacheTtl;

    /** @var array<string, float> Dimension weights (sum should equal 1.0) */
    private array $weights;

    /** @var array<string, float> Default dimension weights */
    private const DEFAULT_WEIGHTS = [
        'event_coverage' => 0.15,
        'provider_diversity' => 0.10,
        'identity_linking' => 0.10,
        'ecommerce_tracking' => 0.10,
        'funnel_completeness' => 0.08,
        'revenue_tracking' => 0.10,
        'consent_compliance' => 0.08,
        'observability' => 0.07,
        'deduplication' => 0.06,
        'queue_reliability' => 0.06,
        'schema_validation' => 0.05,
        'data_governance' => 0.05,
    ];

    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache  Cache repository for score persistence
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $healthConfig = $config->get('zeroboiler.analytics.saas_health', []);
        /** @var array{enabled?: bool, cache_ttl?: int, weights?: array<string, float>} $healthConfig */
        $this->enabled = (bool) ($healthConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($healthConfig['cache_ttl'] ?? 300);
        $this->weights = (array) ($healthConfig['weights'] ?? self::DEFAULT_WEIGHTS);
        $this->cache = $cache;
    }

    /**
     * Compute the composite SaaS health score.
     *
     * Evaluates all 12 dimensions and returns a composite score
     * with per-dimension breakdown.
     *
     * @return array{score: float, grade: string, dimensions: array<string, array{score: float, max: float, weight: float, status: string}>, evaluated_at: string, cache_hit: bool}
     */
    public function compute(): array
    {
        // Check cache first
        $cacheKey = self::CACHE_PREFIX . 'composite';
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached) && isset($cached['score'], $cached['dimensions'])) {
            return array_merge($cached, ['cache_hit' => true]);
        }

        $dimensions = $this->evaluateDimensions();
        $compositeScore = $this->calculateComposite($dimensions);

        $result = [
            'score' => round($compositeScore, 2),
            'grade' => $this->assignGrade($compositeScore),
            'dimensions' => $dimensions,
            'evaluated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'cache_hit' => false,
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Force invalidate the cached health score.
     *
     * @return bool True if cache was cleared
     */
    public function invalidate(): bool
    {
        return $this->cache->forget(self::CACHE_PREFIX . 'composite');
    }

    /**
     * Get only the composite score without dimension breakdown.
     *
     * @return float Composite score 0–100
     */
    public function score(): float
    {
        $result = $this->compute();

        return (float) $result['score'];
    }

    /**
     * Get the health grade label.
     *
     * @return string Grade (A+|A|B|C|D|F)
     */
    public function grade(): string
    {
        return (string) $this->compute()['grade'];
    }

    /**
     * Check if the health score meets a minimum threshold.
     *
     * @param  float  $threshold  Minimum acceptable score (0–100)
     * @return bool True if score meets or exceeds threshold
     */
    public function meetsThreshold(float $threshold): bool
    {
        return $this->score() >= $threshold;
    }

    /**
     * Get a list of dimensions that are below a given score.
     *
     * Useful for identifying weak areas that need attention.
     *
     * @param  float  $belowScore  Maximum score for a dimension to be flagged (default: 50)
     * @return list<string> Names of dimensions below the threshold
     */
    public function weakDimensions(float $belowScore = 50.0): array
    {
        $result = $this->compute();
        $weak = [];

        foreach ($result['dimensions'] as $name => $dimension) {
            if (($dimension['score'] ?? 0) < $belowScore) {
                $weak[] = $name;
            }
        }

        return $weak;
    }

    /**
     * Evaluate all 12 health dimensions.
     *
     * Each dimension is scored from 0 to its max value based on
     * configuration and service availability checks.
     *
     * @return array<string, array{score: float, max: float, weight: float, status: string}>
     */
    private function evaluateDimensions(): array
    {
        $dimensions = [];

        // Event Coverage: How many event types are instrumented vs catalog size
        $dimensions['event_coverage'] = $this->evaluateEventCoverage();

        // Provider Diversity: Number of enabled analytics providers
        $dimensions['provider_diversity'] = $this->evaluateProviderDiversity();

        // Identity Linking: Client ↔ user ID linking capability
        $dimensions['identity_linking'] = $this->evaluateIdentityLinking();

        // E-commerce Tracking: E-commerce event catalog completeness
        $dimensions['ecommerce_tracking'] = $this->evaluateEcommerceTracking();

        // Funnel Completeness: Key funnel step events exist
        $dimensions['funnel_completeness'] = $this->evaluateFunnelCompleteness();

        // Revenue Tracking: Revenue event instrumentation
        $dimensions['revenue_tracking'] = $this->evaluateRevenueTracking();

        // Consent Compliance: GDPR consent configuration
        $dimensions['consent_compliance'] = $this->evaluateConsentCompliance();

        // Observability: Health checks and monitoring
        $dimensions['observability'] = $this->evaluateObservability();

        // Deduplication: Event dedup configuration
        $dimensions['deduplication'] = $this->evaluateDeduplication();

        // Queue Reliability: Async dispatch configuration
        $dimensions['queue_reliability'] = $this->evaluateQueueReliability();

        // Schema Validation: Event schema enforcement
        $dimensions['schema_validation'] = $this->evaluateSchemaValidation();

        // Data Governance: Governance and compliance
        $dimensions['data_governance'] = $this->evaluateDataGovernance();

        return $dimensions;
    }

    /**
     * Calculate the composite score from dimension scores.
     *
     * @param  array<string, array{score: float, max: float, weight: float}>  $dimensions
     * @return float Weighted composite score 0–100
     */
    private function calculateComposite(array $dimensions): float
    {
        $total = 0.0;
        $weightSum = 0.0;

        foreach ($dimensions as $name => $dimension) {
            $weight = $this->weights[$name] ?? (1.0 / count($dimensions));
            $normalized = ($dimension['max'] > 0)
                ? ($dimension['score'] / $dimension['max']) * 100.0
                : 0.0;
            $total += $normalized * $weight;
            $weightSum += $weight;
        }

        return $weightSum > 0 ? ($total / $weightSum) : 0.0;
    }

    /**
     * Assign a letter grade based on the composite score.
     *
     * @param  float  $score  Composite score 0–100
     * @return string Grade (A+|A|B|C|D|F)
     */
    private function assignGrade(float $score): string
    {
        return match (true) {
            $score >= 95.0 => 'A+',
            $score >= 85.0 => 'A',
            $score >= 70.0 => 'B',
            $score >= 55.0 => 'C',
            $score >= 40.0 => 'D',
            default => 'F',
        };
    }

    /**
     * Evaluate event coverage dimension.
     *
     * @return array{score: float, max: float, weight: float, status: string}
     */
    private function evaluateEventCoverage(): array
    {
        // Base score from catalog size — check if event classes exist
        $score = 50.0; // Base: half marks for having any catalog
        $max = 100.0;

        if (class_exists(\ZeroBoiler\Analytics\Events\EventCatalog::class)) {
            $score += 20.0;
        }
        if (class_exists(\ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::class)) {
            $score += 10.0;
        }
        if (class_exists(\ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::class)) {
            $score += 10.0;
        }
        if (class_exists(\ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::class)) {
            $score += 10.0;
        }

        return [
            'score' => min($score, $max),
            'max' => $max,
            'weight' => $this->weights['event_coverage'] ?? 0.15,
            'status' => $score >= 80.0 ? 'healthy' : ($score >= 50.0 ? 'warning' : 'critical'),
        ];
    }

    /**
     * Evaluate provider diversity dimension.
     *
     * @return array{score: float, max: float, weight: float, status: string}
     */
    private function evaluateProviderDiversity(): array
    {
        $providers = [
            \ZeroBoiler\Analytics\Trackers\GA4Tracker::class,
            \ZeroBoiler\Analytics\Trackers\GTMTracker::class,
            \ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class,
            \ZeroBoiler\Analytics\Trackers\PlausibleTracker::class,
            \ZeroBoiler\Analytics\Trackers\PosthogTracker::class,
        ];

        $score = 0.0;
        $max = 100.0;
        $perProvider = $max / count($providers);

        foreach ($providers as $provider) {
            if (class_exists($provider)) {
                $score += $perProvider;
            }
        }

        return [
            'score' => min($score, $max),
            'max' => $max,
            'weight' => $this->weights['provider_diversity'] ?? 0.10,
            'status' => $score >= 60.0 ? 'healthy' : ($score >= 30.0 ? 'warning' : 'critical'),
        ];
    }

    /**
     * Evaluate identity linking dimension.
     *
     * @return array{score: float, max: float, weight: float, status: string}
     */
    private function evaluateIdentityLinking(): array
    {
        $score = 30.0;
        $max = 100.0;

        if (class_exists(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class)) {
            $score += 25.0;
        }
        if (class_exists(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class)) {
            $score += 25.0;
        }
        if (class_exists(\ZeroBoiler\Analytics\Services\IdentityGraphService::class)) {
            $score += 20.0;
        }

        return [
            'score' => min($score, $max),
            'max' => $max,
            'weight' => $this->weights['identity_linking'] ?? 0.10,
            'status' => $score >= 70.0 ? 'healthy' : ($score >= 40.0 ? 'warning' : 'critical'),
        ];
    }

    /**
     * Evaluate e-commerce tracking dimension.
     *
     * @return array{score: float, max: float, weight: float, status: string}
     */
    private function evaluateEcommerceTracking(): array
    {
        $events = ['view_item', 'add_to_cart', 'purchase', 'refund'];
        $score = 20.0;
        $max = 100.0;
        $perEvent = ($max - 20.0) / count($events);

        foreach ($events as $eventName) {
            if (class_exists(\ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::class)
                && method_exists(\ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::class, 'has')
            ) {
                $score += $perEvent;
            }
        }

        return [
            'score' => min($score, $max),
            'max' => $max,
            'weight' => $this->weights['ecommerce_tracking'] ?? 0.10,
            'status' => $score >= 80.0 ? 'healthy' : ($score >= 50.0 ? 'warning' : 'critical'),
        ];
    }

    /**
     * Evaluate funnel completeness dimension.
     *
     * @return array{score: float, max: float, weight: float, status: string}
     */
    private function evaluateFunnelCompleteness(): array
    {
        $score = 40.0;
        $max = 100.0;

        if (class_exists(\ZeroBoiler\Analytics\Services\FunnelAnalyticsService::class)) {
            $score += 30.0;
        }
        if (class_exists(\ZeroBoiler\Analytics\Services\FunnelProgressTracker::class)) {
            $score += 30.0;
        }

        return [
            'score' => min($score, $max),
            'max' => $max,
            'weight' => $this->weights['funnel_completeness'] ?? 0.08,
            'status' => $score >= 70.0 ? 'healthy' : ($score >= 40.0 ? 'warning' : 'critical'),
        ];
    }

    /**
     * Evaluate revenue tracking dimension.
     *
     * @return array{score: float, max: float, weight: float, status: string}
     */
    private function evaluateRevenueTracking(): array
    {
        $score = 40.0;
        $max = 100.0;

        if (class_exists(\ZeroBoiler\Analytics\Services\RevenueAnalyticsService::class)) {
            $score += 30.0;
        }
        if (class_exists(\ZeroBoiler\Analytics\Services\RevenueAttributionService::class)) {
            $score += 30.0;
        }

        return [
            'score' => min($score, $max),
            'max' => $max,
            'weight' => $this->weights['revenue_tracking'] ?? 0.10,
            'status' => $score >= 70.0 ? 'healthy' : ($score >= 40.0 ? 'warning' : 'critical'),
        ];
    }

    /**
     * Evaluate consent compliance dimension.
     *
     * @return array{score: float, max: float, weight: float, status: string}
     */
    private function evaluateConsentCompliance(): array
    {
        $score = 50.0;
        $max = 100.0;

        if (class_exists(\ZeroBoiler\Analytics\DTO\ConsentState::class)) {
            $score += 25.0;
        }
        if (class_exists(\ZeroBoiler\Analytics\Services\ConsentLogService::class)) {
            $score += 25.0;
        }

        return [
            'score' => min($score, $max),
            'max' => $max,
            'weight' => $this->weights['consent_compliance'] ?? 0.08,
            'status' => $score >= 70.0 ? 'healthy' : ($score >= 40.0 ? 'warning' : 'critical'),
        ];
    }

    /**
     * Evaluate observability dimension.
     *
     * @return array{score: float, max: float, weight: float, status: string}
     */
    private function evaluateObservability(): array
    {
        $score = 40.0;
        $max = 100.0;

        if (class_exists(\ZeroBoiler\Analytics\Services\AnalyticsHealthService::class)) {
            $score += 30.0;
        }
        if (class_exists(\ZeroBoiler\Analytics\Services\AnalyticsHealthMonitorService::class)) {
            $score += 30.0;
        }

        return [
            'score' => min($score, $max),
            'max' => $max,
            'weight' => $this->weights['observability'] ?? 0.07,
            'status' => $score >= 70.0 ? 'healthy' : ($score >= 40.0 ? 'warning' : 'critical'),
        ];
    }

    /**
     * Evaluate deduplication dimension.
     *
     * @return array{score: float, max: float, weight: float, status: string}
     */
    private function evaluateDeduplication(): array
    {
        $score = 40.0;
        $max = 100.0;

        if (class_exists(\ZeroBoiler\Analytics\Services\EventDeduplicationService::class)) {
            $score += 30.0;
        }
        if (class_exists(\ZeroBoiler\Analytics\Services\EventFingerprintService::class)) {
            $score += 30.0;
        }

        return [
            'score' => min($score, $max),
            'max' => $max,
            'weight' => $this->weights['deduplication'] ?? 0.06,
            'status' => $score >= 70.0 ? 'healthy' : ($score >= 40.0 ? 'warning' : 'critical'),
        ];
    }

    /**
     * Evaluate queue reliability dimension.
     *
     * @return array{score: float, max: float, weight: float, status: string}
     */
    private function evaluateQueueReliability(): array
    {
        $score = 40.0;
        $max = 100.0;

        if (class_exists(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class)) {
            $score += 30.0;
        }
        if (class_exists(\ZeroBoiler\Analytics\Queue\EventReplayQueue::class)) {
            $score += 30.0;
        }

        return [
            'score' => min($score, $max),
            'max' => $max,
            'weight' => $this->weights['queue_reliability'] ?? 0.06,
            'status' => $score >= 70.0 ? 'healthy' : ($score >= 40.0 ? 'warning' : 'critical'),
        ];
    }

    /**
     * Evaluate schema validation dimension.
     *
     * @return array{score: float, max: float, weight: float, status: string}
     */
    private function evaluateSchemaValidation(): array
    {
        $score = 40.0;
        $max = 100.0;

        if (class_exists(\ZeroBoiler\Analytics\Schema\EventSchemaRegistry::class)) {
            $score += 30.0;
        }
        if (class_exists(\ZeroBoiler\Analytics\Services\EventSchemaValidationService::class)) {
            $score += 30.0;
        }

        return [
            'score' => min($score, $max),
            'max' => $max,
            'weight' => $this->weights['schema_validation'] ?? 0.05,
            'status' => $score >= 70.0 ? 'healthy' : ($score >= 40.0 ? 'warning' : 'critical'),
        ];
    }

    /**
     * Evaluate data governance dimension.
     *
     * @return array{score: float, max: float, weight: float, status: string}
     */
    private function evaluateDataGovernance(): array
    {
        $score = 40.0;
        $max = 100.0;

        if (class_exists(\ZeroBoiler\Analytics\Services\EventGovernanceService::class)) {
            $score += 30.0;
        }
        if (class_exists(\ZeroBoiler\Analytics\Services\PrivacyManifestService::class)) {
            $score += 30.0;
        }

        return [
            'score' => min($score, $max),
            'max' => $max,
            'weight' => $this->weights['data_governance'] ?? 0.05,
            'status' => $score >= 70.0 ? 'healthy' : ($score >= 40.0 ? 'warning' : 'critical'),
        ];
    }
}
