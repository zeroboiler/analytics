<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventPriorityCalculator;

/**
 * Comprehensive health check service for analytics configuration and coverage.
 *
 * Runs a full diagnostic across all analytics subsystems and returns
 * actionable health status, coverage scores, and improvement recommendations.
 *
 * Covers: provider configuration, catalog coverage, AARRR completeness,
 * identity tracking, queue setup, GDPR compliance, consent, lifecycle
 * mapper, auto-tracking, and dedup configuration.
 *
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::healthCheck()
 */
final class AnalyticsHealthCheckService
{
    private const VERSION = '4.6.0';

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly ConfigRepository $config,
    ): void {}

    /**
     * Run the full health check diagnostic.
     *
     * Returns a comprehensive health report with per-subsystem status,
     * coverage scores, and prioritized recommendations.
     *
     * @return array{
     *     status: string,
     *     version: string,
     *     overall_score: int,
     *     timestamp: string,
     *     subsystems: array<string, array{status: string, score: int, details: array<string, mixed>}>
     *     recommendations: list<array{priority: string, category: string, message: string}>
     * }
     */
    public function run(): array
    {
        $subsystems = [
            'providers' => $this->checkProviders(),
            'catalog' => $this->checkCatalog(),
            'aarr_coverage' => $this->checkAarrCoverage(),
            'identity' => $this->checkIdentity(),
            'queue' => $this->checkQueue(),
            'gdpr' => $this->checkGdpr(),
            'consent' => $this->checkConsent(),
            'lifecycle' => $this->checkLifecycle(),
            'auto_track' => $this->checkAutoTrack(),
            'dedup' => $this->checkDedup(),
            'api' => $this->checkApi(),
            'pipeline' => $this->checkPipeline(),
        ];

        $scores = array_column($subsystems, 'score');
        $overall = (int) round(array_sum($scores) / count($scores));
        $recommendations = $this->buildRecommendations($subsystems);
        $status = $this->determineStatus($overall, $subsystems);

        return [
            'status' => $status,
            'version' => self::VERSION,
            'overall_score' => $overall,
            'timestamp' => date('c'),
            'subsystems' => $subsystems,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Quick ping — returns version and whether at least one provider is configured.
     *
     * @return array{status: string, version: string, providers_configured: int, catalog_size: int}
     */
    public function ping(): array
    {
        $analyticsConfig = $this->config->get('zeroboiler.analytics', []);
        $count = 0;

        if (($analyticsConfig['ga4']['enabled'] ?? false) === true) {
            $count++;
        }
        if (($analyticsConfig['gtm']['enabled'] ?? false) === true) {
            $count++;
        }
        if (($analyticsConfig['meta_pixel']['enabled'] ?? false) === true) {
            $count++;
        }
        if (($analyticsConfig['plausible']['enabled'] ?? false) === true) {
            $count++;
        }
        if (($analyticsConfig['posthog']['enabled'] ?? false) === true) {
            $count++;
        }
        if (($analyticsConfig['webhook']['enabled'] ?? false) === true) {
            $count++;
        }

        return [
            'status' => $count > 0 ? 'ok' : 'no_providers',
            'version' => self::VERSION,
            'providers_configured' => $count,
            'catalog_size' => EventCatalog::count(),
        ];
    }

    /**
     * Check provider configuration health.
     *
     * @return array{status: string, score: int, details: array<string, mixed>}
     */
    private function checkProviders(): array
    {
        $analyticsConfig = $this->config->get('zeroboiler.analytics', []);
        $providers = [];

        $ga4Config = $analyticsConfig['ga4'] ?? [];
        $providers['ga4'] = [
            'enabled' => (bool) ($ga4Config['enabled'] ?? false),
            'measurement_id' => !empty($ga4Config['measurement_id']),
            'api_secret' => !empty($ga4Config['api_secret']),
        ];

        $gtmConfig = $analyticsConfig['gtm'] ?? [];
        $providers['gtm'] = [
            'enabled' => (bool) ($gtmConfig['enabled'] ?? false),
            'container_id' => !empty($gtmConfig['container_id']),
        ];

        $metaConfig = $analyticsConfig['meta_pixel'] ?? [];
        $providers['meta_pixel'] = [
            'enabled' => (bool) ($metaConfig['enabled'] ?? false),
            'pixel_id' => !empty($metaConfig['id']),
            'access_token' => !empty($metaConfig['access_token']),
        ];

        $plausibleConfig = $analyticsConfig['plausible'] ?? [];
        $providers['plausible'] = [
            'enabled' => (bool) ($plausibleConfig['enabled'] ?? false),
            'domain' => !empty($plausibleConfig['domain']),
        ];

        $posthogConfig = $analyticsConfig['posthog'] ?? [];
        $providers['posthog'] = [
            'enabled' => (bool) ($posthogConfig['enabled'] ?? false),
            'api_key' => !empty($posthogConfig['api_key']),
        ];

        $webhookConfig = $analyticsConfig['webhook'] ?? [];
        $providers['webhook'] = [
            'enabled' => (bool) ($webhookConfig['enabled'] ?? false),
            'url' => !empty($webhookConfig['url']),
        ];

        $enabledCount = count(array_filter($providers, fn (array $p): bool => $p['enabled']));

        // Score: 0 if none, 40 if GA4+GTM (standard), 70 if +Meta, 90 if +optional, 100 if all 6
        $score = match (true) {
            $enabledCount === 0 => 0,
            $enabledCount === 1 => 20,
            $enabledCount === 2 => 40,
            $enabledCount === 3 => 60,
            $enabledCount === 4 => 80,
            $enabledCount === 5 => 90,
            $enabledCount >= 6 => 100,
            default => 0,
        };

        return [
            'status' => $enabledCount > 0 ? 'ok' : 'warning',
            'score' => $score,
            'details' => [
                'enabled_count' => $enabledCount,
                'providers' => $providers,
            ],
        ];
    }

    /**
     * Check event catalog coverage.
     *
     * @return array{status: string, score: int, details: array<string, mixed>}
     */
    private function checkCatalog(): array
    {
        $readiness = EventCatalog::industryReadinessScore();
        $total = EventCatalog::count();
        $byCategory = EventCatalog::byCategory();
        $categoryCounts = array_map(fn (array $cat): int => count($cat), $byCategory);

        $score = $readiness['score'];

        return [
            'status' => $score >= 90 ? 'ok' : ($score >= 70 ? 'warning' : 'critical'),
            'score' => $score,
            'details' => [
                'total_events' => $total,
                'by_category' => $categoryCounts,
                'standard_coverage' => $readiness,
                'ga4_count' => count(EventCatalog::allGa4Names()),
                'meta_count' => count(EventCatalog::allMetaNames()),
            ],
        ];
    }

    /**
     * Check AARRR funnel coverage.
     *
     * @return array{status: string, score: int, details: array<string, mixed>}
     */
    private function checkAarrCoverage(): array
    {
        $calculator = new EventPriorityCalculator;
        $classified = $calculator->classifyAll();
        $maturity = $calculator->maturityScore();
        $funnels = $calculator->funnelReadiness();
        $categories = ['acquisition', 'activation', 'retention', 'revenue', 'referral'];

        $categoryScores = [];
        foreach ($categories as $cat) {
            $count = count($classified[$cat] ?? []);
            $categoryScores[$cat] = [
                'event_count' => $count,
                'has_events' => $count > 0,
            ];
        }

        $allCovered = count(array_filter($categoryScores, fn (array $c): bool => $c['has_events'])) === count($categories);

        return [
            'status' => $allCovered && $maturity['score'] >= 80 ? 'ok' : 'warning',
            'score' => $maturity['score'],
            'details' => [
                'categories' => $categoryScores,
                'maturity' => [
                    'score' => $maturity['score'],
                    'grade' => $maturity['grade'],
                ],
                'funnels' => [
                    'signup' => $funnels['signup_funnel']['score'],
                    'purchase' => $funnels['purchase_funnel']['score'],
                    'subscription' => $funnels['subscription_funnel']['score'],
                    'overall' => $funnels['overall'],
                ],
            ],
        ];
    }

    /**
     * Check identity tracking configuration.
     *
     * @return array{status: string, score: int, details: array<string, mixed>}
     */
    private function checkIdentity(): array
    {
        $identityConfig = $this->config->get('zeroboiler.analytics.identity', []);
        $score = 0;
        $issues = [];

        if (!empty($identityConfig['cookie_name'])) {
            $score += 30;
        } else {
            $issues[] = 'No cookie name configured';
        }

        if (($identityConfig['cookie_ttl'] ?? 0) > 0) {
            $score += 20;
        }

        if (($identityConfig['cookie_secure'] ?? false) === true) {
            $score += 20;
        } else {
            $issues[] = 'Cookie secure flag not set — insecure in production';
        }

        $sameSite = $identityConfig['cookie_samesite'] ?? 'Lax';
        if (in_array($sameSite, ['Lax', 'Strict'], true)) {
            $score += 20;
        }

        if (($identityConfig['link_on_auth'] ?? false) === true) {
            $score += 10;
        }

        return [
            'status' => $score >= 80 ? 'ok' : ($score >= 50 ? 'warning' : 'critical'),
            'score' => $score,
            'details' => [
                'cookie_name' => $identityConfig['cookie_name'] ?? null,
                'cookie_ttl' => $identityConfig['cookie_ttl'] ?? null,
                'cookie_secure' => $identityConfig['cookie_secure'] ?? false,
                'cookie_samesite' => $identityConfig['cookie_samesite'] ?? null,
                'link_on_auth' => $identityConfig['link_on_auth'] ?? false,
                'issues' => $issues,
            ],
        ];
    }

    /**
     * Check queue configuration.
     *
     * @return array{status: string, score: int, details: array<string, mixed>}
     */
    private function checkQueue(): array
    {
        $queueConfig = $this->config->get('zeroboiler.analytics.queue', []);
        $score = 0;

        if (($queueConfig['enabled'] ?? false) === true) {
            $score += 50;
        }

        if (!empty($queueConfig['queue'])) {
            $score += 30;
        }

        if (!empty($queueConfig['connection'])) {
            $score += 20;
        }

        return [
            'status' => $score >= 80 ? 'ok' : ($score >= 50 ? 'warning' : 'critical'),
            'score' => $score,
            'details' => [
                'enabled' => (bool) ($queueConfig['enabled'] ?? false),
                'queue_name' => $queueConfig['queue'] ?? null,
                'connection' => $queueConfig['connection'] ?? null,
            ],
        ];
    }

    /**
     * Check GDPR compliance settings.
     *
     * @return array{status: string, score: int, details: array<string, mixed>}
     */
    private function checkGdpr(): array
    {
        $gdprConfig = $this->config->get('zeroboiler.analytics.gdpr', []);
        $piiConfig = $this->config->get('zeroboiler.analytics.pii_sanitization', []);
        $retentionConfig = $this->config->get('zeroboiler.analytics.replay', []);
        $score = 0;

        if (($gdprConfig['anonymize_ip'] ?? false) === true) {
            $score += 40;
        }

        if (!empty($piiConfig['enabled'])) {
            $score += 30;
        }

        if (!empty($retentionConfig['enabled']) && ($retentionConfig['max_attempts'] ?? 0) > 0) {
            $score += 30;
        }

        return [
            'status' => $score >= 80 ? 'ok' : ($score >= 40 ? 'warning' : 'critical'),
            'score' => $score,
            'details' => [
                'ip_anonymization' => (bool) ($gdprConfig['anonymize_ip'] ?? false),
                'pii_sanitization' => (bool) ($piiConfig['enabled'] ?? false),
                'pii_strategy' => $piiConfig['strategy'] ?? null,
                'event_replay' => (bool) ($retentionConfig['enabled'] ?? false),
            ],
        ];
    }

    /**
     * Check consent mode configuration.
     *
     * @return array{status: string, score: int, details: array<string, mixed>}
     */
    private function checkConsent(): array
    {
        $consentConfig = $this->config->get('zeroboiler.analytics.consent', []);
        $score = 0;
        $issues = [];

        $default = $consentConfig['default'] ?? 'granted';
        if ($default === 'denied') {
            $score += 50; // GDPR-safe default
        } else {
            $score += 20;
            $issues[] = 'Default consent is "granted" — consider "denied" for GDPR compliance';
        }

        $purposes = $consentConfig['purposes'] ?? [];
        if (!empty($purposes)) {
            $score += 30;
        }

        if (($consentConfig['log_enabled'] ?? false) === true) {
            $score += 20;
        }

        return [
            'status' => $score >= 80 ? 'ok' : ($score >= 50 ? 'warning' : 'critical'),
            'score' => $score,
            'details' => [
                'default_state' => $default,
                'purposes_count' => count($purposes),
                'purposes' => array_keys($purposes),
                'log_enabled' => (bool) ($consentConfig['log_enabled'] ?? false),
                'issues' => $issues,
            ],
        ];
    }

    /**
     * Check lifecycle event mapper configuration.
     *
     * @return array{status: string, score: int, details: array<string, mixed>}
     */
    private function checkLifecycle(): array
    {
        $lifecycleConfig = $this->config->get('zeroboiler.analytics.lifecycle', []);
        $score = 0;

        if (($lifecycleConfig['enabled'] ?? false) === true) {
            $score += 40;
        }

        $events = $lifecycleConfig['events'] ?? [];
        $enabledEvents = count(array_filter($events, fn (bool $v): bool => $v === true));
        $totalEvents = count($events);
        $score += $totalEvents > 0 ? (int) round(($enabledEvents / $totalEvents) * 60) : 0;

        return [
            'status' => $score >= 70 ? 'ok' : ($score >= 40 ? 'warning' : 'critical'),
            'score' => $score,
            'details' => [
                'enabled' => (bool) ($lifecycleConfig['enabled'] ?? false),
                'mapped_events' => $totalEvents,
                'enabled_events' => $enabledEvents,
                'event_list' => array_keys(array_filter($events, fn (bool $v): bool => $v === true)),
            ],
        ];
    }

    /**
     * Check auto-track configuration.
     *
     * @return array{status: string, score: int, details: array<string, mixed>}
     */
    private function checkAutoTrack(): array
    {
        $autoTrackConfig = $this->config->get('zeroboiler.analytics.auto_track', []);
        $clientConfig = $this->config->get('zeroboiler.analytics.client_auto_track', []);
        $score = 0;

        if (($autoTrackConfig['enabled'] ?? false) === true) {
            $score += 30;
        }

        $serverEvents = $autoTrackConfig['events'] ?? [];
        $enabledServerEvents = count(array_filter($serverEvents, fn (bool $v): bool => $v === true));
        $score += min(30, $enabledServerEvents * 5);

        $clientTrackers = array_filter($clientConfig, fn (bool $v): bool => $v === true);
        $score += min(40, count($clientTrackers) * 7);

        return [
            'status' => $score >= 70 ? 'ok' : ($score >= 40 ? 'warning' : 'critical'),
            'score' => $score,
            'details' => [
                'server_enabled' => (bool) ($autoTrackConfig['enabled'] ?? false),
                'server_events_enabled' => $enabledServerEvents,
                'client_trackers' => array_keys(array_filter($clientConfig, fn (mixed $v): bool => $v === true)),
                'event_map_count' => count($autoTrackConfig['event_map'] ?? []),
            ],
        ];
    }

    /**
     * Check dedup configuration.
     *
     * @return array{status: string, score: int, details: array<string, mixed>}
     */
    private function checkDedup(): array
    {
        $dedupConfig = $this->config->get('zeroboiler.analytics.dedup', []);
        $score = 0;

        if (($dedupConfig['enabled'] ?? false) === true) {
            $score += 50;
        }

        if (($dedupConfig['window_seconds'] ?? 0) > 0) {
            $score += 30;
        }

        if (($dedupConfig['max_fingerprints'] ?? 0) > 0) {
            $score += 20;
        }

        return [
            'status' => $score >= 80 ? 'ok' : ($score >= 50 ? 'warning' : 'critical'),
            'score' => $score,
            'details' => [
                'enabled' => (bool) ($dedupConfig['enabled'] ?? false),
                'window_seconds' => $dedupConfig['window_seconds'] ?? null,
                'max_fingerprints' => $dedupConfig['max_fingerprints'] ?? null,
                'cache_prefix' => $dedupConfig['cache_prefix'] ?? null,
            ],
        ];
    }

    /**
     * Check API endpoint configuration.
     *
     * @return array{status: string, score: int, details: array<string, mixed>}
     */
    private function checkApi(): array
    {
        $apiConfig = $this->config->get('zeroboiler.analytics.api', []);
        $score = 0;

        if (($apiConfig['enabled'] ?? false) === true) {
            $score += 40;
        }

        if (($apiConfig['throttle'] ?? 0) > 0) {
            $score += 30;
        }

        if (!empty($apiConfig['auth_middleware'])) {
            $score += 30;
        }

        return [
            'status' => $score >= 80 ? 'ok' : ($score >= 50 ? 'warning' : 'critical'),
            'score' => $score,
            'details' => [
                'enabled' => (bool) ($apiConfig['enabled'] ?? false),
                'throttle' => $apiConfig['throttle'] ?? null,
                'base_url' => $apiConfig['base_url'] ?? null,
                'auth_middleware' => $apiConfig['auth_middleware'] ?? null,
            ],
        ];
    }

    /**
     * Check pipeline configuration.
     *
     * @return array{status: string, score: int, details: array<string, mixed>}
     */
    private function checkPipeline(): array
    {
        $pipelineConfig = $this->config->get('zeroboiler.analytics.pipeline', []);
        $samplingConfig = $this->config->get('zeroboiler.analytics.sampling', []);
        $score = 0;

        if (($pipelineConfig['auto_utm'] ?? false) === true) {
            $score += 25;
        }

        if (($pipelineConfig['auto_metadata'] ?? false) === true) {
            $score += 25;
        }

        if (($pipelineConfig['auto_timestamp'] ?? false) === true) {
            $score += 15;
        }

        if (($pipelineConfig['schema_enrichment'] ?? false) === true) {
            $score += 15;
        }

        // Sampling should be disabled (rate=1.0) or have sane defaults
        $samplingRate = (float) ($samplingConfig['rate'] ?? 1.0);
        if ($samplingRate >= 0.5) {
            $score += 20;
        } else {
            $score += 5;
        }

        return [
            'status' => $score >= 70 ? 'ok' : ($score >= 40 ? 'warning' : 'critical'),
            'score' => $score,
            'details' => [
                'auto_utm' => (bool) ($pipelineConfig['auto_utm'] ?? false),
                'auto_metadata' => (bool) ($pipelineConfig['auto_metadata'] ?? false),
                'auto_timestamp' => (bool) ($pipelineConfig['auto_timestamp'] ?? false),
                'schema_enrichment' => (bool) ($pipelineConfig['schema_enrichment'] ?? false),
                'sampling_enabled' => (bool) ($samplingConfig['enabled'] ?? false),
                'sampling_rate' => $samplingRate,
            ],
        ];
    }

    /**
     * Determine overall status from scores.
     *
     * @param  int  $overall
     * @param  array<string, array{status: string, score: int}>  $subsystems
     * @return string
     */
    private function determineStatus(int $overall, array $subsystems): string
    {
        $criticalCount = count(array_filter(
            $subsystems,
            fn (array $s): bool => ($s['status'] ?? '') === 'critical',
        ));

        if ($criticalCount > 0) {
            return 'critical';
        }

        if ($overall >= 80) {
            return 'healthy';
        }

        if ($overall >= 60) {
            return 'degraded';
        }

        return 'unhealthy';
    }

    /**
     * Build prioritized recommendations from subsystem checks.
     *
     * @param  array<string, array{status: string, score: int, details: array<string, mixed>}>  $subsystems
     * @return list<array{priority: string, category: string, message: string}>
     */
    private function buildRecommendations(array $subsystems): array
    {
        $recommendations = [];

        foreach ($subsystems as $name => $subsystem) {
            if ($subsystem['status'] === 'critical') {
                $recommendations[] = [
                    'priority' => 'critical',
                    'category' => $name,
                    'message' => "Critical issue in {$name} — score is {$subsystem['score']}/100",
                ];
            } elseif ($subsystem['status'] === 'warning' && $subsystem['score'] < 60) {
                $recommendations[] = [
                    'priority' => 'high',
                    'category' => $name,
                    'message' => "Improve {$name} configuration — score is {$subsystem['score']}/100",
                ];
            }
        }

        // Check identity issues specifically
        $identityIssues = $subsystems['identity']['details']['issues'] ?? [];
        foreach ($identityIssues as $issue) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'identity',
                'message' => $issue,
            ];
        }

        // Check consent issues
        $consentIssues = $subsystems['consent']['details']['issues'] ?? [];
        foreach ($consentIssues as $issue) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'consent',
                'message' => $issue,
            ];
        }

        usort($recommendations, function (array $a, array $b): int {
            $priority = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

            return ($priority[$a['priority']] ?? 99) <=> ($priority[$b['priority']] ?? 99);
        });

        return $recommendations;
    }
}
