<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;

/**
 * v2.76 — SaaS Industry Standard Readiness Test.
 *
 * Validates the complete analytics package against industry-standard
 * SaaS analytics requirements: event coverage, catalog integrity,
 * lifecycle mapping, funnel helpers, billing/revenue events,
 * product-led growth signals, and AARRR framework representation.
 */
final class V76SaaSStarterIndustryStandardTest extends TestCase
{
    // ── Event Count Integrity ─────────────────────────────────────────

    public function test_total_event_count_is_84(): void
    {
        $this->assertSame(84, EventCatalog::count());
    }

    public function test_ecommerce_event_count_is_13(): void
    {
        $this->assertSame(13, EcommerceEvents::count());
    }

    public function test_saas_event_count_is_46(): void
    {
        $this->assertSame(46, SaaSEvents::count());
    }

    public function test_engagement_event_count_is_25(): void
    {
        $this->assertSame(25, EngagementEvents::count());
    }

    // ── Catalog Integrity ───────────────────────────────────────────

    public function test_catalog_validation_passes(): void
    {
        $result = EventCatalog::validate();

        $this->assertTrue($result['valid'], 'Catalog validation failed: '.implode('; ', $result['errors']));
        $this->assertEmpty($result['errors']);
    }

    public function test_no_duplicate_event_names_across_categories(): void
    {
        $allNames = EventCatalog::names();
        $uniqueNames = array_unique($allNames);

        $this->assertCount(count($allNames), $uniqueNames, 'Duplicate event names detected across categories');
    }

    public function test_all_events_have_required_keys(): void
    {
        $required = EventCatalog::requiredKeys();
        $all = EventCatalog::all();

        foreach ($all as $name => $entry) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $entry, "Event '{$name}' missing required key '{$key}'");
            }
        }
    }

    public function test_summary_is_consistent(): void
    {
        $summary = EventCatalog::summary();

        $this->assertSame(84, $summary['total']);
        $this->assertSame(13, $summary['ecommerce']);
        $this->assertSame(46, $summary['saas']);
        $this->assertSame(25, $summary['engagement']);
        $this->assertSame(84, $summary['with_ga4']); // All events have GA4 mapping
    }

    // ── Core SaaS Events (Starter) ───────────────────────────────────

    public function test_core_saas_events_exist(): void
    {
        $coreEvents = ['sign_up', 'login', 'logout', 'start_trial', 'trial_end', 'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation', 'trial_converted', 'subscription_resumed'];

        foreach ($coreEvents as $eventName) {
            $this->assertTrue(EventCatalog::has($eventName), "Core SaaS event '{$eventName}' missing from catalog");
            $entry = EventCatalog::get($eventName);
            $this->assertNotNull($entry);
            $this->assertSame('saas', EventCatalog::getCategory($eventName));
        }
    }

    public function test_core_saas_helper_returns_expected_count(): void
    {
        $core = EventCatalog::coreSaaS();

        $this->assertGreaterThanOrEqual(12, count($core));
    }

    // ── E-commerce Funnel ───────────────────────────────────────────

    public function test_checkout_funnel_events_exist_in_order(): void
    {
        $funnel = EventCatalog::checkoutFunnel();
        $funnelNames = array_map(fn (array $e): string => $e['name'], $funnel);

        // view_item should come before purchase
        $viewItemIdx = array_search('view_item', $funnelNames, true);
        $purchaseIdx = array_search('purchase', $funnelNames, true);

        $this->assertNotFalse($viewItemIdx, 'view_item missing from checkout funnel');
        $this->assertNotFalse($purchaseIdx, 'purchase missing from checkout funnel');
        $this->assertLessThan($purchaseIdx, $viewItemIdx, 'view_item should come before purchase in checkout funnel');
    }

    // ── Activation Funnel ───────────────────────────────────────────

    public function test_activation_funnel_contains_signup(): void
    {
        $funnel = EventCatalog::activationFunnel();
        $funnelNames = array_map(fn (array $e): string => $e['name'], $funnel);

        $this->assertContains('sign_up', $funnelNames);
        $this->assertContains('feature_used', $funnelNames);
    }

    // ── Retention Signals ──────────────────────────────────────────

    public function test_retention_signals_include_churn_and_positive_signals(): void
    {
        $signals = EventCatalog::retentionSignals();
        $signalNames = array_map(fn (array $e): string => $e['name'], $signals);

        // Churn signals
        $this->assertContains('cancellation', $signalNames);
        $this->assertContains('payment_failed', $signalNames);

        // Positive retention signals
        $this->assertContains('feature_used', $signalNames);
        $this->assertContains('plan_upgrade', $signalNames);
    }

    // ── Billing Events (v2.76) ───────────────────────────────────────

    public function test_billing_events_include_all_financial_events(): void
    {
        $billing = EventCatalog::billingEvents();
        $billingNames = array_map(fn (array $e): string => $e['name'], $billing);

        $this->assertContains('payment_succeeded', $billingNames);
        $this->assertContains('payment_failed', $billingNames);
        $this->assertContains('invoice_generated', $billingNames);
        $this->assertContains('credit_applied', $billingNames);
        $this->assertContains('billing_retry', $billingNames);
        $this->assertContains('subscription_value_changed', $billingNames);
        $this->assertContains('subscribe', $billingNames);
        $this->assertContains('purchase', $billingNames);

        $this->assertGreaterThanOrEqual(11, count($billing));
    }

    // ── Product-Led Growth Events (v2.76) ───────────────────────────

    public function test_product_growth_events_cover_aarrr_framework(): void
    {
        $growth = EventCatalog::productGrowthEvents();
        $growthNames = array_map(fn (array $e): string => $e['name'], $growth);

        // Acquisition
        $this->assertContains('sign_up', $growthNames);
        $this->assertContains('start_trial', $growthNames);

        // Activation
        $this->assertContains('trial_converted', $growthNames);
        $this->assertContains('feature_used', $growthNames);

        // Retention
        $this->assertContains('content_engagement', $growthNames);

        // Revenue
        $this->assertContains('plan_upgrade', $growthNames);
        $this->assertContains('purchase', $growthNames);

        // Referral
        $this->assertContains('share', $growthNames);
        $this->assertContains('invite_sent', $growthNames);

        $this->assertGreaterThanOrEqual(20, count($growth));
    }

    // ── AARRR Lifecycle (v2.76) ─────────────────────────────────────

    public function test_all_lifecycle_events_is_comprehensive(): void
    {
        $lifecycle = EventCatalog::allLifecycleEvents();

        // Should contain events from multiple categories
        $this->assertGreaterThanOrEqual(40, count($lifecycle), 'AARRR lifecycle should include at least 40 events');

        // Should have unique events (no duplicates)
        $names = array_map(fn (array $e): string => $e['name'], $lifecycle);
        $this->assertCount(count($names), array_unique($names), 'Lifecycle events should be unique');
    }

    // ── Critical Events ─────────────────────────────────────────────

    public function test_critical_events_are_never_samplable(): void
    {
        $critical = EventCatalog::criticalEvents();
        $samplable = EventCatalog::samplableEvents();

        $criticalNames = array_map(fn (array $e): string => $e['name'], $critical);
        $samplableNames = array_map(fn (array $e): string => $e['name'], $samplable);

        $overlap = array_intersect($criticalNames, $samplableNames);

        $this->assertEmpty($overlap, 'Critical events should not be in the samplable list: '.implode(', ', $overlap));
    }

    public function test_critical_events_include_revenue_and_auth(): void
    {
        $critical = EventCatalog::criticalEvents();
        $criticalNames = array_map(fn (array $e): string => $e['name'], $critical);

        $this->assertContains('purchase', $criticalNames);
        $this->assertContains('subscribe', $criticalNames);
        $this->assertContains('sign_up', $criticalNames);
        $this->assertContains('login', $criticalNames);
        $this->assertContains('billing_retry', $criticalNames);
    }

    // ── Revenue Events ──────────────────────────────────────────────

    public function test_revenue_events_span_ecommerce_and_saas(): void
    {
        $revenue = EventCatalog::revenueEvents();
        $revenueNames = array_map(fn (array $e): string => $e['name'], $revenue);

        // E-commerce revenue
        $this->assertContains('purchase', $revenueNames);
        $this->assertContains('add_to_cart', $revenueNames);

        // SaaS revenue
        $this->assertContains('subscribe', $revenueNames);
        $this->assertContains('trial_converted', $revenueNames);

        $this->assertGreaterThanOrEqual(18, count($revenue));
    }

    // ── Provider Coverage ────────────────────────────────────────────

    public function test_all_events_have_ga4_mapping(): void
    {
        $all = EventCatalog::all();

        foreach ($all as $name => $entry) {
            $this->assertArrayHasKey('ga4', $entry, "Event '{$name}' missing GA4 mapping");
            $this->assertNotEmpty($entry['ga4'], "Event '{$name}' has empty GA4 mapping");
        }
    }

    public function test_saas_funnel_events_exist(): void
    {
        $funnel = EventCatalog::saasFunnelEvents();
        $funnelNames = array_map(fn (array $e): string => $e['name'], $funnel);

        $this->assertContains('sign_up', $funnelNames);
        $this->assertContains('subscribe', $funnelNames);
        $this->assertContains('plan_upgrade', $funnelNames);
        $this->assertContains('cancellation', $funnelNames);
        $this->assertContains('trial_converted', $funnelNames);
    }

    // ── Category Consistency ─────────────────────────────────────────

    public function test_all_ecommerce_events_have_ecommerce_category(): void
    {
        foreach (EcommerceEvents::all() as $name => $entry) {
            $this->assertSame('ecommerce', EventCatalog::getCategory($name));
        }
    }

    public function test_all_saas_events_have_saas_category(): void
    {
        foreach (SaaSEvents::all() as $name => $entry) {
            $this->assertSame('saas', EventCatalog::getCategory($name));
        }
    }

    public function test_all_engagement_events_have_engagement_category(): void
    {
        foreach (EngagementEvents::all() as $name => $entry) {
            $this->assertSame('engagement', EventCatalog::getCategory($name));
        }
    }

    // ── v2.76 New Events Exist ─────────────────────────────────────

    public function test_v76_new_events_exist_in_catalog(): void
    {
        $newEvents = [
            'subscription_value_changed',
            'usage_quota_reached',
            'billing_retry',
            'subscription_paused',
        ];

        foreach ($newEvents as $eventName) {
            $this->assertTrue(EventCatalog::has($eventName), "v2.76 event '{$eventName}' missing from catalog");
            $this->assertSame('saas', EventCatalog::getCategory($eventName));
        }
    }

    public function test_v76_new_events_have_provider_mappings(): void
    {
        $newEvents = ['subscription_value_changed', 'usage_quota_reached', 'billing_retry', 'subscription_paused'];

        foreach ($newEvents as $eventName) {
            $entry = EventCatalog::get($eventName);
            $this->assertNotNull($entry);
            $this->assertArrayHasKey('ga4', $entry);
            $this->assertArrayHasKey('posthog', $entry);
            $this->assertNotEmpty($entry['ga4']);
            $this->assertNotEmpty($entry['posthog']);
        }
    }

    // ── Engagement Events (v2.76 count fix) ──────────────────────────

    public function test_engagement_events_include_core_tracking(): void
    {
        $engagementNames = EngagementEvents::names();

        $this->assertContains('page_view', $engagementNames);
        $this->assertContains('scroll_depth', $engagementNames);
        $this->assertContains('click', $engagementNames);
        $this->assertContains('form_start', $engagementNames);
        $this->assertContains('form_submit', $engagementNames);
        $this->assertContains('search', $engagementNames);
        $this->assertContains('share', $engagementNames);
        $this->assertContains('error', $engagementNames);
    }

    // ── Provider Coverage Summary ───────────────────────────────────

    public function test_provider_coverage_summary_is_complete(): void
    {
        $coverage = EventCatalog::providerCoverage();

        $this->assertArrayHasKey('ga4', $coverage);
        $this->assertArrayHasKey('meta', $coverage);
        $this->assertArrayHasKey('posthog', $coverage);
        $this->assertArrayHasKey('plausible', $coverage);
        $this->assertArrayHasKey('counts', $coverage);

        $this->assertSame(84, $coverage['counts']['ga4']);
        $this->assertGreaterThan(0, $coverage['counts']['meta']);
        $this->assertGreaterThan(0, $coverage['counts']['posthog']);
        $this->assertGreaterThan(0, $coverage['counts']['plausible']);
    }

    // ── Reverse Provider Lookup ────────────────────────────────────

    public function test_reverse_provider_lookup_works(): void
    {
        $results = EventCatalog::searchByProvider('ga4', 'page_view');

        $this->assertNotEmpty($results);
        $this->assertSame('page_view', $results[0]['name']);
        $this->assertSame('engagement', $results[0]['category']);
    }

    // ── Event Class Resolution ──────────────────────────────────────

    public function test_class_for_returns_valid_class_strings(): void
    {
        $classes = [
            'purchase' => EcommerceEvents::classFor('purchase'),
            'sign_up' => SaaSEvents::classFor('sign_up'),
            'page_view' => EngagementEvents::classFor('page_view'),
        ];

        foreach ($classes as $eventName => $class) {
            $this->assertNotNull($class, "Class for '{$eventName}' should not be null");
            $this->assertStringContainsString('Event', (string) $class, "Class for '{$eventName}' should contain 'Event'");
        }
    }

    public function test_class_for_unknown_event_returns_null(): void
    {
        $this->assertNull(EventCatalog::classFor('nonexistent_event'));
    }

    // ── Catalog By Category ──────────────────────────────────────────

    public function test_by_category_returns_three_categories(): void
    {
        $byCategory = EventCatalog::byCategory();

        $this->assertArrayHasKey('ecommerce', $byCategory);
        $this->assertArrayHasKey('saas', $byCategory);
        $this->assertArrayHasKey('engagement', $byCategory);
        $this->assertCount(3, $byCategory);
    }

    // ── PostHog and Plausible Mappings ──────────────────────────────

    public function test_posthog_mappings_are_complete(): void
    {
        $mappings = EventCatalog::allPosthogMappings();

        $this->assertCount(84, $mappings);
        $this->assertSame('$pageview', $mappings['page_view']);
        $this->assertSame('$signup', $mappings['sign_up']);
    }

    public function test_plausible_mappings_exist_for_key_events(): void
    {
        $plausibleNames = EventCatalog::allPlausibleNames();

        $this->assertContains('pageview', $plausibleNames);
        $this->assertContains('purchase', $plausibleNames);
        $this->assertContains('signup', $plausibleNames);
    }
}
