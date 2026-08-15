<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Support\EngagementFormatConverter;
use ZeroBoiler\Analytics\Support\SaaSFormatConverter;

/**
 * @covers \ZeroBoiler\Analytics\Support\EngagementFormatConverter
 */
test('page_view converts to GA4 format with url and title', function (): void {
    $result = EngagementFormatConverter::pageViewToGa4([
        'url' => 'https://example.com/dashboard',
        'title' => 'Dashboard — My SaaS',
        'referrer' => 'https://google.com',
    ]);

    expect($result)
        ->toBeArray()
        ->toHaveKey('page_location')
        ->and($result['page_location'])->toBe('https://example.com/dashboard')
        ->and($result['page_title'])->toBe('Dashboard — My SaaS')
        ->and($result['page_referrer'])->toBe('https://google.com');
});

test('page_view converts to GA4 with engagement_time_msec', function (): void {
    $result = EngagementFormatConverter::pageViewToGa4([
        'url' => 'https://example.com/page',
        'engagement_time_msec' => 4500,
    ]);

    expect($result['engagement_time_msec'])->toBe(4500);
});

test('page_view converts to GA4 with page_location alias', function (): void {
    $result = EngagementFormatConverter::pageViewToGa4([
        'page_location' => 'https://example.com/alias',
        'page_title' => 'Alias Page',
    ]);

    expect($result['page_location'])->toBe('https://example.com/alias');
});

test('page_view converts to Meta format', function (): void {
    $result = EngagementFormatConverter::pageViewToMeta([
        'title' => 'My Page',
    ]);

    expect($result)
        ->toBeArray()
        ->toHaveKey('content_name')
        ->and($result['content_name'])->toBe('My Page')
        ->and($result['content_type'])->toBe('page');
});

test('page_view converts to Meta with custom content_type', function (): void {
    $result = EngagementFormatConverter::pageViewToMeta([
        'content_type' => 'landing_page',
    ]);

    expect($result['content_type'])->toBe('landing_page');
});

test('page_view converts to PostHog format', function (): void {
    $result = EngagementFormatConverter::pageViewToPosthog([
        'url' => 'https://example.com/about',
        'referrer' => 'https://twitter.com',
        'title' => 'About Us',
    ]);

    expect($result)
        ->toBeArray()
        ->and($result['$current_url'])->toBe('https://example.com/about')
        ->and($result['$referrer'])->toBe('https://twitter.com')
        ->and($result['$title'])->toBe('About Us');
});

test('page_view converts to PostHog with screen dimensions', function (): void {
    $result = EngagementFormatConverter::pageViewToPosthog([
        'url' => 'https://example.com',
        'screen_width' => 1920,
        'screen_height' => 1080,
    ]);

    expect($result['$screen_width'])->toBe(1920)
        ->and($result['$screen_height'])->toBe(1080);
});

test('scroll_depth converts to GA4 with percent_scrolled', function (): void {
    $result = EngagementFormatConverter::scrollDepthToGa4([
        'percent' => 75,
        'url' => 'https://example.com/article',
    ]);

    expect($result['percent_scrolled'])->toBe(75)
        ->and($result['page_location'])->toBe('https://example.com/article');
});

test('scroll_depth converts to GA4 with scroll_percent alias', function (): void {
    $result = EngagementFormatConverter::scrollDepthToGa4([
        'scroll_percent' => 90,
    ]);

    expect($result['percent_scrolled'])->toBe(90);
});

test('scroll_depth converts to Meta format', function (): void {
    $result = EngagementFormatConverter::scrollDepthToMeta([
        'percent' => 50,
    ]);

    expect($result['scroll_percent'])->toBe(50)
        ->and($result['content_type'])->toBe('scroll');
});

test('scroll_depth converts to PostHog format', function (): void {
    $result = EngagementFormatConverter::scrollDepthToPosthog([
        'percent' => 25,
        'url' => 'https://example.com/page',
    ]);

    expect($result['scroll_depth'])->toBe(25)
        ->and($result['$current_url'])->toBe('https://example.com/page');
});

test('click converts to GA4 with link_url and link_text', function (): void {
    $result = EngagementFormatConverter::clickToGa4([
        'url' => 'https://example.com/pricing',
        'text' => 'View Pricing',
        'outbound' => true,
    ]);

    expect($result['link_url'])->toBe('https://example.com/pricing')
        ->and($result['link_text'])->toBe('View Pricing')
        ->and($result['outbound'])->toBeTrue()
        ->and($result['link_domain'])->toBe('example.com');
});

test('click converts to GA4 with element context', function (): void {
    $result = EngagementFormatConverter::clickToGa4([
        'link_url' => 'https://example.com',
        'element_class' => 'btn btn-primary',
        'element_id' => 'cta-button',
        'element_tag' => 'button',
    ]);

    expect($result['element_class'])->toBe('btn btn-primary')
        ->and($result['element_id'])->toBe('cta-button')
        ->and($result['element_tag'])->toBe('button');
});

test('click converts to GA4 parses domain correctly', function (): void {
    $result = EngagementFormatConverter::clickToGa4([
        'url' => 'https://docs.example.com/guide',
    ]);

    expect($result['link_domain'])->toBe('docs.example.com');
});

test('click converts to Meta format', function (): void {
    $result = EngagementFormatConverter::clickToMeta([
        'text' => 'Buy Now',
        'url' => 'https://example.com/buy',
    ]);

    expect($result['content_name'])->toBe('Buy Now')
        ->and($result['content_category'])->toBe('click')
        ->and($result['link_url'])->toBe('https://example.com/buy');
});

test('click converts to PostHog format', function (): void {
    $result = EngagementFormatConverter::clickToPosthog([
        'url' => 'https://example.com/link',
        'text' => 'Click me',
        'outbound' => false,
        'page_location' => 'https://example.com/page',
    ]);

    expect($result['link_url'])->toBe('https://example.com/link')
        ->and($result['link_text'])->toBe('Click me')
        ->and($result['outbound'])->toBeFalse()
        ->and($result['$current_url'])->toBe('https://example.com/page');
});

test('form_start converts to GA4 format', function (): void {
    $result = EngagementFormatConverter::formStartToGa4([
        'form_id' => 'contact-form',
        'form_name' => 'Contact',
        'form_action' => '/api/contact',
    ]);

    expect($result['form_id'])->toBe('contact-form')
        ->and($result['form_name'])->toBe('Contact')
        ->and($result['form_destination'])->toBe('/api/contact');
});

test('form_start converts to GA4 with element_id alias', function (): void {
    $result = EngagementFormatConverter::formStartToGa4([
        'element_id' => 'login-form',
        'name' => 'Login',
    ]);

    expect($result['form_id'])->toBe('login-form')
        ->and($result['form_name'])->toBe('Login');
});

test('form_start converts to Meta format', function (): void {
    $result = EngagementFormatConverter::formStartToMeta([
        'form_name' => 'Newsletter',
        'form_id' => 'newsletter-form',
    ]);

    expect($result['content_name'])->toBe('Newsletter')
        ->and($result['content_category'])->toBe('form')
        ->and($result['form_id'])->toBe('newsletter-form');
});

test('form_start converts to PostHog format', function (): void {
    $result = EngagementFormatConverter::formStartToPosthog([
        'form_id' => 'signup',
        'page_location' => 'https://example.com/register',
    ]);

    expect($result['form_id'])->toBe('signup')
        ->and($result['$current_url'])->toBe('https://example.com/register');
});

test('form_submit converts to GA4 generate_lead format', function (): void {
    $result = EngagementFormatConverter::formSubmitToGa4([
        'form_id' => 'demo-request',
        'form_name' => 'Demo Request',
        'value' => 250.00,
        'currency' => 'USD',
    ]);

    expect($result['form_id'])->toBe('demo-request')
        ->and($result['form_name'])->toBe('Demo Request')
        ->and($result['value'])->toBe(250.00)
        ->and($result['currency'])->toBe('USD');
});

test('form_submit converts to Meta Lead format', function (): void {
    $result = EngagementFormatConverter::formSubmitToMeta([
        'name' => 'Contact Form',
        'value' => 100.00,
    ]);

    expect($result['content_name'])->toBe('Contact Form')
        ->and($result['content_category'])->toBe('form')
        ->and($result['value'])->toBe(100.00)
        ->and($result['currency'])->toBe('USD');
});

test('form_submit converts to PostHog format with success flag', function (): void {
    $result = EngagementFormatConverter::formSubmitToPosthog([
        'form_id' => 'contact',
        'success' => true,
    ]);

    expect($result['form_id'])->toBe('contact')
        ->and($result['success'])->toBeTrue();
});

test('search converts to GA4 format', function (): void {
    $result = EngagementFormatConverter::searchToGa4([
        'query' => 'analytics tools',
        'results_count' => 42,
        'category' => 'products',
    ]);

    expect($result['search_term'])->toBe('analytics tools')
        ->and($result['number_of_results'])->toBe(42)
        ->and($result['category'])->toBe('products');
});

test('search converts to GA4 with search_term alias', function (): void {
    $result = EngagementFormatConverter::searchToGa4([
        'search_term' => 'laravel',
        'number_of_results' => 10,
    ]);

    expect($result['search_term'])->toBe('laravel');
});

test('search converts to Meta Search format', function (): void {
    $result = EngagementFormatConverter::searchToMeta([
        'query' => 'pricing plans',
        'category' => 'saas',
    ]);

    expect($result['search_string'])->toBe('pricing plans')
        ->and($result['content_category'])->toBe('saas');
});

test('search converts to PostHog $search format', function (): void {
    $result = EngagementFormatConverter::searchToPosthog([
        'term' => 'react',
        'results_count' => 7,
    ]);

    expect($result['$search'])->toBe('react')
        ->and($result['results'])->toBe(7);
});

test('share converts to GA4 format', function (): void {
    $result = EngagementFormatConverter::shareToGa4([
        'method' => 'Twitter',
        'item_id' => 'article-123',
        'item_name' => 'Getting Started',
    ]);

    expect($result['method'])->toBe('Twitter')
        ->and($result['item_id'])->toBe('article-123')
        ->and($result['item_name'])->toBe('Getting Started')
        ->and($result['content_type'])->toBe('page');
});

test('share converts to GA4 with platform alias', function (): void {
    $result = EngagementFormatConverter::shareToGa4([
        'platform' => 'LinkedIn',
    ]);

    expect($result['method'])->toBe('LinkedIn');
});

test('share converts to Meta Share format', function (): void {
    $result = EngagementFormatConverter::shareToMeta([
        'method' => 'Email',
        'content_name' => 'Blog Post',
        'share_url' => 'https://example.com/blog/post',
    ]);

    expect($result['method'])->toBe('Email')
        ->and($result['content_name'])->toBe('Blog Post')
        ->and($result['share_url'])->toBe('https://example.com/blog/post');
});

test('share converts to PostHog format', function (): void {
    $result = EngagementFormatConverter::shareToPosthog([
        'share_url' => 'https://example.com/page',
        'method' => 'Copy',
    ]);

    expect($result['$share'])->toBe('https://example.com/page')
        ->and($result['method'])->toBe('Copy');
});

test('error converts to GA4 format', function (): void {
    $result = EngagementFormatConverter::errorToGa4([
        'message' => 'TypeError: Cannot read property of null',
        'code' => 500,
        'fatal' => true,
        'url' => 'https://example.com/app',
    ]);

    expect($result['error_message'])->toBe('TypeError: Cannot read property of null')
        ->and($result['error_code'])->toBe(500)
        ->and($result['fatal'])->toBeTrue()
        ->and($result['page_location'])->toBe('https://example.com/app');
});

test('error converts to Meta format', function (): void {
    $result = EngagementFormatConverter::errorToMeta([
        'message' => 'Network timeout',
        'source' => 'client',
        'fatal' => false,
    ]);

    expect($result['error_message'])->toBe('Network timeout')
        ->and($result['error_source'])->toBe('client')
        ->and($result['fatal'])->toBeFalse();
});

test('error converts to PostHog $exception format', function (): void {
    $result = EngagementFormatConverter::errorToPosthog([
        'type' => 'TypeError',
        'message' => 'undefined is not a function',
        'stack' => 'at Object.<anonymous> (app.js:42:15)',
        'level' => 'warning',
        'url' => 'https://example.com',
    ]);

    expect($result['$exception_type'])->toBe('TypeError')
        ->and($result['$exception_message'])->toBe('undefined is not a function')
        ->and($result['$exception_stack'])->toBe('at Object.<anonymous> (app.js:42:15)')
        ->and($result['$exception_level'])->toBe('warning')
        ->and($result['$current_url'])->toBe('https://example.com')
        ->and($result['$lib'])->toBe('zeroboiler-analytics-server');
});

test('error converts to PostHog with fatal=true defaults to fatal level', function (): void {
    $result = EngagementFormatConverter::errorToPosthog([
        'message' => 'Crash',
        'fatal' => true,
    ]);

    expect($result['$exception_level'])->toBe('fatal');
});

// ── convertForProvider central dispatch ──────────────────────────

test('convertForProvider dispatches page_view to GA4', function (): void {
    $result = EngagementFormatConverter::convertForProvider('page_view', [
        'url' => 'https://example.com',
        'title' => 'Home',
    ], 'ga4');

    expect($result['page_location'])->toBe('https://example.com')
        ->and($result['page_title'])->toBe('Home');
});

test('convertForProvider dispatches page_view to Meta', function (): void {
    $result = EngagementFormatConverter::convertForProvider('page_view', [
        'title' => 'Pricing',
    ], 'meta');

    expect($result['content_name'])->toBe('Pricing');
});

test('convertForProvider dispatches page_view to PostHog', function (): void {
    $result = EngagementFormatConverter::convertForProvider('page_view', [
        'url' => 'https://example.com/features',
    ], 'posthog');

    expect($result['$current_url'])->toBe('https://example.com/features');
});

test('convertForProvider dispatches click to all providers', function (): void {
    $params = ['url' => 'https://example.com/link', 'text' => 'Click'];

    $ga4 = EngagementFormatConverter::convertForProvider('click', $params, 'ga4');
    $meta = EngagementFormatConverter::convertForProvider('click', $params, 'meta');
    $posthog = EngagementFormatConverter::convertForProvider('click', $params, 'posthog');

    expect($ga4['link_url'])->toBe('https://example.com/link')
        ->and($meta['content_name'])->toBe('Click')
        ->and($posthog['link_text'])->toBe('Click');
});

test('convertForProvider dispatches scroll_depth to GA4', function (): void {
    $result = EngagementFormatConverter::convertForProvider('scroll_depth', [
        'percent' => 90,
    ], 'ga4');

    expect($result['percent_scrolled'])->toBe(90);
});

test('convertForProvider dispatches form_start to Meta', function (): void {
    $result = EngagementFormatConverter::convertForProvider('form_start', [
        'form_name' => 'Signup',
    ], 'meta');

    expect($result['content_name'])->toBe('Signup');
});

test('convertForProvider dispatches form_submit to GA4', function (): void {
    $result = EngagementFormatConverter::convertForProvider('form_submit', [
        'form_id' => 'login',
        'value' => 50,
    ], 'ga4');

    expect($result['form_id'])->toBe('login')
        ->and($result['value'])->toBe(50.0);
});

test('convertForProvider dispatches search to PostHog', function (): void {
    $result = EngagementFormatConverter::convertForProvider('search', [
        'query' => 'saas analytics',
    ], 'posthog');

    expect($result['$search'])->toBe('saas analytics');
});

test('convertForProvider dispatches share to GA4', function (): void {
    $result = EngagementFormatConverter::convertForProvider('share', [
        'method' => 'Facebook',
        'item_id' => 'page-1',
    ], 'ga4');

    expect($result['method'])->toBe('Facebook');
});

test('convertForProvider dispatches error to all providers', function (): void {
    $params = ['message' => 'test error', 'code' => 500];

    $ga4 = EngagementFormatConverter::convertForProvider('error', $params, 'ga4');
    $meta = EngagementFormatConverter::convertForProvider('error', $params, 'meta');
    $posthog = EngagementFormatConverter::convertForProvider('error', $params, 'posthog');

    expect($ga4['error_message'])->toBe('test error')
        ->and($meta['error_message'])->toBe('test error')
        ->and($posthog['$exception_message'])->toBe('test error');
});

test('convertForProvider returns original params for unknown provider', function (): void {
    $params = ['url' => 'https://example.com'];

    $result = EngagementFormatConverter::convertForProvider('page_view', $params, 'plausible');

    expect($result)->toBe($params);
});

test('convertForProvider returns original params for unknown event', function (): void {
    $params = ['custom' => 'data'];

    $result = EngagementFormatConverter::convertForProvider('custom_event', $params, 'ga4');

    expect($result)->toBe($params);
});

// ── Alias handling ──────────────────────────────────────────────────

test('convertForProvider handles js_error alias to error converters', function (): void {
    $params = ['message' => 'Uncaught ReferenceError', 'fatal' => true];

    $ga4 = EngagementFormatConverter::convertForProvider('js_error', $params, 'ga4');
    $posthog = EngagementFormatConverter::convertForProvider('js_error', $params, 'posthog');

    expect($ga4['error_message'])->toBe('Uncaught ReferenceError')
        ->and($posthog['$exception_message'])->toBe('Uncaught ReferenceError');
});

test('convertForProvider handles client_error alias', function (): void {
    $params = ['message' => 'Client error'];

    $ga4 = EngagementFormatConverter::convertForProvider('client_error', $params, 'ga4');

    expect($ga4['error_message'])->toBe('Client error');
});

test('convertForProvider handles outbound_click alias with outbound=true', function (): void {
    $params = ['url' => 'https://external.com', 'text' => 'Link'];

    $ga4 = EngagementFormatConverter::convertForProvider('outbound_click', $params, 'ga4');
    $posthog = EngagementFormatConverter::convertForProvider('outbound_click', $params, 'posthog');

    expect($ga4['outbound'])->toBeTrue()
        ->and($posthog['outbound'])->toBeTrue();
});

test('convertForProvider handles file_download alias', function (): void {
    $params = ['url' => 'https://example.com/report.pdf', 'text' => 'Download'];

    $meta = EngagementFormatConverter::convertForProvider('file_download', $params, 'meta');

    expect($meta['content_category'])->toBe('download');
});

// ── buildProviderEvent ──────────────────────────────────────────────

test('buildProviderEvent creates a ready-to-dispatch AnalyticsEvent', function (): void {
    $event = EngagementFormatConverter::buildProviderEvent(
        'page_view',
        ['url' => 'https://example.com', 'title' => 'Home'],
        'ga4',
        'client-abc',
        'user-123',
    );

    expect($event)
        ->toBeInstanceOf(AnalyticsEvent::class)
        ->and($event->name)->toBe('page_view')
        ->and($event->clientId)->toBe('client-abc')
        ->and($event->userId)->toBe('user-123')
        ->and($event->category)->toBe('engagement')
        ->and($event->params['page_location'])->toBe('https://example.com');
});

test('buildProviderEvent with null IDs', function (): void {
    $event = EngagementFormatConverter::buildProviderEvent(
        'click',
        ['url' => 'https://example.com', 'text' => 'Click'],
        'meta',
    );

    expect($event->clientId)->toBeNull()
        ->and($event->userId)->toBeNull()
        ->and($event->params['content_name'])->toBe('Click');
});

// ── supportedEvents and supports ────────────────────────────────────

test('supportedEvents returns all 12 event names', function (): void {
    $events = EngagementFormatConverter::supportedEvents();

    expect($events)
        ->toBeArray()
        ->toHaveCount(12)
        ->and($events)->toContain('page_view')
        ->and($events)->toContain('scroll_depth')
        ->and($events)->toContain('click')
        ->and($events)->toContain('form_start')
        ->and($events)->toContain('form_submit')
        ->and($events)->toContain('search')
        ->and($events)->toContain('share')
        ->and($events)->toContain('error')
        ->and($events)->toContain('js_error')
        ->and($events)->toContain('client_error')
        ->and($events)->toContain('outbound_click')
        ->and($events)->toContain('file_download');
});

test('supports returns true for known events', function (): void {
    expect(EngagementFormatConverter::supports('page_view'))->toBeTrue()
        ->and(EngagementFormatConverter::supports('click'))->toBeTrue()
        ->and(EngagementFormatConverter::supports('error'))->toBeTrue()
        ->and(EngagementFormatConverter::supports('js_error'))->toBeTrue()
        ->and(EngagementFormatConverter::supports('outbound_click'))->toBeTrue();
});

test('supports returns false for unknown events', function (): void {
    expect(EngagementFormatConverter::supports('custom_event'))->toBeFalse()
        ->and(EngagementFormatConverter::supports('purchase'))->toBeFalse()
        ->and(EngagementFormatConverter::supports(''))->toBeFalse();
});

test('supportedProviders returns all 3 providers', function (): void {
    $providers = EngagementFormatConverter::supportedProviders();

    expect($providers)->toBe(['ga4', 'meta', 'posthog']);
});

// ── Null/missing parameter defaults ────────────────────────────────

test('page_view handles empty params gracefully', function (): void {
    $ga4 = EngagementFormatConverter::pageViewToGa4([]);
    $meta = EngagementFormatConverter::pageViewToMeta([]);
    $posthog = EngagementFormatConverter::pageViewToPosthog([]);

    expect($ga4['page_location'])->toBeNull()
        ->and($ga4['page_title'])->toBeNull()
        ->and($meta['content_name'])->toBeNull()
        ->and($meta['content_type'])->toBe('page')
        ->and($posthog['$current_url'])->toBeNull();
});

test('click handles empty params gracefully', function (): void {
    $result = EngagementFormatConverter::clickToGa4([]);

    expect($result['link_url'])->toBeNull()
        ->and($result['link_text'])->toBeNull()
        ->and($result['link_domain'])->toBeNull()
        ->and($result['outbound'])->toBeFalse();
});

test('search handles empty params gracefully', function (): void {
    $ga4 = EngagementFormatConverter::searchToGa4([]);
    $posthog = EngagementFormatConverter::searchToPosthog([]);

    expect($ga4['search_term'])->toBeNull()
        ->and($ga4['number_of_results'])->toBeNull()
        ->and($posthog['$search'])->toBeNull();
});

test('error handles empty params gracefully', function (): void {
    $posthog = EngagementFormatConverter::errorToPosthog([]);

    expect($posthog['$exception_type'])->toBeNull()
        ->and($posthog['$exception_message'])->toBeNull()
        ->and($posthog['$exception_level'])->toBe('error')
        ->and($posthog['$lib'])->toBe('zeroboiler-analytics-server');
});

// ── Parity with SaaSFormatConverter API surface ───────────────────

test('EngagementFormatConverter has same API as SaaSFormatConverter', function (): void {
    // Both must have: convertForProvider, buildProviderEvent
    expect(method_exists(EngagementFormatConverter::class, 'convertForProvider'))->toBeTrue()
        ->and(method_exists(EngagementFormatConverter::class, 'buildProviderEvent'))->toBeTrue()
        ->and(method_exists(EngagementFormatConverter::class, 'supportedEvents'))->toBeTrue()
        ->and(method_exists(EngagementFormatConverter::class, 'supportedProviders'))->toBeTrue()
        ->and(method_exists(EngagementFormatConverter::class, 'supports'))->toBeTrue();
});

// ── Version consistency ──────────────────────────────────────────

test('EngagementFormatConverter class exists with correct namespace', function (): void {
    expect(class_exists(EngagementFormatConverter::class))->toBeTrue();
});

test('AnalyticsEvent version is consistent', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('159.0.0');
});
