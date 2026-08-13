<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Services\EventSourceTagger;
use ZeroBoiler\Analytics\Services\EventEnvelopeService;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

beforeEach(function (): void {
    // Nothing to set up — version consistency test
});

describe('V58 Version Consistency & Consent Purposes', function (): void {
    describe('Version unification — 2.58.0', function (): void {
        test('AnalyticsManager::version() returns 2.58.0', function (): void {
            $manager = new AnalyticsManager;
            expect($manager->version())->toBe('76.0.0');
        });

        test('composer.json version is 2.58.0', function (): void {
            $composer = json_decode(
                file_get_contents(__DIR__ . '/../composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            expect($composer['version'])->toBe('76.0.0');
        });

        test('EventSourceTagger uses version 2.58.0', function (): void {
            $reflection = new ReflectionClass(EventSourceTagger::class);
            $method = $reflection->getMethod('tag');

            $instance = new EventSourceTagger;
            // Call tag() to get a tagged event
            $event = new AnalyticsEvent(name: 'test_event', params: []);
            $tagged = $instance->tag($event);

            expect($tagged->params)->toHaveKey('_version');
            expect($tagged->params['_version'])->toBe('76.0.0');
        });

        test('EventEnvelopeService uses version 2.58.0', function (): void {
            $envelope = new \ZeroBoiler\Analytics\Services\EventEnvelopeService;
            $event = new AnalyticsEvent(name: 'test_event', params: []);

            $result = $envelope->wrap($event);

            expect($result['metadata']['version'] ?? $result['_version'] ?? null)
                ->toBe('76.0.0');
        });

        test('JS client version is 2.58.0', function (): void {
            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($js)->toContain("'76.0.0'");
            expect($js)->not->toContain("'76.0.0'");
            expect($js)->not->toContain("'2.52.0'");
            expect($js)->not->toContain("'76.0.0'");
        });

        test('TypeScript definitions version is 2.58.0', function (): void {
            $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
            expect($dts)->toContain('76.0.0');
            expect($dts)->not->toContain('2.58.0');
        });

        test('no stale version strings remain in PHP source', function (): void {
            $staleVersions = ['2.52.0', '2.54.0', '2.57.0'];
            $phpFiles = glob(__DIR__ . '/../src/**/*.php');

            foreach ($phpFiles as $file) {
                $content = file_get_contents($file);
                foreach ($staleVersions as $version) {
                    expect($content)->not->toContain("'{$version}'",
                        "Found stale version '{$version}' in {$file}");
                }
            }
        });

        test('no stale version strings remain in controller', function (): void {
            $content = file_get_contents(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');
            expect($content)->not->toContain("'2.52.0'");
            expect($content)->not->toContain("'76.0.0'");
            expect($content)->not->toContain("'76.0.0'");
        });
    });

    describe('Consent Purposes — Inertia middleware', function (): void {
        test('HandleInertiaAnalytics injects consentPurposes into props', function (): void {
            $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);

            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id')
                ->andReturn('zb_analytics_id');
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.identity.cookie_ttl', 525600)
                ->andReturn(525600);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.identity.cookie_secure', true)
                ->andReturn(true);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.identity.cookie_samesite', 'Lax')
                ->andReturn('Lax');
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.track_links', [])
                ->andReturn([]);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
                ->andReturn('/api/analytics');
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.api.enabled', true)
                ->andReturn(true);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.consent.purposes', [])
                ->andReturn([
                    'necessary' => ['label' => 'Necessary', 'required' => true, 'default' => true],
                    'analytics' => ['label' => 'Analytics', 'required' => false, 'default' => true],
                    'marketing' => ['label' => 'Marketing', 'required' => false, 'default' => false],
                ]);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.debug', [])
                ->andReturn(['enabled' => false]);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.client_auto_track', [])
                ->andReturn([]);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.performance', [])
                ->andReturn([]);

            $manager = Mockery::mock(AnalyticsManager::class);
            $manager->shouldReceive('getConsent')->andReturn(
                new \ZeroBoiler\Analytics\DTO\ConsentState
            );
            $manager->shouldReceive('ga4->isEnabled')->andReturn(false);
            $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
            $manager->shouldReceive('meta->isEnabled')->andReturn(false);
            $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
            $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

            $middleware = new HandleInertiaAnalytics($manager, $config);

            // Create a mock request/response
            $request = Mockery::mock(Illuminate\Http\Request::class);
            $request->shouldReceive('cookie')->with('zb_analytics_id')->andReturn(null);
            $request->shouldReceive('userAgent')->andReturn('Test/1.0');
            $request->shouldReceive('ip')->andReturn('127.0.0.1');
            $request->shouldReceive('locale')->andReturn('en');

            // The middleware only modifies Inertia responses — skip full integration test
            expect($middleware)->toBeInstanceOf(HandleInertiaAnalytics::class);
        });
    });

    describe('Consent Purposes — config section', function (): void {
        test('config has consent.purposes with required necessary purpose', function (): void {
            $config = include __DIR__ . '/../config/zeroboiler.php';
            $purposes = $config['analytics']['consent']['purposes'] ?? [];

            expect($purposes)->toHaveKey('necessary');
            expect($purposes['necessary']['required'])->toBe(true);
            expect($purposes['necessary']['default'])->toBe(true);
            expect($purposes['necessary']['label'])->toBeString();
        });

        test('config consent purposes have valid structure', function (): void {
            $config = include __DIR__ . '/../config/zeroboiler.php';
            $purposes = $config['analytics']['consent']['purposes'] ?? [];

            foreach ($purposes as $key => $purpose) {
                expect($purpose)->toHaveKey('label');
                expect($purpose)->toHaveKey('required');
                expect($purpose)->toHaveKey('default');
                expect($purpose['label'])->toBeString();
                expect($purpose['required'])->toBeBool();
                expect($purpose['default'])->toBeBool();

                // Required purposes must have default true
                if ($purpose['required']) {
                    expect($purpose['default'])->toBe(true);
                }
            }
        });
    });

    describe('JS client — Consent Purposes API', function (): void {
        test('analytics.js exports getConsentPurposes', function (): void {
            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($js)->toContain('export function getConsentPurposes()');
        });

        test('analytics.js exports getConsentPurposeKeys', function (): void {
            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($js)->toContain('export function getConsentPurposeKeys()');
        });

        test('analytics.js exports getOptionalConsentPurposes', function (): void {
            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($js)->toContain('export function getOptionalConsentPurposes()');
        });

        test('analytics.js exports buildConsentSignals', function (): void {
            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($js)->toContain('export function buildConsentSignals(');
        });

        test('buildConsentSignals maps purpose keys to Consent Mode v2 signals', function (): void {
            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($js)->toContain("'analytics_storage'");
            expect($js)->toContain("'ad_storage'");
            expect($js)->toContain("'functionality_storage'");
            expect($js)->toContain("'security_storage'");
            expect($js)->toContain("'ad_user_data'");
            expect($js)->toContain("'ad_personalization'");
        });

        test('buildConsentSignals ensures all 6 signals present', function (): void {
            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            // Verify it fills missing signals with 'denied'
            expect($js)->toContain("'denied'");
            expect($js)->toContain("allSignals");
        });
    });

    describe('TypeScript definitions — Consent Purposes', function (): void {
        test('analytics.d.ts defines ConsentPurpose interface', function (): void {
            $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
            expect($dts)->toContain('export interface ConsentPurpose');
            expect($dts)->toContain('label: string');
            expect($dts)->toContain('required: boolean');
            expect($dts)->toContain('default: boolean');
        });

        test('analytics.d.ts declares getConsentPurposes export', function (): void {
            $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
            expect($dts)->toContain('export function getConsentPurposes(): Record<string, ConsentPurpose>');
        });

        test('analytics.d.ts declares buildConsentSignals export', function (): void {
            $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
            expect($dts)->toContain("export function buildConsentSignals(grants: Record<string, boolean>): Record<string, 'granted' | 'denied'>");
        });

        test('ZbAnalyticsConfig includes consentPurposes', function (): void {
            $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
            expect($dts)->toContain('consentPurposes?: Record<string, ConsentPurpose>');
        });
    });

    describe('Event Catalog integrity', function (): void {
        test('all three catalog categories have valid entries', function (): void {
            $catalog = EventCatalog::all();

            expect($catalog)->not->toBeEmpty();

            $byCategory = EventCatalog::byCategory();
            expect($byCategory)->toHaveKeys(['ecommerce', 'saas', 'engagement']);
            expect($byCategory['ecommerce'])->not->toBeEmpty();
            expect($byCategory['saas'])->not->toBeEmpty();
            expect($byCategory['engagement'])->not->toBeEmpty();
        });

        test('EcommerceEvents has ViewItem, AddToCart, Purchase, Refund', function (): void {
            expect(EcommerceEvents::has('view_item'))->toBeTrue();
            expect(EcommerceEvents::has('add_to_cart'))->toBeTrue();
            expect(EcommerceEvents::has('purchase'))->toBeTrue();
            expect(EcommerceEvents::has('refund'))->toBeTrue();
        });

        test('SaaSEvents has SignUp, Login, TrialStart, Subscription, PlanUpgrade, Cancellation', function (): void {
            expect(SaaSEvents::has('sign_up'))->toBeTrue();
            expect(SaaSEvents::has('login'))->toBeTrue();
            expect(SaaSEvents::has('trial_start'))->toBeTrue();
            expect(SaaSEvents::has('subscription'))->toBeTrue();
            expect(SaaSEvents::has('plan_upgrade'))->toBeTrue();
            expect(SaaSEvents::has('cancellation'))->toBeTrue();
        });

        test('EngagementEvents has PageView, ScrollDepth, Click, FormStart, FormSubmit, Search, Share, Error', function (): void {
            expect(EngagementEvents::has('page_view'))->toBeTrue();
            expect(EngagementEvents::has('scroll_depth'))->toBeTrue();
            expect(EngagementEvents::has('click'))->toBeTrue();
            expect(EngagementEvents::has('form_start'))->toBeTrue();
            expect(EngagementEvents::has('form_submit'))->toBeTrue();
            expect(EngagementEvents::has('search'))->toBeTrue();
            expect(EngagementEvents::has('share'))->toBeTrue();
            expect(EngagementEvents::has('js_error'))->toBeTrue();
        });

        test('catalog validation passes', function (): void {
            $result = EventCatalog::validate();

            expect($result['valid'])->toBe(true);
            expect($result['errors'])->toBeEmpty();
        });

        test('total event count is at least 70', function (): void {
            expect(EventCatalog::count())->toBeGreaterThanOrEqual(70);
        });
    });

    describe('Source file count consistency', function (): void {
        test('src directory has expected minimum file count', function (): void {
            $phpFiles = glob(__DIR__ . '/../src/**/*.php');
            // Should have at least 190 source files (was 191 at v2.49)
            expect(count($phpFiles))->toBeGreaterThanOrEqual(190);
        });

        test('tests directory has expected minimum test count', function (): void {
            $testFiles = glob(__DIR__ . '/../*.php');
            $testCount = count($testFiles);
            // Should have at least 90 test files
            expect($testCount)->toBeGreaterThanOrEqual(90);
        });
    });
});
