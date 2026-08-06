<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Facades\Analytics;

describe('setUserProperties', function () {
    beforeEach(function () {
        $this->manager = Mockery::mock(AnalyticsManager::class);
        App::instance('zeroboiler.analytics', $this->manager);
    });

    afterEach(function () {
        Mockery::close();
        Analytics::clearResolvedInstance('zeroboiler.analytics');
    });

    it('delegates setUserProperties with traits to the manager', function () {
        $traits = ['name' => 'John Doe', 'plan' => 'pro'];

        $this->manager->shouldReceive('setUserProperties')
            ->once()
            ->with($traits, null);

        Analytics::setUserProperties($traits);
    });

    it('passes user_id when provided', function () {
        $traits = ['plan' => 'enterprise'];

        $this->manager->shouldReceive('setUserProperties')
            ->once()
            ->with($traits, '42');

        Analytics::setUserProperties($traits, '42');
    });
});

describe('alias', function () {
    beforeEach(function () {
        $this->manager = Mockery::mock(AnalyticsManager::class);
        App::instance('zeroboiler.analytics', $this->manager);
    });

    afterEach(function () {
        Mockery::close();
        Analytics::clearResolvedInstance('zeroboiler.analytics');
    });

    it('delegates alias with previous and new IDs to the manager', function () {
        $this->manager->shouldReceive('alias')
            ->once()
            ->with('anon-uuid-123', 'user-42');

        Analytics::alias('anon-uuid-123', 'user-42');
    });
});

describe('Manager setUserProperties and alias', function () {
    it('setUserProperties creates correct AnalyticsEvent', function () {
        $manager = Mockery::mock(AnalyticsManager::class)->makePartial();

        $manager->shouldReceive('track')
            ->once()
            ->with('set_user_properties', Mockery::on(function (array $params) {
                return $params['name'] === 'Jane' && $params['plan'] === 'pro' && $params['user_id'] === '7';
            }));

        $manager->setUserProperties(['name' => 'Jane', 'plan' => 'pro'], '7');

        Mockery::close();
    });

    it('setUserProperties omits user_id when null', function () {
        $manager = Mockery::mock(AnalyticsManager::class)->makePartial();

        $manager->shouldReceive('track')
            ->once()
            ->with('set_user_properties', Mockery::on(function (array $params) {
                return $params['company'] === 'Acme' && !isset($params['user_id']);
            }));

        $manager->setUserProperties(['company' => 'Acme']);

        Mockery::close();
    });

    it('alias creates correct event payload', function () {
        $manager = Mockery::mock(AnalyticsManager::class)->makePartial();

        $manager->shouldReceive('track')
            ->once()
            ->with('alias', Mockery::on(function (array $params) {
                return $params['previous_id'] === 'device-abc' && $params['new_id'] === 'user-42';
            }));

        $manager->alias('device-abc', 'user-42');

        Mockery::close();
    });
});
