<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventValidationService;
use ZeroBoiler\Analytics\Services\SaaSAnalyticsService;
use ZeroBoiler\Analytics\Tracking\SessionTracker;

// ── EventValidationService Tests ──────────────────────────────────────

describe('EventValidationService', function () {
    it('validates correct event names', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'validation' => [
                        'strict' => false,
                        'whitelist' => [],
                    ],
                ],
            ],
        ]);

        $validator = new EventValidationService($config);

        $result = $validator->validate(new AnalyticsEvent(name: 'page_view'));

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    it('rejects event names with invalid characters', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'validation' => [
                        'strict' => false,
                        'whitelist' => [],
                    ],
                ],
            ],
        ]);

        $validator = new EventValidationService($config);

        $result = $validator->validate(new AnalyticsEvent(name: 'Invalid Name!'));

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->not->toBeEmpty();
    });

    it('accepts whitelisted events in strict mode', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'validation' => [
                        'strict' => true,
                        'whitelist' => ['page_view', 'purchase'],
                    ],
                ],
            ],
        ]);

        $validator = new EventValidationService($config);

        $result = $validator->validate(new AnalyticsEvent(name: 'page_view'));

        expect($result['valid'])->toBeTrue();
    });

    it('rejects non-whitelisted events in strict mode', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'validation' => [
                        'strict' => true,
                        'whitelist' => ['page_view'],
                    ],
                ],
            ],
        ]);

        $validator = new EventValidationService($config);

        $result = $validator->validate(new AnalyticsEvent(name: 'custom_event'));

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toHaveCount(1);
    });

    it('allows all events when whitelist is empty in strict mode', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'validation' => [
                        'strict' => true,
                        'whitelist' => [],
                    ],
                ],
            ],
        ]);

        $validator = new EventValidationService($config);

        $result = $validator->validate(new AnalyticsEvent(name: 'custom_event'));

        // Empty whitelist = allow all
        expect($result['valid'])->toBeTrue();
    });

    it('sanitizes parameter keys', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'validation' => [
                        'strict' => false,
                        'whitelist' => [],
                    ],
                ],
            ],
        ]);

        $validator = new EventValidationService($config);

        $event = new AnalyticsEvent(
            name: 'test_event',
            params: [
                'valid_key' => 'value',
                "\x00bad_key" => 'value',
            ],
        );

        $result = $validator->validate($event);

        expect($result['valid'])->toBeTrue();
        expect($result['event']->params)->toHaveKey('valid_key');
        expect($result['event']->params)->not->toHaveKey("\x00bad_key");
    });

    it('detects duplicate events', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'validation' => [
                        'strict' => false,
                        'whitelist' => [],
                        'deduplication_window' => 10,
                    ],
                ],
            ],
        ]);

        $validator = new EventValidationService($config);

        $event = new AnalyticsEvent(name: 'duplicate_test', params: ['key' => 'value']);

        $result1 = $validator->validate($event);
        expect($result1['valid'])->toBeTrue();

        $result2 = $validator->validate($event);
        expect($result2['valid'])->toBeFalse();
        expect($result2['errors'])->toContain('Duplicate event detected within deduplication window');
    });

    it('allows same event after deduplication window', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'validation' => [
                        'strict' => false,
                        'whitelist' => [],
                        'deduplication_window' => 0,
                    ],
                ],
            ],
        ]);

        $validator = new EventValidationService($config);

        $event = new AnalyticsEvent(name: 'dedup_test');

        $result1 = $validator->validate($event);
        $result2 = $validator->validate($event);

        // With 0 window, second should also be valid
        expect($result2['valid'])->toBeTrue();
    });

    it('clears deduplication cache', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'validation' => [
                        'strict' => false,
                        'whitelist' => [],
                        'deduplication_window' => 10,
                    ],
                ],
            ],
        ]);

        $validator = new EventValidationService($config);

        $event = new AnalyticsEvent(name: 'cache_test');

        $validator->validate($event);
        $validator->clearCache();

        $result = $validator->validate($event);
        expect($result['valid'])->toBeTrue();
    });

    it('reports cache size', function () {
        $config = new Repository([]);

        $validator = new EventValidationService($config);

        expect($validator->getCacheSize())->toBe(0);

        $validator->validate(new AnalyticsEvent(name: 'size_test'));

        expect($validator->getCacheSize())->toBe(1);
    });

    it('isValidEventName validates format', function () {
        $config = new Repository([]);

        $validator = new EventValidationService($config);

        expect($validator->isValidEventName('page_view'))->toBeTrue();
        expect($validator->isValidEventName('purchase_v2'))->toBeTrue();
        expect($validator->isValidEventName('PageView'))->toBeFalse();
        expect($validator->isValidEventName('123abc'))->toBeFalse();
        expect($validator->isValidEventName(''))->toBeFalse();
        expect($validator->isValidEventName('event-name'))->toBeFalse();
    });

    it('isWhitelisted checks whitelist', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'validation' => [
                        'strict' => true,
                        'whitelist' => ['allowed_event'],
                    ],
                ],
            ],
        ]);

        $validator = new EventValidationService($config);

        expect($validator->isWhitelisted('allowed_event'))->toBeTrue();
        expect($validator->isWhitelisted('other_event'))->toBeFalse();
    });

    it('isWhitelisted allows all when empty', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'validation' => [
                        'strict' => true,
                        'whitelist' => [],
                    ],
                ],
            ],
        ]);

        $validator = new EventValidationService($config);

        expect($validator->isWhitelisted('any_event'))->toBeTrue();
    });
});

// ── SaaSAnalyticsService Tests ───────────────────────────────────────

describe('SaaSAnalyticsService', function () {
    it('can be instantiated', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        expect($service)->toBeInstanceOf(SaaSAnalyticsService::class);
    });

    it('tracks sign up event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackSignUp('github');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('sign_up');
    });

    it('tracks login event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackLogin('web');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('login');
    });

    it('tracks trial start', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackTrialStart('pro', 14);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('start_trial');
    });

    it('tracks subscription', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackSubscription('business', 99.99, 'USD');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('subscribe');
    });

    it('tracks plan upgrade', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackPlanUpgrade('starter', 'pro');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('plan_upgrade');
    });

    it('tracks cancellation', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackCancellation('pro', 'too_expensive');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('cancellation');
    });

    it('tracks feature usage', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackFeatureUsed('export', 5);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('feature_used');
    });

    it('tracks custom event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackCustomEvent('custom_saaS_event', ['key' => 'value']);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('custom_saas_event');
    });

    it('returns the underlying manager', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        expect($service->getManager())->toBe($manager);
    });
});

// ── SessionTracker Tests ─────────────────────────────────────────────

describe('SessionTracker', function () {
    it('can be instantiated', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new SessionTracker($queue, $manager);

        expect($tracker)->toBeInstanceOf(SessionTracker::class);
    });

    it('starts a session', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new SessionTracker($queue, $manager);

        $tracker->startSession('sess-123', ['source' => 'email']);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('session_start');
        expect($tracker->hasSession('sess-123'))->toBeTrue();
    });

    it('ends a session with duration', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new SessionTracker($queue, $manager);

        $tracker->startSession('sess-456');
        $tracker->trackSessionPageView('sess-456');
        $tracker->trackSessionPageView('sess-456');
        $tracker->endSession('sess-456');

        $layer = $manager->gtm()->getDataLayer();
        // session_start + 2 page_views + session_end = 4 events
        expect($layer)->toHaveCount(4);
        expect($layer[3]['event'])->toBe('session_end');
        expect($layer[3]['eventParams']['session_page_count'])->toBe(2);
        expect($layer[3]['eventParams']['session_duration_ms'])->toBeGreaterThanOrEqual(0);
        expect($tracker->hasSession('sess-456'))->toBeFalse();
    });

    it('tracks session page views', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new SessionTracker($queue, $manager);

        $tracker->startSession('sess-789');
        $tracker->trackSessionPageView('sess-789');
        $tracker->trackSessionPageView('sess-789');
        $tracker->trackSessionPageView('sess-789');

        expect($tracker->getSessionPageCount('sess-789'))->toBe(3);
    });

    it('tracks funnel steps', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new SessionTracker($queue, $manager);

        $tracker->trackFunnelStep('signup', 'landing', 1);
        $tracker->trackFunnelStep('signup', 'form', 2);
        $tracker->trackFunnelStep('signup', 'confirm', 3);
        $tracker->trackFunnelComplete('signup', 3);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(4);
        expect($layer[0]['event'])->toBe('funnel_step');
        expect($layer[3]['event'])->toBe('funnel_complete');
    });

    it('tracks funnel abandonment', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new SessionTracker($queue, $manager);

        $tracker->trackFunnelStep('purchase', 'cart', 1);
        $tracker->trackFunnelStep('purchase', 'checkout', 2);
        $tracker->trackFunnelAbandon('purchase', 'checkout', 4);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(3);
        expect($layer[2]['event'])->toBe('funnel_abandon');
        expect($layer[2]['eventParams']['funnel_abandoned_at_step'])->toBe('checkout');
    });

    it('returns zero for non-existent session duration', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new SessionTracker($queue, $manager);

        expect($tracker->getSessionDuration('nonexistent'))->toBe(0);
        expect($tracker->getSessionPageCount('nonexistent'))->toBe(0);
        expect($tracker->hasSession('nonexistent'))->toBeFalse();
    });

    it('ends non-existent session gracefully', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);
        $tracker = new SessionTracker($queue, $manager);

        // Should not throw
        $tracker->endSession('nonexistent');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('session_end');
        expect($layer[0]['eventParams']['session_duration_ms'])->toBe(0);
    });
});

// ── Health Endpoint Tests ─────────────────────────────────────────────

describe('AnalyticsEventController (Health)', function () {
    it('returns health status', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST1234', 'api_secret' => 'test-secret-key-long'],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $controller = new \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController($manager, $config);

        $response = $controller->health();

        expect($response->getStatusCode())->toBe(200);
        $data = $response->getData(true);
        expect($data['status'])->toBe('ok');
        expect($data['version'])->toBe('1.1.0');
        expect($data['providers'])->toHaveKey('ga4');
        expect($data['providers'])->toHaveKey('gtm');
        expect($data['timestamp'])->not->toBeEmpty();
    });

    it('returns empty providers when none enabled', function () {
        $config = new Repository([]);

        $manager = new AnalyticsManager($config);
        $controller = new \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController($manager, $config);

        $response = $controller->health();

        $data = $response->getData(true);
        expect($data['status'])->toBe('ok');
        expect($data['providers'])->toBeEmpty();
    });
});
