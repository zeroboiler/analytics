<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Analytics Health Monitor Dashboard Service.
 *
 * Provides a unified health monitoring dashboard for the entire analytics
 * stack. Aggregates health data from multiple subsystems (providers,
 * queue, cache, consent, pipeline, config) into a single composite score
 * (0-100) with detailed per-subsystem breakdowns.
 *
 * Designed for SaaS admin dashboards — call `getDashboardData()` for
 * a complete snapshot suitable for chart rendering.
 *
 * Health dimensions:
 *   - Provider connectivity (0-100): Can we reach GA4/Meta/PostHog/etc?
 *   - Queue health (0-100): Is the event queue processing normally?
 *   - Config integrity (0-100): Is the configuration valid and complete?
 *   - Pipeline health (0-100): Are pipeline stages processing correctly?
 *   - Consent coverage (0-100): Is GDPR consent properly configured?
 *   - Rate limiting (0-100): Are rate limits healthy?
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService
 * @see \ZeroBoiler\Analytics\Services\AnalyticsHealthService
 * @see \ZeroBoiler\Analytics\Services\AnalyticsReadinessService
 *
 * @since 8.8.0
 */
final class AnalyticsHealthMonitorService
{
    private const DEFAULT_CACHE_TTL = 300; // 5 minutes

    private const GRADE_THRESHOLDS = [
        'A' => 90,
        'B' => 80,
        'C' => 70,
        'D' => 60,
        'F' => 0,
    ];

    private CacheRepository $cache;

    private ConfigRepository $config;

    private int $cacheTtl;

    /** @var array<string, float> */
    private array $weights;

    /** @var list<string> */
    private array $enabledDimensions;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $monitorConfig = $config->get('zeroboiler.analytics.health_monitor', []);
        /** @var array{cache_ttl?: int, weights?: array<string, float>, dimensions?: list<string>} $monitorConfig */

        $this->cacheTtl = (int) ($monitorConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->weights = (array) ($monitorConfig['weights'] ?? [
            'providers' => 0.25,
            'queue' => 0.20,
            'config' => 0.20,
            'pipeline' => 0.15,
            'consent' => 0.10,
            'rate_limiting' => 0.10,
        ]);
        $this->enabledDimensions = (array) ($monitorConfig['dimensions'] ?? [
            'providers', 'queue', 'config', 'pipeline', 'consent', 'rate_limiting',
        ]);
    }

    /**
     * Get the full health dashboard data.
     *
     * Returns a complete snapshot with composite score, grade, per-dimension
     * breakdowns, alerts, and timestamp. Designed for admin dashboard rendering.
     *
     * @return array{composite_score: int, grade: string, status: string, dimensions: array<string, array{name: string, score: int, weight: float, status: string, details: array<string, mixed>}>, alerts: array<int, array{severity: string, dimension: string, message: string, timestamp: string}>, metadata: array{computed_at: string, cache_ttl: int, refresh_interval_seconds: int}}
     */
    public function getDashboardData(): array
    {
        $cacheKey = 'zb_health_monitor_dashboard';

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $dimensions = $this->computeAllDimensions();
        $compositeScore = $this->computeCompositeScore($dimensions);
        $grade = $this->computeGrade($compositeScore);
        $status = $this->computeStatus($compositeScore);
        $alerts = $this->generateAlerts($dimensions);

        $result = [
            'composite_score' => $compositeScore,
            'grade' => $grade,
            'status' => $status,
            'dimensions' => $dimensions,
            'alerts' => $alerts,
            'metadata' => [
                'computed_at' => date('c'),
                'cache_ttl' => $this->cacheTtl,
                'refresh_interval_seconds' => $this->cacheTtl,
            ],
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Get the composite health score (0-100).
     */
    public function getScore(): int
    {
        $dashboard = $this->getDashboardData();

        return $dashboard['composite_score'];
    }

    /**
     * Get the health grade (A-F).
     */
    public function getGrade(): string
    {
        $dashboard = $this->getDashboardData();

        return $dashboard['grade'];
    }

    /**
     * Get health score for a specific dimension.
     *
     * @param  string  $dimension  Dimension name (providers, queue, config, pipeline, consent, rate_limiting)
     * @return int Score 0-100, or 0 if dimension not found
     */
    public function getDimensionScore(string $dimension): int
    {
        $dashboard = $this->getDashboardData();

        return $dashboard['dimensions'][$dimension]['score'] ?? 0;
    }

    /**
     * Check if the analytics stack is healthy (composite score >= 80).
     */
    public function isHealthy(): bool
    {
        return $this->getScore() >= 80;
    }

    /**
     * Check if the analytics stack is degraded (composite score 60-79).
     */
    public function isDegraded(): bool
    {
        $score = $this->getScore();

        return $score >= 60 && $score < 80;
    }

    /**
     * Check if the analytics stack is critical (composite score < 60).
     */
    public function isCritical(): bool
    {
        return $this->getScore() < 60;
    }

    /**
     * Get time-series health history for trend chart rendering.
     *
     * @param  int  $points  Number of data points
     * @return array<int, array{timestamp: string, score: int, grade: string}>
     */
    public function getHistory(int $points = 24): array
    {
        $cacheKey = 'zb_health_monitor_history';
        $history = $this->cache->get($cacheKey, []);

        if (! is_array($history)) {
            $history = [];
        }

        return array_slice($history, -$points);
    }

    /**
     * Record a health data point (called by scheduled commands or middleware).
     */
    public function recordDataPoint(): void
    {
        $cacheKey = 'zb_health_monitor_history';
        $history = $this->cache->get($cacheKey, []);

        if (! is_array($history)) {
            $history = [];
        }

        $score = $this->getScore();
        $grade = $this->computeGrade($score);

        $history[] = [
            'timestamp' => date('c'),
            'score' => $score,
            'grade' => $grade,
        ];

        // Keep last 168 points (7 days at hourly intervals)
        $this->cache->put($cacheKey, array_slice($history, -168), 604800);
    }

    /**
     * Invalidate the cached dashboard data.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget('zb_health_monitor_dashboard');
    }

    /**
     * Compute all enabled dimension scores.
     *
     * @return array<string, array{name: string, score: int, weight: float, status: string, details: array<string, mixed>}>
     */
    private function computeAllDimensions(): array
    {
        $dimensions = [];
        $computers = $this->getDimensionComputers();

        foreach ($this->enabledDimensions as $dim) {
            $computer = $computers[$dim] ?? null;
            $weight = $this->weights[$dim] ?? 0.0;

            if ($computer !== null) {
                $result = $computer();
            } else {
                $result = ['score' => 100, 'details' => []];
            }

            $score = (int) $result['score'];
            $status = $score >= 90 ? 'healthy' : ($score >= 70 ? 'warning' : ($score >= 50 ? 'degraded' : 'critical'));

            $dimensions[$dim] = [
                'name' => $this->dimensionDisplayName($dim),
                'score' => min(100, max(0, $score)),
                'weight' => $weight,
                'status' => $status,
                'details' => $result['details'] ?? [],
            ];
        }

        return $dimensions;
    }

    /**
     * Get the dimension computation functions.
     *
     * @return array<string, callable(): array{score: int, details: array<string, mixed>}>
     */
    private function getDimensionComputers(): array
    {
        return [
            'providers' => fn (): array => $this->checkProviderHealth(),
            'queue' => fn (): array => $this->checkQueueHealth(),
            'config' => fn (): array => $this->checkConfigHealth(),
            'pipeline' => fn (): array => $this->checkPipelineHealth(),
            'consent' => fn (): array => $this->checkConsentHealth(),
            'rate_limiting' => fn (): array => $this->checkRateLimitHealth(),
        ];
    }

    /**
     * Check provider connectivity and health.
     *
     * @return array{score: int, details: array<string, mixed>}
     */
    private function checkProviderHealth(): array
    {
        $details = [];
        $totalWeight = 0;
        $weightedSum = 0;

        $providers = [
            'ga4' => ['label' => 'GA4', 'config_key' => 'ga4', 'weight' => 0.30],
            'gtm' => ['label' => 'GTM', 'config_key' => 'gtm', 'weight' => 0.10],
            'meta' => ['label' => 'Meta Pixel', 'config_key' => 'meta_pixel', 'weight' => 0.25],
            'plausible' => ['label' => 'Plausible', 'config_key' => 'plausible', 'weight' => 0.15],
            'posthog' => ['label' => 'PostHog', 'config_key' => 'posthog', 'weight' => 0.15],
            'webhook' => ['label' => 'Webhook', 'config_key' => 'webhook', 'weight' => 0.05],
        ];

        foreach ($providers as $key => $provider) {
            $pConfig = $this->config->get("zeroboiler.analytics.{$provider['config_key']}", []);
            /** @var array{enabled?: bool} $pConfig */
            $enabled = (bool) ($pConfig['enabled'] ?? false);
            $configured = $this->isProviderConfigured($provider['config_key']);

            if (! $enabled) {
                $details[$key] = ['enabled' => false, 'configured' => $configured, 'score' => null];
                continue;
            }

            $totalWeight += $provider['weight'];
            $score = $configured ? 100 : 40;

            // Check provider health cache (from telemetry)
            $healthCacheKey = 'zb_analytics_telemetry_' . $key . '_health';
            $healthData = $this->cache->get($healthCacheKey);
            if (is_array($healthData)) {
                $healthScore = (int) ($healthData['score'] ?? 100);
                $score = (int) (($score + $healthScore) / 2);
            }

            $details[$key] = [
                'enabled' => true,
                'configured' => $configured,
                'score' => $score,
            ];
            $weightedSum += $score * $provider['weight'];
        }

        $score = $totalWeight > 0 ? (int) ($weightedSum / $totalWeight) : 100;

        // Bonus: no providers configured at all is a warning
        $anyEnabled = false;
        foreach ($details as $detail) {
            if (($detail['enabled'] ?? false)) {
                $anyEnabled = true;
                break;
            }
        }
        if (! $anyEnabled) {
            $score = 30;
        }

        return ['score' => $score, 'details' => $details];
    }

    /**
     * Check queue health and processing status.
     *
     * @return array{score: int, details: array<string, mixed>}
     */
    private function checkQueueHealth(): array
    {
        $queueConfig = $this->config->get('zeroboiler.analytics.queue', []);
        /** @var array{enabled?: bool, queue?: string, connection?: string, max_batch_size?: int} $queueConfig */
        $enabled = (bool) ($queueConfig['enabled'] ?? true);
        $queue = (string) ($queueConfig['queue'] ?? 'analytics');
        $connection = (string) ($queueConfig['connection'] ?? '');

        $details = [
            'enabled' => $enabled,
            'queue_name' => $queue,
            'connection' => $connection ?: 'default',
        ];

        $score = 100;

        if (! $enabled) {
            // Sync mode — not ideal but acceptable
            $score = 70;
            $details['note'] = 'Synchronous dispatch (queue disabled)';
        }

        // Check DLQ health
        $dlqConfig = $this->config->get('zeroboiler.analytics.dead_letter_queue', []);
        /** @var array{enabled?: bool, strategy?: string} $dlqConfig */
        $dlqEnabled = (bool) ($dlqConfig['enabled'] ?? true);
        $details['dlq_enabled'] = $dlqEnabled;
        if (! $dlqEnabled) {
            $score = (int) ($score * 0.9);
        }

        // Check replay enabled
        $replayConfig = $this->config->get('zeroboiler.analytics.replay', []);
        /** @var array{enabled?: bool} $replayConfig */
        $replayEnabled = (bool) ($replayConfig['enabled'] ?? true);
        $details['replay_enabled'] = $replayEnabled;
        if (! $replayEnabled) {
            $score = (int) ($score * 0.95);
        }

        return ['score' => $score, 'details' => $details];
    }

    /**
     * Check configuration health and completeness.
     *
     * @return array{score: int, details: array<string, mixed>}
     */
    private function checkConfigHealth(): array
    {
        $details = [];
        $score = 100;

        // Check consent default is set explicitly
        $consentDefault = $this->config->get('zeroboiler.analytics.consent.default', 'granted');
        $details['consent_default'] = $consentDefault;
        if ($consentDefault === 'granted') {
            $score = (int) ($score * 0.95); // Slight penalty for non-explicit consent
        }

        // Check identity cookie configured
        $cookieName = $this->config->get('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id');
        $details['identity_cookie'] = $cookieName;

        // Check debug mode OFF
        $debugEnabled = (bool) $this->config->get('zeroboiler.analytics.debug.enabled', false);
        $details['debug_mode'] = $debugEnabled;
        if ($debugEnabled) {
            $score = (int) ($score * 0.7);
        }

        // Check validation active
        $strict = (bool) $this->config->get('zeroboiler.analytics.validation.strict', false);
        $details['strict_validation'] = $strict;

        // Check dedup active
        $dedupEnabled = (bool) $this->config->get('zeroboiler.analytics.dedup.enabled', true);
        $details['dedup_enabled'] = $dedupEnabled;
        if (! $dedupEnabled) {
            $score = (int) ($score * 0.9);
        }

        // Check PII sanitization
        $piiEnabled = (bool) $this->config->get('zeroboiler.analytics.pii_sanitization.enabled', false);
        $details['pii_sanitization'] = $piiEnabled;

        // Check GDPR IP anonymization
        $gdprAnonymize = (bool) $this->config->get('zeroboiler.analytics.gdpr.anonymize_ip', false);
        $details['gdpr_ip_anonymization'] = $gdprAnonymize;
        if (! $gdprAnonymize) {
            $score = (int) ($score * 0.95);
        }

        return ['score' => $score, 'details' => $details];
    }

    /**
     * Check pipeline processing health.
     *
     * @return array{score: int, details: array<string, mixed>}
     */
    private function checkPipelineHealth(): array
    {
        $pipelineConfig = $this->config->get('zeroboiler.analytics.pipeline', []);
        /** @var array{auto_utm?: bool, auto_timestamp?: bool, auto_metadata?: bool, schema_enrichment?: bool} $pipelineConfig */

        $details = [
            'auto_utm' => (bool) ($pipelineConfig['auto_utm'] ?? true),
            'auto_metadata' => (bool) ($pipelineConfig['auto_metadata'] ?? true),
            'schema_enrichment' => (bool) ($pipelineConfig['schema_enrichment'] ?? false),
        ];

        $score = 100;

        // Check dedup pipeline
        $dedupConfig = $this->config->get('zeroboiler.analytics.dedup', []);
        /** @var array{enabled?: bool} $dedupConfig */
        $dedupEnabled = (bool) ($dedupConfig['enabled'] ?? true);
        $details['dedup'] = $dedupEnabled;
        if (! $dedupEnabled) {
            $score = (int) ($score * 0.9);
        }

        // Check debounce
        $debounceConfig = $this->config->get('zeroboiler.analytics.debounce', []);
        /** @var array{enabled?: bool} $debounceConfig */
        $debounceEnabled = (bool) ($debounceConfig['enabled'] ?? true);
        $details['debounce'] = $debounceEnabled;

        // Check performance budget
        $perfBudget = $this->config->get('zeroboiler.analytics.performance_budget', []);
        /** @var array{enabled?: bool} $perfBudget */
        $perfEnabled = (bool) ($perfBudget['enabled'] ?? false);
        $details['performance_budget'] = $perfEnabled;

        return ['score' => $score, 'details' => $details];
    }

    /**
     * Check consent coverage and GDPR compliance.
     *
     * @return array{score: int, details: array<string, mixed>}
     */
    private function checkConsentHealth(): array
    {
        $consentConfig = $this->config->get('zeroboiler.analytics.consent', []);
        /** @var array{default?: string, purposes?: array<string, mixed>, log_enabled?: bool, log_ttl?: int} $consentConfig */

        $details = [
            'default' => (string) ($consentConfig['default'] ?? 'granted'),
            'purposes' => (array) ($consentConfig['purposes'] ?? []),
            'log_enabled' => (bool) ($consentConfig['log_enabled'] ?? false),
        ];

        $score = 100;

        $default = $consentConfig['default'] ?? 'granted';
        if ($default === 'granted') {
            $score = (int) ($score * 0.85); // Not GDPR-safe by default
        }

        if (! ($consentConfig['log_enabled'] ?? false)) {
            $score = (int) ($score * 0.9);
        }

        if (empty($consentConfig['purposes'])) {
            $score = (int) ($score * 0.95);
        }

        return ['score' => $score, 'details' => $details];
    }

    /**
     * Check rate limiting health.
     *
     * @return array{score: int, details: array<string, mixed>}
     */
    private function checkRateLimitHealth(): array
    {
        $apiConfig = $this->config->get('zeroboiler.analytics.api', []);
        /** @var array{throttle?: int, enabled?: bool} $apiConfig */
        $guardConfig = $this->config->get('zeroboiler.analytics.api_guard', []);
        /** @var array{enabled?: bool} $guardConfig */

        $details = [
            'api_enabled' => (bool) ($apiConfig['enabled'] ?? true),
            'throttle' => (int) ($apiConfig['throttle'] ?? 60),
            'guard_enabled' => (bool) ($guardConfig['enabled'] ?? true),
        ];

        $score = 100;

        if (! ($apiConfig['enabled'] ?? true)) {
            $score = 50;
        }

        if (! ($guardConfig['enabled'] ?? true)) {
            $score = (int) ($score * 0.8);
        }

        // Check provider rate limits
        $providerRateConfig = $this->config->get('zeroboiler.analytics.provider_rate_limits', []);
        /** @var array{enabled?: bool} $providerRateConfig */
        $details['provider_rate_limits'] = (bool) ($providerRateConfig['enabled'] ?? false);

        return ['score' => $score, 'details' => $details];
    }

    /**
     * Compute the composite score from dimension scores and weights.
     *
     * @param  array<string, array{score: int, weight: float}>  $dimensions
     */
    private function computeCompositeScore(array $dimensions): int
    {
        $totalWeight = 0;
        $weightedSum = 0;

        foreach ($dimensions as $dim) {
            $weightedSum += $dim['score'] * $dim['weight'];
            $totalWeight += $dim['weight'];
        }

        if ($totalWeight === 0.0) {
            return 0;
        }

        return (int) round($weightedSum / $totalWeight);
    }

    /**
     * Compute a letter grade from a numeric score.
     */
    private function computeGrade(int $score): string
    {
        foreach (self::GRADE_THRESHOLDS as $grade => $threshold) {
            if ($score >= $threshold) {
                return $grade;
            }
        }

        return 'F';
    }

    /**
     * Compute a status string from a numeric score.
     */
    private function computeStatus(int $score): string
    {
        return match (true) {
            $score >= 90 => 'healthy',
            $score >= 80 => 'operational',
            $score >= 70 => 'degraded',
            $score >= 60 => 'warning',
            default => 'critical',
        };
    }

    /**
     * Generate alerts for dimensions with issues.
     *
     * @param  array<string, array{score: int, name: string}>  $dimensions
     * @return array<int, array{severity: string, dimension: string, message: string, timestamp: string}>
     */
    private function generateAlerts(array $dimensions): array
    {
        $alerts = [];

        foreach ($dimensions as $key => $dim) {
            if ($dim['score'] < 50) {
                $alerts[] = [
                    'severity' => 'critical',
                    'dimension' => $key,
                    'message' => "{$dim['name']} health is critical (score: {$dim['score']})",
                    'timestamp' => date('c'),
                ];
            } elseif ($dim['score'] < 70) {
                $alerts[] = [
                    'severity' => 'warning',
                    'dimension' => $key,
                    'message' => "{$dim['name']} health is degraded (score: {$dim['score']})",
                    'timestamp' => date('c'),
                ];
            }
        }

        return array_values($alerts);
    }

    /**
     * Check if a provider has required credentials configured.
     */
    private function isProviderConfigured(string $configKey): bool
    {
        $config = $this->config->get("zeroboiler.analytics.{$configKey}", []);

        return match ($configKey) {
            'ga4' => ! empty($config['measurement_id']) && ! empty($config['api_secret']),
            'gtm' => ! empty($config['container_id']),
            'meta_pixel' => ! empty($config['id']),
            'plausible' => ! empty($config['domain']) && ! empty($config['api_key']),
            'posthog' => ! empty($config['api_key']),
            'webhook' => ! empty($config['url']),
            default => false,
        };
    }

    /**
     * Get display name for a dimension.
     */
    private function dimensionDisplayName(string $dim): string
    {
        return match ($dim) {
            'providers' => 'Provider Connectivity',
            'queue' => 'Queue Health',
            'config' => 'Config Integrity',
            'pipeline' => 'Pipeline Health',
            'consent' => 'Consent Coverage',
            'rate_limiting' => 'Rate Limiting',
            default => ucfirst(str_replace('_', ' ', $dim)),
        };
    }
}
