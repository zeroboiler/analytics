<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Bus\AnalyticsEventBus;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\EventSchemaJsonGenerator;
use ZeroBoiler\Analytics\Services\RegionalConsentService;

/**
 * @covers \ZeroBoiler\Analytics\Services\EventSchemaJsonGenerator
 * @covers \ZeroBoiler\Analytics\Bus\AnalyticsEventBus
 * @covers \ZeroBoiler\Analytics\Services\RegionalConsentService
 */
final class V54EventBusSchemaRegionalTest extends TestCase
{
    // ── Event Schema JSON Generator Tests ─────────────────────────────

    public function test_catalog_schema_contains_all_events(): void
    {
        $generator = new EventSchemaJsonGenerator;
        $schema = $generator->generateCatalogSchema();

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertSame('ZeroBoiler Analytics Event Catalog', $schema['title']);
        $this->assertSame('5.7.0', $schema['version']);
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertArrayHasKey('params', $schema['properties']);
        $this->assertArrayHasKey('client_id', $schema['properties']);
        $this->assertArrayHasKey('user_id', $schema['properties']);

        // Event name enum should contain all catalog events
        $eventNames = EventCatalog::names();
        $this->assertNotEmpty($eventNames);
        $this->assertSame($eventNames, $schema['properties']['name']['enum']);
    }

    public function test_catalog_schema_has_definitions(): void
    {
        $generator = new EventSchemaJsonGenerator;
        $schema = $generator->generateCatalogSchema();

        $this->assertArrayHasKey('definitions', $schema);
        $this->assertArrayHasKey('item', $schema['definitions']);
        $this->assertArrayHasKey('consent_state', $schema['definitions']);
        $this->assertArrayHasKey('utm', $schema['definitions']);
    }

    public function test_event_schema_for_purchase_has_typed_params(): void
    {
        $generator = new EventSchemaJsonGenerator;
        $schema = $generator->generateEventSchema('purchase');

        $this->assertSame('purchase', $schema['title']);
        $this->assertSame('ecommerce', $schema['_zb']['category']);

        $params = $schema['properties']['params']['properties'];
        $this->assertArrayHasKey('transaction_id', $params);
        $this->assertArrayHasKey('value', $params);
        $this->assertArrayHasKey('currency', $params);
        $this->assertArrayHasKey('items', $params);

        // Value must be non-negative number
        $this->assertSame('number', $params['value']['type']);
        $this->assertSame(0, $params['value']['minimum']);

        // Currency must be 3-letter code
        $this->assertSame('^[A-Z]{3}$', $params['currency']['pattern']);
    }

    public function test_event_schema_for_signup_has_typed_params(): void
    {
        $generator = new EventSchemaJsonGenerator;
        $schema = $generator->generateEventSchema('sign_up');

        $this->assertSame('sign_up', $schema['title']);
        $this->assertSame('saas', $schema['_zb']['category']);

        $params = $schema['properties']['params']['properties'];
        $this->assertArrayHasKey('method', $params);
        $this->assertArrayHasKey('referral', $params);
        $this->assertArrayHasKey('plan', $params);
    }

    public function test_event_schema_for_unknown_event(): void
    {
        $generator = new EventSchemaJsonGenerator;
        $schema = $generator->generateEventSchema('nonexistent_event_xyz');

        $this->assertStringContainsString('Unknown event', $schema['description']);
        $this->assertSame('nonexistent_event_xyz', $schema['properties']['name']['const']);
    }

    public function test_category_schema_saas(): void
    {
        $generator = new EventSchemaJsonGenerator;
        $schema = $generator->generateCategorySchema('saas');

        $this->assertSame('SaaS Events', $schema['title']);
        $saasNames = SaaSEvents::names();
        $this->assertSame($saasNames, $schema['properties']['name']['enum']);
    }

    public function test_category_schema_ecommerce(): void
    {
        $generator = new EventSchemaJsonGenerator;
        $schema = $generator->generateCategorySchema('ecommerce');

        $this->assertSame('Ecommerce Events', $schema['title']);
        $ecommerceNames = EcommerceEvents::names();
        $this->assertSame($ecommerceNames, $schema['properties']['name']['enum']);
    }

    public function test_event_names_schema(): void
    {
        $generator = new EventSchemaJsonGenerator;
        $schema = $generator->generateEventNamesSchema();

        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertArrayHasKey('enum', $schema['properties']['name']);
        $this->assertNotEmpty($schema['properties']['name']['enum']);
    }

    public function test_provider_mapping_table(): void
    {
        $generator = new EventSchemaJsonGenerator;
        $table = $generator->generateProviderMappingTable();

        $this->assertArrayHasKey('ga4', $table);
        $this->assertArrayHasKey('meta', $table);
        $this->assertArrayHasKey('posthog', $table);
        $this->assertArrayHasKey('plausible', $table);

        // Purchase mapping
        $this->assertSame('purchase', $table['ga4']['purchase']);
        $this->assertSame('Purchase', $table['meta']['purchase']);
        $this->assertSame('purchase', $table['posthog']['purchase']);

        // All catalog events should have GA4 mapping
        $this->assertCount(count(EventCatalog::all()), $table['ga4']);
    }

    public function test_to_json_returns_valid_json(): void
    {
        $generator = new EventSchemaJsonGenerator;
        $json = $generator->toJson();

        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('$schema', $decoded);
        $this->assertArrayHasKey('properties', $decoded);
    }

    public function test_event_to_json(): void
    {
        $generator = new EventSchemaJsonGenerator;
        $json = $generator->eventToJson('page_view');

        $decoded = json_decode($json, true);
        $this->assertSame('page_view', $decoded['title']);
        $this->assertSame('engagement', $decoded['_zb']['category']);
    }

    public function test_purchase_schema_items_reference(): void
    {
        $generator = new EventSchemaJsonGenerator;
        $schema = $generator->generateEventSchema('purchase');

        $items = $schema['properties']['params']['properties']['items'];
        $this->assertSame('array', $items['type']);
        $this->assertSame('#/definitions/item', $items['items']['$ref']);
    }

    public function test_engagement_events_schema_count(): void
    {
        $generator = new EventSchemaJsonGenerator;
        $schema = $generator->generateCategorySchema('engagement');

        $engagementNames = EngagementEvents::names();
        $this->assertGreaterThanOrEqual(30, count($engagementNames));
        $this->assertSame($engagementNames, $schema['properties']['name']['enum']);
    }

    // ── Analytics Event Bus Tests ─────────────────────────────────────

    public function test_subscribe_and_publish(): void
    {
        $bus = new AnalyticsEventBus;
        $received = [];

        $bus->subscribe('purchase', function (AnalyticsEvent $event) use (&$received): void {
            $received[] = $event;
        });

        $event = new AnalyticsEvent('purchase', ['value' => 99.99]);
        $bus->publish($event);

        $this->assertCount(1, $received);
        $this->assertSame('purchase', $received[0]->name);
        $this->assertSame(99.99, $received[0]->params['value']);
    }

    public function test_global_subscriber_receives_all_events(): void
    {
        $bus = new AnalyticsEventBus;
        $received = [];

        $bus->subscribeAll(function (AnalyticsEvent $event) use (&$received): void {
            $received[] = $event->name;
        });

        $bus->publish(new AnalyticsEvent('purchase'));
        $bus->publish(new AnalyticsEvent('sign_up'));
        $bus->publish(new AnalyticsEvent('page_view'));

        $this->assertCount(3, $received);
        $this->assertSame(['purchase', 'sign_up', 'page_view'], $received);
    }

    public function test_wildcard_subscriber(): void
    {
        $bus = new AnalyticsEventBus;
        $received = [];

        $bus->subscribe('*', function (AnalyticsEvent $event) use (&$received): void {
            $received[] = $event->name;
        });

        $bus->publish(new AnalyticsEvent('purchase'));

        $this->assertCount(1, $received);
        $this->assertSame('purchase', $received[0]);
    }

    public function test_middleware_modifies_event(): void
    {
        $bus = new AnalyticsEventBus;
        $received = [];

        $bus->addMiddleware('*', function (AnalyticsEvent $event): AnalyticsEvent {
            return new AnalyticsEvent(
                name: $event->name,
                params: array_merge($event->params, ['enriched' => true]),
                clientId: $event->clientId,
                userId: $event->userId,
                timestamp: $event->timestamp,
            );
        });

        $bus->subscribe('click', function (AnalyticsEvent $event) use (&$received): void {
            $received[] = $event->params;
        });

        $bus->publish(new AnalyticsEvent('click', ['button' => 'buy']));

        $this->assertCount(1, $received);
        $this->assertTrue($received[0]['enriched']);
        $this->assertSame('buy', $received[0]['button']);
    }

    public function test_named_middleware_only_applies_to_matching_events(): void
    {
        $bus = new AnalyticsEventBus;
        $purchaseParams = [];
        $clickParams = [];

        $bus->addMiddleware('purchase', function (AnalyticsEvent $event): AnalyticsEvent {
            return new AnalyticsEvent(
                name: $event->name,
                params: array_merge($event->params, ['purchase_enriched' => true]),
                clientId: $event->clientId,
                userId: $event->userId,
                timestamp: $event->timestamp,
            );
        });

        $bus->subscribe('*', function (AnalyticsEvent $event) use (&$purchaseParams, &$clickParams): void {
            if ($event->name === 'purchase') {
                $purchaseParams = $event->params;
            }
            if ($event->name === 'click') {
                $clickParams = $event->params;
            }
        });

        $bus->publish(new AnalyticsEvent('purchase', ['value' => 50]));
        $bus->publish(new AnalyticsEvent('click', ['element' => 'nav']));

        $this->assertTrue($purchaseParams['purchase_enriched']);
        $this->assertArrayNotHasKey('purchase_enriched', $clickParams);
    }

    public function test_reentrant_publish_is_safe(): void
    {
        $bus = new AnalyticsEventBus;
        $received = [];

        // Subscriber that publishes another event
        $bus->subscribe('purchase', function (AnalyticsEvent $event) use ($bus, &$received): void {
            $received[] = 'purchase';
            // This nested publish should be queued, not cause recursion
            $bus->publish(new AnalyticsEvent('refund', ['original' => $event->params['transaction_id'] ?? '']));
        });

        $bus->subscribe('refund', function (AnalyticsEvent $event) use (&$received): void {
            $received[] = 'refund';
        });

        $bus->publish(new AnalyticsEvent('purchase', ['transaction_id' => 'TXN-1']));

        // Both events should be received in order
        $this->assertSame(['purchase', 'refund'], $received);
    }

    public function test_forget_removes_subscribers(): void
    {
        $bus = new AnalyticsEventBus;
        $received = [];

        $bus->subscribe('purchase', function (AnalyticsEvent $event) use (&$received): void {
            $received[] = $event->name;
        });

        $bus->publish(new AnalyticsEvent('purchase'));
        $this->assertCount(1, $received);

        $bus->forget('purchase');

        $bus->publish(new AnalyticsEvent('purchase'));
        $this->assertCount(1, $received); // No new receives
    }

    public function test_flush_removes_all(): void
    {
        $bus = new AnalyticsEventBus;
        $received = [];

        $bus->subscribeAll(function (AnalyticsEvent $event) use (&$received): void {
            $received[] = $event->name;
        });

        $bus->subscribe('purchase', function (AnalyticsEvent $event) use (&$received): void {
            $received[] = $event->name;
        });

        $bus->flush();
        $bus->publish(new AnalyticsEvent('purchase'));

        $this->assertEmpty($received);
    }

    public function test_subscriber_count(): void
    {
        $bus = new AnalyticsEventBus;

        $this->assertSame(0, $bus->subscriberCount('purchase'));
        $this->assertFalse($bus->hasSubscribers('purchase'));
        $this->assertFalse($bus->hasGlobalSubscribers());

        $bus->subscribe('purchase', function (AnalyticsEvent $event): void {});

        $this->assertSame(1, $bus->subscriberCount('purchase'));
        $this->assertTrue($bus->hasSubscribers('purchase'));

        $bus->subscribeAll(function (AnalyticsEvent $event): void {});

        $this->assertTrue($bus->hasGlobalSubscribers());
        $this->assertSame(1, $bus->totalSubscriberCount()); // 0 named (already counted) + 1 global
    }

    public function test_registered_events(): void
    {
        $bus = new AnalyticsEventBus;

        $bus->subscribe('purchase', function (AnalyticsEvent $event): void {});
        $bus->subscribe('sign_up', function (AnalyticsEvent $event): void {});

        $events = $bus->registeredEvents();
        $this->assertContains('purchase', $events);
        $this->assertContains('sign_up', $events);
    }

    public function test_publish_many(): void
    {
        $bus = new AnalyticsEventBus;
        $received = [];

        $bus->subscribeAll(function (AnalyticsEvent $event) use (&$received): void {
            $received[] = $event->name;
        });

        $events = [
            new AnalyticsEvent('purchase'),
            new AnalyticsEvent('sign_up'),
            new AnalyticsEvent('page_view'),
        ];

        $bus->publishMany($events);

        $this->assertSame(['purchase', 'sign_up', 'page_view'], $received);
    }

    public function test_no_subscribers_does_not_error(): void
    {
        $bus = new AnalyticsEventBus;

        // Should not throw
        $bus->publish(new AnalyticsEvent('purchase', ['value' => 100]));
        $this->assertTrue(true);
    }

    // ── Regional Consent Service Tests ──────────────────────────────

    public function test_gdpr_region_defaults_to_denied(): void
    {
        $service = $this->createEnabledService();

        // EU countries
        $this->assertSame('denied', $service->getConsentDefault('DE'));
        $this->assertSame('denied', $service->getConsentDefault('FR'));
        $this->assertSame('denied', $service->getConsentDefault('GB'));
        $this->assertSame('denied', $service->getConsentDefault('BR'));
        $this->assertSame('denied', $service->getConsentDefault('CA'));
        $this->assertSame('denied', $service->getConsentDefault('JP'));
        $this->assertSame('denied', $service->getConsentDefault('IN'));
        $this->assertSame('denied', $service->getConsentDefault('CH'));
    }

    public function test_non_gdpr_region_defaults_to_granted(): void
    {
        $service = $this->createEnabledService();

        $this->assertSame('granted', $service->getConsentDefault('US'));
        $this->assertSame('granted', $service->getConsentDefault('AU'));
        $this->assertSame('granted', $service->getConsentDefault('MX'));
        $this->assertSame('granted', $service->getConsentDefault('NG'));
    }

    public function test_disabled_service_returns_default(): void
    {
        $service = $this->createService(enabled: false);

        $this->assertSame('granted', $service->getConsentDefault('DE')); // Would be 'denied' if enabled
        $this->assertSame('granted', $service->getConsentDefault('US'));
    }

    public function test_case_insensitive_country_code(): void
    {
        $service = $this->createEnabledService();

        $this->assertSame('denied', $service->getConsentDefault('de'));
        $this->assertSame('denied', $service->getConsentDefault('gb'));
        $this->assertSame('granted', $service->getConsentDefault('us'));
    }

    public function test_excluded_regions_skip_gdpr(): void
    {
        $service = $this->createEnabledService(excluded: ['CH']);

        $this->assertSame('granted', $service->getConsentDefault('CH')); // Excluded from GDPR
    }

    public function test_additional_regions_are_treated_as_gdpr(): void
    {
        $service = $this->createEnabledService(additional: ['AU']);

        $this->assertSame('denied', $service->getConsentDefault('AU')); // Additional GDPR region
    }

    public function test_consent_from_ip_with_geo_header(): void
    {
        $service = $this->createEnabledService();

        // Cloudflare header
        $this->assertSame('denied', $service->getConsentDefaultFromIp('1.2.3.4', [
            'cf-ipcountry' => 'DE',
        ]));

        // Vercel header
        $this->assertSame('granted', $service->getConsentDefaultFromIp('5.6.7.8', [
            'x-vercel-ip-country' => 'US',
        ]));

        // No headers — returns default
        $this->assertSame('granted', $service->getConsentDefaultFromIp('9.10.11.12'));
    }

    public function test_is_gdpr_region(): void
    {
        $service = $this->createEnabledService();

        $this->assertTrue($service->isGdprRegion('DE'));
        $this->assertTrue($service->isGdprRegion('GB'));
        $this->assertTrue($service->isGdprRegion('BR'));
        $this->assertFalse($service->isGdprRegion('US'));
        $this->assertFalse($service->isGdprRegion('AU'));
    }

    public function test_is_us_privacy_state(): void
    {
        $service = $this->createEnabledService();

        $this->assertTrue($service->isUsPrivacyState('CA'));
        $this->assertTrue($service->isUsPrivacyState('CO'));
        $this->assertTrue($service->isUsPrivacyState('CT'));
        $this->assertTrue($service->isUsPrivacyState('VA'));
        $this->assertFalse($service->isUsPrivacyState('NY'));
        $this->assertFalse($service->isUsPrivacyState('TX'));
    }

    public function test_get_gdpr_regions(): void
    {
        $service = $this->createEnabledService(additional: ['AU', 'NZ']);

        $regions = $service->getGdprRegions();

        $this->assertContains('DE', $regions);
        $this->assertContains('GB', $regions);
        $this->assertContains('AU', $regions); // Additional
        $this->assertContains('NZ', $regions); // Additional
        $this->assertGreaterThan(40, count($regions));
    }

    public function test_get_us_privacy_states(): void
    {
        $service = $this->createEnabledService();

        $states = $service->getUsPrivacyStates();

        $this->assertContains('CA', $states);
        $this->assertContains('CO', $states);
        $this->assertCount(11, $states);
    }

    public function test_summary(): void
    {
        $service = $this->createEnabledService(additional: ['AU']);

        $summary = $service->summary();

        $this->assertTrue($summary['enabled']);
        $this->assertSame('granted', $summary['default_consent']);
        $this->assertSame('denied', $summary['gdpr_default']);
        $this->assertGreaterThan(40, $summary['gdpr_regions_count']);
        $this->assertSame(11, $summary['us_privacy_states_count']);
        $this->assertContains('AU', $summary['additional_regions']);
    }

    public function test_is_enabled(): void
    {
        $this->assertTrue($this->createEnabledService()->isEnabled());
        $this->assertFalse($this->createService(enabled: false)->isEnabled());
    }

    public function test_custom_consent_defaults(): void
    {
        $service = $this->createEnabledService(
            defaultConsent: 'denied',
            gdprRegionDefault: 'granted',
        );

        // Non-GDPR regions use custom default
        $this->assertSame('denied', $service->getConsentDefault('US'));

        // GDPR regions use custom GDPR default
        $this->assertSame('granted', $service->getConsentDefault('DE'));
    }

    // ── Helper Methods ──────────────────────────────────────────────

    private function createEnabledService(
        array $additional = [],
        array $excluded = [],
        string $defaultConsent = 'granted',
        string $gdprRegionDefault = 'denied',
    ): RegionalConsentService {
        return $this->createService(
            enabled: true,
            additional: $additional,
            excluded: $excluded,
            defaultConsent: $defaultConsent,
            gdprRegionDefault: $gdprRegionDefault,
        );
    }

    private function createService(
        bool $enabled = false,
        array $additional = [],
        array $excluded = [],
        string $defaultConsent = 'granted',
        string $gdprRegionDefault = 'denied',
    ): RegionalConsentService {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);

        $config->method('get')
            ->with('zeroboiler.analytics.regional_consent')
            ->willReturn([
                'enabled' => $enabled,
                'additional_regions' => $additional,
                'excluded_regions' => $excluded,
                'default_consent' => $defaultConsent,
                'gdpr_default' => $gdprRegionDefault,
                'gdpr_region_default' => $gdprRegionDefault,
            ]);

        return new RegionalConsentService($config);
    }
}
