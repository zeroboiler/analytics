<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\Support\AnalyticsEventNameRule;
use ZeroBoiler\Analytics\Support\EventTransformer;
use ZeroBoiler\Analytics\Support\WebhookSignatureValidator;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

// ── AnalyticsConfig Tests ──────────────────────────────────────────

describe('AnalyticsConfig', function () {
    it('reads default values when config is empty', function () {
        $config = new AnalyticsConfig(new Repository([]));

        expect($config->ga4Enabled())->toBeFalse();
        expect($config->ga4MeasurementId())->toBe('');
        expect($config->gtmEnabled())->toBeFalse();
        expect($config->metaPixelEnabled())->toBeFalse();
        expect($config->plausibleEnabled())->toBeFalse();
        expect($config->posthogEnabled())->toBeFalse();
        expect($config->webhookEnabled())->toBeFalse();
    });

    it('reads GA4 config values', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => [
                        'enabled' => true,
                        'measurement_id' => 'G-ABC123',
                        'api_secret' => 'secret_key',
                    ],
                ],
            ],
        ]));

        expect($config->ga4Enabled())->toBeTrue();
        expect($config->ga4MeasurementId())->toBe('G-ABC123');
        expect($config->ga4ApiSecret())->toBe('secret_key');
    });

    it('reads GTM config values', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => [
                        'enabled' => true,
                        'container_id' => 'GTM-TEST',
                    ],
                ],
            ],
        ]));

        expect($config->gtmEnabled())->toBeTrue();
        expect($config->gtmContainerId())->toBe('GTM-TEST');
    });

    it('reads Meta Pixel config values', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'meta_pixel' => [
                        'enabled' => true,
                        'id' => '123456789',
                        'access_token' => 'token',
                    ],
                ],
            ],
        ]));

        expect($config->metaPixelEnabled())->toBeTrue();
        expect($config->metaPixelId())->toBe('123456789');
        expect($config->metaPixelAccessToken())->toBe('token');
    });

    it('reads consent defaults', function () {
        $denied = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'consent' => ['default' => 'denied'],
                ],
            ],
        ]));

        expect($denied->consentDefault())->toBe('denied');
        expect($denied->consentDefaultDenied())->toBeTrue();

        $granted = new AnalyticsConfig(new Repository([]));

        expect($granted->consentDefault())->toBe('granted');
        expect($granted->consentDefaultDenied())->toBeFalse();
    });

    it('reads queue config', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => [
                        'enabled' => false,
                        'queue' => 'high-priority',
                        'connection' => 'redis',
                    ],
                ],
            ],
        ]));

        expect($config->queueEnabled())->toBeFalse();
        expect($config->queueName())->toBe('high-priority');
        expect($config->queueConnection())->toBe('redis');
    });

    it('reads identity config', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'identity' => [
                        'cookie_name' => 'custom_id',
                        'cookie_ttl' => 10080,
                        'cookie_secure' => false,
                        'cookie_samesite' => 'Strict',
                    ],
                ],
            ],
        ]));

        expect($config->identityCookieName())->toBe('custom_id');
        expect($config->identityCookieTtl())->toBe(10080);
        expect($config->identityCookieSecure())->toBeFalse();
        expect($config->identityCookieSameSite())->toBe('Strict');
    });

    it('reads API config', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'api' => [
                        'enabled' => true,
                        'throttle' => 120,
                        'base_url' => '/v2/analytics',
                    ],
                ],
            ],
        ]));

        expect($config->apiEnabled())->toBeTrue();
        expect($config->apiThrottle())->toBe(120);
        expect($config->apiBaseUrl())->toBe('/v2/analytics');
    });

    it('reads ecommerce config', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ecommerce' => [
                        'currency' => 'EUR',
                        'brand' => 'Acme',
                        'tax_behavior' => 'exclusive',
                    ],
                ],
            ],
        ]));

        expect($config->ecommerceCurrency())->toBe('EUR');
        expect($config->ecommerceBrand())->toBe('Acme');
        expect($config->ecommerceTaxBehavior())->toBe('exclusive');
    });

    it('reads debug config', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'debug' => [
                        'enabled' => true,
                        'log_events' => true,
                    ],
                ],
            ],
        ]));

        expect($config->debugEnabled())->toBeTrue();
        expect($config->debugLogEvents())->toBeTrue();
    });

    it('reads sampling config', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'sampling' => [
                        'enabled' => true,
                        'rate' => 0.1,
                        'deterministic' => false,
                    ],
                ],
            ],
        ]));

        expect($config->samplingEnabled())->toBeTrue();
        expect($config->samplingRate())->toBe(0.1);
        expect($config->samplingDeterministic())->toBeFalse();
    });

    it('reads PII config', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'pii_sanitization' => [
                        'enabled' => true,
                        'strategy' => 'remove',
                        'custom_fields' => ['ssn', 'phone'],
                    ],
                ],
            ],
        ]));

        expect($config->piiEnabled())->toBeTrue();
        expect($config->piiStrategy())->toBe('remove');
        expect($config->piiCustomFields())->toBe(['ssn', 'phone']);
    });

    it('reads auto-track config', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'auto_track' => [
                        'enabled' => true,
                        'events' => [
                            'auth.login' => true,
                            'team.invited' => true,
                        ],
                        'models' => [],
                        'event_map' => [],
                    ],
                ],
            ],
        ]));

        expect($config->autoTrackEnabled())->toBeTrue();
        expect($config->autoTrackEvents())->toHaveCount(2);
        expect($config->autoTrackModels())->toBeEmpty();
        expect($config->autoTrackEventMap())->toBeEmpty();
    });

    it('reads client auto-track config', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'client_auto_track' => [
                        'page_views' => false,
                        'scroll_depth' => false,
                        'form_tracking' => false,
                        'error_tracking' => false,
                        'link_tracking' => true,
                        'session_tracking' => false,
                        'idle_timeout' => 600,
                        'error_ignore_patterns' => ['ResizeObserver'],
                    ],
                ],
            ],
        ]));

        expect($config->clientAutoTrackPageViews())->toBeFalse();
        expect($config->clientAutoTrackLinkTracking())->toBeTrue();
        expect($config->clientAutoTrackIdleTimeout())->toBe(600);
        expect($config->clientAutoTrackErrorIgnorePatterns())->toBe(['ResizeObserver']);
    });

    it('reads performance config', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'performance' => [
                        'enabled' => true,
                        'track_lcp' => true,
                        'track_fcp' => true,
                        'send_to_server' => false,
                    ],
                ],
            ],
        ]));

        expect($config->performanceEnabled())->toBeTrue();
        expect($config->performanceTrackLcp())->toBeTrue();
        expect($config->performanceTrackFcp())->toBeTrue();
        expect($config->performanceSendToServer())->toBeFalse();
    });

    it('generates summary array', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST'],
                    'queue' => ['enabled' => true, 'queue' => 'analytics'],
                ],
            ],
        ]));

        $summary = $config->summary();

        expect($summary)->toBeArray();
        expect($summary)->toHaveKey('ga4');
        expect($summary)->toHaveKey('gtm');
        expect($summary)->toHaveKey('consent');
        expect($summary)->toHaveKey('queue');
        expect($summary)->toHaveKey('identity');
        expect($summary)->toHaveKey('api');
        expect($summary)->toHaveKey('auto_track');
        expect($summary)->toHaveKey('ecommerce');
        expect($summary)->toHaveKey('sampling');
        expect($summary)->toHaveKey('pii_sanitization');
        expect($summary['ga4']['enabled'])->toBeTrue();
        expect($summary['ga4']['measurement_id'])->toBe('G-TEST');
    });

    it('has() checks config existence', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => true],
                ],
            ],
        ]));

        expect($config->has('ga4.enabled'))->toBeTrue();
        expect($config->has('plausible.api_key'))->toBeFalse();
    });

    it('get() returns raw config values', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'custom_key' => 'custom_value',
                ],
            ],
        ]));

        expect($config->get('custom_key'))->toBe('custom_value');
        expect($config->get('nonexistent', 'fallback'))->toBe('fallback');
    });

    it('reads replay queue config', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'replay' => [
                        'enabled' => true,
                        'max_attempts' => 5,
                        'base_delay' => 2.0,
                        'max_delay' => 120.0,
                        'jitter' => 0.3,
                    ],
                ],
            ],
        ]));

        expect($config->replayEnabled())->toBeTrue();
        expect($config->replayMaxAttempts())->toBe(5);
        expect($config->replayBaseDelay())->toBe(2.0);
        expect($config->replayMaxDelay())->toBe(120.0);
        expect($config->replayJitter())->toBe(0.3);
    });

    it('reads webhook config', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'webhook' => [
                        'enabled' => true,
                        'url' => 'https://hook.example.com',
                        'secret' => 'wh_secret',
                        'timeout' => 10,
                        'retries' => 3,
                        'sign' => true,
                        'headers' => ['X-Custom' => 'value'],
                    ],
                ],
            ],
        ]));

        expect($config->webhookEnabled())->toBeTrue();
        expect($config->webhookUrl())->toBe('https://hook.example.com');
        expect($config->webhookSecret())->toBe('wh_secret');
        expect($config->webhookTimeout())->toBe(10);
        expect($config->webhookRetries())->toBe(3);
        expect($config->webhookSign())->toBeTrue();
        expect($config->webhookHeaders())->toBe(['X-Custom' => 'value']);
    });

    it('reads audit log config', function () {
        $config = new AnalyticsConfig(new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'audit_log' => [
                        'enabled' => true,
                        'priority' => 200,
                    ],
                ],
            ],
        ]));

        expect($config->auditLogEnabled())->toBeTrue();
        expect($config->auditLogPriority())->toBe(200);
    });
});

// ── AnalyticsEventNameRule Tests ──────────────────────────────────────

describe('AnalyticsEventNameRule', function () {
    it('accepts valid event names', function () {
        $rule = new AnalyticsEventNameRule();

        expect($rule->passes('name', 'page_view'))->toBeTrue();
        expect($rule->passes('name', 'purchase'))->toBeTrue();
        expect($rule->passes('name', 'ab_test_exposure'))->toBeTrue();
        expect($rule->passes('name', 'button_click_v2'))->toBeTrue();
    });

    it('rejects empty strings', function () {
        $rule = new AnalyticsEventNameRule();

        expect($rule->passes('name', ''))->toBeFalse();
    });

    it('rejects non-string values', function () {
        $rule = new AnalyticsEventNameRule();

        expect($rule->passes('name', null))->toBeFalse();
        expect($rule->passes('name', 123))->toBeFalse();
        expect($rule->passes('name', ['array']))->toBeFalse();
    });

    it('rejects uppercase event names', function () {
        $rule = new AnalyticsEventNameRule();

        expect($rule->passes('name', 'PageView'))->toBeFalse();
        expect($rule->passes('name', 'PURCHASE'))->toBeFalse();
    });

    it('rejects names with hyphens', function () {
        $rule = new AnalyticsEventNameRule();

        expect($rule->passes('name', 'page-view'))->toBeFalse();
        expect($rule->passes('name', 'add-to-cart'))->toBeFalse();
    });

    it('rejects names starting with numbers', function () {
        $rule = new AnalyticsEventNameRule();

        expect($rule->passes('name', '1event'))->toBeFalse();
        expect($rule->passes('name', '123abc'))->toBeFalse();
    });

    it('rejects names with special characters', function () {
        $rule = new AnalyticsEventNameRule();

        expect($rule->passes('name', 'event!name'))->toBeFalse();
        expect($rule->passes('name', 'event.name'))->toBeFalse();
        expect($rule->passes('name', 'event name'))->toBeFalse();
    });

    it('rejects names exceeding max length', function () {
        $rule = new AnalyticsEventNameRule();
        $longName = str_repeat('a', 101);

        expect($rule->passes('name', $longName))->toBeFalse();
    });

    it('accepts exactly max length (100 chars)', function () {
        $rule = new AnalyticsEventNameRule();
        $name = 'a'.str_repeat('b', 99);

        expect($rule->passes('name', $name))->toBeTrue();
    });

    it('accepts single character name', function () {
        $rule = new AnalyticsEventNameRule();

        expect($rule->passes('name', 'a'))->toBeTrue();
    });

    it('validates against event catalog when enabled', function () {
        $rule = new AnalyticsEventNameRule(checkCatalog: true);

        expect($rule->passes('name', 'page_view'))->toBeTrue();
        expect($rule->passes('name', 'purchase'))->toBeTrue();
        expect($rule->passes('name', 'sign_up'))->toBeTrue();
        expect($rule->passes('name', 'nonexistent_event_xyz'))->toBeFalse();
    });

    it('validates against strict whitelist when enabled', function () {
        $rule = new AnalyticsEventNameRule(
            strict: true,
            whitelist: ['page_view', 'purchase'],
        );

        expect($rule->passes('name', 'page_view'))->toBeTrue();
        expect($rule->passes('name', 'purchase'))->toBeTrue();
        expect($rule->passes('name', 'sign_up'))->toBeFalse();
    });

    it('strict mode with empty whitelist allows all valid names', function () {
        $rule = new AnalyticsEventNameRule(
            strict: true,
            whitelist: [],
        );

        expect($rule->passes('name', 'any_valid_name'))->toBeTrue();
        expect($rule->passes('name', 'INVALID'))->toBeFalse();
    });

    it('returns appropriate error messages', function () {
        $basic = new AnalyticsEventNameRule();
        expect($basic->message())->toContain('valid analytics event name');

        $strict = new AnalyticsEventNameRule(strict: true);
        expect($strict->message())->toContain('whitelisted');

        $catalog = new AnalyticsEventNameRule(checkCatalog: true);
        expect($catalog->message())->toContain('catalog');
    });
});

// ── WebhookSignatureValidator Tests ──────────────────────────────────

describe('WebhookSignatureValidator', function () {
    it('validates correct HMAC-SHA256 signature', function () {
        $payload = '{"event":"purchase","value":99.99}';
        $secret = 'test_secret_123';

        $signature = WebhookSignatureValidator::sign($payload, $secret);

        expect(WebhookSignatureValidator::valid($payload, $signature, $secret))->toBeTrue();
    });

    it('rejects incorrect signature', function () {
        $payload = '{"event":"purchase","value":99.99}';
        $secret = 'test_secret_123';

        expect(WebhookSignatureValidator::valid($payload, 'invalid_signature', $secret))->toBeFalse();
    });

    it('rejects empty signature', function () {
        $payload = '{"event":"test"}';
        $secret = 'secret';

        expect(WebhookSignatureValidator::valid($payload, '', $secret))->toBeFalse();
    });

    it('rejects empty secret', function () {
        $payload = '{"event":"test"}';
        $signature = 'some_signature';

        expect(WebhookSignatureValidator::valid($payload, $signature, ''))->toBeFalse();
    });

    it('sign() produces consistent output', function () {
        $payload = '{"test":true}';
        $secret = 'consistent_secret';

        $sig1 = WebhookSignatureValidator::sign($payload, $secret);
        $sig2 = WebhookSignatureValidator::sign($payload, $secret);

        expect($sig1)->toBe($sig2);
        expect(strlen($sig1))->toBe(64); // SHA256 hex output
    });

    it('sign() differs for different payloads', function () {
        $secret = 'secret';

        $sig1 = WebhookSignatureValidator::sign('{"a":1}', $secret);
        $sig2 = WebhookSignatureValidator::sign('{"b":2}', $secret);

        expect($sig1)->not->toBe($sig2);
    });

    it('validates from X-ZB-Signature header', function () {
        $payload = '{"event":"test"}';
        $secret = 'header_secret';
        $signature = WebhookSignatureValidator::sign($payload, $secret);

        $headers = [
            'X-ZB-Signature' => $signature,
        ];

        expect(WebhookSignatureValidator::validateFromHeaders($payload, $headers, $secret))->toBeTrue();
    });

    it('validates from X-Hub-Signature-256 header (Meta format)', function () {
        $payload = '{"event":"test"}';
        $secret = 'meta_secret';
        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        $headers = [
            'X-Hub-Signature-256' => $expected,
        ];

        expect(WebhookSignatureValidator::validateFromHeaders($payload, $headers, $secret))->toBeTrue();
    });

    it('rejects when no valid header present', function () {
        $payload = '{"event":"test"}';
        $headers = [];

        expect(WebhookSignatureValidator::validateFromHeaders($payload, $headers, 'secret'))->toBeFalse();
    });

    it('rejects tampered payload with valid signature', function () {
        $payload = '{"event":"purchase","value":99.99}';
        $secret = 'tamper_secret';
        $signature = WebhookSignatureValidator::sign($payload, $secret);

        $tampered = '{"event":"purchase","value":0.01}';

        expect(WebhookSignatureValidator::valid($tampered, $signature, $secret))->toBeFalse();
    });
});

// ── EventTransformer Tests ───────────────────────────────────────────

describe('EventTransformer', function () {
    it('maps GA4 event names to Meta equivalents', function () {
        expect(EventTransformer::ga4ToMetaEventName('view_item'))->toBe('ViewContent');
        expect(EventTransformer::ga4ToMetaEventName('add_to_cart'))->toBe('AddToCart');
        expect(EventTransformer::ga4ToMetaEventName('begin_checkout'))->toBe('InitiateCheckout');
        expect(EventTransformer::ga4ToMetaEventName('add_payment_info'))->toBe('AddPaymentInfo');
        expect(EventTransformer::ga4ToMetaEventName('purchase'))->toBe('Purchase');
        expect(EventTransformer::ga4ToMetaEventName('add_to_wishlist'))->toBe('AddToWishlist');
    });

    it('returns null for events without Meta equivalent', function () {
        expect(EventTransformer::ga4ToMetaEventName('remove_from_cart'))->toBeNull();
        expect(EventTransformer::ga4ToMetaEventName('view_cart'))->toBeNull();
        expect(EventTransformer::ga4ToMetaEventName('refund'))->toBeNull();
        expect(EventTransformer::ga4ToMetaEventName('custom_event'))->toBeNull();
    });

    it('checks Meta equivalent existence', function () {
        expect(EventTransformer::hasMetaEquivalent('purchase'))->toBeTrue();
        expect(EventTransformer::hasMetaEquivalent('remove_from_cart'))->toBeFalse();
    });

    it('converts GA4 items to Meta contents format', function () {
        $items = [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'item_category' => 'Gadgets', 'price' => 49.99, 'quantity' => 2],
            ['item_id' => 'SKU-002', 'item_name' => 'Gadget', 'item_category' => 'Gadgets', 'price' => 29.99, 'quantity' => 1],
        ];

        $result = EventTransformer::ga4ItemsToMetaContents($items);

        expect($result['content_ids'])->toBe(['SKU-001', 'SKU-002']);
        expect($result['contents'])->toHaveCount(2);
        expect($result['num_items'])->toBe(3);
        expect($result['contents'][0]['id'])->toBe('SKU-001');
        expect($result['contents'][0]['quantity'])->toBe(2);
        expect($result['contents'][0]['item_price'])->toBe(49.99);
    });

    it('converts GA4 event data to Meta params', function () {
        $data = [
            'value' => 99.99,
            'currency' => 'EUR',
            'items' => [
                ['item_id' => 'SKU-001', 'price' => 49.99, 'quantity' => 2, 'item_name' => 'Widget'],
            ],
        ];

        $params = EventTransformer::ga4ToMetaParams('purchase', $data);

        expect($params['value'])->toBe(99.99);
        expect($params['currency'])->toBe('EUR');
        expect($params['contents'])->toHaveCount(1);
        expect($params['content_ids'])->toBe(['SKU-001']);
        expect($params['num_items'])->toBe(2);
    });

    it('filters null values from Meta params', function () {
        $params = EventTransformer::ga4ToMetaParams('view_item', ['value' => null]);

        expect($params)->not->toHaveKey('value');
    });

    it('transforms events for Meta provider', function () {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'value' => 49.99,
                'currency' => 'USD',
                'items' => [
                    ['item_id' => 'SKU-001', 'price' => 49.99, 'quantity' => 1],
                ],
            ],
            clientId: 'client-123',
        );

        $transformed = EventTransformer::transformForProvider($event, 'meta');

        expect($transformed->name)->toBe('Purchase');
        expect($transformed->clientId)->toBe('client-123');
        expect($transformed->params)->toHaveKey('contents');
    });

    it('passes through events without Meta equivalent', function () {
        $event = new AnalyticsEvent(
            name: 'custom_event',
            params: ['key' => 'value'],
        );

        $transformed = EventTransformer::transformForProvider($event, 'meta');

        expect($transformed->name)->toBe('custom_event');
        expect($transformed->params)->toBe(['key' => 'value']);
    });

    it('passes through events unchanged for GA4 provider', function () {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99],
        );

        $transformed = EventTransformer::transformForProvider($event, 'ga4');

        expect($transformed->name)->toBe('purchase');
        expect($transformed->params)->toBe(['value' => 99.99]);
    });

    it('maps SaaS events to PostHog names', function () {
        $map = EventTransformer::saasToPosthogEventMap();

        expect($map['sign_up'])->toBe('$signup');
        expect($map['login'])->toBe('$identify');
        expect($map['logout'])->toBe('logout');
    });

    it('transforms SaaS events for PostHog', function () {
        $event = new AnalyticsEvent(
            name: 'sign_up',
            params: ['method' => 'github'],
            clientId: 'anon-123',
        );

        $transformed = EventTransformer::transformForProvider($event, 'posthog');

        expect($transformed->name)->toBe('$signup');
        expect($transformed->clientId)->toBe('anon-123');
    });

    it('handles empty items array for Meta conversion', function () {
        $result = EventTransformer::ga4ItemsToMetaContents([]);

        expect($result['content_ids'])->toBeEmpty();
        expect($result['contents'])->toBeEmpty();
        expect($result['num_items'])->toBe(0);
    });

    it('handles items with missing fields gracefully', function () {
        $items = [
            ['item_id' => 'SKU-001'], // missing other fields
        ];

        $result = EventTransformer::ga4ItemsToMetaContents($items);

        expect($result['contents'][0]['id'])->toBe('SKU-001');
        expect($result['contents'][0]['quantity'])->toBe(1);
        expect($result['contents'][0]['item_price'])->toBe(0.0);
        expect($result['contents'][0]['name'])->toBe('');
    });

    it('includes content_name and content_type in Meta params', function () {
        $data = [
            'value' => 15.0,
            'currency' => 'USD',
            'content_name' => 'Widget Pro',
            'content_type' => 'product',
        ];

        $params = EventTransformer::ga4ToMetaParams('view_item', $data);

        expect($params['content_name'])->toBe('Widget Pro');
        expect($params['content_type'])->toBe('product');
    });
});
