<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber;
use ZeroBoiler\Analytics\Services\SaaSLifecycleObserver;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;

/**
 * V94 — Svelte Composables + SaaS Lifecycle API Integration Test.
 *
 * Validates:
 * - useAnalytics.svelte.js composable exists and exports
 * - useLifecycle.svelte.js composable exists and exports
 * - analytics.d.ts TypeScript definitions for lifecycle composables
 * - Lifecycle API endpoint (GET /api/analytics/lifecycle?identity=X)
 * - SaaS lifecycle observer signals and funnel tracking
 * - Version consistency across all artifacts
 */
test('v94.0.0 version is set correctly', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('94.0.0');

    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('94.0.0');
});

test('useAnalytics.svelte.js composable file exists and exports useAnalytics', function (): void {
    $path = __DIR__ . '/../resources/js/useAnalytics.svelte.js';
    expect(file_exists($path))->toBeTrue('useAnalytics.svelte.js should exist');

    $content = file_get_contents($path);
    expect($content)->toContain('export function useAnalytics');
    expect($content)->toContain('export function analyticsStore');
    expect($content)->toContain('export default useAnalytics');
});

test('useAnalytics.svelte.js composable exports all required stores and methods', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');

    // Reactive stores
    expect($content)->toContain('ready');
    expect($content)->toContain('trackingId');
    expect($content)->toContain('userId');
    expect($content)->toContain('authStateChanged');

    // Core tracking methods
    expect($content)->toContain('trackEvent');
    expect($content)->toContain('trackPageView');
    expect($content)->toContain('trackEcommerce');
    expect($content)->toContain('identify');
    expect($content)->toContain('updateConsent');
    expect($content)->toContain('flushQueue');

    // Lifecycle features
    expect($content)->toContain('authStateChanged');
    expect($content)->toContain('autoIdentify');
    expect($content)->toContain('lifecycleAware');
    expect($content)->toContain('autoFlush');
});

test('useAnalytics.svelte.js composable handles Inertia page navigation', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');

    expect($content)->toContain('@inertiajs/svelte');
    expect($content)->toContain('page.subscribe');
    expect($content)->toContain('zbAnalytics');
    expect($content)->toContain('setupPageWatcher');
});

test('useLifecycle.svelte.js composable file exists and exports useLifecycle', function (): void {
    $path = __DIR__ . '/../resources/js/useLifecycle.svelte.js';
    expect(file_exists($path))->toBeTrue('useLifecycle.svelte.js should exist');

    $content = file_get_contents($path);
    expect($content)->toContain('export function useLifecycle');
    expect($content)->toContain('export default useLifecycle');
});

test('useLifecycle.svelte.js composable exports all lifecycle stores', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/useLifecycle.svelte.js');

    // Core lifecycle stores
    expect($content)->toContain('activationScore');
    expect($content)->toContain('churnRisk');
    expect($content)->toContain('funnelProgress');
    expect($content)->toContain('featureAdoption');
    expect($content)->toContain('sessionEngagement');
    expect($content)->toContain('expansionMomentum');
    expect($content)->toContain('lifecycleLoaded');

    // API fetch
    expect($content)->toContain('fetchLifecycle');
    expect($content)->toContain('refresh');
    expect($content)->toContain('stopAutoRefresh');

    // Derived stores
    expect($content)->toContain('activationGrade');
    expect($content)->toContain('churnLevel');
    expect($content)->toContain('funnelCompletion');

    // Helper methods
    expect($content)->toContain('isActive');
    expect($content)->toContain('isAtRisk');

    // Constants
    expect($content)->toContain('SAAS_FUNNEL_STEPS');
    expect($content)->toContain('ACTIVATION_WEIGHTS');
    expect($content)->toContain('CHURN_WEIGHTS');
});

test('useLifecycle.svelte.js composable handles SaaS funnel steps', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/useLifecycle.svelte.js');

    // Industry-standard SaaS funnel steps
    expect($content)->toContain("'sign_up'");
    expect($content)->toContain("'email_verified'");
    expect($content)->toContain("'first_login'");
    expect($content)->toContain("'trial_start'");
    expect($content)->toContain("'first_feature'");
    expect($content)->toContain("'team_created'");
    expect($content)->toContain("'integration_connected'");
    expect($content)->toContain("'subscription'");
    expect($content)->toContain("'plan_upgrade'");
    expect($content)->toContain("'activated'");
});

test('useLifecycle.svelte.js composable fetches from analytics API', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/useLifecycle.svelte.js');

    expect($content)->toContain('apiBase');
    expect($content)->toContain('/lifecycle?identity=');
    expect($content)->toContain('fetch(');
    expect($content)->toContain('application/json');
    expect($content)->toContain('credentials');
});

test('useLifecycle.svelte.js composable score grading functions', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/useLifecycle.svelte.js');

    // Score to grade conversion
    expect($content)->toContain('scoreToGrade');
    expect($content)->toContain("'A'");
    expect($content)->toContain("'B'");
    expect($content)->toContain("'C'");
    expect($content)->toContain("'D'");
    expect($content)->toContain("'F'");

    // Churn risk levels
    expect($content)->toContain('riskLevel');
    expect($content)->toContain("'critical'");
    expect($content)->toContain("'high'");
    expect($content)->toContain("'medium'");
    expect($content)->toContain("'low'");

    // Churn recommendations
    expect($content)->toContain('churnRecommendation');
    expect($content)->toContain('Immediate intervention');
});

test('analytics.d.ts contains useAnalytics TypeScript types', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');

    expect($content)->toContain('UseAnalyticsOptions');
    expect($content)->toContain('UseAnalyticsReturn');
    expect($content)->toContain('export function useAnalytics');
    expect($content)->toContain('export function analyticsStore');

    // Stores
    expect($content)->toContain('ready: Writable<boolean>');
    expect($content)->toContain('trackingId: Writable<string | null>');
    expect($content)->toContain('userId: Writable<string | null>');
    expect($content)->toContain('authStateChanged: Writable<boolean>');

    // Methods
    expect($content)->toContain('track: (name: string, params?: Record<string, unknown>) => Promise<void>');
    expect($content)->toContain('identify: (userId?: string | null) => Promise<void>');
    expect($content)->toContain('flush: () => Promise<void>');
});

test('analytics.d.ts contains useLifecycle TypeScript types', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');

    // Interfaces
    expect($content)->toContain('UseLifecycleOptions');
    expect($content)->toContain('UseLifecycleReturn');
    expect($content)->toContain('ActivationScoreData');
    expect($content)->toContain('ChurnRiskData');
    expect($content)->toContain('FunnelProgressData');
    expect($content)->toContain('FeatureAdoptionData');
    expect($content)->toContain('SessionEngagementData');
    expect($content)->toContain('ExpansionMomentumData');
    expect($content)->toContain('LifecycleApiResponse');

    // Exports
    expect($content)->toContain('export function useLifecycle');

    // Churn risk levels in type
    expect($content)->toContain("'critical' | 'high' | 'medium' | 'low'");

    // Lifecycle API response fields
    expect($content)->toContain('activation_score');
    expect($content)->toContain('churn_risk_score');
    expect($content)->toContain('funnel_progress');
    expect($content)->toContain('features_used');
    expect($content)->toContain('expansion_momentum');
});

test('analytics.d.ts contains all Svelte composable types', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');

    // useAnalyticsConfig
    expect($content)->toContain('export function useAnalyticsConfig');
    expect($content)->toContain('export function useConsentState');
    expect($content)->toContain('export function useMaturity');
    expect($content)->toContain('export function useFunnelReadiness');

    // usePerformanceTracker
    expect($content)->toContain('UsePerformanceTrackerOptions');
    expect($content)->toContain('UsePerformanceTrackerReturn');
    expect($content)->toContain('export function usePerformanceTracker');
});

test('lifecycle API route is registered', function (): void {
    $content = file_get_contents(__DIR__ . '/../routes/analytics.php');

    expect($content)->toContain("Route::get('lifecycle', [AnalyticsEventController::class, 'lifecycle'])");
    expect($content)->toContain("Route::get('lifecycle/subscriber', [AnalyticsEventController::class, 'lifecycleSubscriber'])");
    expect($content)->toContain('SaaS Lifecycle Analytics (v94.0.0)');
});

test('lifecycle API controller supports identity parameter', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');

    // The lifecycle method now accepts Request and checks for identity param
    expect($content)->toContain('public function lifecycle(Request $request)');
    expect($content)->toContain("->query('identity')");
    expect($content)->toContain('SaaSLifecycleObserver');
    expect($content)->toContain('getSignals');
});

test('SaaS lifecycle observer provides correct signal structure', function (): void {
    $defaultSignals = SaaSLifecycleObserver::trialWeights();

    // Activation weights exist for key lifecycle events
    expect($defaultSignals)->toHaveKey('trial_start');
    expect($defaultSignals)->toHaveKey('login');
    expect($defaultSignals)->toHaveKey('feature_used');
    expect($defaultSignals)->toHaveKey('subscription');
    expect($defaultSignals)->toHaveKey('plan_upgrade');
    expect($defaultSignals)->toHaveKey('trial_converted');

    // Weights are in valid range
    foreach ($defaultSignals as $event => $weight) {
        expect($weight)->toBeGreaterThanOrEqual(0);
        expect($weight)->toBeLessThanOrEqual(100);
    }
});

test('SaaS lifecycle observer provides churn risk weights', function (): void {
    $weights = SaaSLifecycleObserver::churnRiskWeights();

    expect($weights)->toHaveKey('support_ticket');
    expect($weights)->toHaveKey('feature_limit_reached');
    expect($weights)->toHaveKey('billing_retry');
    expect($weights)->toHaveKey('downgrade_visit');
    expect($weights)->toHaveKey('reduced_usage');
    expect($weights)->toHaveKey('error');

    foreach ($weights as $indicator => $weight) {
        expect($weight)->toBeGreaterThan(0);
    }
});

test('SaaS lifecycle observer provides trial step map', function (): void {
    $stepMap = SaaSLifecycleObserver::trialStepMap();

    expect($stepMap)->toBeArray();
    expect(count($stepMap))->toBeGreaterThanOrEqual(6);

    // Key steps exist
    expect($stepMap)->toHaveKey('trial_start');
    expect($stepMap)->toHaveKey('login');
    expect($stepMap)->toHaveKey('feature_used');
    expect($stepMap)->toHaveKey('subscription');
});

test('SaaS lifecycle observer default signals structure', function (): void {
    $observerClass = new \ReflectionClass(SaaSLifecycleObserver::class);

    // Class is final
    expect($observerClass->isFinal())->toBeTrue();
});

test('event catalog contains SaaS lifecycle events', function (): void {
    $saasEvents = SaaSEvents::all();

    // Core SaaS lifecycle events must exist
    expect($saasEvents)->toHaveKey('sign_up');
    expect($saasEvents)->toHaveKey('login');
    expect($saasEvents)->toHaveKey('start_trial');
    expect($saasEvents)->toHaveKey('subscription');
    expect($saasEvents)->toHaveKey('plan_upgrade');
    expect($saasEvents)->toHaveKey('cancellation');
    expect($saasEvents)->toHaveKey('trial_converted');

    // Each event must have GA4 mapping
    foreach (['sign_up', 'login', 'start_trial', 'subscription', 'plan_upgrade'] as $name) {
        expect($saasEvents[$name]['ga4'])->toBeString();
    }
});

test('lifecycle config section contains all required keys', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';
    $lifecycle = $config['analytics']['lifecycle'] ?? [];

    expect($lifecycle)->toHaveKey('enabled');
    expect($lifecycle)->toHaveKey('events');
    expect($lifecycle)->toHaveKey('custom_mappings');
    expect($lifecycle)->toHaveKey('override_defaults');
    expect($lifecycle)->toHaveKey('queue_events');

    // Events should be boolean toggles
    foreach ($lifecycle['events'] as $key => $value) {
        expect(is_bool($value))->toBeTrue("Lifecycle event toggle '{$key}' must be boolean");
    }
});

test('lifecycle config event toggles cover all DEFAULT_MAPPINGS keys', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';
    $lifecycleEvents = $config['analytics']['lifecycle']['events'] ?? [];

    // Minimum expected lifecycle event toggles
    $expectedKeys = [
        'auth.login',
        'auth.register',
        'auth.logout',
        'subscription.created',
        'subscription.upgraded',
        'subscription.cancelled',
        'trial.started',
        'trial.ended',
        'feature.used',
        'feature.limit_reached',
        'order.completed',
        'order.refunded',
        'form.submitted',
        'search.performed',
        'error.occurred',
        'account.activated',
        'account.deactivated',
        'team.created',
        'team.member_joined',
        'billing.payment_succeeded',
        'billing.payment_failed',
        'consent.granted',
        'consent.withdrawn',
        'gdpr.data_subject_access_request',
        'gdpr.data_erasure_completed',
        'plan.changed',
        'trial.converted',
        'trial.expired',
        'sla.breach',
        'feature.adopted',
        'revenue.expansion',
        'subscription.value_changed',
        'usage.quota_reached',
        'billing.retry',
        'subscription.paused',
        'workspace.created',
        'milestone.reached',
    ];

    foreach ($expectedKeys as $key) {
        expect(array_key_exists($key, $lifecycleEvents))
            ->toBe(true, "Missing lifecycle event toggle: {$key}");
    }
});

test('version consistency across all artifacts', function (): void {
    $phpVersion = AnalyticsEvent::VERSION;
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    $composerVersion = $composer['version'];

    $dtsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    preg_match('/@version (\d+\.\d+\.\d+)/', $dtsContent, $dtsMatch);
    $dtsVersion = $dtsMatch[1] ?? 'unknown';

    $jsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    preg_match('/@version (\d+\.\d+\.\d+)/', $jsContent, $jsMatch);
    $jsVersion = $jsMatch[1] ?? 'unknown';

    expect($phpVersion)->toBe('94.0.0');
    expect($composerVersion)->toBe('94.0.0');
    expect($dtsVersion)->toBe('94.0.0');
    expect($jsVersion)->toBe('94.0.0');

    // All must be identical
    expect($phpVersion)->toBe($composerVersion);
    expect($phpVersion)->toBe($dtsVersion);
    expect($phpVersion)->toBe($jsVersion);
});

test('event catalog is valid with no errors', function (): void {
    $result = EventCatalog::validate();

    expect($result['valid'])->toBeTrue('Event catalog should be valid');
    expect($result['errors'])->toBeEmpty();
});

test('event catalog has 100+ events across all categories', function (): void {
    $count = EventCatalog::count();

    expect($count)->toBeGreaterThanOrEqual(100);
});

test('ecommerce events exist with GA4 and Meta mappings', function (): void {
    $ecommerce = EcommerceEvents::all();

    // Core ecommerce events
    expect($ecommerce)->toHaveKey('view_item');
    expect($ecommerce)->toHaveKey('add_to_cart');
    expect($ecommerce)->toHaveKey('purchase');
    expect($ecommerce)->toHaveKey('refund');

    // GA4 mappings
    expect($ecommerce['view_item']['ga4'])->toBeString();
    expect($ecommerce['add_to_cart']['ga4'])->toBeString();
    expect($ecommerce['purchase']['ga4'])->toBeString();
    expect($ecommerce['refund']['ga4'])->toBeString();

    // Meta Pixel mappings (at least for purchase)
    expect($ecommerce['purchase']['meta'])->toBe('Purchase');
    expect($ecommerce['refund']['meta'])->toBe('Refund');
});

test('engagement events cover all industry-standard types', function (): void {
    $engagement = EngagementEvents::all();

    // Core engagement events
    expect($engagement)->toHaveKey('page_view');
    expect($engagement)->toHaveKey('scroll_depth');
    expect($engagement)->toHaveKey('click');
    expect($engagement)->toHaveKey('form_start');
    expect($engagement)->toHaveKey('form_submit');
    expect($engagement)->toHaveKey('search');
    expect($engagement)->toHaveKey('share');
    expect($engagement)->toHaveKey('error');
});

test('all Svelte composable files exist', function (): void {
    $composables = [
        'resources/js/useAnalytics.svelte.js',
        'resources/js/useAnalyticsConfig.svelte.js',
        'resources/js/usePerformanceTracker.svelte.js',
        'resources/js/useLifecycle.svelte.js',
    ];

    foreach ($composables as $relativePath) {
        $fullPath = __DIR__ . '/../' . $relativePath;
        expect(file_exists($fullPath))->toBeTrue("{$relativePath} should exist");

        $content = file_get_contents($fullPath);
        expect(strlen($content))->toBeGreaterThan(500, "{$relativePath} should not be empty");
    }
});

test('SaaS lifecycle observer forget method exists for GDPR erasure', function (): void {
    $reflection = new \ReflectionClass(SaaSLifecycleObserver::class);

    expect($reflection->hasMethod('forget'))->toBeTrue();
    expect($reflection->hasMethod('flush'))->toBeTrue();
    expect($reflection->hasMethod('getSignals'))->toBeTrue();
    expect($reflection->hasMethod('activationScore'))->toBeTrue();
    expect($reflection->hasMethod('churnRisk'))->toBeTrue();
    expect($reflection->hasMethod('record'))->toBeTrue();
    expect($reflection->hasMethod('aggregateMetrics'))->toBeTrue();
});

test('useLifecycle.svelte.js composable auto-refresh support', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/useLifecycle.svelte.js');

    expect($content)->toContain('autoFetch');
    expect($content)->toContain('refreshIntervalMs');
    expect($content)->toContain('setInterval');
    expect($content)->toContain('clearInterval');
    expect($content)->toContain('stopAutoRefresh');
});

test('useAnalytics.svelte.js version is 94.0.0', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');

    expect($content)->toContain('@version 94.0.0');
});

test('useLifecycle.svelte.js version is 94.0.0', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/useLifecycle.svelte.js');

    expect($content)->toContain('@version 94.0.0');
});
