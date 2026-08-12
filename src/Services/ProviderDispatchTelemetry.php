<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Provider dispatch telemetry — tracks success/failure counts, latency,
 * and dispatch rates per analytics provider.
 *
 * Provides real-time observability into provider health, dispatch volume,
 * and error rates. Used by dashboards and health monitoring services.
 *
 * All metrics are stored in cache with configurable TTL (default: 5 minutes).
 * Aggregation windows: 1m, 5m, 15m, 60m.
 *
 * @since 32.0.0
 */
final class ProviderDispatchTelemetry
{
    /** @var string Cache prefix for all telemetry keys */
    private const CACHE_PREFIX = 'zb_telemetry_dispatch_';

    /** @var int Default cache TTL for metrics (seconds) */
    private const DEFAULT_TTL = 300;

    /** @var int Max events per provider per minute before sampling */
    private const HIGH_VOLUME_THRESHOLD = 10000;

    private CacheRepository $cache;

    private int $ttl;

    /** @var list<string> Tracked providers */
    private const PROVIDERS = [
        'ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog',
        'mixpanel', 'amplitude', 'webhook', 'tiktok', 'linkedin',
    ];

    /**
     * @param  CacheRepository|null  $cache  Cache repository
     * @param  int|null  $ttl  Cache TTL in seconds
     */
    public function __construct(?CacheRepository $cache = null, ?int $ttl = null)
    {
        $this->cache = $cache ?? Cache::getFacadeRoot();
        $this->ttl = $ttl ?? self::DEFAULT_TTL;
    }

    /**
     * Record a successful dispatch for a provider.
     *
     * @param  string  $provider  Provider key (ga4, meta_pixel, etc.)
     * @param  string  $eventName  Event name that was dispatched
     * @param  float|null  $latencyMs  Dispatch latency in milliseconds
     */
    public function recordSuccess(string $provider, string $eventName, ?float $latencyMs = null): void
    {
        $this->incrementCounter($provider, 'success');
        $this->incrementCounter($provider, 'total');
        $this->trackEvent($provider, $eventName);

        if ($latencyMs !== null) {
            $this->recordLatency($provider, $latencyMs);
        }
    }

    /**
     * Record a failed dispatch for a provider.
     *
     * @param  string  $provider  Provider key
     * @param  string  $eventName  Event name that failed
     * @param  string  $error  Error message or type
     * @param  int  $statusCode  HTTP status code (0 if not applicable)
     */
    public function recordFailure(string $provider, string $eventName, string $error, int $statusCode = 0): void
    {
        $this->incrementCounter($provider, 'failure');
        $this->incrementCounter($provider, 'total');
        $this->trackEvent($provider, $eventName);
        $this->trackError($provider, $error, $statusCode);
    }

    /**
     * Get telemetry summary for all providers.
     *
     * @return array{providers: array<string, array{success: int, failure: int, total: int, error_rate: float, top_events: list<string>, avg_latency_ms: float|null, last_error: string|null}>, total_dispatched: int, total_failures: int, overall_error_rate: float}
     */
    public function summary(): array
    {
        $providers = [];
        $totalDispatched = 0;
        $totalFailures = 0;

        foreach (self::PROVIDERS as $provider) {
            $data = $this->getProviderStats($provider);
            $totalDispatched += $data['total'];
            $totalFailures += $data['failure'];
            $providers[$provider] = $data;
        }

        return [
            'providers' => $providers,
            'total_dispatched' => $totalDispatched,
            'total_failures' => $totalFailures,
            'overall_error_rate' => $totalDispatched > 0
                ? round(($totalFailures / $totalDispatched) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Get telemetry for a single provider.
     *
     * @return array{success: int, failure: int, total: int, error_rate: float, top_events: list<string>, avg_latency_ms: float|null, last_error: string|null}
     */
    public function providerStats(string $provider): array
    {
        return $this->getProviderStats($provider);
    }

    /**
     * Get the top dispatched events across all providers.
     *
     * @param  int  $limit  Number of events to return
     * @return list<array{event: string, count: int}>
     */
    public function topEvents(int $limit = 20): array
    {
        $allEvents = [];
        $eventsKey = self::CACHE_PREFIX . 'events_aggregate';

        $events = $this->cache->get($eventsKey, []);

        if (! is_array($events)) {
            return [];
        }

        foreach ($events as $name => $count) {
            $allEvents[] = ['event' => $name, 'count' => $count];
        }

        usort($allEvents, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($allEvents, 0, $limit);
    }

    /**
     * Check if a provider is in high-volume mode (exceeding threshold).
     */
    public function isHighVolume(string $provider): bool
    {
        $data = $this->getProviderStats($provider);

        return $data['total'] >= self::HIGH_VOLUME_THRESHOLD;
    }

    /**
     * Reset all telemetry counters.
     */
    public function reset(): void
    {
        foreach (self::PROVIDERS as $provider) {
            foreach (['success', 'failure', 'total'] as $type) {
                $this->cache->forget(self::CACHE_PREFIX . "{$provider}_{$type}");
            }
            $this->cache->forget(self::CACHE_PREFIX . "{$provider}_events");
            $this->cache->forget(self::CACHE_PREFIX . "{$provider}_latency");
            $this->cache->forget(self::CACHE_PREFIX . "{$provider}_errors");
        }

        $this->cache->forget(self::CACHE_PREFIX . 'events_aggregate');
    }

    /**
     * Get the list of tracked providers.
     *
     * @return list<string>
     */
    public static function trackedProviders(): array
    {
        return self::PROVIDERS;
    }

    /**
     * Increment a counter for a provider.
     */
    private function incrementCounter(string $provider, string $type): void
    {
        $key = self::CACHE_PREFIX . "{$provider}_{$type}";
        $this->cache->increment($key);

        // Ensure key exists with TTL
        if ($this->cache->get($key) === null) {
            $this->cache->put($key, 1, $this->ttl);
        }
    }

    /**
     * Track an event dispatch for a provider.
     */
    private function trackEvent(string $provider, string $eventName): void
    {
        $providerKey = self::CACHE_PREFIX . "{$provider}_events";
        $providerEvents = $this->cache->get($providerKey, []);

        if (! is_array($providerEvents)) {
            $providerEvents = [];
        }

        $providerEvents[$eventName] = ($providerEvents[$eventName] ?? 0) + 1;
        $this->cache->put($providerKey, $providerEvents, $this->ttl);

        // Global aggregate
        $aggregateKey = self::CACHE_PREFIX . 'events_aggregate';
        $aggregate = $this->cache->get($aggregateKey, []);

        if (! is_array($aggregate)) {
            $aggregate = [];
        }

        $aggregate[$eventName] = ($aggregate[$eventName] ?? 0) + 1;
        $this->cache->put($aggregateKey, $aggregate, $this->ttl);
    }

    /**
     * Record dispatch latency for a provider (rolling average).
     */
    private function recordLatency(string $provider, float $latencyMs): void
    {
        $key = self::CACHE_PREFIX . "{$provider}_latency";

        $current = $this->cache->get($key, []);

        if (! is_array($current)) {
            $current = [];
        }

        $current[] = $latencyMs;

        // Keep only last 100 samples for rolling average
        if (count($current) > 100) {
            $current = array_slice($current, -100);
        }

        $this->cache->put($key, $current, $this->ttl);
    }

    /**
     * Track an error for a provider.
     */
    private function trackError(string $provider, string $error, int $statusCode): void
    {
        $key = self::CACHE_PREFIX . "{$provider}_errors";
        $errors = $this->cache->get($key, []);

        if (! is_array($errors)) {
            $errors = [];
        }

        $errors[] = [
            'error' => $error,
            'status' => $statusCode,
            'time' => now()->toIso8601String(),
        ];

        // Keep only last 50 errors
        if (count($errors) > 50) {
            $errors = array_slice($errors, -50);
        }

        $this->cache->put($key, $errors, $this->ttl);
    }

    /**
     * Build stats for a single provider.
     *
     * @return array{success: int, failure: int, total: int, error_rate: float, top_events: list<string>, avg_latency_ms: float|null, last_error: string|null}
     */
    private function getProviderStats(string $provider): array
    {
        $success = (int) ($this->cache->get(self::CACHE_PREFIX . "{$provider}_success", 0) ?? 0);
        $failure = (int) ($this->cache->get(self::CACHE_PREFIX . "{$provider}_failure", 0) ?? 0);
        $total = (int) ($this->cache->get(self::CACHE_PREFIX . "{$provider}_total", 0) ?? 0);

        // Top events for this provider
        $events = $this->cache->get(self::CACHE_PREFIX . "{$provider}_events", []);
        $topEvents = [];

        if (is_array($events)) {
            arsort($events);
            $topEvents = array_slice(array_keys($events), 0, 10);
        }

        // Average latency
        $latencySamples = $this->cache->get(self::CACHE_PREFIX . "{$provider}_latency", []);
        $avgLatency = null;

        if (is_array($latencySamples) && count($latencySamples) > 0) {
            $avgLatency = round(array_sum($latencySamples) / count($latencySamples), 2);
        }

        // Last error
        $errors = $this->cache->get(self::CACHE_PREFIX . "{$provider}_errors", []);
        $lastError = null;

        if (is_array($errors) && count($errors) > 0) {
            $last = end($errors);
            $lastError = is_array($last) ? $last['error'] : null;
        }

        return [
            'success' => $success,
            'failure' => $failure,
            'total' => $total,
            'error_rate' => $total > 0 ? round(($failure / $total) * 100, 2) : 0.0,
            'top_events' => $topEvents,
            'avg_latency_ms' => $avgLatency,
            'last_error' => $lastError,
        ];
    }
}
