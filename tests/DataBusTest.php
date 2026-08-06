<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Bus\AnalyticsDataBus;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;

describe('AnalyticsDataBus', function () {
    beforeEach(function () {
        $this->manager = Mockery::mock(\ZeroBoiler\Analytics\AnalyticsManager::class);
        $this->queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $this->bus = new AnalyticsDataBus($this->manager, $this->queue, useAsync: false);
    });

    afterEach(function () {
        Mockery::close();
    });

    describe('construction', function () {
        it('initializes with empty rules', function () {
            expect($this->bus->getRules())->toBe([]);
        });

        it('has all 5 default providers', function () {
            expect($this->bus->getDefaultProviders())->toBe([
                'ga4', 'gtm', 'meta', 'plausible', 'posthog',
            ]);
        });
    });

    describe('addRule', function () {
        it('adds a rule and returns self for chaining', function () {
            $result = $this->bus->addRule(
                fn (AnalyticsEvent $e): bool => $e->name === 'purchase',
                ['ga4', 'meta'],
            );

            expect($result)->toBe($this->bus);
            expect($this->bus->getRules())->toHaveCount(1);
        });

        it('stores rule with condition and providers', function () {
            $condition = fn (AnalyticsEvent $e): bool => str_starts_with($e->name, 'sign_');
            $this->bus->addRule($condition, ['ga4']);

            $rules = $this->bus->getRules();
            expect($rules[0]['providers'])->toBe(['ga4']);
            expect($rules[0]['condition'](new AnalyticsEvent(name: 'sign_up', params: [])))->toBeTrue();
            expect($rules[0]['condition'](new AnalyticsEvent(name: 'purchase', params: [])))->toBeFalse();
        });
    });

    describe('clearRules', function () {
        it('removes all rules', function () {
            $this->bus->addRule(fn (): bool => true, ['ga4']);
            $this->bus->addRule(fn (): bool => true, ['meta']);
            expect($this->bus->getRules())->toHaveCount(2);

            $this->bus->clearRules();
            expect($this->bus->getRules())->toBe([]);
        });

        it('returns self for chaining', function () {
            $result = $this->bus->clearRules();
            expect($result)->toBe($this->bus);
        });
    });

    describe('routeByPattern', function () {
        it('routes events matching glob pattern', function () {
            $ga4 = Mockery::mock(GA4Tracker::class);
            $meta = Mockery::mock(MetaPixelTracker::class);
            $gtm = Mockery::mock(GTMTracker::class);

            $ga4->shouldReceive('isEnabled')->andReturn(true);
            $ga4->shouldReceive('track')->once();
            $meta->shouldReceive('isEnabled')->andReturn(true);
            $meta->shouldReceive('track')->once();
            $gtm->shouldReceive('isEnabled')->andReturn(true);
            $gtm->shouldNotReceive('track');

            $this->manager->shouldReceive('ga4')->andReturn($ga4);
            $this->manager->shouldReceive('meta')->andReturn($meta);
            $this->manager->shouldReceive('gtm')->andReturn($gtm);

            $this->bus->routeByPattern('purchase*', ['ga4', 'meta']);
            $this->bus->route(new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]));
        });

        it('ignores events not matching pattern', function () {
            $this->queue->shouldReceive('dispatch')->once();

            $this->bus->routeByPattern('purchase*', ['ga4']);
            $this->bus->route(new AnalyticsEvent(name: 'sign_up', params: []));
        });
    });

    describe('routeByCategory', function () {
        it('routes ecommerce events to specified providers', function () {
            $ga4 = Mockery::mock(GA4Tracker::class);
            $ga4->shouldReceive('isEnabled')->andReturn(true);
            $ga4->shouldReceive('track')->once();
            $this->manager->shouldReceive('ga4')->andReturn($ga4);

            $this->bus->routeByCategory('ecommerce', ['ga4']);
            $this->bus->route(new AnalyticsEvent(name: 'purchase', params: []));
        });

        it('routes SaaS events to specified providers', function () {
            $ga4 = Mockery::mock(GA4Tracker::class);
            $ga4->shouldReceive('isEnabled')->andReturn(true);
            $ga4->shouldReceive('track')->once();
            $this->manager->shouldReceive('ga4')->andReturn($ga4);

            $this->bus->routeByCategory(['saas'], ['ga4']);
            $this->bus->route(new AnalyticsEvent(name: 'sign_up', params: []));
        });

        it('accepts multiple categories', function () {
            $ga4 = Mockery::mock(GA4Tracker::class);
            $ga4->shouldReceive('isEnabled')->andReturn(true);
            $ga4->shouldReceive('track')->once();
            $this->manager->shouldReceive('ga4')->andReturn($ga4);

            $this->bus->routeByCategory(['ecommerce', 'saas'], ['ga4']);
            $this->bus->route(new AnalyticsEvent(name: 'sign_up', params: []));
        });
    });

    describe('routeByParam', function () {
        it('routes events with matching parameter value', function () {
            $ga4 = Mockery::mock(GA4Tracker::class);
            $ga4->shouldReceive('isEnabled')->andReturn(true);
            $ga4->shouldReceive('track')->once();
            $this->manager->shouldReceive('ga4')->andReturn($ga4);

            $this->bus->routeByParam('method', 'github', ['ga4']);
            $this->bus->route(new AnalyticsEvent(name: 'sign_up', params: ['method' => 'github']));
        });

        it('ignores events with non-matching parameter value', function () {
            $this->queue->shouldReceive('dispatch')->once();

            $this->bus->routeByParam('method', 'github', ['ga4']);
            $this->bus->route(new AnalyticsEvent(name: 'sign_up', params: ['method' => 'email']));
        });
    });

    describe('routePiiOnly', function () {
        it('detects PII events by email key', function () {
            $ga4 = Mockery::mock(GA4Tracker::class);
            $ga4->shouldReceive('isEnabled')->andReturn(true);
            $ga4->shouldReceive('track')->once();
            $this->manager->shouldReceive('ga4')->andReturn($ga4);

            $this->bus->routePiiOnly(['ga4']);
            $this->bus->route(new AnalyticsEvent(name: 'form_submit', params: ['email' => 'user@example.com']));
        });

        it('detects PII events by phone key', function () {
            $ga4 = Mockery::mock(GA4Tracker::class);
            $ga4->shouldReceive('isEnabled')->andReturn(true);
            $ga4->shouldReceive('track')->once();
            $this->manager->shouldReceive('ga4')->andReturn($ga4);

            $this->bus->routePiiOnly(['ga4']);
            $this->bus->route(new AnalyticsEvent(name: 'form_submit', params: ['phone' => '+1234567890']));
        });

        it('passes through non-PII events to default dispatch', function () {
            $this->queue->shouldReceive('dispatch')->once();

            $this->bus->routePiiOnly(['ga4']);
            $this->bus->route(new AnalyticsEvent(name: 'click', params: ['element' => 'button']));
        });
    });

    describe('routeTo', function () {
        it('dispatches to specific providers only', function () {
            $ga4 = Mockery::mock(GA4Tracker::class);
            $meta = Mockery::mock(MetaPixelTracker::class);
            $gtm = Mockery::mock(GTMTracker::class);

            $ga4->shouldReceive('isEnabled')->andReturn(true);
            $ga4->shouldReceive('track')->once();
            $meta->shouldReceive('isEnabled')->andReturn(true);
            $meta->shouldReceive('track')->once();
            $gtm->shouldReceive('isEnabled')->andReturn(true);
            $gtm->shouldNotReceive('track');

            $this->manager->shouldReceive('ga4')->andReturn($ga4);
            $this->manager->shouldReceive('meta')->andReturn($meta);
            $this->manager->shouldReceive('gtm')->andReturn($gtm);

            $event = new AnalyticsEvent(name: 'custom', params: []);
            $this->bus->routeTo($event, ['ga4', 'meta']);
        });
    });

    describe('routeExcept', function () {
        it('dispatches to all providers except specified ones', function () {
            $ga4 = Mockery::mock(GA4Tracker::class);
            $meta = Mockery::mock(MetaPixelTracker::class);

            $ga4->shouldReceive('isEnabled')->andReturn(true);
            $ga4->shouldReceive('track')->once();
            $meta->shouldReceive('isEnabled')->andReturn(true);
            $meta->shouldReceive('track')->once();
            $this->manager->shouldReceive('ga4')->andReturn($ga4);
            $this->manager->shouldReceive('meta')->andReturn($meta);
            $this->manager->shouldNotReceive('gtm');

            $event = new AnalyticsEvent(name: 'test', params: []);
            $this->bus->routeExcept($event, ['gtm', 'plausible', 'posthog']);
        });
    });

    describe('route (no rules)', function () {
        it('dispatches to all providers via standard path when no rules match', function () {
            $this->queue->shouldReceive('dispatch')->once();

            $event = new AnalyticsEvent(name: 'page_view', params: []);
            $this->bus->route($event);
        });
    });

    describe('rule evaluation order', function () {
        it('uses first matching rule only', function () {
            $ga4 = Mockery::mock(GA4Tracker::class);
            $meta = Mockery::mock(MetaPixelTracker::class);

            $ga4->shouldReceive('isEnabled')->andReturn(true);
            $ga4->shouldReceive('track')->once();
            $meta->shouldNotReceive('track');
            $this->manager->shouldReceive('ga4')->andReturn($ga4);
            $this->manager->shouldReceive('meta')->andReturn($meta);

            $this->bus->addRule(
                fn (AnalyticsEvent $e): bool => true,
                ['ga4'],
            );
            $this->bus->addRule(
                fn (AnalyticsEvent $e): bool => true,
                ['meta'],
            );

            $event = new AnalyticsEvent(name: 'purchase', params: []);
            $this->bus->route($event);
        });
    });

    describe('custom rule', function () {
        it('routes by value threshold', function () {
            $meta = Mockery::mock(MetaPixelTracker::class);
            $meta->shouldReceive('isEnabled')->andReturn(true);
            $meta->shouldReceive('track')->once();
            $this->manager->shouldReceive('meta')->andReturn($meta);

            $this->bus->addRule(
                fn (AnalyticsEvent $e): bool => ($e->params['value'] ?? 0) > 100,
                ['meta'],
            );

            $this->bus->route(new AnalyticsEvent(name: 'purchase', params: ['value' => 150]));
        });

        it('falls through to standard dispatch when value is below threshold', function () {
            $this->queue->shouldReceive('dispatch')->once();

            $this->bus->addRule(
                fn (AnalyticsEvent $e): bool => ($e->params['value'] ?? 0) > 100,
                ['meta'],
            );

            $this->bus->route(new AnalyticsEvent(name: 'purchase', params: ['value' => 50]));
        });
    });
});
