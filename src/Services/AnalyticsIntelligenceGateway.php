<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;

/**
 * Analytics Intelligence Gateway — unified SaaS health monitoring API.
 *
 * Aggregates signals from multiple analytics subsystems into a single
 * coherent health dashboard payload. Designed for real-time SaaS ops
 * dashboards, alerting systems, and executive reporting.
 *
 * Subsystems aggregated:
 *   - Provider health (enabled/disabled, configuration status)
 *   - Catalog coverage (event instrumentation completeness)
 *   - Anomaly detection (recent event anomalies from AnalyticsAnomalyDetectionService)
 *   - Funnel health (signup → trial → subscribe conversion rates)
 *   - Churn prediction (retention signal events)
 *   - Revenue health (billing event pipeline status)
 *   - Pipeline health (enrichment, validation, queue status)
 *   - Data quality (deduplication, sampling, PII sanitization)
 *   - Provider fallback status (circuit breaker states)
 *   - Event budget utilization
 *   - Privacy compliance status (consent, GDPR, DPIA)
 *   - Transformation engine status
 *
 * Each subsystem is optional — the gateway gracefully degrades when
 * a service is not available or not configured.
 *
 * @since 71.0.0
 */
final class AnalyticsIntelligenceGateway
{
    private AnalyticsConfig $config;

    private AnalyticsManager $manager;

    private ?AnalyticsAnomalyDetectionService $anomalyService;

    private ?SaaSConversionService $conversionService;

    private ?ChurnPredictionService $churnService;

    private ?EventHealthMonitorService $healthMonitor;

    private ?ProviderFallbackService $fallbackService;

    private ?EventBudgetService $budgetService;

    private ?PrivacyImpactAssessmentService $privacyService;

    private ?EventTransformationEngine $transformationEngine;

    private ?AnalyticsConsentComplianceService $consentService;

    private ?EventPredictiveScoringService $predictiveService;

    private ?RealTimeAggregationService $realtimeService;

    private ?DeadLetterQueueService $dlqService;

    private ?ProviderHealthMonitor $providerHealthMonitor;

    /**
     * @param  AnalyticsManager  $manager
     * @param  ConfigRepository  $config
     * @param  AnalyticsAnomalyDetectionService|null  $anomalyService
     * @param  SaaSConversionService|null  $conversionService
     * @param  ChurnPredictionService|null  $churnService
     * @param  EventHealthMonitorService|null  $healthMonitor
     * @param  ProviderFallbackService|null  $fallbackService
     * @param  EventBudgetService|null  $budgetService
     * @param  PrivacyImpactAssessmentService|null  $privacyService
     * @param  EventTransformationEngine|null  $transformationEngine
     * @param  AnalyticsConsentComplianceService|null  $consentService
     * @param  EventPredictiveScoringService|null  $predictiveService
     * @param  RealTimeAggregationService|null  $realtimeService
     * @param  DeadLetterQueueService|null  $dlqService
     * @param  ProviderHealthMonitor|null  $providerHealthMonitor
     */
    public function __construct(
        AnalyticsManager $manager,
        ConfigRepository $config,
        ?AnalyticsAnomalyDetectionService $anomalyService = null,
        ?SaaSConversionService $conversionService = null,
        ?ChurnPredictionService $churnService = null,
        ?EventHealthMonitorService $healthMonitor = null,
        ?ProviderFallbackService $fallbackService = null,
        ?EventBudgetService $budgetService = null,
        ?PrivacyImpactAssessmentService $privacyService = null,
        ?EventTransformationEngine $transformationEngine = null,
        ?AnalyticsConsentComplianceService $consentService = null,
        ?EventPredictiveScoringService $predictiveService = null,
        ?RealTimeAggregationService $realtimeService = null,
        ?DeadLetterQueueService $dlqService = null,
        ?ProviderHealthMonitor $providerHealthMonitor = null,
    ): void {
        $this->manager = $manager;
        $this->config = new AnalyticsConfig($config);
        $this->anomalyService = $anomalyService;
        $this->conversionService = $conversionService;
        $this->churnService = $churnService;
        $this->healthMonitor = $healthMonitor;
        $this->fallbackService = $fallbackService;
        $this->budgetService = $budgetService;
        $this->privacyService = $privacyService;
        $this->transformationEngine = $transformationEngine;
        $this->consentService = $consentService;
        $this->predictiveService = $predictiveService;
        $this->realtimeService = $realtimeService;
        $this->dlqService = $dlqService;
        $this->providerHealthMonitor = $providerHealthMonitor;
    }

    /**
     * Generate the full intelligence dashboard payload.
     *
     * Aggregates all subsystems into a single response suitable for
     * rendering a real-time SaaS analytics dashboard.
     *
     * @param  array{include?: list<string>, exclude?: list<string>}  $options
     * @return array{timestamp: string, version: string, provider_health: array<string, mixed>, catalog_coverage: array<string, mixed>, anomaly_summary: array<string, mixed>, funnel_health: array<string, mixed>, churn_signals: array<string, mixed>, revenue_health: array<string, mixed>, pipeline_health: array<string, mixed>, data_quality: array<string, mixed>, fallback_status: array<string, mixed>, budget_utilization: array<string, mixed>, privacy_compliance: array<string, mixed>, transformation_status: array<string, mixed>, overall_score: int, overall_grade: string, alerts: list<array{severity: string, source: string, message: string}>}
     */
    public function dashboard(array $options = []): array
    {
        $include = $options['include'] ?? null;
        $exclude = $options['exclude'] ?? [];

        $dashboard = [
            'timestamp' => now()->toIso8601String(),
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'provider_health' => $this->getProviderHealth(),
            'catalog_coverage' => $this->getCatalogCoverage(),
            'anomaly_summary' => $this->getAnomalySummary(),
            'funnel_health' => $this->getFunnelHealth(),
            'churn_signals' => $this->getChurnSignals(),
            'revenue_health' => $this->getRevenueHealth(),
            'pipeline_health' => $this->getPipelineHealth(),
            'data_quality' => $this->getDataQuality(),
            'fallback_status' => $this->getFallbackStatus(),
            'budget_utilization' => $this->getBudgetUtilization(),
            'privacy_compliance' => $this->getPrivacyCompliance(),
            'transformation_status' => $this->getTransformationStatus(),
        ];

        // Filter sections based on include/exclude options
        if (is_array($include) && $include !== []) {
            $filtered = [];
            foreach ($include as $key) {
                if (array_key_exists($key, $dashboard)) {
                    $filtered[$key] = $dashboard[$key];
                }
            }
            $dashboard = $filtered;
        } elseif (is_array($exclude) && $exclude !== []) {
            foreach ($exclude as $key) {
                unset($dashboard[$key]);
            }
        }

        // Compute overall health score and grade
        $scoreResult = $this->computeOverallScore($dashboard);
        $dashboard['overall_score'] = $scoreResult['score'];
        $dashboard['overall_grade'] = $scoreResult['grade'];
        $dashboard['alerts'] = $scoreResult['alerts'];

        return $dashboard;
    }

    /**
     * Generate a lightweight heartbeat for monitoring systems.
     *
     * Returns a minimal payload suitable for uptime checks and
     * health monitoring endpoints. Designed for high-frequency polling.
     *
     * @return array{status: string, version: string, timestamp: string, enabled_providers: int, total_providers: int, catalog_events: int, score: int, grade: string}
     */
    public function heartbeat(): array
    {
        $catalogCount = EventCatalog::count();
        $enabledProviders = $this->countEnabledProviders();
        $totalProviders = 10;

        $score = $this->computeQuickScore($enabledProviders, $totalProviders, $catalogCount);

        return [
            'status' => $score >= 70 ? 'healthy' : ($score >= 40 ? 'degraded' : 'critical'),
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'timestamp' => now()->toIso8601String(),
            'enabled_providers' => $enabledProviders,
            'total_providers' => $totalProviders,
            'catalog_events' => $catalogCount,
            'score' => $score,
            'grade' => $this->scoreToGrade($score),
        ];
    }

    /**
     * Get provider health status for all configured providers.
     *
     * @return array{providers: array<string, array{enabled: bool, configured: bool, healthy: bool}>, enabled_count: int, total: int}
     */
    private function getProviderHealth(): array
    {
        $providers = [
            'ga4' => [
                'enabled' => $this->manager->ga4()->isEnabled(),
                'configured' => $this->manager->ga4()->getMeasurementId() !== '',
            ],
            'gtm' => [
                'enabled' => $this->manager->gtm()->isEnabled(),
                'configured' => $this->manager->gtm()->getContainerId() !== '',
            ],
            'meta_pixel' => [
                'enabled' => $this->manager->meta()->isEnabled(),
                'configured' => $this->manager->meta()->getPixelId() !== '',
            ],
            'plausible' => [
                'enabled' => $this->manager->plausible()->isEnabled(),
                'configured' => $this->manager->plausible()->getDomain() !== '',
            ],
            'posthog' => [
                'enabled' => $this->manager->posthog()->isEnabled(),
                'configured' => $this->manager->posthog()->getHost() !== '',
            ],
            'mixpanel' => [
                'enabled' => $this->manager->mixpanel()->isEnabled(),
                'configured' => $this->manager->mixpanel()->getToken() !== '',
            ],
            'amplitude' => [
                'enabled' => $this->manager->amplitude()->isEnabled(),
                'configured' => $this->manager->amplitude()->getApiKey() !== '',
            ],
            'tiktok' => [
                'enabled' => $this->manager->tiktok()->isEnabled(),
                'configured' => $this->manager->tiktok()->getPixelId() !== '',
            ],
            'linkedin' => [
                'enabled' => $this->manager->linkedin()->isEnabled(),
                'configured' => $this->manager->linkedin()->getPartnerId() !== '',
            ],
        ];

        // Merge provider health monitor data if available
        if ($this->providerHealthMonitor !== null) {
            try {
                $healthData = $this->providerHealthMonitor->health();
                if (is_array($healthData)) {
                    foreach ($providers as $name => &$provider) {
                        if (isset($healthData[$name])) {
                            $provider['healthy'] = (bool) ($healthData[$name]['healthy'] ?? $provider['configured']);
                            $provider['latency_ms'] = $healthData[$name]['latency_ms'] ?? null;
                            $provider['last_check'] = $healthData[$name]['last_check'] ?? null;
                        } else {
                            $provider['healthy'] = $provider['configured'];
                        }
                    }
                    unset($provider);
                }
            } catch (\Throwable) {
                // Gracefully degrade — mark all as configured
                foreach ($providers as &$provider) {
                    $provider['healthy'] = $provider['configured'];
                }
                unset($provider);
            }
        } else {
            foreach ($providers as &$provider) {
                $provider['healthy'] = $provider['configured'];
            }
            unset($provider);
        }

        $enabledCount = count(array_filter($providers, static fn (array $p): bool => $p['enabled']));

        return [
            'providers' => $providers,
            'enabled_count' => $enabledCount,
            'total' => 10,
        ];
    }

    /**
     * Get event catalog instrumentation coverage analysis.
     *
     * @return array{total: int, by_category: array<string, int>, industry_standard_coverage: float, starter_coverage: float, essential_coverage: float, instrumented: int, gap_count: int, top_gaps: list<string>}
     */
    private function getCatalogCoverage(): array
    {
        $byCategory = EventCatalog::byCategory();
        $categoryCounts = [];

        foreach ($byCategory as $category => $events) {
            $categoryCounts[$category] = count($events);
        }

        $total = EventCatalog::count();
        $industry = EventCatalog::industryStandard();
        $starter = EventCatalog::recommendedInstrumentation('starter');
        $essential = EventCatalog::saasEssential();

        // Assume all catalog events are "instrumented" (registered = tracked)
        $instrumented = $total;
        $gapCount = max(0, $industry['count'] - $instrumented);

        // Top gaps: industry standard events not yet in catalog
        $instrumentedNames = array_keys(EventCatalog::all());
        $topGaps = [];
        foreach ($industry['critical'] as $entry) {
            if (! in_array($entry['name'] ?? '', $instrumentedNames, true)) {
                $topGaps[] = $entry['name'] ?? '';
            }
        }

        return [
            'total' => $total,
            'by_category' => $categoryCounts,
            'industry_standard_coverage' => $industry['count'] > 0
                ? round(($instrumented / $industry['count']) * 100, 1)
                : 0.0,
            'starter_coverage' => $starter['count'] > 0
                ? round((min($instrumented, $starter['count']) / $starter['count']) * 100, 1)
                : 0.0,
            'essential_coverage' => $essential['count'] > 0
                ? round((min($instrumented, $essential['count']) / $essential['count']) * 100, 1)
                : 0.0,
            'instrumented' => $instrumented,
            'gap_count' => $gapCount,
            'top_gaps' => array_slice($topGaps, 0, 10),
        ];
    }

    /**
     * Get anomaly detection summary.
     *
     * @return array{enabled: bool, recent_anomalies: int, severity_breakdown: array<string, int>, last_checked: string|null, status: string}
     */
    private function getAnomalySummary(): array
    {
        if ($this->anomalyService === null) {
            return [
                'enabled' => false,
                'recent_anomalies' => 0,
                'severity_breakdown' => [],
                'last_checked' => null,
                'status' => 'not_configured',
            ];
        }

        try {
            $anomalies = $this->anomalyService->recentAnomalies(limit: 10);
            $severityBreakdown = ['critical' => 0, 'warning' => 0, 'info' => 0];

            foreach ($anomalies as $anomaly) {
                $severity = $anomaly['severity'] ?? 'info';
                if (isset($severityBreakdown[$severity])) {
                    $severityBreakdown[$severity]++;
                }
            }

            $hasCritical = $severityBreakdown['critical'] > 0;

            return [
                'enabled' => true,
                'recent_anomalies' => count($anomalies),
                'severity_breakdown' => $severityBreakdown,
                'last_checked' => now()->toIso8601String(),
                'status' => $hasCritical ? 'alerting' : 'nominal',
            ];
        } catch (\Throwable) {
            return [
                'enabled' => true,
                'recent_anomalies' => 0,
                'severity_breakdown' => [],
                'last_checked' => now()->toIso8601String(),
                'status' => 'error',
            ];
        }
    }

    /**
     * Get SaaS funnel health analysis.
     *
     * @return array{signup_to_trial: float|null, trial_to_paid: float|null, signup_to_paid: float|null, status: string, events_tracked: list<string>}
     */
    private function getFunnelHealth(): array
    {
        $funnelEvents = EventCatalog::saasFunnelEvents();
        $eventNames = array_map(static fn (array $e): string => $e['name'], $funnelEvents);

        if ($this->conversionService === null) {
            return [
                'signup_to_trial' => null,
                'trial_to_paid' => null,
                'signup_to_paid' => null,
                'status' => 'not_configured',
                'events_tracked' => $eventNames,
            ];
        }

        try {
            $rates = $this->conversionService->funnelRates();

            return [
                'signup_to_trial' => $rates['signup_to_trial'] ?? null,
                'trial_to_paid' => $rates['trial_to_paid'] ?? null,
                'signup_to_paid' => $rates['signup_to_paid'] ?? null,
                'status' => ($rates['trial_to_paid'] ?? 0) >= 25 ? 'healthy' : 'attention',
                'events_tracked' => $eventNames,
            ];
        } catch (\Throwable) {
            return [
                'signup_to_trial' => null,
                'trial_to_paid' => null,
                'signup_to_paid' => null,
                'status' => 'error',
                'events_tracked' => $eventNames,
            ];
        }
    }

    /**
     * Get churn prediction signals.
     *
     * @return array{enabled: bool, risk_level: string, signal_events: list<string>, retention_signals_count: int, status: string}
     */
    private function getChurnSignals(): array
    {
        $retentionEvents = EventCatalog::retentionSignals();
        $signalNames = array_map(static fn (array $e): string => $e['name'], $retentionEvents);

        if ($this->churnService === null) {
            return [
                'enabled' => false,
                'risk_level' => 'unknown',
                'signal_events' => $signalNames,
                'retention_signals_count' => count($signalNames),
                'status' => 'not_configured',
            ];
        }

        try {
            $churnData = $this->churnService->predict();

            return [
                'enabled' => true,
                'risk_level' => $churnData['risk_level'] ?? 'unknown',
                'signal_events' => $signalNames,
                'retention_signals_count' => count($signalNames),
                'status' => 'active',
            ];
        } catch (\Throwable) {
            return [
                'enabled' => true,
                'risk_level' => 'unknown',
                'signal_events' => $signalNames,
                'retention_signals_count' => count($signalNames),
                'status' => 'error',
            ];
        }
    }

    /**
     * Get revenue health status.
     *
     * @return array{billing_events_tracked: int, revenue_events: list<string>, ecommerce_currency: string, subscription_tiers: int, status: string}
     */
    private function getRevenueHealth(): array
    {
        $billingEvents = EventCatalog::billingEvents();
        $revenueEventNames = array_map(static fn (array $e): string => $e['name'], $billingEvents);

        return [
            'billing_events_tracked' => count($billingEvents),
            'revenue_events' => array_slice($revenueEventNames, 0, 15),
            'ecommerce_currency' => $this->config->ecommerceCurrency(),
            'subscription_tiers' => 0,
            'status' => count($billingEvents) >= 10 ? 'healthy' : 'attention',
        ];
    }

    /**
     * Get analytics pipeline health status.
     *
     * @return array{queue_enabled: bool, queue_connection: string|null, auto_utm: bool, auto_timestamp: bool, sampling_enabled: bool, sampling_rate: float, pii_enabled: bool, consent_log_enabled: bool, validation_strict: bool, status: string}
     */
    private function getPipelineHealth(): array
    {
        $checks = 0;
        $healthy = 0;

        if ($this->config->queueEnabled()) {
            $checks++;
            $healthy++;
        }

        if ($this->config->pipelineAutoUtm()) {
            $checks++;
            $healthy++;
        }

        if ($this->config->validationStrict()) {
            $checks++;
            $healthy++;
        }

        if ($this->config->piiEnabled()) {
            $checks++;
            $healthy++;
        }

        if ($this->config->consentLogEnabled()) {
            $checks++;
            $healthy++;
        }

        return [
            'queue_enabled' => $this->config->queueEnabled(),
            'queue_connection' => $this->config->queueConnection(),
            'auto_utm' => $this->config->pipelineAutoUtm(),
            'auto_timestamp' => $this->config->pipelineAutoTimestamp(),
            'sampling_enabled' => $this->config->samplingEnabled(),
            'sampling_rate' => $this->config->samplingRate(),
            'pii_enabled' => $this->config->piiEnabled(),
            'consent_log_enabled' => $this->config->consentLogEnabled(),
            'validation_strict' => $this->config->validationStrict(),
            'status' => $checks > 0 && $healthy === $checks ? 'healthy' : 'attention',
        ];
    }

    /**
     * Get data quality metrics.
     *
     * @return array{dedup_window: int, pii_strategy: string, pii_custom_fields: int, sampling_deterministic: bool, quality_score: float}
     */
    private function getDataQuality(): array
    {
        $score = 0.0;

        // PII protection contributes to quality
        if ($this->config->piiEnabled()) {
            $score += 25.0;
        }

        // Deduplication window
        if ($this->config->validationDeduplicationWindow() > 0) {
            $score += 25.0;
        }

        // Validation strict mode
        if ($this->config->validationStrict()) {
            $score += 25.0;
        }

        // Schema enrichment
        if ($this->config->transformationStrict()) {
            $score += 25.0;
        }

        return [
            'dedup_window' => $this->config->validationDeduplicationWindow(),
            'pii_strategy' => $this->config->piiStrategy(),
            'pii_custom_fields' => count($this->config->piiCustomFields()),
            'sampling_deterministic' => $this->config->samplingDeterministic(),
            'quality_score' => $score,
        ];
    }

    /**
     * Get provider fallback / circuit breaker status.
     *
     * @return array{enabled: bool, providers: array<string, string>, status: string}
     */
    private function getFallbackStatus(): array
    {
        if ($this->fallbackService === null) {
            return [
                'enabled' => false,
                'providers' => [],
                'status' => 'not_configured',
            ];
        }

        try {
            $status = $this->fallbackService->status();

            return [
                'enabled' => true,
                'providers' => is_array($status) ? $status : [],
                'status' => 'active',
            ];
        } catch (\Throwable) {
            return [
                'enabled' => true,
                'providers' => [],
                'status' => 'error',
            ];
        }
    }

    /**
     * Get event budget utilization.
     *
     * @return array{enabled: bool, utilization_percent: float|null, remaining_percent: float|null, status: string}
     */
    private function getBudgetUtilization(): array
    {
        if ($this->budgetService === null) {
            return [
                'enabled' => false,
                'utilization_percent' => null,
                'remaining_percent' => null,
                'status' => 'not_configured',
            ];
        }

        try {
            $summary = $this->budgetService->summary();

            return [
                'enabled' => true,
                'utilization_percent' => $summary['utilization_percent'] ?? null,
                'remaining_percent' => $summary['remaining_percent'] ?? null,
                'status' => ($summary['utilization_percent'] ?? 0) >= 90 ? 'warning' : 'nominal',
            ];
        } catch (\Throwable) {
            return [
                'enabled' => true,
                'utilization_percent' => null,
                'remaining_percent' => null,
                'status' => 'error',
            ];
        }
    }

    /**
     * Get privacy compliance status.
     *
     * @return array{consent_default: string, consent_log_enabled: bool, consent_log_ttl: int, gdpr_compliant: bool, status: string}
     */
    private function getPrivacyCompliance(): array
    {
        $consentDefault = $this->config->consentDefault();
        $consentLogEnabled = $this->config->consentLogEnabled();
        $piiEnabled = $this->config->piiEnabled();
        $gdprCompliant = $consentDefault !== 'granted' || $consentLogEnabled;

        return [
            'consent_default' => $consentDefault,
            'consent_log_enabled' => $consentLogEnabled,
            'consent_log_ttl' => $this->config->consentLogTtl(),
            'gdpr_compliant' => $gdprCompliant,
            'status' => $gdprCompliant ? 'compliant' : 'attention',
        ];
    }

    /**
     * Get transformation engine status.
     *
     * @return array{enabled: bool, cache_ttl: int, strict: bool, mapping_count: int, status: string}
     */
    private function getTransformationStatus(): array
    {
        $mappings = $this->config->transformationMappings();

        return [
            'enabled' => $this->config->transformationEnabled(),
            'cache_ttl' => $this->config->transformationCacheTtl(),
            'strict' => $this->config->transformationStrict(),
            'mapping_count' => count($mappings),
            'status' => $this->config->transformationEnabled() ? 'active' : 'disabled',
        ];
    }

    /**
     * Count the number of enabled analytics providers.
     */
    private function countEnabledProviders(): int
    {
        $count = 0;

        if ($this->manager->ga4()->isEnabled()) {
            $count++;
        }

        if ($this->manager->gtm()->isEnabled()) {
            $count++;
        }

        if ($this->manager->meta()->isEnabled()) {
            $count++;
        }

        if ($this->manager->plausible()->isEnabled()) {
            $count++;
        }

        if ($this->manager->posthog()->isEnabled()) {
            $count++;
        }

        if ($this->manager->mixpanel()->isEnabled()) {
            $count++;
        }

        if ($this->manager->amplitude()->isEnabled()) {
            $count++;
        }

        if ($this->manager->tiktok()->isEnabled()) {
            $count++;
        }

        if ($this->manager->linkedin()->isEnabled()) {
            $count++;
        }

        return $count;
    }

    /**
     * Compute the overall health score from all dashboard sections.
     *
     * @param  array<string, mixed>  $dashboard
     * @return array{score: int, grade: string, alerts: list<array{severity: string, source: string, message: string}>}
     */
    private function computeOverallScore(array $dashboard): array
    {
        $score = 0;
        $maxScore = 0;
        $alerts = [];

        // Provider health (25 points max)
        $maxScore += 25;
        $providerHealth = $dashboard['provider_health'] ?? [];
        $enabledCount = $providerHealth['enabled_count'] ?? 0;
        $providerScore = min(25, (int) round(($enabledCount / 10) * 25));
        $score += $providerScore;

        if ($enabledCount === 0) {
            $alerts[] = [
                'severity' => 'critical',
                'source' => 'provider_health',
                'message' => 'No analytics providers are enabled',
            ];
        }

        // Catalog coverage (20 points max)
        $maxScore += 20;
        $coverage = $dashboard['catalog_coverage'] ?? [];
        $coveragePercent = $coverage['industry_standard_coverage'] ?? 0;
        $score += min(20, (int) round(($coveragePercent / 100) * 20));

        if (($coverage['gap_count'] ?? 0) > 5) {
            $alerts[] = [
                'severity' => 'warning',
                'source' => 'catalog_coverage',
                'message' => ($coverage['gap_count'] ?? 0) . ' industry-standard events not instrumented',
            ];
        }

        // Anomaly summary (10 points max)
        $maxScore += 10;
        $anomaly = $dashboard['anomaly_summary'] ?? [];
        if (($anomaly['status'] ?? '') === 'nominal') {
            $score += 10;
        } elseif (($anomaly['status'] ?? '') === 'alerting') {
            $score += 2;
            $alerts[] = [
                'severity' => 'critical',
                'source' => 'anomaly_detection',
                'message' => ($anomaly['recent_anomalies'] ?? 0) . ' critical anomalies detected',
            ];
        } elseif (($anomaly['status'] ?? '') === 'not_configured') {
            $score += 5;
        }

        // Pipeline health (15 points max)
        $maxScore += 15;
        $pipeline = $dashboard['pipeline_health'] ?? [];
        if (($pipeline['status'] ?? '') === 'healthy') {
            $score += 15;
        } else {
            $score += 8;
        }

        // Privacy compliance (15 points max)
        $maxScore += 15;
        $privacy = $dashboard['privacy_compliance'] ?? [];
        if (($privacy['status'] ?? '') === 'compliant') {
            $score += 15;
        } else {
            $score += 5;
            $alerts[] = [
                'severity' => 'warning',
                'source' => 'privacy_compliance',
                'message' => 'GDPR compliance gap detected — review consent configuration',
            ];
        }

        // Data quality (15 points max)
        $maxScore += 15;
        $quality = $dashboard['data_quality'] ?? [];
        $qualityScore = $quality['quality_score'] ?? 0;
        $score += (int) round(($qualityScore / 100) * 15);

        // Normalize to 0-100
        $normalizedScore = $maxScore > 0 ? (int) round(($score / $maxScore) * 100) : 0;

        return [
            'score' => $normalizedScore,
            'grade' => $this->scoreToGrade($normalizedScore),
            'alerts' => $alerts,
        ];
    }

    /**
     * Compute a quick health score for the heartbeat endpoint.
     */
    private function computeQuickScore(int $enabledProviders, int $totalProviders, int $catalogCount): int
    {
        $providerScore = $totalProviders > 0
            ? (int) round(($enabledProviders / $totalProviders) * 50)
            : 0;

        // Catalog coverage: 50+ events = full score
        $catalogScore = min(50, (int) round(($catalogCount / 50) * 50));

        return min(100, $providerScore + $catalogScore);
    }

    /**
     * Convert a numeric score to a letter grade.
     */
    private function scoreToGrade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A+',
            $score >= 80 => 'A',
            $score >= 70 => 'B',
            $score >= 60 => 'C',
            $score >= 50 => 'D',
            $score >= 40 => 'E',
            default => 'F',
        };
    }
}
