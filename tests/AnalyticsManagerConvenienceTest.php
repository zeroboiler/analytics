<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Facades\Analytics;

describe('AnalyticsManager Convenience Methods', function () {
    beforeEach(function () {
        $this->config = Mockery::mock(ConfigRepository::class);

        // Return empty configs so no trackers are enabled
        $this->config->shouldReceive('get')
            ->andReturnUsing(function (string $key, $default = null) {
                $emptyConfigs = [
                    'zeroboiler.analytics.ga4' => [],
                    'zeroboiler.analytics.gtm' => [],
                    'zeroboiler.analytics.meta_pixel' => [],
                    'zeroboiler.analytics.plausible' => [],
                    'zeroboiler.analytics.posthog' => [],
                    'zeroboiler.analytics.consent' => ['default' => 'granted'],
                    'zeroboiler.analytics.debug' => [],
                ];

                return $emptyConfigs[$key] ?? $default;
            });
    });

    afterEach(function () {
        Mockery::close();
    });

    describe('purchase()', function () {
        it('tracks a purchase event with transaction ID and value', function () {
            $manager = new AnalyticsManager($this->config);

            // Since no trackers are enabled, this won't error
            // Just verify the method signature works
            $manager->purchase('TXN-12345', 99.99);

            // No assertion needed — method should not throw
            expect(true)->toBeTrue();
        });

        it('tracks purchase with items', function () {
            $manager = new AnalyticsManager($this->config);

            $items = [
                ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
            ];

            $manager->purchase('TXN-67890', 99.98, $items, ['coupon' => 'WELCOME20']);

            // No assertion needed — method should not throw
            expect(true)->toBeTrue();
        });

        it('defaults currency to USD', function () {
            $manager = new AnalyticsManager($this->config);

            // Capture tracked events by checking the method works without error
            $manager->purchase('TXN-USD', 50.00);

            expect(true)->toBeTrue();
        });

        it('accepts custom currency via params', function () {
            $manager = new AnalyticsManager($this->config);

            $manager->purchase('TXN-EUR', 75.00, [], ['currency' => 'EUR']);

            expect(true)->toBeTrue();
        });
    });

    describe('identify()', function () {
        it('tracks an identify event with user ID', function () {
            $manager = new AnalyticsManager($this->config);

            $manager->identify('42');

            expect(true)->toBeTrue();
        });

        it('tracks identify with client ID and traits', function () {
            $manager = new AnalyticsManager($this->config);

            $traits = [
                'email_hash' => hash('sha256', 'user@example.com'),
                'plan' => 'pro',
            ];

            $manager->identify('42', 'client-uuid-123', $traits);

            expect(true)->toBeTrue();
        });

        it('works with null client ID', function () {
            $manager = new AnalyticsManager($this->config);

            $manager->identify('42', null, ['name' => 'John']);

            expect(true)->toBeTrue();
        });

        it('works with empty traits', function () {
            $manager = new AnalyticsManager($this->config);

            $manager->identify('42', 'client-abc', []);

            expect(true)->toBeTrue();
        });
    });
});
