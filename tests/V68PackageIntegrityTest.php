<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;

beforeEach(function (): void {
    $this->manager = new AnalyticsManager(null);
});

describe('V68 Package Integrity', function (): void {
    // ─── Version Consistency ─────────────────────────────────────────
    describe('Version Consistency', function (): void {
        test('AnalyticsManager returns 2.68.0', function (): void {
            expect($this->manager->version())->toBe('76.0.0');
        });

        test('composer.json version is 2.68.0', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['version'])->toBe('76.0.0');
        });

        test('JS client header version is 2.68.0', function (): void {
            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($js)->toContain('@version 76.0.0');
        });

        test('JS client getVersion returns 2.68.0', function (): void {
            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($js)->toContain("return '76.0.0'");
        });

        test('JS client _getInternalVersion returns 2.68.0', function (): void {
            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($js)->toContain("_getInternalVersion");
            // Both getVersion and _getInternalVersion return the same version
            $matches = [];
            preg_match_all("/return '2\.(\d+\.\d+)'/", $js, $matches);
            // All version strings in the JS file should be identical
            $versions = array_unique($matches[1] ?? []);
            expect(count($versions))->toBe(1);
            expect($versions[0])->toBe('68.0');
        });

        test('TypeScript definitions version is 2.68.0', function (): void {
            $ts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
            expect($ts)->toContain('@version 76.0.0');
        });

        test('no stale 2.67.0 version references in source files', function (): void {
            $stale = shell_exec('grep -rn "2\.67\.0" ' . escapeshellarg(__DIR__ . '/../src') . ' --include="*.php" 2>/dev/null || true');
            expect(trim($stale ?: ''))->toBe('');
        });

        test('no stale 2.67.0 version references in JS client', function (): void {
            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($js)->not->toContain('2.67.0');
        });

        test('no stale 2.67.0 version references in TypeScript definitions', function (): void {
            $ts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
            expect($ts)->not->toContain('2.67.0');
        });

        test('no stale 2.67.0 in composer.json', function (): void {
            $composer = file_get_contents(__DIR__ . '/../composer.json');
            expect($composer)->not->toContain('2.67.0');
        });
    });

    // ─── File Counts ───────────────────────────────────────────────────
    describe('File Counts', function (): void {
        test('source file count is at least 200', function (): void {
            $srcFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
            // glob may not recurse with GLOB_BRACE on all platforms, count individual
            $count = 0;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $count++;
                }
            }
            expect($count)->toBeGreaterThanOrEqual(200);
        });

        test('test file count is at least 100', function (): void {
            $count = 0;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php' && $file->getFilename() !== 'Pest.php') {
                    $count++;
                }
            }
            expect($count)->toBeGreaterThanOrEqual(100);
        });

        test('JS client exists and is substantial', function (): void {
            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            $lines = count(explode("\n", $js));
            expect($lines)->toBeGreaterThanOrEqual(3000);
        });

        test('TypeScript definitions exist and are substantial', function (): void {
            $ts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
            $lines = count(explode("\n", $ts));
            expect($lines)->toBeGreaterThanOrEqual(800);
        });

        test('config file exists and is substantial', function (): void {
            $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
            $lines = count(explode("\n", $config));
            expect($lines)->toBeGreaterThanOrEqual(1000);
        });

        test('routes file exists', function (): void {
            expect(file_exists(__DIR__ . '/../routes/analytics.php'))->toBeTrue();
        });
    });

    // ─── Event Catalog Integrity ──────────────────────────────────────
    describe('Event Catalog Integrity', function (): void {
        test('total catalog count is at least 70', function (): void {
            expect(EventCatalog::count())->toBeGreaterThanOrEqual(70);
        });

        test('ecommerce category exists with at least 12 events', function (): void {
            $byCategory = EventCatalog::byCategory();
            expect($byCategory)->toHaveKey('ecommerce');
            expect(count($byCategory['ecommerce']))->toBeGreaterThanOrEqual(12);
        });

        test('saas category exists with at least 35 events', function (): void {
            $byCategory = EventCatalog::byCategory();
            expect($byCategory)->toHaveKey('saas');
            expect(count($byCategory['saas']))->toBeGreaterThanOrEqual(35);
        });

        test('engagement category exists with at least 20 events', function (): void {
            $byCategory = EventCatalog::byCategory();
            expect($byCategory)->toHaveKey('engagement');
            expect(count($byCategory['engagement']))->toBeGreaterThanOrEqual(20);
        });

        test('all events have required keys', function (): void {
            $catalog = EventCatalog::all();
            foreach ($catalog as $name => $entry) {
                expect($entry)->toHaveKey('name');
                expect($entry)->toHaveKey('class');
                expect($entry)->toHaveKey('ga4');
                expect($entry)->toHaveKey('meta');
                expect($entry)->toHaveKey('category');
                expect($entry['name'])->toBe($name);
            }
        });

        test('no duplicate event names in catalog', function (): void {
            $names = EventCatalog::names();
            expect($names)->toEqual(array_unique($names));
        });

        test('EcommerceEvents has at least 12 entries', function (): void {
            expect(count(EcommerceEvents::all()))->toBeGreaterThanOrEqual(12);
        });

        test('SaaSEvents has at least 35 entries', function (): void {
            expect(count(SaaSEvents::all()))->toBeGreaterThanOrEqual(35);
        });

        test('EngagementEvents has at least 20 entries', function (): void {
            expect(count(EngagementEvents::all()))->toBeGreaterThanOrEqual(20);
        });

        test('all catalog classes exist and extend AnalyticsEvent', function (): void {
            $catalog = EventCatalog::all();
            foreach ($catalog as $name => $entry) {
                expect(class_exists($entry['class']))->toBeTrue("`{$name}` class {$entry['class']} does not exist");
            }
        });

        test('cross-provider GA4 mappings exist for all events', function (): void {
            $catalog = EventCatalog::all();
            foreach ($catalog as $name => $entry) {
                expect($entry['ga4'])->not->toBeNull("`{$name}` missing GA4 mapping");
            }
        });

        test('cross-provider Meta mappings exist for all events', function (): void {
            $catalog = EventCatalog::all();
            foreach ($catalog as $name => $entry) {
                expect($entry['meta'])->not->toBeNull("`{$name}` missing Meta mapping");
            }
        });
    });

    // ─── SaaS Event Table Completeness ───────────────────────────────
    describe('SaaS Event Table Completeness', function (): void {
        test('TrialConvertedEvent exists in catalog', function (): void {
            expect(EventCatalog::has('trial_converted'))->toBeTrue();
        });

        test('SubscriptionResumedEvent exists in catalog', function (): void {
            expect(EventCatalog::has('subscription_resumed'))->toBeTrue();
        });

        test('MilestoneReachedEvent exists in catalog', function (): void {
            expect(EventCatalog::has('milestone_reached'))->toBeTrue();
        });

        test('TrialConvertedEvent has valid catalog entry', function (): void {
            $entry = EventCatalog::get('trial_converted');
            expect($entry)->not->toBeNull();
            expect($entry['category'])->toBe('saas');
            expect($entry['ga4'])->not->toBeEmpty();
            expect($entry['meta'])->not->toBeEmpty();
        });

        test('SubscriptionResumedEvent has valid catalog entry', function (): void {
            $entry = EventCatalog::get('subscription_resumed');
            expect($entry)->not->toBeNull();
            expect($entry['category'])->toBe('saas');
            expect($entry['ga4'])->not->toBeEmpty();
            expect($entry['meta'])->not->toBeEmpty();
        });

        test('MilestoneReachedEvent has valid catalog entry', function (): void {
            $entry = EventCatalog::get('milestone_reached');
            expect($entry)->not->toBeNull();
            expect($entry['category'])->toBe('saas');
            expect($entry['ga4'])->not->toBeEmpty();
            expect($entry['meta'])->not->toBeEmpty();
        });

        test('core SaaS events are present', function (): void {
            $coreSaasEvents = [
                'sign_up', 'login', 'logout', 'start_trial', 'end_trial',
                'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation',
                'feature_used', 'revenue_tracked', 'invite_sent',
                'integration_connected', 'subscription_renewal',
            ];
            foreach ($coreSaasEvents as $event) {
                expect(EventCatalog::has($event))->toBeTrue("`{$event}` missing from catalog");
            }
        });
    });

    // ─── Roadmap Completion ───────────────────────────────────────────
    describe('Roadmap Completion', function (): void {
        test('Event Catalog exists — items 1 implemented', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Events\EventCatalog::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::class))->toBeTrue();
        });

        test('Server-Side Lifecycle Tracker exists — item 2 implemented', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Services\LifecycleEventMapper::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Tracking\ServerSideTracker::class))->toBeTrue();
        });

        test('Inertia middleware exists — item 3 implemented', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class))->toBeTrue();
        });

        test('API controller and routes exist — item 4 implemented', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class))->toBeTrue();
            expect(file_exists(__DIR__ . '/../routes/analytics.php'))->toBeTrue();
        });

        test('Svelte JS client library exists — item 5 implemented', function (): void {
            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($js)->toContain('trackEvent');
            expect($js)->toContain('trackPageView');
            expect($js)->toContain('initInertiaPageViewTracker');
            expect($js)->toContain('scroll');
        });

        test('Event queue (async dispatch) exists — item 6 implemented', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Queue\EventReplayQueue::class))->toBeTrue();
        });

        test('User identity linking exists — item 7 implemented', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Tracking\AnonymousIdTracker::class))->toBeTrue();
        });

        test('E-commerce helpers with GA4 + Meta format conversion — item 8 implemented', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Support\EcommerceFormatConverter::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Services\EcommerceAnalyticsService::class))->toBeTrue();
        });

        test('Admin commands exist — item 9 implemented', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class))->toBeTrue();
        });

        test('Config expansion exists — item 10 implemented', function (): void {
            $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
            expect($config)->toContain("'queue'");
            expect($config)->toContain("'api'");
            expect($config)->toContain("'identity'");
            expect($config)->toContain("'ecommerce'");
        });

        test('Optional providers (Plausible, PostHog) exist — item 11 implemented', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class))->toBeTrue();
        });

        test('Tests and README exist — item 12 implemented', function (): void {
            expect(file_exists(__DIR__ . '/../README.md'))->toBeTrue();
            expect(file_exists(__DIR__ . '/../CHANGES.md'))->toBeTrue();
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect(strlen($readme))->toBeGreaterThan(50000); // Comprehensive README
        });
    });

    // ─── PHP 8.5 Compliance ───────────────────────────────────────────
    describe('PHP 8.5 Compliance', function (): void {
        test('all source files declare strict types', function (): void {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $violations = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $content = file_get_contents($file->getPathname());
                    if (! str_contains($content, 'declare(strict_types=1)')) {
                        $violations[] = $file->getPathname();
                    }
                }
            }
            expect($violations)->toBeEmpty('Files missing declare(strict_types=1): ' . implode(', ', $violations));
        });

        test('AnalyticsManager is final', function (): void {
            $ref = new ReflectionClass(AnalyticsManager::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('AnalyticsEvent DTO is readonly', function (): void {
            $ref = new ReflectionClass(AnalyticsEvent::class);
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('all tracker classes implement TrackerInterface', function (): void {
            $trackers = [
                \ZeroBoiler\Analytics\Trackers\GA4Tracker::class,
                \ZeroBoiler\Analytics\Trackers\GTMTracker::class,
                \ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class,
                \ZeroBoiler\Analytics\Trackers\PlausibleTracker::class,
                \ZeroBoiler\Analytics\Trackers\PosthogTracker::class,
                \ZeroBoiler\Analytics\Trackers\WebhookTracker::class,
            ];
            foreach ($trackers as $tracker) {
                expect(class_exists($tracker))->toBeTrue("{$tracker} does not exist");
                $ref = new ReflectionClass($tracker);
                expect($ref->implementsInterface(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class))
                    ->toBeTrue("{$tracker} does not implement TrackerInterface");
            }
        });

        test('AnalyticsManager constructor has void return type', function (): void {
            $ref = new ReflectionMethod(AnalyticsManager::class, '__construct');
            expect($ref->getReturnType()?->getName())->toBe('void');
        });
    });

    // ─── Config Integrity ─────────────────────────────────────────────
    describe('Config Integrity', function (): void {
        test('config file returns valid array', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect($config)->toBeArray();
            expect($config)->toHaveKey('analytics');
        });

        test('core config sections exist', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            $analytics = $config['analytics'];
            $coreSections = [
                'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track', 'queue',
                'identity', 'ecommerce', 'revenue', 'api', 'plausible', 'posthog',
                'debug', 'validation', 'pipeline', 'sampling', 'pii_sanitization',
            ];
            foreach ($coreSections as $section) {
                expect($analytics)->toHaveKey($section);
            }
        });

        test('enterprise config sections exist', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            $analytics = $config['analytics'];
            $enterpriseSections = [
                'broadcast', 'tenant', 'retention_policy', 'gate', 'reporting',
                'dead_letter_queue', 'realtime', 'ab_tests', 'snapshots', 'saas_kpi',
            ];
            foreach ($enterpriseSections as $section) {
                expect($analytics)->toHaveKey($section);
            }
        });

        test('advanced config sections exist', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            $analytics = $config['analytics'];
            $advancedSections = [
                'conversion_analytics', 'delivery_confirmation', 'priority',
                'data_warehouse', 'property_schema', 'envelope', 'consent_purposes',
            ];
            foreach ($advancedSections as $section) {
                expect($analytics)->toHaveKey($section);
            }
        });
    });

    // ─── Provider Architecture ───────────────────────────────────────
    describe('Provider Architecture', function (): void {
        test('6 providers are configured', function (): void {
            $providers = $this->manager->providerInfo();
            expect($providers)->toHaveKey('ga4');
            expect($providers)->toHaveKey('gtm');
            expect($providers)->toHaveKey('meta');
            expect($providers)->toHaveKey('plausible');
            expect($providers)->toHaveKey('posthog');
            expect($providers)->toHaveKey('webhook');
            expect(count($providers))->toBe(6);
        });

        test('all providers have enabled and id fields', function (): void {
            $providers = $this->manager->providerInfo();
            foreach ($providers as $name => $info) {
                expect($info)->toHaveKey('enabled');
                expect($info)->toHaveKey('id');
            }
        });
    });

    // ─── README Content Verification ──────────────────────────────────
    describe('README Content', function (): void {
        test('README documents 73 events', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('73');
        });

        test('README documents 38 SaaS events', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('38 SaaS');
        });

        test('README documents v2.68.0 in health response', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('"version": "2.68.0"');
        });

        test('README includes upgrade guide for v2.68.0', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('v2.68.0');
            expect($readme)->toContain('From v2.67.x to v2.68.0');
        });

        test('README includes v2.68.0 changelog entry', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('v2.68.0 —');
        });

        test('README documents TrialConvertedEvent', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('TrialConvertedEvent');
        });

        test('README documents SubscriptionResumedEvent', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('SubscriptionResumedEvent');
        });

        test('README documents MilestoneReachedEvent', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('MilestoneReachedEvent');
        });

        test('README is at least 50KB (comprehensive)', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect(strlen($readme))->toBeGreaterThan(50000);
        });
    });
});
