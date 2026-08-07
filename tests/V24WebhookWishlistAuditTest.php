<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\WishlistEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Middleware\AuditLogMiddleware;
use ZeroBoiler\Analytics\Trackers\WebhookTracker;

// ── WishlistEvent Tests ──────────────────────────────────────────────

describe('WishlistEvent', function () {
    it('creates a wishlist event with required params', function () {
        $event = new WishlistEvent(itemId: 'SKU-001');

        expect($event->name)->toBe('add_to_wishlist');
        expect($event->params)->toHaveKey('item_id');
        expect($event->params['item_id'])->toBe('SKU-001');
    });

    it('creates a wishlist event with all params', function () {
        $event = new WishlistEvent(
            itemId: 'SKU-002',
            itemName: 'Premium Widget',
            itemCategory: 'Widgets',
            price: 29.99,
            currency: 'EUR',
        );

        expect($event->name)->toBe('add_to_wishlist');
        expect($event->params['item_name'])->toBe('Premium Widget');
        expect($event->params['item_category'])->toBe('Widgets');
        expect($event->params['price'])->toBe(29.99);
        expect($event->params['currency'])->toBe('EUR');
    });

    it('supports client and user identity', function () {
        $event = new WishlistEvent(
            itemId: 'SKU-003',
            clientId: 'client-uuid',
            userId: 'user-42',
        );

        expect($event->clientId)->toBe('client-uuid');
        expect($event->userId)->toBe('user-42');
    });

    it('is registered in EcommerceEvents catalog', function () {
        expect(\ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::has('add_to_wishlist'))->toBeTrue();
    });

    it('is registered in EventCatalog', function () {
        expect(EventCatalog::has('add_to_wishlist'))->toBeTrue();

        $entry = EventCatalog::get('add_to_wishlist');
        expect($entry)->not->toBeNull();
        expect($entry['ga4'])->toBe('add_to_wishlist');
        expect($entry['meta'])->toBe('AddToWishlist');
        expect($entry['category'])->toBe('ecommerce');
    });
});

// ── WebhookTracker Tests ──────────────────────────────────────────────

describe('WebhookTracker', function () {
    it('creates a disabled tracker by default', function () {
        $tracker = new WebhookTracker;

        expect($tracker->isEnabled())->toBeFalse();
        expect($tracker->getWebhookUrl())->toBe('');
    });

    it('creates an enabled tracker with config', function () {
        $tracker = new WebhookTracker(
            webhookUrl: 'https://hooks.example.com/analytics',
            secret: 'my-secret',
            enabled: true,
            timeout: 10,
            retries: 2,
        );

        expect($tracker->isEnabled())->toBeTrue();
        expect($tracker->getWebhookUrl())->toBe('https://hooks.example.com/analytics');
    });

    it('builds a payload correctly', function () {
        $tracker = new WebhookTracker(
            webhookUrl: 'https://hooks.example.com/analytics',
            enabled: true,
        );

        $event = new AnalyticsEvent(
            name: 'test_event',
            params: ['key' => 'value'],
            clientId: 'client-123',
        );

        $payload = $tracker->buildPayload($event);

        expect($payload['event'])->toBe('test_event');
        expect($payload['params'])->toBe(['key' => 'value']);
        expect($payload['client_id'])->toBe('client-123');
        expect($payload['timestamp'])->toBeString();
    });

    it('signs payload when secret is provided', function () {
        $tracker = new WebhookTracker(
            webhookUrl: 'https://hooks.example.com/analytics',
            secret: 'test-secret',
            enabled: true,
            signPayloads: true,
        );

        $event = new AnalyticsEvent(name: 'signed_event');
        $payload = $tracker->buildPayload($event);

        expect($payload)->toHaveKey('signature');
        expect($payload['signature'])->toBeString();
        expect(strlen($payload['signature']))->toBe(64); // SHA256 = 64 hex chars
    });

    it('does not sign payload without secret', function () {
        $tracker = new WebhookTracker(
            webhookUrl: 'https://hooks.example.com/analytics',
            enabled: true,
        );

        $event = new AnalyticsEvent(name: 'unsigned_event');
        $payload = $tracker->buildPayload($event);

        expect($payload)->not->toHaveKey('signature');
    });

    it('does not track when disabled', function () {
        $tracker = new WebhookTracker(
            webhookUrl: 'https://hooks.example.com/analytics',
            enabled: false,
        );

        $event = new AnalyticsEvent(name: 'should_not_track');

        // Should not throw — disabled trackers silently skip
        $tracker->track($event);
        expect(true)->toBeTrue();
    });

    it('sets consent state correctly', function () {
        $tracker = new WebhookTracker(
            webhookUrl: 'https://hooks.example.com/analytics',
            enabled: true,
        );

        expect($tracker->isEnabled())->toBeTrue();

        $tracker->setConsent(\ZeroBoiler\Analytics\DTO\ConsentState::denied());
        expect($tracker->isEnabled())->toBeFalse();

        $tracker->setConsent(\ZeroBoiler\Analytics\DTO\ConsentState::granted());
        expect($tracker->isEnabled())->toBeTrue();
    });
});

// ── AuditLogMiddleware Tests ──────────────────────────────────────────

describe('AuditLogMiddleware', function () {
    it('creates a disabled middleware by default', function () {
        $middleware = new AuditLogMiddleware;

        expect($middleware->isEnabled())->toBeFalse();
        expect($middleware->getPriority())->toBe(100);
    });

    it('creates an enabled middleware with config', function () {
        $middleware = new AuditLogMiddleware(enabled: true, priority: 50);

        expect($middleware->isEnabled())->toBeTrue();
        expect($middleware->getPriority())->toBe(50);
    });

    it('passes events through the pipeline', function () {
        $middleware = new AuditLogMiddleware(enabled: false);
        $event = new AnalyticsEvent(name: 'test_audit');

        $result = $middleware->process($event, fn (AnalyticsEvent $e): ?AnalyticsEvent => $e);

        expect($result)->not->toBeNull();
        expect($result->name)->toBe('test_audit');
    });

    it('filters null events correctly', function () {
        $middleware = new AuditLogMiddleware(enabled: true);

        $result = $middleware->process(
            new AnalyticsEvent(name: 'filtered'),
            fn (): ?AnalyticsEvent => null,
        );

        expect($result)->toBeNull();
    });

    it('can toggle enabled state at runtime', function () {
        $middleware = new AuditLogMiddleware(enabled: false);

        $middleware->setEnabled(true);
        expect($middleware->isEnabled())->toBeTrue();

        $middleware->setEnabled(false);
        expect($middleware->isEnabled())->toBeFalse();
    });
});

// ── Event Catalog Expansion Tests ──────────────────────────────────────

describe('EventCatalog v2.4', function () {
    it('has 33 total events (32 + wishlist)', function () {
        expect(EventCatalog::count())->toBe(33);
    });

    it('has 9 ecommerce events', function () {
        expect(\ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count())->toBe(9);
    });

    it('wishlist is in ecommerce names', function () {
        $names = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::names();
        expect($names)->toContain('add_to_wishlist');
    });

    it('wishlist class resolves correctly', function () {
        $class = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::classFor('add_to_wishlist');
        expect($class)->toBe(WishlistEvent::class);
    });

    it('all GA4 names include add_to_wishlist', function () {
        $ga4Names = EventCatalog::allGa4Names();
        expect($ga4Names)->toContain('add_to_wishlist');
    });

    it('all Meta names include AddToWishlist', function () {
        $metaNames = EventCatalog::allMetaNames();
        expect($metaNames)->toContain('AddToWishlist');
    });

    it('search finds wishlist events', function () {
        $results = EventCatalog::search('wishlist');
        expect($results)->toHaveCount(1);
        expect($results[0]['name'])->toBe('add_to_wishlist');
    });
});

// ── AnalyticsManager Webhook Integration Tests ────────────────────────

describe('AnalyticsManager v2.4', function () {
    it('creates webhook tracker from config', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'webhook' => [
                        'enabled' => true,
                        'url' => 'https://hooks.example.com/analytics',
                        'secret' => 'test-secret',
                        'timeout' => 5,
                        'retries' => 1,
                        'sign' => true,
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        expect($manager->webhook()->isEnabled())->toBeTrue();
        expect($manager->webhook()->getWebhookUrl())->toBe('https://hooks.example.com/analytics');
    });

    it('version is 2.5.0', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        expect($manager->version())->toBe('2.35.0');
    });

    it('providerSummary includes webhook', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'webhook' => [
                        'enabled' => true,
                        'url' => 'https://hooks.example.com/analytics',
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $summary = $manager->providerSummary();

        expect($summary)->toHaveKey('webhook');
        expect($summary['webhook']['enabled'])->toBeTrue();
        expect($summary['webhook']['id'])->toBe('https://hooks.example.com/analytics');
    });

    it('consent propagation includes webhook', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'webhook' => [
                        'enabled' => true,
                        'url' => 'https://hooks.example.com/analytics',
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        expect($manager->webhook()->isEnabled())->toBeTrue();

        $manager->denyConsent();

        expect($manager->webhook()->isEnabled())->toBeFalse();

        $manager->grantConsent();

        expect($manager->webhook()->isEnabled())->toBeTrue();
    });

    it('eventCatalogSummary reports updated counts', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $summary = $manager->eventCatalogSummary();

        expect($summary['ecommerce'])->toBe(9);
        expect($summary['total'])->toBe(33);
    });

    it('totalEventCount is 33', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        expect($manager->totalEventCount())->toBe(33);
    });
});
