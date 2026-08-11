<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Support\EventTransformer;

/**
 * @covers \ZeroBoiler\Analytics\Events\EventCatalog
 * @covers \ZeroBoiler\Analytics\Support\EventTransformer
 *
 * @since 10.6.0
 */
final class MixpanelAmplitudeParityTest extends TestCase
{
    // ─── Catalog: Mixpanel Fields ────────────────────────────────────────

    public function testAllCatalogEntriesHaveMixpanelField(): void
    {
        $all = EventCatalog::all();

        foreach ($all as $name => $entry) {
            $this->assertArrayHasKey(
                'mixpanel',
                $entry,
                "Event '{$name}' is missing 'mixpanel' field in catalog.",
            );
            $this->assertIsString(
                $entry['mixpanel'],
                "Event '{$name}' has non-string 'mixpanel' field.",
            );
        }
    }

    public function testAllCatalogEntriesHaveAmplitudeField(): void
    {
        $all = EventCatalog::all();

        foreach ($all as $name => $entry) {
            $this->assertArrayHasKey(
                'amplitude',
                $entry,
                "Event '{$name}' is missing 'amplitude' field in catalog.",
            );
            $this->assertIsString(
                $entry['amplitude'],
                "Event '{$name}' has non-string 'amplitude' field.",
            );
        }
    }

    // ─── EventCatalog: allMixpanelNames ──────────────────────────────────

    public function testAllMixpanelNamesReturnsNonEmptyArray(): void
    {
        $names = EventCatalog::allMixpanelNames();

        $this->assertIsArray($names);
        $this->assertNotEmpty($names);
    }

    public function testAllMixpanelNamesContainsExpectedEcommerceEvents(): void
    {
        $names = EventCatalog::allMixpanelNames();

        $this->assertContains('Purchase', $names);
        $this->assertContains('Add to Cart', $names);
        $this->assertContains('Refund', $names);
    }

    public function testAllMixpanelNamesContainsExpectedSaasEvents(): void
    {
        $names = EventCatalog::allMixpanelNames();

        $this->assertContains('Sign Up', $names);
        $this->assertContains('Login', $names);
        $this->assertContains('Subscribe', $names);
    }

    // ─── EventCatalog: allAmplitudeNames ──────────────────────────────────

    public function testAllAmplitudeNamesReturnsNonEmptyArray(): void
    {
        $names = EventCatalog::allAmplitudeNames();

        $this->assertIsArray($names);
        $this->assertNotEmpty($names);
    }

    public function testAllAmplitudeNamesUsesPastTense(): void
    {
        $names = EventCatalog::allAmplitudeNames();

        // Amplitude prefers past-tense: "Completed Order", "Added to Cart"
        $this->assertContains('Completed Order', $names);
        $this->assertContains('Added to Cart', $names);
        $this->assertContains('Refunded Order', $names);
    }

    public function testAllAmplitudeNamesContainsSaasEvents(): void
    {
        $names = EventCatalog::allAmplitudeNames();

        $this->assertContains('Signed Up', $names);
        $this->assertContains('Logged In', $names);
        $this->assertContains('Subscribed', $names);
    }

    // ─── EventCatalog: mixpanelNameFor / amplitudeNameFor ────────────────

    public function testMixpanelNameForReturnsExpectedName(): void
    {
        $this->assertSame(
            'Add to Cart',
            EventCatalog::mixpanelNameFor('add_to_cart'),
        );
        $this->assertSame(
            'Purchase',
            EventCatalog::mixpanelNameFor('purchase'),
        );
        $this->assertSame(
            'Sign Up',
            EventCatalog::mixpanelNameFor('sign_up'),
        );
    }

    public function testMixpanelNameForUnknownEventReturnsNull(): void
    {
        $this->assertNull(EventCatalog::mixpanelNameFor('nonexistent_event'));
    }

    public function testAmplitudeNameForReturnsExpectedName(): void
    {
        $this->assertSame(
            'Added to Cart',
            EventCatalog::amplitudeNameFor('add_to_cart'),
        );
        $this->assertSame(
            'Completed Order',
            EventCatalog::amplitudeNameFor('purchase'),
        );
        $this->assertSame(
            'Signed Up',
            EventCatalog::amplitudeNameFor('sign_up'),
        );
    }

    public function testAmplitudeNameForUnknownEventReturnsNull(): void
    {
        $this->assertNull(EventCatalog::amplitudeNameFor('nonexistent_event'));
    }

    // ─── EventCatalog: byProvider includes mixpanel/amplitude ──────────

    public function testByProviderIncludesMixpanelAndAmplitude(): void
    {
        $byProvider = EventCatalog::byProvider();

        $this->assertArrayHasKey('mixpanel', $byProvider);
        $this->assertArrayHasKey('amplitude', $byProvider);
        $this->assertNotEmpty($byProvider['mixpanel']);
        $this->assertNotEmpty($byProvider['amplitude']);
    }

    public function testByProviderMixpanelUsesTitleCase(): void
    {
        $byProvider = EventCatalog::byProvider();

        $this->assertContains('Purchase', $byProvider['mixpanel']);
        $this->assertContains('Add to Cart', $byProvider['mixpanel']);
    }

    public function testByProviderAmplitudeUsesPastTense(): void
    {
        $byProvider = EventCatalog::byProvider();

        $this->assertContains('Completed Order', $byProvider['amplitude']);
        $this->assertContains('Added to Cart', $byProvider['amplitude']);
    }

    // ─── EventCatalog: providerCoverage ──────────────────────────────────

    public function testProviderCoverageIncludesMixpanelAndAmplitude(): void
    {
        $coverage = EventCatalog::providerCoverage();

        $this->assertArrayHasKey('mixpanel', $coverage);
        $this->assertArrayHasKey('amplitude', $coverage);
        $this->assertArrayHasKey('mixpanel', $coverage['counts']);
        $this->assertArrayHasKey('amplitude', $coverage['counts']);
        $this->assertGreaterThan(0, $coverage['counts']['mixpanel']);
        $this->assertGreaterThan(0, $coverage['counts']['amplitude']);
    }

    // ─── EventCatalog: summary ───────────────────────────────────────────

    public function testSummaryIncludesMixpanelAndAmplitudeCounts(): void
    {
        $summary = EventCatalog::summary();

        $this->assertArrayHasKey('with_mixpanel', $summary);
        $this->assertArrayHasKey('with_amplitude', $summary);
        $this->assertGreaterThan(0, $summary['with_mixpanel']);
        $this->assertGreaterThan(0, $summary['with_amplitude']);
        // All events should have mixpanel and amplitude fields
        $this->assertSame($summary['total'], $summary['with_mixpanel']);
        $this->assertSame($summary['total'], $summary['with_amplitude']);
    }

    // ─── EventCatalog: allProviderMappingsMatrix ─────────────────────────

    public function testAllProviderMappingsMatrixIncludesMixpanelAndAmplitude(): void
    {
        $matrix = EventCatalog::allProviderMappingsMatrix();

        $this->assertNotEmpty($matrix);

        foreach ($matrix as $name => $mapping) {
            $this->assertArrayHasKey('mixpanel', $mapping, "Event '{$name}' missing 'mixpanel' in mapping matrix.");
            $this->assertArrayHasKey('amplitude', $mapping, "Event '{$name}' missing 'amplitude' in mapping matrix.");
        }
    }

    public function testMappingMatrixHasCorrectMixpanelNames(): void
    {
        $matrix = EventCatalog::allProviderMappingsMatrix();

        $this->assertSame('Add to Cart', $matrix['add_to_cart']['mixpanel']);
        $this->assertSame('Purchase', $matrix['purchase']['mixpanel']);
        $this->assertSame('Sign Up', $matrix['sign_up']['mixpanel']);
    }

    public function testMappingMatrixHasCorrectAmplitudeNames(): void
    {
        $matrix = EventCatalog::allProviderMappingsMatrix();

        $this->assertSame('Added to Cart', $matrix['add_to_cart']['amplitude']);
        $this->assertSame('Completed Order', $matrix['purchase']['amplitude']);
        $this->assertSame('Signed Up', $matrix['sign_up']['amplitude']);
    }

    // ─── Category-level helper methods ───────────────────────────────────

    public function testEcommerceEventsHasMixpanelNames(): void
    {
        $names = EcommerceEvents::mixpanelNames();

        $this->assertNotEmpty($names);
        $this->assertContains('Purchase', $names);
        $this->assertContains('Add to Cart', $names);
    }

    public function testEcommerceEventsHasAmplitudeNames(): void
    {
        $names = EcommerceEvents::amplitudeNames();

        $this->assertNotEmpty($names);
        $this->assertContains('Completed Order', $names);
        $this->assertContains('Added to Cart', $names);
    }

    public function testSaasEventsHasMixpanelNames(): void
    {
        $names = SaaSEvents::mixpanelNames();

        $this->assertNotEmpty($names);
        $this->assertContains('Sign Up', $names);
    }

    public function testSaasEventsHasAmplitudeNames(): void
    {
        $names = SaaSEvents::amplitudeNames();

        $this->assertNotEmpty($names);
        $this->assertContains('Signed Up', $names);
    }

    public function testEngagementEventsHasMixpanelNames(): void
    {
        $names = EngagementEvents::mixpanelNames();

        $this->assertNotEmpty($names);
        $this->assertContains('Page View', $names);
    }

    public function testEngagementEventsHasAmplitudeNames(): void
    {
        $names = EngagementEvents::amplitudeNames();

        $this->assertNotEmpty($names);
        $this->assertContains('Page View', $names);
    }

    public function testSecurityEventsHasMixpanelNames(): void
    {
        $names = SecurityEvents::mixpanelNames();

        $this->assertNotEmpty($names);
        $this->assertContains('Login Attempt', $names);
    }

    public function testSecurityEventsHasAmplitudeNames(): void
    {
        $names = SecurityEvents::amplitudeNames();

        $this->assertNotEmpty($names);
        $this->assertContains('Login Attempt', $names);
    }

    public function testUptimeEventsHasMixpanelNames(): void
    {
        $names = UptimeEvents::mixpanelNames();

        $this->assertNotEmpty($names);
        $this->assertContains('Service Up', $names);
    }

    public function testUptimeEventsHasAmplitudeNames(): void
    {
        $names = UptimeEvents::amplitudeNames();

        $this->assertNotEmpty($names);
        $this->assertContains('Service Up', $names);
    }

    // ─── EventTransformer ───────────────────────────────────────────────

    public function testSaasToMixpanelEventMapReturnsNonEmptyArray(): void
    {
        $map = EventTransformer::saasToMixpanelEventMap();

        $this->assertNotEmpty($map);
        $this->assertSame('Sign Up', $map['sign_up']);
        $this->assertSame('Add to Cart', $map['add_to_cart']);
        $this->assertSame('Purchase', $map['purchase']);
    }

    public function testSaasToAmplitudeEventMapReturnsNonEmptyArray(): void
    {
        $map = EventTransformer::saasToAmplitudeEventMap();

        $this->assertNotEmpty($map);
        $this->assertSame('Signed Up', $map['sign_up']);
        $this->assertSame('Added to Cart', $map['add_to_cart']);
        $this->assertSame('Completed Order', $map['purchase']);
    }

    public function testTransformForProviderMixpanel(): void
    {
        $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
            name: 'add_to_cart',
            params: ['item_id' => 'SKU-123'],
        );

        $transformed = EventTransformer::transformForProvider($event, 'mixpanel');

        $this->assertSame('Add to Cart', $transformed->name);
        $this->assertSame(['item_id' => 'SKU-123'], $transformed->params);
    }

    public function testTransformForProviderAmplitude(): void
    {
        $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99],
        );

        $transformed = EventTransformer::transformForProvider($event, 'amplitude');

        $this->assertSame('Completed Order', $transformed->name);
        $this->assertSame(['value' => 99.99], $transformed->params);
    }

    public function testTransformForProviderPassthroughWhenUnknown(): void
    {
        $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
            name: 'custom_unknown_event',
            params: ['foo' => 'bar'],
        );

        $transformed = EventTransformer::transformForProvider($event, 'mixpanel');

        $this->assertSame('custom_unknown_event', $transformed->name);
    }

    // ─── Mixpanel vs Amplitude naming conventions ─────────────────────────

    public function testMixpanelUsesTitleCaseNotSnakeCase(): void
    {
        $matrix = EventCatalog::allProviderMappingsMatrix();

        foreach ($matrix as $name => $mapping) {
            $mixpanel = $mapping['mixpanel'];
            // Mixpanel names should NOT contain underscores
            $this->assertDoesNotMatchRegularExpression(
                '/_/',
                $mixpanel,
                "Mixpanel event for '{$name}' should use Title Case, not snake_case: {$mixpanel}",
            );
        }
    }

    public function testAmplitudeUsesDifferentNamesThanMixpanel(): void
    {
        $matrix = EventCatalog::allProviderMappingsMatrix();

        $diffCount = 0;
        foreach ($matrix as $name => $mapping) {
            if ($mapping['mixpanel'] !== $mapping['amplitude']) {
                $diffCount++;
            }
        }

        // At least some events should have different names
        $this->assertGreaterThan(10, $diffCount, 'Expected more than 10 events with different Mixpanel/Amplitude names.');
    }
}
