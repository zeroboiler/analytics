<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Support\SaaSFormatConverter;
use ZeroBoiler\Analytics\Support\EngagementFormatConverter;

beforeEach(function (): void {
    //
});

describe('V1650 — 8-Provider Format Converter Parity', function (): void {

    // ── SaaSFormatConverter ────────────────────────────────────────

    describe('SaaSFormatConverter supportedProviders', function (): void {
        test('returns all 8 providers', function (): void {
            $providers = SaaSFormatConverter::supportedProviders();
            expect($providers)->toBe([
                'ga4', 'meta', 'posthog', 'mixpanel', 'amplitude',
                'plausible', 'tiktok', 'linkedin',
            ]);
            expect(count($providers))->toBe(8);
        });

        test('supports() returns true for all 7 SaaS events', function (): void {
            $events = ['sign_up', 'login', 'start_trial', 'subscribe', 'subscription', 'plan_upgrade', 'cancellation'];
            foreach ($events as $event) {
                expect(SaaSFormatConverter::supports($event))->toBeTrue();
            }
        });

        test('supports() returns false for unknown events', function (): void {
            expect(SaaSFormatConverter::supports('unknown_event'))->toBeFalse();
            expect(SaaSFormatConverter::supports('page_view'))->toBeFalse();
        });
    });

    describe('SaaSFormatConverter sign_up → 8 providers', function (): void {
        $params = ['method' => 'email', 'plan' => 'pro', 'value' => 49.99, 'currency' => 'USD', 'is_paid' => true, 'predicted_ltv' => 599.88, 'referral_code' => 'FRIEND10'];

        test('signUpToMixpanel converts correctly', function () use ($params): void {
            $result = SaaSFormatConverter::signUpToMixpanel($params);
            expect($result['signup_method'])->toBe('email');
            expect($result['plan'])->toBe('pro');
            expect($result['is_paid'])->toBeTrue();
            expect($result['predicted_ltv'])->toBe(599.88);
            expect($result['referral_code'])->toBe('FRIEND10');
        });

        test('signUpToAmplitude converts correctly', function () use ($params): void {
            $result = SaaSFormatConverter::signUpToAmplitude($params);
            expect($result['signup_method'])->toBe('email');
            expect($result['plan'])->toBe('pro');
            expect($result['user_properties']['predicted_ltv'])->toBe(599.88);
        });

        test('signUpToPlausible converts correctly', function () use ($params): void {
            $result = SaaSFormatConverter::signUpToPlausible($params);
            expect($result['signup_method'])->toBe('email');
            expect($result['plan'])->toBe('pro');
            expect($result['is_paid'])->toBe('true');
        });

        test('signUpToTiktok converts correctly', function () use ($params): void {
            $result = SaaSFormatConverter::signUpToTiktok($params);
            expect($result['content_name'])->toBe('sign_up');
            expect($result['value'])->toBe(49.99);
            expect($result['currency'])->toBe('USD');
            expect($result['method'])->toBe('email');
        });

        test('signUpToLinkedin converts correctly', function () use ($params): void {
            $result = SaaSFormatConverter::signUpToLinkedin($params);
            expect($result['value'])->toBe(49.99);
            expect($result['currency'])->toBe('USD');
            expect($result['method'])->toBe('email');
            expect($result['plan'])->toBe('pro');
        });
    });

    describe('SaaSFormatConverter convertForProvider dispatch', function (): void {
        $params = ['method' => 'email', 'plan' => 'pro', 'value' => 29.99];

        test('dispatches sign_up to all 8 providers', function () use ($params): void {
            $providers = SaaSFormatConverter::supportedProviders();
            foreach ($providers as $provider) {
                $result = SaaSFormatConverter::convertForProvider('sign_up', $params, $provider);
                expect($result)->toBeArray();
                expect(count($result))->toBeGreaterThan(0);
            }
        });

        test('dispatches login to all 8 providers', function () use ($params): void {
            $providers = SaaSFormatConverter::supportedProviders();
            foreach ($providers as $provider) {
                $result = SaaSFormatConverter::convertForProvider('login', $params, $provider);
                expect($result)->toBeArray();
            }
        });

        test('dispatches start_trial to all 8 providers', function () use ($params): void {
            $providers = SaaSFormatConverter::supportedProviders();
            foreach ($providers as $provider) {
                $result = SaaSFormatConverter::convertForProvider('start_trial', $params, $provider);
                expect($result)->toBeArray();
            }
        });

        test('dispatches subscription to all 8 providers', function () use ($params): void {
            $providers = SaaSFormatConverter::supportedProviders();
            foreach ($providers as $provider) {
                $result = SaaSFormatConverter::convertForProvider('subscription', $params, $provider);
                expect($result)->toBeArray();
            }
        });

        test('dispatches plan_upgrade to all 8 providers', function () use ($params): void {
            $upgradeParams = ['from_plan' => 'free', 'to_plan' => 'pro', 'value' => 20.0];
            $providers = SaaSFormatConverter::supportedProviders();
            foreach ($providers as $provider) {
                $result = SaaSFormatConverter::convertForProvider('plan_upgrade', $upgradeParams, $provider);
                expect($result)->toBeArray();
            }
        });

        test('dispatches cancellation to all 8 providers', function () use ($params): void {
            $cancelParams = ['plan' => 'pro', 'reason' => 'too_expensive', 'lost_revenue' => 29.99];
            $providers = SaaSFormatConverter::supportedProviders();
            foreach ($providers as $provider) {
                $result = SaaSFormatConverter::convertForProvider('cancellation', $cancelParams, $provider);
                expect($result)->toBeArray();
            }
        });

        test('unknown provider returns params unchanged', function () use ($params): void {
            $result = SaaSFormatConverter::convertForProvider('sign_up', $params, 'unknown_provider');
            expect($result)->toBe($params);
        });
    });

    describe('SaaSFormatConverter buildRevenueParams', function (): void {
        test('builds revenue params for all 8 providers', function (): void {
            $providers = SaaSFormatConverter::supportedProviders();
            foreach ($providers as $provider) {
                $result = SaaSFormatConverter::buildRevenueParams($provider, 99.99, 'USD', 'enterprise', 'annual', 'sub_123');
                expect($result)->toBeArray();
                expect($result)->not->toBeEmpty();
            }
        });

        test('mixpanel includes subscription_id', function (): void {
            $result = SaaSFormatConverter::buildRevenueParams('mixpanel', 49.99, 'EUR', 'pro', 'monthly', 'sub_456');
            expect($result['subscription_id'])->toBe('sub_456');
            expect($result['revenue'])->toBe(49.99);
        });

        test('amplitude includes user_properties with mrr', function (): void {
            $result = SaaSFormatConverter::buildRevenueParams('amplitude', 29.99, 'USD', 'starter', 'monthly', 'sub_789');
            expect($result['user_properties']['plan'])->toBe('starter');
            expect($result['user_properties']['mrr'])->toBe(29.99);
        });

        test('plausible revenue is string', function (): void {
            $result = SaaSFormatConverter::buildRevenueParams('plausible', 19.99, 'GBP', 'basic');
            expect($result['revenue'])->toBe('19.99');
        });

        test('tiktok includes content_name', function (): void {
            $result = SaaSFormatConverter::buildRevenueParams('tiktok', 79.99, 'USD', 'business');
            expect($result['content_name'])->toBe('revenue');
        });

        test('linkedin includes value and currency', function (): void {
            $result = SaaSFormatConverter::buildRevenueParams('linkedin', 49.99, 'USD', 'pro');
            expect($result['value'])->toBe(49.99);
            expect($result['currency'])->toBe('USD');
        });
    });

    describe('SaaSFormatConverter cancellation → Mixpanel', function (): void {
        test('includes tenure_days and nps_before', function (): void {
            $params = ['plan' => 'pro', 'reason' => 'missing_features', 'lost_revenue' => 99.99, 'tenure_days' => 180, 'nps_before' => 7];
            $result = SaaSFormatConverter::cancellationToMixpanel($params);
            expect($result['tenure_days'])->toBe(180);
            expect($result['nps_before'])->toBe(7);
            expect($result['lost_mrr'])->toBe(99.99);
        });
    });

    describe('SaaSFormatConverter cancellation → Amplitude', function (): void {
        test('includes user_properties with subscription_status', function (): void {
            $params = ['plan' => 'enterprise', 'reason' => 'budget', 'lost_revenue' => 299.99];
            $result = SaaSFormatConverter::cancellationToAmplitude($params);
            expect($result['user_properties']['subscription_status'])->toBe('cancelled');
            expect($result['revenue_lost'])->toBe(299.99);
        });
    });

    describe('SaaSFormatConverter buildProviderEvent', function (): void {
        test('builds event with saas category for all 8 providers', function (): void {
            $providers = SaaSFormatConverter::supportedProviders();
            foreach ($providers as $provider) {
                $event = SaaSFormatConverter::buildProviderEvent('sign_up', ['method' => 'github'], $provider, 'cid_1', 'user_1');
                expect($event->name)->toBe('sign_up');
                expect($event->category)->toBe('saas');
                expect($event->clientId)->toBe('cid_1');
                expect($event->userId)->toBe('user_1');
                expect($event->params)->toBeArray();
            }
        });
    });

    // ── EngagementFormatConverter ───────────────────────────────────

    describe('EngagementFormatConverter supportedProviders', function (): void {
        test('returns all 8 providers', function (): void {
            $providers = EngagementFormatConverter::supportedProviders();
            expect($providers)->toBe([
                'ga4', 'meta', 'posthog', 'mixpanel', 'amplitude',
                'plausible', 'tiktok', 'linkedin',
            ]);
            expect(count($providers))->toBe(8);
        });

        test('supportedEvents returns 12 events', function (): void {
            $events = EngagementFormatConverter::supportedEvents();
            expect(count($events))->toBe(12);
        });
    });

    describe('EngagementFormatConverter page_view → 8 providers', function (): void {
        $params = ['url' => 'https://example.com/page', 'title' => 'Test Page', 'referrer' => 'https://google.com', 'engagement_time_msec' => 5000];

        test('pageViewToMixpanel converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::pageViewToMixpanel($params);
            expect($result['page'])->toBe('https://example.com/page');
            expect($result['title'])->toBe('Test Page');
            expect($result['referrer'])->toBe('https://google.com');
            expect($result['engagement_time'])->toBe(5000);
        });

        test('pageViewToAmplitude converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::pageViewToAmplitude($params);
            expect($result['page_location'])->toBe('https://example.com/page');
            expect($result['page_title'])->toBe('Test Page');
        });

        test('pageViewToPlausible converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::pageViewToPlausible($params);
            expect($result['path'])->toBe('https://example.com/page');
            expect($result['title'])->toBe('Test Page');
        });

        test('pageViewToTiktok converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::pageViewToTiktok($params);
            expect($result['content_name'])->toBe('Test Page');
            expect($result['content_type'])->toBe('product');
            expect($result['page_url'])->toBe('https://example.com/page');
        });

        test('pageViewToLinkedin converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::pageViewToLinkedin($params);
            expect($result['value'])->toBe(0.0);
            expect($result['page_url'])->toBe('https://example.com/page');
            expect($result['page_title'])->toBe('Test Page');
        });
    });

    describe('EngagementFormatConverter click → 5 new providers', function (): void {
        $params = ['url' => 'https://example.com/checkout', 'text' => 'Buy Now', 'outbound' => false, 'element_id' => 'cta-buy'];

        test('clickToMixpanel converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::clickToMixpanel($params);
            expect($result['link_url'])->toBe('https://example.com/checkout');
            expect($result['link_text'])->toBe('Buy Now');
            expect($result['outbound'])->toBeFalse();
            expect($result['element_id'])->toBe('cta-buy');
        });

        test('clickToAmplitude converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::clickToAmplitude($params);
            expect($result['link_url'])->toBe('https://example.com/checkout');
            expect($result['link_domain'])->toBe('example.com');
            expect($result['outbound'])->toBeFalse();
        });

        test('clickToPlausible converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::clickToPlausible($params);
            expect($result['link_url'])->toBe('https://example.com/checkout');
            expect($result['outbound'])->toBe('false');
        });

        test('clickToTiktok converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::clickToTiktok($params);
            expect($result['content_name'])->toBe('Buy Now');
            expect($result['content_type'])->toBe('click');
        });

        test('clickToLinkedin converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::clickToLinkedin($params);
            expect($result['link_url'])->toBe('https://example.com/checkout');
            expect($result['element_id'])->toBe('cta-buy');
        });
    });

    describe('EngagementFormatConverter error → 5 new providers', function (): void {
        $params = ['message' => 'TypeError: undefined variable', 'code' => 500, 'fatal' => true, 'type' => 'TypeError', 'stack' => 'at line 42'];

        test('errorToMixpanel converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::errorToMixpanel($params);
            expect($result['error_message'])->toBe('TypeError: undefined variable');
            expect($result['error_code'])->toBe(500);
            expect($result['fatal'])->toBeTrue();
            expect($result['error_type'])->toBe('TypeError');
        });

        test('errorToAmplitude converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::errorToAmplitude($params);
            expect($result['error_message'])->toBe('TypeError: undefined variable');
            expect($result['error_type'])->toBe('TypeError');
            expect($result['fatal'])->toBeTrue();
            expect($result['stack_trace'])->toBe('at line 42');
        });

        test('errorToPlausible converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::errorToPlausible($params);
            expect($result['error_message'])->toBe('TypeError: undefined variable');
            expect($result['error_code'])->toBe('500');
            expect($result['fatal'])->toBe('true');
        });

        test('errorToTiktok converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::errorToTiktok($params);
            expect($result['content_name'])->toBe('error');
            expect($result['error_message'])->toBe('TypeError: undefined variable');
            expect($result['fatal'])->toBeTrue();
        });

        test('errorToLinkedin converts correctly', function () use ($params): void {
            $result = EngagementFormatConverter::errorToLinkedin($params);
            expect($result['error_message'])->toBe('TypeError: undefined variable');
            expect($result['error_code'])->toBe(500);
        });
    });

    describe('EngagementFormatConverter convertForProvider dispatch', function (): void {
        $pageViewParams = ['url' => '/test', 'title' => 'Test'];

        test('dispatches page_view to all 8 providers', function () use ($pageViewParams): void {
            $providers = EngagementFormatConverter::supportedProviders();
            foreach ($providers as $provider) {
                $result = EngagementFormatConverter::convertForProvider('page_view', $pageViewParams, $provider);
                expect($result)->toBeArray();
                expect(count($result))->toBeGreaterThan(0);
            }
        });

        test('dispatches click to all 8 providers', function (): void {
            $params = ['url' => 'https://x.com', 'text' => 'Link'];
            $providers = EngagementFormatConverter::supportedProviders();
            foreach ($providers as $provider) {
                $result = EngagementFormatConverter::convertForProvider('click', $params, $provider);
                expect($result)->toBeArray();
            }
        });

        test('dispatches error to all 8 providers', function (): void {
            $params = ['message' => 'test error'];
            $providers = EngagementFormatConverter::supportedProviders();
            foreach ($providers as $provider) {
                $result = EngagementFormatConverter::convertForProvider('error', $params, $provider);
                expect($result)->toBeArray();
            }
        });

        test('dispatches alias js_error to all 8 providers', function (): void {
            $params = ['message' => 'JS Error', 'type' => 'ReferenceError'];
            $providers = EngagementFormatConverter::supportedProviders();
            foreach ($providers as $provider) {
                $result = EngagementFormatConverter::convertForProvider('js_error', $params, $provider);
                expect($result)->toBeArray();
            }
        });

        test('dispatches all 8 engagement events for each provider', function (): void {
            $events = EngagementFormatConverter::supportedEvents();
            $providers = EngagementFormatConverter::supportedProviders();
            $tested = 0;
            foreach ($events as $event) {
                foreach ($providers as $provider) {
                    $result = EngagementFormatConverter::convertForProvider($event, ['url' => '/'], $provider);
                    expect($result)->toBeArray();
                    $tested++;
                }
            }
            // 12 events × 8 providers = 96 combinations
            expect($tested)->toBe(96);
        });
    });

    describe('EngagementFormatConverter buildProviderEvent', function (): void {
        test('builds event with engagement category for all 8 providers', function (): void {
            $providers = EngagementFormatConverter::supportedProviders();
            foreach ($providers as $provider) {
                $event = EngagementFormatConverter::buildProviderEvent('page_view', ['url' => '/'], $provider, 'cid_1', 'user_1');
                expect($event->name)->toBe('page_view');
                expect($event->category)->toBe('engagement');
                expect($event->clientId)->toBe('cid_1');
            }
        });
    });

    // ── Cross-converter parity checks ────────────────────────────────

    describe('Format converter parity', function (): void {
        test('SaaS and Engagement converters have same supportedProviders', function (): void {
            expect(SaaSFormatConverter::supportedProviders())->toBe(
                EngagementFormatConverter::supportedProviders()
            );
        });

        test('all 8 providers are covered in convertForProvider', function (): void {
            $providers = ['ga4', 'meta', 'posthog', 'mixpanel', 'amplitude', 'plausible', 'tiktok', 'linkedin'];

            // SaaS events
            $saasEvents = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'];
            foreach ($saasEvents as $event) {
                foreach ($providers as $provider) {
                    $result = SaaSFormatConverter::convertForProvider($event, ['value' => 10], $provider);
                    expect($result)->toBeArray();
                }
            }

            // Engagement events
            $engEvents = ['page_view', 'click', 'search', 'error', 'form_start', 'form_submit', 'share', 'scroll_depth'];
            foreach ($engEvents as $event) {
                foreach ($providers as $provider) {
                    $result = EngagementFormatConverter::convertForProvider($event, ['url' => '/'], $provider);
                    expect($result)->toBeArray();
                }
            }
        });
    });
});
