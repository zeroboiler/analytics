<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Pipeline\EventClassificationEnricher;
use ZeroBoiler\Analytics\Services\EventSessionizer;
use ZeroBoiler\Analytics\Services\EventFunnelAggregator;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->cache->shouldReceive('get')->andReturn(null);
    $this->cache->shouldReceive('put')->andReturn(true);
    $this->cache->shouldReceive('forget')->andReturn(true);
});

describe('EventSessionizer', function (): void {
    test('records event and returns session metadata', function (): void {
        $sessionizer = new EventSessionizer($this->cache, [
            'session_ttl' => 1800,
            'max_sessions_per_client' => 10,
        ]);

        $event = AnalyticsEvent::make('page_view', [
            'client_id' => 'test-client-123',
            'session_id' => 'session-abc',
        ]);

        $result = $sessionizer->record($event);

        expect($result)->toHaveKeys(['session_id', 'event_count', 'unique_events', 'duration_estimate', 'engagement_score']);
        expect($result['session_id'])->toBe('session-abc');
        expect($result['event_count'])->toBe(1);
        expect($result['unique_events'])->toBe(1);
    });

    test('tracks unique events correctly across multiple records', function (): void {
        $sessionData = null;

        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key) use (&$sessionData): ?array {
                if (str_contains($key, 'session_test-client')) {
                    return $sessionData;
                }
                return null;
            });

        $this->cache->shouldReceive('put')
            ->andReturnUsing(function (string $key, array $data) use (&$sessionData): bool {
                if (str_contains($key, 'session_test-client')) {
                    $sessionData = $data;
                }
                return true;
            });

        $sessionizer = new EventSessionizer($this->cache, [
            'session_ttl' => 1800,
            'max_sessions_per_client' => 10,
        ]);

        $event1 = AnalyticsEvent::make('page_view', ['client_id' => 'test-client', 'session_id' => 'session-1']);
        $sessionizer->record($event1);

        $event2 = AnalyticsEvent::make('click', ['client_id' => 'test-client', 'session_id' => 'session-1']);
        $result2 = $sessionizer->record($event2);

        expect($result2['event_count'])->toBe(2);
        expect($result2['unique_events'])->toBe(2);
    });

    test('identifies conversion events', function (): void {
        $sessionData = null;

        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key) use (&$sessionData): ?array {
                if (str_contains($key, 'session_test-client')) {
                    return $sessionData;
                }
                return null;
            });

        $this->cache->shouldReceive('put')
            ->andReturnUsing(function (string $key, array $data) use (&$sessionData): bool {
                if (str_contains($key, 'session_test-client')) {
                    $sessionData = $data;
                }
                return true;
            });

        $sessionizer = new EventSessionizer($this->cache, [
            'session_ttl' => 1800,
            'max_sessions_per_client' => 10,
        ]);

        $purchaseEvent = AnalyticsEvent::make('purchase', [
            'client_id' => 'test-client',
            'session_id' => 'session-1',
            'value' => 99.99,
        ]);

        $result = $sessionizer->record($purchaseEvent);

        // Purchase is a conversion event → engagement bonus
        expect($result['engagement_score'])->toBeGreaterThanOrEqual(20.0);
    });

    test('aggregateStats returns empty for unknown client', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_session_index:unknown-client')
            ->andReturn(null);

        $sessionizer = new EventSessionizer($this->cache, [
            'session_ttl' => 1800,
            'max_sessions_per_client' => 10,
        ]);

        $stats = $sessionizer->aggregateStats('unknown-client');

        expect($stats['total_sessions'])->toBe(0);
        expect($stats['total_events'])->toBe(0);
        expect($stats['conversion_rate'])->toBe(0.0);
    });

    test('endSession returns null for non-existent session', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_session_client:nonexistent')
            ->andReturn(null);

        $sessionizer = new EventSessionizer($this->cache, [
            'session_ttl' => 1800,
            'max_sessions_per_client' => 10,
        ]);

        $result = $sessionizer->endSession('client', 'nonexistent');

        expect($result)->toBeNull();
    });
});

describe('EventFunnelAggregator', function (): void {
    test('records event in signup funnel', function (): void {
        $aggregator = new EventFunnelAggregator($this->cache, [
            'cache_ttl' => 300,
        ]);

        $result = $aggregator->record('sign_up', 'user-123');

        expect($result['progress'])->toHaveKey('signup');
        expect($result['progress']['signup']['step_index'])->toBe(1); // sign_up is step index 1 (page_view=0)
        expect($result['progress']['signup']['total_steps'])->toBe(3);
    });

    test('tracks funnel progress across events', function (): void {
        $trackerData = [];

        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key) use (&$trackerData): ?array {
                if (str_contains($key, 'zb_funnel_signup:')) {
                    return $trackerData['signup'] ?? null;
                }
                if (str_contains($key, 'zb_funnel_report:')) {
                    return $trackerData['report'] ?? null;
                }
                return null;
            });

        $this->cache->shouldReceive('put')
            ->andReturnUsing(function (string $key, array $data) use (&$trackerData): bool {
                if (str_contains($key, 'zb_funnel_signup:')) {
                    $trackerData['signup'] = $data;
                }
                if (str_contains($key, 'zb_funnel_report:')) {
                    $trackerData['report'] = $data;
                }
                return true;
            });

        $aggregator = new EventFunnelAggregator($this->cache, [
            'cache_ttl' => 300,
        ]);

        // Step 0: page_view
        $r1 = $aggregator->record('page_view', 'user-1');
        expect($r1['progress']['signup']['step_index'])->toBe(0);
        expect($r1['progress']['signup']['steps_completed'])->toBe(1);

        // Step 1: sign_up
        $r2 = $aggregator->record('sign_up', 'user-1');
        expect($r2['progress']['signup']['step_index'])->toBe(1);
        expect($r2['progress']['signup']['steps_completed'])->toBe(2);
        expect($r2['progress']['signup']['percentage'])->toBe(round((2 / 3) * 100, 2));
    });

    test('getFunnelReport returns null for unknown funnel', function (): void {
        $aggregator = new EventFunnelAggregator($this->cache, [
            'cache_ttl' => 300,
        ]);

        expect($aggregator->getFunnelReport('nonexistent'))->toBeNull();
    });

    test('hasFunnel returns true for built-in funnels', function (): void {
        $aggregator = new EventFunnelAggregator($this->cache, [
            'cache_ttl' => 300,
        ]);

        expect($aggregator->hasFunnel('signup'))->toBeTrue();
        expect($aggregator->hasFunnel('purchase'))->toBeTrue();
        expect($aggregator->hasFunnel('subscription'))->toBeTrue();
        expect($aggregator->hasFunnel('activation'))->toBeTrue();
        expect($aggregator->hasFunnel('expansion'))->toBeTrue();
        expect($aggregator->hasFunnel('nonexistent'))->toBeFalse();
    });

    test('getDefinedFunnels returns all built-in and custom funnels', function (): void {
        $aggregator = new EventFunnelAggregator($this->cache, [
            'cache_ttl' => 300,
            'funnels' => [
                'custom_onboarding' => [
                    'steps' => ['sign_up', 'tutorial_completed', 'first_project'],
                    'conversion_event' => 'first_project',
                    'time_window' => 604800,
                ],
            ],
        ]);

        $funnels = $aggregator->getDefinedFunnels();

        expect($funnels)->toHaveKey('signup');
        expect($funnels)->toHaveKey('purchase');
        expect($funnels)->toHaveKey('custom_onboarding');
        expect($funnels['custom_onboarding']['conversion_event'])->toBe('first_project');
    });

    test('getAllFunnelReports returns all funnel summaries', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn(null);

        $aggregator = new EventFunnelAggregator($this->cache, [
            'cache_ttl' => 300,
        ]);

        $reports = $aggregator->getAllFunnelReports();

        expect($reports)->toHaveKey('signup');
        expect($reports)->toHaveKey('purchase');
        expect($reports)->toHaveKey('subscription');
        expect($reports)->toHaveKey('activation');
        expect($reports)->toHaveKey('expansion');

        // Empty funnel reports should have 0% conversion
        expect($reports['signup']['overall_conversion_rate'])->toBe(0.0);
        expect($reports['signup']['total_entered'])->toBe(0);
    });

    test('getFunnelReport returns empty report for funnel with no data', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn(null);

        $aggregator = new EventFunnelAggregator($this->cache, [
            'cache_ttl' => 300,
        ]);

        $report = $aggregator->getFunnelReport('signup');

        expect($report)->not->toBeNull();
        expect($report['funnel'])->toBe('signup');
        expect($report['overall_conversion_rate'])->toBe(0.0);
        expect($report['total_entered'])->toBe(0);
        expect(count($report['steps']))->toBe(3); // page_view, sign_up, email_verified
    });
});

describe('EventClassificationEnricher', function (): void {
    test('enriches known catalog events with metadata', function (): void {
        $enricher = new EventClassificationEnricher();

        $event = AnalyticsEvent::make('purchase', ['value' => 99.99]);
        $enriched = $enricher->enrich($event);

        expect($enriched->param('_zb_category'))->toBe('ecommerce');
        expect($enriched->param('_zb_known'))->toBe(true);
        expect($enriched->param('_zb_priority'))->toBe('critical');
        expect($enriched->param('_zb_provider_map'))->toBeArray();
        expect($enriched->param('_zb_provider_map')['ga4'])->toBe('purchase');
        expect($enriched->param('_zb_provider_map')['meta'])->toBe('Purchase');
        expect($enriched->param('_zb_event_class'))->toBeString();
    });

    test('enriches unknown events as custom with inferred priority', function (): void {
        $enricher = new EventClassificationEnricher();

        $event = AnalyticsEvent::make('custom_button_click', []);
        $enriched = $enricher->enrich($event);

        expect($enriched->param('_zb_category'))->toBe('custom');
        expect($enriched->param('_zb_known'))->toBe(false);
        expect($enriched->param('_zb_priority'))->toBe('normal');
    });

    test('infers high priority for custom revenue events', function (): void {
        $enricher = new EventClassificationEnricher();

        $event = AnalyticsEvent::make('my_custom_payment_processed', []);
        $enriched = $enricher->enrich($event);

        expect($enriched->param('_zb_priority'))->toBe('high');
    });

    test('infers low priority for custom tracking events', function (): void {
        $enricher = new EventClassificationEnricher();

        $event = AnalyticsEvent::make('heartbeat_ping', []);
        $enriched = $enricher->enrich($event);

        expect($enriched->param('_zb_priority'))->toBe('low');
    });

    test('enriches SaaS events with correct category and priority', function (): void {
        $enricher = new EventClassificationEnricher();

        $event = AnalyticsEvent::make('plan_upgrade', ['from_plan' => 'starter', 'to_plan' => 'pro']);
        $enriched = $enricher->enrich($event);

        expect($enriched->param('_zb_category'))->toBe('saas');
        expect($enriched->param('_zb_priority'))->toBe('high');
    });

    test('enriches engagement events with correct category', function (): void {
        $enricher = new EventClassificationEnricher();

        $event = AnalyticsEvent::make('scroll_depth', ['percent' => 75]);
        $enriched = $enricher->enrich($event);

        expect($enriched->param('_zb_category'))->toBe('engagement');
        expect($enriched->param('_zb_priority'))->toBe('low');
    });

    test('batch enrich works correctly', function (): void {
        $enricher = new EventClassificationEnricher();

        $events = [
            AnalyticsEvent::make('purchase', []),
            AnalyticsEvent::make('custom_event', []),
            AnalyticsEvent::make('sign_up', []),
        ];

        $enriched = $enricher->enrichBatch($events);

        expect(count($enriched))->toBe(3);
        expect($enriched[0]->param('_zb_category'))->toBe('ecommerce');
        expect($enriched[1]->param('_zb_category'))->toBe('custom');
        expect($enriched[2]->param('_zb_category'))->toBe('saas');
    });

    test('getEventPriority returns correct priorities', function (): void {
        $enricher = new EventClassificationEnricher();

        expect($enricher->getEventPriority('purchase'))->toBe('critical');
        expect($enricher->getEventPriority('sign_up'))->toBe('critical');
        expect($enricher->getEventPriority('payment_succeeded'))->toBe('critical');
        expect($enricher->getEventPriority('page_view'))->toBe('low');
        expect($enricher->getEventPriority('scroll_depth'))->toBe('low');
        expect($enricher->getEventPriority('login'))->toBe('normal');
    });
});

describe('v8.0.0 Integration', function (): void {
    test('sessionizer + funnel aggregator + classifier work together', function (): void {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);
        $cache->shouldReceive('forget')->andReturn(true);

        $sessionizer = new EventSessionizer($cache, [
            'session_ttl' => 1800,
            'max_sessions_per_client' => 10,
        ]);

        $funnelAggregator = new EventFunnelAggregator($cache, [
            'cache_ttl' => 300,
        ]);

        $classifier = new EventClassificationEnricher();

        // Simulate a user journey: page_view → sign_up → start_trial
        $events = [
            AnalyticsEvent::make('page_view', ['client_id' => 'u1', 'session_id' => 's1']),
            AnalyticsEvent::make('sign_up', ['client_id' => 'u1', 'session_id' => 's1']),
            AnalyticsEvent::make('start_trial', ['client_id' => 'u1', 'session_id' => 's1']),
        ];

        foreach ($events as $event) {
            $sessionizer->record($event);
            $funnelAggregator->record($event->name(), 'u1');
            $classifier->enrich($event);
        }

        // Verify session stats
        $stats = $sessionizer->aggregateStats('u1');
        expect($stats['total_events'])->toBe(3);

        // Verify funnel progress
        $activationReport = $funnelAggregator->getFunnelReport('activation');
        expect($activationReport)->not->toBeNull();
        expect($activationReport['steps'])->toHaveCount(3);
    });
});
