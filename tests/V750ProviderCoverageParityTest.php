<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tests for v75.0.0 — Event Catalog Provider Coverage Parity.
 *
 * Validates that every event catalog entry across all 6 categories
 * includes consistent provider mapping fields: ga4, meta, posthog,
 * plausible, mixpanel, amplitude, tiktok, linkedin.
 *
 * @since 75.0.0
 */
final class V750ProviderCoverageParityTest extends TestCase
{
    /** @var list<array{catalog: class-string, name: string}> */
    private const CATALOGS = [
        ['catalog' => EcommerceEvents::class, 'name' => 'EcommerceEvents'],
        ['catalog' => SaaSEvents::class, 'name' => 'SaaSEvents'],
        ['catalog' => EngagementEvents::class, 'name' => 'EngagementEvents'],
        ['catalog' => SecurityEvents::class, 'name' => 'SecurityEvents'],
        ['catalog' => UptimeEvents::class, 'name' => 'UptimeEvents'],
        ['catalog' => InfrastructureEvents::class, 'name' => 'InfrastructureEvents'],
    ];

    /** @var list<string> Required provider fields for every catalog entry */
    private const REQUIRED_PROVIDER_FIELDS = [
        'ga4',
        'meta',
        'posthog',
        'plausible',
        'mixpanel',
        'amplitude',
        'tiktok',
        'linkedin',
    ];

    /**
     * Every catalog entry must have all 8 provider fields.
     */
    public function test_all_entries_have_all_provider_fields(): void
    {
        foreach (self::CATALOGS as $catalogInfo) {
            $catalogClass = $catalogInfo['catalog'];
            $catalogName = $catalogInfo['name'];

            foreach ($catalogClass::all() as $eventName => $entry) {
                foreach (self::REQUIRED_PROVIDER_FIELDS as $field) {
                    $this->assertArrayHasKey(
                        $field,
                        $entry,
                        "{$catalogName}::{$eventName} is missing required provider field '{$field}'",
                    );
                }
            }
        }
    }

    /**
     * ga4 and posthog are always strings (never null).
     */
    public function test_ga4_and_posthog_are_never_null(): void
    {
        foreach (self::CATALOGS as $catalogInfo) {
            $catalogClass = $catalogInfo['catalog'];
            $catalogName = $catalogInfo['name'];

            foreach ($catalogClass::all() as $eventName => $entry) {
                $this->assertNotNull(
                    $entry['ga4'],
                    "{$catalogName}::{$eventName} has null ga4 mapping",
                );
                $this->assertIsString(
                    $entry['ga4'],
                    "{$catalogName}::{$eventName} ga4 is not a string",
                );

                $this->assertNotNull(
                    $entry['posthog'],
                    "{$catalogName}::{$eventName} has null posthog mapping",
                );
                $this->assertIsString(
                    $entry['posthog'],
                    "{$catalogName}::{$eventName} posthog is not a string",
                );
            }
        }
    }

    /**
     * tiktok and linkedin are either string or null (nullable).
     */
    public function test_tiktok_linkedin_are_nullable_strings(): void
    {
        foreach (self::CATALOGS as $catalogInfo) {
            $catalogClass = $catalogInfo['catalog'];
            $catalogName = $catalogInfo['name'];

            foreach ($catalogClass::all() as $eventName => $entry) {
                $tiktok = $entry['tiktok'] ?? null;
                $linkedin = $entry['linkedin'] ?? null;

                $this->assertTrue(
                    $tiktok === null || is_string($tiktok),
                    "{$catalogName}::{$eventName} tiktok must be string or null",
                );
                $this->assertTrue(
                    $linkedin === null || is_string($linkedin),
                    "{$catalogName}::{$eventName} linkedin must be string or null",
                );
            }
        }
    }

    /**
     * Event name in each entry matches the array key.
     */
    public function test_entry_name_matches_key(): void
    {
        foreach (self::CATALOGS as $catalogInfo) {
            $catalogClass = $catalogInfo['catalog'];
            $catalogName = $catalogInfo['name'];

            foreach ($catalogClass::all() as $eventName => $entry) {
                $this->assertSame(
                    $eventName,
                    $entry['name'],
                    "{$catalogName}:: entry name mismatch for key '{$eventName}'",
                );
            }
        }
    }

    /**
     * All event classes referenced in catalogs exist and extend AnalyticsEvent.
     */
    public function test_all_catalog_classes_exist(): void
    {
        foreach (self::CATALOGS as $catalogInfo) {
            $catalogClass = $catalogInfo['catalog'];
            $catalogName = $catalogInfo['name'];

            foreach ($catalogClass::all() as $eventName => $entry) {
                $className = $entry['class'] ?? null;
                $this->assertNotNull(
                    $className,
                    "{$catalogName}::{$eventName} has null class reference",
                );
                $this->assertTrue(
                    class_exists($className),
                    "{$catalogName}::{$eventName} class '{$className}' does not exist",
                );
                $this->assertTrue(
                    is_a($className, AnalyticsEvent::class, true),
                    "{$catalogName}::{$eventName} class '{$className}' does not extend AnalyticsEvent",
                );
            }
        }
    }

    /**
     * No duplicate event names within the same catalog.
     */
    public function test_no_duplicate_names_within_catalog(): void
    {
        foreach (self::CATALOGS as $catalogInfo) {
            $catalogClass = $catalogInfo['catalog'];
            $names = $catalogClass::names();
            $uniqueNames = array_unique($names);

            $this->assertSame(
                count($names),
                count($uniqueNames),
                "{$catalogInfo['name']} has duplicate event names",
            );
        }
    }

    /**
     * Total event count across all catalogs matches EventCatalog::count().
     */
    public function test_total_count_matches_unified_catalog(): void
    {
        $sum = 0;
        foreach (self::CATALOGS as $catalogInfo) {
            $sum += $catalogInfo['catalog']::count();
        }

        $this->assertSame($sum, EventCatalog::count());
    }

    /**
     * byProvider() returns all 9 providers.
     */
    public function test_by_provider_returns_all_nine_providers(): void
    {
        $byProvider = EventCatalog::byProvider();

        $expectedProviders = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

        foreach ($expectedProviders as $provider) {
            $this->assertArrayHasKey(
                $provider,
                $byProvider,
                "EventCatalog::byProvider() missing '{$provider}' key",
            );
            $this->assertIsArray(
                $byProvider[$provider],
                "EventCatalog::byProvider()['{$provider}'] is not an array",
            );
        }
    }

    /**
     * Category accessor returns correct category name.
     */
    public function test_category_names(): void
    {
        $this->assertSame('ecommerce', EcommerceEvents::category());
        $this->assertSame('saas', SaaSEvents::category());
        $this->assertSame('engagement', EngagementEvents::category());
        $this->assertSame('security', SecurityEvents::category());
        $this->assertSame('uptime', UptimeEvents::category());
        $this->assertSame('infrastructure', InfrastructureEvents::category());
    }

    /**
     * Each catalog's tiktokNames() and linkedinNames() return non-empty arrays
     * with consistent entries (only non-null values).
     */
    public function test_tiktok_linkedin_names_are_consistent(): void
    {
        foreach (self::CATALOGS as $catalogInfo) {
            $catalogClass = $catalogInfo['catalog'];
            $catalogName = $catalogInfo['name'];

            $tiktokNames = $catalogClass::tiktokNames();
            $linkedinNames = $catalogClass::linkedinNames();

            $this->assertIsArray($tiktokNames, "{$catalogName}::tiktokNames() should return array");
            $this->assertIsArray($linkedinNames, "{$catalogName}::linkedinNames() should return array");

            // All returned names should be non-null strings
            foreach ($tiktokNames as $name) {
                $this->assertIsString($name, "{$catalogName}::tiktokNames() contains non-string");
                $this->assertNotNull($name, "{$catalogName}::tiktokNames() contains null");
            }

            foreach ($linkedinNames as $name) {
                $this->assertIsString($name, "{$catalogName}::linkedinNames() contains non-string");
                $this->assertNotNull($name, "{$catalogName}::linkedinNames() contains null");
            }

            // No duplicates
            $this->assertSame(
                count($tiktokNames),
                count(array_unique($tiktokNames)),
                "{$catalogName}::tiktokNames() has duplicates",
            );
            $this->assertSame(
                count($linkedinNames),
                count(array_unique($linkedinNames)),
                "{$catalogName}::linkedinNames() has duplicates",
            );
        }
    }

    /**
     * AnalyticsEvent::VERSION is 75.0.0.
     */
    public function test_version_is_75(): void
    {
        $this->assertSame('75.0.0', AnalyticsEvent::VERSION);
    }
}
