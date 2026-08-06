<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Engagement\ClickEvent;
use ZeroBoiler\Analytics\Events\Engagement\FormStartEvent;
use ZeroBoiler\Analytics\Events\Engagement\FormSubmitEvent;
use ZeroBoiler\Analytics\Events\Engagement\ScrollDepthEvent;
use ZeroBoiler\Analytics\Events\Engagement\SearchEvent;
use ZeroBoiler\Analytics\Events\Engagement\ShareEvent;
use ZeroBoiler\Analytics\Events\Engagement\ErrorEvent;
use ZeroBoiler\Analytics\Events\Engagement\PageViewEvent;
use ZeroBoiler\Analytics\Events\Engagement\SessionStartEvent;
use ZeroBoiler\Analytics\Events\Engagement\SessionEndEvent;
use ZeroBoiler\Analytics\Events\Engagement\WebVitalsEvent;
use ZeroBoiler\Analytics\Events\Engagement\JSErrorEvent;
use ZeroBoiler\Analytics\Events\Engagement\OutboundClickEvent;
use ZeroBoiler\Analytics\Events\Engagement\TimingEvent;
use ZeroBoiler\Analytics\Events\Engagement\ScreenViewEvent;
use ZeroBoiler\Analytics\Events\Engagement\AbTestExposureEvent;
use ZeroBoiler\Analytics\Events\Engagement\NotificationEvent;
use ZeroBoiler\Analytics\Events\Engagement\CampaignAttributionEvent;
use ZeroBoiler\Analytics\Events\Engagement\TimeOnPageEvent;

/**
 * Tests for all 20 typed engagement event classes.
 *
 * Validates constructor parameters, DTO conversion, and param structure
 * for every engagement event in the catalog.
 */
final class V218EngagementEventClassesTest extends TestCase
{
    public function test_click_event_creates_valid_dto(): void
    {
        $event = new ClickEvent(element: 'cta_button', page: '/pricing');
        $dto = $event;

        $this->assertInstanceOf(AnalyticsEvent::class, $dto);
        $this->assertSame('click', $dto->name);
        $this->assertSame('cta_button', $dto->params['element']);
        $this->assertSame('/pricing', $dto->params['page']);
    }

    public function test_scroll_depth_event_creates_valid_dto(): void
    {
        $event = new ScrollDepthEvent(percent: 75, pageLocation: '/docs');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('scroll_depth', $event->name);
        $this->assertSame(75, $event->params['percent']);
    }

    public function test_form_start_event_creates_valid_dto(): void
    {
        $event = new FormStartEvent(formName: 'contact', formId: 'contact-form');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('form_start', $event->name);
        $this->assertSame('contact', $event->params['form_name']);
    }

    public function test_form_submit_event_creates_valid_dto(): void
    {
        $event = new FormSubmitEvent(formName: 'signup', formMethod: 'POST');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('form_submit', $event->name);
        $this->assertSame('POST', $event->params['form_method']);
    }

    public function test_search_event_creates_valid_dto(): void
    {
        $event = new SearchEvent(searchTerm: 'analytics sdk', resultsCount: 42);

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('search', $event->name);
        $this->assertSame('analytics sdk', $event->params['search_term']);
        $this->assertSame(42, $event->params['results_count']);
    }

    public function test_share_event_creates_valid_dto(): void
    {
        $event = new ShareEvent(method: 'twitter', contentType: 'article');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('share', $event->name);
        $this->assertSame('twitter', $event->params['method']);
    }

    public function test_error_event_creates_valid_dto(): void
    {
        $event = new ErrorEvent(message: 'Undefined variable', source: '/app.php', line: 42);

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('error', $event->name);
        $this->assertSame('Undefined variable', $event->params['error_message']);
        $this->assertSame(42, $event->params['error_line']);
    }

    public function test_page_view_event_creates_valid_dto(): void
    {
        $event = new PageViewEvent(title: 'Pricing', location: '/pricing');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('page_view', $event->name);
        $this->assertSame('Pricing', $event->params['page_title']);
    }

    public function test_session_start_event_creates_valid_dto(): void
    {
        $event = new SessionStartEvent(sessionId: 'abc123', source: 'direct');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('session_start', $event->name);
        $this->assertSame('abc123', $event->params['session_id']);
    }

    public function test_session_end_event_creates_valid_dto(): void
    {
        $event = new SessionEndEvent(sessionId: 'abc123', durationSeconds: 300, exitReason: 'idle');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('session_end', $event->name);
        $this->assertSame(300, $event->params['duration_seconds']);
        $this->assertSame('idle', $event->params['end_reason']);
    }

    public function test_web_vitals_event_creates_valid_dto(): void
    {
        $event = new WebVitalsEvent(metricName: 'LCP', metricValue: 2500, rating: 'good');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('web_vitals', $event->name);
        $this->assertSame('LCP', $event->params['metric_name']);
        $this->assertSame('good', $event->params['rating']);
    }

    public function test_js_error_event_creates_valid_dto(): void
    {
        $event = new JSErrorEvent(message: 'TypeError', source: 'app.js', line: 10, errorType: 'unhandled_rejection');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('js_error', $event->name);
        $this->assertSame('TypeError', $event->params['error_message']);
        $this->assertSame('unhandled_rejection', $event->params['error_type']);
    }

    public function test_outbound_click_event_creates_valid_dto(): void
    {
        $event = new OutboundClickEvent(linkUrl: 'https://example.com', linkName: 'docs', linkType: 'external');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('outbound_click', $event->name);
        $this->assertSame('https://example.com', $event->params['link_url']);
    }

    public function test_timing_event_creates_valid_dto(): void
    {
        $event = new TimingEvent(timingName: 'api_request', timingDurationMs: 150);

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('timing', $event->name);
        $this->assertSame(150, $event->params['timing_duration_ms']);
    }

    public function test_screen_view_event_creates_valid_dto(): void
    {
        $event = new ScreenViewEvent(screenName: 'Dashboard', screenClass: 'main');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('screen_view', $event->name);
        $this->assertSame('Dashboard', $event->params['screen_name']);
    }

    public function test_ab_test_exposure_event_creates_valid_dto(): void
    {
        $event = new AbTestExposureEvent(experimentId: 'pricing_v2', variantId: 'variant_a');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('ab_test_exposure', $event->name);
        $this->assertSame('pricing_v2', $event->params['experiment_id']);
    }

    public function test_notification_event_creates_valid_dto(): void
    {
        $event = new NotificationEvent(channel: 'email', action: 'opened', notificationType: 'welcome');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('notification', $event->name);
        $this->assertSame('email', $event->params['notification_channel']);
        $this->assertSame('opened', $event->params['notification_action']);
    }

    public function test_campaign_attribution_event_creates_valid_dto(): void
    {
        $event = new CampaignAttributionEvent(
            utmSource: 'google',
            utmMedium: 'cpc',
            utmCampaign: 'brand',
        );

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('campaign_attribution', $event->name);
        $this->assertSame('google', $event->params['utm_source']);
    }

    public function test_time_on_page_event_creates_valid_dto(): void
    {
        $event = new TimeOnPageEvent(seconds: 45, pagePath: '/docs/getting-started');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('time_on_page', $event->name);
        $this->assertSame(45, $event->params['seconds']);
    }

    public function test_all_engagement_events_have_required_params(): void
    {
        $events = [
            new ClickEvent(),
            new ScrollDepthEvent(),
            new FormStartEvent(),
            new FormSubmitEvent(),
            new SearchEvent(),
            new ShareEvent(),
            new ErrorEvent(),
            new PageViewEvent(),
            new SessionStartEvent(),
            new SessionEndEvent(),
            new WebVitalsEvent(),
            new JSErrorEvent(),
            new OutboundClickEvent(),
            new TimingEvent(),
            new ScreenViewEvent(),
            new AbTestExposureEvent(),
            new NotificationEvent(),
            new CampaignAttributionEvent(),
            new TimeOnPageEvent(),
        ];

        foreach ($events as $event) {
            $this->assertInstanceOf(AnalyticsEvent::class, $event);
            $this->assertNotEmpty($event->name);
        }

        $this->assertCount(19, $events);
    }
}
