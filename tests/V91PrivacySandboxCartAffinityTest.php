<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\Engagement\ConsentGrantedEvent;
use ZeroBoiler\Analytics\Events\Engagement\ConsentWithdrawnEvent;
use ZeroBoiler\Analytics\Events\SaaS\DataSubjectAccessRequestEvent;
use ZeroBoiler\Analytics\Events\SaaS\DataErasureCompletedEvent;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;

test('ConsentGrantedEvent has correct name and params', function (): void {
    $event = new ConsentGrantedEvent(
        clientId: 'client-123',
        userId: 'user-456',
        purposes: ['analytics', 'marketing'],
        method: 'banner',
    );

    expect($event->getName())->toBe('consent_granted');
    expect($event->getParams())->toHaveKey('purposes');
    expect($event->getParams()['purposes'])->toBe(['analytics', 'marketing']);
    expect($event->getParams()['purpose_count'])->toBe(2);
    expect($event->getParams()['method'])->toBe('banner');
    expect($event->getClientId())->toBe('client-123');
    expect($event->getUserId())->toBe('user-456');
});

test('ConsentWithdrawnEvent has correct name and params', function (): void {
    $event = new ConsentWithdrawnEvent(
        clientId: 'client-123',
        purposes: ['marketing'],
        method: 'settings',
    );

    expect($event->getName())->toBe('consent_withdrawn');
    expect($event->getParams()['purposes'])->toBe(['marketing']);
    expect($event->getParams()['purpose_count'])->toBe(1);
    expect($event->getParams()['method'])->toBe('settings');
    expect($event->getUserId())->toBeNull();
});

test('DataSubjectAccessRequestEvent has correct name and params', function (): void {
    $event = new DataSubjectAccessRequestEvent(
        clientId: 'client-789',
        userId: 'user-100',
        requestType: 'access',
    );

    expect($event->getName())->toBe('data_subject_access_request');
    expect($event->getParams()['request_type'])->toBe('access');
});

test('DataErasureCompletedEvent has correct name and params', function (): void {
    $event = new DataErasureCompletedEvent(
        userId: 'user-200',
        categoriesErased: ['analytics', 'profile'],
        requestId: 'DSAR-001',
    );

    expect($event->getName())->toBe('data_erasure_completed');
    expect($event->getParams()['categories_erased'])->toBe(['analytics', 'profile']);
    expect($event->getParams()['categories_count'])->toBe(2);
    expect($event->getParams()['request_id'])->toBe('DSAR-001');
});

test('consent events are registered in EngagementEvents catalog', function (): void {
    expect(EngagementEvents::has('consent_granted'))->toBeTrue();
    expect(EngagementEvents::has('consent_withdrawn'))->toBeTrue();

    $granted = EngagementEvents::get('consent_granted');
    expect($granted)->not->toBeNull();
    expect($granted['class'])->toBe(ConsentGrantedEvent::class);
    expect($granted['ga4'])->toBe('consent_update');
    expect($granted['meta'])->toBe('ConsentGranted');
    expect($granted['posthog'])->toBe('consent_granted');

    $withdrawn = EngagementEvents::get('consent_withdrawn');
    expect($withdrawn)->not->toBeNull();
    expect($withdrawn['class'])->toBe(ConsentWithdrawnEvent::class);
});

test('DSAR events are registered in SaaSEvents catalog', function (): void {
    expect(SaaSEvents::has('data_subject_access_request'))->toBeTrue();
    expect(SaaSEvents::has('data_erasure_completed'))->toBeTrue();

    $dsar = SaaSEvents::get('data_subject_access_request');
    expect($dsar)->not->toBeNull();
    expect($dsar['class'])->toBe(DataSubjectAccessRequestEvent::class);

    $erasure = SaaSEvents::get('data_erasure_completed');
    expect($erasure)->not->toBeNull();
    expect($erasure['class'])->toBe(DataErasureCompletedEvent::class);
});

test('new events are discoverable via EventCatalog', function (): void {
    expect(EventCatalog::has('consent_granted'))->toBeTrue();
    expect(EventCatalog::has('consent_withdrawn'))->toBeTrue();
    expect(EventCatalog::has('data_subject_access_request'))->toBeTrue();
    expect(EventCatalog::has('data_erasure_completed'))->toBeTrue();

    expect(EventCatalog::getCategory('consent_granted'))->toBe('engagement');
    expect(EventCatalog::getCategory('data_subject_access_request'))->toBe('saas');
});

test('EventCatalog::gdprEvents includes consent events', function (): void {
    $gdpr = EventCatalog::gdprEvents();
    $names = array_column($gdpr, 'name');

    expect($names)->toContain('consent_granted');
    expect($names)->toContain('consent_withdrawn');
    expect($names)->toContain('data_subject_access_request');
    expect($names)->toContain('data_erasure_completed');
});

test('engagement event count increased by 2', function (): void {
    // Previous count was 28 (goal_conversion was the last)
    expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);
});

test('SaaS event count increased by 2', function (): void {
    // Previous count was 50+
    expect(SaaSEvents::count())->toBeGreaterThanOrEqual(52);
});

test('EventCatalog total count includes new events', function (): void {
    $total = EventCatalog::count();
    expect($total)->toBeGreaterThanOrEqual(100);
});

test('PrivacySandboxService has correct structure', function (): void {
    $service = new \ZeroBoiler\Analytics\Services\PrivacySandboxService(
        cache: app('cache'),
        config: app('config'),
    );

    // When disabled, should return empty arrays
    expect($service->isEnabled())->toBeFalse();
    expect($service->eventToTopics('add_to_cart'))->toBe([]);
    expect($service->getTopicsForClient('test-client'))->toBe([]);
    expect($service->getTopTopicsForClient('test-client'))->toBe([]);
    expect($service->topicTaxonomy())->toBeArray();
    expect($service->topicTaxonomy())->not->toBeEmpty();
});

test('PrivacySandboxService topic taxonomy is complete', function (): void {
    $taxonomy = \ZeroBoiler\Analytics\Services\PrivacySandboxService::topicTaxonomy();

    // Should have at least 16 built-in topics
    expect(count($taxonomy))->toBeGreaterThanOrEqual(16);
    expect($taxonomy)->toHaveKey('/Shopping');
    expect($taxonomy)->toHaveKey('/Technology & Computing');
    expect($taxonomy)->toHaveKey('/Finance');
});

test('PrivacySandboxService builds attribution payloads', function (): void {
    $service = new \ZeroBoiler\Analytics\Services\PrivacySandboxService(
        cache: app('cache'),
        config: app('config'),
    );

    $source = $service->buildAttributionSource('click-123', 'purchase', 0.8);
    expect($source['event_id'])->toBe('click-123');
    expect($source['conversion_goal'])->toBe('purchase');
    expect($source['priority'])->toBe(0.8);
    expect($source['attribution_window_days'])->toBe(30);

    $trigger = $service->buildAttributionTrigger('click-123', 'purchase', ['value' => 99.99]);
    expect($trigger['source_event_id'])->toBe('click-123');
    expect($trigger['trigger_data'])->toBe('purchase');
});

test('CartStateManager has correct structure', function (): void {
    $service = new \ZeroBoiler\Analytics\Services\CartStateManager(
        manager: app('zeroboiler.analytics'),
        cache: app('cache'),
        config: app('config'),
    );

    expect($service->isEnabled())->toBeTrue();
    expect($service->hasCart('nonexistent'))->toBeFalse();
    expect($service->getCart('nonexistent'))->toBeNull();
});

test('CartStateManager update and retrieve cart', function (): void {
    $service = new \ZeroBoiler\Analytics\Services\CartStateManager(
        manager: app('zeroboiler.analytics'),
        cache: app('cache'),
        config: app('config'),
    );

    $items = [
        ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 2],
        ['item_id' => 'SKU-002', 'item_name' => 'Gadget', 'price' => 49.99, 'quantity' => 1],
    ];

    $state = $service->updateCart('client-cart-test', $items);

    expect($state['value'])->toBe(29.99 * 2 + 49.99); // 109.97
    expect($state['item_count'])->toBe(3);
    expect($state['currency'])->toBe('USD');

    $retrieved = $service->getCart('client-cart-test');
    expect($retrieved)->not->toBeNull();
    expect($retrieved['value'])->toBe(109.97);

    // Cleanup
    $service->clearCart('client-cart-test');
});

test('CartStateManager abandonment score', function (): void {
    $service = new \ZeroBoiler\Analytics\Services\CartStateManager(
        manager: app('zeroboiler.analytics'),
        cache: app('cache'),
        config: app('config'),
    );

    // Empty cart → zero score
    $score = $service->abandonmentScore('no-cart-client');
    expect($score['score'])->toBe(0.0);
    expect($score['is_abandoned'])->toBeFalse();

    // Cleanup
    $service->clearCart('no-cart-client');
});

test('CartStateManager merge anonymous to authenticated', function (): void {
    $service = new \ZeroBoiler\Analytics\Services\CartStateManager(
        manager: app('zeroboiler.analytics'),
        cache: app('cache'),
        config: app('config'),
    );

    // Setup anonymous cart
    $anonItems = [
        ['item_id' => 'SKU-001', 'price' => 10.0, 'quantity' => 1],
    ];
    $service->updateCart('anon-client', $anonItems);

    // Merge into authenticated cart
    $result = $service->mergeCart('anon-client', 'auth-client');
    expect($result['merged'])->toBeTrue();
    expect($result['value'])->toBe(10.0);

    // Verify merged cart
    $merged = $service->getCart('auth-client');
    expect($merged)->not->toBeNull();
    expect($merged['items'])->toHaveCount(1);

    // Cleanup
    $service->clearCart('anon-client');
    $service->clearCart('auth-client');
});

test('EventAffinityService has correct structure', function (): void {
    $service = new \ZeroBoiler\Analytics\Services\EventAffinityService(
        cache: app('cache'),
        metrics: app('zeroboiler.analytics')->metrics(),
        config: app('config'),
    );

    expect($service->isEnabled())->toBeTrue();
    expect($service->highAffinityPairs())->toBe([]);
    expect($service->summary())->toBeArray();
});

test('EventAffinityService lift returns 0 for insufficient data', function (): void {
    $service = new \ZeroBoiler\Analytics\Services\EventAffinityService(
        cache: app('cache'),
        metrics: app('zeroboiler.analytics')->metrics(),
        config: app('config'),
    );

    expect($service->lift('signup', 'purchase'))->toBe(0.0);
    expect($service->conditionalProbability('signup', 'purchase'))->toBe(0.0);
    expect($service->relatedEvents('signup'))->toBe([]);
});

test('composer.json version is current', function (): void {
    $json = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($json['version'])->toBe('74.0.0');
});
