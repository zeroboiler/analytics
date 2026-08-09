<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event reporting service for periodic analytics summaries.
 *
 * Generates daily, weekly, and monthly event reports including:
 * - Total event counts per provider
 * - Top events by frequency
 * - Category breakdown
 * - Trending events (events with increasing velocity)
 * - Dispatch success/failure rates
 *
 * Reports are cached for configurable TTL and can be retrieved
 * via the API or artisan commands.
 *
 * @since 1.0.0
 */
final class EventReportingService
{
    private AnalyticsMetrics $metrics;

    private CacheRepository $cache;

    private int $cacheTtl;

    private bool $enabled;

    private int $trendingWindowSeconds;

    private int $topEventsLimit;

    private int $trendingLimit;

    public function __construct(
        AnalyticsMetrics $metrics,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->metrics = $metrics;
        $this->cache = $cache;

        $reportConfig = $config->get('zeroboiler.analytics.reporting', []);
        /** @var array{enabled?: bool, cache_ttl?: int, trending_window?: int, top_events_limit?: int, trending_limit?: int} $reportConfig */
        $this->enabled = (bool) ($reportConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($reportConfig['cache_ttl'] ?? 300);
        $this->trendingWindowSeconds = (int) ($reportConfig['trending_window'] ?? 3600);
        $this->topEventsLimit = (int) ($reportConfig['top_events_limit'] ?? 20);
        $this->trendingLimit = (int) ($reportConfig['trending_limit'] ?? 10);
    }

    /**
     * Generate a full analytics report for the given period.
     *
     * @param  'daily'|'weekly'|'monthly'|'hourly'  $period
     * @return array{period: string, generated_at: string, enabled: bool, total_events: int, total_dispatched: int, total_failed: int, success_rate: float, by_provider: array<string, int>, by_category: array<string, int>, top_events: list<array{name: string, count: int, category: string|null}>, trending_events: list<array{name: string, current_rate: float, previous_rate: float, growth: float}>, event_catalog_summary: array{total: int, ecommerce: int, saas: int, engagement: int}, replay_summary: array{pending: int, failed: int}}
     */
    public function report(string $period = 'daily'): array
    {
        if (! $this->enabled) {
            return [
                'period' => $period,
                'generated_at' => now()->toIso8601String(),
                'enabled' => false,
                'total_events' => 0,
                'total_dispatched' => 0,
                'total_failed' => 0,
                'success_rate' => 0.0,
                'by_provider' => [],
                'by_category' => [],
                'top_events' => [],
                'trending_events' => [],
                'event_catalog_summary' => EventCatalog::count() > 0
                    ? ['total' => EventCatalog::count(), 'ecommerce' => 0, 'saas' => 0, 'engagement' => 0]
                    : ['total' => 0, 'ecommerce' => 0, 'saas' => 0, 'engagement' => 0],
                'replay_summary' => ['pending' => 0, 'failed' => 0],
            ];
        }

        $cacheKey = "zeroboiler:analytics:report:{$period}:" . now()->format('Y-m-d-H');

        /** @var array|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $snapshot = $this->metrics->snapshot();
        $report = $this->buildReport($period, $snapshot);

        $this->cache->put($cacheKey, $report, $this->cacheTtl);

        return $report;
    }

    /**
     * Generate a quick summary (single-line status).
     *
     * @return array{events: int, dispatched: int, failed: int, success_rate: float, top_event: string|null}
     */
    public function quickSummary(): array
    {
        $snapshot = $this->metrics->snapshot();
        $totalDispatched = array_sum($snapshot['dispatchedByProvider'] ?? []);
        $totalFailed = $snapshot['failureCount'] ?? 0;
        $totalEvents = $snapshot['totalEvents'] ?? 0;
        $topEvent = null;

        if (($snapshot['eventCounts'] ?? []) !== []) {
            arsort($snapshot['eventCounts']);
            $topEvent = array_key_first($snapshot['eventCounts']);
        }

        return [
            'events' => $totalEvents,
            'dispatched' => $totalDispatched,
            'failed' => $totalFailed,
            'success_rate' => $totalDispatched > 0
                ? round(($totalDispatched - $totalFailed) / $totalDispatched * 100, 2)
                : 100.0,
            'top_event' => $topEvent,
        ];
    }

    /**
     * Get the top N events by count.
     *
     * @return list<array{name: string, count: int, category: string|null}>
     */
    public function topEvents(int $limit = 20): array
    {
        $snapshot = $this->metrics->snapshot();
        $eventCounts = $snapshot['eventCounts'] ?? [];

        arsort($eventCounts);

        $result = [];
        $count = 0;

        foreach ($eventCounts as $name => $count_) {
            if ($count >= $limit) {
                break;
            }

            $result[] = [
                'name' => $name,
                'count' => $count_,
                'category' => EventCatalog::getCategory($name),
            ];
            $count++;
        }

        return $result;
    }

    /**
     * Get trending events — events with increasing dispatch rate.
     *
     * Compares recent event counts against the previous window to detect
     * events with significant growth (potential viral content, feature adoption).
     *
     * @return list<array{name: string, current_rate: float, previous_rate: float, growth: float}>
     */
    public function trendingEvents(int $limit = 10): array
    {
        $snapshot = $this->metrics->snapshot();
        $eventCounts = $snapshot['eventCounts'] ?? [];

        $currentWindowSeconds = $this->trendingWindowSeconds;
        $previousWindowSeconds = $this->trendingWindowSeconds;

        $trending = [];

        foreach ($eventCounts as $name => $count) {
            $currentRate = $count > 0 ? $count / $currentWindowSeconds : 0.0;

            // Estimate previous rate from trend data if available
            $trendData = $snapshot['trendData'][$name] ?? null;
            $previousRate = 0.0;

            if (is_array($trendData) && count($trendData) >= 2) {
                $prevCount = array_sum(array_slice($trendData, -max(1, count($trendData) - 1)));
                $previousRate = $prevCount > 0
                    ? $prevCount / $previousWindowSeconds
                    : 0.0;
            }

            // Calculate growth percentage
            $growth = $previousRate > 0
                ? (($currentRate - $previousRate) / $previousRate) * 100
                : ($currentRate > 0 ? 100.0 : 0.0);

            if ($growth > 10.0) { // Only include events with >10% growth
                $trending[] = [
                    'name' => $name,
                    'current_rate' => round($currentRate, 4),
                    'previous_rate' => round($previousRate, 4),
                    'growth' => round($growth, 2),
                ];
            }
        }

        // Sort by growth descending
        usort($trending, fn (array $a, array $b): int => $b['growth'] <=> $a['growth']);

        return array_slice($trending, 0, $limit);
    }

    /**
     * Generate provider-level dispatch statistics.
     *
     * @return array{providers: array<string, array{dispatched: int, failed: int, success_rate: float}>, totals: array{dispatched: int, failed: int, success_rate: float}}
     */
    public function providerStats(): array
    {
        $snapshot = $this->metrics->snapshot();
        $dispatchedByProvider = $snapshot['dispatchedByProvider'] ?? [];
        $failuresByProvider = $snapshot['failuresByProvider'] ?? [];

        $providers = [];
        $totalDispatched = 0;
        $totalFailed = 0;

        foreach ($dispatchedByProvider as $provider => $count) {
            $failed = $failuresByProvider[$provider] ?? 0;
            $totalDispatched += $count;
            $totalFailed += $failed;

            $providers[$provider] = [
                'dispatched' => $count,
                'failed' => $failed,
                'success_rate' => $count > 0
                    ? round(($count - $failed) / $count * 100, 2)
                    : 100.0,
            ];
        }

        return [
            'providers' => $providers,
            'totals' => [
                'dispatched' => $totalDispatched,
                'failed' => $totalFailed,
                'success_rate' => $totalDispatched > 0
                    ? round(($totalDispatched - $totalFailed) / $totalDispatched * 100, 2)
                    : 100.0,
            ],
        ];
    }

    /**
     * Check if the reporting service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Invalidate all report caches.
     */
    public function clearCache(): void
    {
        $periods = ['hourly', 'daily', 'weekly', 'monthly'];

        foreach ($periods as $period) {
            // Clear for multiple hours/days to ensure full invalidation
            for ($i = 0; $i < 48; $i++) {
                $this->cache->forget("zeroboiler:analytics:report:{$period}:" . now()->subHours($i)->format('Y-m-d-H'));
            }
        }
    }

    /**
     * Build a full report from a metrics snapshot.
     *
     * @param  'daily'|'weekly'|'monthly'|'hourly'  $period
     * @param  array{totalEvents: int, dispatchedByProvider: array<string, int>, failureCount: int, eventCounts: array<string, int>, trendData: array<string, list<int>>, failuresByProvider: array<string, int>}  $snapshot
     * @return array{period: string, generated_at: string, enabled: bool, total_events: int, total_dispatched: int, total_failed: int, success_rate: float, by_provider: array<string, int>, by_category: array<string, int>, top_events: list<array{name: string, count: int, category: string|null}>, trending_events: list<array{name: string, current_rate: float, previous_rate: float, growth: float}>, event_catalog_summary: array{total: int, ecommerce: int, saas: int, engagement: int}, replay_summary: array{pending: int, failed: int}}
     */
    private function buildReport(string $period, array $snapshot): array
    {
        $totalDispatched = array_sum($snapshot['dispatchedByProvider'] ?? []);
        $totalFailed = $snapshot['failureCount'] ?? 0;

        // Category breakdown
        $byCategory = ['ecommerce' => 0, 'saas' => 0, 'engagement' => 0];

        foreach ($snapshot['eventCounts'] ?? [] as $name => $count) {
            $category = EventCatalog::getCategory($name);

            if ($category !== null && isset($byCategory[$category])) {
                $byCategory[$category] += $count;
            }
        }

        // Top events
        $topEvents = $this->topEvents($this->topEventsLimit);

        // Trending events
        $trendingEvents = $this->trendingEvents($this->trendingLimit);

        // Event catalog summary
        $catalogByCategory = EventCatalog::byCategory();

        return [
            'period' => $period,
            'generated_at' => now()->toIso8601String(),
            'enabled' => true,
            'total_events' => $snapshot['totalEvents'] ?? 0,
            'total_dispatched' => $totalDispatched,
            'total_failed' => $totalFailed,
            'success_rate' => $totalDispatched > 0
                ? round(($totalDispatched - $totalFailed) / $totalDispatched * 100, 2)
                : 100.0,
            'by_provider' => $snapshot['dispatchedByProvider'] ?? [],
            'by_category' => $byCategory,
            'top_events' => $topEvents,
            'trending_events' => $trendingEvents,
            'event_catalog_summary' => [
                'total' => EventCatalog::count(),
                'ecommerce' => count($catalogByCategory['ecommerce'] ?? []),
                'saas' => count($catalogByCategory['saas'] ?? []),
                'engagement' => count($catalogByCategory['engagement'] ?? []),
            ],
            'replay_summary' => [
                'pending' => 0,
                'failed' => 0,
            ],
        ];
    }
}
