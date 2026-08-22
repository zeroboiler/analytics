<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event Anonymization Aggregation Service.
 *
 * Provides k-anonymity-safe event aggregation for GDPR-compliant dashboards.
 * Aggregates events into anonymized buckets that meet a minimum group size
 * threshold (k) before exposing aggregate statistics, preventing individual
 * user identification from sparse data.
 *
 * Use cases:
 * - Admin dashboards showing per-feature usage stats
 * - Compliance reports that cannot expose individual user behavior
 * - Public-facing analytics (e.g., "X users completed onboarding this week")
 * - Data sharing with third parties under GDPR Article 89 safeguards
 *
 * Aggregation levels:
 * - event: Per-event-name totals
 * - category: Per-category (ecommerce, saas, engagement) totals
 * - hourly: Per-hour time-bucketed counts
 * - daily: Per-day time-bucketed counts
 *
 * Privacy guarantees:
 * - Groups with fewer than k events are suppressed (reported as null)
 * - Optional noise injection (Laplace mechanism) for differential privacy
 * - Timestamps are rounded to configurable granularity
 *
 * Configuration: `zeroboiler.analytics.anonymized_aggregation`
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsAnonymizationService
 * @see \ZeroBoiler\Analytics\Services\DataMinimizationService
 *
 * @since 1.0.0
 */
final class EventAnonymizationAggregationService
{
    private const CACHE_PREFIX = 'zb_anon_agg_';

    private bool $enabled;

    private CacheRepository $cache;

    private AnalyticsMetrics $metrics;

    /** @var int Minimum group size for k-anonymity */
    private int $kThreshold;

    /** @var int Cache TTL for aggregated results in seconds */
    private int $cacheTtl;

    /** @var bool Whether to inject Laplace noise for differential privacy */
    private bool $laplaceNoise;

    /** @var float Laplace noise scale (epsilon = 1/scale) */
    private float $noiseScale;

    /** @var string Timestamp rounding granularity */
    private string $timeGranularity;

    /** @var int Maximum age of source events in seconds */
    private int $maxEventAge;

    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        AnalyticsMetrics $metrics,
    ){
        $this->cache = $cache;
        $this->metrics = $metrics;

        $aggConfig = $config->get('zeroboiler.analytics.anonymized_aggregation', []);
        /** @var array{enabled?: bool, k_threshold?: int, cache_ttl?: int, laplace_noise?: bool, noise_scale?: float, time_granularity?: string, max_event_age?: int} $aggConfig */
        $this->enabled = (bool) ($aggConfig['enabled'] ?? true);
        $this->kThreshold = (int) ($aggConfig['k_threshold'] ?? 5);
        $this->cacheTtl = (int) ($aggConfig['cache_ttl'] ?? 3600);
        $this->laplaceNoise = (bool) ($aggConfig['laplace_noise'] ?? false);
        $this->noiseScale = (float) ($aggConfig['noise_scale'] ?? 1.0);
        $this->timeGranularity = (string) ($aggConfig['time_granularity'] ?? 'hour');
        $this->maxEventAge = (int) ($aggConfig['max_event_age'] ?? 86400);
    }

    /**
     * Aggregate event counts by event name with k-anonymity.
     *
     * Groups with fewer than k events are suppressed.
     *
     * @return array{aggregated: array<string, int|null>, suppressed: list<string>, total_events: int, k_threshold: int}
     */
    public function aggregateByEvent(): array
    {
        $eventCounts = $this->metrics->getEventCounts();
        $aggregated = [];
        $suppressed = [];
        $totalEvents = 0;

        foreach ($eventCounts as $eventName => $count) {
            $totalEvents += $count;

            if ($count < $this->kThreshold) {
                $aggregated[$eventName] = null;
                $suppressed[] = $eventName;
            } else {
                $value = $this->applyNoise($count);
                $aggregated[$eventName] = (int) round($value);
            }
        }

        return [
            'aggregated' => $aggregated,
            'suppressed' => $suppressed,
            'total_events' => $totalEvents,
            'k_threshold' => $this->kThreshold,
        ];
    }

    /**
     * Aggregate event counts by category (ecommerce, saas, engagement).
     *
     * Category totals are generally safe from k-anonymity concerns since
     * they aggregate across many event types, but we still enforce the
     * threshold for consistency.
     *
     * @return array{aggregated: array<string, int|null>, categories: list<string>, k_threshold: int}
     */
    public function aggregateByCategory(): array
    {
        $categoryCounts = $this->metrics->getCategoryCounts();
        $aggregated = [];

        foreach ($categoryCounts as $category => $count) {
            if ($count < $this->kThreshold) {
                $aggregated[$category] = null;
            } else {
                $aggregated[$category] = (int) round($this->applyNoise($count));
            }
        }

        return [
            'aggregated' => $aggregated,
            'categories' => array_keys($categoryCounts),
            'k_threshold' => $this->kThreshold,
        ];
    }

    /**
     * Aggregate event counts into time buckets with k-anonymity.
     *
     * Buckets with fewer than k events are suppressed.
     * Timestamp granularity is configurable (hour, day).
     *
     * @param  string  $granularity  'hour' or 'day'
     * @param  int  $limit  Maximum number of buckets to return
     * @return array{buckets: list<array{period: string, count: int|null, suppressed: bool}>, granularity: string, k_threshold: int, total_suppressed: int}
     */
    public function aggregateByTime(string $granularity = 'hour', int $limit = 24): array
    {
        $buckets = $this->buildTimeBuckets($granularity, $limit);
        $result = [];
        $totalSuppressed = 0;

        foreach ($buckets as $period => $count) {
            $isSuppressed = $count < $this->kThreshold;
            if ($isSuppressed) {
                $totalSuppressed++;
            }

            $result[] = [
                'period' => $period,
                'count' => $isSuppressed ? null : (int) round($this->applyNoise($count)),
                'suppressed' => $isSuppressed,
            ];
        }

        return [
            'buckets' => $result,
            'granularity' => $granularity,
            'k_threshold' => $this->kThreshold,
            'total_suppressed' => $totalSuppressed,
        ];
    }

    /**
     * Build a privacy-safe summary for dashboard display.
     *
     * Combines event, category, and time aggregations into a single
     * GDPR-compliant summary object suitable for public dashboards.
     *
     * @return array{events: array<string, int|null>, categories: array<string, int|null>, hourly: list<array{period: string, count: int|null}>, total_dispatched: int, k_threshold: int, noise_applied: bool}
     */
    public function dashboardSummary(): array
    {
        $byEvent = $this->aggregateByEvent();
        $byCategory = $this->aggregateByCategory();
        $byTime = $this->aggregateByTime($this->timeGranularity, 24);

        return [
            'events' => $byEvent['aggregated'],
            'categories' => $byCategory['aggregated'],
            'hourly' => array_map(
                fn (array $b): array => ['period' => $b['period'], 'count' => $b['count']],
                $byTime['buckets'],
            ),
            'total_dispatched' => $byEvent['total_events'],
            'k_threshold' => $this->kThreshold,
            'noise_applied' => $this->laplaceNoise,
        ];
    }

    /**
     * Record an event for aggregation tracking.
     *
     * Called by the event pipeline when anonymized aggregation is enabled.
     * Increments per-event and per-time-bucket counters.
     */
    public function record(AnalyticsEvent $event): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            $key = self::CACHE_PREFIX . 'event:' . $event->name;
            $count = $this->cache->get($key, 0);
            /** @var int $count */
            $this->cache->increment($key);
            if ($count === 0) {
                $this->cache->put($key, 1, $this->cacheTtl);
            }

            // Time bucket
            $timeKey = $this->getTimeBucketKey($event);
            $timeCount = $this->cache->get($timeKey, 0);
            /** @var int $timeCount */
            $this->cache->increment($timeKey);
            if ($timeCount === 0) {
                $this->cache->put($timeKey, 1, $this->cacheTtl);
            }
        } catch (\Throwable $e) {
            // Cache operations may fail — fail silently for aggregation
        }
    }

    /**
     * Build time-bucketed counters from cache.
     *
     * @return array<string, int>
     */
    private function buildTimeBuckets(string $granularity, int $limit): array
    {
        $buckets = [];
        $now = now();

        for ($i = $limit - 1; $i >= 0; $i--) {
            if ($granularity === 'day') {
                $period = $now->copy()->subDays($i)->format('Y-m-d');
            } else {
                $period = $now->copy()->subHours($i)->format('Y-m-d H:00');
            }

            $key = self::CACHE_PREFIX . 'time:' . $granularity . ':' . $period;
            $count = $this->cache->get($key, 0);
            /** @var int $count */
            $buckets[$period] = $count;
        }

        return $buckets;
    }

    /**
     * Get the time bucket cache key for an event.
     */
    private function getTimeBucketKey(AnalyticsEvent $event): string
    {
        $timestamp = $event->timestamp ?? new \DateTimeImmutable();
        $format = $this->timeGranularity === 'day' ? 'Y-m-d' : 'Y-m-d H:00';

        return self::CACHE_PREFIX . 'time:' . $this->timeGranularity . ':' . $timestamp->format($format);
    }

    /**
     * Apply Laplace noise if enabled, for differential privacy.
     */
    private function applyNoise(int $value): float
    {
        if (! $this->laplaceNoise || $this->noiseScale <= 0) {
            return (float) $value;
        }

        // Box-Muller transform for Gaussian noise, then Laplace via difference of exponentials
        $u = (mt_rand() / mt_getrandmax());
        $v = (mt_rand() / mt_getrandmax());

        // Laplace noise: -scale * sign(U) * ln(1 - 2|U|)
        $sign = $u >= 0.5 ? 1.0 : -1.0;
        $absU = abs($u - 0.5) * 2.0;
        $laplace = -$this->noiseScale * $sign * log(max($absU, 1e-10));

        return max(0.0, (float) $value + $laplace);
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the k-anonymity threshold.
     */
    public function getKThreshold(): int
    {
        return $this->kThreshold;
    }

    /**
     * Check if Laplace noise is enabled.
     */
    public function isNoiseEnabled(): bool
    {
        return $this->laplaceNoise;
    }
}
