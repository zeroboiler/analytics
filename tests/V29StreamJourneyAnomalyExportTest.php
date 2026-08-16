<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\UserJourneyService;
use ZeroBoiler\Analytics\Services\AnomalyDetectionService;
use ZeroBoiler\Analytics\Services\EventStreamService;
use ZeroBoiler\Analytics\Services\ExportService;

beforeEach(function (): void {
    $config = mock(\Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->andReturn([]);
    $this->manager = new AnalyticsManager($config);
    $this->metrics = $this->manager->metrics();
    $this->queue = mock(QueuedAnalyticsDispatcher::class);
    $this->queue->shouldIgnoreMissing();
});

// ─── UserJourneyService ────────────────────────────────────────────────────

describe('UserJourneyService', function (): void {
    beforeEach(function (): void {
        $this->journey = new UserJourneyService(
            $this->manager,
            $this->queue,
            false, // sync for testing
            100,
            50,
        );
    });

    test('records steps in a journey', function (): void {
        $this->journey->recordStep('j1', 'page_view', ['page' => '/home'], 'u1', 'c1', '/home');
        $this->journey->recordStep('j1', 'add_to_cart', ['item_id' => 'SKU-1'], 'u1', 'c1');

        $result = $this->journey->getJourney('j1');

        expect($result)->not->toBeNull();
        expect($result['step_count'])->toBe(2);
        expect($result['user_id'])->toBe('u1');
        expect($result['event_sequence'])->toBe('page_view → add_to_cart');
    });

    test('returns null for non-existent journey', function (): void {
        expect($this->journey->getJourney('nonexistent'))->toBeNull();
    });

    test('extracts page flow correctly', function (): void {
        $this->journey->recordStep('j1', 'page_view', [], null, null, '/home');
        $this->journey->recordStep('j1', 'page_view', [], null, null, '/products');
        $this->journey->recordStep('j1', 'click', [], null, null, '/products');
        $this->journey->recordStep('j1', 'page_view', [], null, null, '/checkout');

        $flow = $this->journey->getPageFlow('j1');

        expect($flow)->toBe(['/home', '/products', '/checkout']);
    });

    test('finds most common patterns', function (): void {
        // Journey 1: page_view → add_to_cart → purchase
        $this->journey->recordStep('j1', 'page_view', []);
        $this->journey->recordStep('j1', 'add_to_cart', []);
        $this->journey->recordStep('j1', 'purchase', []);

        // Journey 2: page_view → add_to_cart → purchase
        $this->journey->recordStep('j2', 'page_view', []);
        $this->journey->recordStep('j2', 'add_to_cart', []);
        $this->journey->recordStep('j2', 'purchase', []);

        // Journey 3: page_view → bounce
        $this->journey->recordStep('j3', 'page_view', []);

        $patterns = $this->journey->mostCommonPatterns(0, 10);

        expect($patterns)->toHaveCount(2);
        expect($patterns[0]['pattern'])->toBe('page_view → add_to_cart → purchase');
        expect($patterns[0]['count'])->toBe(2);
        expect($patterns[1]['pattern'])->toBe('page_view');
        expect($patterns[1]['count'])->toBe(1);
    });

    test('identifies drop-off points', function (): void {
        $this->journey->recordStep('j1', 'page_view', []);
        $this->journey->recordStep('j2', 'page_view', []);
        $this->journey->recordStep('j2', 'add_to_cart', []);

        $dropOffs = $this->journey->dropOffPoints();

        expect($dropOffs)->toHaveCount(2);
        expect($dropOffs[0]['event'])->toBe('page_view');
        expect($dropOffs[0]['drop_offs'])->toBe(1);
        expect($dropOffs[0]['rate'])->toBe(50.0);
    });

    test('finds matching journeys with pattern', function (): void {
        $this->journey->recordStep('j1', 'page_view', []);
        $this->journey->recordStep('j1', 'add_to_cart', []);
        $this->journey->recordStep('j1', 'purchase', []);

        $this->journey->recordStep('j2', 'page_view', []);
        $this->journey->recordStep('j2', 'signup', []);

        $matches = $this->journey->findMatchingJourneys('page_view → add_to_cart → purchase');

        expect($matches)->toHaveCount(1);
        expect($matches[0]['journey_id'])->toBe('j1');
    });

    test('finds matching journeys with wildcard', function (): void {
        $this->journey->recordStep('j1', 'page_view', []);
        $this->journey->recordStep('j1', 'add_to_cart', []);
        $this->journey->recordStep('j1', 'purchase', []);

        $matches = $this->journey->findMatchingJourneys('page_view → * → purchase');

        expect($matches)->toHaveCount(1);
        expect($matches[0]['journey_id'])->toBe('j1');
    });

    test('computes funnel conversion rates', function (): void {
        // Journey 1: full funnel
        $this->journey->recordStep('j1', 'page_view', []);
        $this->journey->recordStep('j1', 'add_to_cart', []);
        $this->journey->recordStep('j1', 'purchase', []);

        // Journey 2: abandoned at add_to_cart
        $this->journey->recordStep('j2', 'page_view', []);
        $this->journey->recordStep('j2', 'add_to_cart', []);

        // Journey 3: only page_view
        $this->journey->recordStep('j3', 'page_view', []);

        $funnel = $this->journey->funnelConversion(['page_view', 'add_to_cart', 'purchase']);

        expect($funnel['entrances'])->toBe(3);
        expect($funnel['completions'])->toBe(1);
        expect($funnel['conversion_rate'])->toBe(33.33);
        expect($funnel['step_rates'])->toHaveCount(3);
        expect($funnel['step_rates'][0]['step'])->toBe('page_view');
        expect($funnel['step_rates'][0]['count'])->toBe(3);
    });

    test('ends journey and dispatches event', function (): void {
        $this->journey->recordStep('j1', 'page_view', []);
        $this->journey->recordStep('j1', 'signup', []);

        $this->queue->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'user_journey_completed'
                    && ($event->params['journey_step_count'] ?? 0) === 2;
            });

        $this->journey->endJourney('j1', 'converted');

        expect($this->journey->getJourney('j1'))->toBeNull();
    });

    test('provides aggregate stats', function (): void {
        $this->journey->recordStep('j1', 'page_view', [], 'u1', 'c1');
        $this->journey->recordStep('j1', 'click', [], 'u1', 'c1');
        $this->journey->recordStep('j2', 'page_view', []);

        $stats = $this->journey->getStats();

        expect($stats['total_journeys'])->toBe(2);
        expect($stats['avg_steps'])->toBe(1.5);
        expect($stats['journeys_with_identity'])->toBe(1);
        expect($stats['journeys_anonymous'])->toBe(1);
    });

    test('flushes all journeys', function (): void {
        $this->journey->recordStep('j1', 'page_view', []);
        $this->journey->flush();

        expect($this->journey->count())->toBe(0);
    });

    test('evicts oldest journey when max reached', function (): void {
        $journey = new UserJourneyService($this->manager, $this->queue, false, 2, 50);

        $journey->recordStep('j1', 'page_view', []);
        $journey->recordStep('j2', 'page_view', []);
        $journey->recordStep('j3', 'page_view', []);

        // j1 should be evicted
        expect($journey->getJourney('j1'))->toBeNull();
        expect($journey->getJourney('j2'))->not->toBeNull();
        expect($journey->getJourney('j3'))->not->toBeNull();
    });
});

// ─── AnomalyDetectionService ──────────────────────────────────────────────

describe('AnomalyDetectionService', function (): void {
    beforeEach(function (): void {
        $this->anomaly = new AnomalyDetectionService(
            $this->manager,
            $this->metrics,
            $this->queue,
            60,
            2.0,
            3, // low minDataPoints for testing
        );
    });

    test('returns null stats when no data', function (): void {
        $stats = $this->anomaly->stats('purchase');

        expect($stats['mean'])->toBe(0.0);
        expect($stats['baseline_windows'])->toBe(0);
        expect($stats['is_anomaly'])->toBeFalse();
    });

    test('records events and detects anomalies', function (): void {
        // Establish baseline: 3 windows with ~5 events each
        for ($w = 0; $w < 3; $w++) {
            for ($i = 0; $i < 5; $i++) {
                $this->anomaly->record('page_view', time());
            }
            $this->anomaly->rotateAndCheck();
        }

        // Current window should have ~5 events as baseline
        $stats = $this->anomaly->stats('page_view');
        expect($stats['mean'])->toBe(5.0);

        // Now record a spike: 20 events (well above 2σ from mean=5)
        for ($i = 0; $i < 20; $i++) {
            $this->anomaly->record('page_view', time());
        }

        $anomalies = $this->anomaly->rotateAndCheck();

        // Should detect the spike as anomaly
        expect(count($anomalies))->toBeGreaterThan(0);
        expect($anomalies[0]['event'])->toBe('page_view');
        expect($anomalies[0]['severity'])->toBeIn(['warning', 'elevated', 'critical']);
    });

    test('dispatches alert events for anomalies', function (): void {
        $anomaly = new AnomalyDetectionService(
            $this->manager,
            $this->metrics,
            $this->queue,
            60,
            0.5, // very sensitive for testing
            1,
            dispatchAlerts: true,
        );

        // Baseline
        for ($i = 0; $i < 5; $i++) {
            $anomaly->record('click', time());
        }
        $anomaly->rotateAndCheck();

        // Spike
        for ($i = 0; $i < 50; $i++) {
            $anomaly->record('click', time());
        }

        $this->queue->shouldReceive('dispatch')
            ->atLeastOnce()
            ->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'analytics_anomaly_detected';
            });

        $anomaly->rotateAndCheck();
    });

    test('provides anomaly summary', function (): void {
        $summary = $this->anomaly->summary();

        expect($summary)->toHaveKey('tracked_events');
        expect($summary)->toHaveKey('total_anomalies');
        expect($summary)->toHaveKey('window_size');
        expect($summary['sensitivity'])->toBe(2.0);
    });

    test('flushes all data', function (): void {
        $this->anomaly->record('test', time());
        $this->anomaly->flush();

        $stats = $this->anomaly->stats('test');
        expect($stats['baseline_windows'])->toBe(0);
    });
});

// ─── EventStreamService ────────────────────────────────────────────────────

describe('EventStreamService', function (): void {
    beforeEach(function (): void {
        $this->stream = new EventStreamService($this->manager, $this->metrics, 100);
    });

    test('pushes and retrieves events', function (): void {
        $this->stream->push('page_view', ['page' => '/home'], 'c1', 'u1', 'ga4');

        $events = $this->stream->since(0);

        expect($events)->toHaveCount(1);
        expect($events[0]['event'])->toBe('page_view');
        expect($events[0]['client_id'])->toBe('c1');
        expect($events[0]['provider'])->toBe('ga4');
    });

    test('cursor-based polling works', function (): void {
        $this->stream->push('event_a', []);
        $this->stream->push('event_b', []);
        $this->stream->push('event_c', []);

        expect($this->stream->cursor())->toBe(3);

        $events = $this->stream->since(2);
        expect($events)->toHaveCount(1);
        expect($events[0]['event'])->toBe('event_c');
    });

    test('returns empty when cursor is current', function (): void {
        $this->stream->push('event_a', []);

        $events = $this->stream->since(1);
        expect($events)->toBe([]);
    });

    test('filters by event name', function (): void {
        $this->stream->push('page_view', []);
        $this->stream->push('purchase', []);
        $this->stream->push('page_view', []);

        $filtered = $this->stream->filter('page_view');

        expect($filtered)->toHaveCount(2);
    });

    test('filters by wildcard pattern', function (): void {
        $this->stream->push('page_view', []);
        $this->stream->push('page_scroll', []);
        $this->stream->push('purchase', []);

        $filtered = $this->stream->filter('page_*');

        expect($filtered)->toHaveCount(2);
    });

    test('evicts oldest events when buffer is full', function (): void {
        $stream = new EventStreamService($this->manager, $this->metrics, 3);

        $stream->push('event_1', []);
        $stream->push('event_2', []);
        $stream->push('event_3', []);
        $stream->push('event_4', []);

        $all = $stream->since(0);
        expect($all)->toHaveCount(3);
        expect($all[0]['event'])->toBe('event_2');
    });

    test('sanitizes sensitive parameters', function (): void {
        $this->stream->push('login', [
            'email' => 'user@example.com',
            'password' => 'secret123',
            'normal_field' => 'hello',
        ]);

        $events = $this->stream->since(0);

        expect($events[0]['params']['password'])->toBe('[REDACTED]');
        expect($events[0]['params']['normal_field'])->toBe('hello');
    });

    test('provides stream stats', function (): void {
        $this->stream->push('page_view', []);
        $this->stream->push('page_view', []);
        $this->stream->push('purchase', [], null, null, 'ga4');

        $stats = $this->stream->stats();

        expect($stats['buffered'])->toBe(3);
        expect($stats['event_types'])->toBe(2);
        expect($stats['by_provider']['ga4'])->toBe(1);
    });

    test('flushes buffer and resets cursor', function (): void {
        $this->stream->push('event_1', []);
        $this->stream->flush();

        expect($this->stream->bufferedCount())->toBe(0);
        expect($this->stream->cursor())->toBe(0);
    });
});

// ─── ExportService ──────────────────────────────────────────────────────────

describe('ExportService', function (): void {
    beforeEach(function (): void {
        $this->stream = new EventStreamService($this->manager, $this->metrics, 100);
        $this->export = new ExportService($this->manager, $this->metrics, $this->stream);

        // Push some events for export
        $this->stream->push('page_view', ['page' => '/home'], 'c1', 'u1', 'ga4');
        $this->stream->push('purchase', ['value' => 99.99, 'currency' => 'USD'], 'c1', 'u1', 'ga4');
        $this->stream->push('signup', ['method' => 'email'], 'c2', null, 'meta');
    });

    test('exports as JSON', function (): void {
        $json = $this->export->toJson();

        $data = json_decode($json, true);

        expect($data)->toBeArray();
        expect(count($data))->toBe(3);
        expect($data[0]['event'])->toBe('page_view');
        expect($data[1]['event'])->toBe('purchase');
    });

    test('exports as CSV', function (): void {
        $csv = $this->export->toCsv();

        $lines = explode("\n", $csv);

        expect(count($lines))->toBe(4); // header + 3 rows
        expect($lines[0])->toContain('id,event,category');
        expect($lines[1])->toContain('page_view');
    });

    test('exports with event filter', function (): void {
        $json = $this->export->toJson('page_view');

        $data = json_decode($json, true);

        expect(count($data))->toBe(1);
        expect($data[0]['event'])->toBe('page_view');
    });

    test('exports metrics summary', function (): void {
        $json = $this->export->metricsExport();

        $data = json_decode($json, true);

        expect($data)->toHaveKey('generated_at');
        expect($data)->toHaveKey('version');
        expect($data)->toHaveKey('metrics');
        expect($data)->toHaveKey('providers');
    });

    test('compliance export redacts PII', function (): void {
        $json = $this->export->complianceExport();

        $data = json_decode($json, true);

        expect(count($data))->toBe(3);

        // Check PII is redacted
        foreach ($data as $event) {
            if ($event['user_id_hash'] !== null) {
                expect($event['user_id_hash'])->toBeString();
                expect($event)->not->toHaveKey('user_id');
            }
        }
    });
});
