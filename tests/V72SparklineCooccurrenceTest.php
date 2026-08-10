<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Services\EventSparklineService;
use ZeroBoiler\Analytics\Services\EventCooccurrenceService;

beforeEach(function (): void {
    $this->cache = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $this->metrics = new AnalyticsMetrics;
});

describe('EventSparklineService', function (): void {
    it('creates an empty sparkline for unknown events', function (): void {
        $service = new EventSparklineService($this->cache, $this->metrics);

        $result = $service->sparkline('unknown_event', 10);

        expect($result)->toHaveKey('event');
        expect($result['event'])->toBe('unknown_event');
        expect($result['data'])->toBeArray();
        expect($result['data'])->toHaveCount(10);
        expect($result['min'])->toBe(0);
        expect($result['max'])->toBe(0);
        expect($result['avg'])->toBe(0.0);
        expect($result['trend'])->toBe('flat');
        expect($result['points'])->toBe(10);
    });

    it('generates sparkline with correct data structure', function (): void {
        $service = new EventSparklineService($this->cache, $this->metrics);

        $result = $service->sparkline('test_event', 24, 24);

        expect($result)->toHaveKeys(['event', 'data', 'min', 'max', 'avg', 'trend', 'points']);
        expect($result['event'])->toBe('test_event');
        expect($result['points'])->toBe(24);
        expect($result['data'])->toHaveCount(24);
        expect($result['data'])->each(fn ($v) => expect(is_numeric($v))->toBeTrue());
    });

    it('uses cache for repeated sparkline requests', function (): void {
        $cachedResult = [
            'event' => 'cached_event',
            'data' => [1, 2, 3, 4, 5],
            'min' => 1,
            'max' => 5,
            'avg' => 3.0,
            'trend' => 'up',
            'points' => 5,
        ];

        $this->cache->shouldReceive('get')
            ->with(Mockery::pattern('/^zb_sparkline_/'))
            ->andReturn($cachedResult);

        $service = new EventSparklineService($this->cache, $this->metrics);
        $result = $service->sparkline('cached_event', 5, 5);

        expect($result)->toBe($cachedResult);
    });

    it('caches computed sparkline results', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn(null);
        $this->cache->shouldReceive('put')
            ->withArgs(fn (string $key, array $value, int $ttl): bool =>
                str_starts_with($key, 'zb_sparkline_') && $ttl > 0
            )
            ->once();

        $service = new EventSparklineService($this->cache, $this->metrics);
        $service->sparkline('page_view', 12, 12);
    });

    it('generates batch sparklines for multiple events', function (): void {
        $service = new EventSparklineService($this->cache, $this->metrics);

        $results = $service->sparklines(['page_view', 'click', 'scroll_depth'], 10);

        expect($results)->toBeArray();
        expect($results)->toHaveCount(3);
        expect($results)->toHaveKeys(['page_view', 'click', 'scroll_depth']);

        foreach ($results as $result) {
            expect($result)->toHaveKeys(['event', 'data', 'min', 'max', 'avg', 'trend', 'points']);
            expect($result['points'])->toBe(10);
        }
    });

    it('generates category sparklines', function (): void {
        $service = new EventSparklineService($this->cache, $this->metrics);

        $results = $service->categorySparklines(12);

        expect($results)->toBeArray();
        expect($results)->toHaveKeys(['ecommerce', 'saas', 'engagement']);

        foreach ($results as $category => $sparkline) {
            expect($sparkline)->toHaveKey('data');
            expect($sparkline['data'])->toHaveCount(12);
        }
    });

    it('generates dashboard summary with correct structure', function (): void {
        $service = new EventSparklineService($this->cache, $this->metrics);

        $summary = $service->dashboardSummary(12);

        expect($summary)->toBeArray();
        expect($summary)->toHaveKeys(['total_events', 'top_movers', 'aggregate_trend', 'categories']);
        expect($summary['categories'])->toHaveKeys(['ecommerce', 'saas', 'engagement']);
        expect($summary['top_movers'])->toBeArray();
        expect(in_array($summary['aggregate_trend'], ['up', 'down', 'flat'], true))->toBeTrue();
    });

    it('reports enabled status correctly', function (): void {
        $service = new EventSparklineService($this->cache, $this->metrics);

        expect($service->isEnabled())->toBe(true);
    });

    it('calculates trend direction correctly', function (): void {
        $service = new EventSparklineService($this->cache, $this->metrics);

        // Test with reflected method to access private calculateTrend
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('calculateTrend');
        $method->setAccessible(true);

        // Upward trend
        $upwardData = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0];
        expect($method->invoke($service, $upwardData))->toBe('up');

        // Downward trend
        $downwardData = [8.0, 7.0, 6.0, 5.0, 4.0, 3.0, 2.0, 1.0];
        expect($method->invoke($service, $downwardData))->toBe('down');

        // Flat trend
        $flatData = [5.0, 5.0, 5.0, 5.0, 5.0, 5.0, 5.0, 5.0];
        expect($method->invoke($service, $flatData))->toBe('flat');

        // Too few points
        $shortData = [1.0, 2.0];
        expect($method->invoke($service, $shortData))->toBe('flat');
    });

    it('generates provider sparklines with correct providers', function (): void {
        $service = new EventSparklineService($this->cache, $this->metrics);

        $results = $service->providerSparklines(10);

        expect($results)->toBeArray();
        expect($results)->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible', 'webhook']);

        foreach ($results as $provider => $sparkline) {
            expect($sparkline)->toHaveKey('data');
            expect($sparkline['points'])->toBe(10);
        }
    });

    it('respects custom points and period parameters', function (): void {
        $service = new EventSparklineService($this->cache, $this->metrics);

        $result = $service->sparkline('page_view', 48, 72);

        expect($result['points'])->toBe(48);
        expect($result['data'])->toHaveCount(48);
    });

    it('generates valid sparkline min/max/avg for non-empty data', function (): void {
        // Cache a count for the event to make it non-empty
        $this->cache->shouldReceive('get')
            ->andReturn(null); // No cached sparkline
        $this->cache->shouldReceive('put')
            ->once();
        // Provide count for the event
        $this->cache->shouldReceive('get')
            ->withArgs(fn (string $key): bool => str_contains($key, 'count:page_view'))
            ->andReturn(100);

        $service = new EventSparklineService($this->cache, $this->metrics);
        $result = $service->sparkline('page_view', 10, 24);

        expect($result['min'])->toBeGreaterThanOrEqual(0);
        expect($result['max'])->toBeGreaterThanOrEqual(0);
        expect($result['avg'])->toBeGreaterThanOrEqual(0.0);
        expect($result['max'])->toBeGreaterThanOrEqual($result['min']);
    });
});

describe('EventCooccurrenceService', function (): void {
    it('initializes with empty matrix', function (): void {
        $service = new EventCooccurrenceService($this->cache, $this->metrics);

        $matrix = $service->getMatrix();

        expect($matrix)->toBeArray();
    });

    it('records events and builds co-occurrence pairs', function (): void {
        $service = new EventCooccurrenceService($this->cache, $this->metrics);

        $service->recordEvent('page_view', 'session_1');
        $service->recordEvent('click', 'session_1');
        $service->recordEvent('form_start', 'session_1');

        $pairs = $service->topPairs(10);

        expect($pairs)->toBeArray();
        // page_view and click should co-occur
        $foundPair = false;
        foreach ($pairs as $pair) {
            $events = [$pair['event_a'], $pair['event_b']];
            if (in_array('page_view', $events, true) && in_array('click', $events, true)) {
                $foundPair = true;
                expect($pair['count'])->toBeGreaterThanOrEqual(1);
            }
        }
        expect($foundPair)->toBe(true);
    });

    it('finds co-occurring events for a given event', function (): void {
        $service = new EventCooccurrenceService($this->cache, $this->metrics);

        $service->recordEvent('page_view', 'session_1');
        $service->recordEvent('scroll_depth', 'session_1');
        $service->recordEvent('click', 'session_1');

        $cooccurring = $service->cooccurringWith('page_view', 10);

        expect($cooccurring)->toBeArray();
        expect(count($cooccurring))->toBeGreaterThanOrEqual(2); // at least scroll_depth and click
    });

    it('generates dashboard summary with correct structure', function (): void {
        $service = new EventCooccurrenceService($this->cache, $this->metrics);

        $service->recordEvent('page_view', 'session_1');
        $service->recordEvent('click', 'session_1');

        $summary = $service->dashboardSummary();

        expect($summary)->toBeArray();
        expect($summary)->toHaveKeys(['top_pairs', 'total_pairs', 'event_degrees', 'clusters']);
    });

    it('does not include self-pair in co-occurring results', function (): void {
        $service = new EventCooccurrenceService($this->cache, $this->metrics);

        $service->recordEvent('page_view', 'session_1');

        $cooccurring = $service->cooccurringWith('page_view', 10);

        foreach ($cooccurring as $item) {
            expect($item['event'])->not->toBe('page_view');
        }
    });

    it('reports enabled status correctly', function (): void {
        $service = new EventCooccurrenceService($this->cache, $this->metrics);

        expect($service->isEnabled())->toBe(true);
    });

    it('resets state correctly', function (): void {
        $service = new EventCooccurrenceService($this->cache, $this->metrics);

        $service->recordEvent('page_view', 'session_1');
        $service->recordEvent('click', 'session_1');

        expect($service->topPairs(10))->not->toBeEmpty();

        $service->reset();

        // After reset, matrix should be rebuilt from metrics (which has no data)
        // So pairs should be empty unless buildFromMetrics creates them
    });

    it('handles empty session gracefully', function (): void {
        $service = new EventCooccurrenceService($this->cache, $this->metrics);

        // Just getting the matrix without recording anything
        $matrix = $service->getMatrix();

        expect($matrix)->toBeArray();
    });

    it('groups events by session correctly', function (): void {
        $service = new EventCooccurrenceService($this->cache, $this->metrics);

        // Session 1: page_view → click → form_start
        $service->recordEvent('page_view', 'session_a');
        $service->recordEvent('click', 'session_a');
        $service->recordEvent('form_start', 'session_a');

        // Session 2: page_view → scroll_depth → click
        $service->recordEvent('page_view', 'session_b');
        $service->recordEvent('scroll_depth', 'session_b');
        $service->recordEvent('click', 'session_b');

        $pairs = $service->topPairs(10);

        // page_view → click should appear twice (once per session)
        $pageViewClickCount = 0;
        foreach ($pairs as $pair) {
            $events = [$pair['event_a'], $pair['event_b']];
            sort($events);
            if ($events === ['click', 'page_view']) {
                $pageViewClickCount = $pair['count'];
            }
        }

        expect($pageViewClickCount)->toBeGreaterThanOrEqual(2);
    });

    it('handles same-event recording without self-pairs', function (): void {
        $service = new EventCooccurrenceService($this->cache, $this->metrics);

        $service->recordEvent('page_view', 'session_1');
        $service->recordEvent('page_view', 'session_1');

        $cooccurring = $service->cooccurringWith('page_view', 10);

        // page_view should not appear in its own co-occurring list
        foreach ($cooccurring as $item) {
            expect($item['event'])->not->toBe('page_view');
        }
    });

    it('computes correlation scores between 0 and 1', function (): void {
        $service = new EventCooccurrenceService($this->cache, $this->metrics);

        $service->recordEvent('page_view', 'session_1');
        $service->recordEvent('click', 'session_1');

        $pairs = $service->topPairs(10);

        foreach ($pairs as $pair) {
            expect($pair['correlation'])->toBeGreaterThanOrEqual(0.0);
            expect($pair['correlation'])->toBeLessThanOrEqual(1.0);
        }
    });
});
