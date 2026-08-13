<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\AnalyticsEventOccurred;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\AnalyticsDataService;
use ZeroBoiler\Analytics\Services\EventTaxonomyService;
use ZeroBoiler\Analytics\Tracking\TenantAnalyticsContext;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Http\HttpMiddlewareContract;

/**
 * V76.0.0 — Industry Standard SaaS Analytics Maturity Test.
 *
 * Validates v76.0.0 features: AnalyticsDataService (DAU/MAU, revenue trends,
 * dashboard queries), EventTaxonomyService (tag-based classification),
 * TenantAnalyticsContext (multi-tenant isolation), AnalyticsEventOccurred
 * Laravel event, config expansion, route additions, and full version sweep.
 */
test('v76.0.0: version is 76.0.0 everywhere', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('76.0.0');

    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('76.0.0');

    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain('@version 76.0.0');
    expect($js)->toContain("'76.0.0'");

    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelte)->toContain('@version 76.0.0');

    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-76.0.0');
});

test('v76.0.0: composer.json requires PHP 8.5+ and Laravel 13', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['require']['illuminate/contracts'])->toContain('^13');
});

test('v76.0.0: AnalyticsDataService class exists with required methods', function (): void {
    expect(class_exists(AnalyticsDataService::class))->toBeTrue();

    $ref = new ReflectionClass(AnalyticsDataService::class);
    $methods = array_map(fn (\ReflectionMethod $m
    ): string => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));

    // Active user tracking
    expect($methods)->toContain('recordActiveUser');
    expect($methods)->toContain('getDAU');
    expect($methods)->toContain('getMAU');
    expect($methods)->toContain('getStickiness');

    // Event counters
    expect($methods)->toContain('incrementEvent');
    expect($methods)->toContain('getEventCount');
    expect($methods)->toContain('getTopEvents');
    expect($methods)->toContain('getDailyTotal');

    // Revenue tracking
    expect($methods)->toContain('recordRevenue');
    expect($methods)->toContain('getDailyRevenue');
    expect($methods)->toContain('getMonthlyRevenue');
    expect($methods)->toContain('getRevenueBySource');

    // Provider stats
    expect($methods)->toContain('recordProviderDispatch');
    expect($methods)->toContain('getProviderStats');

    // Funnel
    expect($methods)->toContain('recordFunnelStep');
    expect($methods)->toContain('getFunnelConversion');

    // Dashboard
    expect($methods)->toContain('getDashboardSummary');
});

test('v76.0.0: AnalyticsDataService DAU/MAU structure', function (): void {
    $cache = app('cache');
    $metrics = new AnalyticsMetrics;
    $service = new AnalyticsDataService($cache, $metrics, 3600, 86400);

    $dau = $service->getDAU();
    expect($dau)->toHaveKeys(['client_count', 'user_count', 'date']);
    expect($dau['date'])->toBe(date('Y-m-d'));

    $mau = $service->getMAU();
    expect($mau)->toHaveKeys(['user_count', 'month']);
    expect($mau['month'])->toBe(date('Y-m'));

    $stickiness = $service->getStickiness();
    expect($stickiness)->toHaveKeys(['dau', 'mau', 'stickiness']);
    expect($stickiness['stickiness'])->toBeFloat();
});

test('v76.0.0: AnalyticsDataService dashboard summary structure', function (): void {
    $cache = app('cache');
    $metrics = new AnalyticsMetrics;
    $service = new AnalyticsDataService($cache, $metrics);

    $summary = $service->getDashboardSummary();
    expect($summary)->toHaveKeys([
        'dau', 'mau', 'stickiness',
        'daily_revenue', 'monthly_revenue',
        'top_events', 'total_events_today',
        'provider_stats', 'generated_at',
    ]);
});

test('v76.0.0: AnalyticsDataService revenue tracking', function (): void {
    $cache = app('cache');
    $metrics = new AnalyticsMetrics;
    $service = new AnalyticsDataService($cache, $metrics);

    $service->recordRevenue(99.99, 'USD', 'purchase');
    $service->recordRevenue(49.50, 'USD', 'subscribe');

    $daily = $service->getDailyRevenue('USD');
    expect($daily)->toHaveKeys(['amount', 'currency', 'events', 'date']);
    expect($daily['amount'])->toBeGreaterThanOrEqual(0.0);

    $bySource = $service->getRevenueBySource();
    expect($bySource)->toBeArray();
});

test('v76.0.0: AnalyticsDataService provider stats structure', function (): void {
    $cache = app('cache');
    $metrics = new AnalyticsMetrics;
    $service = new AnalyticsDataService($cache, $metrics);

    $stats = $service->getProviderStats();
    expect($stats)->toHaveKey('providers');

    foreach (['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook'] as $provider) {
        expect($stats['providers'])->toHaveKey($provider);
        expect($stats['providers'][$provider])->toHaveKeys(['success', 'failure', 'total', 'success_rate']);
    }
});

test('v76.0.0: AnalyticsDataService funnel conversion', function (): void {
    $cache = app('cache');
    $metrics = new AnalyticsMetrics;
    $service = new AnalyticsDataService($cache, $metrics);

    $funnel = $service->getFunnelConversion('signup', ['landed', 'registered', 'confirmed']);
    expect($funnel)->toHaveKeys(['funnel', 'date', 'steps', 'overall_conversion']);
    expect($funnel['steps'])->toHaveCount(3);
    expect($funnel['overall_conversion'])->toBeFloat();
});

test('v76.0.0: EventTaxonomyService class exists with required methods', function (): void {
    expect(class_exists(EventTaxonomyService::class))->toBeTrue();

    $ref = new ReflectionClass(EventTaxonomyService::class);
    $methods = array_map(fn (\ReflectionMethod $m
    ): string => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));

    expect($methods)->toContain('getTags');
    expect($methods)->toContain('hasTag');
    expect($methods)->toContain('addTags');
    expect($methods)->toContain('removeTags');
    expect($methods)->toContain('getEventsWithTag');
    expect($methods)->toContain('getAllTags');
    expect($methods)->toContain('getTagsByGroup');
    expect($methods)->toContain('getTagSummary');
    expect($methods)->toContain('autoClassify');
    expect($methods)->toContain('getEventsWithAllTags');
    expect($methods)->toContain('getEventsWithAnyTag');
});

test('v76.0.0: EventTaxonomyService auto-classification', function (): void {
    $cache = app('cache');
    $service = new EventTaxonomyService($cache);

    $result = $service->autoClassify();
    expect($result)->toHaveKeys(['classified', 'tags_applied', 'events']);
    expect($result['classified'])->toBeGreaterThanOrEqual(100);
    expect($result['tags_applied'])->toBeGreaterThanOrEqual(200);

    // Check specific event tags
    $purchaseTags = $service->getTags('purchase');
    expect($purchaseTags)->toContain('revenue');
    expect($purchaseTags)->toContain('conversion');

    $signupTags = $service->getTags('sign_up');
    expect($signupTags)->toContain('acquisition');

    $loginTags = $service->getTags('login');
    expect($loginTags)->toContain('authentication');

    $trialTags = $service->getTags('start_trial');
    expect($trialTags)->toContain('onboarding');
    expect($trialTags)->toContain('conversion');
});

test('v76.0.0: EventTaxonomyService tag groups', function (): void {
    $cache = app('cache');
    $service = new EventTaxonomyService($cache);
    $service->autoClassify();

    $groups = $service->getTagsByGroup();
    expect($groups)->toBeArray();
    expect($groups)->toHaveKey('ecommerce');

    $summary = $service->getTagSummary();
    expect($summary)->toBeArray();

    $allTags = $service->getAllTags();
    expect($allTags)->not->toBeEmpty();
});

test('v76.0.0: EventTaxonomyService dynamic tags', function (): void {
    $cache = app('cache');
    $service = new EventTaxonomyService($cache);

    $service->addTags('custom_event', ['team_alpha', 'marketing']);
    expect($service->getTags('custom_event'))->toContain('team_alpha');
    expect($service->hasTag('custom_event', 'marketing'))->toBeTrue();

    $service->removeTags('custom_event', ['marketing']);
    expect($service->hasTag('custom_event', 'marketing'))->toBeFalse();

    $tagEvents = $service->getEventsWithTag('team_alpha');
    expect($tagEvents)->toContain('custom_event');
});

test('v76.0.0: EventTaxonomyService multi-tag filtering', function (): void {
    $cache = app('cache');
    $service = new EventTaxonomyService($cache);
    $service->autoClassify();

    // Find events with both 'revenue' and 'conversion' tags (AND logic)
    $allRevenue = $service->getEventsWithTag('revenue');
    $allConversion = $service->getEventsWithTag('conversion');

    $intersection = $service->getEventsWithAllTags(['revenue', 'conversion']);
    expect($intersection)->not->toBeEmpty();

    // OR logic should return more events
    $orResult = $service->getEventsWithAnyTag(['revenue', 'compliance']);
    expect(count($orResult))->toBeGreaterThanOrEqual(count($allRevenue));
});

test('v76.0.0: AnalyticsEventOccurred Laravel event exists', function (): void {
    expect(class_exists(AnalyticsEventOccurred::class))->toBeTrue();

    $ref = new ReflectionClass(AnalyticsEventOccurred::class);
    expect($ref->isFinal())->toBeTrue();

    $event = new AnalyticsEvent(
        name: 'purchase',
        params: ['value' => 99.99],
    );

    $occurred = new AnalyticsEventOccurred(
        $event,
        ['ga4' => true, 'meta' => true, 'posthog' => true],
        ['request_id' => 'req-123'],
    );

    expect($occurred->analyticsEvent->name)->toBe('purchase');
    expect($occurred->dispatchedTo)->toHaveKey('ga4');
    expect($occurred->context)->toHaveKey('request_id');
});

test('v76.0.0: TenantAnalyticsContext class exists with required methods', function (): void {
    expect(class_exists(TenantAnalyticsContext::class))->toBeTrue();

    $ref = new ReflectionClass(TenantAnalyticsContext::class);
    $methods = array_map(fn (
ReflectionMethod $m): string => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));

    expect($methods)->toContain('setTenant');
    expect($methods)->toContain('clearTenant');
    expect($methods)->toContain('getTenantId');
    expect($methods)->toContain('getTenantName');
    expect($methods)->toContain('hasTenant');
    expect($methods)->toContain('eventContext');
    expect($methods)->toContain('withinTenant');
    expect($methods)->toContain('resolveTenant');
    expect($methods)->toContain('incrementTenantEventCount');
    expect($methods)->toContain('getTenantStats');
    expect($methods)->toContain('recordTenantRevenue');
    expect($methods)->toContain('getTenantRevenue');
});

test('v76.0.0: TenantAnalyticsContext basic operations', function (): void {
    $cache = app('cache');
    $context = new TenantAnalyticsContext($cache);

    expect($context->hasTenant())->toBeFalse();

    $context->setTenant('tenant-123', 'Acme Corp', ['plan' => 'pro', 'region' => 'us-east']);

    expect($context->hasTenant())->toBeTrue();
    expect($context->getTenantId())->toBe('tenant-123');
    expect($context->getTenantName())->toBe('Acme Corp');
    expect($context->getMetaValue('plan'))->toBe('pro');

    $eventCtx = $context->eventContext();
    expect($eventCtx)->toHaveKey('tenant_id');
    expect($eventCtx)->toHaveKey('tenant_name');
    expect($eventCtx['tenant_plan'])->toBe('pro');

    $context->clearTenant();
    expect($context->hasTenant())->toBeFalse();
    expect($context->eventContext())->toBeEmpty();
});

test('v76.0.0: TenantAnalyticsContext withinTenant scope', function (): void {
    $cache = app('cache');
    $context = new TenantAnalyticsContext($cache);

    $context->setTenant('original-tenant', 'Original');

    $result = $context->withinTenant('scoped-tenant', 'Scoped', function () use ($context): string {
        expect($context->getTenantId())->toBe('scoped-tenant');
        return $context->getTenantId();
    });

    expect($result)->toBe('scoped-tenant');
    expect($context->getTenantId())->toBe('original-tenant');
});

test('v76.0.0: config has new sections', function (): void {
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->not->toBeFalse();

    $newSections = [
        'data_service', 'taxonomy', 'tenant', 'broadcast',
    ];

    foreach ($newSections as $section) {
        expect($config)->toContain("'{$section}' => [");
    }
});

test('v76.0.0: routes include dashboard, taxonomy, and tenant endpoints', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
    expect($routes)->not->toBeFalse();

    // Dashboard endpoints
    expect($routes)->toContain("Route::get('dashboard'");
    expect($routes)->toContain("Route::get('dashboard/dau'");
    expect($routes)->toContain("Route::get('dashboard/mau'");
    expect($routes)->toContain("Route::get('dashboard/stickiness'");
    expect($routes)->toContain("Route::get('dashboard/revenue'");
    expect($routes)->toContain("Route::get('dashboard/funnel/{funnelName}'");

    // Taxonomy endpoints
    expect($routes)->toContain("Route::get('taxonomy/tags'");
    expect($routes)->toContain("Route::get('taxonomy/groups'");
    expect($routes)->toContain("Route::post('taxonomy/classify'");
    expect($routes)->toContain("Route::get('taxonomy/event/{eventName}'");

    // Tenant endpoints
    expect($routes)->toContain("Route::get('tenant/{tenantId}/stats'");
    expect($routes)->toContain("Route::get('tenant/{tenantId}/revenue'");

    // Route count 150+
    preg_match_all("/Route::(get|post|put|patch|delete)\\\\(/", $routes, $matches);
    expect(count($matches[0]))->toBeGreaterThanOrEqual(150);
});

test('v76.0.0: ServiceProvider registers new services', function (): void {
    $provider = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);

    expect($provider)->toHaveMethod('register');

    $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($content)->toContain('AnalyticsDataService::class');
    expect($content)->toContain('EventTaxonomyService::class');
    expect($content)->toContain('TenantAnalyticsContext::class');
});

test('v76.0.0: Inertia middleware still implements HttpMiddlewareContract', function (): void {
    $ref = new ReflectionClass(HandleInertiaAnalytics::class);
    expect($ref->implementsInterface(HttpMiddlewareContract::class))->toBeTrue();
});

test('v76.0.0: event catalog still validates cleanly', function (): void {
    $result = EventCatalog::validate();
    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();

    $summary = EventCatalog::summary();
    expect($summary['total'])->toBeGreaterThanOrEqual(100);
});

test('v76.0.0: services layer has 85+ files', function (): void {
    $servicesDir = __DIR__ . '/../src/Services';
    $serviceFiles = glob($servicesDir . '/*.php');
    expect($serviceFiles)->not->toBeEmpty();
    expect(count($serviceFiles))->toBeGreaterThanOrEqual(85);
});

test('v76.0.0: test file count is 165+', function (): void {
    $testDir = __DIR__;
    $testFiles = glob($testDir . '/*Test.php');
    $featureTestFiles = glob($testDir . '/Feature/**/*.php', GLOB_ERR);
    if ($featureTestFiles === false) {
        $featureTestFiles = [];
    }

    $total = count($testFiles) + count($featureTestFiles);
    expect($total)->toBeGreaterThanOrEqual(165);
});
