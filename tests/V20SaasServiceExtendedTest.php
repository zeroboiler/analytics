<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\SaaSAnalyticsService;

describe('v2.0 — SaaSAnalyticsService extended methods', function () {
    it('trackPlanDowngrade dispatches plan_downgrade event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackPlanDowngrade('pro', 'starter');

        $layer = $manager->gtm()->getDataLayer();
        // trackPlanDowngrade dispatches both a typed PlanDowngradeEvent and a raw track
        expect($layer)->toHaveCount(2);

        // The raw track event should have the correct params
        $rawEvent = array_filter($layer, fn (array $e) => ($e['event'] ?? '') === 'plan_downgrade');
        expect($rawEvent)->not->toBeEmpty();

        $first = array_values($rawEvent)[0];
        expect($first['from_plan'])->toBe('pro');
        expect($first['to_plan'])->toBe('starter');
    });

    it('trackLogout dispatches logout event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackLogout('sanctum');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('logout');
        expect($layer[0]['method'])->toBe('sanctum');
    });

    it('trackLogout works without method', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackLogout();

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('logout');
    });

    it('trackTrialEnd dispatches trial_end event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackTrialEnd('expired', 'pro');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('trial_end');
        expect($layer[0]['outcome'])->toBe('expired');
        expect($layer[0]['plan_name'])->toBe('pro');
    });

    it('trackTrialEnd dispatches with converted outcome', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackTrialEnd('converted');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('trial_end');
        expect($layer[0]['outcome'])->toBe('converted');
    });

    it('trackRevenue dispatches revenue_tracked event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackRevenue(5000.00, 'mrr', 'business');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('revenue_tracked');
        expect($layer[0]['value'])->toBe(5000.0);
        expect($layer[0]['revenue_type'])->toBe('mrr');
        expect($layer[0]['plan_name'])->toBe('business');
    });

    it('trackRevenue uses defaults', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackRevenue(99.99);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['revenue_type'])->toBe('one_time');
        expect($layer[0]['currency'])->toBe('USD');
    });

    it('all SaaS methods dispatch correct event names', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new SaaSAnalyticsService($manager);

        $service->trackSignUp('email');
        $service->trackLogin('web');
        $service->trackTrialStart('pro', 14);
        $service->trackSubscription('business', 99.99);
        $service->trackPlanUpgrade('starter', 'pro');
        $service->trackPlanDowngrade('pro', 'starter'); // fires 2 events
        $service->trackCancellation('pro', 'too_expensive');
        $service->trackFeatureUsed('export', 5);
        $service->trackLogout('sanctum');
        $service->trackTrialEnd('converted', 'pro');
        $service->trackRevenue(5000.00, 'mrr', 'business');

        $layer = $manager->gtm()->getDataLayer();
        $events = array_column($layer, 'event');

        expect($events)->toContain('sign_up');
        expect($events)->toContain('login');
        expect($events)->toContain('start_trial');
        expect($events)->toContain('subscribe');
        expect($events)->toContain('plan_upgrade');
        expect($events)->toContain('plan_downgrade');
        expect($events)->toContain('cancellation');
        expect($events)->toContain('feature_used');
        expect($events)->toContain('logout');
        expect($events)->toContain('trial_end');
        expect($events)->toContain('revenue_tracked');
    });
});
