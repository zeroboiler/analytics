<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

describe('Event Catalog', function () {
    describe('EcommerceEvents', function () {
        it('returns all e-commerce event names', function () {
            $names = EcommerceEvents::names();

            expect($names)->toContain('view_item');
            expect($names)->toContain('add_to_cart');
            expect($names)->toContain('purchase');
            expect($names)->toContain('refund');
            expect($names)->toContain('begin_checkout');
            expect($names)->toContain('add_payment_info');
            expect($names)->toContain('remove_from_cart');
            expect($names)->toContain('view_cart');
        });

        it('returns correct count of e-commerce events', function () {
            expect(EcommerceEvents::count())->toBe(8);
        });

        it('returns all event entries with required keys', function () {
            $all = EcommerceEvents::all();

            foreach ($all as $name => $entry) {
                expect($entry)->toHaveKey('name');
                expect($entry)->toHaveKey('class');
                expect($entry)->toHaveKey('ga4');
                expect($entry)->toHaveKey('meta');
                expect($entry['name'])->toBe($name);
            }
        });

        it('can look up a specific event', function () {
            $event = EcommerceEvents::get('purchase');

            expect($event)->not->toBeNull();
            expect($event['class'])->toBe(\ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent::class);
            expect($event['ga4'])->toBe('purchase');
            expect($event['meta'])->toBe('Purchase');
        });

        it('returns null for unknown event', function () {
            expect(EcommerceEvents::get('nonexistent'))->toBeNull();
        });

        it('checks if event exists', function () {
            expect(EcommerceEvents::has('purchase'))->toBeTrue();
            expect(EcommerceEvents::has('nonexistent'))->toBeFalse();
        });

        it('returns GA4 event names', function () {
            $ga4 = EcommerceEvents::ga4Names();

            expect($ga4)->toContain('view_item');
            expect($ga4)->toContain('purchase');
            expect(count($ga4))->toBe(8);
        });

        it('returns Meta Pixel event names (non-null only)', function () {
            $meta = EcommerceEvents::metaNames();

            expect($meta)->toContain('ViewContent');
            expect($meta)->toContain('AddToCart');
            expect($meta)->not->toContain(null);
        });

        it('returns class for a given event name', function () {
            expect(EcommerceEvents::classFor('view_item'))->toBe(
                \ZeroBoiler\Analytics\Events\Ecommerce\ViewItemEvent::class,
            );
            expect(EcommerceEvents::classFor('nonexistent'))->toBeNull();
        });

        it('returns correct category', function () {
            expect(EcommerceEvents::category())->toBe('ecommerce');
        });
    });

    describe('SaaSEvents', function () {
        it('returns all SaaS event names', function () {
            $names = SaaSEvents::names();

            expect($names)->toContain('sign_up');
            expect($names)->toContain('login');
            expect($names)->toContain('logout');
            expect($names)->toContain('start_trial');
            expect($names)->toContain('subscribe');
            expect($names)->toContain('plan_upgrade');
            expect($names)->toContain('cancellation');
            expect($names)->toContain('feature_used');
            expect($names)->toContain('revenue_tracked');
        });

        it('returns correct count of SaaS events', function () {
            expect(SaaSEvents::count())->toBe(11);
        });

        it('can look up subscription event', function () {
            $event = SaaSEvents::get('subscribe');

            expect($event)->not->toBeNull();
            expect($event['class'])->toBe(\ZeroBoiler\Analytics\Events\SaaS\SubscriptionEvent::class);
            expect($event['ga4'])->toBe('purchase');
            expect($event['meta'])->toBe('Subscribe');
        });

        it('checks if event exists', function () {
            expect(SaaSEvents::has('sign_up'))->toBeTrue();
            expect(SaaSEvents::has('nonexistent'))->toBeFalse();
        });

        it('returns class for a given event name', function () {
            expect(SaaSEvents::classFor('sign_up'))->toBe(
                \ZeroBoiler\Analytics\Events\SaaS\SignUpEvent::class,
            );
        });

        it('returns correct category', function () {
            expect(SaaSEvents::category())->toBe('saas');
        });
    });

    describe('EngagementEvents', function () {
        it('returns all engagement event names', function () {
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
        });

        it('returns correct count of engagement events', function () {
            expect(EngagementEvents::count())->toBe(10);
        });

        it('can look up page_view event', function () {
            $event = EngagementEvents::get('page_view');

            expect($event)->not->toBeNull();
            expect($event['class'])->toBe(\ZeroBoiler\Analytics\Events\Engagement\PageViewEvent::class);
            expect($event['ga4'])->toBe('page_view');
            expect($event['meta'])->toBe('PageView');
        });

        it('checks if event exists', function () {
            expect(EngagementEvents::has('page_view'))->toBeTrue();
            expect(EngagementEvents::has('nonexistent'))->toBeFalse();
        });

        it('returns correct category', function () {
            expect(EngagementEvents::category())->toBe('engagement');
        });
    });

    describe('EventCatalog (unified)', function () {
        it('returns total count across all categories', function () {
            expect(EventCatalog::count())->toBe(29);
        });

        it('returns all event names across all categories', function () {
            $names = EventCatalog::names();

            expect($names)->toContain('view_item');
            expect($names)->toContain('sign_up');
            expect($names)->toContain('page_view');
            expect($names)->toContain('purchase');
            expect($names)->toContain('cancellation');
        });

        it('returns events grouped by category', function () {
            $byCategory = EventCatalog::byCategory();

            expect($byCategory)->toHaveKey('ecommerce');
            expect($byCategory)->toHaveKey('saas');
            expect($byCategory)->toHaveKey('engagement');
            expect(count($byCategory['ecommerce']))->toBe(8);
            expect(count($byCategory['saas']))->toBe(11);
            expect(count($byCategory['engagement']))->toBe(10);
        });

        it('each event entry has category annotation', function () {
            $all = EventCatalog::all();

            foreach ($all as $name => $entry) {
                expect($entry)->toHaveKey('category');
                expect(in_array($entry['category'], ['ecommerce', 'saas', 'engagement'], true))->toBeTrue();
            }
        });

        it('can look up events from any category', function () {
            expect(EventCatalog::get('purchase'))->not->toBeNull();
            expect(EventCatalog::get('sign_up'))->not->toBeNull();
            expect(EventCatalog::get('page_view'))->not->toBeNull();
            expect(EventCatalog::get('nonexistent'))->toBeNull();
        });

        it('can check if event exists across categories', function () {
            expect(EventCatalog::has('purchase'))->toBeTrue();
            expect(EventCatalog::has('sign_up'))->toBeTrue();
            expect(EventCatalog::has('page_view'))->toBeTrue();
            expect(EventCatalog::has('nonexistent'))->toBeFalse();
        });

        it('returns class for event from any category', function () {
            expect(EventCatalog::classFor('purchase'))->toBe(
                \ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent::class,
            );
            expect(EventCatalog::classFor('sign_up'))->toBe(
                \ZeroBoiler\Analytics\Events\SaaS\SignUpEvent::class,
            );
            expect(EventCatalog::classFor('page_view'))->toBe(
                \ZeroBoiler\Analytics\Events\Engagement\PageViewEvent::class,
            );
            expect(EventCatalog::classFor('nonexistent'))->toBeNull();
        });

        it('returns all GA4 event names (deduplicated)', function () {
            $ga4 = EventCatalog::allGa4Names();

            expect($ga4)->toContain('view_item');
            expect($ga4)->toContain('page_view');
            expect($ga4)->toContain('sign_up');
            // purchase appears in both ecommerce and SaaS subscription
            expect(count(array_unique($ga4)))->toBe(count($ga4));
        });

        it('returns all Meta event names (deduplicated)', function () {
            $meta = EventCatalog::allMetaNames();

            expect($meta)->toContain('ViewContent');
            expect($meta)->toContain('PageView');
            expect($meta)->toContain('CompleteRegistration');
        });

        it('can filter by category', function () {
            $ecommerce = EventCatalog::category('ecommerce');
            expect(count($ecommerce))->toBe(8);

            $saas = EventCatalog::category('saas');
            expect(count($saas))->toBe(11);

            $engagement = EventCatalog::category('engagement');
            expect(count($engagement))->toBe(10);

            $invalid = EventCatalog::category('nonexistent');
            expect($invalid)->toBe([]);
        });
    });
});
