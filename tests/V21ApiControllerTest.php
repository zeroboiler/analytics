<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;

describe('v2.1 — AnalyticsEventController', function () {
    describe('track() endpoint', function () {
        it('validates required name field', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                        'identity' => ['cookie_name' => 'zb_analytics_id'],
                        'pipeline' => ['auto_utm' => false, 'auto_timestamp' => false],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);
            $controller = new AnalyticsEventController($manager, $config);

            $request = new \Illuminate\Http\Request([
                // Missing 'name' field
                'params' => ['key' => 'value'],
            ]);

            $response = $controller->track($request);

            // Validation should fail — returns redirect or 422
            expect($response->status())->not->toBe(200);
        });

        it('tracks event with correct name and params', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                        'identity' => ['cookie_name' => 'zb_analytics_id'],
                        'pipeline' => ['auto_utm' => false, 'auto_timestamp' => false],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);
            $controller = new AnalyticsEventController($manager, $config);

            $request = new \Illuminate\Http\Request([
                'name' => 'button_click',
                'params' => ['element' => 'buy_now'],
            ]);

            $response = $controller->track($request);

            expect($response->status())->toBe(200);

            $layer = $manager->gtm()->getDataLayer();
            expect($layer)->toHaveCount(1);
            expect($layer[0]['event'])->toBe('button_click');
            expect($layer[0]['element'])->toBe('buy_now');
        });

        it('extracts client ID from header', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                        'identity' => ['cookie_name' => 'zb_analytics_id'],
                        'pipeline' => ['auto_utm' => false, 'auto_timestamp' => false],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);
            $controller = new AnalyticsEventController($manager, $config);

            $request = new \Illuminate\Http\Request([
                'name' => 'test_event',
            ]);
            $request->headers->set('X-Analytics-Client-Id', 'client-uuid-123');

            $controller->track($request);

            $layer = $manager->gtm()->getDataLayer();
            expect($layer[0]['client_id'])->toBe('client-uuid-123');
        });
    });

    describe('batch() endpoint', function () {
        it('validates events array is required', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                        'identity' => ['cookie_name' => 'zb_analytics_id'],
                        'pipeline' => ['auto_utm' => false, 'auto_timestamp' => false],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);
            $controller = new AnalyticsEventController($manager, $config);

            $request = new \Illuminate\Http\Request();

            $response = $controller->batch($request);
            expect($response->status())->not->toBe(200);
        });

        it('tracks multiple events in one request', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                        'identity' => ['cookie_name' => 'zb_analytics_id'],
                        'pipeline' => ['auto_utm' => false, 'auto_timestamp' => false],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);
            $controller = new AnalyticsEventController($manager, $config);

            $request = new \Illuminate\Http\Request([
                'events' => [
                    ['name' => 'event_a', 'params' => ['x' => 1]],
                    ['name' => 'event_b', 'params' => ['y' => 2]],
                    ['name' => 'event_c', 'params' => ['z' => 3]],
                ],
            ]);

            $response = $controller->batch($request);

            expect($response->status())->toBe(200);
            $data = json_decode($response->getContent(), true);
            expect($data['count'])->toBe(3);

            $layer = $manager->gtm()->getDataLayer();
            expect($layer)->toHaveCount(3);
        });
    });

    describe('health() endpoint', function () {
        it('returns ok status with version', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                        'identity' => ['cookie_name' => 'zb_analytics_id'],
                        'pipeline' => ['auto_utm' => false, 'auto_timestamp' => false],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);
            $controller = new AnalyticsEventController($manager, $config);

            $request = new \Illuminate\Http\Request();
            $response = $controller->health();

            expect($response->status())->toBe(200);
            $data = json_decode($response->getContent(), true);
            expect($data['status'])->toBe('ok');
            expect($data['version'])->toBe('5.3.0');
            expect($data['providers'])->toHaveKey('ga4');
            expect($data['consent'])->toHaveKey('analytics_storage');
        });

        it('lists enabled providers only', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'ga4' => ['enabled' => false],
                        'gtm' => ['enabled' => false],
                        'meta_pixel' => ['enabled' => false],
                        'plausible' => ['enabled' => false],
                        'posthog' => ['enabled' => false],
                        'identity' => ['cookie_name' => 'zb_analytics_id'],
                        'pipeline' => ['auto_utm' => false, 'auto_timestamp' => false],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);
            $controller = new AnalyticsEventController($manager, $config);

            $request = new \Illuminate\Http\Request();
            $response = $controller->health();
            $data = json_decode($response->getContent(), true);

            expect($data['providers'])->toBeEmpty();
        });
    });

    describe('validateEventName()', function () {
        it('accepts valid lowercase names', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                        'identity' => ['cookie_name' => 'zb_analytics_id'],
                        'pipeline' => ['auto_utm' => false, 'auto_timestamp' => false],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);
            $controller = new AnalyticsEventController($manager, $config);

            // Use reflection to access private method
            $method = new \ReflectionMethod($controller, 'validateEventName');

            $result = $method->invoke($controller, 'page_view');
            expect($result['valid'])->toBeTrue();
            expect($result['sanitized'])->toBe('page_view');
        });

        it('rejects uppercase event names', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                        'identity' => ['cookie_name' => 'zb_analytics_id'],
                        'pipeline' => ['auto_utm' => false, 'auto_timestamp' => false],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);
            $controller = new AnalyticsEventController($manager, $config);

            $method = new \ReflectionMethod($controller, 'validateEventName');

            $result = $method->invoke($controller, 'PageView');
            expect($result['valid'])->toBeFalse();
            expect($result['sanitized'])->toBe('pageview');
        });

        it('rejects names starting with numbers', function () {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                        'identity' => ['cookie_name' => 'zb_analytics_id'],
                        'pipeline' => ['auto_utm' => false, 'auto_timestamp' => false],
                    ],
                ],
            ]);

            $manager = new AnalyticsManager($config);
            $controller = new AnalyticsEventController($manager, $config);

            $method = new \ReflectionMethod($controller, 'validateEventName');

            $result = $method->invoke($controller, '123event');
            expect($result['valid'])->toBeFalse();
        });
    });
});
