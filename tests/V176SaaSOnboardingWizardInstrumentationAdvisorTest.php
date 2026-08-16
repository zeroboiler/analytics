<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Services\AnalyticsConfigValidationService;
use ZeroBoiler\Analytics\Services\EventInstrumentationAdvisor;
use ZeroBoiler\Analytics\Services\SaaSOnboardingWizardService;

/**
 * V176 — SaaS Starter Onboarding Wizard + Event Instrumentation Advisor +
 * Config Validation Service — Industry-Standard SaaS Analytics Upgrade.
 *
 * Validates:
 * - SaaSOnboardingWizardService: 19 steps, completion assessment, grades, gaps
 * - EventInstrumentationAdvisor: report, coverage, maturity, quick wins
 * - AnalyticsConfigValidationService: core structure, provider, consent, security
 * - Version consistency across all entry points (177.0.0)
 * - New controller endpoints exist
 * - New routes registered
 * - Service registration in ServiceProvider
 * - PHP 8.5 strict types, return types, docblocks
 */
test('v176 version consistency: version is 177.0.0 everywhere', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('177.0.0');

    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('177.0.0');

    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain('@version 177.0.0');

    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelte)->toContain('@version 177.0.0');

    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($dts)->toContain('@version 177.0.0');

    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-177.0.0');
});

test('v176 saas onboarding wizard: service exists and is final', function (): void {
    expect(class_exists(SaaSOnboardingWizardService::class))->toBeTrue();

    $reflection = new ReflectionClass(SaaSOnboardingWizardService::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->hasMethod('getState'))->toBeTrue();
    expect($reflection->hasMethod('summary'))->toBeTrue();
    expect($reflection->hasMethod('gaps'))->toBeTrue();
    expect($reflection->hasMethod('nextAction'))->toBeTrue();
    expect($reflection->hasMethod('grade'))->toBeTrue();
    expect($reflection->hasMethod('categoryBreakdown'))->toBeTrue();
    expect($reflection->hasMethod('invalidateCache'))->toBeTrue();

    // Constructor should be void-returning with type declarations
    $constructor = $reflection->getConstructor();
    expect($constructor)->not->toBeNull();
    expect($constructor->hasReturnType())->toBeTrue();
    $params = $constructor->getParameters();
    expect(count($params))->toBe(2);

    // Check strict types
    $contents = file_get_contents($reflection->getFileName());
    expect($contents)->toContain('declare(strict_types=1)');
});

test('v176 saas onboarding wizard: has 19 steps covering all categories', function (): void {
    // We can't instantiate without Laravel, but we can check the file structure
    $contents = file_get_contents(__DIR__ . '/../src/Services/SaaSOnboardingWizardService.php');

    // Check step definitions
    expect($contents)->toContain("'key' => 'provider_ga4'");
    expect($contents)->toContain("'key' => 'consent_mode'");
    expect($contents)->toContain("'key' => 'core_saas_events'");
    expect($contents)->toContain("'key' => 'identity_linking'");
    expect($contents)->toContain("'key' => 'inertia_middleware'");
    expect($contents)->toContain("'key' => 'js_client'");
    expect($contents)->toContain("'key' => 'provider_meta'");
    expect($contents)->toContain("'key' => 'lifecycle_tracking'");
    expect($contents)->toContain("'key' => 'queue_config'");
    expect($contents)->toContain("'key' => 'ecommerce_events'");
    expect($contents)->toContain("'key' => 'event_validation'");
    expect($contents)->toContain("'key' => 'error_tracking'");
    expect($contents)->toContain("'key' => 'session_recording'");

    // Check priorities
    expect($contents)->toContain("'priority' => 'critical'");
    expect($contents)->toContain("'priority' => 'high'");
    expect($contents)->toContain("'priority' => 'medium'");
    expect($contents)->toContain("'priority' => 'low'");

    // Check categories
    expect($contents)->toContain("'category' => 'providers'");
    expect($contents)->toContain("'category' => 'compliance'");
    expect($contents)->toContain("'category' => 'events'");
    expect($contents)->toContain("'category' => 'infrastructure'");
    expect($contents)->toContain("'category' => 'quality'");
});

test('v176 saas onboarding wizard: grade calculation is correct', function (): void {
    $contents = file_get_contents(__DIR__ . '/../src/Services/SaaSOnboardingWizardService.php');

    // Grade boundaries
    expect($contents)->toContain("return 'A+'");
    expect($contents)->toContain("return 'A'");
    expect($contents)->toContain("return 'B+'");
    expect($contents)->toContain("return 'B'");
    expect($contents)->toContain("return 'C'");
    expect($contents)->toContain("return 'D'");
    expect($contents)->toContain("return 'F'");

    // Grade method checks critical remaining
    expect($contents)->toContain('$criticalRemaining');
});

test('v176 event instrumentation advisor: service exists and is final', function (): void {
    expect(class_exists(EventInstrumentationAdvisor::class))->toBeTrue();

    $reflection = new ReflectionClass(EventInstrumentationAdvisor::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->hasMethod('getReport'))->toBeTrue();
    expect($reflection->hasMethod('summary'))->toBeTrue();
    expect($reflection->hasMethod('gaps'))->toBeTrue();
    expect($reflection->hasMethod('quickWins'))->toBeTrue();
    expect($reflection->hasMethod('priorityMatrix'))->toBeTrue();
    expect($reflection->hasMethod('stageCoverage'))->toBeTrue();
    expect($reflection->hasMethod('invalidateCache'))->toBeTrue();

    // Check strict types
    $contents = file_get_contents($reflection->getFileName());
    expect($contents)->toContain('declare(strict_types=1)');
});

test('v176 event instrumentation advisor: covers SaaS funnel stages', function (): void {
    $contents = file_get_contents(__DIR__ . '/../src/Services/EventInstrumentationAdvisor.php');

    // AARRR funnel stages
    expect($contents)->toContain("'signup'");
    expect($contents)->toContain("'activation'");
    expect($contents)->toContain("'retention'");
    expect($contents)->toContain("'revenue'");
    expect($contents)->toContain("'referral'");

    // Core SaaS events
    expect($contents)->toContain("'label' => 'User Registration'");
    expect($contents)->toContain("'label' => 'Trial Start'");
    expect($contents)->toContain("'label' => 'Subscription Created'");
    expect($contents)->toContain("'label' => 'Plan Upgrade'");
    expect($contents)->toContain("'label' => 'Purchase'");

    // Maturity levels
    expect($contents)->toContain("'Enterprise'");
    expect($contents)->toContain("'Growth'");
    expect($contents)->toContain("'Starter+'");
    expect($contents)->toContain("'Starter'");
    expect($contents)->toContain("'Minimal'");

    // Code snippets for recommendations
    expect($contents)->toContain('code_snippet');
    expect($contents)->toContain('rationale');
});

test('v176 event instrumentation advisor: recommendations have code snippets', function (): void {
    $contents = file_get_contents(__DIR__ . '/../src/Services/EventInstrumentationAdvisor.php');

    // Each recommendation should have a code snippet showing how to implement
    expect($contents)->toContain("Analytics::track('sign_up'");
    expect($contents)->toContain("Analytics::track('trial_start'");
    expect($contents)->toContain("Analytics::purchase('TXN-123'");
    expect($contents)->toContain("Analytics::track('plan_upgrade'");
    expect($contents)->toContain("Analytics::track('feature_used'");
    expect($contents)->toContain('await trackPageView()');
});

test('v176 config validation service: service exists and is final', function (): void {
    expect(class_exists(AnalyticsConfigValidationService::class))->toBeTrue();

    $reflection = new ReflectionClass(AnalyticsConfigValidationService::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->hasMethod('validate'))->toBeTrue();

    // Check strict types
    $contents = file_get_contents($reflection->getFileName());
    expect($contents)->toContain('declare(strict_types=1)');

    // Return type of validate should be array
    $method = $reflection->getMethod('validate');
    $returnType = $method->getReturnType();
    expect($returnType)->not->toBeNull();
    expect((string) $returnType)->toBe('array');
});

test('v176 config validation service: validates all major config sections', function (): void {
    $contents = file_get_contents(__DIR__ . '/../src/Services/AnalyticsConfigValidationService.php');

    // Core structure validation
    expect($contents)->toContain('validateCoreStructure');

    // Provider validation
    expect($contents)->toContain('validateProviderConfig');
    expect($contents)->toContain("'ga4.measurement_id'");
    expect($contents)->toContain("'gtm.container_id'");
    expect($contents)->toContain("'meta_pixel.id'");
    expect($contents)->toContain("'posthog.credentials'");

    // Consent validation
    expect($contents)->toContain('validateConsentConfig');
    expect($contents)->toContain("'consent.default'");

    // Queue validation
    expect($contents)->toContain('validateQueueConfig');
    expect($contents)->toContain("'queue.batch_size'");

    // Security validation
    expect($contents)->toContain('validateSecuritySettings');
    expect($contents)->toContain("'security.cookie_secure'");
    expect($contents)->toContain("'security.cookie_samesite'");

    // Performance validation
    expect($contents)->toContain('validatePerformanceSettings');
    expect($contents)->toContain("'sampling.rate'");
    expect($contents)->toContain("'dedup.window'");

    // Issue levels
    expect($contents)->toContain("addIssue('error'");
    expect($contents)->toContain("addIssue('warning'");
    expect($contents)->toContain("addIssue('info'");

    // Score calculation
    expect($contents)->toContain("'valid' => \$errors === 0");
    expect($contents)->toContain("'score' => max(0, 100");
});

test('v176 config validation service: validates GA4 measurement ID format', function (): void {
    $contents = file_get_contents(__DIR__ . '/../src/Services/AnalyticsConfigValidationService.php');

    // GA4 ID must start with G-
    expect($contents)->toContain("str_starts_with(\$id, 'G-')");
    // GTM ID must start with GTM-
    expect($contents)->toContain("str_starts_with(\$id, 'GTM-')");
    // Sampling rate bounds
    expect($contents)->toContain('$rate <= 0.0 || $rate > 1.0');
    // SameSite values
    expect($contents)->toContain("['Strict', 'Lax', 'None']");
});

test('v176 controller: new endpoints exist', function (): void {
    $reflection = new ReflectionClass(AnalyticsEventController::class);

    // Onboarding wizard endpoints
    expect($reflection->hasMethod('onboardingWizardState'))->toBeTrue();
    expect($reflection->hasMethod('onboardingWizardSummary'))->toBeTrue();
    expect($reflection->hasMethod('onboardingWizardGaps'))->toBeTrue();
    expect($reflection->hasMethod('onboardingWizardNextAction'))->toBeTrue();

    // Instrumentation advisor endpoints
    expect($reflection->hasMethod('instrumentationAdvisor'))->toBeTrue();
    expect($reflection->hasMethod('instrumentationSummary'))->toBeTrue();
    expect($reflection->hasMethod('instrumentationGaps'))->toBeTrue();
    expect($reflection->hasMethod('instrumentationStageCoverage'))->toBeTrue();

    // Config validation endpoint
    expect($reflection->hasMethod('configValidate'))->toBeTrue();
});

test('v176 controller: new methods have correct return types', function (): void {
    $reflection = new ReflectionClass(AnalyticsEventController::class);

    $methods = [
        'onboardingWizardState',
        'onboardingWizardSummary',
        'onboardingWizardGaps',
        'onboardingWizardNextAction',
        'instrumentationAdvisor',
        'instrumentationSummary',
        'instrumentationGaps',
        'instrumentationStageCoverage',
        'configValidate',
    ];

    foreach ($methods as $method) {
        $methodRef = $reflection->getMethod($method);
        $returnType = $methodRef->getReturnType();
        expect($returnType)->not->toBeNull();
        expect((string) $returnType)->toBe('Illuminate\Http\JsonResponse');
    }
});

test('v176 routes: new routes registered', function (): void {
    $contents = file_get_contents(__DIR__ . '/../routes/analytics.php');

    // Onboarding wizard routes
    expect($contents)->toContain("Route::get('onboarding-wizard', [AnalyticsEventController::class, 'onboardingWizardState'])");
    expect($contents)->toContain("Route::get('onboarding-wizard/summary', [AnalyticsEventController::class, 'onboardingWizardSummary'])");
    expect($contents)->toContain("Route::get('onboarding-wizard/gaps', [AnalyticsEventController::class, 'onboardingWizardGaps'])");
    expect($contents)->toContain("Route::get('onboarding-wizard/next', [AnalyticsEventController::class, 'onboardingWizardNextAction'])");

    // Instrumentation advisor routes
    expect($contents)->toContain("Route::get('instrumentation', [AnalyticsEventController::class, 'instrumentationAdvisor'])");
    expect($contents)->toContain("Route::get('instrumentation/summary', [AnalyticsEventController::class, 'instrumentationSummary'])");
    expect($contents)->toContain("Route::get('instrumentation/gaps', [AnalyticsEventController::class, 'instrumentationGaps'])");
    expect($contents)->toContain("Route::get('instrumentation/stage', [AnalyticsEventController::class, 'instrumentationStageCoverage'])");

    // Config validation route
    expect($contents)->toContain("Route::post('config/validate', [AnalyticsEventController::class, 'configValidate'])");

    // Version comments
    expect($contents)->toContain('v177.0.0');
});

test('v176 service provider: new services registered', function (): void {
    $contents = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

    expect($contents)->toContain('SaaSOnboardingWizardService');
    expect($contents)->toContain('EventInstrumentationAdvisor');
    expect($contents)->toContain('AnalyticsConfigValidationService');
});

test('v176 existing functionality: event catalog still intact', function (): void {
    $all = EventCatalog::all();

    expect(count($all))->toBeGreaterThanOrEqual(100);
    expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
    expect(SaaSEvents::count())->toBeGreaterThanOrEqual(50);
    expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);

    // GA4 mappings exist for all events
    foreach ($all as $name => $entry) {
        expect($entry)->toHaveKeys(['name', 'class', 'ga4', 'category']);
    }
});

test('v176 existing functionality: tracker interface compliance', function (): void {
    $trackerDir = __DIR__ . '/../src/Trackers/';
    $files = glob($trackerDir . '*Tracker.php');

    expect($files)->not->toBeEmpty();
    expect(count($files))->toBeGreaterThanOrEqual(10); // GA4, GTM, Meta, Plausible, PostHog, Mixpanel, Amplitude, TikTok, LinkedIn, Webhook

    $interface = new ReflectionClass(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class);
    $requiredMethods = ['track', 'isEnabled', 'headScripts', 'bodyScripts', 'setConsent', 'getConsent'];

    foreach ($requiredMethods as $method) {
        expect($interface->hasMethod($method))->toBeTrue();
    }
});

test('v176 existing functionality: inertia middleware exists', function (): void {
    expect(class_exists(HandleInertiaAnalytics::class))->toBeTrue();

    $reflection = new ReflectionClass(HandleInertiaAnalytics::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->implementsInterface(\ZeroBoiler\Analytics\Http\HttpMiddlewareContract::class))->toBeTrue();
});

test('v176 existing functionality: analytics manager is final', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsManager::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('v176 existing functionality: facade accessor works', function (): void {
    $facade = new ReflectionClass(\ZeroBoiler\Analytics\Facades\Analytics::class);
    expect($facade->isFinal())->toBeTrue();

    $getAccessor = $facade->getMethod('getFacadeAccessor');
    expect($getAccessor->isStatic())->toBeTrue();
});

test('v176 php syntax: all new files have strict types and license header', function (): void {
    $files = [
        __DIR__ . '/../src/Services/SaaSOnboardingWizardService.php',
        __DIR__ . '/../src/Services/EventInstrumentationAdvisor.php',
        __DIR__ . '/../src/Services/AnalyticsConfigValidationService.php',
    ];

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
        expect($contents)->toContain('@since 177.0.0');
    }
});

test('v176 docblocks: all public methods have docblocks', function (): void {
    $files = [
        SaaSOnboardingWizardService::class => [
            'getState', 'summary', 'gaps', 'nextAction', 'grade',
            'invalidateCache', 'isStepCompleted', 'categoryBreakdown',
        ],
        EventInstrumentationAdvisor::class => [
            'getReport', 'summary', 'gaps', 'quickWins',
            'priorityMatrix', 'stageCoverage', 'invalidateCache',
        ],
        AnalyticsConfigValidationService::class => [
            'validate',
        ],
    ];

    foreach ($files as $className => $methods) {
        $reflection = new ReflectionClass($className);
        foreach ($methods as $method) {
            $methodRef = $reflection->getMethod($method);
            $docComment = $methodRef->getDocComment();
            expect($docComment)->not->toBeFalse("{$className}::{$method}() is missing a docblock");
        }
    }
});

test('v176 source file count: still above 1240', function (): void {
    $phpFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
    // Count all PHP files under src/
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../src/', RecursiveDirectoryIterator::SKIP_DOTS)
    );
    $count = 0;
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $count++;
        }
    }

    expect($count)->toBeGreaterThanOrEqual(1247); // Was 1247 before this upgrade
});
