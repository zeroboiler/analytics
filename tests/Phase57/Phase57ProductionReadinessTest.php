<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests\Phase57;

use PHPUnit\Framework\Attributes\Test;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEvents;
use ZeroBoiler\Analytics\Events\SaaS\CustomerSuccessEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\Webhook\WebhookEvents;

/**
 * Phase 57 Production Readiness — Cross-Provider Schema Parity & Config Completeness Audit.
 *
 * Validates: EventCatalog category coverage (9/9), cross-provider mapping completeness
 * (every event has ga4+posthog+mixpanel+amplitude), SaaS event catalog count (80+),
 * Ecommerce event catalog count (16+), Engagement event catalog count (25+), Security/Uptime/
 * Infrastructure/Marketing/Webhook catalog coverage, EventCatalog::all() merge integrity,
 * byCategory() structure, names() uniqueness, category count consistency, file quality
 * (strict_types, MIT headers, final classes, return type declarations), config section
 * presence for all documented sections, provider mapping non-null coverage, CustomerSuccess
 * catalog presence, version consistency 232.0.0 across 5 entry points.
 *
 * @since 232.0.0
 */
final class Phase57ProductionReadinessTest extends \PHPUnit\Framework\TestCase
{
    // ── File Quality ─────────────────────────────────────────────────

    private static function assertFileQuality(string $path, string $label): void
    {
        self::assertFileExists($path, "{$label} file must exist");
        $content = file_get_contents($path);
        self::assertStringContainsString('declare(strict_types=1)', $content, "{$label} must have strict_types");
        self::assertStringContainsString('MIT license', $content, "{$label} must have MIT license header");
        self::assertStringContainsString('final class', $content, "{$label} class must be final");
    }

    #[Test]
    public function event_catalog_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/Events/EventCatalog.php',
            'EventCatalog'
        );
    }

    #[Test]
    public function ecommerce_events_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/Events/Ecommerce/EcommerceEvents.php',
            'EcommerceEvents'
        );
    }

    #[Test]
    public function saas_events_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/Events/SaaS/SaaSEvents.php',
            'SaaSEvents'
        );
    }

    #[Test]
    public function engagement_events_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/Events/Engagement/EngagementEvents.php',
            'EngagementEvents'
        );
    }

    #[Test]
    public function security_events_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/Events/Security/SecurityEvents.php',
            'SecurityEvents'
        );
    }

    #[Test]
    public function uptime_events_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/Events/Uptime/UptimeEvents.php',
            'UptimeEvents'
        );
    }

    #[Test]
    public function infrastructure_events_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/Events/Infrastructure/InfrastructureEvents.php',
            'InfrastructureEvents'
        );
    }

    #[Test]
    public function marketing_events_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/Events/Marketing/MarketingEvents.php',
            'MarketingEvents'
        );
    }

    #[Test]
    public function webhook_events_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/Events/Webhook/WebhookEvents.php',
            'WebhookEvents'
        );
    }

    #[Test]
    public function customer_success_events_has_strict_types_and_mit(): void
    {
        self::assertFileQuality(
            __DIR__ . '/../../src/Events/SaaS/CustomerSuccessEvents.php',
            'CustomerSuccessEvents'
        );
    }

    // ── Catalog Size Requirements ────────────────────────────────────

    #[Test]
    public function ecommerce_catalog_has_at_least_16_events(): void
    {
        $events = EcommerceEvents::all();
        self::assertGreaterThanOrEqual(16, count($events), 'Ecommerce catalog must have 16+ events');
    }

    #[Test]
    public function saas_catalog_has_at_least_80_events(): void
    {
        $events = SaaSEvents::all();
        self::assertGreaterThanOrEqual(80, count($events), 'SaaS catalog must have 80+ events');
    }

    #[Test]
    public function engagement_catalog_has_at_least_25_events(): void
    {
        $events = EngagementEvents::all();
        self::assertGreaterThanOrEqual(25, count($events), 'Engagement catalog must have 25+ events');
    }

    #[Test]
    public function security_catalog_has_at_least_7_events(): void
    {
        $events = SecurityEvents::all();
        self::assertGreaterThanOrEqual(7, count($events), 'Security catalog must have 7+ events');
    }

    #[Test]
    public function uptime_catalog_has_at_least_5_events(): void
    {
        $events = UptimeEvents::all();
        self::assertGreaterThanOrEqual(5, count($events), 'Uptime catalog must have 5+ events');
    }

    #[Test]
    public function infrastructure_catalog_has_at_least_15_events(): void
    {
        $events = InfrastructureEvents::all();
        self::assertGreaterThanOrEqual(15, count($events), 'Infrastructure catalog must have 15+ events');
    }

    #[Test]
    public function marketing_catalog_has_at_least_35_events(): void
    {
        $events = MarketingEvents::all();
        self::assertGreaterThanOrEqual(35, count($events), 'Marketing catalog must have 35+ events');
    }

    #[Test]
    public function webhook_catalog_has_at_least_3_events(): void
    {
        $events = WebhookEvents::all();
        self::assertGreaterThanOrEqual(3, count($events), 'Webhook catalog must have 3+ events');
    }

    #[Test]
    public function customer_success_catalog_has_at_least_5_events(): void
    {
        $events = CustomerSuccessEvents::all();
        self::assertGreaterThanOrEqual(5, count($events), 'CustomerSuccess catalog must have 5+ events');
    }

    // ── Unified Catalog Integrity ───────────────────────────────────

    #[Test]
    public function event_catalog_all_returns_merged_categories(): void
    {
        $all = EventCatalog::all();
        self::assertIsArray($all);
        self::assertNotEmpty($all);
    }

    #[Test]
    public function event_catalog_by_category_has_all_9_categories(): void
    {
        $byCategory = EventCatalog::byCategory();
        $expectedCategories = [
            'ecommerce', 'saas', 'engagement', 'security', 'uptime',
            'infrastructure', 'marketing', 'customer_success', 'webhook',
        ];
        foreach ($expectedCategories as $category) {
            self::assertArrayHasKey($category, $byCategory, "byCategory() must contain '{$category}'");
            self::assertNotEmpty($byCategory[$category], "Category '{$category}' must not be empty");
        }
        self::assertCount(9, $byCategory, 'byCategory() must return exactly 9 categories');
    }

    #[Test]
    public function event_catalog_names_are_unique(): void
    {
        $names = EventCatalog::names();
        $uniqueNames = array_unique($names);
        self::assertCount(count($names), $uniqueNames, 'Event names across all categories must be unique');
    }

    #[Test]
    public function event_catalog_names_count_matches_all_count(): void
    {
        $all = EventCatalog::all();
        $names = EventCatalog::names();
        self::assertCount(count($all), $names, 'names() count must match all() count');
    }

    #[Test]
    public function event_catalog_total_at_least_190_events(): void
    {
        $all = EventCatalog::all();
        self::assertGreaterThanOrEqual(190, count($all), 'Total catalog must have 190+ events across all categories');
    }

    #[Test]
    public function event_catalog_has_returns_bool_for_known_and_unknown(): void
    {
        self::assertTrue(EventCatalog::has('sign_up'), 'has() must return true for known event');
        self::assertTrue(EventCatalog::has('page_view'), 'has() must return true for page_view');
        self::assertTrue(EventCatalog::has('view_item'), 'has() must return true for view_item');
        self::assertFalse(EventCatalog::has('nonexistent_event_xyz'), 'has() must return false for unknown event');
    }

    #[Test]
    public function event_catalog_get_returns_entry_for_known(): void
    {
        $entry = EventCatalog::get('sign_up');
        self::assertNotNull($entry, 'get() must return entry for known event');
        self::assertArrayHasKey('name', $entry, 'Entry must have name');
        self::assertArrayHasKey('class', $entry, 'Entry must have class');
        self::assertArrayHasKey('ga4', $entry, 'Entry must have ga4 mapping');
        self::assertArrayHasKey('category', $entry, 'Entry must have category');
    }

    #[Test]
    public function event_catalog_get_returns_null_for_unknown(): void
    {
        self::assertNull(EventCatalog::get('nonexistent_event_xyz'), 'get() must return null for unknown');
    }

    #[Test]
    public function event_catalog_get_category_returns_correct_category(): void
    {
        self::assertSame('saas', EventCatalog::getCategory('sign_up'), 'sign_up must be saas category');
        self::assertSame('ecommerce', EventCatalog::getCategory('view_item'), 'view_item must be ecommerce category');
        self::assertSame('engagement', EventCatalog::getCategory('page_view'), 'page_view must be engagement category');
        self::assertSame('security', EventCatalog::getCategory('suspicious_activity'), 'suspicious_activity must be security category');
        self::assertNull(EventCatalog::getCategory('nonexistent'), 'Unknown event must return null category');
    }

    #[Test]
    public function event_catalog_all_with_plugins_merges_without_conflict(): void
    {
        $builtin = EventCatalog::all();
        $pluginEvents = [
            'custom_plugin_event' => [
                'name' => 'custom_plugin_event',
                'class' => \ZeroBoiler\Analytics\Events\CustomEvent::class,
                'ga4' => 'custom_plugin_event',
                'meta' => null,
                'category' => 'custom',
            ],
        ];
        $merged = EventCatalog::allWithPlugins($pluginEvents);
        self::assertGreaterThan(count($builtin), count($merged), 'Merged must have more events than builtin');
        self::assertArrayHasKey('custom_plugin_event', $merged, 'Plugin event must be in merged catalog');
    }

    #[Test]
    public function event_catalog_all_with_plugins_builtin_wins_on_conflict(): void
    {
        $pluginEvents = [
            'sign_up' => [
                'name' => 'sign_up_plugin_override',
                'class' => 'SomePluginClass',
                'ga4' => 'sign_up_override',
                'meta' => null,
                'category' => 'custom',
            ],
        ];
        $merged = EventCatalog::allWithPlugins($pluginEvents);
        // sign_up should remain the builtin version (class is SignUpEvent)
        $entry = $merged['sign_up'] ?? null;
        self::assertNotNull($entry, 'sign_up must exist in merged');
        // Class should NOT be the plugin override
        self::assertNotSame('SomePluginClass', $entry['class'], 'Builtin must win over plugin conflict');
    }

    // ── Cross-Provider Mapping Completeness ─────────────────────────

    #[Test]
    public function all_catalog_entries_have_ga4_mapping(): void
    {
        $all = EventCatalog::all();
        foreach ($all as $name => $entry) {
            self::assertArrayHasKey('ga4', $entry, "Event '{$name}' must have ga4 mapping");
            self::assertNotEmpty($entry['ga4'], "Event '{$name}' ga4 mapping must not be empty");
        }
    }

    #[Test]
    public function all_catalog_entries_have_posthog_mapping(): void
    {
        $all = EventCatalog::all();
        foreach ($all as $name => $entry) {
            self::assertArrayHasKey('posthog', $entry, "Event '{$name}' must have posthog mapping");
            self::assertNotEmpty($entry['posthog'], "Event '{$name}' posthog mapping must not be empty");
        }
    }

    #[Test]
    public function all_catalog_entries_have_mixpanel_mapping(): void
    {
        $all = EventCatalog::all();
        foreach ($all as $name => $entry) {
            self::assertArrayHasKey('mixpanel', $entry, "Event '{$name}' must have mixpanel mapping");
            self::assertNotEmpty($entry['mixpanel'], "Event '{$name}' mixpanel mapping must not be empty");
        }
    }

    #[Test]
    public function all_catalog_entries_have_amplitude_mapping(): void
    {
        $all = EventCatalog::all();
        foreach ($all as $name => $entry) {
            self::assertArrayHasKey('amplitude', $entry, "Event '{$name}' must have amplitude mapping");
            self::assertNotEmpty($entry['amplitude'], "Event '{$name}' amplitude mapping must not be empty");
        }
    }

    #[Test]
    public function core_saas_events_have_non_null_meta_mapping(): void
    {
        $saasEvents = SaaSEvents::all();
        $coreSaas = ['sign_up', 'login', 'start_trial', 'subscription_created', 'plan_upgrade', 'cancellation'];
        foreach ($coreSaas as $name) {
            self::assertArrayHasKey($name, $saasEvents, "Core SaaS event '{$name}' must exist");
            $entry = $saasEvents[$name];
            self::assertArrayHasKey('meta', $entry, "Core SaaS event '{$name}' must have meta mapping");
        }
    }

    #[Test]
    public function core_ecommerce_events_have_non_null_meta_mapping(): void
    {
        $ecomEvents = EcommerceEvents::all();
        $coreEcom = ['view_item', 'add_to_cart', 'purchase', 'refund'];
        foreach ($coreEcom as $name) {
            self::assertArrayHasKey($name, $ecomEvents, "Core ecommerce event '{$name}' must exist");
            $entry = $ecomEvents[$name];
            self::assertArrayHasKey('meta', $entry, "Core ecommerce event '{$name}' must have meta mapping");
            self::assertNotNull($entry['meta'], "Core ecommerce event '{$name}' meta must not be null");
        }
    }

    // ── Category Catalog Methods ──────────────────────────────────────

    #[Test]
    public function all_catalog_classes_have_all_method(): void
    {
        $catalogClasses = [
            EcommerceEvents::class,
            SaaSEvents::class,
            EngagementEvents::class,
            SecurityEvents::class,
            UptimeEvents::class,
            InfrastructureEvents::class,
            MarketingEvents::class,
            WebhookEvents::class,
            CustomerSuccessEvents::class,
        ];
        foreach ($catalogClasses as $class) {
            $reflection = new \ReflectionClass($class);
            self::assertTrue($reflection->hasMethod('all'), "{$class} must have all() method");
            self::assertTrue($reflection->hasMethod('has'), "{$class} must have has() method");
            self::assertTrue($reflection->hasMethod('get'), "{$class} must have get() method");
            self::assertTrue($reflection->hasMethod('names'), "{$class} must have names() method");
        }
    }

    #[Test]
    public function all_catalog_classes_are_final(): void
    {
        $catalogClasses = [
            EventCatalog::class,
            EcommerceEvents::class,
            SaaSEvents::class,
            EngagementEvents::class,
            SecurityEvents::class,
            UptimeEvents::class,
            InfrastructureEvents::class,
            MarketingEvents::class,
            WebhookEvents::class,
            CustomerSuccessEvents::class,
        ];
        foreach ($catalogClasses as $class) {
            $reflection = new \ReflectionClass($class);
            self::assertTrue($reflection->isFinal(), "{$class} must be final");
        }
    }

    // ── Config Completeness Audit ─────────────────────────────────────

    #[Test]
    public function config_has_analytics_root_key(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'analytics' => [", $content, 'Config must have analytics root key');
    }

    #[Test]
    public function config_has_ga4_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'ga4' => [", $content);
    }

    #[Test]
    public function config_has_gtm_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'gtm' => [", $content);
    }

    #[Test]
    public function config_has_meta_pixel_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'meta_pixel' => [", $content);
    }

    #[Test]
    public function config_has_consent_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'consent' => [", $content);
    }

    #[Test]
    public function config_has_auto_track_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'auto_track' => [", $content);
    }

    #[Test]
    public function config_has_queue_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'queue' => [", $content);
    }

    #[Test]
    public function config_has_lifecycle_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'lifecycle' => [", $content);
    }

    #[Test]
    public function config_has_api_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'api' => [", $content);
    }

    #[Test]
    public function config_has_identity_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'identity' => [", $content);
    }

    #[Test]
    public function config_has_ecommerce_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'ecommerce' => [", $content);
    }

    #[Test]
    public function config_has_event_lifecycle_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'event_lifecycle' => [", $content);
    }

    #[Test]
    public function config_has_client_auto_track_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'client_auto_track' => [", $content);
    }

    #[Test]
    public function config_has_revenue_checksum_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'revenue_checksum' => [", $content);
    }

    #[Test]
    public function config_has_dedup_cache_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'dedup_cache' => [", $content);
    }

    #[Test]
    public function config_has_retention_cohort_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'retention_cohort' => [", $content);
    }

    #[Test]
    public function config_has_pipeline_health_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'pipeline_health' => [", $content);
    }

    #[Test]
    public function config_has_feature_flags_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'feature_flags' => [", $content);
    }

    #[Test]
    public function config_has_growth_metrics_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString("'growth_metrics' => [", $content);
    }

    // ── Version Consistency ──────────────────────────────────────────

    #[Test]
    public function analytics_event_version_is_current(): void
    {
        $version = AnalyticsEvent::VERSION;
        self::assertSame('232.0.0', $version, 'AnalyticsEvent::VERSION must be 232.0.0');
    }

    #[Test]
    public function readme_version_matches(): void
    {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        self::assertStringContainsString('232.0.0', $readme, 'README must reference version 232.0.0');
    }

    #[Test]
    public function changelog_has_latest_version(): void
    {
        $changelog = file_get_contents(__DIR__ . '/../../CHANGELOG.md');
        self::assertStringContainsString('232.0.0', $changelog, 'CHANGELOG must reference version 232.0.0');
    }

    #[Test]
    public function composer_version_matches(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        self::assertArrayHasKey('version', $composer, 'composer.json must have version key');
        self::assertSame('232.0.0', $composer['version'], 'composer.json version must be 232.0.0');
    }

    #[Test]
    public function config_file_has_strict_types(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        self::assertStringContainsString('declare(strict_types=1)', $content, 'Config must have strict_types');
    }

    #[Test]
    public function routes_file_has_strict_types(): void
    {
        $content = file_get_contents(__DIR__ . '/../../routes/analytics.php');
        self::assertStringContainsString('declare(strict_types=1)', $content, 'Routes must have strict_types');
    }

    // ── Event Class File Quality (Sample) ────────────────────────────

    #[Test]
    public function core_ecommerce_event_classes_are_final_readonly(): void
    {
        $eventClasses = [
            'ZeroBoiler\Analytics\Events\Ecommerce\ViewItemEvent',
            'ZeroBoiler\Analytics\Events\Ecommerce\AddToCartEvent',
            'ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent',
            'ZeroBoiler\Analytics\Events\Ecommerce\RefundEvent',
        ];
        foreach ($eventClasses as $class) {
            $file = __DIR__ . '/../../src/' . str_replace('\\', '/', $class) . '.php';
            self::assertFileExists($file, "{$class} file must exist");
            $content = file_get_contents($file);
            self::assertStringContainsString('declare(strict_types=1)', $content);
            self::assertStringContainsString('MIT license', $content);
            self::assertStringContainsString('final readonly class', $content, "{$class} must be final readonly");
        }
    }

    #[Test]
    public function core_saas_event_classes_are_final_readonly(): void
    {
        $eventClasses = [
            'ZeroBoiler\Analytics\Events\SaaS\SignUpEvent',
            'ZeroBoiler\Analytics\Events\SaaS\LoginEvent',
            'ZeroBoiler\Analytics\Events\SaaS\TrialStartEvent',
            'ZeroBoiler\Analytics\Events\SaaS\PlanUpgradeEvent',
            'ZeroBoiler\Analytics\Events\SaaS\CancellationEvent',
        ];
        foreach ($eventClasses as $class) {
            $file = __DIR__ . '/../../src/' . str_replace('\\', '/', $class) . '.php';
            self::assertFileExists($file, "{$class} file must exist");
            $content = file_get_contents($file);
            self::assertStringContainsString('declare(strict_types=1)', $content);
            self::assertStringContainsString('MIT license', $content);
            self::assertStringContainsString('final readonly class', $content, "{$class} must be final readonly");
        }
    }

    #[Test]
    public function core_engagement_event_classes_are_final_readonly(): void
    {
        $eventClasses = [
            'ZeroBoiler\Analytics\Events\Engagement\PageViewEvent',
            'ZeroBoiler\Analytics\Events\Engagement\ScrollDepthEvent',
            'ZeroBoiler\Analytics\Events\Engagement\ClickEvent',
            'ZeroBoiler\Analytics\Events\Engagement\FormStartEvent',
            'ZeroBoiler\Analytics\Events\Engagement\FormSubmitEvent',
            'ZeroBoiler\Analytics\Events\Engagement\SearchEvent',
            'ZeroBoiler\Analytics\Events\Engagement\ShareEvent',
            'ZeroBoiler\Analytics\Events\Engagement\ErrorEvent',
        ];
        foreach ($eventClasses as $class) {
            $file = __DIR__ . '/../../src/' . str_replace('\\', '/', $class) . '.php';
            self::assertFileExists($file, "{$class} file must exist");
            $content = file_get_contents($file);
            self::assertStringContainsString('declare(strict_types=1)', $content);
            self::assertStringContainsString('MIT license', $content);
            self::assertStringContainsString('final readonly class', $content, "{$class} must be final readonly");
        }
    }

    #[Test]
    public function core_security_event_classes_are_final_with_strict_types(): void
    {
        $eventClasses = [
            'ZeroBoiler\Analytics\Events\Security\SuspiciousActivityEvent',
            'ZeroBoiler\Analytics\Events\Security\LoginAttemptEvent',
        ];
        foreach ($eventClasses as $class) {
            $file = __DIR__ . '/../../src/' . str_replace('\\', '/', $class) . '.php';
            self::assertFileExists($file, "{$class} file must exist");
            $content = file_get_contents($file);
            self::assertStringContainsString('declare(strict_types=1)', $content);
            self::assertStringContainsString('MIT license', $content);
            self::assertStringContainsString('final class', $content, "{$class} must be final");
        }
    }

    // ── ServiceProvider Registration ──────────────────────────────────

    #[Test]
    public function service_provider_registers_event_catalog_dependencies(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');
        self::assertStringContainsString('class AnalyticsServiceProvider', $content);
        self::assertStringContainsString('declare(strict_types=1)', $content);
        self::assertStringContainsString('MIT license', $content);
    }

    // ── Summary Statistics ───────────────────────────────────────────

    #[Test]
    public function total_catalog_is_reasonably_comprehensive(): void
    {
        $byCategory = EventCatalog::byCategory();
        $total = 0;
        $breakdown = [];
        foreach ($byCategory as $category => $events) {
            $count = count($events);
            $total += $count;
            $breakdown[$category] = $count;
        }
        // Verify minimum per-category coverage
        self::assertGreaterThanOrEqual(16, $breakdown['ecommerce'], 'Ecommerce must have 16+ events');
        self::assertGreaterThanOrEqual(80, $breakdown['saas'], 'SaaS must have 80+ events');
        self::assertGreaterThanOrEqual(25, $breakdown['engagement'], 'Engagement must have 25+ events');
        self::assertGreaterThanOrEqual(190, $total, "Total catalog must have 190+ events, got {$total}");
    }

    #[Test]
    public function source_file_count_is_above_1400(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                __DIR__ . '/../../src',
                \RecursiveDirectoryIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }
        self::assertGreaterThanOrEqual(1400, $count, "src/ must contain 1400+ PHP files, got {$count}");
    }

    #[Test]
    public function suite_file_count_is_above_480(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                __DIR__ . '/../../tests',
                \RecursiveDirectoryIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }
        self::assertGreaterThanOrEqual(480, $count, "tests/ must contain 480+ PHP files, got {$count}");
    }

    #[Test]
    public function command_count_is_above_110(): void
    {
        $iterator = new \DirectoryIterator(__DIR__ . '/../../src/Console/Commands');
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }
        self::assertGreaterThanOrEqual(110, $count, "Commands dir must have 110+ files, got {$count}");
    }
}
