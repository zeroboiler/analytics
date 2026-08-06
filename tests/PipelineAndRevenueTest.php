<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Engagement\CampaignAttributionEvent;
use ZeroBoiler\Analytics\Events\SaaS\RevenueEvent;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Pipeline\ConsentFilter;
use ZeroBoiler\Analytics\Pipeline\TimestampEnricher;
use ZeroBoiler\Analytics\Pipeline\UtmEnricher;
use ZeroBoiler\Analytics\Pipeline\UserContextEnricher;
use ZeroBoiler\Analytics\Services\RevenueAnalyticsService;

// ── Event Pipeline Tests ─────────────────────────────────────────────

describe('EventPipeline', function () {
    it('passes event through empty pipeline', function () {
        $pipeline = new EventPipeline;
        $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']);

        $result = $pipeline->process($event);

        expect($result)->not->toBeNull();
        expect($result->name)->toBe('test_event');
        expect($result->params)->toBe(['key' => 'value']);
    });

    it('applies single pipe', function () {
        $pipeline = new EventPipeline;
        $pipeline->pipe(function (AnalyticsEvent $event): AnalyticsEvent {
            return new AnalyticsEvent(
                name: $event->name,
                params: array_merge($event->params, ['enriched' => true]),
                clientId: $event->clientId,
                userId: $event->userId,
            );
        });

        $event = new AnalyticsEvent(name: 'test', params: ['original' => true]);
        $result = $pipeline->process($event);

        expect($result)->not->toBeNull();
        expect($result->params)->toBe([
            'original' => true,
            'enriched' => true,
        ]);
    });

    it('chains multiple pipes in order', function () {
        $pipeline = new EventPipeline;
        $pipeline->pipe(function (AnalyticsEvent $event): AnalyticsEvent {
            return new AnalyticsEvent(
                name: $event->name . '_step1',
                params: $event->params,
                clientId: $event->clientId,
                userId: $event->userId,
            );
        });
        $pipeline->pipe(function (AnalyticsEvent $event): AnalyticsEvent {
            return new AnalyticsEvent(
                name: $event->name . '_step2',
                params: array_merge($event->params, ['processed' => true]),
                clientId: $event->clientId,
                userId: $event->userId,
            );
        });

        $event = new AnalyticsEvent(name: 'base');
        $result = $pipeline->process($event);

        expect($result)->not->toBeNull();
        expect($result->name)->toBe('base_step1_step2');
        expect($result->params)->toBe(['processed' => true]);
    });

    it('drops event when pipe returns null', function () {
        $pipeline = new EventPipeline;
        $pipeline->pipe(function (AnalyticsEvent $event): ?AnalyticsEvent {
            return null; // Drop the event
        });
        $pipeline->pipe(function (AnalyticsEvent $event): AnalyticsEvent {
            // This should never execute
            return new AnalyticsEvent(name: 'should_not_reach', params: []);
        });

        $event = new AnalyticsEvent(name: 'test');
        $result = $pipeline->process($event);

        expect($result)->toBeNull();
    });

    it('reports pipe count', function () {
        $pipeline = new EventPipeline;
        expect($pipeline->pipeCount())->toBe(0);

        $pipeline->pipe(fn (AnalyticsEvent $e) => $e);
        expect($pipeline->pipeCount())->toBe(1);

        $pipeline->pipe(fn (AnalyticsEvent $e) => $e);
        $pipeline->pipe(fn (AnalyticsEvent $e) => $e);
        expect($pipeline->pipeCount())->toBe(3);
    });

    it('flushes all pipes', function () {
        $pipeline = new EventPipeline;
        $pipeline->pipe(fn (AnalyticsEvent $e) => $e);
        $pipeline->pipe(fn (AnalyticsEvent $e) => $e);

        $pipeline->flush();
        expect($pipeline->pipeCount())->toBe(0);
    });

    it('returns self from pipe for fluent interface', function () {
        $pipeline = new EventPipeline;
        $result = $pipeline->pipe(fn (AnalyticsEvent $e) => $e);

        expect($result)->toBe($pipeline);
    });
});

// ── UtmEnricher Tests ────────────────────────────────────────────────

describe('UtmEnricher', function () {
    it('attaches UTM params to event', function () {
        $enricher = new UtmEnricher([
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring_sale',
        ]);

        $event = new AnalyticsEvent(name: 'page_view', params: ['page' => '/home']);
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        expect($result->params['utm_source'])->toBe('google');
        expect($result->params['utm_medium'])->toBe('cpc');
        expect($result->params['utm_campaign'])->toBe('spring_sale');
        expect($result->params['page'])->toBe('/home');
    });

    it('preserves existing params', function () {
        $enricher = new UtmEnricher([
            'utm_source' => 'twitter',
            'utm_content' => 'tweet_link',
        ]);

        $event = new AnalyticsEvent(name: 'click', params: ['element' => 'cta', 'utm_source' => 'manual_override']);
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        // Existing params are preserved; UTM enricher merges (adds new keys)
        expect($result->params['element'])->toBe('cta');
        expect($result->params['utm_source'])->toBe('manual_override');
    });

    it('does nothing when no UTM params in context', function () {
        $enricher = new UtmEnricher(['other_param' => 'value']);

        $event = new AnalyticsEvent(name: 'test', params: ['key' => 'value']);
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        expect($result->params)->toBe(['key' => 'value']);
    });

    it('ignores empty UTM values', function () {
        $enricher = new UtmEnricher([
            'utm_source' => '',
            'utm_medium' => '  ',
        ]);

        $event = new AnalyticsEvent(name: 'test');
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        expect($result->params)->toBe([]);
    });

    it('only extracts known UTM keys', function () {
        $enricher = new UtmEnricher([
            'utm_source' => 'facebook',
            'utm_medium' => 'social',
            'utm_campaign' => 'brand_awareness',
            'utm_term' => 'analytics+tools',
            'utm_content' => 'video_ad',
            'some_other_key' => 'should_not_appear',
        ]);

        $event = new AnalyticsEvent(name: 'test');
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        expect($result->params)->toHaveKey('utm_source');
        expect($result->params)->toHaveKey('utm_medium');
        expect($result->params)->toHaveKey('utm_campaign');
        expect($result->params)->toHaveKey('utm_term');
        expect($result->params)->toHaveKey('utm_content');
        expect($result->params)->not->toHaveKey('some_other_key');
    });
});

// ── UserContextEnricher Tests ──────────────────────────────────────────

describe('UserContextEnricher', function () {
    it('attaches user context to event', function () {
        $enricher = new UserContextEnricher([
            'user_id' => '42',
            'user_email' => 'user@example.com',
            'user_plan' => 'pro',
        ]);

        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        expect($result->params['user_id'])->toBe('42');
        expect($result->params['user_email'])->toBe('user@example.com');
        expect($result->params['user_plan'])->toBe('pro');
    });

    it('sets userId from context when event has none', function () {
        $enricher = new UserContextEnricher([
            'user_id' => '123',
            'user_name' => 'John Doe',
        ]);

        $event = new AnalyticsEvent(name: 'click', params: []);
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        expect($result->userId)->toBe('123');
    });

    it('does not override existing userId', function () {
        $enricher = new UserContextEnricher([
            'user_id' => 'context_user',
        ]);

        $event = new AnalyticsEvent(name: 'click', params: [], userId: 'event_user');
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        expect($result->userId)->toBe('event_user');
    });
});

// ── ConsentFilter Tests ───────────────────────────────────────────────

describe('ConsentFilter', function () {
    it('passes event when consent is granted', function () {
        $filter = new ConsentFilter(true);

        $event = new AnalyticsEvent(name: 'page_view');
        $result = $filter($event);

        expect($result)->not->toBeNull();
        expect($result->name)->toBe('page_view');
    });

    it('drops event when consent is denied', function () {
        $filter = new ConsentFilter(false);

        $event = new AnalyticsEvent(name: 'page_view');
        $result = $filter($event);

        expect($result)->toBeNull();
    });
});

// ── TimestampEnricher Tests ─────────────────────────────────────────────

describe('TimestampEnricher', function () {
    it('adds timestamp and epoch to event', function () {
        $enricher = new TimestampEnricher;

        $event = new AnalyticsEvent(name: 'click');
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        expect($result->params)->toHaveKey('event_timestamp');
        expect($result->params)->toHaveKey('event_epoch');
        expect($result->params['event_epoch'])->toBeInt();
    });

    it('adds session_id when provided', function () {
        $enricher = new TimestampEnricher('session-abc-123');

        $event = new AnalyticsEvent(name: 'click');
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        expect($result->params['session_id'])->toBe('session-abc-123');
    });

    it('does not add session_id when null', function () {
        $enricher = new TimestampEnricher(null);

        $event = new AnalyticsEvent(name: 'click');
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        expect($result->params)->not->toHaveKey('session_id');
    });
});

// ── RevenueEvent Tests ─────────────────────────────────────────────────

describe('RevenueEvent', function () {
    it('creates revenue event with required params', function () {
        $event = new RevenueEvent(amount: 99.99, currency: 'USD', revenueType: 'mrr');

        expect($event->name)->toBe('revenue_tracked');
        expect($event->params['value'])->toBe(99.99);
        expect($event->params['currency'])->toBe('USD');
        expect($event->params['revenue_type'])->toBe('mrr');
    });

    it('includes plan name when provided', function () {
        $event = new RevenueEvent(
            amount: 29.99,
            revenueType: 'subscription',
            planName: 'pro',
        );

        expect($event->params['plan_name'])->toBe('pro');
    });

    it('includes extra params', function () {
        $event = new RevenueEvent(
            amount: 500.0,
            revenueType: 'arr',
            extra: ['subscriber_count' => 150],
        );

        expect($event->params['subscriber_count'])->toBe(150);
    });

    it('defaults currency to USD', function () {
        $event = new RevenueEvent(amount: 10.00);

        expect($event->params['currency'])->toBe('USD');
    });
});

// ── CampaignAttributionEvent Tests ──────────────────────────────────────

describe('CampaignAttributionEvent', function () {
    it('creates campaign attribution event', function () {
        $event = new CampaignAttributionEvent(
            source: 'google',
            medium: 'cpc',
            campaign: 'brand_search',
            term: 'analytics tool',
            content: 'text_ad',
        );

        expect($event->name)->toBe('campaign_attribution');
        expect($event->params['utm_source'])->toBe('google');
        expect($event->params['utm_medium'])->toBe('cpc');
        expect($event->params['utm_campaign'])->toBe('brand_search');
        expect($event->params['utm_term'])->toBe('analytics tool');
        expect($event->params['utm_content'])->toBe('text_ad');
    });

    it('works with minimal params', function () {
        $event = new CampaignAttributionEvent(
            source: 'twitter',
            medium: 'social',
            campaign: 'product_launch',
        );

        expect($event->name)->toBe('campaign_attribution');
        expect($event->params['utm_source'])->toBe('twitter');
        expect($event->params)->not->toHaveKey('utm_term');
        expect($event->params)->not->toHaveKey('utm_content');
        expect($event->params)->not->toHaveKey('landing_page');
    });
});

// ── RevenueAnalyticsService Tests ──────────────────────────────────────

describe('RevenueAnalyticsService', function () {
    it('tracks MRR with subscriber count', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager, 'USD');

        $service->trackMRR(5000.0, 42);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('revenue_tracked');
        expect($layer[0]['eventParams']['value'])->toBe(5000.0);
        expect($layer[0]['eventParams']['revenue_type'])->toBe('mrr');
        expect($layer[0]['eventParams']['subscriber_count'])->toBe(42);
        expect($layer[0]['eventParams']['currency'])->toBe('USD');
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
        $service = new RevenueAnalyticsService($manager, 'EUR');

        $service->trackOneTime(149.99, 'consulting_fee');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['eventParams']['value'])->toBe(149.99);
        expect($layer[0]['eventParams']['revenue_type'])->toBe('one_time');
        expect($layer[0]['eventParams']['currency'])->toBe('EUR');
    });

    it('tracks upgrade revenue with diff', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager);

        $service->trackUpgradeRevenue(99.99, 29.99, 'starter', 'pro');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['eventParams']['value'])->toBe(70.0); // 99.99 - 29.99
        expect($layer[0]['eventParams']['revenue_type'])->toBe('upgrade');
        expect($layer[0]['eventParams']['previous_plan'])->toBe('starter');
        expect($layer[0]['eventParams']['new_amount'])->toBe(99.99);
        expect($layer[0]['eventParams']['previous_amount'])->toBe(29.99);
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
        expect($layer)->toHaveCount(1);
        expect($layer[0]['eventParams']['value'])->toBe(29.99);
        expect($layer[0]['eventParams']['revenue_type'])->toBe('churn');
        expect($layer[0]['eventParams']['plan_name'])->toBe('pro');
        expect($layer[0]['eventParams']['churn_reason'])->toBe('too_expensive');
    });

    it('tracks addon revenue', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager);

        $service->trackAddon(9.99, 'extra_storage', 'pro');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['eventParams']['value'])->toBe(9.99);
        expect($layer[0]['eventParams']['revenue_type'])->toBe('addon');
        expect($layer[0]['eventParams']['addon_name'])->toBe('extra_storage');
        expect($layer[0]['eventParams']['plan_name'])->toBe('pro');
    });

    it('exposes underlying manager', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new RevenueAnalyticsService($manager);

        expect($service->getManager())->toBe($manager);
    });
});

// ── Pipeline Integration Tests ─────────────────────────────────────────

describe('Pipeline Integration', function () {
    it('full pipeline with UTM + consent + timestamp', function () {
        $pipeline = new EventPipeline;
        $pipeline->pipe(new UtmEnricher([
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ]));
        $pipeline->pipe(new ConsentFilter(true));
        $pipeline->pipe(new TimestampEnricher('sess-123'));

        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 49.99]);
        $result = $pipeline->process($event);

        expect($result)->not->toBeNull();
        expect($result->params['utm_source'])->toBe('google');
        expect($result->params['utm_medium'])->toBe('cpc');
        expect($result->params['value'])->toBe(49.99);
        expect($result->params['event_timestamp'])->toBeString();
        expect($result->params['session_id'])->toBe('sess-123');
    });

    it('pipeline drops event when consent denied', function () {
        $pipeline = new EventPipeline;
        $pipeline->pipe(new ConsentFilter(false));
        $pipeline->pipe(new TimestampEnricher);

        $event = new AnalyticsEvent(name: 'page_view');
        $result = $pipeline->process($event);

        expect($result)->toBeNull();
    });

    it('withDefaults creates pre-configured pipeline', function () {
        $pipeline = EventPipeline::withDefaults([
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
        ]);

        expect($pipeline->pipeCount())->toBe(4);

        $event = new AnalyticsEvent(name: 'click');
        $result = $pipeline->process($event);

        expect($result)->not->toBeNull();
        expect($result->params['utm_source'])->toBe('newsletter');
        expect($result->params)->toHaveKey('event_timestamp');
    });
});
