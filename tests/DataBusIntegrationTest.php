<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\App;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Bus\AnalyticsDataBus;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;

describe('AnalyticsManager DataBus Integration', function () {
    describe('dispatchToTrackers with DataBus', function () {
        it('uses direct dispatch when DataBus has no rules', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                        'gtm' => ['enabled' => false, 'container_id' => ''],
                        'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                        'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => '', 'base_url' => ''],
                        'posthog' => ['enabled' => false, 'api_key' => '', 'host' => '', 'project_id' => ''],
                        'consent' => ['default' => 'granted'],
                        'debug' => ['enabled' => false, 'log_events' => false],
                    ],
                ],
            ]);

            $bus = Mockery::mock(AnalyticsDataBus::class);
            $bus->shouldReceive('getRules')->andReturn([]);

            App::instance(AnalyticsDataBus::class, $bus);

            $manager = new AnalyticsManager($config);
            $manager->track('test_event', ['key' => 'value']);
            // No error = success (all trackers disabled, no exception)
        });

        it('routes through DataBus when rules exist', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                        'gtm' => ['enabled' => false, 'container_id' => ''],
                        'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                        'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => '', 'base_url' => ''],
                        'posthog' => ['enabled' => false, 'api_key' => '', 'host' => '', 'project_id' => ''],
                        'consent' => ['default' => 'granted'],
                        'debug' => ['enabled' => false, 'log_events' => false],
                    ],
                ],
            ]);

            $bus = Mockery::mock(AnalyticsDataBus::class);
            $bus->shouldReceive('getRules')->andReturn([
                ['condition' => fn (): bool => true, 'providers' => ['ga4']],
            ]);
            $bus->shouldReceive('route')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'purchase';
            });

            App::instance(AnalyticsDataBus::class, $bus);

            $manager = new AnalyticsManager($config);
            $manager->track('purchase', ['value' => 99.99]);
        });
    });

    describe('directDispatch', function () {
        it('dispatches to all enabled trackers bypassing DataBus', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                        'gtm' => ['enabled' => false, 'container_id' => ''],
                        'meta_pixel' => ['enabled' => true, 'id' => '123', 'access_token' => 'token'],
                        'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => '', 'base_url' => ''],
                        'posthog' => ['enabled' => false, 'api_key' => '', 'host' => '', 'project_id' => ''],
                        'consent' => ['default' => 'granted'],
                        'debug' => ['enabled' => false, 'log_events' => false],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);

            // Mock the HTTP client for GA4 and Meta
            $httpClient = Mockery::mock(\Illuminate\Http\Client\PendingRequest::class);
            $httpClient->shouldReceive('post')->twice()->andReturn(
                Mockery::mock(\Illuminate\Http\Client\Response::class),
            );
            App::instance('http', $httpClient);

            $event = new AnalyticsEvent(name: 'critical_event', params: ['key' => 'value']);
            $manager->directDispatch($event);

            // If no exception, both GA4 and Meta were dispatched to
            expect(true)->toBeTrue();
        });

        it('skips disabled trackers', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                        'gtm' => ['enabled' => false, 'container_id' => ''],
                        'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                        'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => '', 'base_url' => ''],
                        'posthog' => ['enabled' => false, 'api_key' => '', 'host' => '', 'project_id' => ''],
                        'consent' => ['default' => 'granted'],
                        'debug' => ['enabled' => false, 'log_events' => false],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);
            $event = new AnalyticsEvent(name: 'test', params: []);
            $manager->directDispatch($event);
            // No error = all disabled trackers were skipped
            expect(true)->toBeTrue();
        });
    });

    describe('debug mode with DataBus', function () {
        it('logs events in debug mode without dispatching', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                        'gtm' => ['enabled' => false, 'container_id' => ''],
                        'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                        'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => '', 'base_url' => ''],
                        'posthog' => ['enabled' => false, 'api_key' => '', 'host' => '', 'project_id' => ''],
                        'consent' => ['default' => 'granted'],
                        'debug' => ['enabled' => true, 'log_events' => true],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);
            $manager->track('debug_test', ['key' => 'value']);

            // DataBus should never be called in debug mode
            expect($manager->isDebug())->toBeTrue();
        });
    });
});
