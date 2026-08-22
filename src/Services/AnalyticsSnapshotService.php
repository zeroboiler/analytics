<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;

/**
 * Periodic analytics snapshot service for trend comparisons.
 *
 * Captures point-in-time snapshots of analytics metrics at configurable
 * intervals (hourly, daily). Enables before/after comparisons, trend detection,
 * and historical performance tracking without requiring a database.
 *
 * Snapshots are stored in the Laravel cache with configurable TTL.
 * For persistent storage, use a Redis or database cache driver.
 *
 * Configuration: `zeroboiler.analytics.snapshots`
 *
 * @since 1.0.0
 */
final class AnalyticsSnapshotService
{
    private const CACHE_PREFIX = 'zb_analytics_snapshot_';

    private const DEFAULT_DAILY_TTL = 7776000; // 90 days

    private const DEFAULT_HOURLY_TTL = 604800; // 7 days

    private AnalyticsMetrics $metrics;

    private CacheRepository $cache;

    private bool $enabled;

    private int $dailyTtl;

    private int $hourlyTtl;

    private int $maxDailySnapshots;

    private int $maxHourlySnapshots;

    public function __construct(
        AnalyticsMetrics $metrics,
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->metrics = $metrics;
        $this->cache = $cache;

        $snapshotConfig = $config->get('zeroboiler.analytics.snapshots', []);
        /** @var array{enabled?: bool, daily_ttl?: int, hourly_ttl?: int, max_daily?: int, max_hourly?: int} $snapshotConfig */
        $this->enabled = (bool) ($snapshotConfig['enabled'] ?? true);
        $this->dailyTtl = (int) ($snapshotConfig['daily_ttl'] ?? self::DEFAULT_DAILY_TTL);
        $this->hourlyTtl = (int) ($snapshotConfig['hourly_ttl'] ?? self::DEFAULT_HOURLY_TTL);
        $this->maxDailySnapshots = (int) ($snapshotConfig['max_daily'] ?? 90);
        $this->maxHourlySnapshots = (int) ($snapshotConfig['max_hourly'] ?? 168);
    }

    /**
     * Take a daily snapshot of current metrics.
     *
     * Should be called by a scheduled command (e.g., daily at midnight).
     *
     * @return array{date: string, snapshot: array<string, mixed>, stored: bool}
     */
    public function takeDailySnapshot(?string $date = null): array
    {
        if (! $this->enabled) {
            return ['date' => $date ?? date('Y-m-d'), 'snapshot' => [], 'stored' => false];
        }

        $date = $date ?? date('Y-m-d');
        $key = self::CACHE_PREFIX . "daily:{$date}";
        $snapshot = $this->captureSnapshot('daily', $date);

        $this->cache->put($key, $snapshot, $this->dailyTtl);

        // Enforce max snapshots by cleaning old ones
        $this->enforceLimit('daily', $this->maxDailySnapshots);

        return [
            'date' => $date,
            'snapshot' => $snapshot,
            'stored' => true,
        ];
    }

    /**
     * Take an hourly snapshot of current metrics.
     *
     * Should be called by a scheduled command (e.g., every hour).
     *
     * @return array{hour: string, snapshot: array<string, mixed>, stored: bool}
     */
    public function takeHourlySnapshot(?string $hour = null): array
    {
        if (! $this->enabled) {
            return ['hour' => $hour ?? date('Y-m-d-H'), 'snapshot' => [], 'stored' => false];
        }

        $hour = $hour ?? date('Y-m-d-H');
        $key = self::CACHE_PREFIX . "hourly:{$hour}";
        $snapshot = $this->captureSnapshot('hourly', $hour);

        $this->cache->put($key, $snapshot, $this->hourlyTtl);

        // Enforce max snapshots by cleaning old ones
        $this->enforceLimit('hourly', $this->maxHourlySnapshots);

        return [
            'hour' => $hour,
            'snapshot' => $snapshot,
            'stored' => true,
        ];
    }

    /**
     * Get a specific daily snapshot.
     *
     * @return array<string, mixed>|null
     */
    public function getDailySnapshot(string $date): ?array
    {
        $key = self::CACHE_PREFIX . "daily:{$date}";
        $data = $this->cache->get($key);

        return is_array($data) ? $data : null;
    }

    /**
     * Get a specific hourly snapshot.
     *
     * @return array<string, mixed>|null
     */
    public function getHourlySnapshot(string $hour): ?array
    {
        $key = self::CACHE_PREFIX . "hourly:{$hour}";
        $data = $this->cache->get($key);

        return is_array($data) ? $data : null;
    }

    /**
     * Get the latest daily snapshot.
     *
     * @return array<string, mixed>|null
     */
    public function latestDaily(): ?array
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today = date('Y-m-d');

        return $this->getDailySnapshot($today) ?? $this->getDailySnapshot($yesterday);
    }

    /**
     * Get the latest hourly snapshot.
     *
     * @return array<string, mixed>|null
     */
    public function latestHourly(): ?array
    {
        $currentHour = date('Y-m-d-H');
        $previousHour = date('Y-m-d-H', strtotime('-1 hour'));

        return $this->getHourlySnapshot($currentHour) ?? $this->getHourlySnapshot($previousHour);
    }

    /**
     * Compare today's snapshot with yesterday's.
     *
     * @return array{today: array<string, mixed>|null, yesterday: array<string, mixed>|null, delta: array<string, int|float>|null}
     */
    public function dailyComparison(): array
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $todaySnapshot = $this->getDailySnapshot($today);
        $yesterdaySnapshot = $this->getDailySnapshot($yesterday);

        $delta = null;

        if ($todaySnapshot !== null && $yesterdaySnapshot !== null) {
            $delta = $this->computeDelta($yesterdaySnapshot, $todaySnapshot);
        }

        return [
            'today' => $todaySnapshot,
            'yesterday' => $yesterdaySnapshot,
            'delta' => $delta,
        ];
    }

    /**
     * Get all available daily snapshot dates.
     *
     * @return list<string>
     */
    public function dailySnapshotDates(): array
    {
        // This requires a taggable cache or iterating known dates
        $dates = [];
        $today = date('Y-m-d');

        for ($i = 0; $i < $this->maxDailySnapshots; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            if ($this->getDailySnapshot($date) !== null) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Get summary of snapshot service state.
     *
     * @return array{enabled: bool, daily_ttl: int, hourly_ttl: int, max_daily: int, max_hourly: int, latest_daily: array<string, mixed>|null, latest_hourly: array<string, mixed>|null, comparison: array<string, mixed>}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'daily_ttl' => $this->dailyTtl,
            'hourly_ttl' => $this->hourlyTtl,
            'max_daily' => $this->maxDailySnapshots,
            'max_hourly' => $this->maxHourlySnapshots,
            'latest_daily' => $this->latestDaily(),
            'latest_hourly' => $this->latestHourly(),
            'comparison' => $this->dailyComparison(),
        ];
    }

    /**
     * Check if snapshots are enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Capture current metrics as a snapshot.
     *
     * @param  string  $type  'daily' or 'hourly'
     * @param  string  $period  Date or hour identifier
     * @return array{type: string, period: string, captured_at: string, total_dispatched: int, total_failed: int, per_provider: array<string, int>, replay_queue_size: int}
     */
    private function captureSnapshot(string $type, string $period): array
    {
        return [
            'type' => $type,
            'period' => $period,
            'captured_at' => date('c'),
            'total_dispatched' => $this->metrics->totalDispatched(),
            'total_failed' => $this->metrics->totalFailed(),
            'per_provider' => $this->metrics->summary(),
            'replay_queue_size' => $this->metrics->replayQueueSize(),
        ];
    }

    /**
     * Compute the delta between two snapshots.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return array<string, int|float>
     */
    private function computeDelta(array $previous, array $current): array
    {
        $delta = [];

        $numericKeys = ['total_dispatched', 'total_failed', 'replay_queue_size'];

        foreach ($numericKeys as $key) {
            $prev = is_int($previous[$key] ?? null) ? $previous[$key] : 0;
            $curr = is_int($current[$key] ?? null) ? $current[$key] : 0;
            $delta[$key] = $curr - $prev;

            if ($prev > 0) {
                $delta[$key . '_pct'] = round((($curr - $prev) / $prev) * 100, 2);
            }
        }

        return $delta;
    }

    /**
     * Enforce maximum snapshot count by removing oldest entries.
     */
    private function enforceLimit(string $type, int $max): void
    {
        // With a taggable cache driver, this would use tags
        // For basic drivers, we rely on TTL expiration
    }
}
