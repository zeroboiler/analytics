<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

/**
 * Phase 34 — Version Integrity & SaaS Maturity Audit.
 *
 * Verifies version consistency across all package files and SaaS starter
 * maturity across event catalogs, config sections, and provider coverage.
 *
 * @since 105.0.0
 */
describe('Phase 34 — Version Integrity & SaaS Maturity Audit', function () {
    describe('Version Consistency', function () {
        it('has consistent version across all 5 package files', function () {
            $expected = '105.0.0';

            // 1. PHP DTO constant
            expect(AnalyticsEvent::VERSION)->toBe($expected);

            // 2. composer.json
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['version'])->toBe($expected);

            // 3. package.json
            $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
            expect($pkg['version'])->toBe($expected);

            // 4. analytics.js (check first 50 lines for @version tag)
            $jsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($jsContent)->toContain('@version ' . $expected);

            // 5. analytics.d.ts
            $dtsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
            expect($dtsContent)->toContain('@version ' . $expected);
        });

        it('has version annotation in AnalyticsServiceProvider docblock', function () {
            $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
            expect($content)->toContain('@version 105.0.0');
        });

        it('package.json repository directory is root (not nested)', function () {
            $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
            expect($pkg['repository']['directory'])->toBe('.');
        });
    });

    describe('EventCatalog Maturity', function () {
        it('summary returns all 6 built-in categories', function () {
            $summary = EventCatalog::summary();
            $expectedCategories = ['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure'];

            foreach ($expectedCategories as $category) {
                expect(array_key_exists($category, $summary))->toBeTrue(
                    "Missing category: {$category}",
                );
            }

            expect(count(array_intersect_key($summary, array_flip($expectedCategories))))->toBe(6);
            expect($summary['total'])->toBeGreaterThan(0);
        });

        it('providerCoverage includes all 8 providers with counts and event lists', function () {
            $coverage = EventCatalog::providerCoverage();
            $expectedProviders = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

            foreach ($expectedProviders as $provider) {
                expect(array_key_exists($provider, $coverage))->toBeTrue(
                    "Missing provider in coverage: {$provider}",
                );
                expect($coverage[$provider])->toHaveKey('count');
                expect($coverage[$provider])->toHaveKey('events');
            }
        });

        it('byProvider returns entries for all 8 providers', function () {
            $byProvider = EventCatalog::byProvider();
            $expectedProviders = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

            foreach ($expectedProviders as $provider) {
                expect(array_key_exists($provider, $byProvider))->toBeTrue(
                    "Missing provider in byProvider: {$provider}",
                );
            }
        });
    });

    describe('Event Catalog Core Events', function () {
        it('Ecommerce catalog has purchase funnel events with provider mappings', function () {
            $funnelEvents = ['view_item', 'add_to_cart', 'purchase', 'refund'];

            foreach ($funnelEvents as $eventName) {
                expect(EcommerceEvents::has($eventName))->toBeTrue(
                    "Missing ecommerce event: {$eventName}",
                );
                $entry = EcommerceEvents::get($eventName);
                expect($entry)->not()->toBeNull();
                expect($entry)->toHaveKey('ga4');
                expect($entry)->toHaveKey('meta');
                expect($entry['ga4'])->toBeString();
            }
        });

        it('SaaS catalog has lifecycle events with provider mappings', function () {
            $lifecycleEvents = ['sign_up', 'login', 'trial_start', 'plan_upgrade', 'cancellation'];

            foreach ($lifecycleEvents as $eventName) {
                expect(SaaSEvents::has($eventName))->toBeTrue(
                    "Missing SaaS event: {$eventName}",
                );
                $entry = SaaSEvents::get($eventName);
                expect($entry)->not()->toBeNull();
                expect($entry)->toHaveKey('ga4');
                expect($entry)->toHaveKey('meta');
                expect($entry['ga4'])->toBeString();
            }
        });

        it('Engagement catalog has core tracking events with provider mappings', function () {
            $coreEvents = ['page_view', 'click', 'form_submit', 'search', 'share', 'error'];

            foreach ($coreEvents as $eventName) {
                expect(EngagementEvents::has($eventName))->toBeTrue(
                    "Missing engagement event: {$eventName}",
                );
                $entry = EngagementEvents::get($eventName);
                expect($entry)->not()->toBeNull();
                expect($entry)->toHaveKey('ga4');
                expect($entry['ga4'])->toBeString();
            }
        });
    });

    describe('Config SaaS Sections', function () {
        it('has all required SaaS config sections', function () {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            $analytics = $config['analytics'] ?? [];

            $requiredSections = [
                'queue', 'api', 'identity', 'ecommerce', 'lifecycle',
                'consent', 'sampling', 'revenue_waterfall', 'feature_flags',
            ];

            foreach ($requiredSections as $section) {
                expect(array_key_exists($section, $analytics))->toBeTrue(
                    "Missing config section: analytics.{$section}",
                );
            }
        });

        it('queue section has required keys', function () {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            $queue = $config['analytics']['queue'] ?? [];

            $requiredKeys = ['enabled', 'queue', 'connection', 'max_batch_size'];
            foreach ($requiredKeys as $key) {
                expect(array_key_exists($key, $queue))->toBeTrue(
                    "Missing queue key: {$key}",
                );
            }
        });

        it('identity section has cookie and resolution keys', function () {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            $identity = $config['analytics']['identity'] ?? [];

            $requiredKeys = ['cookie_name', 'cookie_ttl', 'cookie_secure', 'cookie_samesite', 'link_on_auth'];
            foreach ($requiredKeys as $key) {
                expect(array_key_exists($key, $identity))->toBeTrue(
                    "Missing identity key: {$key}",
                );
            }

            // Resolution keys
            $resolutionKeys = ['cache_prefix', 'link_ttl', 'max_links_per_user'];
            foreach ($resolutionKeys as $key) {
                expect(array_key_exists($key, $identity))->toBeTrue(
                    "Missing identity resolution key: {$key}",
                );
            }
        });

        it('lifecycle section has enabled and custom_mappings keys', function () {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            $lifecycle = $config['analytics']['lifecycle'] ?? [];

            expect(array_key_exists('enabled', $lifecycle))->toBeTrue();
            expect(array_key_exists('queue_events', $lifecycle))->toBeTrue();
            expect(array_key_exists('custom_mappings', $lifecycle))->toBeTrue();
            expect($lifecycle['custom_mappings'])->toBeArray();
        });
    });

    describe('Package Metadata', function () {
        it('package.json peerDependencies are valid and optional', function () {
            $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);

            expect($pkg)->toHaveKey('peerDependencies');
            expect($pkg)->toHaveKey('peerDependenciesMeta');

            foreach (array_keys($pkg['peerDependencies']) as $dep) {
                expect(isset($pkg['peerDependenciesMeta'][$dep]['optional']))->toBeTrue(
                    "Peer dependency {$dep} should be marked as optional",
                );
            }
        });

        it('composer.json requires PHP 8.5+ and Laravel 13+', function () {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

            expect($composer['require']['php'])->toBe('^8.5');
            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
        });
    });
});
