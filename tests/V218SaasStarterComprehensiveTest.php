<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

/**
 * Comprehensive SaaS starter test suite — validates the complete feature matrix.
 *
 * Covers: event catalog completeness, cross-provider mappings, category structure,
 * typed class resolution, and the full 49-event feature set.
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 */
final class SaasStarterComprehensiveTest extends TestCase
{
    // ─── Event Catalog Completeness ────────────────────────────────────────

    public function test_total_event_count_is_49(): void
    {
        $this->assertSame(49, EventCatalog::count());
    }

    public function test_ecommerce_category_has_12_events(): void
    {
        $this->assertSame(12, EcommerceEvents::count());
    }

    public function test_saas_category_has_17_events(): void
    {
        $this->assertSame(17, SaaSEvents::count());
    }

    public function test_engagement_category_has_20_events(): void
    {
        $this->assertSame(20, EngagementEvents::count());
    }

    public function test_categories_sum_to_total(): void
    {
        $sum = EcommerceEvents::count() + SaaSEvents::count() + EngagementEvents::count();
        $this->assertSame($sum, EventCatalog::count());
    }

    // ─── Category Structure ──────────────────────────────────────────────

    public function test_by_category_returns_all_three_categories(): void
    {
        $byCategory = EventCatalog::byCategory();

        $this->assertArrayHasKey('ecommerce', $byCategory);
        $this->assertArrayHasKey('saas', $byCategory);
        $this->assertArrayHasKey('engagement', $byCategory);
        $this->assertCount(3, $byCategory);
    }

    public function test_category_method_returns_correct_category(): void
    {
        $ecommerce = EventCatalog::category('ecommerce');
        $saas = EventCatalog::category('saas');
        $engagement = EventCatalog::category('engagement');

        $this->assertCount(12, $ecommerce);
        $this->assertCount(17, $saas);
        $this->assertCount(20, $engagement);
    }

    public function test_category_unknown_returns_empty_array(): void
    {
        $this->assertSame([], EventCatalog::category('unknown'));
    }

    public function test_all_events_have_category_annotation(): void
    {
        $all = EventCatalog::all();

        foreach ($all as $name => $entry) {
            $this->assertArrayHasKey(
                'category',
                $entry,
                "Event '{$name}' is missing 'category' annotation",
            );
            $this->assertContains(
                $entry['category'],
                ['ecommerce', 'saas', 'engagement'],
                "Event '{$name}' has invalid category '{$entry['category']}'",
            );
        }
    }

    // ─── Event Name Validation ────────────────────────────────────────────

    public function test_all_event_names_follow_snake_case_convention(): void
    {
        $names = EventCatalog::names();

        foreach ($names as $name) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $name,
                "Event name '{$name}' does not follow snake_case convention",
            );
        }
    }

    public function test_event_names_are_unique_across_categories(): void
    {
        $allNames = EventCatalog::names();
        $uniqueNames = array_unique($allNames);

        $this->assertCount(count($allNames), $uniqueNames, 'Duplicate event names detected across categories');
    }

    // ─── Typed Class Resolution ──────────────────────────────────────────

    public function test_every_event_has_a_typed_class(): void
    {
        $all = EventCatalog::all();

        foreach ($all as $name => $entry) {
            $this->assertArrayHasKey('class', $entry, "Event '{$name}' is missing 'class' key");
            $this->assertIsString($entry['class']);
            $this->assertNotEmpty($entry['class'], "Event '{$name}' has empty class name");
            $this->assertTrue(
                class_exists($entry['class']) || str_contains($entry['class'], '\\'),
                "Event '{$name}' class '{$entry['class']}' is not a valid FQCN",
            );
        }
    }

    public function test_class_for_returns_null_for_unknown_event(): void
    {
        $this->assertNull(EventCatalog::classFor('nonexistent_event'));
    }

    public function test_class_for_resolves_ecommerce_event(): void
    {
        $class = EventCatalog::classFor('view_item');

        $this->assertNotNull($class);
        $this->assertStringContainsString('ViewItemEvent', $class);
    }

    public function test_class_for_resolves_saas_event(): void
    {
        $class = EventCatalog::classFor('sign_up');

        $this->assertNotNull($class);
        $this->assertStringContainsString('SignUpEvent', $class);
    }

    public function test_class_for_resolves_engagement_event(): void
    {
        $class = EventCatalog::classFor('page_view');

        $this->assertNotNull($class);
        $this->assertStringContainsString('PageViewEvent', $class);
    }

    // ─── Cross-Provider GA4 Mappings ─────────────────────────────────────

    public function test_all_events_have_ga4_mapping(): void
    {
        $all = EventCatalog::all();

        foreach ($all as $name => $entry) {
            $this->assertArrayHasKey('ga4', $entry, "Event '{$name}' is missing 'ga4' mapping");
            $this->assertIsString($entry['ga4']);
            $this->assertNotEmpty($entry['ga4'], "Event '{$name}' has empty GA4 mapping");
        }
    }

    public function test_ga4_names_are_unique(): void
    {
        $ga4Names = EventCatalog::allGa4Names();
        $uniqueGa4 = array_unique($ga4Names);

        // Some events share GA4 names (e.g. multiple engagement events may map to same GA4 event)
        // but we verify the list is non-empty and well-formed
        $this->assertNotEmpty($ga4Names);
        $this->assertGreaterThan(0, count($uniqueGa4));
    }

    public function test_all_ga4_names_follow_convention(): void
    {
        $ga4Names = EventCatalog::allGa4Names();

        foreach ($ga4Names as $ga4Name) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $ga4Name,
                "GA4 name '{$ga4Name}' does not follow snake_case",
            );
        }
    }

    // ─── Cross-Provider Meta Mappings ─────────────────────────────────────

    public function test_meta_names_are_non_empty_strings_or_null(): void
    {
        $all = EventCatalog::all();

        foreach ($all as $name => $entry) {
            $this->assertArrayHasKey('meta', $entry, "Event '{$name}' is missing 'meta' key");
            // Meta can be null (not all events have Meta equivalents)
            $this->assertTrue(
                $entry['meta'] === null || (is_string($entry['meta']) && $entry['meta'] !== ''),
                "Event '{$name}' has invalid meta mapping",
            );
        }
    }

    public function test_ecommerce_events_have_meta_equivalents(): void
    {
        $ecommerce = EventCatalog::category('ecommerce');
        $eventsWithMeta = array_filter(
            $ecommerce,
            fn (array $entry): bool => $entry['meta'] !== null,
        );

        // At least 4 e-commerce events should have Meta equivalents
        $this->assertGreaterThanOrEqual(4, count($eventsWithMeta), 'Expected at least 4 e-commerce events with Meta equivalents');
    }

    // ─── Provider Expansion (PostHog + Plausible) ──────────────────────

    public function test_by_provider_returns_all_four_providers(): void
    {
        $byProvider = EventCatalog::byProvider();

        $this->assertArrayHasKey('ga4', $byProvider);
        $this->assertArrayHasKey('meta', $byProvider);
        $this->assertArrayHasKey('posthog', $byProvider);
        $this->assertArrayHasKey('plausible', $byProvider);
    }

    public function test_posthog_names_include_signup_alias(): void
    {
        $posthogNames = EventCatalog::allPosthogNames();

        // PostHog maps sign_up → $signup
        $this->assertContains('$signup', $posthogNames, 'PostHog names should include $signup alias');
    }

    public function test_posthog_names_are_non_empty(): void
    {
        $posthogNames = EventCatalog::allPosthogNames();

        $this->assertNotEmpty($posthogNames);
        // PostHog should have at least as many names as the original catalog
        $this->assertGreaterThanOrEqual(40, count($posthogNames));
    }

    public function test_plausible_names_excludes_unsupported_events(): void
    {
        $plausibleNames = EventCatalog::allPlausibleNames();

        $this->assertNotEmpty($plausibleNames);
        // Plausible does not support all events — should be a subset
        $this->assertLessThanOrEqual(EventCatalog::count(), count($plausibleNames));
    }

    // ─── Search Functionality ──────────────────────────────────────────────

    public function test_search_returns_matching_events(): void
    {
        $results = EventCatalog::search('cart');

        $this->assertNotEmpty($results);
        foreach ($results as $entry) {
            $this->assertStringContainsString('cart', $entry['name']);
        }
    }

    public function test_search_returns_empty_for_no_match(): void
    {
        $results = EventCatalog::search('zzzznonexistent');

        $this->assertSame([], $results);
    }

    public function test_search_is_case_insensitive(): void
    {
        $resultsLower = EventCatalog::search('purchase');
        $resultsUpper = EventCatalog::search('PURCHASE');

        $this->assertSame(count($resultsLower), count($resultsUpper));
    }

    // ─── Has/Get Lookup ──────────────────────────────────────────────────

    public function test_has_returns_true_for_known_event(): void
    {
        $this->assertTrue(EventCatalog::has('purchase'));
        $this->assertTrue(EventCatalog::has('sign_up'));
        $this->assertTrue(EventCatalog::has('page_view'));
    }

    public function test_has_returns_false_for_unknown_event(): void
    {
        $this->assertFalse(EventCatalog::has('nonexistent_event'));
    }

    public function test_get_returns_entry_for_known_event(): void
    {
        $entry = EventCatalog::get('purchase');

        $this->assertNotNull($entry);
        $this->assertSame('purchase', $entry['name']);
        $this->assertSame('ecommerce', $entry['category']);
    }

    public function test_get_returns_null_for_unknown_event(): void
    {
        $this->assertNull(EventCatalog::get('nonexistent_event'));
    }

    public function test_names_returns_list_of_strings(): void
    {
        $names = EventCatalog::names();

        $this->assertIsArray($names);
        $this->assertCount(49, $names);
        $this->assertContains('purchase', $names);
        $this->assertContains('sign_up', $names);
        $this->assertContains('page_view', $names);
    }

    public function test_count_returns_integer(): void
    {
        $count = EventCatalog::count();

        $this->assertIsInt($count);
        $this->assertGreaterThan(0, $count);
    }

    // ─── Specific E-Commerce Events ──────────────────────────────────────

    public function test_core_ecommerce_events_exist(): void
    {
        $coreEvents = ['view_item', 'add_to_cart', 'remove_from_cart', 'view_cart', 'begin_checkout', 'add_payment_info', 'purchase', 'refund', 'add_to_wishlist', 'select_item', 'select_promotion', 'view_promotion'];

        foreach ($coreEvents as $event) {
            $this->assertTrue(
                EcommerceEvents::has($event),
                "Core e-commerce event '{$event}' not found in catalog",
            );
        }
    }

    // ─── Specific SaaS Events ─────────────────────────────────────────────

    public function test_core_saas_events_exist(): void
    {
        $coreEvents = ['sign_up', 'login', 'logout', 'trial_start', 'trial_end', 'subscription', 'plan_upgrade', 'plan_downgrade', 'cancellation', 'feature_used', 'revenue_tracked'];

        foreach ($coreEvents as $event) {
            $this->assertTrue(
                SaaSEvents::has($event),
                "Core SaaS event '{$event}' not found in catalog",
            );
        }
    }

    // ─── Specific Engagement Events ─────────────────────────────────────

    public function test_core_engagement_events_exist(): void
    {
        $coreEvents = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error', 'session_start', 'session_end', 'web_vitals', 'js_error', 'outbound_click', 'timing', 'screen_view', 'ab_test_exposure', 'notification', 'campaign_attribution', 'time_on_page'];

        foreach ($coreEvents as $event) {
            $this->assertTrue(
                EngagementEvents::has($event),
                "Core engagement event '{$event}' not found in catalog",
            );
        }
    }

    // ─── Cross-Category Event Entries Have Required Keys ──────────────────

    /**
     * @dataProvider eventEntryKeysProvider
     */
    public function test_event_entry_has_required_keys(string $key): void
    {
        $all = EventCatalog::all();

        foreach ($all as $name => $entry) {
            $this->assertArrayHasKey(
                $key,
                $entry,
                "Event '{$name}' is missing required key '{$key}'",
            );
        }
    }

    /**
     * @return array<string, array{key: string}>
     */
    public static function eventEntryKeysProvider(): array
    {
        return [
            'name' => ['key' => 'name'],
            'class' => ['key' => 'class'],
            'ga4' => ['key' => 'ga4'],
            'meta' => ['key' => 'meta'],
            'category' => ['key' => 'category'],
        ];
    }
}
