<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

// ── Version Consistency (v146.0.0) ────────────────────────────────────

describe('V144 Version Consistency', function () {
    it('has VERSION 146.0.0 in AnalyticsEvent', function () {
        expect(AnalyticsEvent::VERSION)->toBe('146.0.0');
    });

    it('has consistent version across composer.json equivalent', function () {
        expect(AnalyticsEvent::VERSION)->toBe('146.0.0');
    });

    it('has strict_types declaration in all checked files', function () {
        $files = [
            __DIR__ . '/../../src/DTO/AnalyticsEvent.php',
            __DIR__ . '/../../src/AnalyticsManager.php',
            __DIR__ . '/../../src/Facades/Analytics.php',
            __DIR__ . '/../../src/Events/EventCatalog.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        }
    });

    it('has MIT license headers in all checked files', function () {
        $files = [
            __DIR__ . '/../../src/DTO/AnalyticsEvent.php',
            __DIR__ . '/../../src/AnalyticsManager.php',
            __DIR__ . '/../../src/Facades/Analytics.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('MIT license');
        }
    });
});

// ── identifyAndTrack Method (v146.0.0) ───────────────────────────────

describe('V144 identifyAndTrack', function () {
    it('exists on AnalyticsManager', function () {
        $manager = new AnalyticsManager;

        expect(method_exists($manager, 'identifyAndTrack'))->toBeTrue();
    });

    it('fires identify then track with user_id and client_id', function () {
        $manager = new AnalyticsManager;
        $events = [];

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$events): void {
            $events[] = $event->name;
        });

        $manager->identifyAndTrack('user-123', 'sign_up', 'client-abc', ['method' => 'email']);

        // Should have fired 2 events: identify + sign_up
        expect($events)->toHaveCount(2);
        expect($events[0])->toBe('identify');
        expect($events[1])->toBe('sign_up');
    });

    it('passes traits to identify call', function () {
        $manager = new AnalyticsManager;
        $identifyParams = null;

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$identifyParams): void {
            if ($event->name === 'identify') {
                $identifyParams = $event->params;
            }
        });

        $manager->identifyAndTrack('user-456', 'trial_start', null, [], ['plan' => 'Pro', 'email_hash' => 'abc123']);

        expect($identifyParams)->not->toBeNull();
        expect($identifyParams['user_id'])->toBe('user-456');
        expect($identifyParams['plan'])->toBe('Pro');
        expect($identifyParams['email_hash'])->toBe('abc123');
    });

    it('merges user_id and client_id into event params', function () {
        $manager = new AnalyticsManager;
        $trackParams = null;

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$trackParams): void {
            if ($event->name === 'purchase') {
                $trackParams = $event->params;
            }
        });

        $manager->identifyAndTrack('user-789', 'purchase', 'client-xyz', ['value' => 99.99]);

        expect($trackParams)->not->toBeNull();
        expect($trackParams['user_id'])->toBe('user-789');
        expect($trackParams['client_id'])->toBe('client-xyz');
        expect($trackParams['value'])->toBe(99.99);
    });

    it('works without clientId', function () {
        $manager = new AnalyticsManager;
        $events = [];

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$events): void {
            $events[] = $event->name;
        });

        $manager->identifyAndTrack('user-001', 'login');

        expect($events)->toHaveCount(2);
        expect($events[0])->toBe('identify');
        expect($events[1])->toBe('login');
    });
});

// ── Web Vitals Convenience Method (v146.0.0) ─────────────────────────

describe('V144 Web Vitals Convenience', function () {
    it('tracks web_vitals event with correct params', function () {
        $manager = new AnalyticsManager;
        $captured = null;

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$captured): void {
            if ($event->name === 'web_vitals') {
                $captured = $event->params;
            }
        });

        $manager->webVitals('LCP', 1200);

        expect($captured)->not->toBeNull();
        expect($captured['metric_name'])->toBe('LCP');
        expect($captured['value'])->toBe(1200);
        expect($captured['rating'])->toBe('good');
    });

    it('infers needs-improvement for mid-range LCP', function () {
        $manager = new AnalyticsManager;
        $captured = null;

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$captured): void {
            if ($event->name === 'web_vitals') {
                $captured = $event->params;
            }
        });

        $manager->webVitals('LCP', 3000);

        expect($captured['rating'])->toBe('needs-improvement');
    });

    it('infers poor for high LCP', function () {
        $manager = new AnalyticsManager;
        $captured = null;

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$captured): void {
            if ($event->name === 'web_vitals') {
                $captured = $event->params;
            }
        });

        $manager->webVitals('LCP', 5000);

        expect($captured['rating'])->toBe('poor');
    });

    it('allows manual rating override', function () {
        $manager = new AnalyticsManager;
        $captured = null;

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$captured): void {
            if ($event->name === 'web_vitals') {
                $captured = $event->params;
            }
        });

        $manager->webVitals('CLS', 0.15, ['rating' => 'good']);

        expect($captured['rating'])->toBe('good');
    });

    it('correctly rates CLS values', function () {
        $manager = new AnalyticsManager;

        $tests = [
            ['CLS', 0.05, 'good'],
            ['CLS', 0.15, 'needs-improvement'],
            ['CLS', 0.30, 'poor'],
            ['INP', 100, 'good'],
            ['INP', 350, 'needs-improvement'],
            ['INP', 600, 'poor'],
            ['TTFB', 500, 'good'],
            ['TTFB', 1200, 'needs-improvement'],
            ['FCP', 1000, 'good'],
            ['FCP', 2500, 'needs-improvement'],
        ];

        foreach ($tests as [$metric, $value, $expected]) {
            $captured = null;
            $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$captured): void {
                if ($event->name === 'web_vitals') {
                    $captured = $event->params;
                }
            });
            $manager->webVitals($metric, $value);
            expect($captured['rating'])->toBe($expected, "Failed for {$metric}={$value}");
        }
    });
});

// ── New Engagement Convenience Methods (v146.0.0) ───────────────────

describe('V144 Engagement Convenience Methods', function () {
    $methods = [
        'jsError' => ['message', 'Uncaught TypeError'],
        'timing' => ['api_call', 'render', 250],
        'sessionStart' => ['sess-abc'],
        'sessionEnd' => ['sess-abc', 300],
        'elementVisibility' => ['hero-banner', 'section', true],
        'copyText' => ['Hello World', 'header'],
        'hover' => ['cta-button', 2000],
        'clientError' => ['TypeError: undefined', 'TypeError', 'app.js'],
    ];

    it('has all new engagement methods on AnalyticsManager', function () use ($methods) {
        $manager = new AnalyticsManager;

        foreach (array_keys($methods) as $method) {
            expect(method_exists($manager, $method))->toBeTrue("Missing method: {$method}");
        }
    });

    it('tracks jsError with source and line', function () {
        $manager = new AnalyticsManager;
        $captured = null;

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$captured): void {
            if ($event->name === 'js_error') {
                $captured = $event->params;
            }
        });

        $manager->jsError('Cannot read property of null', 'app.js', 42);

        expect($captured['message'])->toBe('Cannot read property of null');
        expect($captured['source'])->toBe('app.js');
        expect($captured['line'])->toBe(42);
    });

    it('tracks timing with category and value', function () {
        $manager = new AnalyticsManager;
        $captured = null;

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$captured): void {
            if ($event->name === 'timing') {
                $captured = $event->params;
            }
        });

        $manager->timing('api_call', 'fetch_users', 342);

        expect($captured['timing_category'])->toBe('api_call');
        expect($captured['timing_variable'])->toBe('fetch_users');
        expect($captured['timing_value'])->toBe(342);
    });

    it('tracks copyText with privacy-safe text length', function () {
        $manager = new AnalyticsManager;
        $captured = null;

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$captured): void {
            if ($event->name === 'copy_text') {
                $captured = $event->params;
            }
        });

        $manager->copyText('Some sensitive text that should not be sent', 'code-block');

        expect($captured['text_length'])->toBe(46);
        expect($captured['source'])->toBe('code-block');
        // Ensure the actual text content is NOT in params
        expect($captured)->not->toHaveKey('text');
        expect($captured)->not->toHaveKey('content');
    });
});

// ── New SaaS Lifecycle Convenience Methods (v146.0.0) ────────────────

describe('V144 SaaS Lifecycle Convenience Methods', function () {
    it('has all new SaaS methods on AnalyticsManager', function () {
        $manager = new AnalyticsManager;
        $methods = [
            'consentGrant', 'consentWithdraw', 'paymentMethodAdded',
            'creditApplied', 'featureLimitReached', 'integrationFailed',
        ];

        foreach ($methods as $method) {
            expect(method_exists($manager, $method))->toBeTrue("Missing method: {$method}");
        }
    });

    it('tracks consentGrant with purposes', function () {
        $manager = new AnalyticsManager;
        $captured = null;

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$captured): void {
            if ($event->name === 'consent_granted') {
                $captured = $event->params;
            }
        });

        $manager->consentGrant(['analytics' => true, 'marketing' => false]);

        expect($captured['purposes'])->toBe(['analytics' => true, 'marketing' => false]);
    });

    it('tracks featureLimitReached with full context', function () {
        $manager = new AnalyticsManager;
        $captured = null;

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$captured): void {
            if ($event->name === 'feature_limit_reached') {
                $captured = $event->params;
            }
        });

        $manager->featureLimitReached('api_calls', 'count', 10000, 10000);

        expect($captured['feature'])->toBe('api_calls');
        expect($captured['limit_type'])->toBe('count');
        expect($captured['current_value'])->toBe(10000);
        expect($captured['limit_value'])->toBe(10000);
    });

    it('tracks integrationFailed with error type', function () {
        $manager = new AnalyticsManager;
        $captured = null;

        $manager->onEventDispatched(function (AnalyticsEvent $event) use (&$captured): void {
            if ($event->name === 'integration_failed') {
                $captured = $event->params;
            }
        });

        $manager->integrationFailed('slack', 'auth');

        expect($captured['integration'])->toBe('slack');
        expect($captured['error_type'])->toBe('auth');
    });
});

// ── Facade Annotations (v146.0.0) ────────────────────────────────────

describe('V144 Facade Annotations', function () {
    it('has identifyAndTrack in facade docblock', function () {
        $content = file_get_contents(__DIR__ . '/../../src/Facades/Analytics.php');
        expect($content)->toContain('identifyAndTrack');
    });

    it('has webVitals in facade docblock', function () {
        $content = file_get_contents(__DIR__ . '/../../src/Facades/Analytics.php');
        expect($content)->toContain('webVitals');
    });

    it('has all 17 new method annotations in facade', function () {
        $content = file_get_contents(__DIR__ . '/../../src/Facades/Analytics.php');
        $methods = [
            'identifyAndTrack', 'webVitals', 'jsError', 'timing',
            'sessionStart', 'sessionEnd', 'elementVisibility', 'copyText',
            'hover', 'clientError', 'consentGrant', 'consentWithdraw',
            'paymentMethodAdded', 'creditApplied', 'featureLimitReached',
            'integrationFailed',
        ];

        foreach ($methods as $method) {
            expect($content)->toContain("@method static void {$method}("), "Missing facade annotation for {$method}");
        }
    });
});

// ── Event Catalog Completeness (v146.0.0) ───────────────────────────

describe('V144 Event Catalog Completeness', function () {
    it('has all v144 convenience methods matching catalog entries', function () {
        $events = [
            'web_vitals', 'js_error', 'timing', 'session_start', 'session_end',
            'element_visibility', 'copy_text', 'hover', 'client_error',
            'consent_granted', 'consent_withdrawn', 'payment_method_added',
            'credit_applied', 'feature_limit_reached', 'integration_failed',
        ];

        foreach ($events as $event) {
            $exists = EventCatalog::eventExists($event);
            $category = EventCatalog::getCategory($event);
            expect($exists)->toBeTrue("Event '{$event}' not found in catalog");
            expect($category)->not->toBeNull("Event '{$event}' has no category");
        }
    });

    it('ecommerce catalog has all core events', function () {
        $required = ['view_item', 'add_to_cart', 'purchase', 'refund', 'begin_checkout'];

        foreach ($required as $event) {
            expect(EcommerceEvents::has($event))->toBeTrue("Missing ecommerce event: {$event}");
        }
    });

    it('saas catalog has all core events', function () {
        $required = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'];

        foreach ($required as $event) {
            expect(SaaSEvents::has($event))->toBeTrue("Missing SaaS event: {$event}");
        }
    });

    it('engagement catalog has all core events', function () {
        $required = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];

        foreach ($required as $event) {
            expect(EngagementEvents::has($event))->toBeTrue("Missing engagement event: {$event}");
        }
    });
});

// ── Version Sweep Integrity (v146.0.0) ──────────────────────────────

describe('V144 Version Sweep Integrity', function () {
    it('composer.json has version 146.0.0', function () {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['version'])->toBe('146.0.0');
    });

    it('package.json has version 146.0.0', function () {
        $pkg = json_decode(file_get_contents(__DIR__ . '/../../package.json'), true);
        expect($pkg['version'])->toBe('146.0.0');
    });

    it('JS client getVersion returns 146.0.0', function () {
        $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        expect($content)->toContain("return '146.0.0'");
    });

    it('no stale 143.0.0 references in JS files', function () {
        $jsFiles = glob(__DIR__ . '/../../resources/js/*.js');
        $stale = [];

        foreach ($jsFiles as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, '143.0.0')) {
                $stale[] = basename($file);
            }
        }

        expect($stale)->toBeEmpty('Stale 143.0.0 references in: ' . implode(', ', $stale));
    });

    it('no stale 143.0.0 references in TypeScript definitions', function () {
        $tsFiles = glob(__DIR__ . '/../../resources/js/*.d.ts');
        $stale = [];

        foreach ($tsFiles as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, '143.0.0')) {
                $stale[] = basename($file);
            }
        }

        expect($stale)->toBeEmpty('Stale 143.0.0 references in: ' . implode(', ', $stale));
    });
});

// ── No TODO/FIXME (v146.0.0) ────────────────────────────────────────

describe('V144 Code Quality', function () {
    it('has no TODO in AnalyticsManager identifyAndTrack section', function () {
        $content = file_get_contents(__DIR__ . '/../../src/AnalyticsManager.php');
        $section = substr($content, strpos($content, 'identifyAndTrack'), 2000);
        expect($section)->not->toContain('TODO');
        expect($section)->not->toContain('FIXME');
    });

    it('has no TODO in Facade v144 section', function () {
        $content = file_get_contents(__DIR__ . '/../../src/Facades/Analytics.php');
        expect($content)->not->toContain('TODO');
        expect($content)->not->toContain('FIXME');
    });
});
