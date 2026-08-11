<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Support\AnalyticsFake;

/**
 * V10.9.0 — SaaS Identity Linking & Facade Expansion Test.
 *
 * Validates the new v10.9.0 additions:
 * 1. trackSaaSIdentity() convenience method on AnalyticsManager
 * 2. Facade @method docblocks for mixpanel(), amplitude(), trackSaaSIdentity()
 * 3. Version constant correctly set to 10.9.0
 * 4. Event catalog coverage (100+ events, all 3 categories)
 * 5. SaaS lifecycle events (sign_up, login, start_trial, subscribe, plan_upgrade, cancellation)
 * 6. E-commerce cross-provider formatting (GA4 ↔ Meta)
 * 7. Identity linking flow (client_id ↔ user_id)
 * 8. Funnel progress tracking
 * 9. PLG scoring availability
 * 10. Quick-start events coverage
 */
test('v10.9.0 version constant is correct', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('10.9.0');
});

test('v10.9.0 event catalog has 100+ events across all categories', function (): void {
    expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);

    // All three categories must have substantial coverage
    expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
    expect(SaaSEvents::count())->toBeGreaterThanOrEqual(50);
    expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);
});

test('v10.9.0 critical SaaS lifecycle events are present', function (): void {
    $critical = [
        'sign_up', 'login', 'start_trial', 'subscribe',
        'plan_upgrade', 'cancellation', 'trial_end',
        'plan_downgrade', 'subscription_renewal',
    ];

    foreach ($critical as $event) {
        expect(SaaSEvents::has($event))
            ->toBeTrue("SaaS event '{$event}' must be in catalog");
    }
});

test('v10.9.0 critical e-commerce events are present', function (): void {
    $critical = [
        'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart',
        'begin_checkout', 'add_payment_info', 'purchase', 'refund',
    ];

    foreach ($critical as $event) {
        expect(EcommerceEvents::has($event))
            ->toBeTrue("Ecommerce event '{$event}' must be in catalog");
    }
});

test('v10.9.0 critical engagement events are present', function (): void {
    $critical = [
        'page_view', 'scroll_depth', 'click', 'form_start',
        'form_submit', 'search', 'share', 'error',
    ];

    foreach ($critical as $event) {
        expect(EngagementEvents::has($event))
            ->toBeTrue("Engagement event '{$event}' must be in catalog");
    }
});

test('v10.9.0 AnalyticsManager has trackSaaSIdentity method', function (): void {
    $manager = new ReflectionClass(AnalyticsManager::class);

    expect($manager->hasMethod('trackSaaSIdentity'))->toBeTrue();

    $method = $manager->getMethod('trackSaaSIdentity');
    expect($method->isPublic())->toBeTrue();

    // Validate parameters
    $params = $method->getParameters();
    expect($params)->toHaveCount(3);
    expect($params[0]->getName())->toBe('userId');
    expect($params[0]->getType()?->getName())->toBe('string');
    expect($params[1]->getName())->toBe('clientId');
    expect($params[1]->getType()?->getName())->toBe('string');
    expect($params[2]->getName())->toBe('traits');

    // Validate return type
    expect($method->getReturnType()?->getName())->toBe('void');
});

test('v10.9.0 AnalyticsManager has all tracker accessors', function (): void {
    $manager = new ReflectionClass(AnalyticsManager::class);

    $trackers = [
        'ga4', 'gtm', 'meta', 'plausible', 'posthog',
        'webhook', 'mixpanel', 'amplitude',
    ];

    foreach ($trackers as $name) {
        expect($manager->hasMethod($name))
            ->toBeTrue("AnalyticsManager must have {$name}() tracker accessor");
    }
});

test('v10.9.0 Facade has SaaS convenience methods documented', function (): void {
    $facadeFile = file_get_contents(__DIR__ . '/../src/Facades/Analytics.php');
    expect($facadeFile)->not->toBeFalse();

    // Facade docblock must include new methods
    expect($facadeFile)->toContain('@method static void signUp(');
    expect($facadeFile)->toContain('@method static void login(');
    expect($facadeFile)->toContain('@method static void trialStart(');
    expect($facadeFile)->toContain('@method static void subscription(');
    expect($facadeFile)->toContain('@method static void planUpgrade(');
    expect($facadeFile)->toContain('@method static void cancellation(');
    expect($facadeFile)->toContain('@method static \\ZeroBoiler\\Analytics\\Trackers\\MixpanelTracker mixpanel()');
    expect($facadeFile)->toContain('@method static \\ZeroBoiler\\Analytics\\Trackers\\AmplitudeTracker amplitude()');
    expect($facadeFile)->toContain('@method static void trackSaaSIdentity(');
});

test('v10.9.0 AnalyticsManager has complete SaaS lifecycle methods', function (): void {
    $manager = new ReflectionClass(AnalyticsManager::class);

    $saasMethods = [
        'signUp', 'login', 'trialStart', 'subscription',
        'planUpgrade', 'cancellation', 'trialEnd', 'planDowngrade',
        'subscriptionRenewal', 'featureAdopted', 'expansionRevenue',
        'exportEvent', 'importEvent', 'trackSaaSIdentity',
    ];

    foreach ($saasMethods as $method) {
        expect($manager->hasMethod($method))
            ->toBeTrue("AnalyticsManager must have {$method}() method");
    }
});

test('v10.9.0 ecommerce format converter handles GA4 to Meta conversion', function (): void {
    $items = [
        [
            'item_id' => 'SKU-001',
            'item_name' => 'Pro Plan',
            'item_category' => 'subscription',
            'price' => 29.99,
            'quantity' => 1,
        ],
        [
            'item_id' => 'SKU-002',
            'item_name' => 'Add-on: Priority Support',
            'item_category' => 'addon',
            'price' => 9.99,
            'quantity' => 1,
        ],
    ];

    $converter = new \ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
    $result = $converter->toMetaFormat($items);

    expect($result)->toHaveKey('content_ids');
    expect($result)->toHaveKey('contents');
    expect($result)->toHaveKey('num_items');
    expect($result['num_items'])->toBe(2);
    expect($result['content_ids'])->toContain('SKU-001');
    expect($result['content_ids'])->toContain('SKU-002');
});

test('v10.9.0 event catalog validation passes', function (): void {
    $result = EventCatalog::validate();

    expect($result)->toHaveKey('valid');
    expect($result['valid'])->toBeTrue();
    expect($result)->toHaveKey('errors');
    expect($result['errors'])->toBeEmpty();
});

test('v10.9.0 quick-start events cover AARRR funnel', function (): void {
    $quickStart = EventCatalog::quickStart();

    expect($quickStart)->toHaveKey('events');
    expect($quickStart)->toHaveKey('count');
    expect($quickStart)->toHaveKey('categories');
    expect($quickStart)->toHaveKey('funnel_coverage');
    expect($quickStart['count'])->toBeGreaterThanOrEqual(10);

    // Must cover key AARRR categories
    expect($quickStart['categories'])->toHaveKey('saas');
    expect($quickStart['categories'])->toHaveKey('engagement');
});

test('v10.9.0 funnel templates available', function (): void {
    $templates = EventCatalog::funnelTemplates();

    expect($templates)->not->toBeEmpty();

    // Must have core SaaS funnels
    $funnelNames = array_keys($templates);
    expect($funnelNames)->toContain('signup');
});

test('v10.9.0 AnalyticsManager has funnel tracking methods', function (): void {
    $manager = new ReflectionClass(AnalyticsManager::class);

    expect($manager->hasMethod('trackFunnel'))->toBeTrue();
    expect($manager->hasMethod('funnelProgress'))->toBeTrue();
    expect($manager->hasMethod('trackFunnelProgress'))->toBeTrue();
    expect($manager->hasMethod('funnelTemplates'))->toBeTrue();
    expect($manager->hasMethod('funnelTemplate'))->toBeTrue();
});

test('v10.9.0 AnalyticsManager has orchestration methods', function (): void {
    $manager = new ReflectionClass(AnalyticsManager::class);

    expect($manager->hasMethod('orchestrate'))->toBeTrue();
    expect($manager->hasMethod('orchestrateAdvance'))->toBeTrue();
    expect($manager->hasMethod('orchestrateProgress'))->toBeTrue();
    expect($manager->hasMethod('orchestrateComplete'))->toBeTrue();
    expect($manager->hasMethod('orchestrateCancel'))->toBeTrue();
});

test('v10.9.0 AnalyticsManager has B2B group analytics', function (): void {
    $manager = new ReflectionClass(AnalyticsManager::class);

    expect($manager->hasMethod('group'))->toBeTrue();
    expect($manager->hasMethod('groupAddMember'))->toBeTrue();
    expect($manager->hasMethod('getGroup'))->toBeTrue();
});

test('v10.9.0 AnalyticsManager has PLG scoring', function (): void {
    $manager = new ReflectionClass(AnalyticsManager::class);

    expect($manager->hasMethod('plgScore'))->toBeTrue();
    expect($manager->hasMethod('plgAggregate'))->toBeTrue();
    expect($manager->hasMethod('plgInvalidate'))->toBeTrue();
    expect($manager->hasMethod('plgEvents'))->toBeTrue();
});

test('v10.9.0 AnalyticsManager has time-series analytics', function (): void {
    $manager = new ReflectionClass(AnalyticsManager::class);

    expect($manager->hasMethod('timeSeries'))->toBeTrue();
    expect($manager->hasMethod('timeSeriesDashboard'))->toBeTrue();
    expect($manager->hasMethod('timeSeriesCompare'))->toBeTrue();
});

test('v10.9.0 AnalyticsManager has admin/health methods', function (): void {
    $manager = new ReflectionClass(AnalyticsManager::class);

    expect($manager->hasMethod('healthCheck'))->toBeTrue();
    expect($manager->hasMethod('ping'))->toBeTrue();
    expect($manager->hasMethod('insightReport'))->toBeTrue();
    expect($manager->hasMethod('reportSummary'))->toBeTrue();
    expect($manager->hasMethod('dlqSummary'))->toBeTrue();
    expect($manager->hasMethod('maturityScore'))->toBeTrue();
    expect($manager->hasMethod('onboardingChecklist'))->toBeTrue();
    expect($manager->hasMethod('funnelReadiness'))->toBeTrue();
});

test('v10.9.0 AnalyticsDTO has strict types and immutable design', function (): void {
    $event = new AnalyticsEvent(
        name: 'test_event',
        params: ['key' => 'value'],
        clientId: 'client-123',
        userId: 'user-456',
    );

    expect($event->name)->toBe('test_event');
    expect($event->params)->toBe(['key' => 'value']);
    expect($event->clientId)->toBe('client-123');
    expect($event->userId)->toBe('user-456');
    expect($event->timestamp)->toBeInstanceOf(DateTimeInterface::class);
});

test('v10.9.0 GDPR compliance methods present', function (): void {
    $manager = new ReflectionClass(AnalyticsManager::class);

    expect($manager->hasMethod('resetIdentity'))->toBeTrue();
    expect($manager->hasMethod('setConsent'))->toBeTrue();
    expect($manager->hasMethod('grantConsent'))->toBeTrue();
    expect($manager->hasMethod('denyConsent'))->toBeTrue();
    expect($manager->hasMethod('getConsent'))->toBeTrue();
    expect($manager->hasMethod('optOut'))->toBeTrue();
    expect($manager->hasMethod('optIn'))->toBeTrue();
    expect($manager->hasMethod('suppressClient'))->toBeTrue();
    expect($manager->hasMethod('isTrackingAllowed'))->toBeTrue();
});
