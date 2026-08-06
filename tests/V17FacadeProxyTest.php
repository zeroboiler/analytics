<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Engagement\ScreenViewEvent;
use ZeroBoiler\Analytics\Events\Engagement\AbTestExposureEvent;
use ZeroBoiler\Analytics\Events\Engagement\NotificationEvent;
use ZeroBoiler\Analytics\Facades\Analytics;

describe('v1.7.0 — Facade proxy methods', function () {
    beforeEach(function () {
        $this->config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $this->config->shouldReceive('get')->andReturn([]);
        $this->manager = new AnalyticsManager($this->config);
        $this->manager->setDebug(true);
        App::instance('zeroboiler.analytics', $this->manager);
    });

    describe('screenView facade proxy', function () {
        it('calls screenView via facade', function () {
            $manager = App::make('zeroboiler.analytics');
            $manager->setDebug(true); // No HTTP calls

            expect(fn () => Analytics::screenView('Dashboard', 'main'))->not->toThrow();
        });
    });

    describe('abTestExposure facade proxy', function () {
        it('calls abTestExposure via facade', function () {
            expect(fn () => Analytics::abTestExposure('test_id', 'variant_b'))->not->toThrow();
        });
    });

    describe('notification facade proxy', function () {
        it('calls notification via facade', function () {
            expect(fn () => Analytics::notification('push', 'opened', 'daily_reminder'))->not->toThrow();
        });
    });

    describe('trackAsync facade proxy', function () {
        it('falls back gracefully via facade', function () {
            expect(fn () => Analytics::trackAsync('background_event', ['key' => 'val']))->not->toThrow();
        });
    });
});
