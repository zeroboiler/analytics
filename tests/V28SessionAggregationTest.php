<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\EventReplayQueue;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\EventAggregationService;
use ZeroBoiler\Analytics\Services\SessionAnalyticsService;

beforeEach(function () {
    $this->manager = new AnalyticsManager(
        new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                    'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => '', 'base_url' => 'https://plausible.io/api/event'],
                    'posthog' => ['enabled' => false, 'api_key' => '', 'host' => 'https://eu.posthog.com', 'project_id' => ''],
                    'webhook' => ['enabled' => false, 'url' => '', 'secret' => '', 'timeout' => 5, 'retries' => 1, 'sign' => false, 'headers' => []],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => true, 'log_events' => false],
                ],
            ],
        ]),
    );

    $this->metrics = new AnalyticsMetrics(
        new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'metrics' => ['enabled' => true],
                ],
            ],
        ]),
    );

    $this->config = new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                'gtm' => ['enabled' => false, 'container_id' => ''],
                'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => ''],
                'posthog' => ['enabled' => false, 'api_key' => '', 'host' => ''],
                'webhook' => ['enabled' => false, 'url' => '', 'secret' => ''],
                'consent' => ['default' => 'granted'],
                'queue' => ['enabled' => true, 'queue' => 'analytics'],
                'replay' => ['enabled' => true, 'max_attempts' => 3],
                'validation' => ['strict' => false, 'deduplication_window' => 10],
                'sampling' => ['enabled' => false, 'rate' => 1.0],
                'pii_sanitization' => ['enabled' => false, 'strategy' => 'hash'],
            ],
        ],
    ]);

    $this->replayQueue = new EventReplayQueue($this->manager, $this->metrics, $this->config);
});

describe('SessionAnalyticsService', function () {
    beforeEach(function () {
        $dispatcher = new class ($this->manager, $this->config) extends QueuedAnalyticsDispatcher {
            /** @var list<AnalyticsEvent> */
            public array $dispatched = [];

            public function dispatch(AnalyticsEvent $event): void
            {
                $this->dispatched[] = $event;
            }
        };

        $this->sessionService = new SessionAnalyticsService(
            $this->manager,
            $dispatcher,
            false, // sync
            100,
        );
    });

    test('records events in a session', function () {
        $this->sessionService->recordEvent('sess-1', 'page_view');
        $this->sessionService->recordEvent('sess-1', 'click');
        $this->sessionService->recordEvent('sess-1', 'page_view');

        $summary = $this->sessionService->getSessionSummary('sess-1');

        expect($summary)->not->toBeNull();
        expect($summary['event_count'])->toBe(3);
        expect($summary['page_count'])->toBe(2);
        expect($summary['unique_events'])->toBe(2);
        expect($summary['event_types'])->toContain('page_view');
        expect($summary['event_types'])->toContain('click');
    });

    test('returns null for unknown session', function () {
        expect($this->sessionService->getSessionSummary('nonexistent'))->toBeNull();
    });

    test('tracks user_id and client_id', function () {
        $this->sessionService->recordEvent('sess-2', 'page_view', [], 'user-42', 'client-abc');

        $summary = $this->sessionService->getSessionSummary('sess-2');

        expect($summary['user_id'])->toBe('user-42');
        expect($summary['client_id'])->toBe('client-abc');
    });

    test('updates user_id on subsequent events', function () {
        $this->sessionService->recordEvent('sess-3', 'page_view', [], null, 'client-xyz');
        $this->sessionService->recordEvent('sess-3', 'login', [], 'user-99', 'client-xyz');

        $summary = $this->sessionService->getSessionSummary('sess-3');

        expect($summary['user_id'])->toBe('user-99');
        expect($summary['client_id'])->toBe('client-xyz');
    });

    test('counts tracked sessions', function () {
        $this->sessionService->recordEvent('sess-a', 'page_view');
        $this->sessionService->recordEvent('sess-b', 'click');
        $this->sessionService->recordEvent('sess-c', 'search');

        expect($this->sessionService->trackedSessionCount())->toBe(3);
    });

    test('flush clears all sessions', function () {
        $this->sessionService->recordEvent('sess-1', 'page_view');
        $this->sessionService->recordEvent('sess-2', 'click');

        $this->sessionService->flush();

        expect($this->sessionService->trackedSessionCount())->toBe(0);
        expect($this->sessionService->getSessionSummary('sess-1'))->toBeNull();
    });

    test('endSession removes session from tracking', function () {
        $this->sessionService->recordEvent('sess-end', 'page_view', [], 'user-1');

        $this->sessionService->endSession('sess-end');

        expect($this->sessionService->trackedSessionCount())->toBe(0);
        expect($this->sessionService->getSessionSummary('sess-end'))->toBeNull();
    });

    test('endSession dispatches summary event', function () {
        $dispatcher = new class ($this->manager, $this->config) extends QueuedAnalyticsDispatcher {
            /** @var list<AnalyticsEvent> */
            public array $dispatched = [];

            public function dispatch(AnalyticsEvent $event): void
            {
                $this->dispatched[] = $event;
            }
        };

        $service = new SessionAnalyticsService($this->manager, $dispatcher, false, 100);
        $service->recordEvent('sess-dispatch', 'page_view', [], 'user-55');
        $service->recordEvent('sess-dispatch', 'click');

        $service->endSession('sess-dispatch');

        expect($dispatcher->dispatched)->toHaveCount(1);
        expect($dispatcher->dispatched[0]->name)->toBe('analytics_session_summary');
        expect($dispatcher->dispatched[0]->params['session_event_count'])->toBe(2);
        expect($dispatcher->dispatched[0]->params['session_page_count'])->toBe(1);
        expect($dispatcher->dispatched[0]->userId)->toBe('user-55');
    });

    test('evicts oldest session when max is reached', function () {
        $service = new SessionAnalyticsService($this->manager, new class ($this->manager, $this->config) extends QueuedAnalyticsDispatcher {
            public function dispatch(AnalyticsEvent $event): void {}
        }, false, 3);

        $service->recordEvent('s1', 'page_view');
        $service->recordEvent('s2', 'click');
        $service->recordEvent('s3', 'search');

        expect($service->trackedSessionCount())->toBe(3);

        // This should evict s1
        $service->recordEvent('s4', 'page_view');

        expect($service->trackedSessionCount())->toBe(3);
        expect($service->getSessionSummary('s1'))->toBeNull();
        expect($service->getSessionSummary('s4'))->not->toBeNull();
    });

    test('getAggregatedStats returns correct totals', function () {
        $this->sessionService->recordEvent('s1', 'page_view', [], 'user-1');
        $this->sessionService->recordEvent('s1', 'click');
        $this->sessionService->recordEvent('s2', 'page_view');

        $stats = $this->sessionService->getAggregatedStats();

        expect($stats['total_sessions'])->toBe(2);
        expect($stats['total_events'])->toBe(3);
        expect($stats['total_page_views'])->toBe(2);
        expect($stats['avg_events_per_session'])->toBe(1.5);
        expect($stats['sessions_with_identity'])->toBe(1);
        expect($stats['sessions_anonymous'])->toBe(1);
        expect($stats['event_frequency']['page_view'])->toBe(2);
        expect($stats['event_frequency']['click'])->toBe(1);
    });
});

describe('EventAggregationService', function () {
    beforeEach(function () {
        $this->aggregation = new EventAggregationService(
            $this->manager,
            $this->metrics,
            $this->replayQueue,
            $this->config,
        );
    });

    test('records events and counts', function () {
        $this->aggregation->record('page_view');
        $this->aggregation->record('page_view');
        $this->aggregation->record('click');

        expect($this->aggregation->totalTracked())->toBe(3);
        expect($this->aggregation->countFor('page_view'))->toBe(2);
        expect($this->aggregation->countFor('click'))->toBe(1);
        expect($this->aggregation->countFor('nonexistent'))->toBe(0);
    });

    test('records with category grouping', function () {
        $this->aggregation->record('page_view', 'engagement');
        $this->aggregation->record('click', 'engagement');
        $this->aggregation->record('purchase', 'ecommerce');

        $byCategory = $this->aggregation->byCategory();

        expect($byCategory['engagement']['page_view'])->toBe(1);
        expect($byCategory['engagement']['click'])->toBe(1);
        expect($byCategory['ecommerce']['purchase'])->toBe(1);
    });

    test('topEvents returns sorted by count', function () {
        $this->aggregation->record('click');
        $this->aggregation->record('click');
        $this->aggregation->record('click');
        $this->aggregation->record('page_view');
        $this->aggregation->record('page_view');
        $this->aggregation->record('search');

        $top = $this->aggregation->topEvents(3);

        expect($top)->toHaveCount(3);
        expect($top[0]['event'])->toBe('click');
        expect($top[0]['count'])->toBe(3);
        expect($top[1]['event'])->toBe('page_view');
        expect($top[1]['count'])->toBe(2);
        expect($top[2]['event'])->toBe('search');
        expect($top[2]['count'])->toBe(1);
    });

    test('rotate clears counts', function () {
        $this->aggregation->record('page_view');
        $this->aggregation->record('click');

        $this->aggregation->rotate();

        expect($this->aggregation->totalTracked())->toBe(0);
        expect($this->aggregation->countFor('page_view'))->toBe(0);
        expect($this->aggregation->allCounts())->toBe([]);
    });

    test('allCounts returns all event counts', function () {
        $this->aggregation->record('a');
        $this->aggregation->record('b');
        $this->aggregation->record('a');

        $counts = $this->aggregation->allCounts();

        expect($counts)->toBe(['a' => 2, 'b' => 1]);
    });

    test('window size rotation works', function () {
        $aggregation = new EventAggregationService(
            $this->manager,
            $this->metrics,
            $this->replayQueue,
            $this->config,
            5, // window size of 5
        );

        for ($i = 0; $i < 5; $i++) {
            $aggregation->record('event_' . $i);
        }

        expect($aggregation->totalTracked())->toBe(5);

        // The 6th event should trigger rotation
        $aggregation->record('event_5');

        // After rotation, only the 6th event is tracked
        expect($aggregation->totalTracked())->toBe(1);
        expect($aggregation->countFor('event_5'))->toBe(1);
        expect($aggregation->countFor('event_0'))->toBe(0);
    });
});

describe('EventAggregationService — healthReport', function () {
    beforeEach(function () {
        $this->aggregation = new EventAggregationService(
            $this->manager,
            $this->metrics,
            $this->replayQueue,
            $this->config,
        );
    });

    test('returns healthy status when providers are disabled', function () {
        $report = $this->aggregation->healthReport();

        expect($report['status'])->toBe('warning'); // No provider enabled = warning
        expect($report['version'])->toBe('2.8.0');
        expect($report['warnings'])->toContain('No analytics providers are enabled');
        expect($report['recommendations'])->not->toBeEmpty();
    });

    test('includes provider health checks', function () {
        $report = $this->aggregation->healthReport();

        expect($report['providers'])->toHaveKey('ga4');
        expect($report['providers'])->toHaveKey('gtm');
        expect($report['providers'])->toHaveKey('meta_pixel');
        expect($report['providers'])->toHaveKey('plausible');
        expect($report['providers'])->toHaveKey('posthog');
        expect($report['providers'])->toHaveKey('webhook');

        foreach ($report['providers'] as $health) {
            expect($health)->toHaveKeys(['enabled', 'configured']);
        }
    });

    test('includes all sections', function () {
        $report = $this->aggregation->healthReport();

        expect($report)->toHaveKeys([
            'status',
            'providers',
            'queue',
            'replay',
            'consent',
            'validation',
            'sampling',
            'pii',
            'metrics',
            'catalog',
            'aggregation',
            'warnings',
            'recommendations',
            'version',
        ]);
    });

    test('detects sampling rate below threshold', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                    'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => ''],
                    'posthog' => ['enabled' => false, 'api_key' => '', 'host' => ''],
                    'webhook' => ['enabled' => false, 'url' => '', 'secret' => ''],
                    'consent' => ['default' => 'granted'],
                    'queue' => ['enabled' => true],
                    'replay' => ['enabled' => true, 'max_attempts' => 3],
                    'validation' => ['strict' => false, 'deduplication_window' => 10],
                    'sampling' => ['enabled' => true, 'rate' => 0.3],
                    'pii_sanitization' => ['enabled' => false, 'strategy' => 'hash'],
                ],
            ],
        ]);

        $aggregation = new EventAggregationService(
            $this->manager,
            $this->metrics,
            $this->replayQueue,
            $config,
        );

        $report = $aggregation->healthReport();

        expect($report['status'])->toBe('warning');
        expect($report['warnings'])->toContain('Sampling rate is below 50% — significant data loss');
    });

    test('detects enabled but unconfigured provider', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => true, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                    'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => ''],
                    'posthog' => ['enabled' => false, 'api_key' => '', 'host' => ''],
                    'webhook' => ['enabled' => false, 'url' => '', 'secret' => ''],
                    'consent' => ['default' => 'granted'],
                    'queue' => ['enabled' => true],
                    'replay' => ['enabled' => true, 'max_attempts' => 3],
                    'validation' => ['strict' => false],
                    'sampling' => ['enabled' => false, 'rate' => 1.0],
                    'pii_sanitization' => ['enabled' => true, 'strategy' => 'hash'],
                ],
            ],
        ]);

        $aggregation = new EventAggregationService(
            $this->manager,
            $this->metrics,
            $this->replayQueue,
            $config,
        );

        $report = $aggregation->healthReport();

        expect($report['status'])->toBe('warning');
        expect($report['warnings'])->toContain('ga4 is enabled but not fully configured');
    });
});

describe('Version consistency', function () {
    test('all version strings are 2.8.0', function () {
        $manager = new AnalyticsManager(new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                    'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => '', 'base_url' => 'https://plausible.io/api/event'],
                    'posthog' => ['enabled' => false, 'api_key' => '', 'host' => 'https://eu.posthog.com', 'project_id' => ''],
                    'webhook' => ['enabled' => false, 'url' => '', 'secret' => '', 'timeout' => 5, 'retries' => 1, 'sign' => false, 'headers' => []],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => true, 'log_events' => false],
                ],
            ],
        ]));

        expect($manager->version())->toBe('2.94.0');
    });
});
