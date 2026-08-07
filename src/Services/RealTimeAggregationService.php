<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Real-time analytics aggregation service for live dashboards.
 *
 * Maintains rolling counters for events, users, and providers with
 * configurable time windows. Designed for WebSocket broadcast integration
 * via the EventBroadcasterService.
 *
 * Uses the cache driver for cross-process state sharing — works with
 * Redis, Memcached, or database cache backends.
 *
 * Configuration: `zeroboiler.analytics.realtime`
 */
final class RealTimeAggregationService
{
    private const CACHE_PREFIX = 'zb_analytics_realtime_';

    private const CACHE_TTL = 120; // 2 minutes rolling window

    private AnalyticsMetrics $metrics;

    private CacheRepository $cache;

    private bool $enabled;

    private int $windowSeconds;

    private int $topEventsLimit;

    public function __construct(
        AnalyticsMetrics $metrics,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->metrics = $metrics;
        $this->cache = $cache;

        $rtConfig = $config->get('zeroboiler.analytics.realtime', []);
        /** @var array{enabled?: bool, window_seconds?: int, top_events_limit?: int} $rtConfig */
        $this->enabled = (bool) ($rtConfig['enabled'] ?? true);
        $this->windowSeconds = (int) ($rtConfig['window_seconds'] ?? 120);
        $this->topEventsLimit = (int) ($rtConfig['top_events_limit'] ?? 20);
    }

    /**
     * Record an event in the real-time aggregation.
     *
     * Should be called after every tracked event for live dashboard updates.
     */
    public function record(AnalyticsEvent $event): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            $key = self::CACHE_PREFIX . 'events';
            $counters = $this->cache->get($key, []);
            /** @var array<string, int> $counters */

            $counters[$event->name] = ($counters[$event->name] ?? 0) + 1;
            $counters['__total__'] = ($counters['__total__'] ?? 0) + 1;

            $this->cache->put($key, $counters, $this->windowSeconds);

            // Track unique users
            $this->trackUniqueUser($event);

            // Track provider dispatches
            $this->trackProviderEvent($event);
        } catch (\Throwable $e) {
            Log::debug('RealTimeAggregationService: failed to record event', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the current real-time event counters.
     *
     * Returns event name → count mapping for the rolling window,
     * plus total count and unique user count.
     *
     * @return array{events: array<string, int>, total: int, unique_users: int, providers: array<string, int>, period_seconds: int}
     */
    public function snapshot(): array
    {
        $events = $this->cache->get(self::CACHE_PREFIX . 'events', []);
        $users = $this->cache->get(self::CACHE_PREFIX . 'users', []);
        $providers = $this->cache->get(self::CACHE_PREFIX . 'providers', []);

        /** @var array<string, int> $events */
        /** @var array<string, int> $users */
        /** @var array<string, int> $providers */

        // Remove internal counter keys
        $total = $events['__total__'] ?? 0;
        unset($events['__total__']);

        // Sort by count descending
        arsort($events);

        return [
            'events' => array_slice($events, 0, $this->topEventsLimit),
            'total' => $total,
            'unique_users' => count($users),
            'providers' => $providers,
            'period_seconds' => $this->windowSeconds,
        ];
    }

    /**
     * Get the top N events by real-time count.
     *
     * @return list<array{name: string, count: int}>
     */
    public function topEvents(int $limit = 10): array
    {
        $snapshot = $this->snapshot();
        $result = [];

        foreach ($snapshot['events'] as $name => $count) {
            $result[] = ['name' => $name, 'count' => $count];

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Get events per second rate for the current window.
     */
    public function eventsPerSecond(): float
    {
        $total = $this->snapshot()['total'];

        return $this->windowSeconds > 0
            ? round($total / $this->windowSeconds, 2)
            : 0.0;
    }

    /**
     * Get a summary for dashboard display.
     *
     * @return array{enabled: bool, total_events: int, unique_users: int, events_per_second: float, top_events: list<array{name: string, count: int}>, providers: array<string, int>, window_seconds: int}
     */
    public function summary(): array
    {
        $snapshot = $this->snapshot();

        return [
            'enabled' => $this->enabled,
            'total_events' => $snapshot['total'],
            'unique_users' => $snapshot['unique_users'],
            'events_per_second' => $this->eventsPerSecond(),
            'top_events' => $this->topEvents(min($this->topEventsLimit, 10)),
            'providers' => $snapshot['providers'],
            'window_seconds' => $this->windowSeconds,
        ];
    }

    /**
     * Check if real-time aggregation is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Clear all real-time aggregation data.
     */
    public function clear(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'events');
        $this->cache->forget(self::CACHE_PREFIX . 'users');
        $this->cache->forget(self::CACHE_PREFIX . 'providers');
    }

    /**
     * Track a unique user in the rolling window.
     */
    private function trackUniqueUser(AnalyticsEvent $event): void
    {
        $userId = $event->userId ?? $event->clientId;

        if ($userId === null || $userId === '') {
            return;
        }

        $key = self::CACHE_PREFIX . 'users';
        $users = $this->cache->get($key, []);
        /** @var array<string, int> $users */
        $users[$userId] = time();
        $this->cache->put($key, $users, $this->windowSeconds);
    }

    /**
     * Track provider-level event dispatches.
     */
    private function trackProviderEvent(AnalyticsEvent $event): void
    {
        $key = self::CACHE_PREFIX . 'providers';
        $providers = $this->cache->get($key, []);
        /** @var array<string, int> $providers */

        $category = $event->params['__category__'] ?? 'unknown';
        if (is_string($category)) {
            $providers[$category] = ($providers[$category] ?? 0) + 1;
        }

        $this->cache->put($key, $providers, $this->windowSeconds);
    }
}
