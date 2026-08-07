<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Services\EventAggregationService;
use ZeroBoiler\Analytics\Queue\EventReplayQueue;

/**
 * Analytics statistics aggregation service.
 *
 * Provides pre-computed aggregate data for dashboards and monitoring.
 * Combines real-time metrics from EventAggregationService with
 * dispatch/failure counters from AnalyticsMetrics and replay queue status.
 *
 * Designed to power the GET /api/analytics/stats endpoint.
 */
final class AnalyticsStatsService
{
    private AnalyticsManager $manager;

    private AnalyticsMetrics $metrics;

    private EventAggregationService $aggregation;

    private EventReplayQueue $replayQueue;

    /**
     * @param  AnalyticsManager  $manager
     * @param  AnalyticsMetrics  $metrics
     * @param  EventAggregationService  $aggregation
     * @param  EventReplayQueue  $replayQueue
     */
    public function __construct(
        AnalyticsManager $manager,
        AnalyticsMetrics $metrics,
        EventAggregationService $aggregation,
        EventReplayQueue $replayQueue,
    ): void {
        $this->manager = $manager;
        $this->metrics = $metrics;
        $this->aggregation = $aggregation;
        $this->replayQueue = $replayQueue;
    }

    /**
     * Get a summary of analytics statistics.
     *
     * Returns total events, per-category breakdowns, top events,
     * per-provider dispatch/failure stats, and replay queue status.
     *
     * @return array{total_tracked: int, unique_events: int, top_events: list<array{event: string, count: int}>, categories: array<string, int>, providers: array<string, array{dispatched: int, failed: int}>, replay: array<string, mixed>, catalog: array{ecommerce: int, saas: int, engagement: int, total: int}, version: string}
     */
    public function summary(): array
    {
        $topEvents = $this->aggregation->topEvents(10);
        $categoryCounts = $this->aggregation->byCategory();
        $categoryTotals = [];

        foreach ($categoryCounts as $category => $events) {
            $categoryTotals[$category] = array_sum($events);
        }

        return [
            'total_tracked' => $this->aggregation->totalTracked(),
            'unique_events' => count($this->aggregation->allCounts()),
            'top_events' => $topEvents,
            'categories' => $categoryTotals,
            'providers' => $this->metrics->summary(),
            'replay' => $this->replayQueue->summary(),
            'catalog' => $this->manager->eventCatalogSummary(),
            'version' => $this->manager->version(),
        ];
    }

    /**
     * Get per-category event breakdown with event names.
     *
     * @param  int  $limit  Max events per category
     * @return array<string, array{total: int, events: list<array{event: string, count: int}>}>
     */
    public function byCategory(int $limit = 10): array
    {
        $categoryCounts = $this->aggregation->byCategory();
        $result = [];

        foreach ($categoryCounts as $category => $events) {
            arsort($events);
            $top = [];
            $count = 0;

            foreach ($events as $name => $cnt) {
                $top[] = ['event' => $name, 'count' => $cnt];
                $count++;

                if ($count >= $limit) {
                    break;
                }
            }

            $result[$category] = [
                'total' => array_sum($events),
                'events' => $top,
            ];
        }

        return $result;
    }

    /**
     * Get per-provider dispatch and failure counts.
     *
     * @return array<string, array{dispatched: int, failed: int, success_rate: float|null}>
     */
    public function byProvider(): array
    {
        $summary = $this->metrics->summary();
        $result = [];

        foreach ($summary as $provider => $stats) {
            $dispatched = (int) ($stats['dispatched'] ?? 0);
            $failed = (int) ($stats['failed'] ?? 0);
            $total = $dispatched + $failed;

            $result[$provider] = [
                'dispatched' => $dispatched,
                'failed' => $failed,
                'success_rate' => $total > 0
                    ? round(($dispatched / $total) * 100, 2)
                    : null,
            ];
        }

        return $result;
    }
}
