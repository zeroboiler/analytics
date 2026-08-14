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
 * Production heartbeat monitor for ZeroBoiler Analytics.
 *
 * Maintains a cache-backed health pulse that tracks provider circuit states,
 * queue depth, last successful dispatch, and overall system liveness.
 * Designed for monitoring dashboards, scheduled checks, and alerting.
 *
 * @since 120.0.0
 */
final class AnalyticsHeartbeatMonitor
{
    private const CACHE_PREFIX = 'zb_heartbeat_';

    private const DEFAULT_TTL = 300; // 5 minutes

    private const MAX_HISTORY = 1440; // 24 hours at 1-minute granularity

    private CacheRepository $cache;

    private ConfigRepository $config;

    /** @var array<string, array{state: string, since: int, failures: int}> */
    private array $providerCircuits = [];

    private ?int $lastDispatchAt = null;

    private ?int $lastErrorAt = null;

    private ?string $lastError = null;

    private int $queueDepth = 0;

    private int $eventsProcessedSincePulse = 0;

    private int $eventsFailedSincePulse = 0;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;
        $this->loadState();
    }

    /**
     * Record a pulse — the system is alive and processing.
     *
     * Persists current metrics to cache with TTL. Called by queue workers
     * and dispatch middleware on each processing cycle.
     *
     * @return array{status: string, timestamp: int, providers: int, queue_depth: int, events_processed: int, events_failed: int, uptime: int|null}
     */
    public function pulse(): array
    {
        $now = time();
        $heartbeat = [
            'status' => $this->computeStatus(),
            'timestamp' => $now,
            'providers' => count($this->providerCircuits),
            'providers_healthy' => $this->countHealthyProviders(),
            'providers_degraded' => $this->countDegradedProviders(),
            'providers_down' => $this->countDownProviders(),
            'queue_depth' => $this->queueDepth,
            'events_processed' => $this->eventsProcessedSincePulse,
            'events_failed' => $this->eventsFailedSincePulse,
            'last_dispatch_at' => $this->lastDispatchAt,
            'last_error_at' => $this->lastErrorAt,
            'last_error' => $this->lastError,
            'uptime' => $this->computeUptime(),
        ];

        $ttl = (int) $this->config->get('zeroboiler.analytics.heartbeat.ttl', self::DEFAULT_TTL);
        $this->cache->put(self::CACHE_PREFIX . 'current', $heartbeat, $ttl);

        // Append to history ring buffer
        $this->appendToHistory($heartbeat);

        // Reset per-pulse counters
        $this->eventsProcessedSincePulse = 0;
        $this->eventsFailedSincePulse = 0;

        return $heartbeat;
    }

    /**
     * Get the current heartbeat status.
     *
     * Returns the last recorded pulse or a stale indicator if no pulse has been recorded.
     *
     * @return array{status: string, timestamp: int|null, stale: bool, providers: int|null, queue_depth: int|null}
     */
    public function current(): array
    {
        /** @var array|null $heartbeat */
        $heartbeat = $this->cache->get(self::CACHE_PREFIX . 'current');

        if ($heartbeat === null) {
            return [
                'status' => 'unknown',
                'timestamp' => null,
                'stale' => true,
                'providers' => null,
                'queue_depth' => null,
            ];
        }

        $staleThreshold = (int) $this->config->get('zeroboiler.analytics.heartbeat.stale_threshold', 600);
        $isStale = (time() - ($heartbeat['timestamp'] ?? 0)) > $staleThreshold;

        $heartbeat['stale'] = $isStale;

        return $heartbeat;
    }

    /**
     * Record a successful event dispatch to a provider.
     *
     * @param  string  $provider  Provider name (e.g., 'ga4', 'meta', 'posthog')
     */
    public function recordDispatch(string $provider): void
    {
        $this->lastDispatchAt = time();
        $this->eventsProcessedSincePulse++;
        $this->setProviderState($provider, 'closed');
    }

    /**
     * Record a failed dispatch to a provider.
     *
     * Increments the failure count and transitions the circuit to 'open' if
     * the failure threshold is exceeded.
     *
     * @param  string  $provider  Provider name
     * @param  string  $error  Error message
     */
    public function recordFailure(string $provider, string $error = ''): void
    {
        $this->lastErrorAt = time();
        $this->lastError = $error !== '' ? $error : null;
        $this->eventsFailedSincePulse++;

        $threshold = (int) $this->config->get('zeroboiler.analytics.heartbeat.failure_threshold', 5);

        $current = $this->providerCircuits[$provider] ?? [
            'state' => 'closed',
            'since' => time(),
            'failures' => 0,
        ];

        $current['failures']++;

        if ($current['failures'] >= $threshold) {
            $current['state'] = 'open';
            $current['since'] = time();

            Log::warning("ZeroBoiler heartbeat: provider '{$provider}' circuit opened after {$current['failures']} failures");
        } elseif ($current['failures'] >= max(1, (int) ($threshold * 0.5))) {
            $current['state'] = 'half_open';
        }

        $this->providerCircuits[$provider] = $current;
    }

    /**
     * Manually reset a provider's circuit to closed.
     *
     * @param  string  $provider  Provider name
     */
    public function resetProvider(string $provider): void
    {
        $this->providerCircuits[$provider] = [
            'state' => 'closed',
            'since' => time(),
            'failures' => 0,
        ];
    }

    /**
     * Update the queue depth metric.
     *
     * @param  int  $depth  Number of pending events in the queue
     */
    public function setQueueDepth(int $depth): void
    {
        $this->queueDepth = $depth;
    }

    /**
     * Get the heartbeat history for the last N minutes.
     *
     * @param  int  $minutes  Number of minutes to look back (default: 60)
     * @return list<array{status: string, timestamp: int, queue_depth: int, events_processed: int}>
     */
    public function history(int $minutes = 60): array
    {
        /** @var list<array> $history */
        $history = $this->cache->get(self::CACHE_PREFIX . 'history', []);
        $cutoff = time() - ($minutes * 60);

        return array_values(array_filter(
            $history,
            fn (array $entry): bool => ($entry['timestamp'] ?? 0) >= $cutoff,
        ));
    }

    /**
     * Get aggregate health statistics from heartbeat history.
     *
     * Computes uptime percentage, average queue depth, total events processed,
     * and peak queue depth over the specified window.
     *
     * @param  int  $minutes  Analysis window in minutes (default: 60)
     * @return array{uptime_pct: float, avg_queue_depth: float, peak_queue_depth: int, total_events: int, total_failures: int, samples: int}
     */
    public function aggregateStats(int $minutes = 60): array
    {
        $history = $this->history($minutes);
        $count = count($history);

        if ($count === 0) {
            return [
                'uptime_pct' => 0.0,
                'avg_queue_depth' => 0.0,
                'peak_queue_depth' => 0,
                'total_events' => 0,
                'total_failures' => 0,
                'samples' => 0,
            ];
        }

        $healthy = 0;
        $totalQueue = 0;
        $peakQueue = 0;
        $totalEvents = 0;
        $totalFailures = 0;

        foreach ($history as $entry) {
            if (($entry['status'] ?? '') === 'healthy') {
                $healthy++;
            }

            $qd = (int) ($entry['queue_depth'] ?? 0);
            $totalQueue += $qd;
            $peakQueue = max($peakQueue, $qd);
            $totalEvents += (int) ($entry['events_processed'] ?? 0);
            $totalFailures += (int) ($entry['events_failed'] ?? 0);
        }

        return [
            'uptime_pct' => round(($healthy / $count) * 100, 2),
            'avg_queue_depth' => round($totalQueue / $count, 1),
            'peak_queue_depth' => $peakQueue,
            'total_events' => $totalEvents,
            'total_failures' => $totalFailures,
            'samples' => $count,
        ];
    }

    /**
     * Clear all heartbeat data from cache.
     */
    public function clear(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'current');
        $this->cache->forget(self::CACHE_PREFIX . 'history');
        $this->cache->forget(self::CACHE_PREFIX . 'first_pulse');
        $this->providerCircuits = [];
        $this->lastDispatchAt = null;
        $this->lastErrorAt = null;
        $this->lastError = null;
        $this->queueDepth = 0;
    }

    /**
     * Get the circuit state for all tracked providers.
     *
     * @return array<string, array{state: string, since: int, failures: int}>
     */
    public function providerStates(): array
    {
        return $this->providerCircuits;
    }

    /**
     * Check if the overall system is alive based on heartbeat staleness.
     */
    public function isAlive(): bool
    {
        $current = $this->current();

        return ! ($current['stale'] ?? true);
    }

    /**
     * Compute the overall system status based on all metrics.
     *
     * @return 'healthy'|'degraded'|'down'|'unknown'
     */
    private function computeStatus(): string
    {
        if ($this->countDownProviders() > 0) {
            return $this->countHealthyProviders() > 0 ? 'degraded' : 'down';
        }

        if ($this->countDegradedProviders() > 0) {
            return 'degraded';
        }

        if ($this->eventsFailedSincePulse > 0) {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * Compute uptime since the first recorded pulse.
     *
     * @return int|null  Seconds since first pulse, or null if never pulsed
     */
    private function computeUptime(): ?int
    {
        /** @var int|null $firstPulse */
        $firstPulse = $this->cache->get(self::CACHE_PREFIX . 'first_pulse');

        if ($firstPulse === null) {
            // Record first pulse
            $this->cache->put(self::CACHE_PREFIX . 'first_pulse', time(), 86400);

            return null;
        }

        return time() - $firstPulse;
    }

    /**
     * Count providers with 'closed' circuit state.
     */
    private function countHealthyProviders(): int
    {
        return count(array_filter(
            $this->providerCircuits,
            fn (array $c): bool => ($c['state'] ?? '') === 'closed',
        ));
    }

    /**
     * Count providers with 'half_open' circuit state.
     */
    private function countDegradedProviders(): int
    {
        return count(array_filter(
            $this->providerCircuits,
            fn (array $c): bool => ($c['state'] ?? '') === 'half_open',
        ));
    }

    /**
     * Count providers with 'open' circuit state.
     */
    private function countDownProviders(): int
    {
        return count(array_filter(
            $this->providerCircuits,
            fn (array $c): bool => ($c['state'] ?? '') === 'open',
        ));
    }

    /**
     * Set or update a provider's circuit state.
     *
     * @param  string  $provider
     * @param  'closed'|'half_open'|'open'  $state
     */
    private function setProviderState(string $provider, string $state): void
    {
        $current = $this->providerCircuits[$provider] ?? null;

        $this->providerCircuits[$provider] = [
            'state' => $state,
            'since' => $current !== null ? $current['since'] : time(),
            'failures' => $state === 'closed' ? 0 : ($current['failures'] ?? 0),
        ];
    }

    /**
     * Append a heartbeat entry to the history ring buffer.
     *
     * @param  array  $entry
     */
    private function appendToHistory(array $entry): void
    {
        /** @var list<array> $history */
        $history = $this->cache->get(self::CACHE_PREFIX . 'history', []);
        $history[] = [
            'status' => $entry['status'],
            'timestamp' => $entry['timestamp'],
            'queue_depth' => $entry['queue_depth'],
            'events_processed' => $entry['events_processed'],
            'events_failed' => $entry['events_failed'],
        ];

        // Ring buffer: trim to max history size
        if (count($history) > self::MAX_HISTORY) {
            $history = array_slice($history, -self::MAX_HISTORY);
        }

        $this->cache->put(self::CACHE_PREFIX . 'history', $history, 86400);
    }

    /**
     * Load persisted state from cache.
     */
    private function loadState(): void
    {
        /** @var array|null $state */
        $state = $this->cache->get(self::CACHE_PREFIX . 'state');

        if ($state !== null) {
            $this->providerCircuits = $state['providers'] ?? [];
            $this->lastDispatchAt = $state['last_dispatch_at'] ?? null;
            $this->lastErrorAt = $state['last_error_at'] ?? null;
            $this->lastError = $state['last_error'] ?? null;
            $this->queueDepth = $state['queue_depth'] ?? 0;
        }
    }
}
