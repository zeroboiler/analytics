<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ZeroBoiler\Analytics\Middleware\FirstTouchUTMMiddleware;

describe('FirstTouchUTMMiddleware', function (): void {
    beforeEach(function (): void {
        $this->middleware = new FirstTouchUTMMiddleware;
    });

    describe('cookie name resolution', function (): void {
        it('uses default cookie name when no config is set', function (): void {
            $request = Request::create('/test');
            $next = fn (Request $req): Response => new Response('ok');

            $response = $this->middleware->handle($request, $next);

            // Cookie should be queued (no UTM = no cookie set, just pass through)
            expect($response->getStatusCode())->toBe(200);
        });
    });

    describe('first visit with UTM parameters', function (): void {
        it('captures UTM params and stores as first touch', function (): void {
            $request = Request::create('/landing?utm_source=google&utm_medium=cpc&utm_campaign=summer_sale');
            $next = fn (Request $req): Response => new Response('ok');

            $response = $this->middleware->handle($request, $next);

            // First-touch data should be on request attributes
            $firstTouch = $request->attributes->get('_zb_first_touch');
            expect($firstTouch)->toBeArray();
            expect($firstTouch['is_new'])->toBeTrue();
            expect($firstTouch['data']['utm_source'])->toBe('google');
            expect($firstTouch['data']['utm_medium'])->toBe('cpc');
            expect($firstTouch['data']['utm_campaign'])->toBe('summer_sale');
            expect($firstTouch['data'])->toHaveKey('_landing_page');
            expect($firstTouch['data'])->toHaveKey('_first_seen_at');
        });

        it('captures partial UTM params (only source)', function (): void {
            $request = Request::create('/?utm_source=twitter');
            $next = fn (Request $req): Response => new Response('ok');

            $this->middleware->handle($request, $next);

            $firstTouch = $request->attributes->get('_zb_first_touch');
            expect($firstTouch['is_new'])->toBeTrue();
            expect($firstTouch['data']['utm_source'])->toBe('twitter');
            expect($firstTouch['data'])->not->toHaveKey('utm_medium');
        });
    });

    describe('subsequent visit without UTM', function (): void {
        it('preserves original first-touch from cookie', function (): void {
            $originalData = json_encode([
                'utm_source' => 'facebook',
                'utm_medium' => 'social',
                'utm_campaign' => 'q1_launch',
                '_landing_page' => '/welcome',
                '_first_seen_at' => '2025-01-15T10:30:00Z',
            ], JSON_THROW_ON_ERROR);

            $request = Request::create('/dashboard');
            $request->cookies->set('zb_first_touch', $originalData);

            $next = fn (Request $req): Response => new Response('ok');

            $response = $this->middleware->handle($request, $next);

            $firstTouch = $request->attributes->get('_zb_first_touch');
            expect($firstTouch['is_new'])->toBeFalse();
            expect($firstTouch['data']['utm_source'])->toBe('facebook');
            expect($firstTouch['data']['utm_medium'])->toBe('social');
            expect($firstTouch['data']['utm_campaign'])->toBe('q1_launch');
        });

        it('does not overwrite first touch when new UTM arrives', function (): void {
            $originalData = json_encode([
                'utm_source' => 'google',
                'utm_medium' => 'organic',
                '_landing_page' => '/',
                '_first_seen_at' => '2025-01-01T00:00:00Z',
            ], JSON_THROW_ON_ERROR);

            // User comes back with different UTM — original should be preserved
            $request = Request::create('/?utm_source=newsletter&utm_medium=email');
            $request->cookies->set('zb_first_touch', $originalData);

            $next = fn (Request $req): Response => new Response('ok');

            $this->middleware->handle($request, $next);

            $firstTouch = $request->attributes->get('_zb_first_touch');
            expect($firstTouch['is_new'])->toBeFalse();
            expect($firstTouch['data']['utm_source'])->toBe('google'); // Still original
            expect($firstTouch['data']['utm_medium'])->toBe('organic');
        });
    });

    describe('visit without any UTM and no cookie', function (): void {
        it('sets empty first-touch data and is_new is false', function (): void {
            $request = Request::create('/about');
            $next = fn (Request $req): Response => new Response('ok');

            $this->middleware->handle($request, $next);

            $firstTouch = $request->attributes->get('_zb_first_touch');
            expect($firstTouch)->toBeArray();
            expect($firstTouch['is_new'])->toBeFalse();
            expect($firstTouch['data'])->toBeArray();
            expect($firstTouch['data'])->toBeEmpty();
        });
    });

    describe('invalid cookie data', function (): void {
        it('ignores malformed cookie and treats as no cookie', function (): void {
            $request = Request::create('/');
            $request->cookies->set('zb_first_touch', 'not-valid-json!!!');

            $next = fn (Request $req): Response => new Response('ok');

            // Should not throw, just fall back to no cookie behavior
            $this->middleware->handle($request, $next);

            $firstTouch = $request->attributes->get('_zb_first_touch');
            expect($firstTouch)->toBeArray();
        });
    });

    describe('standard UTM params captured', function (): void {
        it('captures all five standard UTM parameters', function (): void {
            $request = Request::create(
                '/?utm_source=google&utm_medium=cpc&utm_campaign=brand&utm_term=analytics&utm_content=sidebar_banner',
            );
            $next = fn (Request $req): Response => new Response('ok');

            $this->middleware->handle($request, $next);

            $firstTouch = $request->attributes->get('_zb_first_touch');
            expect($firstTouch['data']['utm_source'])->toBe('google');
            expect($firstTouch['data']['utm_medium'])->toBe('cpc');
            expect($firstTouch['data']['utm_campaign'])->toBe('brand');
            expect($firstTouch['data']['utm_term'])->toBe('analytics');
            expect($firstTouch['data']['utm_content'])->toBe('sidebar_banner');
        });

        it('ignores empty UTM parameter values', function (): void {
            $request = Request::create('/?utm_source=&utm_medium=cpc');
            $next = fn (Request $req): Response => new Response('ok');

            $this->middleware->handle($request, $next);

            $firstTouch = $request->attributes->get('_zb_first_touch');
            // Empty utm_source should not be captured, but utm_medium should
            expect($firstTouch['data'])->not->toHaveKey('utm_source');
            expect($firstTouch['data']['utm_medium'])->toBe('cpc');
        });
    });

    describe('landing page metadata', function (): void {
        it('stores landing page path and full URL', function (): void {
            $request = Request::create(
                'https://example.com/pricing?utm_source=google',
                'GET',
                [],
                [],
                [],
                ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on'],
            );
            $next = fn (Request $req): Response => new Response('ok');

            $this->middleware->handle($request, $next);

            $firstTouch = $request->attributes->get('_zb_first_touch');
            expect($firstTouch['data']['_landing_page'])->toBe('pricing');
            expect($firstTouch['data']['_landing_url'])->toBeString();
            expect($firstTouch['data']['_first_seen_at'])->toBeString();
        });
    });
});

describe('TypeScript Definitions Existence', function (): void {
    it('analytics.d.ts file exists with proper structure', function (): void {
        $path = __DIR__ . '/../resources/js/analytics.d.ts';
        expect(file_exists($path))->toBeTrue();

        $content = file_get_contents($path);
        expect(str_contains($content, 'export function init'))->toBeTrue();
        expect(str_contains($content, 'export function trackEvent'))->toBeTrue();
        expect(str_contains($content, 'export function trackPageView'))->toBeTrue();
        expect(str_contains($content, 'export function identify'))->toBeTrue();
        expect(str_contains($content, 'ZbAnalyticsProps'))->toBeTrue();
        expect(str_contains($content, 'ConsentState'))->toBeTrue();
        expect(str_contains($content, 'EcommerceItem'))->toBeTrue();
        expect(str_contains($content, 'connectSSE'))->toBeTrue();
        expect(str_contains($content, 'trackCheckoutStep'))->toBeTrue();
        expect(str_contains($content, 'trackSubscriptionEvent'))->toBeTrue();
        expect(str_contains($content, 'trackRevenueEvent'))->toBeTrue();
        expect(str_contains($content, 'trackPlanChange'))->toBeTrue();
        expect(str_contains($content, 'scoreChurnRisk'))->toBeTrue();
        expect(str_contains($content, 'fetchBenchmarks'))->toBeTrue();
        expect(str_contains($content, 'fetchDashboardOverview'))->toBeTrue();
        expect(str_contains($content, '@version 6.3.0'))->toBeTrue();
    });

    it('d.ts covers all exported functions from analytics.js', function (): void {
        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        $dtsPath = __DIR__ . '/../resources/js/analytics.d.ts';

        $jsContent = file_get_contents($jsPath);
        $dtsContent = file_get_contents($dtsPath);

        // Extract all exported function names from JS
        preg_match_all('/^export (?:async )?function (\w+)/m', $jsContent, $jsExports);
        $jsFunctions = array_unique($jsExports[1]);

        // Check that core functions are defined in .d.ts
        $coreFunctions = [
            'init', 'destroy', 'isInitialized', 'getVersion', 'getTrackingId',
            'getApiBaseUrl', 'trackEvent', 'trackEventWithPriority', 'flushQueue',
            'trackPageView', 'trackScreenView', 'trackAbTestExposure',
            'trackEcommerce', 'identify', 'updateConsent', 'trackTrialConversion',
            'initScrollDepth', 'initInertiaPageViewTracker', 'initFormTracking',
            'initErrorTracking', 'initWebVitals', 'initSessionTracking',
            'initAll', 'destroyAll', 'pushToDataLayer', 'captureUTM',
            'getUTMParams', 'getFirstTouchUTM', 'getAttributionContext',
            'optOutTracking', 'optInTracking', 'setUserProperties',
            'trackSaaSEvent', 'trackSearch', 'trackShare', 'trackFileDownload',
            'trackOutboundClick', 'initLinkTracking', 'trackTiming',
            'getConsentState', 'consentGranted', 'consentDenied',
            'connectSSE', 'fetchBenchmarks', 'fetchDashboardOverview',
            'trackCheckoutStep', 'trackSubscriptionEvent', 'trackTrialEvent',
            'trackRevenueEvent', 'trackPlanChange', 'trackAdClick',
            'trackContentEngagement', 'trackOnboardingStep', 'trackFeatureImpression',
        ];

        $missing = [];
        foreach ($coreFunctions as $func) {
            if (! str_contains($dtsContent, "export function {$func}")) {
                $missing[] = $func;
            }
        }

        expect($missing)->toBeEmpty(
            sprintf('Missing type definitions for: %s', implode(', ', $missing)),
        );
    });
});
