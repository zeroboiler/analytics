<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Event Transport Layer — Abstract HTTP transport with configurable retry,
 * timeout, circuit breaker, and connection pooling for analytics dispatch.
 *
 * Wraps HTTP client calls to analytics provider endpoints with production-grade
 * reliability features. Inspired by Segment's transport layer, RudderStack's
 * batching transport, and the circuit breaker pattern from Michael Nygard.
 *
 * Each provider can have its own transport configuration. The service tracks
 * per-provider circuit state, consecutive failure counts, and latency histograms.
 *
 * Configuration: `zeroboiler.analytics.transport`
 *
 * @see \ZeroBoiler\Analytics\Services\ProviderCircuitBreaker
 * @see \ZeroBoiler\Analytics\Services\ProviderFallbackService
 *
 * @since 20.0.0
 */
final class EventTransportService
{
    /** @var string Cache key prefix for circuit state */
    private const CACHE_PREFIX = 'zb_transport_';

    /** Circuit states */
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    private CacheRepository $cache;

    private bool $enabled;

    private int $defaultTimeout;

    private int $defaultRetries;

    private float $circuitThreshold;

    private int $circuitResetTimeout;

    private int $circuitHalfOpenMax;

    private int $metricsTtl;

    /** @var array<string, int> Consecutive failure count per provider */
    private array $failureCount = [];

    /** @var array<string, string> Current circuit state per provider */
    private array $circuitState = [];

    /** @var array<string, list<float>> Latency samples per provider (ms) */
    private array $latencySamples = [];

    /** @var int Maximum latency samples retained per provider */
    private const MAX_LATENCY_SAMPLES = 500;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Config repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $transportConfig = $config->get('zeroboiler.analytics.transport', []);
        /** @var array{enabled?: bool, default_timeout?: int, default_retries?: int, circuit_threshold?: float, circuit_reset_timeout?: int, circuit_half_open_max?: int, metrics_ttl?: int} $transportConfig */

        $this->enabled = (bool) ($transportConfig['enabled'] ?? true);
        $this->defaultTimeout = (int) ($transportConfig['default_timeout'] ?? 5);
        $this->defaultRetries = (int) ($transportConfig['default_retries'] ?? 2);
        $this->circuitThreshold = (float) ($transportConfig['circuit_threshold'] ?? 5.0);
        $this->circuitResetTimeout = (int) ($transportConfig['circuit_reset_timeout'] ?? 60);
        $this->circuitHalfOpenMax = (int) ($transportConfig['circuit_half_open_max'] ?? 3);
        $this->metricsTtl = (int) ($transportConfig['metrics_ttl'] ?? 300);
    }

    /**
     * Check if the transport layer is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if a provider's circuit breaker allows dispatch.
     *
     * @param  string  $provider  Provider name (e.g. 'ga4', 'meta', 'posthog')
     */
    public function canDispatch(string $provider): bool
    {
        $state = $this->getCircuitState($provider);

        return $state !== self::STATE_OPEN;
    }

    /**
     * Record a successful dispatch for a provider.
     *
     * Resets the failure counter and transitions circuit to closed.
     *
     * @param  string  $provider  Provider name
     * @param  float  $latencyMs  Dispatch latency in milliseconds
     */
    public function recordSuccess(string $provider, float $latencyMs): void
    {
        $this->failureCount[$provider] = 0;
        $this->circuitState[$provider] = self::STATE_CLOSED;
        $this->addLatencySample($provider, $latencyMs);

        $this->persistState($provider);
    }

    /**
     * Record a failed dispatch for a provider.
     *
     * Increments the failure counter and may open the circuit breaker
     * if the threshold is exceeded.
     *
     * @param  string  $provider  Provider name
     * @param  string  $error  Error message
     */
    public function recordFailure(string $provider, string $error = ''): void
    {
        $this->failureCount[$provider] = ($this->failureCount[$provider] ?? 0) + 1;

        if ($this->failureCount[$provider] >= $this->circuitThreshold) {
            $this->circuitState[$provider] = self::STATE_OPEN;
            Log::warning("ZeroBoiler Analytics: Circuit breaker OPEN for provider '{$provider}'", [
                'failures' => $this->failureCount[$provider],
                'threshold' => $this->circuitThreshold,
                'error' => $error,
            ]);
        }

        $this->persistState($provider);
    }

    /**
     * Get the current circuit breaker state for a provider.
     *
     * @param  string  $provider  Provider name
     * @return string One of: closed, open, half_open
     */
    public function getCircuitState(string $provider): string
    {
        if (isset($this->circuitState[$provider])) {
            // Check if an open circuit should transition to half-open
            if ($this->circuitState[$provider] === self::STATE_OPEN) {
                $cachedState = $this->cache->get(self::CACHE_PREFIX . 'circuit_' . $provider);
                $lastOpenTime = is_int($cachedState) ? $cachedState : 0;

                if ($lastOpenTime > 0 && (time() - $lastOpenTime) >= $this->circuitResetTimeout) {
                    $this->circuitState[$provider] = self::STATE_HALF_OPEN;
                }
            }

            return $this->circuitState[$provider];
        }

        // Restore from cache
        $cachedState = $this->cache->get(self::CACHE_PREFIX . 'circuit_' . $provider);

        if (is_string($cachedState) && in_array($cachedState, [self::STATE_CLOSED, self::STATE_OPEN, self::STATE_HALF_OPEN], true)) {
            $this->circuitState[$provider] = $cachedState;

            return $cachedState;
        }

        $this->circuitState[$provider] = self::STATE_CLOSED;

        return self::STATE_CLOSED;
    }

    /**
     * Get transport configuration for a specific provider.
     *
     * Returns provider-specific overrides merged with defaults.
     *
     * @param  string  $provider  Provider name
     * @return array{timeout: int, retries: int, retry_delay: int, retry_backoff: float}
     */
    public function getProviderConfig(string $provider): array
    {
        $defaults = [
            'timeout' => $this->defaultTimeout,
            'retries' => $this->defaultRetries,
            'retry_delay' => 500, // ms
            'retry_backoff' => 2.0, // multiplier
        ];

        $overrides = $this->getProviderOverrides($provider);

        return array_merge($defaults, $overrides);
    }

    /**
     * Get the half-open max probe count.
     */
    public function getHalfOpenMax(): int
    {
        return $this->circuitHalfOpenMax;
    }

    /**
     * Get consecutive failure count for a provider.
     */
    public function getFailureCount(string $provider): int
    {
        return $this->failureCount[$provider] ?? 0;
    }

    /**
     * Get latency statistics for a provider.
     *
     * @param  string  $provider  Provider name
     * @return array{count: int, min: float|null, max: float|null, avg: float|null, p50: float|null, p95: float|null, p99: float|null}
     */
    public function getLatencyStats(string $provider): array
    {
        $samples = $this->getLatencySamples($provider);
        $count = count($samples);

        if ($count === 0) {
            return [
                'count' => 0,
                'min' => null,
                'max' => null,
                'avg' => null,
                'p50' => null,
                'p95' => null,
                'p99' => null,
            ];
        }

        sort($samples);

        return [
            'count' => $count,
            'min' => $samples[0],
            'max' => $samples[$count - 1],
            'avg' => round(array_sum($samples) / $count, 2),
            'p50' => round($samples[(int) ($count * 0.50)], 2),
            'p95' => round($samples[(int) ($count * 0.95)], 2),
            'p99' => round($samples[(int) ($count * 0.99)], 2),
        ];
    }

    /**
     * Reset the circuit breaker for a provider.
     *
     * @param  string  $provider  Provider name
     */
    public function resetCircuit(string $provider): void
    {
        $this->failureCount[$provider] = 0;
        $this->circuitState[$provider] = self::STATE_CLOSED;
        $this->latencySamples[$provider] = [];

        $this->cache->forget(self::CACHE_PREFIX . 'circuit_' . $provider);
        $this->cache->forget(self::CACHE_PREFIX . 'failures_' . $provider);
        $this->cache->forget(self::CACHE_PREFIX . 'latency_' . $provider);
    }

    /**
     * Get transport status summary for all providers.
     *
     * @return array<string, array{state: string, failures: int, latency: array{count: int, avg: float|null, p95: float|null}}>
     */
    public function getStatusSummary(array $providers): array
    {
        $summary = [];

        foreach ($providers as $provider) {
            $summary[$provider] = [
                'state' => $this->getCircuitState($provider),
                'failures' => $this->getFailureCount($provider),
                'latency' => [
                    'count' => count($this->getLatencySamples($provider)),
                    'avg' => $this->getLatencyStats($provider)['avg'],
                    'p95' => $this->getLatencyStats($provider)['p95'],
                ],
            ];
        }

        return $summary;
    }

    /**
     * Check if a half-open circuit probe is available.
     */
    public function canProbe(string $provider): bool
    {
        if ($this->getCircuitState($provider) !== self::STATE_HALF_OPEN) {
            return false;
        }

        // In half-open, allow up to halfOpenMax probes
        $probeKey = self::CACHE_PREFIX . 'probes_' . $provider;
        $probes = (int) $this->cache->get($probeKey, 0);

        return $probes < $this->circuitHalfOpenMax;
    }

    /**
     * Record a half-open probe attempt.
     */
    public function recordProbe(string $provider): void
    {
        $probeKey = self::CACHE_PREFIX . 'probes_' . $provider;
        $probes = (int) $this->cache->get($probeKey, 0);
        $this->cache->put($probeKey, $probes + 1, $this->circuitResetTimeout);
    }

    /**
     * Add a latency sample for a provider.
     */
    private function addLatencySample(string $provider, float $latencyMs): void
    {
        if (! isset($this->latencySamples[$provider])) {
            $this->latencySamples[$provider] = $this->loadLatencySamples($provider);
        }

        $this->latencySamples[$provider][] = $latencyMs;

        if (count($this->latencySamples[$provider]) > self::MAX_LATENCY_SAMPLES) {
            array_shift($this->latencySamples[$provider]);
        }

        $this->cache->put(
            self::CACHE_PREFIX . 'latency_' . $provider,
            array_slice($this->latencySamples[$provider], -200),
            $this->metricsTtl,
        );
    }

    /**
     * Get latency samples for a provider.
     *
     * @return list<float>
     */
    private function getLatencySamples(string $provider): array
    {
        if (isset($this->latencySamples[$provider])) {
            return $this->latencySamples[$provider];
        }

        $this->latencySamples[$provider] = $this->loadLatencySamples($provider);

        return $this->latencySamples[$provider];
    }

    /**
     * Load latency samples from cache.
     *
     * @return list<float>
     */
    private function loadLatencySamples(string $provider): array
    {
        $cached = $this->cache->get(self::CACHE_PREFIX . 'latency_' . $provider);

        if (is_array($cached)) {
            return array_values(array_filter($cached, 'is_numeric'));
        }

        return [];
    }

    /**
     * Persist circuit state and failure count to cache.
     */
    private function persistState(string $provider): void
    {
        $this->cache->put(
            self::CACHE_PREFIX . 'circuit_' . $provider,
            $this->circuitState[$provider],
            $this->circuitResetTimeout * 2,
        );

        $this->cache->put(
            self::CACHE_PREFIX . 'failures_' . $provider,
            $this->failureCount[$provider],
            $this->circuitResetTimeout * 2,
        );
    }

    /**
     * Get provider-specific overrides from the transport config.
     *
     * @return array{timeout?: int, retries?: int, retry_delay?: int, retry_backoff?: float}
     */
    private function getProviderOverrides(string $provider): array
    {
        // Provider overrides are stored in the transport config
        // This method can be extended to read from a 'providers' sub-array
        return [];
    }
}
