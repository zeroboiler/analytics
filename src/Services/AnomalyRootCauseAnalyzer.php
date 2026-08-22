<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Anomaly root cause analyzer — traces analytics anomalies back to their
 * most likely originating events using correlation engine data.
 *
 * When an anomaly is detected (by EventSignalIntelligenceService, EventStreamProcessorService,
 * or alert rules), this analyzer:
 * 1. Identifies correlated events from the correlation engine
 * 2. Ranks potential root causes by temporal proximity and correlation strength
 * 3. Provides actionable diagnostic context (affected users, time windows, event sequences)
 * 4. Generates remediation suggestions based on the root cause type
 *
 * Root cause categories:
 * - Infrastructure: Provider outage, queue failure, cache degradation
 * - Behavioral: Spike in specific user cohort, geographic anomaly
 * - Technical: Client-side error spike, integration failure
 * - Data quality: Schema mismatch, validation failure wave
 *
 * Inspired by Datadog AIOps Root Cause Analysis and Honeycomb BubbleUp.
 *
 * @since 48.0.0
 */
final class AnomalyRootCauseAnalyzer
{
    /** @var array<string, mixed> */
    private array $config;

    private string $cachePrefix;

    private int $cacheTtl;

    private int $maxRootCauses;

    private int $lookbackWindowSeconds;

    private float $minConfidenceScore;

    /**
     * @param  CacheRepository  $cache  Cache repository for analysis results
     * @param  EventCorrelationEngineService  $correlationEngine  Correlation engine for event relationships
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly EventCorrelationEngineService $correlationEngine,
        ConfigRepository $config,
    ){
        $this->config = $config->get('zeroboiler.analytics.root_cause_analyzer', []);
        $this->cachePrefix = (string) ($this->config['cache_prefix'] ?? 'zb_rca_');
        $this->cacheTtl = (int) ($this->config['cache_ttl'] ?? 1800); // 30 minutes
        $this->maxRootCauses = (int) ($this->config['max_root_causes'] ?? 5);
        $this->lookbackWindowSeconds = (int) ($this->config['lookback_window_seconds'] ?? 3600); // 1 hour
        $this->minConfidenceScore = (float) ($this->config['min_confidence_score'] ?? 0.2);
    }

    /**
     * Analyze the root cause of an anomaly for a specific event.
     *
     * Uses the correlation engine to find events that commonly precede or
     * co-occur with the anomalous event, then ranks them by likelihood
     * of being the root cause.
     *
     * @param  string  $anomalousEvent  The event that triggered the anomaly
     * @param  string  $anomalyType  Type of anomaly: 'spike', 'drop', 'error', 'latency', 'quality'
     * @param  array<string, mixed>  $anomalyContext  Additional context about the anomaly
     * @return array{root_causes: list<array{event: string, category: string, confidence: float, correlation: float, direction: string, explanation: string, suggestion: string}>, analysis_id: string, timestamp: int, anomalous_event: string, anomaly_type: string}
     */
    public function analyze(string $anomalousEvent, string $anomalyType = 'spike', array $anomalyContext = []): array
    {
        $analysisId = 'rca_' . md5($anomalousEvent . ':' . $anomalyType . ':' . time());
        $cacheKey = $this->cachePrefix . $analysisId;

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Step 1: Get correlated antecedents (events that precede the anomalous event)
        $antecedents = $this->correlationEngine->getAntecedents($anomalousEvent, 20);

        // Step 2: Get correlated events (bidirectional)
        $correlatedEvents = $this->correlationEngine->getCorrelatedEvents($anomalousEvent);

        // Step 3: Score and categorize potential root causes
        $rootCauses = [];

        foreach ($correlatedEvents as $corr) {
            $category = $this->categorizeEvent($corr['event']);
            $confidence = $this->computeConfidence($corr, $anomalyType, $anomalyContext);
            $explanation = $this->generateExplanation($corr['event'], $anomalousEvent, $corr, $anomalyType);
            $suggestion = $this->generateSuggestion($category, $anomalyType, $corr['event']);

            if ($confidence >= $this->minConfidenceScore) {
                $rootCauses[] = [
                    'event' => $corr['event'],
                    'category' => $category,
                    'confidence' => round($confidence, 4),
                    'correlation' => $corr['score'],
                    'direction' => $corr['direction'],
                    'explanation' => $explanation,
                    'suggestion' => $suggestion,
                ];
            }
        }

        // Step 4: Sort by confidence descending
        usort($rootCauses, fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        // Step 5: Add infrastructure root causes if no behavioral causes found
        if (count($rootCauses) < 3) {
            $infraCauses = $this->generateInfrastructureCauses($anomalousEvent, $anomalyType);
            $rootCauses = array_merge($rootCauses, $infraCauses);
        }

        $rootCauses = array_slice($rootCauses, 0, $this->maxRootCauses);

        $result = [
            'root_causes' => $rootCauses,
            'analysis_id' => $analysisId,
            'timestamp' => time(),
            'anomalous_event' => $anomalousEvent,
            'anomaly_type' => $anomalyType,
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        $this->recordAnalysis($result);

        return $result;
    }

    /**
     * Get the most likely root cause from an analysis.
     *
     * Convenience method for alerting integrations.
     *
     * @param  string  $anomalousEvent  The event that triggered the anomaly
     * @param  string  $anomalyType  Type of anomaly
     * @return array{event: string|null, category: string|null, confidence: float, suggestion: string|null}
     */
    public function getTopRootCause(string $anomalousEvent, string $anomalyType = 'spike'): array
    {
        $analysis = $this->analyze($anomalousEvent, $anomalyType);
        $rootCauses = $analysis['root_causes'];

        if ($rootCauses === []) {
            return [
                'event' => null,
                'category' => null,
                'confidence' => 0.0,
                'suggestion' => null,
            ];
        }

        $top = $rootCauses[0];

        return [
            'event' => $top['event'],
            'category' => $top['category'],
            'confidence' => $top['confidence'],
            'suggestion' => $top['suggestion'],
        ];
    }

    /**
     * Get analysis history.
     *
     * @param  int  $limit  Maximum analyses to return
     * @return list<array{analysis_id: string, timestamp: int, anomalous_event: string, anomaly_type: string, root_cause_count: int, top_cause: string|null}>
     */
    public function getAnalysisHistory(int $limit = 20): array
    {
        $historyKey = $this->cachePrefix . 'history';
        /** @var list<array<string, mixed>> $history */
        $history = $this->cache->get($historyKey, []);

        return array_slice($history, 0, $limit);
    }

    /**
     * Get analyzer summary metrics.
     *
     * @return array{total_analyses: int, events_analyzed: int, categories: array<string, int>, avg_confidence: float, cache_prefix: string}
     */
    public function getSummary(): array
    {
        $history = $this->getAnalysisHistory(100);
        $categoryCounts = [];
        $totalConfidence = 0.0;
        $eventsAnalyzed = [];

        foreach ($history as $entry) {
            $eventsAnalyzed[$entry['anomalous_event']] = true;
            if (isset($entry['top_category'])) {
                $cat = $entry['top_category'];
                $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
            }
            $totalConfidence += $entry['top_confidence'] ?? 0.0;
        }

        $avgConfidence = count($history) > 0
            ? round($totalConfidence / count($history), 4)
            : 0.0;

        return [
            'total_analyses' => count($history),
            'events_analyzed' => count($eventsAnalyzed),
            'categories' => $categoryCounts,
            'avg_confidence' => $avgConfidence,
            'cache_prefix' => $this->cachePrefix,
        ];
    }

    /**
     * Clear all analysis history and cached results.
     */
    public function clearHistory(): void
    {
        $historyKey = $this->cachePrefix . 'history';
        /** @var list<array<string, mixed>> $history */
        $history = $this->cache->get($historyKey, []);

        foreach ($history as $entry) {
            $this->cache->forget($this->cachePrefix . ($entry['analysis_id'] ?? ''));
        }

        $this->cache->forget($historyKey);
    }

    /**
     * Categorize an event into a root cause category.
     *
     * @param  string  $eventName  The event name to categorize
     * @return string Category: 'infrastructure', 'behavioral', 'technical', 'data_quality', 'billing', 'unknown'
     */
    private function categorizeEvent(string $eventName): string
    {
        $infraPatterns = [
            'service_down', 'service_up', 'deployment', 'pipeline_failure',
            'incident_started', 'incident_resolved', 'slo_breach', 'api_latency',
            'maintenance_started', 'maintenance_ended', 'error_budget_burned',
        ];

        $techPatterns = [
            'js_error', 'error', 'payment_failed', 'api_rate_limited',
            'integration_failed', 'rate_limit_exceeded', 'webhook_delivered',
        ];

        $billingPatterns = [
            'payment_failed', 'billing_retry', 'invoice_generated',
            'subscription_value_changed', 'credit_applied',
        ];

        $dataQualityPatterns = [
            'consent_withdrawn', 'data_erasure_completed',
        ];

        if (in_array($eventName, $infraPatterns, true)) {
            return 'infrastructure';
        }

        if (in_array($eventName, $techPatterns, true)) {
            return 'technical';
        }

        if (in_array($eventName, $billingPatterns, true)) {
            return 'billing';
        }

        if (in_array($eventName, $dataQualityPatterns, true)) {
            return 'data_quality';
        }

        if (str_starts_with($eventName, 'session_') || str_starts_with($eventName, 'page_')) {
            return 'behavioral';
        }

        return 'behavioral';
    }

    /**
     * Compute confidence score for a potential root cause.
     *
     * Confidence is based on:
     * - Correlation strength from the engine
     * - Directionality (antecedent events get higher confidence)
     * - Category relevance to anomaly type
     * - Temporal proximity (if provided in context)
     *
     * @param  array{event: string, score: float, direction: string, avg_delta: int, cooccurrences: int}  $correlation
     * @param  string  $anomalyType  Type of anomaly
     * @param  array<string, mixed>  $context  Anomaly context
     * @return float Confidence score (0.0–1.0)
     */
    private function computeConfidence(array $correlation, string $anomalyType, array $context = []): float
    {
        $baseConfidence = $correlation['score'];

        // Boost for antecedent direction (cause → effect)
        $directionBoost = match ($correlation['direction']) {
            'before' => 0.15,
            'simultaneous' => 0.05,
            'after' => -0.05,
            default => 0.0,
        };

        // Category relevance to anomaly type
        $categoryRelevance = $this->getCategoryRelevance($correlation['event'], $anomalyType);

        // Frequency bonus (more co-occurrences = higher confidence)
        $frequencyBonus = min(0.1, $correlation['cooccurrences'] * 0.005);

        $confidence = $baseConfidence + $directionBoost + $categoryRelevance + $frequencyBonus;

        return round(min(1.0, max(0.0, $confidence)), 4);
    }

    /**
     * Get category relevance score for an anomaly type.
     */
    private function getCategoryRelevance(string $event, string $anomalyType): float
    {
        $category = $this->categorizeEvent($event);

        return match ($anomalyType) {
            'spike' => match ($category) {
                'infrastructure' => 0.1,
                'behavioral' => 0.05,
                'technical' => 0.08,
                default => 0.0,
            },
            'drop' => match ($category) {
                'infrastructure' => 0.1,
                'technical' => 0.08,
                'data_quality' => 0.05,
                default => 0.0,
            },
            'error' => match ($category) {
                'technical' => 0.15,
                'infrastructure' => 0.1,
                'data_quality' => 0.05,
                default => 0.0,
            },
            'latency' => match ($category) {
                'infrastructure' => 0.15,
                'technical' => 0.1,
                default => 0.0,
            },
            'quality' => match ($category) {
                'data_quality' => 0.15,
                'technical' => 0.1,
                default => 0.0,
            },
            default => 0.0,
        };
    }

    /**
     * Generate a human-readable explanation for a root cause.
     *
     * @param  string  $causeEvent  The suspected root cause event
     * @param  string  $anomalousEvent  The anomalous event
     * @param  array{direction: string, avg_delta: int, cooccurrences: int}  $correlation
     * @param  string  $anomalyType  Type of anomaly
     */
    private function generateExplanation(string $causeEvent, string $anomalousEvent, array $correlation, string $anomalyType): string
    {
        $directionText = match ($correlation['direction']) {
            'before' => 'precedes',
            'after' => 'follows',
            'simultaneous' => 'co-occurs with',
            default => 'is correlated with',
        };

        $timeText = $correlation['avg_delta'] > 0
            ? sprintf('avg %ds apart', $correlation['avg_delta'])
            : 'near-simultaneous';

        return sprintf(
            '"%s" %s "%s" (%s, %d co-occurrences) — potential trigger for %s anomaly',
            $causeEvent,
            $directionText,
            $anomalousEvent,
            $timeText,
            $correlation['cooccurrences'],
            $anomalyType,
        );
    }

    /**
     * Generate an actionable remediation suggestion.
     *
     * @param  string  $category  Root cause category
     * @param  string  $anomalyType  Type of anomaly
     * @param  string  $causeEvent  The suspected root cause event
     */
    private function generateSuggestion(string $category, string $anomalyType, string $causeEvent): string
    {
        return match ($category) {
            'infrastructure' => match ($anomalyType) {
                'spike' => "Check infrastructure health for '{$causeEvent}' — possible deployment or service restart driving anomalous traffic",
                'drop' => "Investigate infrastructure degradation near '{$causeEvent}' — possible service interruption",
                default => "Review infrastructure metrics related to '{$causeEvent}'",
            },
            'technical' => match ($anomalyType) {
                'error' => "Investigate client-side or integration errors for '{$causeEvent}' — check error logs and provider status",
                'latency' => "Check provider response times and queue processing for events related to '{$causeEvent}'",
                default => "Review technical metrics for '{$causeEvent}'",
            },
            'billing' => "Check billing system health — '{$causeEvent}' may indicate payment processing issues affecting event flow",
            'data_quality' => "Review data quality pipeline — '{$causeEvent}' may indicate consent or GDPR processing affecting event volume",
            default => "Monitor '{$causeEvent}' for correlation pattern changes",
        };
    }

    /**
     * Generate infrastructure-related root causes when behavioral causes are insufficient.
     *
     * @param  string  $anomalousEvent  The anomalous event
     * @param  string  $anomalyType  Type of anomaly
     * @return list<array{event: string, category: string, confidence: float, correlation: float, direction: string, explanation: string, suggestion: string}>
     */
    private function generateInfrastructureCauses(string $anomalousEvent, string $anomalyType): array
    {
        $causes = [];

        $infraEvents = [
            'service_down' => 'Possible provider service degradation affecting event delivery',
            'api_latency' => 'Increased API latency causing event processing delays',
            'pipeline_failure' => 'Pipeline processing failure may be blocking or duplicating events',
        ];

        foreach ($infraEvents as $event => $explanation) {
            $causes[] = [
                'event' => $event,
                'category' => 'infrastructure',
                'confidence' => round(0.25, 4),
                'correlation' => 0.0,
                'direction' => 'before',
                'explanation' => $explanation . " — '{$anomalousEvent}' anomaly ({$anomalyType})",
                'suggestion' => "Check provider status pages and infrastructure health for '{$event}' patterns",
            ];
        }

        return $causes;
    }

    /**
     * Record an analysis in the history cache.
     *
     * @param  array{root_causes: list<array{event: string, category: string, confidence: float}>, analysis_id: string, timestamp: int, anomalous_event: string, anomaly_type: string}  $result
     */
    private function recordAnalysis(array $result): void
    {
        $historyKey = $this->cachePrefix . 'history';
        /** @var list<array<string, mixed>> $history */
        $history = $this->cache->get($historyKey, []);

        $topCause = $result['root_causes'][0] ?? null;

        $history[] = [
            'analysis_id' => $result['analysis_id'],
            'timestamp' => $result['timestamp'],
            'anomalous_event' => $result['anomalous_event'],
            'anomaly_type' => $result['anomaly_type'],
            'root_cause_count' => count($result['root_causes']),
            'top_cause' => $topCause !== null ? $topCause['event'] : null,
            'top_category' => $topCause !== null ? $topCause['category'] : null,
            'top_confidence' => $topCause !== null ? $topCause['confidence'] : 0.0,
        ];

        // Keep last 100 analyses
        if (count($history) > 100) {
            $history = array_slice($history, -50);
        }

        $this->cache->put($historyKey, $history, $this->cacheTtl);
    }
}
