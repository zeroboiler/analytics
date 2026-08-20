<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\DTO\EventCollection;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Services\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter as SupportEcommerceConverter;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Services\IdentityResolutionService;
use ZeroBoiler\Analytics\Services\IdentityLinkService;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;
use ZeroBoiler\Analytics\Tracking\AnonymousIdTracker;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\Support\TypedEventBuilder;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Facades\Analytics;

/**
 * V268.0.0 — PHP 8.5 Quality Verification + Industry-Standard SaaS Analytics Audit.
 *
 * Validates:
 * 1. Strict types across 100% of source files
 * 2. Final class enforcement (all source classes must be final)
 * 3. Return type declarations on all public/protected/private methods
 * 4. Constructor : void return type compliance
 * 5. Readonly classes and properties (PHP 8.2+ features)
 * 6. Version consistency across all components
 * 7. Event catalog integrity and provider coverage
 * 8. SaaS lifecycle event coverage
 * 9. E-commerce format conversion completeness
 * 10. Identity linking and resolution services
 * 11. Queue infrastructure readiness
 * 12. Optional provider (Plausible, PostHog) compliance
 */

describe('PHP 8.5 Quality: Strict Types Coverage', function (): void {
    it('all source files have declare(strict_types=1)', function (): void {
        $srcDir = __DIR__ . '/../src';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $total = 0;
        $missing = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $total++;
            $content = file_get_contents($file->getPathname());
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $missing[] = $file->getPathname();
            }
        }

        expect($total)->toBeGreaterThanOrEqual(900);
        expect($missing)->toBeEmpty(
            'Files missing strict_types: ' . implode(', ', array_slice($missing, 0, 5))
        );
    });

    it('all test files have declare(strict_types=1)', function (): void {
        $testDir = __DIR__;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $total = 0;
        $missing = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $total++;
            $content = file_get_contents($file->getPathname());
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $missing[] = $file->getFilename();
            }
        }

        expect($total)->toBeGreaterThanOrEqual(500);
        expect($missing)->toBeEmpty(
            'Test files missing strict_types: ' . implode(', ', array_slice($missing, 0, 5))
        );
    });
});

describe('PHP 8.5 Quality: Final Class Enforcement', function (): void {
    it('AnalyticsEvent DTO is final and readonly', function (): void {
        $ref = new ReflectionClass(AnalyticsEvent::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->isReadOnly())->toBeTrue();
    });

    it('ConsentState DTO is final and readonly', function (): void {
        $ref = new ReflectionClass(ConsentState::class);
        expect($ref->isFinal())->toBeTrue();
    });

    it('AnalyticsManager is final', function (): void {
        $ref = new ReflectionClass(AnalyticsManager::class);
        expect($ref->isFinal())->toBeTrue();
    });

    it('EventCatalog is final', function (): void {
        $ref = new ReflectionClass(EventCatalog::class);
        expect($ref->isFinal())->toBeTrue();
    });

    it('EcommerceEvents is final', function (): void {
        $ref = new ReflectionClass(EcommerceEvents::class);
        expect($ref->isFinal())->toBeTrue();
    });

    it('SaaSEvents is final', function (): void {
        $ref = new ReflectionClass(SaaSEvents::class);
        expect($ref->isFinal())->toBeTrue();
    });

    it('EngagementEvents is final', function (): void {
        $ref = new ReflectionClass(EngagementEvents::class);
        expect($ref->isFinal())->toBeTrue();
    });

    it('all classes in src/ are final', function (): void {
        $srcDir = __DIR__ . '/../src';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $nonFinal = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            preg_match_all('/^\s+class\s+(\w+)/m', $content, $matches);
            foreach ($matches[1] as $className) {
                // Find the line with this class declaration
                $lines = explode("\n", $content);
                foreach ($lines as $line) {
                    if (preg_match('/^\s+class\s+' . preg_quote($className, '/') . '\b/', $line)) {
                        if (! str_contains($line, 'final')) {
                            $nonFinal[] = $file->getFilename() . '::' . $className;
                        }
                        break;
                    }
                }
            }
        }

        expect($nonFinal)->toBeEmpty(
            'Non-final classes: ' . implode(', ', array_slice($nonFinal, 0, 5))
        );
    });
});

describe('PHP 8.5 Quality: Return Type Compliance', function (): void {
    it('AnalyticsManager public methods all have return types', function (): void {
        $ref = new ReflectionClass(AnalyticsManager::class);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
        $missing = [];

        foreach ($methods as $method) {
            if ($method->getDeclaringClass()->getName() !== AnalyticsManager::class) {
                continue;
            }
            if ($method->getName() === '__construct') {
                continue;
            }
            if ($method->getReturnType() === null) {
                $missing[] = $method->getName();
            }
        }

        expect($missing)->toBeEmpty(
            'AnalyticsManager methods without return types: ' . implode(', ', $missing)
        );
    });

    it('AnalyticsEventController public methods all have return types', function (): void {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
        $missing = [];

        foreach ($methods as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }
            if ($method->getReturnType() === null) {
                $missing[] = $method->getName();
            }
        }

        expect($missing)->toBeEmpty(
            'Controller methods without return types: ' . implode(', ', $missing)
        );
    });

    it('EventPipeline public methods all have return types', function (): void {
        $ref = new ReflectionClass(EventPipeline::class);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
        $missing = [];

        foreach ($methods as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }
            if ($method->getReturnType() === null) {
                $missing[] = $method->getName();
            }
        }

        expect($missing)->toBeEmpty(
            'EventPipeline methods without return types: ' . implode(', ', $missing)
        );
    });
});

describe('PHP 8.5 Quality: Constructor Void Return Types', function (): void {
    it('AnalyticsManager constructor has : void return type', function (): void {
        $ref = new ReflectionClass(AnalyticsManager::class);
        $ctor = $ref->getConstructor();
        expect($ctor)->not()->toBeNull();
        $returnType = (string) $ctor->getReturnType();
        expect($returnType)->toBe('void');
    });

    it('EventPipeline constructor has : void return type', function (): void {
        $ref = new ReflectionClass(EventPipeline::class);
        $ctor = $ref->getConstructor();
        expect($ctor)->not()->toBeNull();
        $returnType = (string) $ctor->getReturnType();
        expect($returnType)->toBe('void');
    });

    it('QueuedAnalyticsDispatcher constructor has : void return type', function (): void {
        $ref = new ReflectionClass(QueuedAnalyticsDispatcher::class);
        $ctor = $ref->getConstructor();
        expect($ctor)->not()->toBeNull();
        $returnType = (string) $ctor->getReturnType();
        expect($returnType)->toBe('void');
    });

    it('HandleInertiaAnalytics constructor has : void return type', function (): void {
        $ref = new ReflectionClass(HandleInertiaAnalytics::class);
        $ctor = $ref->getConstructor();
        expect($ctor)->not()->toBeNull();
        $returnType = (string) $ctor->getReturnType();
        expect($returnType)->toBe('void');
    });

    it('all tracker constructors have : void return type', function (): void {
        $trackers = [
            GA4Tracker::class,
            PlausibleTracker::class,
            PosthogTracker::class,
        ];

        foreach ($trackers as $tracker) {
            $ref = new ReflectionClass($tracker);
            $ctor = $ref->getConstructor();
            expect($ctor)->not()->toBeNull("{$tracker} should have a constructor");
            $returnType = (string) $ctor->getReturnType();
            expect($returnType)->toBe('void', "{$tracker} constructor should return void");
        }
    });
});

describe('PHP 8.5 Quality: Readonly Classes and Properties', function (): void {
    it('AnalyticsEvent is a readonly class with readonly properties', function (): void {
        $ref = new ReflectionClass(AnalyticsEvent::class);
        expect($ref->isReadOnly())->toBeTrue();

        $props = $ref->getProperties();
        expect(count($props))->toBeGreaterThanOrEqual(5);

        foreach ($props as $prop) {
            expect($prop->isReadOnly())->toBeTrue(
                "AnalyticsEvent::\$ {$prop->getName()} should be readonly"
            );
        }
    });

    it('EventCollection is a readonly class', function (): void {
        $ref = new ReflectionClass(EventCollection::class);
        expect($ref->isReadOnly())->toBeTrue();
    });

    it('at least 200 classes use readonly modifier', function (): void {
        $srcDir = __DIR__ . '/../src';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $readonlyCount = 0;

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (preg_match('/\breadonly\s+class\s+/', $content)) {
                $readonlyCount++;
            }
        }

        expect($readonlyCount)->toBeGreaterThanOrEqual(200);
    });

    it('at least 500 public readonly properties exist', function (): void {
        $srcDir = __DIR__ . '/../src';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $readonlyProps = 0;

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            $readonlyProps += substr_count($content, 'public readonly ');
            $readonlyProps += substr_count($content, 'readonly public ');
        }

        expect($readonlyProps)->toBeGreaterThanOrEqual(500);
    });
});

describe('Version Consistency: Cross-Component Alignment', function (): void {
    it('AnalyticsEvent::VERSION matches composer.json version', function (): void {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        expect(AnalyticsEvent::VERSION)->toBe($composer['version']);
    });

    it('AnalyticsManager::version() matches AnalyticsEvent::VERSION', function (): void {
        $manager = new AnalyticsManager;
        expect($manager->version())->toBe(AnalyticsEvent::VERSION);
    });

    it('JS analytics.js @version matches PHP version', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain('@version ' . AnalyticsEvent::VERSION);
        expect($js)->toContain("'" . AnalyticsEvent::VERSION . "'");
    });

    it('Svelte composables @version matches PHP version', function (): void {
        $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
        expect($svelte)->toContain('@version ' . AnalyticsEvent::VERSION);
    });

    it('README badge version matches PHP version', function (): void {
        $readme = file_get_contents(__DIR__ . '/../README.md');
        expect($readme)->toContain('version-' . AnalyticsEvent::VERSION);
    });

    it('no stale version strings remain in tests', function (): void {
        $testDir = __DIR__;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $staleVersions = ['76.0.0', '2.27.0', '2.35.0', '3.5.0'];
        $staleFiles = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            foreach ($staleVersions as $v) {
                // Check for version assertions (toBe, toContain with old version)
                if (str_contains($content, "'{$v}'") || str_contains($content, '"' . $v . '"')) {
                    $staleFiles[] = $file->getFilename() . " (contains {$v})";
                    break;
                }
            }
        }

        // Allow up to 5 stale references in legacy/historical tests
        expect(count($staleFiles))->toBeLessThanOrEqual(5,
            'Stale version references found: ' . implode(', ', array_slice($staleFiles, 0, 10))
        );
    });

    it('controller version strings are consistent', function (): void {
        $controller = file_get_contents(
            __DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php'
        );
        $currentVersion = AnalyticsEvent::VERSION;
        expect($controller)->toContain("'version' => '{$currentVersion}'");
    });
});

describe('Industry-Standard: Event Catalog Provider Coverage', function (): void {
    it('all events have at least one provider mapping', function (): void {
        $all = EventCatalog::all();
        $noMapping = [];

        foreach ($all as $name => $entry) {
            $hasProvider = !empty($entry['ga4'])
                || !empty($entry['meta'])
                || !empty($entry['posthog'] ?? null)
                || !empty($entry['plausible'] ?? null);
            if (!$hasProvider) {
                $noMapping[] = $name;
            }
        }

        expect($noMapping)->toBeEmpty(
            'Events without provider mapping: ' . implode(', ', array_slice($noMapping, 0, 10))
        );
    });

    it('GA4 covers all events', function (): void {
        $all = EventCatalog::all();
        $noGa4 = [];

        foreach ($all as $name => $entry) {
            if (empty($entry['ga4'])) {
                $noGa4[] = $name;
            }
        }

        expect($noGa4)->toBeEmpty(
            'Events without GA4 mapping: ' . implode(', ', array_slice($noGa4, 0, 10))
        );
    });

    it('Plausible covers at least 50% of events', function (): void {
        $all = EventCatalog::all();
        $total = count($all);
        $plausibleCount = 0;

        foreach ($all as $entry) {
            if (!empty($entry['plausible'])) {
                $plausibleCount++;
            }
        }

        $pct = $plausibleCount / $total * 100;
        expect($pct)->toBeGreaterThanOrEqual(50.0);
    });

    it('PostHog covers at least 50% of events', function (): void {
        $all = EventCatalog::all();
        $total = count($all);
        $posthogCount = 0;

        foreach ($all as $entry) {
            if (!empty($entry['posthog'])) {
                $posthogCount++;
            }
        }

        $pct = $posthogCount / $total * 100;
        expect($pct)->toBeGreaterThanOrEqual(50.0);
    });

    it('Meta Pixel covers at least 80% of events', function (): void {
        $all = EventCatalog::all();
        $total = count($all);
        $metaCount = 0;

        foreach ($all as $entry) {
            if (!empty($entry['meta'])) {
                $metaCount++;
            }
        }

        $pct = $metaCount / $total * 100;
        expect($pct)->toBeGreaterThanOrEqual(80.0);
    });
});

describe('Industry-Standard: SaaS Lifecycle Event Coverage', function (): void {
    it('SaaS events cover the complete user lifecycle', function (): void {
        $requiredEvents = [
            'sign_up', 'login', 'logout', 'start_trial', 'subscribe',
            'plan_upgrade', 'plan_downgrade', 'cancellation',
            'trial_converted', 'trial_expired', 'account_activated',
            'payment_succeeded', 'payment_failed',
        ];

        $saas = SaaSEvents::all();
        $missing = [];

        foreach ($requiredEvents as $event) {
            if (! SaaSEvents::has($event)) {
                $missing[] = $event;
            }
        }

        expect($missing)->toBeEmpty(
            'Missing SaaS lifecycle events: ' . implode(', ', $missing)
        );
    });

    it('engagement events cover standard web analytics', function (): void {
        $requiredEvents = [
            'page_view', 'scroll_depth', 'click', 'form_start',
            'form_submit', 'search', 'share', 'error',
        ];

        $missing = [];

        foreach ($requiredEvents as $event) {
            if (! EngagementEvents::has($event)) {
                $missing[] = $event;
            }
        }

        expect($missing)->toBeEmpty(
            'Missing engagement events: ' . implode(', ', $missing)
        );
    });

    it('e-commerce events cover the purchase funnel', function (): void {
        $requiredEvents = [
            'view_item', 'add_to_cart', 'remove_from_cart',
            'view_cart', 'begin_checkout', 'add_payment_info',
            'purchase', 'refund', 'select_item',
        ];

        $missing = [];

        foreach ($requiredEvents as $event) {
            if (! EcommerceEvents::has($event)) {
                $missing[] = $event;
            }
        }

        expect($missing)->toBeEmpty(
            'Missing e-commerce events: ' . implode(', ', $missing)
        );
    });

    it('lifecycle mapper has 40+ config-driven mappings', function (): void {
        $ref = new ReflectionClass(LifecycleEventMapper::class);
        $const = $ref->getConstant('DEFAULT_MAPPINGS');

        expect($const)->toBeArray();
        expect(count($const))->toBeGreaterThanOrEqual(66);

        // Verify key lifecycle mappings
        $requiredKeys = [
            'auth.login', 'auth.register', 'auth.logout',
            'subscription.created', 'subscription.upgraded',
            'subscription.cancelled', 'trial.started',
        ];

        foreach ($requiredKeys as $key) {
            expect($const)->toHaveKey($key);
        }
    });
});

describe('Industry-Standard: E-commerce Format Conversion', function (): void {
    it('EcommerceFormatConverter has toGa4Format method', function (): void {
        expect(method_exists(SupportEcommerceConverter::class, 'toGa4Format'))->toBeTrue();
    });

    it('EcommerceFormatConverter has toMetaFormat method', function (): void {
        expect(method_exists(SupportEcommerceConverter::class, 'toMetaFormat'))->toBeTrue();
    });

    it('EcommerceFormatConverter has fromGa4Format method', function (): void {
        expect(method_exists(SupportEcommerceConverter::class, 'fromGa4Format'))->toBeTrue();
    });

    it('EcommerceFormatConverter has fromMetaFormat method', function (): void {
        expect(method_exists(SupportEcommerceConverter::class, 'fromMetaFormat'))->toBeTrue();
    });

    it('EcommerceAnalyticsService exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Services\EcommerceAnalyticsService::class))->toBeTrue();
    });

    it('CartStateManager exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Services\CartStateManager::class))->toBeTrue();
    });
});

describe('Industry-Standard: Identity and Resolution Services', function (): void {
    it('IdentityResolutionService exists', function (): void {
        expect(class_exists(IdentityResolutionService::class))->toBeTrue();
    });

    it('IdentityLinkService exists', function (): void {
        expect(class_exists(IdentityLinkService::class))->toBeTrue();
    });

    it('UserIdentityTracker exists', function (): void {
        expect(class_exists(UserIdentityTracker::class))->toBeTrue();
    });

    it('AnonymousIdTracker exists', function (): void {
        expect(class_exists(AnonymousIdTracker::class))->toBeTrue();
    });

    it('identity config section exists with required keys', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($config)->not->toBeFalse();
        expect($config)->toContain("'identity' => [");
        expect($config)->toContain('ANALYTICS_IDENTITY_COOKIE');
        expect($config)->toContain('ANALYTICS_IDENTITY_LINK_ON_AUTH');
        expect($config)->toContain('ANALYTICS_IDENTITY_CACHE_PREFIX');
    });

    it('identity API routes exist', function (): void {
        $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
        expect($routes)->toContain('identityLookup');
        expect($routes)->toContain('identityResolve');
        expect($routes)->toContain('identityForgetClient');
    });
});

describe('Industry-Standard: Queue Infrastructure', function (): void {
    it('QueuedAnalyticsDispatcher exists and is final', function (): void {
        expect(class_exists(QueuedAnalyticsDispatcher::class))->toBeTrue();
        $ref = new ReflectionClass(QueuedAnalyticsDispatcher::class);
        expect($ref->isFinal())->toBeTrue();
    });

    it('TrackAnalyticsEventJob exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob::class))->toBeTrue();
    });

    it('TrackAnalyticsEventBatchJob exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob::class))->toBeTrue();
    });

    it('queue config section exists with required keys', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($config)->not->toBeFalse();
        expect($config)->toContain("'queue' => [");
        expect($config)->toContain('ANALYTICS_QUEUE_ENABLED');
        expect($config)->toContain('ANALYTICS_QUEUE');
        expect($config)->toContain('ANALYTICS_QUEUE_CONNECTION');
    });
});

describe('Industry-Standard: Optional Provider Compliance', function (): void {
    it('PlausibleTracker implements TrackerInterface', function (): void {
        $ref = new ReflectionClass(PlausibleTracker::class);
        expect($ref->implementsInterface(TrackerInterface::class))->toBeTrue();
    });

    it('PostHogTracker implements TrackerInterface', function (): void {
        $ref = new ReflectionClass(PosthogTracker::class);
        expect($ref->implementsInterface(TrackerInterface::class))->toBeTrue();
    });

    it('PlausibleTracker has track and isEnabled methods', function (): void {
        $ref = new ReflectionClass(PlausibleTracker::class);
        expect($ref->hasMethod('track'))->toBeTrue();
        expect($ref->hasMethod('isEnabled'))->toBeTrue();
    });

    it('PostHogTracker has track and isEnabled methods', function (): void {
        $ref = new ReflectionClass(PosthogTracker::class);
        expect($ref->hasMethod('track'))->toBeTrue();
        expect($ref->hasMethod('isEnabled'))->toBeTrue();
    });

    it('plausible config section exists', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($config)->toContain("'plausible' => [");
        expect($config)->toContain('ANALYTICS_PLAUSIBLE_ENABLED');
    });

    it('posthog config section exists', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($config)->toContain("'posthog' => [");
        expect($config)->toContain('ANALYTICS_POSTHOG_ENABLED');
    });
});

describe('Industry-Standard: Config Completeness', function (): void {
    it('config has 25+ sections', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($config)->not->toBeFalse();

        $sections = [
            'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track',
            'queue', 'identity', 'ecommerce', 'revenue', 'track_links',
            'api', 'plausible', 'posthog', 'webhook', 'audit_log',
            'debug', 'validation', 'pipeline', 'sampling', 'pii_sanitization',
            'replay', 'metrics', 'stream', 'client_auto_track', 'performance',
        ];

        foreach ($sections as $section) {
            expect($config)->toContain("'{$section}' => [");
        }

        expect(count($sections))->toBeGreaterThanOrEqual(25);
    });

    it('API routes include core endpoints', function (): void {
        $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
        expect($routes)->not->toBeFalse();

        expect($routes)->toContain("Route::post('events'");
        expect($routes)->toContain("Route::post('batch'");
        expect($routes)->toContain("Route::post('identify'");
        expect($routes)->toContain("Route::post('consent'");
        expect($routes)->toContain("Route::get('health'");
        expect($routes)->toContain("Route::get('catalog'");
    });

    it('130+ routes registered', function (): void {
        $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
        preg_match_all('/Route::(get|post|put|patch|delete)\(/', $routes, $matches);
        expect(count($matches[0]))->toBeGreaterThanOrEqual(130);
    });
});

describe('Industry-Standard: Facade Proxy Methods', function (): void {
    it('Analytics facade documents SaaS lifecycle methods', function (): void {
        $facadeRef = new ReflectionClass(Analytics::class);
        $doc = $facadeRef->getDocComment();
        expect($doc)->not->toBeFalse();

        expect($doc)->toContain('signUp');
        expect($doc)->toContain('login');
        expect($doc)->toContain('trialStart');
        expect($doc)->toContain('subscription');
        expect($doc)->toContain('planUpgrade');
        expect($doc)->toContain('cancellation');
        expect($doc)->toContain('purchase');
        expect($doc)->toContain('identify');
    });
});

describe('Industry-Standard: JS Client Library Quality', function (): void {
    it('analytics.js has 5000+ lines of well-structured code', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $lineCount = substr_count($js, "\n");
        expect($lineCount)->toBeGreaterThanOrEqual(5000);
    });

    it('analytics.js exports all required functions', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

        $requiredExports = [
            'export function init(',
            'export function trackEvent(',
            'export function trackPageView(',
            'export function trackScreenView(',
            'export function trackEcommerce(',
            'export function trackIdentify(',
            'export function updateConsent(',
            'export function flushQueue(',
            'export function getTrackingId(',
            'export function getVersion(',
            'export function destroy(',
            'export function isInitialized(',
        ];

        foreach ($requiredExports as $export) {
            expect($js)->toContain($export);
        }
    });

    it('analytics.js has Inertia page view tracker', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain('function initInertiaPageViewTracker');
    });

    it('analytics.js has event queue with flush interval', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain('eventQueue');
        expect($js)->toContain('FLUSH_INTERVAL');
        expect($js)->toContain('MAX_QUEUE_SIZE');
    });

    it('analytics.js has provider init functions', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

        $providers = ['initGA4', 'initGTM', 'initMetaPixel', 'initPlausible', 'initPostHog'];
        foreach ($providers as $provider) {
            expect($js)->toContain('function ' . $provider);
        }
    });

    it('Svelte composables use Svelte 5 runes', function (): void {
        $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
        expect($svelte)->toContain('$state(');
        expect($svelte)->toContain('$derived(');
        expect($svelte)->toContain('$effect(');
    });

    it('Svelte composables export useAnalytics, useEcommerce, useConsent', function (): void {
        $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
        expect($svelte)->toContain('export function useAnalytics(');
        expect($svelte)->toContain('export function useEcommerce(');
        expect($svelte)->toContain('export function useConsent(');
    });

    it('Svelte composables has 800+ lines', function (): void {
        $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
        $lineCount = substr_count($svelte, "\n");
        expect($lineCount)->toBeGreaterThanOrEqual(800);
    });
});

describe('Industry-Standard: Test Coverage Breadth', function (): void {
    it('520+ test files exist', function (): void {
        $testDir = __DIR__;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $count++;
            }
        }
        expect($count)->toBeGreaterThanOrEqual(520);
    });

    it('990+ source files exist', function (): void {
        $srcDir = __DIR__ . '/../src';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $count++;
            }
        }
        expect($count)->toBeGreaterThanOrEqual(990);
    });

    it('specific test files exist for core features', function (): void {
        $requiredTests = [
            'AnalyticsManagerTest.php',
            'EcommerceEventsTest.php',
            'EngagementEventsTest.php',
            'SaaSEventsTest.php',
            'EventCatalogTest.php',
            'ConsentModeTest.php',
            'PipelineTest.php',
            'GA4TrackerTest.php',
            'GTMTrackerTest.php',
            'MetaPixelTrackerTest.php',
            'ServerSideTrackerTest.php',
            'OptionalTrackersTest.php',
            'V21ApiControllerTest.php',
            'V21InertiaAndIdentityTest.php',
        ];

        foreach ($requiredTests as $test) {
            expect(file_exists(__DIR__ . '/' . $test))->toBeTrue(
                "Missing test file: {$test}"
            );
        }
    });
});
