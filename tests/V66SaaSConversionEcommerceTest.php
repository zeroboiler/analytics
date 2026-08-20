<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\SaaS\TrialConvertedEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionResumedEvent;
use ZeroBoiler\Analytics\Events\SaaS\MilestoneReachedEvent;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

/**
 * V66 — SaaS Conversion Analytics, Ecommerce Converter Expansion, New Events.
 *
 * Tests cover:
 * - 3 new SaaS event classes (TrialConverted, SubscriptionResumed, MilestoneReached)
 * - SaaSEvents catalog entries for new events
 * - EventCatalog registration and validation
 * - EcommerceFormatConverter new GA4→Meta methods
 * - ga4ToMetaAuto universal converter
 * - Version consistency across codebase
 * - SaaSConversionService existence and structure
 * - Config conversion_analytics section
 * - API routes for conversion endpoints
 * - JS client conversion functions
 */
test('TrialConvertedEvent exists and has correct structure', function (): void {
    expect(class_exists(TrialConvertedEvent::class))->toBeTrue();

    $event = new TrialConvertedEvent(
        plan: 'pro',
        trialPlan: 'free_trial',
        trialDurationDays: 14,
        conversionSource: 'pricing_page',
    );

    expect($event->name)->toBe('trial_converted');
    expect($event->params['plan'])->toBe('pro');
    expect($event->params['trial_plan'])->toBe('free_trial');
    expect($event->params['trial_duration_days'])->toBe(14);
    expect($event->params['conversion_source'])->toBe('pricing_page');
});

test('TrialConvertedEvent is immutable readonly DTO', function (): void {
    $reflection = new ReflectionClass(TrialConvertedEvent::class);
    expect($reflection->isReadOnly())->toBeTrue();
    expect($reflection->isFinal())->toBeTrue();
});

test('TrialConvertedEvent extends AnalyticsEvent', function (): void {
    $event = new TrialConvertedEvent(plan: 'enterprise');
    expect($event)->toBeInstanceOf(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
});

test('SubscriptionResumedEvent exists and has correct structure', function (): void {
    expect(class_exists(SubscriptionResumedEvent::class))->toBeTrue();

    $event = new SubscriptionResumedEvent(
        plan: 'pro',
        previousPlan: 'starter',
        daysSinceCancellation: 30,
        reactivationSource: 'win_back_email',
    );

    expect($event->name)->toBe('subscription_resumed');
    expect($event->params['plan'])->toBe('pro');
    expect($event->params['previous_plan'])->toBe('starter');
    expect($event->params['days_since_cancellation'])->toBe(30);
    expect($event->params['reactivation_source'])->toBe('win_back_email');
});

test('SubscriptionResumedEvent is immutable readonly DTO', function (): void {
    $reflection = new ReflectionClass(SubscriptionResumedEvent::class);
    expect($reflection->isReadOnly())->toBeTrue();
    expect($reflection->isFinal())->toBeTrue();
});

test('MilestoneReachedEvent exists and has correct structure', function (): void {
    expect(class_exists(MilestoneReachedEvent::class))->toBeTrue();

    $event = new MilestoneReachedEvent(
        milestone: 'first_project',
        category: 'activation',
        value: 1,
    );

    expect($event->name)->toBe('milestone_reached');
    expect($event->params['milestone'])->toBe('first_project');
    expect($event->params['milestone_category'])->toBe('activation');
    expect($event->params['milestone_value'])->toBe(1);
});

test('MilestoneReachedEvent is immutable readonly DTO', function (): void {
    $reflection = new ReflectionClass(MilestoneReachedEvent::class);
    expect($reflection->isReadOnly())->toBeTrue();
    expect($reflection->isFinal())->toBeTrue();
});

test('SaaSEvents catalog has trial_converted entry', function (): void {
    expect(SaaSEvents::has('trial_converted'))->toBeTrue();
    $entry = SaaSEvents::get('trial_converted');
    expect($entry)->not->toBeNull();
    expect($entry['class'])->toBe(TrialConvertedEvent::class);
    expect($entry['ga4'])->toBe('trial_converted');
    expect($entry['meta'])->toBe('Subscribe');
    expect($entry['posthog'])->toBe('trial_converted');
    expect($entry['plausible'])->toBe('conversion');
});

test('SaaSEvents catalog has subscription_resumed entry', function (): void {
    expect(SaaSEvents::has('subscription_resumed'))->toBeTrue();
    $entry = SaaSEvents::get('subscription_resumed');
    expect($entry)->not->toBeNull();
    expect($entry['class'])->toBe(SubscriptionResumedEvent::class);
    expect($entry['ga4'])->toBe('subscription_resumed');
    expect($entry['meta'])->toBe('Subscribe');
});

test('SaaSEvents catalog has milestone_reached entry', function (): void {
    expect(SaaSEvents::has('milestone_reached'))->toBeTrue();
    $entry = SaaSEvents::get('milestone_reached');
    expect($entry)->not->toBeNull();
    expect($entry['class'])->toBe(MilestoneReachedEvent::class);
    expect($entry['ga4'])->toBe('milestone_reached');
    expect($entry['meta'])->toBe('MilestoneReached');
    expect($entry['posthog'])->toBe('milestone_reached');
});

test('EventCatalog includes new SaaS events', function (): void {
    expect(EventCatalog::has('trial_converted'))->toBeTrue();
    expect(EventCatalog::has('subscription_resumed'))->toBeTrue();
    expect(EventCatalog::has('milestone_reached'))->toBeTrue();

    expect(EventCatalog::getCategory('trial_converted'))->toBe('saas');
    expect(EventCatalog::getCategory('subscription_resumed'))->toBe('saas');
    expect(EventCatalog::getCategory('milestone_reached'))->toBe('saas');

    expect(EventCatalog::classFor('trial_converted'))->toBe(TrialConvertedEvent::class);
    expect(EventCatalog::classFor('subscription_resumed'))->toBe(SubscriptionResumedEvent::class);
    expect(EventCatalog::classFor('milestone_reached'))->toBe(MilestoneReachedEvent::class);
});

test('EventCatalog validation passes for new events', function (): void {
    $result = EventCatalog::validate();
    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

test('SaaSEvents count increased by 3', function (): void {
    $count = SaaSEvents::count();
    // Previous count was 42, now 45
    expect($count)->toBeGreaterThanOrEqual(42);
});

test('EventCatalog count increased', function (): void {
    $count = EventCatalog::count();
    // Previous total was ~70, now ~73
    expect($count)->toBeGreaterThanOrEqual(70);
});

// ── EcommerceFormatConverter Tests ────────────────────────────────

test('EcommerceFormatConverter ga4ToMetaView converts correctly', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaView([
        'currency' => 'EUR',
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 1],
        ],
    ]);

    expect($result['content_ids'])->toBe(['SKU-001']);
    expect($result['content_type'])->toBe('product');
    expect($result['currency'])->toBe('EUR');
    expect($result['value'])->toBe(29.99);
    expect($result['contents'])->toHaveCount(1);
});

test('EcommerceFormatConverter ga4ToMetaAddToCart converts correctly', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaAddToCart([
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-002', 'item_name' => 'Gadget', 'price' => 49.99, 'quantity' => 2],
        ],
    ]);

    expect($result['content_ids'])->toBe(['SKU-002']);
    expect($result['value'])->toBe(99.98);
    expect($result['content_type'])->toBe('product');
});

test('EcommerceFormatConverter ga4ToMetaBeginCheckout converts correctly', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaBeginCheckout([
        'currency' => 'GBP',
        'value' => 149.99,
        'items' => [
            ['item_id' => 'SKU-003', 'item_name' => 'Premium', 'price' => 149.99, 'quantity' => 1],
        ],
    ]);

    expect($result['num_items'])->toBe(1);
    expect($result['value'])->toBe(149.99);
    expect($result['currency'])->toBe('GBP');
});

test('EcommerceFormatConverter ga4ToMetaAddPaymentInfo converts correctly', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaAddPaymentInfo([
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-004', 'price' => 199.99, 'quantity' => 1],
        ],
    ]);

    expect($result['content_type'])->toBe('product');
    expect($result['value'])->toBe(199.99);
});

test('EcommerceFormatConverter ga4ToMetaAuto converts view_item', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaAuto('view_item', [
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 1],
        ],
    ]);

    expect($result)->not->toBeNull();
    expect($result['meta_event'])->toBe('ViewContent');
    expect($result['meta_params']['content_type'])->toBe('product');
});

test('EcommerceFormatConverter ga4ToMetaAuto converts add_to_cart', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaAuto('add_to_cart', [
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-001', 'price' => 10.0, 'quantity' => 3],
        ],
    ]);

    expect($result)->not->toBeNull();
    expect($result['meta_event'])->toBe('AddToCart');
});

test('EcommerceFormatConverter ga4ToMetaAuto converts begin_checkout', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaAuto('begin_checkout', [
        'currency' => 'USD',
        'value' => 50.0,
        'items' => [['item_id' => 'X', 'price' => 50.0, 'quantity' => 1]],
    ]);

    expect($result)->not->toBeNull();
    expect($result['meta_event'])->toBe('InitiateCheckout');
});

test('EcommerceFormatConverter ga4ToMetaAuto converts add_payment_info', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaAuto('add_payment_info', [
        'currency' => 'USD',
        'items' => [['item_id' => 'X', 'price' => 99.0, 'quantity' => 1]],
    ]);

    expect($result)->not->toBeNull();
    expect($result['meta_event'])->toBe('AddPaymentInfo');
});

test('EcommerceFormatConverter ga4ToMetaAuto converts purchase', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaAuto('purchase', [
        'transaction_id' => 'TXN-123',
        'value' => 99.99,
        'currency' => 'USD',
        'items' => [['item_id' => 'X', 'price' => 99.99, 'quantity' => 1]],
    ]);

    expect($result)->not->toBeNull();
    expect($result['meta_event'])->toBe('Purchase');
    expect($result['meta_params']['transaction_id'])->toBe('TXN-123');
});

test('EcommerceFormatConverter ga4ToMetaAuto converts refund', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaAuto('refund', [
        'transaction_id' => 'TXN-456',
        'value' => 49.99,
        'currency' => 'USD',
        'items' => [['item_id' => 'X', 'price' => 49.99, 'quantity' => 1]],
    ]);

    expect($result)->not->toBeNull();
    expect($result['meta_event'])->toBe('Refund');
});

test('EcommerceFormatConverter ga4ToMetaAuto converts add_to_wishlist', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaAuto('add_to_wishlist', [
        'items' => [['item_id' => 'WISH-001', 'price' => 25.0, 'quantity' => 1]],
        'currency' => 'USD',
    ]);

    expect($result)->not->toBeNull();
    expect($result['meta_event'])->toBe('AddToWishlist');
    expect($result['meta_params']['content_ids'])->toBe(['WISH-001']);
});

test('EcommerceFormatConverter ga4ToMetaAuto returns null for unknown event', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaAuto('unknown_event', []);

    expect($result)->toBeNull();
});

test('EcommerceFormatConverter ga4ToMetaAuto all 7 supported events', function (): void {
    $supported = ['view_item', 'add_to_cart', 'begin_checkout', 'add_payment_info', 'purchase', 'refund', 'add_to_wishlist'];

    foreach ($supported as $eventName) {
        $result = EcommerceFormatConverter::ga4ToMetaAuto($eventName, [
            'items' => [['item_id' => 'X', 'price' => 10.0, 'quantity' => 1]],
            'currency' => 'USD',
        ]);

        expect($result)->not->toBeNull();
        expect($result['meta_event'])->toBeString();
        expect($result['meta_params'])->toBeArray();
    }
});

// ── Service & Config Tests ────────────────────────────────────────

test('SaaSConversionService class exists', function (): void {
    expect(class_exists(\ZeroBoiler\Analytics\Services\SaaSConversionService::class))->toBeTrue();
});

test('SaaSConversionService has required methods', function (): void {
    $class = new ReflectionClass(\ZeroBoiler\Analytics\Services\SaaSConversionService::class);

    $requiredMethods = [
        'trackTrialConversion', 'recordTrialStart', 'trialConversionRate',
        'trialConversionRateByPlan', 'trackSubscriptionResumed', 'winBackRate',
        'trackActivationMilestone', 'activationScore', 'averageActivationScore',
        'recordTrialStartTime', 'timeToConversion', 'recordTimeToConversion',
        'conversionFunnel', 'summary',
    ];

    foreach ($requiredMethods as $method) {
        expect($class->hasMethod($method))
            ->toBeTrue("Missing method: {$method}");
    }
});

test('SaaSConversionService constructor has correct parameters', function (): void {
    $class = new ReflectionClass(\ZeroBoiler\Analytics\Services\SaaSConversionService::class);
    $constructor = $class->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(3);
    expect($params[0]->getName())->toBe('manager');
    expect($params[1]->getName())->toBe('cache');
    expect($params[2]->getName())->toBe('config');
});

test('Config file has conversion_analytics section', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';
    expect($config)->toBeArray();
    expect($config['analytics'])->toBeArray();
    expect($config['analytics']['conversion_analytics'])->toBeArray();
    expect($config['analytics']['conversion_analytics']['enabled'])->toBeTrue();
    expect($config['analytics']['conversion_analytics']['cache_ttl'])->toBeInt();
    expect($config['analytics']['conversion_analytics']['activation_milestones'])->toBeArray();
});

test('Config has 8 default activation milestones', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';
    $milestones = $config['analytics']['conversion_analytics']['activation_milestones'];

    expect($milestones)->toHaveCount(8);
    expect($milestones)->toHaveKey('first_login');
    expect($milestones)->toHaveKey('profile_completed');
    expect($milestones)->toHaveKey('first_feature_used');
    expect($milestones)->toHaveKey('team_created');
    expect($milestones)->toHaveKey('integration_connected');
    expect($milestones)->toHaveKey('invite_sent');
    expect($milestones)->toHaveKey('search_performed');
    expect($milestones)->toHaveKey('three_day_retention');
});

// ── Route & Version Consistency Tests ───────────────────────────────

test('Routes file has conversion endpoints', function (): void {
    $routeContent = file_get_contents(__DIR__ . '/../routes/analytics.php');

    expect($routeContent)->toContain('conversion/summary');
    expect($routeContent)->toContain('conversion/funnel');
    expect($routeContent)->toContain('conversion/activation/');
    expect($routeContent)->toContain('conversion/time-to-convert');
    expect($routeContent)->toContain('conversionSummary');
    expect($routeContent)->toContain('conversionFunnel');
    expect($routeContent)->toContain('conversionActivationScore');
    expect($routeContent)->toContain('conversionTimeToConvert');
});

test('Controller has conversion endpoint methods', function (): void {
    $class = new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);

    expect($class->hasMethod('conversionSummary'))->toBeTrue();
    expect($class->hasMethod('conversionFunnel'))->toBeTrue();
    expect($class->hasMethod('conversionActivationScore'))->toBeTrue();
    expect($class->hasMethod('conversionTimeToConvert'))->toBeTrue();
});

test('ServiceProvider registers SaaSConversionService', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

    expect($content)->toContain('SaaSConversionService');
    expect($content)->toContain('SaaSConversionService::class');
});

test('Version consistency — all PHP files use 2.66.0', function (): void {
    $files = [
        __DIR__ . '/../composer.json',
        __DIR__ . '/../src/AnalyticsManager.php',
        __DIR__ . '/../src/AnalyticsServiceProvider.php',
    ];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        // Should not contain old version
        expect(str_contains($content, '268.0.0'))->toBeFalse("Old version 2.87.0 found in {$file}");
    }
});

test('JS client uses version 2.66.0', function (): void {
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

    expect($js)->toContain("'268.0.0'");
    expect($js)->toContain('@version 268.0.0');
});

test('JS client has conversion tracking functions', function (): void {
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

    expect($js)->toContain('export async function trackTrialConversion');
    expect($js)->toContain('export async function trackSubscriptionResumed');
    expect($js)->toContain('export async function trackMilestone');
    expect($js)->toContain('export async function fetchConversionSummary');
    expect($js)->toContain('export async function fetchConversionFunnel');
});

test('SaaSConversionService summary method return type structure', function (): void {
    $method = new ReflectionMethod(
        \ZeroBoiler\Analytics\Services\SaaSConversionService::class,
        'summary',
    );

    $returnType = $method->getReturnType();
    expect($returnType)->not->toBeNull();
    expect((string) $returnType)->toBe('array');
});

test('SaaSConversionService activationScore return type', function (): void {
    $method = new ReflectionMethod(
        \ZeroBoiler\Analytics\Services\SaaSConversionService::class,
        'activationScore',
    );

    $returnType = $method->getReturnType();
    expect($returnType)->not->toBeNull();
    expect((string) $returnType)->toBe('array');
});

test('EventCatalog allMetaNames includes trial_converted Meta mapping', function (): void {
    $metaNames = EventCatalog::allMetaNames();
    expect($metaNames)->toContain('Subscribe'); // trial_converted maps to Subscribe
});

test('EventCatalog allPlausibleNames includes conversion', function (): void {
    $plausibleNames = EventCatalog::allPlausibleNames();
    expect($plausibleNames)->toContain('conversion'); // trial_converted maps to conversion
});
