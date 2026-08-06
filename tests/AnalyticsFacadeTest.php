<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Facades\Analytics;

describe('Analytics Facade', function () {
    beforeEach(function () {
        // Bind a mock manager to the container
        $this->manager = Mockery::mock(AnalyticsManager::class);
        App::instance('zeroboiler.analytics', $this->manager);
    });

    afterEach(function () {
        Mockery::close();
        // Remove facade resolved instance
        Analytics::clearResolvedInstance('zeroboiler.analytics');
    });

    describe('track proxy', function () {
        it('proxies track() to the manager', function () {
            $this->manager->shouldReceive('track')
                ->once()
                ->with('button_click', ['element' => 'buy_now']);

            Analytics::track('button_click', ['element' => 'buy_now']);
        });

        it('proxies track with empty params', function () {
            $this->manager->shouldReceive('track')
                ->once()
                ->with('heartbeat', []);

            Analytics::track('heartbeat');
        });
    });

    describe('trackEvent proxy', function () {
        it('proxies trackEvent() to the manager', function () {
            $event = new AnalyticsEvent('test_event', ['key' => 'value']);
            $this->manager->shouldReceive('trackEvent')
                ->once()
                ->with($event);

            Analytics::trackEvent($event);
        });
    });

    describe('purchase proxy', function () {
        it('proxies purchase() to the manager', function () {
            $this->manager->shouldReceive('purchase')
                ->once()
                ->with('TXN-12345', 99.99, [], ['currency' => 'USD']);

            Analytics::purchase('TXN-12345', 99.99);
        });

        it('proxies purchase with items', function () {
            $items = [
                ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
            ];

            $this->manager->shouldReceive('purchase')
                ->once()
                ->with('TXN-67890', 99.98, $items, ['currency' => 'EUR']);

            Analytics::purchase('TXN-67890', 99.98, $items, ['currency' => 'EUR']);
        });
    });

    describe('identify proxy', function () {
        it('proxies identify() to the manager', function () {
            $this->manager->shouldReceive('identify')
                ->once()
                ->with('42', null, []);

            Analytics::identify('42');
        });

        it('proxies identify with client ID and traits', function () {
            $traits = ['email_hash' => hash('sha256', 'user@example.com'), 'plan' => 'pro'];

            $this->manager->shouldReceive('identify')
                ->once()
                ->with('42', 'client-uuid-123', $traits);

            Analytics::identify('42', 'client-uuid-123', $traits);
        });
    });

    describe('consent proxy', function () {
        it('proxies setConsent() to the manager', function () {
            $state = ZeroBoiler\Analytics\DTO\ConsentState::granted();
            $this->manager->shouldReceive('setConsent')
                ->once()
                ->with($state);

            Analytics::setConsent($state);
        });

        it('proxies grantConsent() to the manager', function () {
            $this->manager->shouldReceive('grantConsent')
                ->once();

            Analytics::grantConsent();
        });

        it('proxies denyConsent() to the manager', function () {
            $this->manager->shouldReceive('denyConsent')
                ->once();

            Analytics::denyConsent();
        });

        it('proxies getConsent() to the manager', function () {
            $state = ZeroBoiler\Analytics\DTO\ConsentState::denied();
            $this->manager->shouldReceive('getConsent')
                ->once()
                ->andReturn($state);

            $result = Analytics::getConsent();

            expect($result->hasAnalyticsConsent())->toBeFalse();
        });
    });

    describe('debug proxy', function () {
        it('proxies isDebug() to the manager', function () {
            $this->manager->shouldReceive('isDebug')
                ->once()
                ->andReturn(true);

            expect(Analytics::isDebug())->toBeTrue();
        });

        it('proxies setDebug() to the manager', function () {
            $this->manager->shouldReceive('setDebug')
                ->once()
                ->with(true);

            Analytics::setDebug(true);
        });
    });

    describe('scripts proxy', function () {
        it('proxies headScripts() to the manager', function () {
            $this->manager->shouldReceive('headScripts')
                ->once()
                ->andReturn('<script>...</script>');

            expect(Analytics::headScripts())->toBe('<script>...</script>');
        });

        it('proxies bodyScripts() to the manager', function () {
            $this->manager->shouldReceive('bodyScripts')
                ->once()
                ->andReturn('');

            expect(Analytics::bodyScripts())->toBe('');
        });
    });

    describe('tracker access proxy', function () {
        it('proxies ga4() to the manager', function () {
            $mockGa4 = Mockery::mock(ZeroBoiler\Analytics\Trackers\GA4Tracker::class);
            $this->manager->shouldReceive('ga4')
                ->once()
                ->andReturn($mockGa4);

            expect(Analytics::ga4())->toBe($mockGa4);
        });

        it('proxies meta() to the manager', function () {
            $mockMeta = Mockery::mock(ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class);
            $this->manager->shouldReceive('meta')
                ->once()
                ->andReturn($mockMeta);

            expect(Analytics::meta())->toBe($mockMeta);
        });
    });

    describe('resetIdentity proxy', function () {
        it('proxies resetIdentity() to the manager', function () {
            $this->manager->shouldReceive('resetIdentity')
                ->once();

            Analytics::resetIdentity();
        });
    });

    describe('eventCatalogSummary proxy', function () {
        it('proxies eventCatalogSummary() to the manager', function () {
            $this->manager->shouldReceive('eventCatalogSummary')
                ->once()
                ->andReturn([
                    'ecommerce' => 8,
                    'saas' => 11,
                    'engagement' => 10,
                    'total' => 29,
                ]);

            $summary = Analytics::eventCatalogSummary();

            expect($summary['total'])->toBe(29);
            expect($summary['ecommerce'])->toBe(8);
        });
    });

    describe('directDispatch proxy', function () {
        it('proxies directDispatch() to the manager', function () {
            $event = new AnalyticsEvent('critical_event', ['key' => 'value']);
            $this->manager->shouldReceive('directDispatch')
                ->once()
                ->with($event);

            Analytics::directDispatch($event);
        });
    });
});
