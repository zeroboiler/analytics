<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\AnalyticsFake;

describe('Phase 126 — Unified Category Dispatchers', function (): void {
    beforeEach(function (): void {
        Analytics::fake();
    });

    describe('AnalyticsManager::trackEcommerceEvent()', function (): void {
        it('resolves snake_case ecommerce event name', function (): void {
            $result = Analytics::trackEcommerceEvent('view_item', [
                'item_id' => 'SKU-001',
                'item_name' => 'Widget',
                'price' => 49.99,
                'quantity' => 1,
            ]);

            expect($result)->toBeTrue();
            Analytics::assertTracked('view_item');
        });

        it('resolves PascalCase ecommerce event name', function (): void {
            $result = Analytics::trackEcommerceEvent('AddToCart', [
                'item_id' => 'SKU-001',
                'item_name' => 'Widget',
                'price' => 29.99,
                'quantity' => 2,
            ]);

            expect($result)->toBeTrue();
            Analytics::assertTracked('add_to_cart');
        });

        it('resolves camelCase ecommerce event name', function (): void {
            $result = Analytics::trackEcommerceEvent('Purchase', [
                'value' => 99.99,
                'currency' => 'USD',
            ]);

            expect($result)->toBeTrue();
            Analytics::assertTracked('purchase');
        });

        it('returns false for unknown event name', function (): void {
            $result = Analytics::trackEcommerceEvent('nonexistent_event', []);

            expect($result)->toBeFalse();
        });

        it('returns false for event in different category', function (): void {
            $result = Analytics::trackEcommerceEvent('sign_up', []);

            expect($result)->toBeFalse();
        });

        it('merges options into params', function (): void {
            $result = Analytics::trackEcommerceEvent('purchase', [
                'items' => [['item_id' => 'SKU-001']],
            ], [
                'currency' => 'EUR',
                'transaction_id' => 'TXN-999',
                'value' => 59.99,
            ]);

            expect($result)->toBeTrue();
            Analytics::assertTracked('purchase', function (array $params): bool {
                return ($params['currency'] ?? null) === 'EUR'
                    && ($params['transaction_id'] ?? null) === 'TXN-999'
                    && ($params['value'] ?? null) === 59.99;
            });
        });

        it('auto-computes value from price and quantity for single-item events', function (): void {
            $result = Analytics::trackEcommerceEvent('add_to_cart', [
                'item_id' => 'SKU-001',
                'price' => 25.0,
                'quantity' => 3,
            ]);

            expect($result)->toBeTrue();
            Analytics::assertTracked('add_to_cart', function (array $params): bool {
                return ($params['value'] ?? null) === 75.0;
            });
        });

        it('does not override explicit value with auto-computed', function (): void {
            $result = Analytics::trackEcommerceEvent('view_item', [
                'item_id' => 'SKU-001',
                'price' => 25.0,
                'quantity' => 3,
                'value' => 100.0,
            ]);

            expect($result)->toBeTrue();
            Analytics::assertTracked('view_item', function (array $params): bool {
                return ($params['value'] ?? null) === 100.0;
            });
        });

        it('tracks all 15 ecommerce events', function (): void {
            $ecommerceEvents = [
                'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart',
                'begin_checkout', 'add_payment_info', 'purchase', 'refund',
                'add_to_wishlist', 'select_item', 'select_promotion',
                'view_promotion', 'checkout_step', 'abandoned_cart', 'checkout_abandon',
            ];

            foreach ($ecommerceEvents as $event) {
                $result = Analytics::trackEcommerceEvent($event, []);
                expect($result)->toBeTrue("Failed for event: {$event}");
            }

            Analytics::assertTrackedTimes('view_item', 1);
            Analytics::assertTrackedTimes('add_to_cart', 1);
            Analytics::assertTrackedTimes('purchase', 1);
        });
    });

    describe('AnalyticsManager::trackSaaSLifecycle()', function (): void {
        it('resolves SaaS event name', function (): void {
            $result = Analytics::trackSaaSLifecycle('sign_up', ['method' => 'google']);

            expect($result)->toBeTrue();
            Analytics::assertTracked('sign_up');
        });

        it('resolves PascalCase SaaS event name', function (): void {
            $result = Analytics::trackSaaSLifecycle('TrialStart', ['plan_name' => 'pro']);

            expect($result)->toBeTrue();
            Analytics::assertTracked('start_trial');
        });

        it('returns false for ecommerce event', function (): void {
            $result = Analytics::trackSaaSLifecycle('purchase', []);

            expect($result)->toBeFalse();
        });

        it('returns false for unknown event', function (): void {
            $result = Analytics::trackSaaSLifecycle('nonexistent', []);

            expect($result)->toBeFalse();
        });

        it('tracks core SaaS lifecycle events', function (): void {
            $coreEvents = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'];

            foreach ($coreEvents as $event) {
                $result = Analytics::trackSaaSLifecycle($event, []);
                expect($result)->toBeTrue("Failed for event: {$event}");
            }
        });
    });

    describe('AnalyticsManager::trackEngagement()', function (): void {
        it('resolves engagement event name', function (): void {
            $result = Analytics::trackEngagement('page_view', ['page_title' => 'Home']);

            expect($result)->toBeTrue();
            Analytics::assertTracked('page_view');
        });

        it('resolves PascalCase engagement event name', function (): void {
            $result = Analytics::trackEngagement('ScrollDepth', ['percent' => 75]);

            expect($result)->toBeTrue();
            Analytics::assertTracked('scroll_depth');
        });

        it('returns false for SaaS event', function (): void {
            $result = Analytics::trackEngagement('sign_up', []);

            expect($result)->toBeFalse();
        });

        it('returns false for unknown event', function (): void {
            $result = Analytics::trackEngagement('nonexistent', []);

            expect($result)->toBeFalse();
        });

        it('tracks core engagement events', function (): void {
            $coreEvents = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];

            foreach ($coreEvents as $event) {
                $result = Analytics::trackEngagement($event, []);
                expect($result)->toBeTrue("Failed for event: {$event}");
            }
        });
    });

    describe('AnalyticsManager::trackByCategory()', function (): void {
        it('resolves ecommerce event from any category', function (): void {
            $result = Analytics::trackByCategory('purchase', ['value' => 99.99]);

            expect($result)->toBeTrue();
            Analytics::assertTracked('purchase');
        });

        it('resolves SaaS event', function (): void {
            $result = Analytics::trackByCategory('sign_up', ['method' => 'email']);

            expect($result)->toBeTrue();
            Analytics::assertTracked('sign_up');
        });

        it('resolves engagement event', function (): void {
            $result = Analytics::trackByCategory('scroll_depth', ['percent' => 50]);

            expect($result)->toBeTrue();
            Analytics::assertTracked('scroll_depth');
        });

        it('returns false for completely unknown event', function (): void {
            $result = Analytics::trackByCategory('absolutely_not_a_real_event_xyz');

            expect($result)->toBeFalse();
        });

        it('resolves marketing event', function (): void {
            $result = Analytics::trackByCategory('email_opened', []);

            expect($result)->toBeTrue();
            Analytics::assertTracked('email_opened');
        });

        it('resolves security event', function (): void {
            $result = Analytics::trackByCategory('login_attempt', []);

            expect($result)->toBeTrue();
            Analytics::assertTracked('login_attempt');
        });
    });

    describe('EventCatalog integration', function (): void {
        it('trackEcommerceEvent uses EventCatalog::resolve', function (): void {
            $resolved = EventCatalog::resolve('ViewItem');
            expect($resolved)->toBe('view_item');

            $category = EventCatalog::getCategory('view_item');
            expect($category)->toBe('ecommerce');
        });

        it('trackSaaSLifecycle uses EventCatalog::resolve', function (): void {
            $resolved = EventCatalog::resolve('TrialStart');
            expect($resolved)->toBe('start_trial');

            $category = EventCatalog::getCategory('start_trial');
            expect($category)->toBe('saas');
        });

        it('trackEngagement uses EventCatalog::resolve', function (): void {
            $resolved = EventCatalog::resolve('FormSubmit');
            expect($resolved)->toBe('form_submit');

            $category = EventCatalog::getCategory('form_submit');
            expect($category)->toBe('engagement');
        });

        it('EventCatalog::resolve returns null for unknown events', function (): void {
            $resolved = EventCatalog::resolve('definitely_not_real_xyz');
            expect($resolved)->toBeNull();
        });
    });

    describe('AnalyticsFake integration', function (): void {
        it('AnalyticsFake::trackEcommerceEvent returns true for valid events', function (): void {
            $fake = app(AnalyticsFake::class);
            $result = $fake->trackEcommerceEvent('view_item', ['item_id' => 'SKU-001']);

            expect($result)->toBeTrue();
        });

        it('AnalyticsFake::trackSaaSLifecycle returns true for valid events', function (): void {
            $fake = app(AnalyticsFake::class);
            $result = $fake->trackSaaSLifecycle('sign_up', ['method' => 'email']);

            expect($result)->toBeTrue();
        });

        it('AnalyticsFake::trackEngagement returns true for valid events', function (): void {
            $fake = app(AnalyticsFake::class);
            $result = $fake->trackEngagement('click', ['target' => 'buy_now']);

            expect($result)->toBeTrue();
        });

        it('AnalyticsFake::trackByCategory returns true for any valid event', function (): void {
            $fake = app(AnalyticsFake::class);
            $result = $fake->trackByCategory('purchase', ['value' => 99.99]);

            expect($result)->toBeTrue();
        });

        it('AnalyticsFake::trackEcommerceEvent returns false for invalid events', function (): void {
            $fake = app(AnalyticsFake::class);
            $result = $fake->trackEcommerceEvent('nonexistent_xyz', []);

            expect($result)->toBeFalse();
        });
    });
});
