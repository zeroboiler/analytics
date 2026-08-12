<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\SimpleCache\InvalidArgumentException;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Config-driven event sampling strategy service.
 *
 * Controls which events are dispatched (sampled in) vs dropped (sampled out)
 * using configurable strategies per event name, category, or as a global default.
 *
 * Supports three strategies:
 * - `uniform`: Random sampling (each event independently sampled at the rate)
 * - `deterministic`: Hash-based sampling (same event name always gets same decision)
 * - `adaptive`: Volume-aware sampling (automatically adjusts rate based on throughput)
 *
 * Config: `zeroboiler.analytics.sampling`
 *
 * @since 45.0.0
 */
final class EventSamplingStrategyService
{
    private readonly bool $enabled;

    /** @var array<string, mixed> */
    private readonly array $config;

    /** @var array<string, float> Event-name overrides */
    private readonly array $eventOverrides;

    /** @var array<string, float> Category-level overrides */
    private readonly array $categoryOverrides;

    private readonly float $globalRate;

    private readonly string $strategy;

    private readonly string $cachePrefix;

    private readonly int $metricsTtl;

    private readonly int $adaptiveWindowSeconds;

    private CacheRepository $cache;

    /** @var array<string, int> Volume counters for adaptive strategy */
    private array $volumeCounters = [];

    /**
     * Create a new EventSamplingStrategyService.
     *
     * @param  CacheRepository  $cache  Cache repository for metrics and adaptive counters
     * @param  ConfigRepository  $config  Application config repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $samplingConfig = $config->get('zeroboiler.analytics.sampling', []);
        /** @var array{enabled?: bool, global_rate?: float, strategy?: string, event_overrides?: array<string, float>, category_overrides?: array<string, float>, cache_prefix?: string, metrics_ttl?: int, adaptive_window?: int} $samplingConfig */

        $this->config = $samplingConfig;
        $this->enabled = (bool) ($samplingConfig['enabled'] ?? false);
        $this->globalRate = (float) ($samplingConfig['global_rate'] ?? 1.0);
        $this->strategy = (string) ($samplingConfig['strategy'] ?? 'deterministic');
        $this->eventOverrides = (array) ($samplingConfig['event_overrides'] ?? []);
        $this->categoryOverrides = (array) ($samplingConfig['category_overrides'] ?? []);
        $this->cachePrefix = (string) ($samplingConfig['cache_prefix'] ?? 'zb_sampling_');
        $this->metricsTtl = (int) ($samplingConfig['metrics_ttl'] ?? 3600);
        $this->adaptiveWindowSeconds = (int) ($samplingConfig['adaptive_window'] ?? 60);
    }

    /**
     * Determine whether an event should be sampled (included for dispatch).
     *
     * When sampling is disabled, all events pass through (returns true).
     * Priority overrides: event-specific > category-specific > global rate.
     * Critical events always pass through regardless of sampling rate.
     *
     * @param  AnalyticsEvent  $event  The event to evaluate
     * @return bool True if the event should be dispatched, false if it should be dropped
     */
    public function shouldSample(AnalyticsEvent $event): bool
    {
        if (! $this->enabled) {
            return true;
        }

        // Critical events are never sampled out
        if ($event->priority === 'critical') {
            $this->incrementMetric('critical_passed');

            return true;
        }

        $rate = $this->resolveRate($event);

        // Rate of 1.0 = no sampling (all events pass)
        if ($rate >= 1.0) {
            $this->incrementMetric('passed');

            return true;
        }

        // Rate of 0.0 = all dropped
        if ($rate <= 0.0) {
            $this->incrementMetric('dropped');

            return false;
        }

        $sampled = $this->applyStrategy($event, $rate);

        if ($sampled) {
            $this->incrementMetric('passed');
        } else {
            $this->incrementMetric('dropped');
        }

        $this->incrementMetric('total');

        return $sampled;
    }

    /**
     * Resolve the effective sampling rate for an event.
     *
     * Priority: event-specific override > category override > global rate.
     *
     * @param  AnalyticsEvent  $event  The event to resolve rate for
     * @return float Sampling rate between 0.0 and 1.0
     */
    public function resolveRate(AnalyticsEvent $event): float
    {
        // Event-specific override has highest priority
        if (isset($this->eventOverrides[$event->name])) {
            return max(0.0, min(1.0, (float) $this->eventOverrides[$event->name]));
        }

        // Category-level override
        $category = EventCatalog::getCategory($event->name);
        if ($category !== null && isset($this->categoryOverrides[$category])) {
            return max(0.0, min(1.0, (float) $this->categoryOverrides[$category]));
        }

        return max(0.0, min(1.0, $this->globalRate));
    }

    /**
     * Get the configured sampling strategy name.
     *
     * @return string One of: 'uniform', 'deterministic', 'adaptive'
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Get the global default sampling rate.
     */
    public function getGlobalRate(): float
    {
        return $this->globalRate;
    }

    /**
     * Check if the sampling service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Set the global sampling rate at runtime (for dynamic adjustment).
     *
     * @param  float  $rate  New global rate (0.0-1.0)
     */
    public function setGlobalRate(float $rate): void
    {
        $this->globalRate = max(0.0, min(1.0, $rate));
    }

    /**
     * Set a per-event sampling rate override at runtime.
     *
     * @param  string  $eventName  Event name
     * @param  float  $rate  Sampling rate (0.0-1.0)
     */
    public function setEventRate(string $eventName, float $rate): void
    {
        $this->eventOverrides[$eventName] = max(0.0, min(1.0, $rate));
    }

    /**
     * Remove a per-event sampling rate override.
     */
    public function removeEventRate(string $eventName): void
    {
        unset($this->eventOverrides[$eventName]);
    }

    /**
     * Set a per-category sampling rate override at runtime.
     *
     * @param  string  $category  Event category (ecommerce, saas, engagement, security, uptime, infrastructure)
     * @param  float  $rate  Sampling rate (0.0-1.0)
     */
    public function setCategoryRate(string $category, float $rate): void
    {
        $this->categoryOverrides[$category] = max(0.0, min(1.0, $rate));
    }

    /**
     * Get all event-specific overrides.
     *
     * @return array<string, float>
     */
    public function getEventOverrides(): array
    {
        return $this->eventOverrides;
    }

    /**
     * Get all category-level overrides.
     *
     * @return array<string, float>
     */
    public function getCategoryOverrides(): array
    {
        return $this->categoryOverrides;
    }

    /**
     * Get sampling metrics.
     *
     * @return array{passed: int, dropped: int, total: int, critical_passed: int, rate: float, strategy: string}
     */
    public function getMetrics(): array
    {
        return [
            'passed' => $this->getMetric('passed'),
            'dropped' => $this->getMetric('dropped'),
            'total' => $this->getMetric('total'),
            'critical_passed' => $this->getMetric('critical_passed'),
            'rate' => $this->globalRate,
            'strategy' => $this->strategy,
        ];
    }

    /**
     * Get a summary of the sampling configuration.
     *
     * @return array{enabled: bool, strategy: string, global_rate: float, event_overrides_count: int, category_overrides_count: int, effective_rates: array<string, float>}
     */
    public function summary(): array
    {
        $effectiveRates = [];
        foreach ($this->eventOverrides as $event => $rate) {
            $effectiveRates[$event] = $rate;
        }
        foreach ($this->categoryOverrides as $category => $rate) {
            $effectiveRates["category:{$category}"] = $rate;
        }

        return [
            'enabled' => $this->enabled,
            'strategy' => $this->strategy,
            'global_rate' => $this->globalRate,
            'event_overrides_count' => count($this->eventOverrides),
            'category_overrides_count' => count($this->categoryOverrides),
            'effective_rates' => $effectiveRates,
        ];
    }

    /**
     * Reset all metrics counters.
     */
    public function resetMetrics(): void
    {
        $keys = ['passed', 'dropped', 'total', 'critical_passed'];

        foreach ($keys as $key) {
            try {
                $this->cache->forget($this->cachePrefix . 'metrics_' . $key);
            } catch (InvalidArgumentException) {
                // Ignore cache errors
            }
        }
    }

    /**
     * Apply the configured sampling strategy to determine if an event is sampled in.
     *
     * @param  AnalyticsEvent  $event  The event to evaluate
     * @param  float  $rate  The resolved sampling rate
     * @return bool True if the event should be dispatched
     */
    private function applyStrategy(AnalyticsEvent $event, float $rate): bool
    {
        return match ($this->strategy) {
            'uniform' => $this->uniformSample($rate),
            'deterministic' => $this->deterministicSample($event, $rate),
            'adaptive' => $this->adaptiveSample($event, $rate),
            default => true, // Unknown strategy = pass through
        };
    }

    /**
     * Uniform (random) sampling strategy.
     * Each event independently sampled at the given rate.
     */
    private function uniformSample(float $rate): bool
    {
        return (mt_rand() / mt_getrandmax()) <= $rate;
    }

    /**
     * Deterministic (hash-based) sampling strategy.
     * Same event name always gets the same sampling decision.
     */
    private function deterministicSample(AnalyticsEvent $event, float $rate): bool
    {
        $hash = crc32($event->name) & 0xFFFFFFFF;
        $bucket = (int) ($hash / 4_294_967_296.0);

        return ($bucket % 100) < (int) ($rate * 100);
    }

    /**
     * Adaptive sampling strategy.
     * Adjusts sampling rate based on recent event volume.
     * If volume exceeds a threshold, reduces the effective rate to prevent overwhelming providers.
     */
    private function adaptiveSample(AnalyticsEvent $event, float $rate): bool
    {
        // Track volume per event name
        $counterKey = $event->name;
        $this->volumeCounters[$counterKey] = ($this->volumeCounters[$counterKey] ?? 0) + 1;

        $volume = $this->volumeCounters[$counterKey];

        // Adaptive threshold: reduce rate when volume is high
        // If volume > 100 events in the window, scale down proportionally
        $adaptiveThreshold = 100;
        if ($volume > $adaptiveThreshold) {
            $adjustedRate = $rate * ($adaptiveThreshold / $volume);
            $effectiveRate = max(0.01, min(1.0, $adjustedRate));

            return $this->uniformSample($effectiveRate);
        }

        return $this->uniformSample($rate);
    }

    /**
     * Reset adaptive volume counters.
     */
    public function resetAdaptiveCounters(): void
    {
        $this->volumeCounters = [];
    }

    /**
     * Get current adaptive volume counters.
     *
     * @return array<string, int>
     */
    public function getAdaptiveCounters(): array
    {
        return $this->volumeCounters;
    }

    /**
     * Increment a named metric counter.
     */
    private function incrementMetric(string $key): void
    {
        try {
            $cacheKey = $this->cachePrefix . 'metrics_' . $key;
            $current = (int) ($this->cache->get($cacheKey, 0));
            $this->cache->put($cacheKey, $current + 1, $this->metricsTtl);
        } catch (InvalidArgumentException) {
            // Ignore cache errors
        }
    }

    /**
     * Get a named metric counter value.
     */
    private function getMetric(string $key): int
    {
        try {
            return (int) ($this->cache->get($this->cachePrefix . 'metrics_' . $key, 0));
        } catch (InvalidArgumentException) {
            return 0;
        }
    }
}
