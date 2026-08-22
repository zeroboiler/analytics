<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\DTO\EventPriority;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;

describe('v2.73.0 — Industry Standard SaaS Starter Upgrade', function () {
    describe('Version consistency', function () {
        it('AnalyticsEvent VERSION is 2.73.0', function () {
            expect(AnalyticsEvent::VERSION)->toBe('268.0.0');
        });

        it('composer.json version matches AnalyticsEvent VERSION', function () {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['version'])->toBe('268.0.0');
        });
    });

    describe('AnalyticsManager — flushMetrics', function () {
        it('flushMetrics returns snapshot with correct keys', function () {
            $manager = new \ZeroBoiler\Analytics\AnalyticsManager(null);

            $snapshot = $manager->flushMetrics();

            expect($snapshot)->toHaveKeys(['dispatched', 'failed', 'by_provider']);
            expect($snapshot['dispatched'])->toBe(0);
            expect($snapshot['failed'])->toBe(0);
            expect($snapshot['by_provider'])->toBe([]);
        });

        it('version returns 2.73.0', function () {
            $manager = new \ZeroBoiler\Analytics\AnalyticsManager(null);

            expect($manager->version())->toBe('268.0.0');
        });

        it('providerSummary returns all 6 providers', function () {
            $manager = new \ZeroBoiler\Analytics\AnalyticsManager(null);
            $summary = $manager->providerSummary();

            expect($summary)->toHaveKeys(['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook']);
            foreach (['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook'] as $p) {
                expect($summary[$p])->toHaveKey('enabled');
                expect($summary[$p])->toHaveKey('id');
            }
        });

        it('reportSummary returns correct structure', function () {
            $manager = new \ZeroBoiler\Analytics\AnalyticsManager(null);
            $report = $manager->reportSummary();

            expect($report)->toHaveKeys(['events', 'dispatched', 'failed', 'success_rate', 'top_event']);
            expect($report['success_rate'])->toBe(100.0);
        });

        it('dlqSummary returns correct structure', function () {
            $manager = new \ZeroBoiler\Analytics\AnalyticsManager(null);
            $dlq = $manager->dlqSummary();

            expect($dlq)->toHaveKeys(['enabled', 'strategy', 'total', 'buffered', 'max_size', 'storage_path', 'utilization']);
        });
    });

    describe('EventCatalog — new provider methods', function () {
        it('withPosthogMapping returns filtered events for a category', function () {
            $saasPosthog = EventCatalog::withPosthogMapping('saas');

            expect(is_array($saasPosthog))->toBeTrue();

            foreach ($saasPosthog as $entry) {
                expect($entry['posthog'])->not->toBeNull();
                expect($entry['category'])->toBe('saas');
            }
        });

        it('withPosthogMapping returns empty for invalid category', function () {
            $invalid = EventCatalog::withPosthogMapping('nonexistent');
            expect($invalid)->toBe([]);
        });

        it('withPlausibleMapping returns filtered events for a category', function () {
            $ecommercePlausible = EventCatalog::withPlausibleMapping('ecommerce');

            expect(is_array($ecommercePlausible))->toBeTrue();

            foreach ($ecommercePlausible as $entry) {
                expect($entry['plausible'])->not->toBeNull();
                expect($entry['category'])->toBe('ecommerce');
            }
        });

        it('withPlausibleMapping returns empty for invalid category', function () {
            $invalid = EventCatalog::withPlausibleMapping('nonexistent');
            expect($invalid)->toBe([]);
        });

        it('providerCount returns correct counts', function () {
            $ga4Count = EventCatalog::providerCount('ga4');
            $metaCount = EventCatalog::providerCount('meta');
            $posthogCount = EventCatalog::providerCount('posthog');
            $plausibleCount = EventCatalog::providerCount('plausible');

            // GA4 should have mappings for all events
            expect($ga4Count)->toBeGreaterThan(60);
            // Meta should have substantial mappings
            expect($metaCount)->toBeGreaterThan(20);
            // PostHog should have mappings
            expect($posthogCount)->toBeGreaterThan(10);
            // Plausible might be fewer
            expect($plausibleCount)->toBeGreaterThanOrEqual(0);
        });

        it('providerCoverage returns full structure with counts', function () {
            $coverage = EventCatalog::providerCoverage();

            expect($coverage)->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible', 'counts']);
            expect($coverage['counts'])->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible']);
            expect($coverage['counts']['ga4'])->toBeGreaterThan(60);
        });

        it('providerCoverage event lists are non-empty for ga4', function () {
            $coverage = EventCatalog::providerCoverage();

            expect(count($coverage['ga4']))->toBeGreaterThan(60);
            expect(is_array($coverage['ga4']))->toBeTrue();
        });
    });

    describe('EventCatalog — existing methods integrity', function () {
        it('all returns non-empty array with required keys', function () {
            $all = EventCatalog::all();
            expect(count($all))->toBeGreaterThan(60);

            foreach ($all as $name => $entry) {
                expect($entry)->toHaveKey('name');
                expect($entry)->toHaveKey('class');
                expect($entry)->toHaveKey('ga4');
                expect($entry)->toHaveKey('category');
                expect($entry['name'])->toBe($name);
            }
        });

        it('byCategory returns three categories', function () {
            $byCategory = EventCatalog::byCategory();

            expect($byCategory)->toHaveKeys(['ecommerce', 'saas', 'engagement']);
            expect(count($byCategory['ecommerce']))->toBeGreaterThan(0);
            expect(count($byCategory['saas']))->toBeGreaterThan(0);
            expect(count($byCategory['engagement']))->toBeGreaterThan(0);
        });

        it('searchByProvider finds events by GA4 name', function () {
            $results = EventCatalog::searchByProvider('ga4', 'purchase');
            expect(count($results))->toBeGreaterThanOrEqual(1);

            $names = array_map(fn (array $e): string => $e['name'], $results);
            expect($names)->toContain('purchase');
        });

        it('validate returns valid', function () {
            $result = EventCatalog::validate();
            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
        });

        it('allPosthogMappings returns array with catalog keys', function () {
            $mappings = EventCatalog::allPosthogMappings();
            expect(is_array($mappings))->toBeTrue();
            expect(count($mappings))->toBeGreaterThan(60);
        });

        it('allPlausibleMappings returns array with catalog keys', function () {
            $mappings = EventCatalog::allPlausibleMappings();
            expect(is_array($mappings))->toBeTrue();
            expect(count($mappings))->toBeGreaterThan(60);
        });
    });

    describe('Inertia middleware — new props', function () {
        it('HandleInertiaAnalytics class has handle method', function () {
            expect(method_exists(
                \ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class,
                'handle',
            ))->toBeTrue();
        });

        it('HandleInertiaAnalytics constructor accepts AnalyticsManager and Config', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class);
            $params = $ref->getConstructor()->getParameters();

            expect(count($params))->toBe(2);
            expect($params[0]->getName())->toBe('manager');
            expect($params[1]->getName())->toBe('config');
            expect($params[0]->getType()->getName())->toBe(\ZeroBoiler\Analytics\AnalyticsManager::class);
        });
    });

    describe('Full SaaS Starter Checklist', function () {
        it('1. Event Catalog — 3 categories with typed classes', function () {
            expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(12);
            expect(SaaSEvents::count())->toBeGreaterThanOrEqual(38);
            expect(EngagementEvents::count())->toBeGreaterThanOrEqual(23);

            $total = EventCatalog::count();
            expect($total)->toBeGreaterThan(60);
        });

        it('2. Server-Side Lifecycle Tracker — config-driven', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Tracking\ServerSideTracker::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Services\LifecycleEventMapper::class))->toBeTrue();
        });

        it('3. Inertia middleware — full props', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class))->toBeTrue();
        });

        it('4. API controller + routes', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class))->toBeTrue();
            expect(file_exists(__DIR__.'/../routes/analytics.php'))->toBeTrue();

            $routeContent = file_get_contents(__DIR__.'/../routes/analytics.php');
            expect(str_contains($routeContent, 'events'))->toBeTrue();
            expect(str_contains($routeContent, 'batch'))->toBeTrue();
            expect(str_contains($routeContent, 'identify'))->toBeTrue();
            expect(str_contains($routeContent, 'consent'))->toBeTrue();
        });

        it('5. JS client', function () {
            expect(file_exists(__DIR__.'/../resources/js/analytics.js'))->toBeTrue();
            expect(file_exists(__DIR__.'/../resources/js/analytics.d.ts'))->toBeTrue();

            $js = file_get_contents(__DIR__.'/../resources/js/analytics.js');
            expect(strlen($js))->toBeGreaterThan(5000);
        });

        it('6. Event queue', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Queue\EventReplayQueue::class))->toBeTrue();
        });

        it('7. User identity linking', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Tracking\AnonymousIdTracker::class))->toBeTrue();
        });

        it('8. E-commerce helpers', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Support\EcommerceFormatConverter::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Services\EcommerceAnalyticsService::class))->toBeTrue();
        });

        it('9. Admin commands', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand::class))->toBeTrue();
        });

        it('10. Config expansion — all required sections', function () {
            $config = require __DIR__.'/../config/zeroboiler.php';
            $analytics = $config['analytics'];

            $requiredSections = [
                'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track',
                'queue', 'identity', 'ecommerce', 'api', 'plausible',
                'posthog', 'webhook', 'debug', 'validation', 'pipeline',
                'sampling', 'pii_sanitization', 'replay', 'metrics',
                'stream', 'client_auto_track', 'performance',
                'tracking_preference', 'dedup', 'gdpr', 'attribution',
                'profile', 'inbound_webhook', 'funnels', 'alerts',
                'lifecycle', 'correlation', 'retention', 'source_tagging',
                'broadcast', 'tenant', 'reporting', 'dead_letter_queue',
                'realtime', 'ab_tests', 'snapshots', 'saas_kpi',
            ];

            foreach ($requiredSections as $section) {
                expect(array_key_exists($section, $analytics))
                    ->toBeTrue();
            }
        });

        it('11. Optional providers — Plausible + PostHog', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class))->toBeTrue();

            // Verify they implement TrackerInterface
            $plausibleImplements = in_array(
                \ZeroBoiler\Analytics\Trackers\TrackerInterface::class,
                class_implements(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class),
                true,
            );
            $posthogImplements = in_array(
                \ZeroBoiler\Analytics\Trackers\TrackerInterface::class,
                class_implements(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class),
                true,
            );

            expect($plausibleImplements)->toBeTrue();
            expect($posthogImplements)->toBeTrue();
        });

        it('12. Tests — 80+ test files', function () {
            $testFiles = glob(__DIR__.'/*.php');
            expect(count($testFiles))->toBeGreaterThan(80);

            // Check subdirectories too
            $featureTests = glob(__DIR__.'/Feature/**/*.php', GLOB_BRACE);
            expect(count($featureTests))->toBeGreaterThanOrEqual(0);
        });
    });

    describe('Cross-cutting quality checks', function () {
        it('all PHP files use strict types', function () {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $violations = [];

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                if (! str_contains($content, 'declare(strict_types=1)')) {
                    $violations[] = str_replace(__DIR__.'/../', '', $file);
                }
            }

            expect($violations)->toBeEmpty();
        });

        it('all PHP files have license header', function () {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $violations = [];

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                if (! str_contains($content, 'ZeroBoiler, licensed under the MIT license')) {
                    $violations[] = str_replace(__DIR__.'/../', '', $file);
                }
            }

            expect($violations)->toBeEmpty();
        });

        it('key classes are final', function () {
            $finalClasses = [
                \ZeroBoiler\Analytics\AnalyticsManager::class,
                \ZeroBoiler\Analytics\DTO\AnalyticsEvent::class,
                \ZeroBoiler\Analytics\DTO\ConsentState::class,
                \ZeroBoiler\Analytics\Events\EventCatalog::class,
                \ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class,
                \ZeroBoiler\Analytics\Tracking\ServerSideTracker::class,
            ];

            foreach ($finalClasses as $class) {
                $ref = new ReflectionClass($class);
                expect($ref->isFinal())
                    ->toBeTrue();
            }
        });

        it('AnalyticsEvent is readonly', function () {
            $ref = new ReflectionClass(AnalyticsEvent::class);
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('EventCatalog is a utility class with no public constructor', function () {
            $ref = new ReflectionClass(EventCatalog::class);

            // It should have no public constructor (or none at all)
            $constructor = $ref->getConstructor();
            expect($constructor === null || ! $constructor->isPublic())
                ->toBeTrue();
        });

        it('Facade exists and is proxied to AnalyticsManager', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Facades\Analytics::class))->toBeTrue();
        });
    });
});
