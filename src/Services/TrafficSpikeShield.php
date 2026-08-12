<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Traffic spike shield — adaptive event throttling during traffic bursts.
 *
 * Detects sudden traffic spikes using a sliding window algorithm and
 * automatically applies throttling to prevent overwhelming analytics
 * providers, queues, and downstream systems.
 *
 * Features:
 *   - Sliding window rate detection (events per second)
 *   - Adaptive throttling with configurable thresholds
 *   - Per-event-name and per-category throttling rules
 *   - Priority-aware: critical events are never throttled
 *   - Burst detection with cooldown periods
 *   - Metrics tracking (events accepted, throttled, burst count)
 *   - Auto-recovery when traffic normalizes
 *
 * @since 43.0.0
 */
final class TrafficSpikeShield
{
    /** @var int Default normal threshold (events per window) */
    private const DEFAULT_NORMAL_THRESHOLD = 1000;

    /** @var int Default spike threshold (events per window) */
    private const DEFAULT_SPIKE_THRESHOLD = 5000;

    /** @var int Default window size in seconds */
    private const DEFAULT_WINDOW_SIZE = 60;

    /** @var int Default cooldown period after a spike (seconds) */
    private const DEFAULT_COOLDOWN = 30;

    private const CACHE_PREFIX = 'zb_spike_shield_';

    private const METRICS_KEY = 'zb_spike_shield_metrics';

    private CacheRepository $cache;

    private int $normalThreshold;

    private int $spikeThreshold;

    private int $windowSize;

    private int $cooldown;

    private bool $enabled;

    private float $throttleRatio;

    /** @var array<string, int> Per-event name overrides (event_name => max_events_per_window) */
    private array $eventOverrides;

    private int $metricsTtl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  int  $normalThreshold  Normal traffic threshold (events/window)
     * @param  int  $spikeThreshold  Spike detection threshold (events/window)
     * @param  int  $windowSize  Sliding window size in seconds
     * @param  int  $cooldown  Cooldown period after spike detection (seconds)
     * @param  bool  $enabled  Whether the shield is active
     * @param  float  $throttleRatio  Fraction of events to accept during throttle (0.0–1.0)
     * @param  array<string, int>  $eventOverrides  Event-specific overrides
     * @param  int  $metricsTtl  TTL for metrics cache (seconds)
     */
    public function __construct(
        CacheRepository $cache,
        int $normalThreshold = self::DEFAULT_NORMAL_THRESHOLD,
        int $spikeThreshold = self::DEFAULT_SPIKE_THRESHOLD,
        int $windowSize = self::DEFAULT_WINDOW_SIZE,
        int $cooldown = self::DEFAULT_COOLDOWN,
        bool $enabled = true,
        float $throttleRatio = 0.1,
        array $eventOverrides = [],
        int $metricsTtl = 3600,
    ): void {
        $this->cache = $cache;
        $this->normalThreshold = max($normalThreshold, 1);
        $this->spikeThreshold = max($spikeThreshold, $normalThreshold);
        $this->windowSize = max($windowSize, 1);
        $this->cooldown = max($cooldown, 0);
        $this->enabled = $enabled;
        $this->throttleRatio = min(max($throttleRatio, 0.01), 1.0);
        $this->eventOverrides = $eventOverrides;
        $this->metricsTtl = $metricsTtl;
    }

    /**
     * Evaluate whether an event should be allowed through or throttled.
     *
     * Critical events are always allowed. During a spike, only a fraction
     * of events (based on throttleRatio) are allowed through.
     *
     * @param  AnalyticsEvent  $event  The event to evaluate
     * @return bool True if the event should be dispatched
     */
    public function shouldAllow(AnalyticsEvent $event): bool
    {
        if (! $this->enabled) {
            return true;
        }

        // Critical events are never throttled
        if ($event->priority === 'critical') {
            $this->recordEvent($event->name, true);

            return true;
        }

        $currentWindow = $this->getCurrentWindowCount($event->name);
        $threshold = $this->getEffectiveThreshold($event->name);

        // Below normal threshold — always allow
        if ($currentWindow < $this->normalThreshold) {
            $this->recordEvent($event->name, true);

            return true;
        }

        // In spike territory
        if ($currentWindow >= $threshold) {
            $isInCooldown = $this->isInCooldown();

            if ($isInCooldown) {
                // Throttle: probabilistic sampling based on throttleRatio
                $allowed = (mt_rand() / mt_getrandmax()) < $this->throttleRatio;
                $this->recordEvent($event->name, $allowed);

                return $allowed;
            }
        }

        // Between normal and spike — allow but record for monitoring
        $this->recordEvent($event->name, true);

        return true;
    }

    /**
     * Process an event through the shield.
     *
     * Returns the event if allowed, or null if throttled.
     *
     * @param  AnalyticsEvent  $event  The event to process
     * @return AnalyticsEvent|null The event (or null if throttled)
     */
    public function process(AnalyticsEvent $event): ?AnalyticsEvent
    {
        if ($this->shouldAllow($event)) {
            return $event;
        }

        Log::debug('TrafficSpikeShield: throttled event', [
            'event' => $event->name,
            'client_id' => $event->clientId,
            'priority' => $event->priority,
        ]);

        return null;
    }

    /**
     * Check if the system is currently in a cooldown period.
     *
     * @return bool True if in cooldown
     */
    public function isInCooldown(): bool
    {
        return (bool) $this->cache->get(self::CACHE_PREFIX . 'cooldown_active', false);
    }

    /**
     * Manually trigger a cooldown period.
     */
    public function triggerCooldown(): void
    {
        $this->cache->put(self::CACHE_PREFIX . 'cooldown_active', true, $this->cooldown);
        $this->cache->put(self::CACHE_PREFIX . 'cooldown_triggered_at', time(), $this->cooldown);

        $metrics = $this->getRawMetrics();
        $metrics['total_spikes']++;
        $this->cache->put(self::METRICS_KEY, $metrics, $this->metricsTtl);
    }

    /**
     * Manually clear the cooldown period.
     */
    public function clearCooldown(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'cooldown_active');
        $this->cache->forget(self::CACHE_PREFIX . 'cooldown_triggered_at');
    }

    /**
     * Get the current event count within the sliding window for an event name.
     *
     * @param  string  $eventName  Event name
     * @return int Current count
     */
    public function getCurrentWindowCount(string $eventName): int
    {
        return (int) $this->cache->get(
            self::CACHE_PREFIX . 'window_' . $eventName,
            0,
        );
    }

    /**
     * Get the current total event count across all events in the window.
     *
     * @return int Total event count
     */
    public function getTotalWindowCount(): int
    {
        return (int) $this->cache->get(self::CACHE_PREFIX . 'window_total', 0);
    }

    /**
     * Get shield status and metrics.
     *
     * @return array{enabled: bool, in_cooldown: bool, cooldown_remaining: int|null, normal_threshold: int, spike_threshold: int, window_size: int, throttle_ratio: float, total_accepted: int, total_throttled: int, total_spikes: int, current_window_total: int, top_events: array<string, int>}
     */
    public function getStatus(): array
    {
        $metrics = $this->getRawMetrics();
        $inCooldown = $this->isInCooldown();
        $cooldownTriggeredAt = $this->cache->get(self::CACHE_PREFIX . 'cooldown_triggered_at');

        $cooldownRemaining = null;
        if ($inCooldown && is_int($cooldownTriggeredAt)) {
            $elapsed = time() - $cooldownTriggeredAt;
            $cooldownRemaining = max(0, $this->cooldown - $elapsed);
        }

        return [
            'enabled' => $this->enabled,
            'in_cooldown' => $inCooldown,
            'cooldown_remaining' => $cooldownRemaining,
            'normal_threshold' => $this->normalThreshold,
            'spike_threshold' => $this->spikeThreshold,
            'window_size' => $this->windowSize,
            'throttle_ratio' => $this->throttleRatio,
            'total_accepted' => $metrics['total_accepted'] ?? 0,
            'total_throttled' => $metrics['total_throttled'] ?? 0,
            'total_spikes' => $metrics['total_spikes'] ?? 0,
            'current_window_total' => $this->getTotalWindowCount(),
            'top_events' => $metrics['top_events'] ?? [],
        ];
    }

    /**
     * Reset all shield metrics.
     */
    public function resetMetrics(): void
    {
        $this->cache->forget(self::METRICS_KEY);
    }

    /**
     * Record an event decision for metrics tracking.
     *
     * @param  string  $eventName  Event name
     * @param  bool  $allowed  Whether the event was allowed through
     */
    private function recordEvent(string $eventName, bool $allowed): void
    {
        // Increment window counter
        $windowKey = self::CACHE_PREFIX . 'window_' . $eventName;
        $count = (int) $this->cache->get($windowKey, 0);
        $this->cache->put($windowKey, $count + 1, $this->windowSize);

        // Increment total window counter
        $totalKey = self::CACHE_PREFIX . 'window_total';
        $totalCount = (int) $this->cache->get($totalKey, 0);
        $this->cache->put($totalKey, $totalCount + 1, $this->windowSize);

        // Auto-detect spike on total
        if ($totalCount >= $this->spikeThreshold && ! $this->isInCooldown()) {
            $this->triggerCooldown();
        }

        // Track metrics
        $metrics = $this->getRawMetrics();

        if ($allowed) {
            $metrics['total_accepted'] = ($metrics['total_accepted'] ?? 0) + 1;
        } else {
            $metrics['total_throttled'] = ($metrics['total_throttled'] ?? 0) + 1;
        }

        $metrics['top_events'][$eventName] = ($metrics['top_events'][$eventName] ?? 0) + 1;

        $this->cache->put(self::METRICS_KEY, $metrics, $this->metricsTtl);
    }

    /**
     * Get the effective spike threshold for an event name.
     *
     * @param  string  $eventName  Event name
     * @return int Effective threshold
     */
    private function getEffectiveThreshold(string $eventName): int
    {
        return $this->eventOverrides[$eventName] ?? $this->spikeThreshold;
    }

    /**
     * Get raw metrics from cache.
     *
     * @return array{total_accepted: int, total_throttled: int, total_spikes: int, top_events: array<string, int>}
     */
    private function getRawMetrics(): array
    {
        return $this->cache->get(self::METRICS_KEY, [
            'total_accepted' => 0,
            'total_throttled' => 0,
            'total_spikes' => 0,
            'top_events' => [],
        ]);
    }
}
