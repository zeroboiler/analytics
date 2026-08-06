<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Engagement\{
    PageViewEvent,
    ScrollDepthEvent,
    ClickEvent,
    FormStartEvent,
    FormSubmitEvent,
    SearchEvent,
    ShareEvent,
    ErrorEvent,
    CampaignAttributionEvent,
    ScreenViewEvent,
    AbTestExposureEvent,
    NotificationEvent,
    WebVitalsEvent,
    JSErrorEvent,
    TimingEvent,
    SessionStartEvent,
    SessionEndEvent,
    OutboundClickEvent,
    TimeOnPageEvent,
};

describe('V33 Engagement Events Expansion', function () {
    it('PageViewEvent has correct name and params', function () {
        $event = new PageViewEvent('Pricing', 'https://example.com/pricing', 'https://google.com');

        expect($event->name)->toBe('page_view');
        expect($event->params)->toHaveKey('page_title');
        expect($event->params['page_title'])->toBe('Pricing');
        expect($event->params)->toHaveKey('page_location');
        expect($event->params['page_location'])->toBe('https://example.com/pricing');
    });

    it('ScrollDepthEvent captures percent and page', function () {
        $event = new ScrollDepthEvent(percent: 75, page: '/blog/article');

        expect($event->name)->toBe('scroll_depth');
        expect($event->params['percent'])->toBe(75);
        expect($event->params['page'])->toBe('/blog/article');
    });

    it('ClickEvent captures element and page', function () {
        $event = new ClickEvent(element: 'cta_button', page: '/pricing');

        expect($event->name)->toBe('click');
        expect($event->params['element'])->toBe('cta_button');
    });

    it('FormStartEvent captures form name and id', function () {
        $event = new FormStartEvent(formName: 'contact', formId: 'form-123');

        expect($event->name)->toBe('form_start');
        expect($event->params['form_name'])->toBe('contact');
        expect($event->params['form_id'])->toBe('form-123');
    });

    it('FormSubmitEvent captures form details', function () {
        $event = new FormSubmitEvent(formName: 'contact', formId: 'form-123', formMethod: 'POST');

        expect($event->name)->toBe('form_submit');
        expect($event->params['form_method'])->toBe('POST');
    });

    it('SearchEvent captures query and result count', function () {
        $event = new SearchEvent(query: 'analytics laravel', results: 42);

        expect($event->name)->toBe('search');
        expect($event->params['search_query'])->toBe('analytics laravel');
        expect($event->params['search_results'])->toBe(42);
    });

    it('ShareEvent captures method and content type', function () {
        $event = new ShareEvent(method: 'twitter', contentType: 'article', itemId: 'post-123');

        expect($event->name)->toBe('share');
        expect($event->params['share_method'])->toBe('twitter');
        expect($event->params['content_type'])->toBe('article');
    });

    it('ErrorEvent captures code, message, and url', function () {
        $event = new ErrorEvent(code: 404, message: 'Not found', url: '/missing');

        expect($event->name)->toBe('error');
        expect($event->params['error_code'])->toBe(404);
        expect($event->params['error_message'])->toBe('Not found');
    });

    it('CampaignAttributionEvent captures UTM params', function () {
        $event = new CampaignAttributionEvent('google', 'cpc', 'spring_sale', 'analytics', 'banner', '/landing');

        expect($event->name)->toBe('campaign_attribution');
        expect($event->params['utm_source'])->toBe('google');
        expect($event->params['utm_medium'])->toBe('cpc');
        expect($event->params['utm_campaign'])->toBe('spring_sale');
        expect($event->params['utm_term'])->toBe('analytics');
        expect($event->params['utm_content'])->toBe('banner');
    });

    it('CampaignAttributionEvent filters null params', function () {
        $event = new CampaignAttributionEvent('direct', '', 'homepage', null, null);

        expect($event->name)->toBe('campaign_attribution');
        expect($event->params)->not->toHaveKey('utm_term');
        expect($event->params)->not->toHaveKey('utm_content');
    });

    it('ScreenViewEvent captures screen name and class', function () {
        $event = new ScreenViewEvent('Dashboard', 'main');

        expect($event->name)->toBe('screen_view');
        expect($event->params['screen_name'])->toBe('Dashboard');
        expect($event->params['screen_class'])->toBe('main');
    });

    it('AbTestExposureEvent captures experiment and variant', function () {
        $event = new AbTestExposureEvent('pricing_redesign', 'variant_b', 'Pricing Redesign');

        expect($event->name)->toBe('ab_test_exposure');
        expect($event->params['experiment_id'])->toBe('pricing_redesign');
        expect($event->params['variant_id'])->toBe('variant_b');
    });

    it('NotificationEvent captures channel, action, and type', function () {
        $event = new NotificationEvent('email', 'sent', 'welcome_email');

        expect($event->name)->toBe('notification');
        expect($event->params['notification_channel'])->toBe('email');
        expect($event->params['notification_action'])->toBe('sent');
        expect($event->params['notification_type'])->toBe('welcome_email');
    });

    it('WebVitalsEvent captures metric name and value', function () {
        $event = new WebVitalsEvent('LCP', 1234.5, '/home', 'good');

        expect($event->name)->toBe('web_vitals');
        expect($event->params['metric_name'])->toBe('LCP');
        expect($event->params['metric_value'])->toBe(1234.5);
        expect($event->params['rating'])->toBe('good');
    });

    it('JSErrorEvent captures JS error details', function () {
        $event = new JSErrorEvent('Undefined variable: user', 'app.js', 42, 10, 'unhandled', '/dashboard', true);

        expect($event->name)->toBe('js_error');
        expect($event->params['error_message'])->toBe('Undefined variable: user');
        expect($event->params['error_source'])->toBe('app.js');
        expect($event->params['error_line'])->toBe(42);
        expect($event->params['error_col'])->toBe(10);
        expect($event->params['error_type'])->toBe('unhandled');
        expect($event->params['page_path'])->toBe('/dashboard');
        expect($event->params['fatal'])->toBe(true);
    });

    it('JSErrorEvent filters null optional params', function () {
        $event = new JSErrorEvent('TypeError');

        expect($event->name)->toBe('js_error');
        expect($event->params['error_message'])->toBe('TypeError');
        expect($event->params)->not->toHaveKey('error_source');
        expect($event->params)->not->toHaveKey('error_line');
    });

    it('TimingEvent captures timing measurement', function () {
        $event = new TimingEvent('api_response', 350, 'api', '/api/users');

        expect($event->name)->toBe('timing');
        expect($event->params['timing_name'])->toBe('api_response');
        expect($event->params['timing_duration_ms'])->toBe(350);
        expect($event->params['timing_category'])->toBe('api');
    });

    it('SessionStartEvent captures session details', function () {
        $event = new SessionStartEvent('sess-abc-123', '/pricing', 'https://google.com', 'organic');

        expect($event->name)->toBe('session_start');
        expect($event->params['session_id'])->toBe('sess-abc-123');
        expect($event->params['source'])->toBe('organic');
    });

    it('SessionEndEvent captures session summary', function () {
        $event = new SessionEndEvent('sess-abc-123', 300000, 12);

        expect($event->name)->toBe('session_end');
        expect($event->params['session_id'])->toBe('sess-abc-123');
        expect($event->params['session_duration_ms'])->toBe(300000);
        expect($event->params['session_page_count'])->toBe(12);
    });

    it('OutboundClickEvent captures link details', function () {
        $event = new OutboundClickEvent('https://docs.example.com', 'Documentation', 'docs_link', '/features');

        expect($event->name)->toBe('outbound_click');
        expect($event->params['link_url'])->toBe('https://docs.example.com');
        expect($event->params['link_text'])->toBe('Documentation');
        expect($event->params['link_name'])->toBe('docs_link');
    });

    it('TimeOnPageEvent captures duration and page', function () {
        $event = new TimeOnPageEvent(45, '/blog/article');

        expect($event->name)->toBe('time_on_page');
        expect($event->params['seconds'])->toBe(45);
        expect($event->params['page'])->toBe('/blog/article');
    });
});

// ── Engagement Events Catalog Integration ────────────────────────────

describe('V33 Engagement Catalog Completeness', function () {
    it('all engagement events are in the catalog', function () {
        $catalog = \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::all();
        $names = \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::names();

        // 20 engagement events total
        expect(\ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::count())->toBe(20);
        expect($names)->toContain('page_view');
        expect($names)->toContain('scroll_depth');
        expect($names)->toContain('click');
        expect($names)->toContain('form_start');
        expect($names)->toContain('form_submit');
        expect($names)->toContain('search');
        expect($names)->toContain('share');
        expect($names)->toContain('error');
        expect($names)->toContain('time_on_page');
        expect($names)->toContain('campaign_attribution');
        expect($names)->toContain('screen_view');
        expect($names)->toContain('ab_test_exposure');
        expect($names)->toContain('notification');
        expect($names)->toContain('web_vitals');
        expect($names)->toContain('js_error');
        expect($names)->toContain('timing');
        expect($names)->toContain('session_start');
        expect($names)->toContain('session_end');
        expect($names)->toContain('outbound_click');
    });

    it('all engagement events have typed classes (not CustomEvent)', function () {
        $catalog = \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::all();

        foreach ($catalog as $name => $entry) {
            expect($entry['class'])
                ->not->toBe(\ZeroBoiler\Analytics\Events\CustomEvent::class)
                ->and($entry['class'])->toBeString();
        }
    });

    it('all engagement events have GA4 mappings', function () {
        $catalog = \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::all();

        foreach ($catalog as $name => $entry) {
            expect($entry['ga4'])->toBeString();
            expect($entry['ga4'])->not->toBeEmpty();
        }
    });

    it('EventCatalog total includes all 49 events', function () {
        $total = \ZeroBoiler\Analytics\Events\EventCatalog::count();

        // 12 ecommerce + 17 saas + 20 engagement = 49
        expect($total)->toBe(49);
    });

    it('EventCatalog has correct category counts', function () {
        $byCategory = \ZeroBoiler\Analytics\Events\EventCatalog::byCategory();

        expect($byCategory)->toHaveKey('ecommerce');
        expect($byCategory)->toHaveKey('saas');
        expect($byCategory)->toHaveKey('engagement');
        expect(count($byCategory['ecommerce']))->toBe(12);
        expect(count($byCategory['saas']))->toBe(17);
        expect(count($byCategory['engagement']))->toBe(20);
    });

    it('engagement events are searchable', function () {
        $results = \ZeroBoiler\Analytics\Events\EventCatalog::search('scroll');
        $names = array_map(fn (array $e): string => $e['name'], $results);

        expect($names)->toContain('scroll_depth');
    });

    it('engagement events are findable by category', function () {
        $engagement = \ZeroBoiler\Analytics\Events\EventCatalog::category('engagement');

        expect($engagement)->toHaveKey('page_view');
        expect($engagement)->toHaveKey('session_start');
        expect($engagement)->toHaveKey('outbound_click');
        expect(count($engagement))->toBe(20);
    });
});

// ── Engagement Events Dispatch via Manager ────────────────────────────

describe('V33 Engagement Events Dispatch', function () {
    it('dispatches PageViewEvent through manager', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->trackEvent(new PageViewEvent('Home', 'https://example.com'));

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('page_view');
    });

    it('dispatches JSErrorEvent through manager', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->trackEvent(new JSErrorEvent('TypeError: undefined', 'app.js', 42));

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('js_error');
    });

    it('dispatches OutboundClickEvent through manager', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->trackEvent(new OutboundClickEvent('https://external.com', 'Link'));

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('outbound_click');
    });

    it('dispatches TimingEvent through manager', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $manager->trackEvent(new TimingEvent('render', 250));

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1);
        expect($layer[0]['event'])->toBe('timing');
    });

    it('eventCatalogSummary returns correct engagement count', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $summary = $manager->eventCatalogSummary();

        expect($summary['engagement'])->toBe(20);
        expect($summary['ecommerce'])->toBe(12);
        expect($summary['saas'])->toBe(17);
        expect($summary['total'])->toBe(49);
    });

    it('eventExists returns true for all engagement events', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        expect($manager->eventExists('js_error'))->toBeTrue();
        expect($manager->eventExists('outbound_click'))->toBeTrue();
        expect($manager->eventExists('timing'))->toBeTrue();
        expect($manager->eventExists('session_start'))->toBeTrue();
        expect($manager->eventExists('session_end'))->toBeTrue();
        expect($manager->eventExists('web_vitals'))->toBeTrue();
        expect($manager->eventExists('nonexistent_event'))->toBeFalse();
    });
});
