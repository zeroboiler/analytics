<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * Self-monitoring telemetry service for analytics provider health.
 *
 * Periodically verifies provider connectivity and records probe results
 * in cache for dashboard exposure. Supports GA4 measurement protocol ping,
 * PostHog capture endpoint check, Plausible event endpoint, and webhook
 * endpoint verification.
 *
 * Designed to be called by a scheduled command or health-check middleware.
 * Results are cached to avoid hammering providers on every request.
 *
 * @see \ZeroBoiler\Analytics\AnalyticsHealthService
 */
final class AnalyticsTelemetryService
{
    /** @var array<string, array{status: string, latency_ms: int, last_check: string, error?: string}> */
    private array $probes = [];

    private bool $enabled;

    private int $cacheTtl;

    private string $cachePrefix;

    /** @var array<string, array{enabled: bool, measurement_id?: string, api_secret?: string, id?: string, host?: string, url?: string}> */
    private array $providerConfigs;

    /**
     * @param  AnalyticsManager  $manager
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly AnalyticsManager $manager,
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ) {
        $telemetryConfig = $config->get('zeroboiler.analytics.telemetry', []);
        /** @var array{enabled?: bool, cache_ttl?: int, cache_prefix?: string} $telemetryConfig */

        $this->enabled = (bool) ($telemetryConfig['enabled'] ?? false);
        $this->cacheTtl = (int) ($telemetryConfig['cache_ttl'] ?? 300); // 5 minutes
        $this->cachePrefix = (string) ($telemetryConfig['cache_prefix'] ?? 'zb_analytics_telemetry');

        $this->providerConfigs = [
            'ga4' => [
                'enabled' => (bool) ($config->get('zeroboiler.analytics.ga4.enabled', false)),
                'measurement_id' => (string) ($config->get('zeroboiler.analytics.ga4.measurement_id', '')),
                'api_secret' => (string) ($config->get('zeroboiler.analytics.ga4.api_secret', '')),
            ],
            'posthog' => [
                'enabled' => (bool) ($config->get('zeroboiler.analytics.posthog.enabled', false)),
                'host' => (string) ($config->get('zeroboiler.analytics.posthog.host', '')),
                'id' => (string) ($config->get('zeroboiler.analytics.posthog.project_id', '')),
            ],
            'plausible' => [
                'enabled' => (bool) ($config->get('zeroboiler.analytics.plausible.enabled', false)),
                'url' => (string) ($config->get('zeroboiler.analytics.plausible.base_url', '')),
            ],
            'webhook' => [
                'enabled' => (bool) ($config->get('zeroboiler.analytics.webhook.enabled', false)),
                'url' => (string) ($config->get('zeroboiler.analytics.webhook.url', '')),
            ],
        ];
    }

    /**
     * Run connectivity probes against all enabled providers.
     *
     * Sends a lightweight validation request to each provider's endpoint
     * and records the response status and latency. Results are cached
     * for the configured TTL.
     *
     * @return array<string, array{status: string, latency_ms: int, last_check: string, error?: string}>
     */
    public function probeAll(): array
    {
        if (! $this->enabled) {
            return [];
        }

        $results = $this->loadCachedResults();
        $now = date('c');

        foreach ($this->providerConfigs as $provider => $cfg) {
            if (! $cfg['enabled']) {
                $results[$provider] = [
                    'status' => 'disabled',
                    'latency_ms' => 0,
                    'last_check' => $now,
                ];
                continue;
            }

            $results[$provider] = $this->probeProvider($provider, $cfg);
            $results[$provider]['last_check'] = $now;
        }

        $this->probes = $results;
        $this->cacheResults($results);

        return $results;
    }

    /**
     * Probe a single provider by name.
     *
     * @param  'ga4'|'posthog'|'plausible'|'webhook'  $provider
     * @return array{status: string, latency_ms: int, last_check: string, error?: string}
     */
    public function probe(string $provider): array
    {
        if (! $this->enabled) {
            return ['status' => 'disabled', 'latency_ms' => 0, 'last_check' => date('c')];
        }

        $cfg = $this->providerConfigs[$provider] ?? [];

        if (empty($cfg['enabled'])) {
            return ['status' => 'disabled', 'latency_ms' => 0, 'last_check' => date('c')];
        }

        $result = $this->probeProvider($provider, $cfg);
        $result['last_check'] = date('c');

        $cached = $this->loadCachedResults();
        $cached[$provider] = $result;
        $this->cacheResults($cached);

        return $result;
    }

    /**
     * Get the latest probe results (from cache if available).
     *
     * @return array<string, array{status: string, latency_ms: int, last_check: string, error?: string}>
     */
    public function results(): array
    {
        return $this->loadCachedResults();
    }

    /**
     * Get a summary of provider health across all enabled providers.
     *
     * @return array{total: int, healthy: int, degraded: int, unhealthy: int, disabled: int, providers: array<string, string>}
     */
    public function summary(): array
    {
        $results = $this->loadCachedResults();

        $healthy = 0;
        $degraded = 0;
        $unhealthy = 0;
        $disabled = 0;
        $providers = [];

        foreach ($this->providerConfigs as $name => $cfg) {
            $probe = $results[$name] ?? null;

            if ($probe === null || ($probe['status'] ?? '') === 'disabled') {
                $disabled++;
                $providers[$name] = 'disabled';
            } elseif (($probe['status'] ?? '') === 'healthy') {
                $healthy++;
                $providers[$name] = 'healthy';
            } elseif (($probe['status'] ?? '') === 'degraded') {
                $degraded++;
                $providers[$name] = 'degraded';
            } else {
                $unhealthy++;
                $providers[$name] = 'unhealthy';
            }
        }

        return [
            'total' => count($this->providerConfigs),
            'healthy' => $healthy,
            'degraded' => $degraded,
            'unhealthy' => $unhealthy,
            'disabled' => $disabled,
            'providers' => $providers,
        ];
    }

    /**
     * Check if the telemetry service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Probe a specific provider with a lightweight HTTP request.
     *
     * @param  string  $provider
     * @param  array{enabled: bool, measurement_id?: string, api_secret?: string, id?: string, host?: string, url?: string}  $cfg
     * @return array{status: string, latency_ms: int, error?: string}
     */
    private function probeProvider(string $provider, array $cfg): array
    {
        try {
            $start = hrtime(true);
            $url = $this->buildProbeUrl($provider, $cfg);

            if ($url === null) {
                return ['status' => 'unhealthy', 'latency_ms' => 0, 'error' => 'No endpoint URL configured'];
            }

            $client = new \GuzzleHttp\Client(['timeout' => 5]);
            $response = $client->get($url, ['http_errors' => false]);
            $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 400) {
                return ['status' => 'healthy', 'latency_ms' => $elapsed];
            }

            return ['status' => 'unhealthy', 'latency_ms' => $elapsed, 'error' => "HTTP {$statusCode}"];
        } catch (\Throwable $e) {
            $latency = 0;

            return ['status' => 'unhealthy', 'latency_ms' => $latency, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build a probe URL for the given provider.
     *
     * @param  string  $provider
     * @param  array{measurement_id?: string, api_secret?: string, host?: string, id?: string, url?: string}  $cfg
     * @return string|null
     */
    private function buildProbeUrl(string $provider, array $cfg): ?string
    {
        return match ($provider) {
            'ga4' => ($cfg['measurement_id'] && $cfg['api_secret'])
                ? "https://www.google-analytics.com/debug/mp/collect?measurement_id={$cfg['measurement_id']}&api_secret={$cfg['api_secret']}"
                : null,
            'posthog' => ($cfg['host'] && $cfg['id'])
                ? rtrim($cfg['host'], '/')."/api/projects/{$cfg['id']}"
                : null,
            'plausible' => $cfg['url'] ?? null,
            'webhook' => $cfg['url'] ?? null,
            default => null,
        };
    }

    /**
     * Load cached probe results.
     *
     * @return array<string, array{status: string, latency_ms: int, last_check: string, error?: string}>
     */
    private function loadCachedResults(): array
    {
        try {
            $cached = $this->cache->get($this->cachePrefix.':probes');

            return is_array($cached) ? $cached : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Persist probe results to cache.
     *
     * @param  array<string, array{status: string, latency_ms: int, last_check: string, error?: string}>  $results
     */
    private function cacheResults(array $results): void
    {
        try {
            $this->cache->put($this->cachePrefix.':probes', $results, $this->cacheTtl);
        } catch (\Throwable $e) {
            try {
                Log::warning('AnalyticsTelemetryService: failed to cache results', [
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // Log facade unavailable
            }
        }
    }
}
