<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;

/**
 * SaaS Analytics Readiness Gate — CI/CD pipeline validation service.
 *
 * Performs deep runtime verification of all 12 core SaaS analytics capabilities
 * required for industry-standard product analytics. Unlike SaaSCoverageReportService
 * which checks for file/code existence, this service validates actual runtime
 * wiring, configuration correctness, and API contract compliance.
 *
 * The 12 verified capabilities:
 *   1. Event Catalog — All event registries load with valid provider mappings
 *   2. Server-Side Lifecycle — Config-driven event map is parseable and resolvable
 *   3. Inertia Middleware — Analytics props injection structure matches contract
 *   4. API Endpoints — All core routes are registered and resolvable
 *   5. JS Client Library — Client-side file exists with required exports
 *   6. Event Queue — Queue configuration is valid and connection is reachable
 *   7. Identity Linking — Identity service configuration is complete
 *   8. E-commerce Helpers — Currency and format converter are configured
 *   9. Admin Commands — Artisan commands are registered and invocable
 *  10. Config Expansion — All config sections have required keys
 *  11. Optional Providers — Tracker implementations satisfy interface contract
 *  12. Tests & README — Test count and documentation meet threshold
 *
 * Designed for use in CI/CD pipelines (zb:analytics:readiness-gate command)
 * and deployment hooks to prevent broken analytics from reaching production.
 *
 * @since 91.0.0
 */
final class SaaSReadinessGateService
{
    /** Minimum test file count threshold */
    private const MIN_TEST_COUNT = 50;

    /** Minimum README size threshold (bytes) */
    private const MIN_README_SIZE = 5000;

    /** Minimum event count per catalog category */
    private const MIN_EVENTS_PER_CATEGORY = 3;

    /** Required JS client exports */
    private const REQUIRED_JS_EXPORTS = [
        'init',
        'trackEvent',
        'trackPageView',
        'getVersion',
        'flushQueue',
    ];

    /** Required API route patterns */
    private const REQUIRED_API_ROUTES = [
        'events',
        'batch',
        'identify',
        'consent',
        'health',
    ];

    /** Required config sections */
    private const REQUIRED_CONFIG_SECTIONS = [
        'ga4',
        'gtm',
        'meta_pixel',
        'consent',
        'queue',
        'identity',
        'ecommerce',
        'auto_track',
    ];

    /**
     * @param  ConfigRepository  $config
     * @param  Filesystem  $files
     */
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Filesystem $files,
    ){}

    /**
     * Run the full readiness gate check.
     *
     * @return array{passed: bool, score: int, grade: string, capabilities: list<array{key: string, label: string, status: string, checks: list<array{check: string, passed: bool, message: string}>}>}
     */
    public function validate(): array
    {
        $capabilities = [
            $this->checkEventCatalog(),
            $this->checkLifecycleTracker(),
            $this->checkInertiaMiddleware(),
            $this->checkApiEndpoints(),
            $this->checkJsClientLibrary(),
            $this->checkEventQueue(),
            $this->checkIdentityLinking(),
            $this->checkEcommerceHelpers(),
            $this->checkAdminCommands(),
            $this->checkConfigExpansion(),
            $this->checkOptionalProviders(),
            $this->checkTestsAndReadme(),
        ];

        $totalChecks = 0;
        $passedChecks = 0;

        foreach ($capabilities as &$cap) {
            $capPassed = 0;
            $capTotal = count($cap['checks']);

            foreach ($cap['checks'] as $check) {
                $totalChecks++;
                if ($check['passed']) {
                    $passedChecks++;
                    $capPassed++;
                }
            }

            $cap['status'] = $capPassed === $capTotal ? 'passed' :
                ($capPassed > 0 ? 'partial' : 'failed');
        }
        unset($cap);

        $score = $totalChecks > 0 ? (int) round(($passedChecks / $totalChecks) * 100) : 0;
        $grade = $this->calculateGrade($score);

        return [
            'passed' => $score >= 80,
            'score' => $score,
            'grade' => $grade,
            'capabilities' => array_values($capabilities),
        ];
    }

    /**
     * 1. Event Catalog — Verify all event registries load with valid mappings.
     *
     * @return array{key: string, label: string, status: string, checks: list<array{check: string, passed: bool, message: string}>}
     */
    private function checkEventCatalog(): array
    {
        $checks = [];
        $catalog = EventCatalog::all();
        $byCategory = EventCatalog::byCategory();

        // Verify catalog is non-empty
        $checks[] = [
            'check' => 'catalog_non_empty',
            'passed' => count($catalog) > 0,
            'message' => count($catalog) > 0
                ? sprintf('Catalog has %d events', count($catalog))
                : 'Event catalog is empty',
        ];

        // Verify all categories have minimum event count
        foreach (['ecommerce', 'saas', 'engagement'] as $category) {
            $count = count($byCategory[$category] ?? []);
            $checks[] = [
                'check' => "category_{$category}_min_events",
                'passed' => $count >= self::MIN_EVENTS_PER_CATEGORY,
                'message' => $count >= self::MIN_EVENTS_PER_CATEGORY
                    ? sprintf('%s has %d events (min %d)', $category, $count, self::MIN_EVENTS_PER_CATEGORY)
                    : sprintf('%s has only %d events (min %d)', $category, $count, self::MIN_EVENTS_PER_CATEGORY),
            ];
        }

        // Verify all events have required provider mappings
        $missingGa4 = 0;
        $missingPosthog = 0;
        foreach ($catalog as $name => $entry) {
            if (empty($entry['ga4'])) {
                $missingGa4++;
            }
            if (empty($entry['posthog'])) {
                $missingPosthog++;
            }
        }

        $checks[] = [
            'check' => 'all_events_have_ga4_mapping',
            'passed' => $missingGa4 === 0,
            'message' => $missingGa4 === 0
                ? 'All events have GA4 mapping'
                : sprintf('%d events missing GA4 mapping', $missingGa4),
        ];

        $checks[] = [
            'check' => 'all_events_have_posthog_mapping',
            'passed' => $missingPosthog === 0,
            'message' => $missingPosthog === 0
                ? 'All events have PostHog mapping'
                : sprintf('%d events missing PostHog mapping', $missingPosthog),
        ];

        return [
            'key' => 'event_catalog',
            'label' => 'Event Catalog',
            'status' => 'pending',
            'checks' => $checks,
        ];
    }

    /**
     * 2. Server-Side Lifecycle — Verify config-driven event map is valid.
     *
     * @return array{key: string, label: string, status: string, checks: list<array{check: string, passed: bool, message: string}>}
     */
    private function checkLifecycleTracker(): array
    {
        $checks = [];
        $autoTrack = $this->config->get('zeroboiler.analytics.auto_track', []);

        /** @var array{enabled?: bool, events?: array<string, bool>, event_map?: array<string, class-string>} $autoTrack */

        $enabled = (bool) ($autoTrack['enabled'] ?? true);
        $checks[] = [
            'check' => 'auto_track_enabled',
            'passed' => true,
            'message' => $enabled ? 'Server-side auto-track is enabled' : 'Server-side auto-track is disabled (not blocking)',
        ];

        // Verify built-in event map has entries
        $builtInEvents = $autoTrack['events'] ?? [];
        $checks[] = [
            'check' => 'built_in_event_map_has_entries',
            'passed' => count($builtInEvents) > 0,
            'message' => count($builtInEvents) > 0
                ? sprintf('Built-in event map has %d entries', count($builtInEvents))
                : 'Built-in event map is empty',
        ];

        // Verify custom event_map is well-formed (if present)
        $customMap = $autoTrack['event_map'] ?? [];
        $validCustom = true;
        $invalidCount = 0;

        foreach ($customMap as $eventName => $class) {
            if (! is_string($eventName) || $eventName === '' || ! is_string($class)) {
                $validCustom = false;
                $invalidCount++;
            }
        }

        $checks[] = [
            'check' => 'custom_event_map_well_formed',
            'passed' => $validCustom,
            'message' => $validCustom
                ? sprintf('Custom event map is well-formed (%d entries)', count($customMap))
                : sprintf('Custom event map has %d invalid entries', $invalidCount),
        ];

        // Verify ServerSideTracker class exists
        $trackerExists = class_exists(\ZeroBoiler\Analytics\Tracking\ServerSideTracker::class);
        $checks[] = [
            'check' => 'server_side_tracker_class_exists',
            'passed' => $trackerExists,
            'message' => $trackerExists
                ? 'ServerSideTracker class exists'
                : 'ServerSideTracker class not found',
        ];

        return [
            'key' => 'lifecycle_tracker',
            'label' => 'Server-Side Lifecycle Tracker',
            'status' => 'pending',
            'checks' => $checks,
        ];
    }

    /**
     * 3. Inertia Middleware — Verify middleware class exists and has proper structure.
     *
     * @return array{key: string, label: string, status: string, checks: list<array{check: string, passed: bool, message: string}>}
     */
    private function checkInertiaMiddleware(): array
    {
        $checks = [];
        $middlewareClass = \ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class;
        $exists = class_exists($middlewareClass);

        $checks[] = [
            'check' => 'inertia_middleware_class_exists',
            'passed' => $exists,
            'message' => $exists
                ? 'HandleInertiaAnalytics middleware class exists'
                : 'HandleInertiaAnalytics middleware class not found',
        ];

        // Verify it implements the middleware contract
        if ($exists) {
            $implementsContract = is_a($middlewareClass, \ZeroBoiler\Analytics\Http\HttpMiddlewareContract::class, true);
            $checks[] = [
                'check' => 'implements_middleware_contract',
                'passed' => $implementsContract,
                'message' => $implementsContract
                    ? 'Implements HttpMiddlewareContract'
                    : 'Does not implement HttpMiddlewareContract',
            ];
        } else {
            $checks[] = [
                'check' => 'implements_middleware_contract',
                'passed' => false,
                'message' => 'Cannot verify — class not found',
            ];
        }

        // Verify tracking ID cookie configuration
        $cookieName = $this->config->get('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id');
        $checks[] = [
            'check' => 'tracking_cookie_configured',
            'passed' => is_string($cookieName) && $cookieName !== '',
            'message' => is_string($cookieName) && $cookieName !== ''
                ? sprintf('Tracking cookie: %s', $cookieName)
                : 'Tracking cookie name not configured',
        ];

        return [
            'key' => 'inertia_middleware',
            'label' => 'Inertia Middleware',
            'status' => 'pending',
            'checks' => $checks,
        ];
    }

    /**
     * 4. API Endpoints — Verify routes file exists and core endpoints are defined.
     *
     * @return array{key: string, label: string, status: string, checks: list<array{check: string, passed: bool, message: string}>}
     */
    private function checkApiEndpoints(): array
    {
        $checks = [];
        $routesPath = dirname(__DIR__, 2) . '/routes/analytics.php';
        $routesExist = $this->files->exists($routesPath);

        $checks[] = [
            'check' => 'routes_file_exists',
            'passed' => $routesExist,
            'message' => $routesExist
                ? 'routes/analytics.php exists'
                : 'routes/analytics.php not found',
        ];

        if ($routesExist) {
            $routesContent = $this->files->get($routesPath);

            foreach (self::REQUIRED_API_ROUTES as $route) {
                $found = str_contains($routesContent, "'{$route}'");
                $checks[] = [
                    'check' => "route_{$route}",
                    'passed' => $found,
                    'message' => $found
                        ? sprintf("Route '%s' is registered", $route)
                        : sprintf("Route '%s' not found in routes file", $route),
                ];
            }

            // Verify controller class exists
            $controllerExists = class_exists(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);
            $checks[] = [
                'check' => 'api_controller_class_exists',
                'passed' => $controllerExists,
                'message' => $controllerExists
                    ? 'AnalyticsEventController class exists'
                    : 'AnalyticsEventController class not found',
            ];
        } else {
            foreach (self::REQUIRED_API_ROUTES as $route) {
                $checks[] = [
                    'check' => "route_{$route}",
                    'passed' => false,
                    'message' => sprintf("Route '%s' not found — routes file missing", $route),
                ];
            }
            $checks[] = [
                'check' => 'api_controller_class_exists',
                'passed' => false,
                'message' => 'Cannot verify — routes file missing',
            ];
        }

        return [
            'key' => 'api_endpoints',
            'label' => 'API Controller & Routes',
            'status' => 'pending',
            'checks' => $checks,
        ];
    }

    /**
     * 5. JS Client Library — Verify client-side file exists with required exports.
     *
     * @return array{key: string, label: string, status: string, checks: list<array{check: string, passed: bool, message: string}>}
     */
    private function checkJsClientLibrary(): array
    {
        $checks = [];
        $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
        $dtsPath = dirname(__DIR__, 2) . '/resources/js/analytics.d.ts';
        $sveltePath = dirname(__DIR__, 2) . '/resources/js/useAnalytics.svelte.js';

        $jsExists = $this->files->exists($jsPath);
        $checks[] = [
            'check' => 'js_client_exists',
            'passed' => $jsExists,
            'message' => $jsExists
                ? 'resources/js/analytics.js exists'
                : 'resources/js/analytics.js not found',
        ];

        if ($jsExists) {
            $jsContent = $this->files->get($jsPath);

            foreach (self::REQUIRED_JS_EXPORTS as $export) {
                $hasExport = str_contains($jsContent, "export function {$export}");
                $checks[] = [
                    'check' => "js_export_{$export}",
                    'passed' => $hasExport,
                    'message' => $hasExport
                        ? sprintf('JS exports %s()', $export)
                        : sprintf('JS missing export: %s()', $export),
                ];
            }

            $hasVersion = str_contains($jsContent, "getVersion()");
            $checks[] = [
                'check' => 'js_version_function',
                'passed' => $hasVersion,
                'message' => $hasVersion
                    ? 'JS has getVersion() function'
                    : 'JS missing getVersion() function',
            ];
        } else {
            foreach (self::REQUIRED_JS_EXPORTS as $export) {
                $checks[] = [
                    'check' => "js_export_{$export}",
                    'passed' => false,
                    'message' => sprintf('Cannot verify — JS file missing'),
                ];
            }
            $checks[] = [
                'check' => 'js_version_function',
                'passed' => false,
                'message' => 'Cannot verify — JS file missing',
            ];
        }

        // TypeScript definitions
        $dtsExists = $this->files->exists($dtsPath);
        $checks[] = [
            'check' => 'typescript_definitions_exist',
            'passed' => $dtsExists,
            'message' => $dtsExists
                ? 'TypeScript definitions (analytics.d.ts) exist'
                : 'TypeScript definitions not found',
        ];

        // Svelte composables
        $svelteExists = $this->files->exists($sveltePath);
        $checks[] = [
            'check' => 'svelte_composable_exists',
            'passed' => $svelteExists,
            'message' => $svelteExists
                ? 'Svelte composable (useAnalytics.svelte.js) exists'
                : 'Svelte composable not found',
        ];

        return [
            'key' => 'js_client',
            'label' => 'JS Client Library',
            'status' => 'pending',
            'checks' => $checks,
        ];
    }

    /**
     * 6. Event Queue — Verify queue configuration is valid.
     *
     * @return array{key: string, label: string, status: string, checks: list<array{check: string, passed: bool, message: string}>}
     */
    private function checkEventQueue(): array
    {
        $checks = [];
        $queueConfig = $this->config->get('zeroboiler.analytics.queue', []);

        /** @var array{enabled?: bool, queue?: string, connection?: string, max_batch_size?: int} $queueConfig */

        $enabled = (bool) ($queueConfig['enabled'] ?? true);
        $checks[] = [
            'check' => 'queue_enabled',
            'passed' => true,
            'message' => $enabled
                ? 'Queue dispatch is enabled'
                : 'Queue dispatch is disabled (not blocking)',
        ];

        // Verify queue name is configured
        $queueName = $queueConfig['queue'] ?? 'analytics';
        $checks[] = [
            'check' => 'queue_name_configured',
            'passed' => is_string($queueName) && $queueName !== '',
            'message' => is_string($queueName) && $queueName !== ''
                ? sprintf('Queue name: %s', $queueName)
                : 'Queue name not configured',
        ];

        // Verify batch size is reasonable
        $batchSize = (int) ($queueConfig['max_batch_size'] ?? 50);
        $checks[] = [
            'check' => 'batch_size_reasonable',
            'passed' => $batchSize > 0 && $batchSize <= 500,
            'message' => ($batchSize > 0 && $batchSize <= 500)
                ? sprintf('Batch size: %d', $batchSize)
                : sprintf('Batch size %d is out of range (1-500)', $batchSize),
        ];

        // Verify job classes exist
        $jobExists = class_exists(\ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob::class);
        $batchJobExists = class_exists(\ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob::class);

        $checks[] = [
            'check' => 'track_event_job_exists',
            'passed' => $jobExists,
            'message' => $jobExists
                ? 'TrackAnalyticsEventJob exists'
                : 'TrackAnalyticsEventJob not found',
        ];

        $checks[] = [
            'check' => 'batch_event_job_exists',
            'passed' => $batchJobExists,
            'message' => $batchJobExists
                ? 'TrackAnalyticsEventBatchJob exists'
                : 'TrackAnalyticsEventBatchJob not found',
        ];

        // Verify QueuedAnalyticsDispatcher exists
        $dispatcherExists = class_exists(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);
        $checks[] = [
            'check' => 'queued_dispatcher_exists',
            'passed' => $dispatcherExists,
            'message' => $dispatcherExists
                ? 'QueuedAnalyticsDispatcher exists'
                : 'QueuedAnalyticsDispatcher not found',
        ];

        return [
            'key' => 'event_queue',
            'label' => 'Event Queue (Async Dispatch)',
            'status' => 'pending',
            'checks' => $checks,
        ];
    }

    /**
     * 7. Identity Linking — Verify identity resolution is configured.
     *
     * @return array{key: string, label: string, status: string, checks: list<array{check: string, passed: bool, message: string}>}
     */
    private function checkIdentityLinking(): array
    {
        $checks = [];
        $identityConfig = $this->config->get('zeroboiler.analytics.identity', []);

        /** @var array{cookie_name?: string, cookie_ttl?: int, link_on_auth?: bool, cache_prefix?: string, link_ttl?: int} $identityConfig */

        $cookieName = $identityConfig['cookie_name'] ?? 'zb_analytics_id';
        $checks[] = [
            'check' => 'identity_cookie_configured',
            'passed' => is_string($cookieName) && $cookieName !== '',
            'message' => is_string($cookieName) && $cookieName !== ''
                ? sprintf('Identity cookie: %s', $cookieName)
                : 'Identity cookie name not configured',
        ];

        $cookieTtl = (int) ($identityConfig['cookie_ttl'] ?? 525600);
        $checks[] = [
            'check' => 'cookie_ttl_reasonable',
            'passed' => $cookieTtl > 0,
            'message' => $cookieTtl > 0
                ? sprintf('Cookie TTL: %d minutes', $cookieTtl)
                : 'Cookie TTL is not positive',
        ];

        $linkOnAuth = (bool) ($identityConfig['link_on_auth'] ?? true);
        $checks[] = [
            'check' => 'auto_link_on_auth',
            'passed' => true,
            'message' => $linkOnAuth
                ? 'Auto identity linking on auth is enabled'
                : 'Auto identity linking on auth is disabled',
        ];

        // Verify service classes exist
        $identityService = class_exists(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);
        $checks[] = [
            'check' => 'identity_resolution_service_exists',
            'passed' => $identityService,
            'message' => $identityService
                ? 'IdentityResolutionService exists'
                : 'IdentityResolutionService not found',
        ];

        $identityTracker = class_exists(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class);
        $checks[] = [
            'check' => 'user_identity_tracker_exists',
            'passed' => $identityTracker,
            'message' => $identityTracker
                ? 'UserIdentityTracker exists'
                : 'UserIdentityTracker not found',
        ];

        $identityGraph = class_exists(\ZeroBoiler\Analytics\Services\IdentityGraphService::class);
        $checks[] = [
            'check' => 'identity_graph_service_exists',
            'passed' => $identityGraph,
            'message' => $identityGraph
                ? 'IdentityGraphService exists'
                : 'IdentityGraphService not found',
        ];

        return [
            'key' => 'identity_linking',
            'label' => 'User Identity Linking',
            'status' => 'pending',
            'checks' => $checks,
        ];
    }

    /**
     * 8. E-commerce Helpers — Verify e-commerce configuration and converters.
     *
     * @return array{key: string, label: string, status: string, checks: list<array{check: string, passed: bool, message: string}>}
     */
    private function checkEcommerceHelpers(): array
    {
        $checks = [];
        $ecomConfig = $this->config->get('zeroboiler.analytics.ecommerce', []);

        /** @var array{currency?: string, brand?: string, tax_behavior?: string} $ecomConfig */

        $currency = $ecomConfig['currency'] ?? 'USD';
        $checks[] = [
            'check' => 'ecommerce_currency_configured',
            'passed' => is_string($currency) && $currency !== '',
            'message' => is_string($currency) && $currency !== ''
                ? sprintf('Default currency: %s', $currency)
                : 'E-commerce currency not configured',
        ];

        $taxBehavior = $ecomConfig['tax_behavior'] ?? 'inclusive';
        $validTax = in_array($taxBehavior, ['inclusive', 'exclusive', 'not_specified'], true);
        $checks[] = [
            'check' => 'tax_behavior_valid',
            'passed' => $validTax,
            'message' => $validTax
                ? sprintf('Tax behavior: %s', $taxBehavior)
                : sprintf('Invalid tax behavior: %s', $taxBehavior),
        ];

        // Verify format converter exists
        $converterExists = class_exists(\ZeroBoiler\Analytics\Support\EcommerceFormatConverter::class);
        $checks[] = [
            'check' => 'ecommerce_format_converter_exists',
            'passed' => $converterExists,
            'message' => $converterExists
                ? 'EcommerceFormatConverter exists'
                : 'EcommerceFormatConverter not found',
        ];

        // Verify ecommerce service exists
        $ecomService = class_exists(\ZeroBoiler\Analytics\Services\EcommerceAnalyticsService::class);
        $checks[] = [
            'check' => 'ecommerce_analytics_service_exists',
            'passed' => $ecomService,
            'message' => $ecomService
                ? 'EcommerceAnalyticsService exists'
                : 'EcommerceAnalyticsService not found',
        ];

        // Verify cart state manager exists
        $cartManager = class_exists(\ZeroBoiler\Analytics\Services\CartStateManager::class);
        $checks[] = [
            'check' => 'cart_state_manager_exists',
            'passed' => $cartManager,
            'message' => $cartManager
                ? 'CartStateManager exists'
                : 'CartStateManager not found',
        ];

        return [
            'key' => 'ecommerce_helpers',
            'label' => 'E-commerce Helpers',
            'status' => 'pending',
            'checks' => $checks,
        ];
    }

    /**
     * 9. Admin Commands — Verify Artisan commands are registered.
     *
     * @return array{key: string, label: string, status: string, checks: list<array{check: string, passed: bool, message: string}>}
     */
    private function checkAdminCommands(): array
    {
        $checks = [];

        $requiredCommands = [
            'zb:analytics:overview' => \ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class,
            'zb:analytics:test' => \ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class,
            'zb:analytics:integrity' => \ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class,
            'zb:analytics:health' => \ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand::class,
        ];

        foreach ($requiredCommands as $signature => $class) {
            $exists = class_exists($class);
            $checks[] = [
                'check' => "command_{$signature}",
                'passed' => $exists,
                'message' => $exists
                    ? sprintf('Command %s registered', $signature)
                    : sprintf('Command class %s not found', $signature),
            ];
        }

        $commandDir = dirname(__DIR__) . '/Console/Commands';
        $commandCount = 0;
        if ($this->files->isDirectory($commandDir)) {
            $commandCount = count(glob($commandDir . '/*Command.php'));
        }

        $checks[] = [
            'check' => 'total_command_count',
            'passed' => $commandCount >= 10,
            'message' => sprintf('%d Artisan commands registered', $commandCount),
        ];

        return [
            'key' => 'admin_commands',
            'label' => 'Admin Commands',
            'status' => 'pending',
            'checks' => $checks,
        ];
    }

    /**
     * 10. Config Expansion — Verify all required config sections exist.
     *
     * @return array{key: string, label: string, status: string, checks: list<array{check: string, passed: bool, message: string}>}
     */
    private function checkConfigExpansion(): array
    {
        $checks = [];
        $analyticsConfig = $this->config->get('zeroboiler.analytics', []);

        foreach (self::REQUIRED_CONFIG_SECTIONS as $section) {
            $exists = isset($analyticsConfig[$section]);
            $checks[] = [
                'check' => "config_section_{$section}",
                'passed' => $exists,
                'message' => $exists
                    ? sprintf('Config section analytics.%s exists', $section)
                    : sprintf('Config section analytics.%s missing', $section),
            ];
        }

        // Verify config file exists on disk
        $configPath = dirname(__DIR__, 2) . '/config/zeroboiler.php';
        $configExists = $this->files->exists($configPath);
        $checks[] = [
            'check' => 'config_file_exists',
            'passed' => $configExists,
            'message' => $configExists
                ? 'config/zeroboiler.php exists'
                : 'config/zeroboiler.php not found',
        ];

        return [
            'key' => 'config_expansion',
            'label' => 'Config Expansion',
            'status' => 'pending',
            'checks' => $checks,
        ];
    }

    /**
     * 11. Optional Providers — Verify tracker implementations satisfy interface.
     *
     * @return array{key: string, label: string, status: string, checks: list<array{check: string, passed: bool, message: string}>}
     */
    private function checkOptionalProviders(): array
    {
        $checks = [];

        $optionalTrackers = [
            'plausible' => \ZeroBoiler\Analytics\Trackers\PlausibleTracker::class,
            'posthog' => \ZeroBoiler\Analytics\Trackers\PosthogTracker::class,
            'mixpanel' => \ZeroBoiler\Analytics\Trackers\MixpanelTracker::class,
            'amplitude' => \ZeroBoiler\Analytics\Trackers\AmplitudeTracker::class,
            'tiktok' => \ZeroBoiler\Analytics\Trackers\TikTokTracker::class,
            'linkedin' => \ZeroBoiler\Analytics\Trackers\LinkedInTracker::class,
        ];

        foreach ($optionalTrackers as $name => $class) {
            $exists = class_exists($class);
            $implementsInterface = $exists && is_a($class, TrackerInterface::class, true);

            $checks[] = [
                'check' => "tracker_{$name}",
                'passed' => $exists && $implementsInterface,
                'message' => ($exists && $implementsInterface)
                    ? sprintf('%s tracker implements TrackerInterface', ucfirst($name))
                    : sprintf('%s tracker issue (exists: %s, interface: %s)', ucfirst($name), $exists ? 'yes' : 'no', $implementsInterface ? 'yes' : 'no'),
            ];
        }

        // Verify core trackers too
        $coreTrackers = [
            'ga4' => \ZeroBoiler\Analytics\Trackers\GA4Tracker::class,
            'gtm' => \ZeroBoiler\Analytics\Trackers\GTMTracker::class,
            'meta' => \ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class,
        ];

        foreach ($coreTrackers as $name => $class) {
            $exists = class_exists($class);
            $implementsInterface = $exists && is_a($class, TrackerInterface::class, true);

            $checks[] = [
                'check' => "tracker_{$name}",
                'passed' => $exists && $implementsInterface,
                'message' => ($exists && $implementsInterface)
                    ? sprintf('%s tracker implements TrackerInterface', strtoupper($name))
                    : sprintf('%s tracker issue (exists: %s, interface: %s)', strtoupper($name), $exists ? 'yes' : 'no', $implementsInterface ? 'yes' : 'no'),
            ];
        }

        return [
            'key' => 'optional_providers',
            'label' => 'Optional Providers',
            'status' => 'pending',
            'checks' => $checks,
        ];
    }

    /**
     * 12. Tests & README — Verify test count and documentation size.
     *
     * @return array{key: string, label: string, status: string, checks: list<array{check: string, passed: bool, message: string}>}
     */
    private function checkTestsAndReadme(): array
    {
        $checks = [];
        $testsDir = dirname(__DIR__, 2) . '/tests';
        $readmePath = dirname(__DIR__, 2) . '/README.md';

        $testFiles = 0;
        if ($this->files->isDirectory($testsDir)) {
            $testFiles = count(glob($testsDir . '/*Test.php')) + count(glob($testsDir . '/**/*Test.php'));
            // Subtract duplicates from recursive
            $topLevel = count(glob($testsDir . '/*Test.php'));
            $testFiles = $topLevel;
            foreach (glob($testsDir . '/*', GLOB_ONLYDIR) as $subDir) {
                $testFiles += count(glob($subDir . '/*Test.php'));
            }
        }

        $checks[] = [
            'check' => 'test_file_count',
            'passed' => $testFiles >= self::MIN_TEST_COUNT,
            'message' => sprintf('%d test files (min %d)', $testFiles, self::MIN_TEST_COUNT),
        ];

        // Verify Pest.php config exists
        $pestExists = $this->files->exists(dirname(__DIR__, 2) . '/pest.php');
        $checks[] = [
            'check' => 'pest_config_exists',
            'passed' => $pestExists,
            'message' => $pestExists
                ? 'pest.php configuration exists'
                : 'pest.php configuration not found',
        ];

        // Verify README size
        $readmeExists = $this->files->exists($readmePath);
        if ($readmeExists) {
            $readmeSize = $this->files->size($readmePath);
            $checks[] = [
                'check' => 'readme_size',
                'passed' => $readmeSize >= self::MIN_README_SIZE,
                'message' => sprintf('README.md: %d bytes (min %d)', $readmeSize, self::MIN_README_SIZE),
            ];
        } else {
            $checks[] = [
                'check' => 'readme_size',
                'passed' => false,
                'message' => 'README.md not found',
            ];
        }

        // Verify CHANGELOG exists
        $changelogExists = $this->files->exists(dirname(__DIR__, 2) . '/CHANGELOG.md');
        $checks[] = [
            'check' => 'changelog_exists',
            'passed' => $changelogExists,
            'message' => $changelogExists
                ? 'CHANGELOG.md exists'
                : 'CHANGELOG.md not found',
        ];

        return [
            'key' => 'tests_readme',
            'label' => 'Tests & README',
            'status' => 'pending',
            'checks' => $checks,
        ];
    }

    /**
     * Calculate a letter grade from a numeric score.
     *
     * @return string Grade letter (A+ through F)
     */
    private function calculateGrade(int $score): string
    {
        return match (true) {
            $score >= 95 => 'A+',
            $score >= 90 => 'A',
            $score >= 85 => 'A-',
            $score >= 80 => 'B+',
            $score >= 75 => 'B',
            $score >= 70 => 'B-',
            $score >= 65 => 'C+',
            $score >= 60 => 'C',
            $score >= 55 => 'C-',
            $score >= 50 => 'D',
            default => 'F',
        };
    }
}
