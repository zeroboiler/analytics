<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\ProviderSLARecord;

/**
 * Provider SLA monitor — uptime, latency, and breach tracking per analytics provider.
 *
 * Monitors each configured analytics provider against SLA targets:
 * - **Uptime SLA**: Minimum acceptable success rate (e.g., 99.9%)
 * - **Latency SLA**: Maximum acceptable average dispatch latency (e.g., 500ms)
 * - **P99 Latency SLA**: Maximum acceptable 99th percentile latency (e.g., 2000ms)
 * - **Error Budget**: Allowed error count per window before breach
 *
 * Records are aggregated per time window (hourly, daily) and stored in cache.
 * Breaches are logged and emitted via the alert notification pipeline.
 *
 * Provides:
 * - Per-provider SLA dashboard data
 * - Historical uptime trending
 * - Breach detection with automatic alerting
 * - SLA compliance percentage over rolling windows
 * - Provider health comparison matrix
 *
 * Inspired by Google Cloud SLI/SLO framework, Datadog SLO monitoring,
 * and Sentry's service health tracking.
 *
 * Config: `zeroboiler.analytics.sla_monitor`
 *
 * @since 84.0.0
 *
 * @see \ZeroBoiler\Analytics\DTO\ProviderSLARecord
 * @see \ZeroBoiler\Analytics\Services\ProviderHealthMonitor
 * @see \ZeroBoiler\Analytics\Services\AlertNotificationService
 */
final class ProviderSLAMonitor
{
    private const CACHE_PREFIX = 'zb_sla_monitor_';
    private const BREACH_LOG_KEY = 'zb_sla_breaches';

    private readonly bool $enabled;
    private readonly int $windowSeconds;
    private readonly int $retentionWindows;
    private readonly float $defaultUptimeTarget;
    private readonly float $defaultLatencyTarget;
    private readonly float $defaultP99LatencyTarget;
    private readonly int $defaultErrorBudget;
    private readonly bool $alertOnBreach;
    private readonly int $maxBreachHistory;

    /** @var array<string, array{uptime_target: float, latency_target: float, p99_latency_target: float, error_budget: int}> */
    private readonly array $providerTargets;

    /** @var list<string> */
    private readonly array $monitoredProviders;

    private CacheRepository $cache;

    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $slaConfig = $config->get('zeroboiler.analytics.sla_monitor', []);

        $this->enabled = (bool) ($slaConfig['enabled'] ?? true);
        $this->windowSeconds = (int) ($slaConfig['window_seconds'] ?? 3600); // 1 hour default
        $this->retentionWindows = (int) ($slaConfig['retention_windows'] ?? 168); // 7 days hourly
        $this->defaultUptimeTarget = (float) ($slaConfig['default_uptime_target'] ?? 99.9);
        $this->defaultLatencyTarget = (float) ($slaConfig['default_latency_target'] ?? 500.0);
        $this->defaultP99LatencyTarget = (float) ($slaConfig['default_p99_latency_target'] ?? 2000.0);
        $this->defaultErrorBudget = (int) ($slaConfig['default_error_budget'] ?? 10);
        $this->alertOnBreach = (bool) ($slaConfig['alert_on_breach'] ?? true);
        $this->maxBreachHistory = (int) ($slaConfig['max_breach_history'] ?? 1000);
        $this->providerTargets = (array) ($slaConfig['providers'] ?? []);
        $this->monitoredProviders = (array) ($slaConfig['monitored_providers'] ?? [
            'ga4', 'meta_pixel', 'posthog', 'plausible', 'mixpanel',
            'amplitude', 'tiktok', 'linkedin',
        ]);
    }

    /**
     * Record a dispatch result for SLA tracking.
     *
     * @param  string  $provider  Provider identifier
     * @param  bool  $success  Whether the dispatch succeeded
     * @param  float  $latencyMs  Dispatch latency in milliseconds
     */
    public function recordDispatch(string $provider, bool $success, float $latencyMs): void
    {
        if (! $this->enabled) {
            return;
        }

        $window = $this->currentWindowKey();
        $this->accumulateDispatch($provider, $window, $success, $latencyMs);
    }

    /**
     * Get the SLA record for a provider in the current window.
     *
     * @return ProviderSLARecord|null
     */
    public function currentSLA(string $provider): ?ProviderSLARecord
    {
        if (! $this->enabled) {
            return null;
        }

        $window = $this->currentWindowKey();

        return $this->buildSLARecord($provider, $window);
    }

    /**
     * Get SLA records for all monitored providers in the current window.
     *
     * @return array<string, ProviderSLARecord>
     */
    public function allCurrentSLA(): array
    {
        if (! $this->enabled) {
            return [];
        }

        $records = [];
        $window = $this->currentWindowKey();

        foreach ($this->monitoredProviders as $provider) {
            $record = $this->buildSLARecord($provider, $window);

            if ($record !== null) {
                $records[$provider] = $record;
            }
        }

        return $records;
    }

    /**
     * Get SLA compliance percentage for a provider over a rolling window.
     *
     * Returns the percentage of time windows where SLA was met.
     */
    public function compliancePercentage(string $provider, int $windowCount = 24): float
    {
        if (! $this->enabled) {
            return 100.0;
        }

        $metCount = 0;
        $totalWindows = 0;

        for ($i = 0; $i < $windowCount; $i++) {
            $windowKey = $this->windowKeyForOffset($i);

            if (! in_array($provider, $this->monitoredProviders, true)) {
                continue;
            }

            $record = $this->buildSLARecord($provider, $windowKey);

            if ($record !== null) {
                $totalWindows++;
                if ($record->slaMet) {
                    $metCount++;
                }
            }
        }

        if ($totalWindows === 0) {
            return 100.0;
        }

        return ($metCount / $totalWindows) * 100;
    }

    /**
     * Get breach history for all providers.
     *
     * @return list<array{provider: string, window: string, breaches: int, uptime: float, latency: float}>
     */
    public function breachHistory(int $limit = 50): array
    {
        /** @var list<array{provider: string, window: string, breaches: int, uptime: float, latency: float}>|null $breaches */
        $breaches = $this->cache->get(self::BREACH_LOG_KEY);

        if ($breaches === null) {
            return [];
        }

        return array_slice($breaches, 0, $limit);
    }

    /**
     * Get a health comparison matrix across all monitored providers.
     *
     * @return array{providers: array<string, array{uptime: float, latency: float, p99: float, breaches: int, sla_met: bool, compliance: float}>, summary: array{total_providers: int, healthy: int, degraded: int, down: int}}
     */
    public function healthMatrix(): array
    {
        $providers = [];
        $healthy = 0;
        $degraded = 0;
        $down = 0;

        foreach ($this->monitoredProviders as $provider) {
            $record = $this->currentSLA($provider);
            $compliance = $this->compliancePercentage($provider, 24);

            $providers[$provider] = [
                'uptime' => $record?->uptimePercentage ?? 100.0,
                'latency' => $record?->avgLatencyMs ?? 0.0,
                'p99' => $record?->p99LatencyMs ?? 0.0,
                'breaches' => $record?->breachCount ?? 0,
                'sla_met' => $record?->slaMet ?? true,
                'compliance' => $compliance,
            ];

            if ($compliance >= 99.9 && ($record?->slaMet ?? true)) {
                $healthy++;
            } elseif ($compliance >= 95.0) {
                $degraded++;
            } else {
                $down++;
            }
        }

        return [
            'providers' => $providers,
            'summary' => [
                'total_providers' => count($this->monitoredProviders),
                'healthy' => $healthy,
                'degraded' => $degraded,
                'down' => $down,
            ],
        ];
    }

    /**
     * Get SLA summary for dashboard rendering.
     *
     * @return array{enabled: bool, current_window: string, providers: array<string, ProviderSLARecord>, compliance: array<string, float>, health_matrix: array<string, mixed>, breach_count: int}
     */
    public function summary(): array
    {
        $records = $this->allCurrentSLA();
        $compliance = [];

        foreach ($this->monitoredProviders as $provider) {
            $compliance[$provider] = $this->compliancePercentage($provider, 24);
        }

        return [
            'enabled' => $this->enabled,
            'current_window' => $this->currentWindowKey(),
            'providers' => $records,
            'compliance' => $compliance,
            'health_matrix' => $this->healthMatrix(),
            'breach_count' => count($this->breachHistory()),
        ];
    }

    /**
     * Accumulate a single dispatch result into the current window stats.
     */
    private function accumulateDispatch(string $provider, string $window, bool $success, float $latencyMs): void
    {
        $cacheKey = self::CACHE_PREFIX . $provider . '_' . $window;

        /** @var array{total: int, success: int, failed: int, latencies: list<float>}|null $stats */
        $stats = $this->cache->get($cacheKey);

        if ($stats === null) {
            $stats = [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'latencies' => [],
            ];
        }

        $stats['total']++;
        if ($success) {
            $stats['success']++;
        } else {
            $stats['failed']++;
        }

        // Keep only last 1000 latencies for p99 calculation
        $stats['latencies'][] = $latencyMs;
        if (count($stats['latencies']) > 1000) {
            $stats['latencies'] = array_slice($stats['latencies'], -1000);
        }

        $ttl = max($this->windowSeconds, 7200); // minimum 2 hours
        $this->cache->put($cacheKey, $stats, $ttl);
    }

    /**
     * Build an SLA record from accumulated window stats.
     *
     * @return ProviderSLARecord|null
     */
    private function buildSLARecord(string $provider, string $window): ?ProviderSLARecord
    {
        $cacheKey = self::CACHE_PREFIX . $provider . '_' . $window;

        /** @var array{total: int, success: int, failed: int, latencies: list<float>}|null $stats */
        $stats = $this->cache->get($cacheKey);

        if ($stats === null || $stats['total'] === 0) {
            return null;
        }

        $avgLatency = count($stats['latencies']) > 0
            ? array_sum($stats['latencies']) / count($stats['latencies'])
            : 0.0;

        $p99Latency = $this->calculateP99($stats['latencies']);

        $uptimePercentage = $stats['total'] > 0
            ? ($stats['success'] / $stats['total']) * 100
            : 100.0;

        $targets = $this->getTargets($provider);

        $breachCount = 0;

        if ($uptimePercentage < $targets['uptime_target']) {
            $breachCount++;
        }

        if ($avgLatency > $targets['latency_target'] && $stats['total'] > 0) {
            $breachCount++;
        }

        if ($p99Latency > $targets['p99_latency_target']) {
            $breachCount++;
        }

        if ($stats['failed'] > $targets['error_budget']) {
            $breachCount++;
        }

        $slaMet = $breachCount === 0;

        if (! $slaMet && $this->alertOnBreach) {
            $this->logBreach($provider, $window, $breachCount, $uptimePercentage, $avgLatency);
        }

        return new ProviderSLARecord(
            provider: $provider,
            window: $window,
            totalDispatches: $stats['total'],
            successfulDispatches: $stats['success'],
            failedDispatches: $stats['failed'],
            avgLatencyMs: round($avgLatency, 2),
            p99LatencyMs: round($p99Latency, 2),
            uptimePercentage: round($uptimePercentage, 4),
            breachCount: $breachCount,
            slaMet: $slaMet,
        );
    }

    /**
     * Get SLA targets for a specific provider.
     *
     * @return array{uptime_target: float, latency_target: float, p99_latency_target: float, error_budget: int}
     */
    private function getTargets(string $provider): array
    {
        return $this->providerTargets[$provider] ?? [
            'uptime_target' => $this->defaultUptimeTarget,
            'latency_target' => $this->defaultLatencyTarget,
            'p99_latency_target' => $this->defaultP99LatencyTarget,
            'error_budget' => $this->defaultErrorBudget,
        ];
    }

    /**
     * Calculate p99 latency from a list of latencies.
     */
    private function calculateP99(array $latencies): float
    {
        if (count($latencies) < 2) {
            return $latencies[0] ?? 0.0;
        }

        sort($latencies);
        $index = (int) ceil((99 / 100) * count($latencies)) - 1;

        return $latencies[max(0, $index)] ?? 0.0;
    }

    /**
     * Log an SLA breach for alerting.
     */
    private function logBreach(string $provider, string $window, int $breachCount, float $uptime, float $latency): void
    {
        /** @var list<array{provider: string, window: string, breaches: int, uptime: float, latency: float}>|null $breaches */
        $breaches = $this->cache->get(self::BREACH_LOG_KEY);

        if ($breaches === null) {
            $breaches = [];
        }

        $breaches[] = [
            'provider' => $provider,
            'window' => $window,
            'breaches' => $breachCount,
            'uptime' => round($uptime, 4),
            'latency' => round($latency, 2),
            'timestamp' => time(),
        ];

        // Keep only recent breaches
        if (count($breaches) > $this->maxBreachHistory) {
            $breaches = array_slice($breaches, -$this->maxBreachHistory);
        }

        $this->cache->put(self::BREACH_LOG_KEY, $breaches, 86400 * 7); // 7 days

        Log::warning("Analytics SLA breach: provider={$provider}, breaches={$breachCount}, uptime={$uptime}%, latency={$latency}ms", [
            'analytics_sla_breach' => true,
            'provider' => $provider,
            'window' => $window,
        ]);
    }

    /**
     * Generate the cache key for the current time window.
     */
    private function currentWindowKey(): string
    {
        $windowStart = (int) (time() / $this->windowSeconds);

        return date('Y-m-d_H', (int) ($windowStart * $this->windowSeconds));
    }

    /**
     * Generate the cache key for a past time window.
     */
    private function windowKeyForOffset(int $offset): string
    {
        $windowStart = (int) (time() / $this->windowSeconds) - $offset;

        return date('Y-m-d_H', (int) ($windowStart * $this->windowSeconds));
    }
}
