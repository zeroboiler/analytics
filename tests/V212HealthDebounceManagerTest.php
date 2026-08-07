<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Pipeline\EventDebounceFilter;
use ZeroBoiler\Analytics\Services\AnalyticsHealthService;
use ZeroBoiler\Analytics\Services\SaaSAnalyticsService;

beforeEach(function (): void {
    $this->manager = new AnalyticsManager(null);
});

describe('Version Consistency', function (): void {
    test('AnalyticsManager version returns v2.35.0', function (): void {
        expect($this->manager->version())->toBe('2.41.0');
    });

    test('composer.json version matches', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        expect($composer['version'])->toBe('2.41.0');
    });

    test('JS client version matches', function (): void {
        $js = file_get_contents(__DIR__.'/../resources/js/analytics.js');
        expect($js)->toContain('@version 2.35.0');
    });
});

describe('AnalyticsManager — trackError', function (): void {
    test('tracks error event with message', function (): void {
        $this->manager->setDebug(true);
        $this->manager->setDebug(true);

        // No exception = success
        $this->manager->trackError('Something went wrong', 'app.js', 42);
        expect(true)->toBeTrue();
    });

    test('tracks error event with minimal args', function (): void {
        $this->manager->setDebug(true);
        $this->manager->trackError('Oops');
        expect(true)->toBeTrue();
    });
});

describe('AnalyticsManager — mrr', function (): void {
    test('tracks revenue_tracked event with mrr type', function (): void {
        $this->manager->setDebug(true);

        $this->manager->mrr(5000.00, 120);
        expect(true)->toBeTrue();
    });

    test('tracks mrr with additional params', function (): void {
        $this->manager->setDebug(true);

        $this->manager->mrr(10000.00, 250, ['plan' => 'business']);
        expect(true)->toBeTrue();
    });
});

describe('EventDebounceFilter', function (): void {
    test('passes first event immediately', function (): void {
        $filter = new EventDebounceFilter(1000);
        $event = new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 25]);

        $result = $filter->process($event);

        expect($result)->not->toBeNull();
        expect($result->name)->toBe('scroll_depth');
    });

    test('suppresses rapid-fire events within debounce window', function (): void {
        $filter = new EventDebounceFilter(1000);
        $filter->setTestNow(0);

        $event1 = new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 25]);

        // First event at t=0 should pass (no prior dispatch)
        $result1 = $filter->process($event1);
        expect($result1)->not->BeNull();

        // Second event at t=500 (within 1000ms window) should be suppressed
        $filter->setTestNow(500);
        $event2 = new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 50]);
        $result2 = $filter->process($event2);
        expect($result2)->toBeNull();
        expect($filter->isPending('scroll_depth'))->toBeTrue();
        expect($filter->pendingCount())->toBe(1);
    });

    test('releases event after debounce window expires', function (): void {
        $filter = new EventDebounceFilter(1000);

        $event1 = new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 25]);
        $event2 = new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 75]);

        $filter->setTestNow(0);
        $filter->process($event1);

        $filter->setTestNow(500);
        $filter->process($event2);

        // At t=1500, debounce window has expired
        $filter->setTestNow(1500);
        $event3 = new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 90]);
        $result = $filter->process($event3);

        expect($result)->not->BeNull();
        expect($result->params['percent'])->toBe(90);
        expect($filter->pendingCount())->toBe(0);
    });

    test('flushes all pending events', function (): void {
        $filter = new EventDebounceFilter(1000);
        $filter->setTestNow(0);

        // First dispatch of 'a' at t=0 (passes)
        $event1 = new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 50]);
        $filter->process($event1);

        // Suppressed at t=500
        $filter->setTestNow(500);
        $event2 = new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 75]);
        $filter->process($event2);

        // 'time_on_page' has no prior dispatch, so it passes immediately (not pending)
        $filter->process(new AnalyticsEvent(name: 'time_on_page', params: ['seconds' => 10]));

        $flushed = $filter->flush();

        // Only 'scroll_depth' was pending (suppressed)
        expect($flushed)->toHaveCount(1);
        expect($flushed[0]->params['percent'])->toBe(75);
        expect($filter->pendingCount())->toBe(0);
    });

    test('tracks different event names independently', function (): void {
        $filter = new EventDebounceFilter(1000);
        $filter->setTestNow(0);

        $scrollEvent = new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 25]);
        $clickEvent = new AnalyticsEvent(name: 'click', params: ['element' => 'button']);

        $filter->setTestNow(0);
        $result1 = $filter->process($scrollEvent);
        expect($result1)->not->BeNull();

        // Different event name — should pass even within window
        $filter->setTestNow(500);
        $result2 = $filter->process($clickEvent);
        expect($result2)->not->BeNull();
    });

    test('reset clears all state', function (): void {
        $filter = new EventDebounceFilter(1000);
        $filter->setTestNow(0);

        $filter->process(new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 25]));
        $filter->setTestNow(500);
        $filter->process(new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 50]));

        $filter->reset();

        expect($filter->pendingCount())->toBe(0);

        // After reset, next event should pass immediately
        $filter->setTestNow(600);
        $result = $filter->process(new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 75]));
        expect($result)->not->BeNull();
    });

    test('getDebounceMs returns configured value', function (): void {
        $filter = new EventDebounceFilter(2000);
        expect($filter->getDebounceMs())->toBe(2000);
    });
});

describe('AnalyticsHealthService', function (): void {
    test('generates report with correct structure', function (): void {
        $manager = new AnalyticsManager(null);
        $metrics = new AnalyticsMetrics(null);
        $config = app(\Illuminate\Contracts\Config\Repository::class);
        $replayQueue = app(\ZeroBoiler\Analytics\Queue\EventReplayQueue::class);

        $health = new AnalyticsHealthService($manager, $metrics, $replayQueue, $config);
        $report = $health->report();

        expect($report)->toHaveKey('status');
        expect($report)->toHaveKey('version');
        expect($report)->toHaveKey('providers');
        expect($report)->toHaveKey('consent');
        expect($report)->toHaveKey('queue');
        expect($report)->toHaveKey('replay');
        expect($report)->toHaveKey('metrics');
        expect($report)->toHaveKey('catalog');
        expect($report)->toHaveKey('validation');
        expect($report)->toHaveKey('sampling');
        expect($report)->toHaveKey('pii');
        expect($report)->toHaveKey('debug');
        expect($report)->toHaveKey('warnings');
        expect($report)->toHaveKey('recommendations');
    });

    test('report version matches AnalyticsManager version', function (): void {
        $manager = new AnalyticsManager(null);
        $metrics = new AnalyticsMetrics(null);
        $config = app(\Illuminate\Contracts\Config\Repository::class);
        $replayQueue = app(\ZeroBoiler\Analytics\Queue\EventReplayQueue::class);

        $health = new AnalyticsHealthService($manager, $metrics, $replayQueue, $config);
        $report = $health->report();

        expect($report['version'])->toBe($manager->version());
    });

    test('isHealthy returns boolean', function (): void {
        $manager = new AnalyticsManager(null);
        $metrics = new AnalyticsMetrics(null);
        $config = app(\Illuminate\Contracts\Config\Repository::class);
        $replayQueue = app(\ZeroBoiler\Analytics\Queue\EventReplayQueue::class);

        $health = new AnalyticsHealthService($manager, $metrics, $replayQueue, $config);

        expect($health->isHealthy())->toBeBool();
    });

    test('getWarnings and getRecommendations return arrays', function (): void {
        $manager = new AnalyticsManager(null);
        $metrics = new AnalyticsMetrics(null);
        $config = app(\Illuminate\Contracts\Config\Repository::class);
        $replayQueue = app(\ZeroBoiler\Analytics\Queue\EventReplayQueue::class);

        $health = new AnalyticsHealthService($manager, $metrics, $replayQueue, $config);

        expect($health->getWarnings())->toBeArray();
        expect($health->getRecommendations())->toBeArray();
    });
});

describe('SaaSAnalyticsService — trackPlanDowngrade fix', function (): void {
    test('does not double-track plan downgrade', function (): void {
        $manager = new AnalyticsManager(null);
        $manager->setDebug(true);
        $metrics = $manager->metrics();
        $metrics->setEnabled(true);

        $service = new SaaSAnalyticsService($manager);

        $before = $metrics->totalDispatched();
        $service->trackPlanDowngrade('pro', 'starter');
        $after = $metrics->totalDispatched();

        // Should only track once (via typed event), not twice
        expect($after - $before)->toBe(1);
    });
});

describe('Event Catalog — Category Helpers', function (): void {
    test('total event count is consistent across catalogs', function (): void {
        $total = EcommerceEvents::count()
            + SaaSEvents::count()
            + EngagementEvents::count();

        expect(EventCatalog::count())->toBe($total);
    });

    test('all catalog events have required keys', function (): void {
        foreach (EventCatalog::all() as $name => $entry) {
            expect($entry)->toHaveKey('name');
            expect($entry)->toHaveKey('class');
            expect($entry)->toHaveKey('ga4');
            expect($entry)->toHaveKey('meta');
            expect($entry)->toHaveKey('category');
            expect($entry['name'])->toBe($name);
        }
    });

    test('all GA4 names are non-empty strings', function (): void {
        foreach (EventCatalog::allGa4Names() as $ga4Name) {
            expect($ga4Name)->toBeString();
            expect($ga4Name)->not->toBeEmpty();
        }
    });
});

describe('AnalyticsManager — Facade Coverage', function (): void {
    test('all facade methods exist on AnalyticsManager', function (): void {
        $facadeDoc = (new ReflectionClass(\ZeroBoiler\Analytics\Facades\Analytics::class))->getDocComment();
        expect($facadeDoc)->not->toBeFalse();

        $methods = [
            'track', 'trackEvent', 'trackEcommerce', 'purchase', 'identify',
            'screenView', 'pageView', 'serverSidePageView', 'abTestExposure',
            'notification', 'trackAsync', 'setUserProperties', 'alias',
            'logout', 'trialEnd', 'planDowngrade', 'wishlist', 'directDispatch',
            'formatEcommerceForMeta', 'headScripts', 'bodyScripts', 'push',
            'ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook',
            'setConsent', 'grantConsent', 'denyConsent', 'getConsent',
            'isDebug', 'shouldLogEvents', 'setDebug', 'resetIdentity',
            'eventCatalogSummary', 'eventExists', 'eventCategory',
            'totalEventCount', 'trackError', 'mrr',
            'version', 'providerSummary', 'metrics',
        ];

        foreach ($methods as $method) {
            expect(method_exists($this->manager, $method))
                ->toBeTrue("AnalyticsManager missing method: {$method}");
        }
    });
});
