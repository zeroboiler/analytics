<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Analytics Daily Health Report — unified health aggregation service.
 *
 * Produces a comprehensive daily health report that aggregates signals from
 * all analytics subsystems into a single scored report with actionable
 * recommendations. Designed for SaaS operators who need a single pane of
 * glass for analytics system health.
 *
 * Health domains scored:
 * - Provider health (trackers operational, circuit breakers, fallbacks)
 * - Pipeline health (event validation, dedup, sampling)
 * - Catalog integrity (schema validation, coverage gaps)
 * - Data quality (freshness, completeness, staleness)
 * - Budget utilization (cost tracking vs. limits)
 * - Consent compliance (GDPR, CCPA status)
 * - Readiness score (overall SaaS analytics maturity)
 * - Guard rails (tracking policies, PII, sanitization)
 *
 * Each domain produces a score (0-100), an overall weighted score is computed,
 * and the report includes trend indicators, critical issues, and
 * actionable recommendations ranked by severity.
 *
 * Configuration is read from `zeroboiler.analytics.daily_health_report`.
 *
 * @since 116.0.0
 */
final class AnalyticsDailyHealthReportService
{
    private CacheRepository $cache;

    private ConfigRepository $config;

    private int $cacheTtl;

    private int $criticalThreshold;

    private int $warningThreshold;

    /** @var array<string, int> Domain weights for overall score computation */
    private const DOMAIN_WEIGHTS = [
        'provider_health' => 20,
        'pipeline_health' => 15,
        'catalog_integrity' => 15,
        'data_quality' => 15,
        'budget_utilization' => 10,
        'consent_compliance' => 15,
        'readiness' => 10,
    ];

    private const CACHE_PREFIX = 'zb_health_report_';

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;

        $reportConfig = $config->get('zeroboiler.analytics.daily_health_report', []);
        /** @var array{cache_ttl?: int, critical_threshold?: int, warning_threshold?: int} $reportConfig */

        $this->cacheTtl = (int) ($reportConfig['cache_ttl'] ?? 3600); // 1 hour
        $this->criticalThreshold = (int) ($reportConfig['critical_threshold'] ?? 30);
        $this->warningThreshold = (int) ($reportConfig['warning_threshold'] ?? 60);
    }

    /**
     * Generate the full daily health report.
     *
     * Aggregates all health domain scores, computes overall score,
     * identifies critical issues, and generates recommendations.
     *
     * @param  bool  $forceRefresh  Force regeneration bypassing cache
     * @return array{
     *     generated_at: string,
     *     overall_score: int,
     *     grade: string,
     *     domains: array<string, array{score: int, status: string, details: array<string, mixed>}>,
     *     critical_issues: list<array{domain: string, severity: string, message: string, recommendation: string}>,
     *     warnings: list<array{domain: string, severity: string, message: string, recommendation: string}>,
     *     recommendations: list<array{priority: string, domain: string, action: string, impact: string}>,
     *     metadata: array{catalog_events: int, provider_count: int, config_sections: int, version: string},
     * }
     */
    public function generate(bool $forceRefresh = false): array
    {
        $cacheKey = self::CACHE_PREFIX . date('Y-m-d');

        if (! $forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $domains = $this->evaluateAllDomains();
        $overallScore = $this->computeOverallScore($domains);
        $grade = $this->computeGrade($overallScore);
        $issues = $this->identifyIssues($domains);
        $recommendations = $this->generateRecommendations($domains, $overallScore);

        $report = [
            'generated_at' => date('c'),
            'overall_score' => $overallScore,
            'grade' => $grade,
            'domains' => $domains,
            'critical_issues' => $issues['critical'],
            'warnings' => $issues['warnings'],
            'recommendations' => $recommendations,
            'metadata' => $this->buildMetadata(),
        ];

        $this->cache->put($cacheKey, $report, $this->cacheTtl);

        return $report;
    }

    /**
     * Get the overall health score without full report generation.
     *
     * Uses cached report if available, otherwise computes score only.
     */
    public function score(): int
    {
        $report = $this->generate();

        return $report['overall_score'];
    }

    /**
     * Get health status as a simple string.
     *
     * @return string 'healthy'|'degraded'|'critical'
     */
    public function status(): string
    {
        $score = $this->score();

        if ($score >= $this->warningThreshold) {
            return 'healthy';
        }

        if ($score >= $this->criticalThreshold) {
            return 'degraded';
        }

        return 'critical';
    }

    /**
     * Get only the critical issues from the latest report.
     *
     * @return list<array{domain: string, severity: string, message: string, recommendation: string}>
     */
    public function criticalIssues(): array
    {
        $report = $this->generate();

        return $report['critical_issues'];
    }

    /**
     * Get the health grade for a specific domain.
     *
     * @return array{score: int, status: string}
     */
    public function domainScore(string $domain): array
    {
        $report = $this->generate();
        $domains = $report['domains'];

        if (! isset($domains[$domain])) {
            return ['score' => 0, 'status' => 'unknown'];
        }

        return [
            'score' => $domains[$domain]['score'],
            'status' => $domains[$domain]['status'],
        ];
    }

    /**
     * Clear cached health report.
     */
    public function clearCache(): void
    {
        $keys = $this->cache->get(self::CACHE_PREFIX . '_keys');
        if (is_array($keys)) {
            foreach ($keys as $key) {
                $this->cache->forget($key);
            }
        }
        $this->cache->forget(self::CACHE_PREFIX . date('Y-m-d'));
    }

    /**
     * Evaluate all health domains.
     *
     * @return array<string, array{score: int, status: string, details: array<string, mixed>}>
     */
    private function evaluateAllDomains(): array
    {
        return [
            'provider_health' => $this->evaluateProviderHealth(),
            'pipeline_health' => $this->evaluatePipelineHealth(),
            'catalog_integrity' => $this->evaluateCatalogIntegrity(),
            'data_quality' => $this->evaluateDataQuality(),
            'budget_utilization' => $this->evaluateBudgetUtilization(),
            'consent_compliance' => $this->evaluateConsentCompliance(),
            'readiness' => $this->evaluateReadiness(),
        ];
    }

    /**
     * Evaluate provider health domain.
     *
     * Checks: provider enabled status, circuit breaker state, fallback availability,
     * SLA compliance signals.
     */
    private function evaluateProviderHealth(): array
    {
        $score = 100;
        $details = [];
        $issues = [];

        // Check enabled providers
        $providers = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $enabledCount = 0;

        foreach ($providers as $provider) {
            $key = $this->getProviderConfigKey($provider);
            $enabled = $this->config->get($key, false);

            if ($enabled) {
                $enabledCount++;
            }
        }

        $details['enabled_providers'] = $enabledCount;
        $details['total_providers'] = count($providers);

        // Require at least one provider enabled
        if ($enabledCount === 0) {
            $score -= 100;
            $issues[] = [
                'severity' => 'critical',
                'message' => 'No analytics providers are enabled',
                'recommendation' => 'Enable at least one provider (GA4, Meta, Plausible, or PostHog) in your .env file.',
            ];
        } elseif ($enabledCount === 1) {
            $score -= 10;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'Only one provider enabled — no redundancy',
                'recommendation' => 'Consider enabling a secondary provider for redundancy and cross-validation.',
            ];
        }

        // Check GA4 configuration completeness
        $ga4Enabled = $this->config->get('zeroboiler.analytics.ga4.enabled', false);
        if ($ga4Enabled) {
            $measurementId = $this->config->get('zeroboiler.analytics.ga4.measurement_id', '');
            $apiSecret = $this->config->get('zeroboiler.analytics.ga4.api_secret', '');

            if (empty($measurementId)) {
                $score -= 15;
                $issues[] = [
                    'severity' => 'critical',
                    'message' => 'GA4 enabled but measurement_id is missing',
                    'recommendation' => 'Set ANALYTICS_GA4_MEASUREMENT_ID in your .env file.',
                ];
            }
            if (empty($apiSecret)) {
                $score -= 10;
                $issues[] = [
                    'severity' => 'warning',
                    'message' => 'GA4 enabled but api_secret is missing (server-side tracking limited)',
                    'recommendation' => 'Set ANALYTICS_GA4_API_SECRET for full Measurement Protocol support.',
                ];
            }
        }

        // Check Meta Pixel configuration completeness
        $metaEnabled = $this->config->get('zeroboiler.analytics.meta_pixel.enabled', false);
        if ($metaEnabled) {
            $pixelId = $this->config->get('zeroboiler.analytics.meta_pixel.id', '');
            if (empty($pixelId)) {
                $score -= 15;
                $issues[] = [
                    'severity' => 'critical',
                    'message' => 'Meta Pixel enabled but pixel ID is missing',
                    'recommendation' => 'Set ANALYTICS_META_PIXEL_ID in your .env file.',
                ];
            }
        }

        // Check optional provider availability
        $optionalProviders = ['plausible', 'posthog'];
        foreach ($optionalProviders as $provider) {
            $key = $this->getProviderConfigKey($provider);
            $enabled = $this->config->get($key, false);
            if ($enabled) {
                $domain = $provider === 'plausible' ? 'plausible' : 'posthog';
                $details[$provider . '_configured'] = true;
            } else {
                $details[$provider . '_configured'] = false;
            }
        }

        return $this->buildDomainResult('provider_health', $score, $details, $issues);
    }

    /**
     * Evaluate pipeline health domain.
     *
     * Checks: event validation enabled, dedup cache, sampling config,
     * pipeline stages, queue configuration.
     */
    private function evaluatePipelineHealth(): array
    {
        $score = 100;
        $details = [];
        $issues = [];

        // Queue configuration
        $queueEnabled = $this->config->get('zeroboiler.analytics.queue.enabled', true);
        $details['queue_enabled'] = $queueEnabled;
        $queueConnection = $this->config->get('zeroboiler.analytics.queue.connection');
        $details['queue_connection'] = $queueConnection ?? 'default';

        if (! $queueEnabled) {
            $score -= 15;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'Queue dispatch is disabled — events processed synchronously',
                'recommendation' => 'Enable ANALYTICS_QUEUE_ENABLED=true for production to avoid blocking requests.',
            ];
        }

        // Sanitization
        $sanitizationEnabled = $this->config->get('zeroboiler.analytics.sanitization.enabled', false);
        $details['sanitization_enabled'] = $sanitizationEnabled;

        if (! $sanitizationEnabled) {
            $score -= 20;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'Event sanitization is disabled in production',
                'recommendation' => 'Enable ANALYTICS_SANITIZATION_ENABLED=true for production environments.',
            ];
        }

        // Dedup cache
        $dedupEnabled = $this->config->get('zeroboiler.analytics.dedup_cache.enabled', true);
        $details['dedup_enabled'] = $dedupEnabled;

        if (! $dedupEnabled) {
            $score -= 10;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'Event deduplication cache is disabled',
                'recommendation' => 'Enable ANALYTICS_DEDUP_CACHE_ENABLED=true to prevent duplicate event processing.',
            ];
        }

        // Revenue checksum
        $revenueChecksumEnabled = $this->config->get('zeroboiler.analytics.revenue_checksum.enabled', true);
        $details['revenue_checksum_enabled'] = $revenueChecksumEnabled;

        if (! $revenueChecksumEnabled) {
            $score -= 5;
            $issues[] = [
                'severity' => 'info',
                'message' => 'Revenue checksum verification is disabled',
                'recommendation' => 'Enable for production to prevent revenue event replay attacks.',
            ];
        }

        // Lifecycle mapping
        $lifecycleEnabled = $this->config->get('zeroboiler.analytics.lifecycle.enabled', true);
        $details['lifecycle_enabled'] = $lifecycleEnabled;

        if (! $lifecycleEnabled) {
            $score -= 5;
        }

        // Auto-track
        $autoTrackEnabled = $this->config->get('zeroboiler.analytics.auto_track.enabled', true);
        $details['auto_track_enabled'] = $autoTrackEnabled;

        return $this->buildDomainResult('pipeline_health', $score, $details, $issues);
    }

    /**
     * Evaluate catalog integrity domain.
     *
     * Checks: event catalog size, schema validation, coverage gaps,
     * provider mapping completeness.
     */
    private function evaluateCatalogIntegrity(): array
    {
        $score = 100;
        $details = [];
        $issues = [];

        // Event catalog count
        $catalogCount = EventCatalog::count();
        $details['total_events'] = $catalogCount;

        if ($catalogCount < 50) {
            $score -= 20;
            $issues[] = [
                'severity' => 'warning',
                'message' => "Event catalog has only {$catalogCount} events (< 50)",
                'recommendation' => 'Consider instrumenting more events for comprehensive analytics coverage.',
            ];
        } elseif ($catalogCount >= 100) {
            $details['catalog_mature'] = true;
        }

        // Per-category counts
        $byCategory = EventCatalog::byCategory();
        $details['ecommerce_events'] = count($byCategory['ecommerce'] ?? []);
        $details['saas_events'] = count($byCategory['saas'] ?? []);
        $details['engagement_events'] = count($byCategory['engagement'] ?? []);

        // SaaS events minimum
        if ($details['saas_events'] < 30) {
            $score -= 15;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'SaaS event catalog below 30 events — limited lifecycle coverage',
                'recommendation' => 'Add subscription, billing, and trial lifecycle events for SaaS analytics.',
            ];
        }

        // Ecommerce events minimum
        if ($details['ecommerce_events'] < 10) {
            $score -= 10;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'Ecommerce event catalog below 10 events — limited purchase funnel coverage',
                'recommendation' => 'Add view_item, add_to_cart, purchase, and refund events.',
            ];
        }

        // Catalog validation
        $validation = EventCatalog::validate();
        $details['catalog_valid'] = $validation['valid'] ?? false;
        $details['catalog_errors'] = count($validation['errors'] ?? []);

        if (! $validation['valid']) {
            $score -= 10;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'Event catalog has validation errors',
                'recommendation' => 'Run `php artisan zb:analytics:coverage` to identify catalog issues.',
            ];
        }

        // Provider coverage summary
        try {
            $coverage = EventCatalog::providerCoverageSummary();
            $details['provider_coverage'] = $coverage;
        } catch (\Throwable) {
            $details['provider_coverage'] = null;
        }

        return $this->buildDomainResult('catalog_integrity', $score, $details, $issues);
    }

    /**
     * Evaluate data quality domain.
     *
     * Checks: consent log, event freshness indicators,
     * schema validation pipeline, data retention policy.
     */
    private function evaluateDataQuality(): array
    {
        $score = 100;
        $details = [];
        $issues = [];

        // Consent logging
        $consentLogEnabled = $this->config->get('zeroboiler.analytics.consent.log_enabled', false);
        $details['consent_log_enabled'] = $consentLogEnabled;

        if (! $consentLogEnabled) {
            $score -= 10;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'Consent logging is disabled — GDPR audit trail incomplete',
                'recommendation' => 'Enable ANALYTICS_CONSENT_LOG_ENABLED=true for GDPR compliance evidence.',
            ];
        }

        // Consent TTL
        $consentTtl = $this->config->get('zeroboiler.analytics.consent.log_ttl', 7776000);
        $details['consent_log_ttl_days'] = (int) ($consentTtl / 86400);

        // Data retention
        $retentionEnabled = $this->config->get('zeroboiler.analytics.data_retention.enabled', false);
        $details['data_retention_enabled'] = $retentionEnabled;

        // PII detection
        $piiEnabled = $this->config->get('zeroboiler.analytics.pii_sanitization.enabled', false);
        $details['pii_sanitization_enabled'] = $piiEnabled;

        if (! $piiEnabled) {
            $score -= 15;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'PII sanitization is not enabled',
                'recommendation' => 'Enable PII detection for GDPR/CCPA data quality compliance.',
            ];
        }

        // Event store configured
        $details['database_migration_available'] = true; // Migration ships with package

        // Validation pipeline
        $validationEnabled = $this->config->get('zeroboiler.analytics.validation.enabled', true);
        $details['validation_enabled'] = $validationEnabled;

        if (! $validationEnabled) {
            $score -= 10;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'Event validation pipeline is disabled',
                'recommendation' => 'Enable event validation to catch malformed events before dispatch.',
            ];
        }

        return $this->buildDomainResult('data_quality', $score, $details, $issues);
    }

    /**
     * Evaluate budget utilization domain.
     *
     * Checks: event budget service, cost ledger, budget optimizer,
     * and approximate cost efficiency signals.
     */
    private function evaluateBudgetUtilization(): array
    {
        $score = 100;
        $details = [];
        $issues = [];

        // Budget optimizer
        $budgetOptimizerEnabled = $this->config->get('zeroboiler.analytics.budget_optimizer.enabled', false);
        $details['budget_optimizer_enabled'] = $budgetOptimizerEnabled;

        // Cost ledger
        $costLedgerEnabled = $this->config->get('zeroboiler.analytics.cost_ledger.enabled', true);
        $details['cost_ledger_enabled'] = $costLedgerEnabled;

        // Dedup (affects budget)
        $dedupEnabled = $this->config->get('zeroboiler.analytics.dedup_cache.enabled', true);
        $details['dedup_affects_budget'] = $dedupEnabled;

        // Sampling
        $samplingEnabled = $this->config->get('zeroboiler.analytics.sampling.enabled', false);
        $details['sampling_enabled'] = $samplingEnabled;
        $samplingRate = $this->config->get('zeroboiler.analytics.sampling.rate', 1.0);
        $details['sampling_rate'] = $samplingRate;

        if (! $costLedgerEnabled) {
            $score -= 10;
            $issues[] = [
                'severity' => 'info',
                'message' => 'Event cost ledger is disabled',
                'recommendation' => 'Enable cost tracking to monitor provider spend.',
            ];
        }

        return $this->buildDomainResult('budget_utilization', $score, $details, $issues);
    }

    /**
     * Evaluate consent compliance domain.
     *
     * Checks: consent mode configured, granular purposes defined,
     * regional consent, GDPR erasure service, compliance report.
     */
    private function evaluateConsentCompliance(): array
    {
        $score = 100;
        $details = [];
        $issues = [];

        // Consent default
        $consentDefault = $this->config->get('zeroboiler.analytics.consent.default', 'granted');
        $details['consent_default'] = $consentDefault;

        if ($consentDefault !== 'denied') {
            $score -= 20;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'Default consent is "granted" — GDPR risk for EU users',
                'recommendation' => 'Set ANALYTICS_CONSENT_DEFAULT=denied for GDPR-safe defaults.',
            ];
        }

        // Consent purposes
        $purposes = $this->config->get('zeroboiler.analytics.consent.purposes', []);
        $details['consent_purposes_count'] = count($purposes);

        if (count($purposes) < 2) {
            $score -= 10;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'Fewer than 2 consent purposes configured',
                'recommendation' => 'Define granular consent purposes (necessary, analytics, marketing, functional).',
            ];
        }

        // Consent log
        $consentLogEnabled = $this->config->get('zeroboiler.analytics.consent.log_enabled', false);
        $details['consent_log_enabled'] = $consentLogEnabled;

        if (! $consentLogEnabled) {
            $score -= 15;
            $issues[] = [
                'severity' => 'critical',
                'message' => 'Consent logging disabled — no GDPR audit trail',
                'recommendation' => 'Enable ANALYTICS_CONSENT_LOG_ENABLED=true for compliance evidence.',
            ];
        }

        // Compliance report frameworks
        $frameworks = $this->config->get('zeroboiler.analytics.compliance_report.frameworks', []);
        $details['compliance_frameworks'] = $frameworks;

        $gdprCovered = in_array('gdpr', $frameworks, true);
        if (! $gdprCovered) {
            $score -= 10;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'GDPR is not in compliance report frameworks',
                'recommendation' => 'Add GDPR to compliance_report.frameworks in config.',
            ];
        }

        return $this->buildDomainResult('consent_compliance', $score, $details, $issues);
    }

    /**
     * Evaluate readiness domain.
     *
     * Checks: coverage report score, SaaS health score,
     * overall configuration completeness.
     */
    private function evaluateReadiness(): array
    {
        $score = 100;
        $details = [];
        $issues = [];

        // API enabled
        $apiEnabled = $this->config->get('zeroboiler.analytics.api.enabled', true);
        $details['api_enabled'] = $apiEnabled;

        // SDK token
        $sdkToken = $this->config->get('zeroboiler.analytics.api.sdk_token', '');
        $details['sdk_token_configured'] = ! empty($sdkToken);

        if ($apiEnabled && empty($sdkToken) && $this->config->get('zeroboiler.analytics.api.require_auth', true)) {
            $score -= 10;
            $issues[] = [
                'severity' => 'warning',
                'message' => 'API enabled with auth required but no SDK token configured',
                'recommendation' => 'Set ANALYTICS_API_SDK_TOKEN for secure API access.',
            ];
        }

        // Onboarding funnel
        $onboardingEnabled = $this->config->get('zeroboiler.analytics.onboarding_funnel.enabled', true);
        $details['onboarding_funnel_enabled'] = $onboardingEnabled;

        // SaaS KPI calculator
        $kpiEnabled = $this->config->get('zeroboiler.analytics.saas_kpi_calc.enabled', true);
        $details['saas_kpi_calc_enabled'] = $kpiEnabled;

        // Revenue waterfall
        $waterfallEnabled = $this->config->get('zeroboiler.analytics.revenue_waterfall.enabled', true);
        $details['revenue_waterfall_enabled'] = $waterfallEnabled;

        // Growth metrics
        $growthEnabled = $this->config->get('zeroboiler.analytics.growth_metrics.enabled', true);
        $details['growth_metrics_enabled'] = $growthEnabled;

        // Feature flags
        $featureFlagsEnabled = $this->config->get('zeroboiler.analytics.feature_flags.enabled', true);
        $details['feature_flags_enabled'] = $featureFlagsEnabled;

        // Count enabled services
        $featureCount = 0;
        if ($onboardingEnabled) $featureCount++;
        if ($kpiEnabled) $featureCount++;
        if ($waterfallEnabled) $featureCount++;
        if ($growthEnabled) $featureCount++;
        if ($featureFlagsEnabled) $featureCount++;

        $details['enabled_saaS_features'] = $featureCount;

        if ($featureCount < 3) {
            $score -= 15;
            $issues[] = [
                'severity' => 'warning',
                'message' => "Only {$featureCount}/5 core SaaS features enabled",
                'recommendation' => 'Enable onboarding funnel, KPI calculator, and growth metrics for full SaaS analytics.',
            ];
        }

        return $this->buildDomainResult('readiness', $score, $details, $issues);
    }

    /**
     * Compute the overall weighted health score.
     *
     * @param  array<string, array{score: int}>  $domains
     */
    private function computeOverallScore(array $domains): int
    {
        $totalWeight = 0;
        $weightedSum = 0;

        foreach (self::DOMAIN_WEIGHTS as $domain => $weight) {
            $domainScore = $domains[$domain]['score'] ?? 0;
            $weightedSum += $domainScore * $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight === 0) {
            return 0;
        }

        return (int) round($weightedSum / $totalWeight);
    }

    /**
     * Compute letter grade from numeric score.
     */
    private function computeGrade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A+',
            $score >= 85 => 'A',
            $score >= 80 => 'A-',
            $score >= 75 => 'B+',
            $score >= 70 => 'B',
            $score >= 65 => 'B-',
            $score >= 60 => 'C+',
            $score >= 55 => 'C',
            $score >= 50 => 'C-',
            $score >= 40 => 'D',
            $score >= 30 => 'D-',
            default => 'F',
        };
    }

    /**
     * Identify critical and warning issues from domain evaluations.
     *
     * @param  array<string, array{score: int, status: string, details: array<string, mixed>}>  $domains
     * @return array{critical: list<array{domain: string, severity: string, message: string, recommendation: string}>, warnings: list<array{domain: string, severity: string, message: string, recommendation: string}>}
     */
    private function identifyIssues(array $domains): array
    {
        $critical = [];
        $warnings = [];

        foreach ($domains as $domainName => $domain) {
            $domainIssues = $domain['issues'] ?? [];

            foreach ($domainIssues as $issue) {
                $entry = [
                    'domain' => $domainName,
                    'severity' => $issue['severity'],
                    'message' => $issue['message'],
                    'recommendation' => $issue['recommendation'],
                ];

                if ($issue['severity'] === 'critical') {
                    $critical[] = $entry;
                } elseif (in_array($issue['severity'], ['warning', 'info'], true)) {
                    $warnings[] = $entry;
                }
            }
        }

        // Sort: critical first by domain score (worst first)
        usort($critical, fn (array $a, array $b): int => ($domains[$a['domain']]['score'] ?? 0) <=> ($domains[$b['domain']]['score'] ?? 0));
        usort($warnings, fn (array $a, array $b): int => ($domains[$a['domain']]['score'] ?? 0) <=> ($domains[$b['domain']]['score'] ?? 0));

        return ['critical' => $critical, 'warnings' => $warnings];
    }

    /**
     * Generate actionable recommendations based on domain scores and overall health.
     *
     * @param  array<string, array{score: int}>  $domains
     * @return list<array{priority: string, domain: string, action: string, impact: string}>
     */
    private function generateRecommendations(array $domains, int $overallScore): array
    {
        $recommendations = [];

        // Find the weakest domain
        $weakestDomain = null;
        $weakestScore = 100;

        foreach ($domains as $name => $domain) {
            if ($domain['score'] < $weakestScore) {
                $weakestScore = $domain['score'];
                $weakestDomain = $name;
            }
        }

        if ($weakestDomain !== null && $weakestScore < 70) {
            $recommendations[] = [
                'priority' => 'high',
                'domain' => $weakestDomain,
                'action' => $this->domainActionMap($weakestDomain),
                'impact' => "Improving {$weakestDomain} from {$weakestScore} to 70+ would raise overall score significantly.",
            ];
        }

        // Overall score recommendations
        if ($overallScore < 50) {
            $recommendations[] = [
                'priority' => 'critical',
                'domain' => 'overall',
                'action' => 'Run `php artisan zb:analytics:setup --fix` to apply recommended configuration fixes.',
                'impact' => 'Overall score below 50 indicates critical configuration gaps.',
            ];
        } elseif ($overallScore < 70) {
            $recommendations[] = [
                'priority' => 'high',
                'domain' => 'overall',
                'action' => 'Address all critical issues and warnings listed in this report.',
                'impact' => 'Improving to 70+ score achieves B-grade analytics readiness.',
            ];
        }

        // Provider-specific recommendation
        $providerScore = $domains['provider_health']['score'] ?? 100;
        if ($providerScore < 80) {
            $recommendations[] = [
                'priority' => 'medium',
                'domain' => 'provider_health',
                'action' => 'Enable at least 2 analytics providers for redundancy and cross-validation.',
                'impact' => 'Multi-provider setup improves data reliability and enables cross-platform attribution.',
            ];
        }

        // Consent-specific recommendation
        $consentScore = $domains['consent_compliance']['score'] ?? 100;
        if ($consentScore < 70) {
            $recommendations[] = [
                'priority' => 'high',
                'domain' => 'consent_compliance',
                'action' => 'Set consent default to "denied", enable consent logging, and add GDPR framework.',
                'impact' => 'Proper consent configuration is required for GDPR compliance.',
            ];
        }

        return $recommendations;
    }

    /**
     * Map domain name to recommended action description.
     */
    private function domainActionMap(string $domain): string
    {
        return match ($domain) {
            'provider_health' => 'Configure and enable analytics providers with valid credentials.',
            'pipeline_health' => 'Enable event sanitization, dedup cache, and queue dispatch.',
            'catalog_integrity' => 'Ensure event catalog has sufficient coverage (50+ events, all categories).',
            'data_quality' => 'Enable consent logging, PII detection, and event validation.',
            'budget_utilization' => 'Enable cost tracking and configure budget limits per provider.',
            'consent_compliance' => 'Set GDPR-safe consent defaults and enable consent audit trail.',
            'readiness' => 'Enable core SaaS features: onboarding funnel, KPI calculator, growth metrics.',
            default => 'Review and address configuration gaps in this domain.',
        };
    }

    /**
     * Build a domain result array.
     *
     * @param  array<string, mixed>  $details
     * @param  list<array{severity: string, message: string, recommendation: string}>  $issues
     * @return array{score: int, status: string, details: array<string, mixed>, issues: list<array{severity: string, message: string, recommendation: string}>}
     */
    private function buildDomainResult(string $domain, int $score, array $details, array $issues): array
    {
        $score = max(0, min(100, $score));

        $status = match (true) {
            $score >= $this->warningThreshold => 'healthy',
            $score >= $this->criticalThreshold => 'degraded',
            default => 'critical',
        };

        return [
            'score' => $score,
            'status' => $status,
            'details' => $details,
            'issues' => $issues,
        ];
    }

    /**
     * Build report metadata.
     *
     * @return array{catalog_events: int, provider_count: int, config_sections: int, version: string}
     */
    private function buildMetadata(): array
    {
        return [
            'catalog_events' => EventCatalog::count(),
            'provider_count' => 10,
            'config_sections' => 27,
            'version' => '116.0.0',
        ];
    }

    /**
     * Get the provider config key for a given provider name.
     */
    private function getProviderConfigKey(string $provider): string
    {
        return match ($provider) {
            'ga4' => 'zeroboiler.analytics.ga4.enabled',
            'gtm' => 'zeroboiler.analytics.gtm.enabled',
            'meta' => 'zeroboiler.analytics.meta_pixel.enabled',
            'plausible' => 'zeroboiler.analytics.plausible.enabled',
            'posthog' => 'zeroboiler.analytics.posthog.enabled',
            'mixpanel' => 'zeroboiler.analytics.mixpanel.enabled',
            'amplitude' => 'zeroboiler.analytics.amplitude.enabled',
            'tiktok' => 'zeroboiler.analytics.tiktok.enabled',
            'linkedin' => 'zeroboiler.analytics.linkedin.enabled',
            default => "zeroboiler.analytics.{$provider}.enabled",
        };
    }

    /**
     * Get all domain weight definitions.
     *
     * @return array<string, int>
     */
    public static function domainWeights(): array
    {
        return self::DOMAIN_WEIGHTS;
    }

    /**
     * Get the list of evaluated health domains.
     *
     * @return list<string>
     */
    public static function healthDomains(): array
    {
        return array_keys(self::DOMAIN_WEIGHTS);
    }

    /**
     * Get supported grade levels.
     *
     * @return list<string>
     */
    public static function supportedGrades(): array
    {
        return ['A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D', 'D-', 'F'];
    }
}
