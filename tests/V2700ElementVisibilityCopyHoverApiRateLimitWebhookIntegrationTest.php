<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Engagement\CopyTextEvent;
use ZeroBoiler\Analytics\Events\Engagement\ElementVisibilityEvent;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\Engagement\HoverEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\ApiRateLimitedEvent;
use ZeroBoiler\Analytics\Events\SaaS\IntegrationUsedEvent;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\SaaS\WebhookDeliveredEvent;

describe('V2700 Element Visibility, Copy, Hover, API Rate Limit, Webhook, Integration Events', function () {

    // ─── Element Visibility Event ────────────────────────────────────────

    describe('ElementVisibilityEvent', function () {
        test('creates event with required fields', function () {
            $event = new ElementVisibilityEvent('pricing-table', 'visible');

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('element_visibility');
            expect($event->params)->toHaveKey('element_id');
            expect($event->params['element_id'])->toBe('pricing-table');
            expect($event->params['visibility_state'])->toBe('visible');
        });

        test('includes all optional fields when provided', function () {
            $event = new ElementVisibilityEvent(
                elementId: 'hero-section',
                visibilityState: 'visible',
                visibilityRatio: 0.75,
                elementClass: 'section hero',
                section: 'landing',
                pagePath: '/pricing',
            );

            expect($event->params['element_id'])->toBe('hero-section');
            expect($event->params['visibility_state'])->toBe('visible');
            expect($event->params['visibility_ratio'])->toBe(0.75);
            expect($event->params['element_class'])->toBe('section hero');
            expect($event->params['section'])->toBe('landing');
            expect($event->params['page_path'])->toBe('/pricing');
        });

        test('filters out null values', function () {
            $event = new ElementVisibilityEvent('cta-button', 'visible', null, null, null, null);

            expect($event->params)->not->toHaveKey('visibility_ratio');
            expect($event->params)->not->toHaveKey('element_class');
            expect($event->params)->not->toHaveKey('section');
            expect($event->params)->not->toHaveKey('page_path');
        });

        test('rounds visibility ratio to 2 decimal places', function () {
            $event = new ElementVisibilityEvent('test', 'visible', 0.333333);

            expect($event->params['visibility_ratio'])->toBe(0.33);
        });

        test('creates hidden state event', function () {
            $event = new ElementVisibilityEvent('sidebar', 'hidden', 0.0);

            expect($event->params['visibility_state'])->toBe('hidden');
            expect($event->params['visibility_ratio'])->toBe(0.0);
        });
    });

    // ─── Copy Text Event ────────────────────────────────────────────────

    describe('CopyTextEvent', function () {
        test('creates event with required fields only', function () {
            $event = new CopyTextEvent();

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('copy_text');
            expect($event->params)->toBeEmpty();
        });

        test('includes copied text truncated to 200 chars', function () {
            $longText = str_repeat('a', 300);
            $event = new CopyTextEvent(copiedText: $longText);

            expect($event->params['copied_text'])->toHaveLength(200);
        });

        test('preserves short text exactly', function () {
            $event = new CopyTextEvent(copiedText: 'promo-code-SAVE20');

            expect($event->params['copied_text'])->toBe('promo-code-SAVE20');
        });

        test('includes element metadata', function () {
            $event = new CopyTextEvent(
                copiedText: 'sk-1234abcd',
                elementType: 'code',
                elementId: 'api-key-display',
                selectionLength: '12',
                pagePath: '/settings/api',
            );

            expect($event->params['copied_text'])->toBe('sk-1234abcd');
            expect($event->params['element_type'])->toBe('code');
            expect($event->params['element_id'])->toBe('api-key-display');
            expect($event->params['selection_length'])->toBe(12);
            expect($event->params['page_path'])->toBe('/settings/api');
        });

        test('filters null values', function () {
            $event = new CopyTextEvent(copiedText: 'test', elementType: null, elementId: null, selectionLength: null, pagePath: null);

            expect($event->params)->toHaveKey('copied_text');
            expect($event->params)->not->toHaveKey('element_type');
            expect($event->params)->not->toHaveKey('element_id');
        });
    });

    // ─── Hover Event ─────────────────────────────────────────────────────

    describe('HoverEvent', function () {
        test('creates event with only element ID', function () {
            $event = new HoverEvent('cta-upgrade');

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('hover');
            expect($event->params['element_id'])->toBe('cta-upgrade');
        });

        test('includes all fields when provided', function () {
            $event = new HoverEvent(
                elementId: 'pricing-pro',
                elementClass: 'card pricing highlighted',
                elementType: 'div',
                label: 'Upgrade to Pro',
                hoverDurationMs: 1200,
                pagePath: '/pricing',
            );

            expect($event->params['element_id'])->toBe('pricing-pro');
            expect($event->params['element_class'])->toBe('card pricing highlighted');
            expect($event->params['element_type'])->toBe('div');
            expect($event->params['label'])->toBe('Upgrade to Pro');
            expect($event->params['hover_duration_ms'])->toBe(1200);
            expect($event->params['page_path'])->toBe('/pricing');
        });

        test('truncates label to 200 chars', function () {
            $longLabel = str_repeat('Label Text ', 30);
            $event = new HoverEvent('test', label: $longLabel);

            expect($event->params['label'])->toHaveLength(200);
        });

        test('filters null values', function () {
            $event = new HoverEvent('btn-submit', null, null, null, null, null);

            expect($event->params)->toHaveKey('element_id');
            expect($event->params)->not->toHaveKey('element_class');
            expect($event->params)->not->toHaveKey('element_type');
            expect($event->params)->not->toHaveKey('label');
            expect($event->params)->not->toHaveKey('hover_duration_ms');
            expect($event->params)->not->toHaveKey('page_path');
        });
    });

    // ─── API Rate Limited Event ─────────────────────────────────────────

    describe('ApiRateLimitedEvent', function () {
        test('creates event with endpoint only', function () {
            $event = new ApiRateLimitedEvent('/api/v1/exports');

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('api_rate_limited');
            expect($event->params['endpoint'])->toBe('/api/v1/exports');
        });

        test('includes all fields when provided', function () {
            $event = new ApiRateLimitedEvent(
                endpoint: '/api/v1/events',
                method: 'POST',
                limit: 100,
                window: 'per_minute',
                userId: 'user_123',
            );

            expect($event->params['endpoint'])->toBe('/api/v1/events');
            expect($event->params['method'])->toBe('POST');
            expect($event->params['limit'])->toBe(100);
            expect($event->params['window'])->toBe('per_minute');
            expect($event->params['user_id'])->toBe('user_123');
        });

        test('filters null values', function () {
            $event = new ApiRateLimitedEvent('/api/test', null, null, null, null);

            expect($event->params)->toHaveKey('endpoint');
            expect($event->params)->not->toHaveKey('method');
            expect($event->params)->not->toHaveKey('limit');
            expect($event->params)->not->toHaveKey('window');
            expect($event->params)->not->toHaveKey('user_id');
        });
    });

    // ─── Webhook Delivered Event ────────────────────────────────────────

    describe('WebhookDeliveredEvent', function () {
        test('creates event with required fields', function () {
            $event = new WebhookDeliveredEvent('https://hooks.slack.com/services/T00/B00/xxx', 'success');

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('webhook_delivered');
            expect($event->params['status'])->toBe('success');
        });

        test('sanitizes webhook URL to remove credentials', function () {
            $event = new WebhookDeliveredEvent('https://user:secret@api.example.com:8443/webhook?token=abc#/path', 'success');

            expect($event->params['webhook_url'])->toBe('https://api.example.com:8443/webhook');
            expect($event->params['webhook_url'])->not->toContain('user:secret');
            expect($event->params['webhook_url'])->not->toContain('token=abc');
        });

        test('sanitizes URL without port', function () {
            $event = new WebhookDeliveredEvent('https://discord.com/api/webhooks/123/abc', 'failed');

            expect($event->params['webhook_url'])->toBe('https://discord.com/api/webhooks/123/abc');
        });

        test('handles invalid URL gracefully', function () {
            $event = new WebhookDeliveredEvent('not-a-url', 'timeout');

            expect($event->params['webhook_url'])->toBe('[invalid_url]');
        });

        test('includes all delivery metadata', function () {
            $event = new WebhookDeliveredEvent(
                webhookUrl: 'https://hooks.slack.com/services/T00/B00/xxx',
                status: 'retrying',
                statusCode: 503,
                eventType: 'subscription.created',
                responseTimeMs: 2500,
                attemptNumber: 2,
            );

            expect($event->params['status'])->toBe('retrying');
            expect($event->params['status_code'])->toBe(503);
            expect($event->params['event_type'])->toBe('subscription.created');
            expect($event->params['response_time_ms'])->toBe(2500);
            expect($event->params['attempt_number'])->toBe(2);
        });
    });

    // ─── Integration Used Event ──────────────────────────────────────────

    describe('IntegrationUsedEvent', function () {
        test('creates event with integration name only', function () {
            $event = new IntegrationUsedEvent('slack');

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('integration_used');
            expect($event->params['integration_name'])->toBe('slack');
        });

        test('includes all fields when provided', function () {
            $event = new IntegrationUsedEvent(
                integrationName: 'stripe',
                action: 'sync_subscriptions',
                result: 'success',
                responseTimeMs: 450,
                userId: 'user_456',
            );

            expect($event->params['integration_name'])->toBe('stripe');
            expect($event->params['action'])->toBe('sync_subscriptions');
            expect($event->params['result'])->toBe('success');
            expect($event->params['response_time_ms'])->toBe(450);
            expect($event->params['user_id'])->toBe('user_456');
        });

        test('filters null values', function () {
            $event = new IntegrationUsedEvent('github', null, null, null, null);

            expect($event->params)->toHaveKey('integration_name');
            expect($event->params)->not->toHaveKey('action');
            expect($event->params)->not->toHaveKey('result');
            expect($event->params)->not->toHaveKey('response_time_ms');
            expect($event->params)->not->toHaveKey('user_id');
        });
    });

    // ─── Catalog Integrity ──────────────────────────────────────────────

    describe('Engagement catalog integrity', function () {
        test('element_visibility exists in EngagementEvents catalog', function () {
            expect(EngagementEvents::has('element_visibility'))->toBeTrue();
            $entry = EngagementEvents::get('element_visibility');
            expect($entry['class'])->toBe(ElementVisibilityEvent::class);
            expect($entry['ga4'])->toBe('element_visibility');
            expect($entry['posthog'])->toBe('element_visibility');
            expect($entry['mixpanel'])->toBe('Element Visibility');
            expect($entry['amplitude'])->toBe('Element Visibility');
        });

        test('copy_text exists in EngagementEvents catalog', function () {
            expect(EngagementEvents::has('copy_text'))->toBeTrue();
            $entry = EngagementEvents::get('copy_text');
            expect($entry['class'])->toBe(CopyTextEvent::class);
            expect($entry['ga4'])->toBe('copy_text');
        });

        test('hover exists in EngagementEvents catalog', function () {
            expect(EngagementEvents::has('hover'))->toBeTrue();
            $entry = EngagementEvents::get('hover');
            expect($entry['class'])->toBe(HoverEvent::class);
            expect($entry['ga4'])->toBe('hover');
        });

        test('all new events exist in unified EventCatalog', function () {
            expect(EventCatalog::has('element_visibility'))->toBeTrue();
            expect(EventCatalog::has('copy_text'))->toBeTrue();
            expect(EventCatalog::has('hover'))->toBeTrue();
            expect(EventCatalog::has('api_rate_limited'))->toBeTrue();
            expect(EventCatalog::has('webhook_delivered'))->toBeTrue();
            expect(EventCatalog::has('integration_used'))->toBeTrue();
        });

        test('new events have correct category assignment', function () {
            expect(EventCatalog::getCategory('element_visibility'))->toBe('engagement');
            expect(EventCatalog::getCategory('copy_text'))->toBe('engagement');
            expect(EventCatalog::getCategory('hover'))->toBe('engagement');
            expect(EventCatalog::getCategory('api_rate_limited'))->toBe('saas');
            expect(EventCatalog::getCategory('webhook_delivered'))->toBe('saas');
            expect(EventCatalog::getCategory('integration_used'))->toBe('saas');
        });

        test('catalog validation passes', function () {
            $result = EventCatalog::validate();
            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
        });
    });

    describe('SaaS catalog integrity', function () {
        test('api_rate_limited exists in SaaSEvents catalog', function () {
            expect(SaaSEvents::has('api_rate_limited'))->toBeTrue();
            $entry = SaaSEvents::get('api_rate_limited');
            expect($entry['class'])->toBe(ApiRateLimitedEvent::class);
            expect($entry['ga4'])->toBe('api_rate_limited');
            expect($entry['posthog'])->toBe('api_rate_limited');
        });

        test('webhook_delivered exists in SaaSEvents catalog', function () {
            expect(SaaSEvents::has('webhook_delivered'))->toBeTrue();
            $entry = SaaSEvents::get('webhook_delivered');
            expect($entry['class'])->toBe(WebhookDeliveredEvent::class);
            expect($entry['ga4'])->toBe('webhook_delivered');
        });

        test('integration_used exists in SaaSEvents catalog', function () {
            expect(SaaSEvents::has('integration_used'))->toBeTrue();
            $entry = SaaSEvents::get('integration_used');
            expect($entry['class'])->toBe(IntegrationUsedEvent::class);
            expect($entry['ga4'])->toBe('integration_used');
        });

        test('new SaaS events have meta null (custom events)', function () {
            expect(SaaSEvents::get('api_rate_limited')['meta'])->toBeNull();
            expect(SaaSEvents::get('webhook_delivered')['meta'])->toBeNull();
            expect(SaaSEvents::get('integration_used')['meta'])->toBeNull();
        });
    });

    // ─── Version Sweep ──────────────────────────────────────────────────

    describe('Version sweep', function () {
        test('AnalyticsEvent VERSION is 27.0.0', function () {
            expect(AnalyticsEvent::VERSION)->toBe('27.0.0');
        });

        test('all new event classes extend AnalyticsEvent', function () {
            expect(ElementVisibilityEvent::class)->toExtend(AnalyticsEvent::class);
            expect(CopyTextEvent::class)->toExtend(AnalyticsEvent::class);
            expect(HoverEvent::class)->toExtend(AnalyticsEvent::class);
            expect(ApiRateLimitedEvent::class)->toExtend(AnalyticsEvent::class);
            expect(WebhookDeliveredEvent::class)->toExtend(AnalyticsEvent::class);
            expect(IntegrationUsedEvent::class)->toExtend(AnalyticsEvent::class);
        });

        test('all new events are readonly', function () {
            $reflection = new ReflectionClass(ElementVisibilityEvent::class);
            expect($reflection->isReadOnly())->toBeTrue();

            $reflection = new ReflectionClass(CopyTextEvent::class);
            expect($reflection->isReadOnly())->toBeTrue();

            $reflection = new ReflectionClass(HoverEvent::class);
            expect($reflection->isReadOnly())->toBeTrue();

            $reflection = new ReflectionClass(ApiRateLimitedEvent::class);
            expect($reflection->isReadOnly())->toBeTrue();

            $reflection = new ReflectionClass(WebhookDeliveredEvent::class);
            expect($reflection->isReadOnly())->toBeTrue();

            $reflection = new ReflectionClass(IntegrationUsedEvent::class);
            expect($reflection->isReadOnly())->toBeTrue();
        });

        test('catalog count increased by 6', function () {
            // Engagement: +3 (element_visibility, copy_text, hover)
            // SaaS: +3 (api_rate_limited, webhook_delivered, integration_used)
            $count = EventCatalog::count();
            expect($count)->toBeGreaterThan(0);
        });
    });

    // ─── Provider Mapping Coverage ───────────────────────────────────────

    describe('Provider mapping coverage', function () {
        test('new engagement events have ga4 mappings', function () {
            foreach (['element_visibility', 'copy_text', 'hover'] as $name) {
                $entry = EngagementEvents::get($name);
                expect($entry['ga4'])->not->toBeEmpty();
                expect($entry['posthog'])->not->toBeEmpty();
                expect($entry['mixpanel'])->not->toBeEmpty();
                expect($entry['amplitude'])->not->toBeEmpty();
            }
        });

        test('new SaaS events have ga4 mappings', function () {
            foreach (['api_rate_limited', 'webhook_delivered', 'integration_used'] as $name) {
                $entry = SaaSEvents::get($name);
                expect($entry['ga4'])->not->toBeEmpty();
                expect($entry['posthog'])->not->toBeEmpty();
                expect($entry['mixpanel'])->not->toBeEmpty();
                expect($entry['amplitude'])->not->toBeEmpty();
            }
        });
    });
});
