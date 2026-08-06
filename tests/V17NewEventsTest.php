<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Events\Engagement\AbTestExposureEvent;
use ZeroBoiler\Analytics\Events\Engagement\NotificationEvent;
use ZeroBoiler\Analytics\Events\Engagement\ScreenViewEvent;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;

describe('v1.7.0 — Screen View, A/B Test, Notification Events', function () {
    beforeEach(function () {
        $this->config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $this->config->shouldReceive('get')->andReturn([]); // Default: all disabled
    });

    describe('ScreenViewEvent', function () {
        it('creates a screen_view event with name and class', function () {
            $event = new ScreenViewEvent('Dashboard');

            expect($event->name)->toBe('screen_view');
            expect($event->params['screen_name'])->toBe('Dashboard');
            expect($event->params['screen_class'])->toBeNull();
        });

        it('creates a screen_view event with class parameter', function () {
            $event = new ScreenViewEvent('Settings', 'modal');

            expect($event->name)->toBe('screen_view');
            expect($event->params['screen_name'])->toBe('Settings');
            expect($event->params['screen_class'])->toBe('modal');
        });

        it('creates a screen_view event with extra params', function () {
            $event = new ScreenViewEvent('Billing', 'main', ['tab' => 'invoices']);

            expect($event->name)->toBe('screen_view');
            expect($event->params['screen_name'])->toBe('Billing');
            expect($event->params['screen_class'])->toBe('main');
            expect($event->params['tab'])->toBe('invoices');
        });

        it('filters out null screen_class', function () {
            $event = new ScreenViewEvent('Dashboard', null);

            expect($event->params)->toHaveKey('screen_name');
            expect($event->params)->not->toHaveKey('screen_class');
        });

        it('extends AnalyticsEvent', function () {
            $event = new ScreenViewEvent('Home');

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        });
    });

    describe('AbTestExposureEvent', function () {
        it('creates an ab_test_exposure event', function () {
            $event = new AbTestExposureEvent('pricing_redesign_v2', 'variant_a');

            expect($event->name)->toBe('ab_test_exposure');
            expect($event->params['experiment_id'])->toBe('pricing_redesign_v2');
            expect($event->params['variant_id'])->toBe('variant_a');
        });

        it('creates with additional params', function () {
            $event = new AbTestExposureEvent(
                'cta_color_test',
                'control',
                ['experiment_name' => 'CTA Button Color'],
            );

            expect($event->name)->toBe('ab_test_exposure');
            expect($event->params['experiment_name'])->toBe('CTA Button Color');
        });

        it('extends AnalyticsEvent', function () {
            $event = new AbTestExposureEvent('test', 'variant');

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        });
    });

    describe('NotificationEvent', function () {
        it('creates a notification event with required params', function () {
            $event = new NotificationEvent('email', 'sent');

            expect($event->name)->toBe('notification');
            expect($event->params['notification_channel'])->toBe('email');
            expect($event->params['notification_action'])->toBe('sent');
        });

        it('creates a notification event with type', function () {
            $event = new NotificationEvent('push', 'opened', 'weekly_digest');

            expect($event->name)->toBe('notification');
            expect($event->params['notification_channel'])->toBe('push');
            expect($event->params['notification_action'])->toBe('opened');
            expect($event->params['notification_type'])->toBe('weekly_digest');
        });

        it('filters out null notification_type', function () {
            $event = new NotificationEvent('in_app', 'clicked', null);

            expect($event->params)->not->toHaveKey('notification_type');
        });

        it('supports SMS channel', function () {
            $event = new NotificationEvent('sms', 'delivered', 'otp_verification');

            expect($event->params['notification_channel'])->toBe('sms');
            expect($event->params['notification_type'])->toBe('otp_verification');
        });

        it('extends AnalyticsEvent', function () {
            $event = new NotificationEvent('email', 'sent');

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        });
    });

    describe('EngagementEvents catalog (updated)', function () {
        it('includes screen_view in the catalog', function () {
            expect(EngagementEvents::has('screen_view'))->toBeTrue();
            expect(EngagementEvents::count())->toBe(13);
        });

        it('includes ab_test_exposure in the catalog', function () {
            expect(EngagementEvents::has('ab_test_exposure'))->toBeTrue();
        });

        it('includes notification in the catalog', function () {
            expect(EngagementEvents::has('notification'))->toBeTrue();
        });

        it('returns correct class for new events', function () {
            expect(EngagementEvents::classFor('screen_view'))->toBe(ScreenViewEvent::class);
            expect(EngagementEvents::classFor('ab_test_exposure'))->toBe(AbTestExposureEvent::class);
            expect(EngagementEvents::classFor('notification'))->toBe(NotificationEvent::class);
        });

        it('catalog includes all 13 engagement events', function () {
            $names = EngagementEvents::names();

            expect($names)->toContain('page_view');
            expect($names)->toContain('scroll_depth');
            expect($names)->toContain('click');
            expect($names)->toContain('form_start');
            expect($names)->toContain('form_submit');
            expect($names)->toContain('search');
            expect($names)->toContain('share');
            expect($names)->toContain('error');
            expect($names)->toContain('time_on_page');
            expect($names)->toContain('campaign_attribution');
            expect($names)->toContain('screen_view');
            expect($names)->toContain('ab_test_exposure');
            expect($names)->toContain('notification');
            expect(count($names))->toBe(13);
        });
    });

    describe('EventCatalog (unified)', function () {
        it('reports 32 total events', function () {
            expect(EventCatalog::count())->toBe(32);
        });

        it('reports updated engagement count', function () {
            $summary = EventCatalog::byCategory();
            expect($summary['engagement'])->toHaveCount(13);
        });

        it('has all new events in unified catalog', function () {
            expect(EventCatalog::has('screen_view'))->toBeTrue();
            expect(EventCatalog::has('ab_test_exposure'))->toBeTrue();
            expect(EventCatalog::has('notification'))->toBeTrue();
        });

        it('returns correct categories for new events', function () {
            $screen = EventCatalog::get('screen_view');
            expect($screen)->not->toBeNull();
            expect($screen['category'])->toBe('engagement');
            expect($screen['ga4'])->toBe('screen_view');

            $abTest = EventCatalog::get('ab_test_exposure');
            expect($abTest['category'])->toBe('engagement');

            $notification = EventCatalog::get('notification');
            expect($notification['category'])->toBe('engagement');
        });
    });
});

describe('v1.7.0 — AnalyticsManager convenience methods', function () {
    beforeEach(function () {
        $this->config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $this->config->shouldReceive('get')->andReturn([]);
        $this->manager = new AnalyticsManager($this->config);
        $this->manager->setDebug(true); // Prevent actual HTTP calls
    });

    describe('screenView()', function () {
        it('tracks a screen_view event', function () {
            $event = null;
            $this->manager->setDebug(false);

            // Verify the method doesn't throw
            expect(fn () => $this->manager->screenView('Dashboard'))->not->toThrow();
        });
    });

    describe('abTestExposure()', function () {
        it('tracks an ab_test_exposure event', function () {
            expect(fn () => $this->manager->abTestExposure('test_1', 'variant_a'))->not->toThrow();
        });
    });

    describe('notification()', function () {
        it('tracks a notification event', function () {
            expect(fn () => $this->manager->notification('email', 'sent', 'welcome'))->not->toThrow();
        });
    });

    describe('trackAsync()', function () {
        it('falls back to sync when container unavailable', function () {
            // In test context, app() may not be available, so trackAsync
            // should fall back gracefully without throwing
            $this->manager->setDebug(true);

            expect(fn () => $this->manager->trackAsync('async_test', ['key' => 'value']))->not->toThrow();
        });
    });

    describe('eventCatalogSummary()', function () {
        it('returns updated counts for v1.7.0', function () {
            $summary = $this->manager->eventCatalogSummary();

            expect($summary)->toBe([
                'ecommerce' => 8,
                'saas' => 11,
                'engagement' => 13,
                'total' => 32,
            ]);
        });
    });
});
