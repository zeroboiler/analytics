<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

beforeEach(function () {
    $this->fake = new \ZeroBoiler\Analytics\Support\AnalyticsFake;
});

describe('v130 — JS Client API & Revenue Tracking Enhancements', function () {
    describe('AnalyticsManager::trackRevenue', function () {
        test('dispatches revenue_tracked event with correct parameters', function () {
            $manager = $this->fake;
            $manager->trackRevenue(99.99, 'EUR', 'mrr', ['plan_name' => 'Pro']);

            $manager->assertTracked('revenue_tracked', function (array $params) {
                return $params['value'] === 99.99
                    && $params['currency'] === 'EUR'
                    && $params['revenue_type'] === 'mrr'
                    && $params['plan_name'] === 'Pro';
            });
        });

        test('defaults to USD and one_time revenue type', function () {
            $manager = $this->fake;
            $manager->trackRevenue(49.95);

            $manager->assertTracked('revenue_tracked', function (array $params) {
                return $params['value'] === 49.95
                    && $params['currency'] === 'USD'
                    && $params['revenue_type'] === 'one_time';
            });
        });
    });

    describe('AnalyticsManager::trackRevenueEvent', function () {
        test('dispatches event with revenue_checksum', function () {
            $manager = $this->fake;
            $result = $manager->trackRevenueEvent(
                eventName: 'purchase',
                amount: 149.99,
                currency: 'USD',
                transactionId: 'TXN-12345',
            );

            expect($result)->toHaveKey('checksum');
            expect($result)->toHaveKey('event_name');
            expect($result)->toHaveKey('amount');
            expect($result['event_name'])->toBe('purchase');
            expect($result['amount'])->toBe(149.99);
            expect($result['checksum'])->toBeString();
            expect(strlen($result['checksum']))->toBe(64); // SHA-256 hex

            $manager->assertTracked('purchase', function (array $params) {
                return isset($params['revenue_checksum'])
                    && $params['value'] === 149.99
                    && $params['currency'] === 'USD'
                    && $params['transaction_id'] === 'TXN-12345';
            });
        });

        test('generates different checksums for different events', function () {
            $manager = $this->fake;
            $result1 = $manager->trackRevenueEvent('purchase', 100.0, 'USD', 'TXN-1');
            $result2 = $manager->trackRevenueEvent('refund', 50.0, 'EUR', 'TXN-2');

            expect($result1['checksum'])->not->toBe($result2['checksum']);
        });

        test('works without transaction ID', function () {
            $manager = $this->fake;
            $result = $manager->trackRevenueEvent('subscription_created', 29.99);

            expect($result['checksum'])->toBeString();
            $manager->assertTracked('subscription_created');
        });
    });

    describe('EventCatalog::searchByCategory', function () {
        test('searches ecommerce events by pattern', function () {
            $results = EventCatalog::searchByCategory('cart', 'ecommerce');

            expect($results)->not->toBeEmpty();
            $names = array_map(fn (array $e) => $e['name'], $results);
            expect($names)->toContain('add_to_cart');
            expect($names)->toContain('remove_from_cart');
            expect($names)->toContain('view_cart');
        });

        test('searches SaaS events by pattern', function () {
            $results = EventCatalog::searchByCategory('trial', 'saas');

            expect($results)->not->toBeEmpty();
            $names = array_map(fn (array $e) => $e['name'], $results);
            expect($names)->toContain('start_trial');
        });

        test('returns empty array for non-matching pattern', function () {
            $results = EventCatalog::searchByCategory('zzz_nonexistent', 'ecommerce');
            expect($results)->toBeEmpty();
        });

        test('returns empty array for invalid category', function () {
            $results = EventCatalog::searchByCategory('purchase', 'nonexistent');
            expect($results)->toBeEmpty();
        });

        test('does not leak across categories', function () {
            $saasResults = EventCatalog::searchByCategory('purchase', 'saas');
            $saasNames = array_map(fn (array $e) => $e['name'], $saasResults);

            // 'purchase' is ecommerce, not SaaS
            expect($saasNames)->not->toContain('purchase');
        });
    });

    describe('AnalyticsEvent::VERSION', function () {
        test('is 130.0.0', function () {
            expect(AnalyticsEvent::VERSION)->toBe('130.0.0');
        });
    });
});
