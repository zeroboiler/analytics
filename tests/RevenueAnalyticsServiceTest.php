<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\RevenueEvent;
use ZeroBoiler\Analytics\Events\Engagement\CampaignAttributionEvent;
use ZeroBoiler\Analytics\Services\RevenueAnalyticsService;

// ── RevenueEvent Tests ─────────────────────────────────────────────

describe('RevenueEvent', function () {
    it('creates with amount and currency', function () {
        $event = new RevenueEvent(amount: 99.99, currency: 'EUR');

        expect($event->name)->toBe('revenue_tracked')
            ->and($event->params['value'])->toBe(99.99)
            ->and($event->params['currency'])->toBe('EUR');
    });

    it('defaults to USD currency', function () {
        $event = new RevenueEvent(amount: 50.00);

        expect($event->params['currency'])->toBe('USD');
    });

    it('defaults to one_time revenue type', function () {
        $event = new RevenueEvent(amount: 25.00);

        expect($event->params['revenue_type'])->toBe('one_time');
    });

    it('accepts custom revenue type', function () {
        $event = new RevenueEvent(amount: 100.00, revenueType: 'mrr');

        expect($event->params['revenue_type'])->toBe('mrr');
    });

    it('includes plan name when provided', function () {
        $event = new RevenueEvent(amount: 29.99, planName: 'pro');

        expect($event->params['plan_name'])->toBe('pro');
    });

    it('filters out null plan name', function () {
        $event = new RevenueEvent(amount: 10.00);

        expect($event->params)->not->toHaveKey('plan_name');
    });

    it('includes user ID when provided', function () {
        $event = new RevenueEvent(amount: 50.00, userId: 'user-123');

        expect($event->params['user_id'])->toBe('user-123');
    });

    it('includes extra params', function () {
        $event = new RevenueEvent(
            amount: 100.00,
            extra: ['subscriber_count' => 42, 'region' => 'EU'],
        );

        expect($event->params['subscriber_count'])->toBe(42)
            ->and($event->params['region'])->toBe('EU');
    });

    it('extends AnalyticsEvent', function () {
        $event = new RevenueEvent(amount: 10.00);

        expect($event)->toBeInstanceOf(AnalyticsEvent::class);
    });

    it('is readonly and final', function () {
        $reflection = new ReflectionClass(RevenueEvent::class);

        expect($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->isFinal())->toBeTrue();
    });
});

// ── CampaignAttributionEvent Tests ───────────────────────────────────

describe('CampaignAttributionEvent', function () {
    it('creates with UTM parameters', function () {
        $event = new CampaignAttributionEvent(
            source: 'google',
            medium: 'cpc',
            campaign: 'summer_sale',
        );

        expect($event->name)->toBe('campaign_attribution')
            ->and($event->params['utm_source'])->toBe('google')
            ->and($event->params['utm_medium'])->toBe('cpc')
            ->and($event->params['utm_campaign'])->toBe('summer_sale');
    });

    it('includes optional term and content', function () {
        $event = new CampaignAttributionEvent(
            source: 'google',
            medium: 'cpc',
            campaign: 'brand_keywords',
            term: 'analytics tool',
            content: 'text_ad',
        );

        expect($event->params['utm_term'])->toBe('analytics tool')
            ->and($event->params['utm_content'])->toBe('text_ad');
    });

    it('filters out null optional params', function () {
        $event = new CampaignAttributionEvent(
            source: 'twitter',
            medium: 'social',
            campaign: 'launch',
        );

        expect($event->params)->not->toHaveKey('utm_term')
            ->and($event->params)->not->toHaveKey('utm_content')
            ->and($event->params)->not->toHaveKey('landing_page');
    });

    it('includes landing page when provided', function () {
        $event = new CampaignAttributionEvent(
            source: 'newsletter',
            medium: 'email',
            campaign: 'weekly_digest',
            landingPage: 'https://example.com/pricing',
        );

        expect($event->params['landing_page'])->toBe('https://example.com/pricing');
    });

    it('extends AnalyticsEvent', function () {
        $event = new CampaignAttributionEvent('src', 'med', 'camp');

        expect($event)->toBeInstanceOf(AnalyticsEvent::class);
    });

    it('is readonly and final', function () {
        $reflection = new ReflectionClass(CampaignAttributionEvent::class);

        expect($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->isFinal())->toBeTrue();
    });
});

// ── RevenueAnalyticsService Tests ───────────────────────────────────

describe('RevenueAnalyticsService', function () {
    it('can be instantiated', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager);

        expect($service)->toBeInstanceOf(RevenueAnalyticsService::class);
    });

    it('accepts custom default currency', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager, 'EUR');

        expect($service)->toBeInstanceOf(RevenueAnalyticsService::class);
    });

    it('tracks MRR with subscriber count', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager);

        $service->trackMRR(5000.00, 120);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('revenue_tracked');
        expect($layer[0]['eventParams']['value'])->toBe(5000.00);
        expect($layer[0]['eventParams']['currency'])->toBe('USD');
        expect($layer[0]['eventParams']['revenue_type'])->toBe('mrr');
        expect($layer[0]['eventParams']['subscriber_count'])->toBe(120);
    });

    it('tracks ARR with subscriber count', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager);

        $service->trackARR(60000.00, 120);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer[0]['eventParams']['revenue_type'])->toBe('arr');
        expect($layer[0]['eventParams']['value'])->toBe(60000.00);
    });

    it('tracks one-time revenue', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager);

        $service->trackOneTime(49.99, 'setup fee');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer[0]['eventParams']['revenue_type'])->toBe('one_time');
        expect($layer[0]['eventParams']['description'])->toBe('setup fee');
    });

    it('tracks add-on revenue', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager);

        $service->trackAddon(15.00, 'extra_storage', 'pro');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer[0]['eventParams']['revenue_type'])->toBe('addon');
        expect($layer[0]['eventParams']['addon_name'])->toBe('extra_storage');
        expect($layer[0]['eventParams']['plan_name'])->toBe('pro');
    });

    it('tracks upgrade revenue with plan details', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager);

        $service->trackUpgradeRevenue(29.99, 9.99, 'starter', 'pro');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer[0]['eventParams']['revenue_type'])->toBe('upgrade');
        expect($layer[0]['eventParams']['value'])->toBe(20.00); // 29.99 - 9.99
        expect($layer[0]['eventParams']['previous_plan'])->toBe('starter');
        expect($layer[0]['eventParams']['new_amount'])->toBe(29.99);
        expect($layer[0]['eventParams']['previous_amount'])->toBe(9.99);
    });

    it('tracks churn revenue', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager);

        $service->trackChurnRevenue(29.99, 'pro', 'too_expensive');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer[0]['eventParams']['revenue_type'])->toBe('churn');
        expect($layer[0]['eventParams']['plan_name'])->toBe('pro');
        expect($layer[0]['eventParams']['churn_reason'])->toBe('too_expensive');
    });

    it('tracks downgrade revenue', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager);

        $service->trackDowngradeRevenue(9.99, 29.99, 'pro', 'starter');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer[0]['eventParams']['revenue_type'])->toBe('downgrade');
        expect($layer[0]['eventParams']['value'])->toBe(20.00); // 29.99 - 9.99
    });

    it('uses custom currency when provided', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager, 'EUR');

        $service->trackMRR(5000.00, 100);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer[0]['eventParams']['currency'])->toBe('EUR');
    });

    it('allows currency override per method', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager, 'USD');

        $service->trackMRR(5000.00, 100, 'GBP');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer[0]['eventParams']['currency'])->toBe('GBP');
    });

    it('tracks custom revenue event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager);

        $service->trackCustom(75.00, 'expansion', 'enterprise', ['team_size' => 10]);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer[0]['eventParams']['revenue_type'])->toBe('expansion');
        expect($layer[0]['eventParams']['plan_name'])->toBe('enterprise');
        expect($layer[0]['eventParams']['team_size'])->toBe(10);
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
        $service = new RevenueAnalyticsService($manager);

        expect($service->getManager())->toBe($manager);
    });
});
