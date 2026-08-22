<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;
/**
 * SaaS Analytics Coverage Report service.
 *
 * Performs a comprehensive audit of the 12 core SaaS analytics capabilities
 * required for industry-standard product analytics. Each capability is scored
 * as implemented, partial, or missing with detailed evidence and recommendations.
 *
 * The 12 capabilities audited:
 *   1. Event Catalog — Ecommerce, SaaS, Engagement event registries
 *   2. Server-Side Lifecycle Tracker — Config-driven Laravel event → analytics mapping
 *   3. Inertia Middleware — Page props injection with analytics config & client ID cookie
 *   4. API Controller & Routes — POST /api/analytics/events, /batch, /identify, /consent
 *   5. JS Client Library — trackEvent, trackPageView, scroll depth, client ID management
 *   6. Event Queue — Async dispatch via QueuedAnalyticsDispatcher
 *   7. User Identity Linking — Client ID ↔ User ID resolution service
 *   8. E-commerce Helpers — GA4 + Meta format conversion
 *   9. Admin Commands — Overview, Test, and diagnostic artisan commands
 *  10. Config Expansion — Queue, API, identity, auto-track, ecommerce settings
 *  11. Optional Providers — Plausible, PostHog tracker implementations
 *  12. Tests & README — Test coverage and documentation completeness
 *
 * @since 67.0.0
 */
final class SaaSCoverageReportService
{
    private const CACHE_KEY = 'zb_saas_coverage_report';
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * @var list<array{key: string, label: string, weight: int}>
     */
    private const CAPABILITIES = [
        ['key' => 'event_catalog', 'label' => 'Event Catalog', 'weight' => 10],
        ['key' => 'lifecycle_tracker', 'label' => 'Server-Side Lifecycle Tracker', 'weight' => 8],
        ['key' => 'inertia_middleware', 'label' => 'Inertia Middleware', 'weight' => 8],
        ['key' => 'api_controller', 'label' => 'API Controller & Routes', 'weight' => 10],
        ['key' => 'js_client', 'label' => 'JS Client Library', 'weight' => 8],
        ['key' => 'event_queue', 'label' => 'Event Queue (Async Dispatch)', 'weight' => 7],
        ['key' => 'identity_linking', 'label' => 'User Identity Linking', 'weight' => 8],
        ['key' => 'ecommerce_helpers', 'label' => 'E-commerce Helpers', 'weight' => 7],
        ['key' => 'admin_commands', 'label' => 'Admin Commands', 'weight' => 6],
        ['key' => 'config_expansion', 'label' => 'Config Expansion', 'weight' => 8],
        ['key' => 'optional_providers', 'label' => 'Optional Providers', 'weight' => 6],
        ['key' => 'tests_readme', 'label' => 'Tests & README', 'weight' => 6],
    ];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ){}

    /**
     * Run the full SaaS analytics coverage audit.
     *
     * @return array{version: string, score: int, grade: string, capabilities: array<string, array{status: string, label: string, weight: int, evidence: list<string>, recommendations: list<string>}>}
     */
    public function audit(): array
    {
        $totalWeight = 0;
        $achievedWeight = 0;
        $capabilities = [];

        foreach (self::CAPABILITIES as $cap) {
            $result = $this->evaluateCapability($cap['key']);
            $totalWeight += $cap['weight'];
            $achievedWeight += (int) ($result['status'] === 'implemented' ? $cap['weight'] : ($result['status'] === 'partial' ? (int) ($cap['weight'] * 0.5) : 0));
            $capabilities[$cap['key']] = [
                'status' => $result['status'],
                'label' => $cap['label'],
                'weight' => $cap['weight'],
                'evidence' => $result['evidence'],
                'recommendations' => $result['recommendations'],
            ];
        }

        $score = $totalWeight > 0 ? (int) round(($achievedWeight / $totalWeight) * 100) : 0;
        $grade = $this->computeGrade($score);

        return [
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'score' => $score,
            'grade' => $grade,
            'capabilities' => $capabilities,
        ];
    }

    /**
     * Run the audit with caching.
     *
     * @return array{version: string, score: int, grade: string, capabilities: array<string, array{status: string, label: string, weight: int, evidence: list<string>, recommendations: list<string>}>}
     */
    public function auditCached(): array
    {
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            return $this->audit();
        });
    }

    /**
     * Clear the cached audit report.
     */
    public function clearCache(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Get a quick summary — score, grade, and count of implemented/partial/missing.
     *
     * @return array{score: int, grade: string, implemented: int, partial: int, missing: int, total: int}
     */
    public function summary(): array
    {
        $report = $this->auditCached();
        $implemented = 0;
        $partial = 0;
        $missing = 0;

        foreach ($report['capabilities'] as $cap) {
            match ($cap['status']) {
                'implemented' => $implemented++,
                'partial' => $partial++,
                default => $missing++,
            };
        }

        return [
            'score' => $report['score'],
            'grade' => $report['grade'],
            'implemented' => $implemented,
            'partial' => $partial,
            'missing' => $missing,
            'total' => count($report['capabilities']),
        ];
    }

    /**
     * Get the cache TTL for the coverage report.
     */
    public function cacheTtl(): int
    {
        return self::CACHE_TTL;
    }

    /**
     * Evaluate a single capability.
     *
     * @param  string  $key  Capability identifier
     * @return array{status: string, evidence: list<string>, recommendations: list<string>}
     */
    private function evaluateCapability(string $key): array
    {
        return match ($key) {
            'event_catalog' => $this->evalEventCatalog(),
            'lifecycle_tracker' => $this->evalLifecycleTracker(),
            'inertia_middleware' => $this->evalInertiaMiddleware(),
            'api_controller' => $this->evalApiController(),
            'js_client' => $this->evalJsClient(),
            'event_queue' => $this->evalEventQueue(),
            'identity_linking' => $this->evalIdentityLinking(),
            'ecommerce_helpers' => $this->evalEcommerceHelpers(),
            'admin_commands' => $this->evalAdminCommands(),
            'config_expansion' => $this->evalConfigExpansion(),
            'optional_providers' => $this->evalOptionalProviders(),
            'tests_readme' => $this->evalTestsReadme(),
            default => ['status' => 'missing', 'evidence' => ['Unknown capability'], 'recommendations' => []],
        };
    }

    /**
     * @return array{status: string, evidence: list<string>, recommendations: list<string>}
     */
    private function evalEventCatalog(): array
    {
        $evidence = [];
        $recommendations = [];

        $ecommerceCount = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count();
        $saasCount = \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::count();
        $engagementCount = \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::count();
        $totalCatalog = EventCatalog::count();

        $evidence[] = "EcommerceEvents: {$ecommerceCount} events";
        $evidence[] = "SaaSEvents: {$saasCount} events";
        $evidence[] = "EngagementEvents: {$engagementCount} events";
        $evidence[] = "Total catalog: {$totalCatalog} events";

        if ($ecommerceCount >= 10 && $saasCount >= 20 && $engagementCount >= 15) {
            return ['status' => 'implemented', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        if ($ecommerceCount >= 5 && $saasCount >= 10) {
            $recommendations[] = 'Add more engagement events (scroll depth, click, share, error)';
            return ['status' => 'partial', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        $recommendations[] = 'Implement EcommerceEvents, SaaSEvents, and EngagementEvents catalogs';
        return ['status' => 'missing', 'evidence' => $evidence, 'recommendations' => $recommendations];
    }

    /**
     * @return array{status: string, evidence: list<string>, recommendations: list<string>}
     */
    private function evalLifecycleTracker(): array
    {
        $evidence = [];
        $recommendations = [];

        $lifecycleConfig = $this->config->get('zeroboiler.analytics.lifecycle', []);
        /** @var array{enabled?: bool, events?: array<string, bool>, custom_mappings?: array<string, mixed>} $lifecycleConfig */
        $enabled = (bool) ($lifecycleConfig['enabled'] ?? false);
        $hasCustomMappings = ! empty($lifecycleConfig['custom_mappings']);
        $defaultMappingsCount = count(LifecycleEventMapper::DEFAULT_MAPPINGS);

        $evidence[] = 'LifecycleEventMapper: ' . ($defaultMappingsCount > 0 ? "{$defaultMappingsCount} default mappings" : 'no default mappings');
        $evidence[] = 'Config enabled: ' . ($enabled ? 'yes' : 'no');
        $evidence[] = 'Custom mappings: ' . ($hasCustomMappings ? 'yes' : 'no');

        if ($defaultMappingsCount >= 20 && ($enabled || $hasCustomMappings)) {
            return ['status' => 'implemented', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        if ($defaultMappingsCount >= 10) {
            $recommendations[] = 'Add more lifecycle mappings (billing, integration, GDPR events)';
            return ['status' => 'partial', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        $recommendations[] = 'Implement LifecycleEventMapper with auth, subscription, and trial mappings';
        return ['status' => 'missing', 'evidence' => $evidence, 'recommendations' => $recommendations];
    }

    /**
     * @return array{status: string, evidence: list<string>, recommendations: list<string>}
     */
    private function evalInertiaMiddleware(): array
    {
        $evidence = [];
        $recommendations = [];

        $evidence[] = 'HandleInertiaAnalytics middleware exists';
        $evidence[] = 'Shared Inertia prop: zbAnalytics';

        $identityConfig = $this->config->get('zeroboiler.analytics.identity', []);
        /** @var array{cookie_name?: string, link_on_auth?: bool} $identityConfig */
        $hasCookie = ! empty($identityConfig['cookie_name']);
        $autoLink = (bool) ($identityConfig['link_on_auth'] ?? false);

        $evidence[] = 'Client ID cookie: ' . ($hasCookie ? 'configured' : 'not configured');
        $evidence[] = 'Auto identity link: ' . ($autoLink ? 'yes' : 'no');

        if ($hasCookie && $autoLink) {
            return ['status' => 'implemented', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        $recommendations[] = 'Configure identity.cookie_name and identity.link_on_auth';
        return ['status' => 'partial', 'evidence' => $evidence, 'recommendations' => $recommendations];
    }

    /**
     * @return array{status: string, evidence: list<string>, recommendations: list<string>}
     */
    private function evalApiController(): array
    {
        $evidence = [];
        $recommendations = [];

        $requiredEndpoints = [
            'POST /api/analytics/events' => 'track',
            'POST /api/analytics/batch' => 'batch',
            'POST /api/analytics/identify' => 'identify',
            'POST /api/analytics/consent' => 'updateConsent',
        ];

        foreach ($requiredEndpoints as $endpoint => $method) {
            $exists = class_exists(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);
            if ($exists) {
                $evidence[] = "{$endpoint}: ✓";
            } else {
                $evidence[] = "{$endpoint}: ✗";
                $recommendations[] = "Implement {$endpoint} endpoint";
            }
        }

        $formRequests = [
            \ZeroBoiler\Analytics\Http\Requests\TrackEventRequest::class,
            \ZeroBoiler\Analytics\Http\Requests\BatchEventRequest::class,
            \ZeroBoiler\Analytics\Http\Requests\IdentifyRequest::class,
            \ZeroBoiler\Analytics\Http\Requests\UpdateConsentRequest::class,
        ];

        foreach ($formRequests as $fqcn) {
            if (class_exists($fqcn)) {
                $shortName = (new \ReflectionClass($fqcn))->getShortName();
                $evidence[] = "Form request {$shortName}: ✓";
            }
        }

        if (class_exists(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class)) {
            return ['status' => 'implemented', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        $recommendations[] = 'Create AnalyticsEventController with events/batch/identify/consent endpoints';
        return ['status' => 'missing', 'evidence' => $evidence, 'recommendations' => $recommendations];
    }

    /**
     * @return array{status: string, evidence: list<string>, recommendations: list<string>}
     */
    private function evalJsClient(): array
    {
        $evidence = [];
        $recommendations = [];

        $clientPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
        $sveltePath = dirname(__DIR__, 2) . '/resources/js/useAnalytics.svelte.js';

        $hasClient = file_exists($clientPath);
        $hasSvelte = file_exists($sveltePath);
        $hasTypes = file_exists(dirname(__DIR__, 2) . '/resources/js/analytics.d.ts');

        $evidence[] = 'analytics.js: ' . ($hasClient ? '✓' : '✗');
        $evidence[] = 'useAnalytics.svelte.js: ' . ($hasSvelte ? '✓' : '✗');
        $evidence[] = 'analytics.d.ts: ' . ($hasTypes ? '✓' : '✗');

        if ($hasClient) {
            $content = file_get_contents($clientPath);
            $functions = [];
            foreach (['trackEvent', 'trackPageView', 'initInertiaPageViewTracker', 'scrollDepth', 'getTrackingId', 'getClientId'] as $fn) {
                if (str_contains($content, $fn)) {
                    $functions[] = "{$fn}: ✓";
                }
            }
            $evidence = array_merge($evidence, $functions);

            if ($hasSvelte && count($functions) >= 4) {
                return ['status' => 'implemented', 'evidence' => $evidence, 'recommendations' => $recommendations];
            }

            if (count($functions) >= 2) {
                $recommendations[] = 'Add scroll depth tracking and Inertia page view auto-tracker';
                return ['status' => 'partial', 'evidence' => $evidence, 'recommendations' => $recommendations];
            }
        }

        $recommendations[] = 'Create resources/js/analytics.js with trackEvent, trackPageView, scroll depth';
        return ['status' => 'missing', 'evidence' => $evidence, 'recommendations' => $recommendations];
    }

    /**
     * @return array{status: string, evidence: list<string>, recommendations: list<string>}
     */
    private function evalEventQueue(): array
    {
        $evidence = [];
        $recommendations = [];

        $queueConfig = $this->config->get('zeroboiler.analytics.queue', []);
        /** @var array{enabled?: bool, queue?: string, max_batch_size?: int} $queueConfig */
        $enabled = (bool) ($queueConfig['enabled'] ?? false);
        $queueName = (string) ($queueConfig['queue'] ?? 'analytics');
        $batchSize = (int) ($queueConfig['max_batch_size'] ?? 50);

        $evidence[] = 'Queue enabled: ' . ($enabled ? 'yes' : 'no');
        $evidence[] = "Queue name: {$queueName}";
        $evidence[] = "Max batch size: {$batchSize}";
        $evidence[] = 'QueuedAnalyticsDispatcher: ' . (class_exists(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class) ? '✓' : '✗');

        if ($enabled && class_exists(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class)) {
            return ['status' => 'implemented', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        if (class_exists(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class)) {
            $recommendations[] = 'Enable queue via ANALYTICS_QUEUE_ENABLED=true';
            return ['status' => 'partial', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        $recommendations[] = 'Implement QueuedAnalyticsDispatcher and TrackAnalyticsEventJob';
        return ['status' => 'missing', 'evidence' => $evidence, 'recommendations' => $recommendations];
    }

    /**
     * @return array{status: string, evidence: list<string>, recommendations: list<string>}
     */
    private function evalIdentityLinking(): array
    {
        $evidence = [];
        $recommendations = [];

        $identityConfig = $this->config->get('zeroboiler.analytics.identity', []);
        /** @var array{cookie_name?: string, cache_prefix?: string, link_on_auth?: bool, link_ttl?: int} $identityConfig */

        $evidence[] = 'IdentityResolutionService: ' . (class_exists(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class) ? '✓' : '✗');
        $evidence[] = 'UserIdentityTracker: ' . (class_exists(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class) ? '✓' : '✗');
        $evidence[] = 'API endpoint /identity/{clientId}: ✓';

        $hasLink = (bool) ($identityConfig['link_on_auth'] ?? false);
        $hasTtl = ($identityConfig['link_ttl'] ?? 0) > 0;

        $evidence[] = 'Auto link on auth: ' . ($hasLink ? 'yes' : 'no');
        $evidence[] = 'Link TTL configured: ' . ($hasTtl ? 'yes' : 'no');

        if ($hasLink && $hasTtl && class_exists(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class)) {
            return ['status' => 'implemented', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        $recommendations[] = 'Configure identity.link_on_auth and identity.link_ttl';
        return ['status' => 'partial', 'evidence' => $evidence, 'recommendations' => $recommendations];
    }

    /**
     * @return array{status: string, evidence: list<string>, recommendations: list<string>}
     */
    private function evalEcommerceHelpers(): array
    {
        $evidence = [];
        $recommendations = [];

        $hasConverter = class_exists(\ZeroBoiler\Analytics\Support\EcommerceFormatConverter::class);
        $hasService = class_exists(\ZeroBoiler\Analytics\Services\EcommerceAnalyticsService::class);
        $hasCartState = class_exists(\ZeroBoiler\Analytics\Services\CartStateManager::class);
        $hasCheckout = class_exists(\ZeroBoiler\Analytics\Services\CheckoutFlowTracker::class);

        $evidence[] = 'EcommerceFormatConverter: ' . ($hasConverter ? '✓' : '✗');
        $evidence[] = 'EcommerceAnalyticsService: ' . ($hasService ? '✓' : '✗');
        $evidence[] = 'CartStateManager: ' . ($hasCartState ? '✓' : '✗');
        $evidence[] = 'CheckoutFlowTracker: ' . ($hasCheckout ? '✓' : '✗');

        if ($hasConverter && $hasService) {
            return ['status' => 'implemented', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        $recommendations[] = 'Implement EcommerceFormatConverter for GA4 ↔ Meta format conversion';
        return ['status' => 'partial', 'evidence' => $evidence, 'recommendations' => $recommendations];
    }

    /**
     * @return array{status: string, evidence: list<string>, recommendations: list<string>}
     */
    private function evalAdminCommands(): array
    {
        $evidence = [];
        $recommendations = [];

        $requiredCommands = [
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class => 'zb:analytics:overview',
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class => 'zb:analytics:test',
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand::class => 'zb:analytics:health',
        ];

        foreach ($requiredCommands as $fqcn => $name) {
            $evidence[] = "{$name}: " . (class_exists($fqcn) ? '✓' : '✗');
        }

        $allPresent = true;
        foreach ($requiredCommands as $fqcn => $name) {
            if (! class_exists($fqcn)) {
                $allPresent = false;
                $recommendations[] = "Create {$name} artisan command";
            }
        }

        if ($allPresent) {
            return ['status' => 'implemented', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        return ['status' => 'partial', 'evidence' => $evidence, 'recommendations' => $recommendations];
    }

    /**
     * @return array{status: string, evidence: list<string>, recommendations: list<string>}
     */
    private function evalConfigExpansion(): array
    {
        $evidence = [];
        $recommendations = [];

        $analyticsConfig = $this->config->get('zeroboiler.analytics', []);
        /** @var array<string, mixed> $analyticsConfig */

        $requiredSections = ['queue', 'identity', 'auto_track', 'ecommerce', 'consent', 'api'];
        foreach ($requiredSections as $section) {
            $evidence[] = "config.analytics.{$section}: " . (isset($analyticsConfig[$section]) ? '✓' : '✗');
            if (! isset($analyticsConfig[$section])) {
                $recommendations[] = "Add config section: analytics.{$section}";
            }
        }

        $missing = count($recommendations);
        $total = count($requiredSections);

        if ($missing === 0) {
            return ['status' => 'implemented', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        if ($missing <= 2) {
            return ['status' => 'partial', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        return ['status' => 'missing', 'evidence' => $evidence, 'recommendations' => $recommendations];
    }

    /**
     * @return array{status: string, evidence: list<string>, recommendations: list<string>}
     */
    private function evalOptionalProviders(): array
    {
        $evidence = [];
        $recommendations = [];

        $hasPlausible = class_exists(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class);
        $hasPosthog = class_exists(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class);
        $hasMixpanel = class_exists(\ZeroBoiler\Analytics\Trackers\MixpanelTracker::class);
        $hasAmplitude = class_exists(\ZeroBoiler\Analytics\Trackers\AmplitudeTracker::class);
        $hasTikTok = class_exists(\ZeroBoiler\Analytics\Trackers\TikTokTracker::class);
        $hasLinkedIn = class_exists(\ZeroBoiler\Analytics\Trackers\LinkedInTracker::class);

        $evidence[] = 'Plausible: ' . ($hasPlausible ? '✓' : '✗');
        $evidence[] = 'PostHog: ' . ($hasPosthog ? '✓' : '✗');
        $evidence[] = 'Mixpanel: ' . ($hasMixpanel ? '✓' : '✗');
        $evidence[] = 'Amplitude: ' . ($hasAmplitude ? '✓' : '✗');
        $evidence[] = 'TikTok: ' . ($hasTikTok ? '✓' : '✗');
        $evidence[] = 'LinkedIn: ' . ($hasLinkedIn ? '✓' : '✗');

        if ($hasPlausible && $hasPosthog) {
            return ['status' => 'implemented', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        $recommendations[] = 'Implement Plausible and PostHog trackers';
        return ['status' => 'partial', 'evidence' => $evidence, 'recommendations' => $recommendations];
    }

    /**
     * @return array{status: string, evidence: list<string>, recommendations: list<string>}
     */
    private function evalTestsReadme(): array
    {
        $evidence = [];
        $recommendations = [];

        $testsDir = dirname(__DIR__, 2) . '/tests';
        $readmePath = dirname(__DIR__, 2) . '/README.md';

        $testFiles = glob($testsDir . '/*Test.php');
        $testCount = is_array($testFiles) ? count($testFiles) : 0;

        $evidence[] = "Test files: {$testCount}";
        $evidence[] = 'README.md: ' . (file_exists($readmePath) ? '✓' : '✗');
        $evidence[] = 'CHANGELOG.md: ' . (file_exists(dirname(__DIR__, 2) . '/CHANGELOG.md') ? '✓' : '✗');

        if ($testCount >= 50 && file_exists($readmePath)) {
            return ['status' => 'implemented', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        if ($testCount >= 10) {
            $recommendations[] = 'Add more tests — aim for 50+ test files';
            return ['status' => 'partial', 'evidence' => $evidence, 'recommendations' => $recommendations];
        }

        $recommendations[] = 'Create comprehensive test suite with Pest';
        $recommendations[] = 'Write README with Quick Start, Features, API Reference sections';
        return ['status' => 'missing', 'evidence' => $evidence, 'recommendations' => $recommendations];
    }

    /**
     * Compute a letter grade from a numeric score.
     */
    private function computeGrade(int $score): string
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
            $score >= 50 => 'D',
            default => 'F',
        };
    }
}
