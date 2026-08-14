<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEvents;
use ZeroBoiler\Analytics\Events\SaaS\OnboardingStartedEvent;
use ZeroBoiler\Analytics\Events\SaaS\InviteAcceptedEvent;
use ZeroBoiler\Analytics\Events\SaaS\PasswordResetRequestedEvent;
use ZeroBoiler\Analytics\Events\SaaS\PaymentMethodRemovedEvent;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

/**
 * Verifies the analytics package meets industry-standard SaaS starter criteria.
 *
 * Tests the full event catalog (200+ events), lifecycle mapping,
 * identity tracking, ecommerce conversion, and version consistency.
 *
 * @since 131.0.0
 */
final class V131SaaSStarterIndustryStandardTest extends TestCase
{
    // ── Version Consistency ──────────────────────────────────────────

    public function test_version_is_132(): void
    {
        $this->assertSame('132.0.0', AnalyticsEvent::VERSION);
    }

    // ── Event Catalog Counts ────────────────────────────────────────

    public function test_saas_events_count_meets_minimum(): void
    {
        $this->assertGreaterThanOrEqual(71, SaaSEvents::count());
    }

    public function test_ecommerce_events_count_meets_minimum(): void
    {
        $this->assertGreaterThanOrEqual(15, EcommerceEvents::count());
    }

    public function test_engagement_events_count_meets_minimum(): void
    {
        $this->assertGreaterThanOrEqual(35, EngagementEvents::count());
    }

    public function test_marketing_events_count_meets_minimum(): void
    {
        $this->assertGreaterThanOrEqual(28, MarketingEvents::count());
    }

    public function test_total_catalog_exceeds_200_events(): void
    {
        $total = SaaSEvents::count()
            + EcommerceEvents::count()
            + EngagementEvents::count()
            + SecurityEvents::count()
            + UptimeEvents::count()
            + InfrastructureEvents::count()
            + MarketingEvents::count();

        $this->assertGreaterThanOrEqual(200, $total, "Total catalog has {$total} events, expected 200+");
    }

    public function test_all_catalogs_have_consistent_entry_structure(): void
    {
        $catalogs = [
            SaaSEvents::all(),
            EcommerceEvents::all(),
            EngagementEvents::all(),
        ];

        foreach ($catalogs as $name => $entries) {
            foreach ($entries as $eventName => $entry) {
                $this->assertArrayHasKey('name', $entry, "Missing 'name' in catalog entry: {$eventName}");
                $this->assertArrayHasKey('class', $entry, "Missing 'class' in catalog entry: {$eventName}");
                $this->assertArrayHasKey('ga4', $entry, "Missing 'ga4' in catalog entry: {$eventName}");
                $this->assertArrayHasKey('meta', $entry, "Missing 'meta' in catalog entry: {$eventName}");
                $this->assertArrayHasKey('posthog', $entry, "Missing 'posthog' in catalog entry: {$eventName}");
                $this->assertArrayHasKey('mixpanel', $entry, "Missing 'mixpanel' in catalog entry: {$eventName}");
                $this->assertArrayHasKey('amplitude', $entry, "Missing 'amplitude' in catalog entry: {$eventName}");
                $this->assertArrayHasKey('tiktok', $entry, "Missing 'tiktok' in catalog entry: {$eventName}");
                $this->assertArrayHasKey('linkedin', $entry, "Missing 'linkedin' in catalog entry: {$eventName}");
            }
        }
    }

    // ── Core SaaS Events Present ─────────────────────────────────────

    public function test_core_saas_signup_event_exists(): void
    {
        $this->assertTrue(SaaSEvents::has('sign_up'));
        $entry = SaaSEvents::get('sign_up');
        $this->assertSame('sign_up', $entry['name']);
        $this->assertSame('sign_up', $entry['ga4']);
        $this->assertSame('CompleteRegistration', $entry['meta']);
    }

    public function test_core_saas_login_event_exists(): void
    {
        $this->assertTrue(SaaSEvents::has('login'));
        $this->assertSame('login', SaaSEvents::get('login')['ga4']);
    }

    public function test_core_saas_trial_start_event_exists(): void
    {
        $this->assertTrue(SaaSEvents::has('start_trial'));
        $this->assertSame('Subscribe', SaaSEvents::get('start_trial')['meta']);
    }

    public function test_core_saas_subscription_event_exists(): void
    {
        $this->assertTrue(SaaSEvents::has('subscribe'));
        $this->assertSame('purchase', SaaSEvents::get('subscribe')['ga4']);
    }

    public function test_core_saas_plan_upgrade_event_exists(): void
    {
        $this->assertTrue(SaaSEvents::has('plan_upgrade'));
    }

    public function test_core_saas_cancellation_event_exists(): void
    {
        $this->assertTrue(SaaSEvents::has('cancellation'));
        $this->assertSame('CancelSubscription', SaaSEvents::get('cancellation')['meta']);
    }

    // ── Core Ecommerce Events Present ───────────────────────────────

    public function test_core_ecommerce_view_item_exists(): void
    {
        $this->assertTrue(EcommerceEvents::has('view_item'));
        $this->assertSame('ViewContent', EcommerceEvents::get('view_item')['meta']);
    }

    public function test_core_ecommerce_add_to_cart_exists(): void
    {
        $this->assertTrue(EcommerceEvents::has('add_to_cart'));
        $this->assertSame('AddToCart', EcommerceEvents::get('add_to_cart')['meta']);
    }

    public function test_core_ecommerce_purchase_exists(): void
    {
        $this->assertTrue(EcommerceEvents::has('purchase'));
        $this->assertSame('Purchase', EcommerceEvents::get('purchase')['meta']);
    }

    public function test_core_ecommerce_refund_exists(): void
    {
        $this->assertTrue(EcommerceEvents::has('refund'));
    }

    // ── Core Engagement Events Present ──────────────────────────────

    public function test_core_engagement_page_view_exists(): void
    {
        $this->assertTrue(EngagementEvents::has('page_view'));
        $this->assertSame('PageView', EngagementEvents::get('page_view')['meta']);
    }

    public function test_core_engagement_scroll_depth_exists(): void
    {
        $this->assertTrue(EngagementEvents::has('scroll_depth'));
    }

    public function test_core_engagement_click_exists(): void
    {
        $this->assertTrue(EngagementEvents::has('click'));
    }

    public function test_core_engagement_form_start_exists(): void
    {
        $this->assertTrue(EngagementEvents::has('form_start'));
    }

    public function test_core_engagement_form_submit_exists(): void
    {
        $this->assertTrue(EngagementEvents::has('form_submit'));
    }

    public function test_core_engagement_search_exists(): void
    {
        $this->assertTrue(EngagementEvents::has('search'));
    }

    public function test_core_engagement_share_exists(): void
    {
        $this->assertTrue(EngagementEvents::has('share'));
    }

    public function test_core_engagement_error_exists(): void
    {
        $this->assertTrue(EngagementEvents::has('error'));
    }

    // ── New v131 Events ──────────────────────────────────────────────

    public function test_onboarding_started_event_exists_in_catalog(): void
    {
        $this->assertTrue(SaaSEvents::has('onboarding_started'));
        $entry = SaaSEvents::get('onboarding_started');
        $this->assertSame('onboarding_started', $entry['name']);
        $this->assertSame(OnboardingStartedEvent::class, $entry['class']);
        $this->assertSame('CompleteRegistration', $entry['meta']);
        $this->assertSame('activation', $entry['plausible']);
    }

    public function test_invite_accepted_event_exists_in_catalog(): void
    {
        $this->assertTrue(SaaSEvents::has('invite_accepted'));
        $entry = SaaSEvents::get('invite_accepted');
        $this->assertSame('invite_accepted', $entry['name']);
        $this->assertSame(InviteAcceptedEvent::class, $entry['class']);
        $this->assertSame('Lead', $entry['meta']);
    }

    public function test_password_reset_requested_event_exists_in_catalog(): void
    {
        $this->assertTrue(SaaSEvents::has('password_reset_requested'));
        $entry = SaaSEvents::get('password_reset_requested');
        $this->assertSame(PasswordResetRequestedEvent::class, $entry['class']);
    }

    public function test_payment_method_removed_event_exists_in_catalog(): void
    {
        $this->assertTrue(SaaSEvents::has('payment_method_removed'));
        $entry = SaaSEvents::get('payment_method_removed');
        $this->assertSame(PaymentMethodRemovedEvent::class, $entry['class']);
    }

    // ── Event DTO Construction ───────────────────────────────────────

    public function test_new_event_dtos_construct_with_correct_name(): void
    {
        $event = new OnboardingStartedEvent(['step' => 1]);
        $this->assertSame('onboarding_started', $event->name);
        $this->assertSame(['step' => 1], $event->params);
    }

    public function test_new_event_dtos_accept_client_id(): void
    {
        $event = new InviteAcceptedEvent([], 'client-abc', 'user-123');
        $this->assertSame('invite_accepted', $event->name);
        $this->assertSame('client-abc', $event->clientId);
        $this->assertSame('user-123', $event->userId);
    }

    public function test_new_event_dtos_have_default_empty_params(): void
    {
        $event = new PasswordResetRequestedEvent();
        $this->assertSame([], $event->params);
        $this->assertNull($event->clientId);
        $this->assertNull($event->userId);
    }

    // ── EventCatalog Integration ──────────────────────────────────────

    public function test_event_catalog_includes_new_events(): void
    {
        $all = EventCatalog::all();
        $this->assertArrayHasKey('onboarding_started', $all);
        $this->assertArrayHasKey('invite_accepted', $all);
        $this->assertArrayHasKey('password_reset_requested', $all);
        $this->assertArrayHasKey('payment_method_removed', $all);
    }

    public function test_event_catalog_assigns_saas_category(): void
    {
        $all = EventCatalog::all();
        $this->assertSame('saas', $all['onboarding_started']['category']);
        $this->assertSame('saas', $all['invite_accepted']['category']);
    }

    // ── E-commerce Format Conversion ─────────────────────────────────

    public function test_ecommerce_ga4_to_meta_contents_conversion(): void
    {
        $items = [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 2, 'item_category' => 'Electronics'],
        ];

        $result = EcommerceFormatConverter::ga4ToMetaContents($items);

        $this->assertCount(1, $result['contents']);
        $this->assertSame('SKU-001', $result['contents'][0]['id']);
        $this->assertSame(2, $result['contents'][0]['quantity']);
        $this->assertSame(29.99, $result['contents'][0]['item_price']);
        $this->assertSame(1, $result['num_items']);
        $this->assertSame(59.98, $result['value']);
    }

    public function test_ecommerce_ga4_to_meta_purchase_conversion(): void
    {
        $params = [
            'transaction_id' => 'TXN-123',
            'value' => 99.99,
            'currency' => 'USD',
            'items' => [
                ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
            ],
        ];

        $result = EcommerceFormatConverter::ga4ToMetaPurchase($params);

        $this->assertSame('USD', $result['currency']);
        $this->assertSame(99.99, $result['value']);
        $this->assertSame('product', $result['content_type']);
        $this->assertCount(1, $result['content_ids']);
    }

    public function test_ecommerce_build_ga4_purchase(): void
    {
        $result = EcommerceFormatConverter::buildGa4Purchase(
            'TXN-456',
            149.99,
            'EUR',
            [['item_id' => 'P-001', 'price' => 149.99, 'quantity' => 1]],
            ['tax' => 19.99, 'shipping' => 5.00, 'coupon' => 'SUMMER20'],
        );

        $this->assertSame('TXN-456', $result['transaction_id']);
        $this->assertSame(149.99, $result['value']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame(19.99, $result['tax']);
        $this->assertSame(5.0, $result['shipping']);
        $this->assertSame('SUMMER20', $result['coupon']);
    }

    // ── Cross-Provider Mapping Coverage ───────────────────────────────

    public function test_all_catalogs_have_provider_coverage_for_core_events(): void
    {
        $coreSaas = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'];
        foreach ($coreSaas as $name) {
            $entry = SaaSEvents::get($name);
            $this->assertNotNull($entry, "Missing SaaS event: {$name}");
            $this->assertNotEmpty($entry['ga4'], "{$name} missing GA4 mapping");
            $this->assertNotEmpty($entry['posthog'], "{$name} missing PostHog mapping");
            $this->assertNotEmpty($entry['mixpanel'], "{$name} missing Mixpanel mapping");
            $this->assertNotEmpty($entry['amplitude'], "{$name} missing Amplitude mapping");
        }
    }

    public function test_ecommerce_core_events_have_meta_pixel_mapping(): void
    {
        $coreEcom = ['view_item', 'add_to_cart', 'purchase', 'refund'];
        foreach ($coreEcom as $name) {
            $entry = EcommerceEvents::get($name);
            $this->assertNotNull($entry, "Missing ecommerce event: {$name}");
            $this->assertNotNull($entry['meta'], "{$name} missing Meta Pixel mapping");
        }
    }

    // ── Catalog Helper Methods ───────────────────────────────────────

    public function test_catalog_names_returns_non_empty_list(): void
    {
        $names = SaaSEvents::names();
        $this->assertIsArray($names);
        $this->assertNotEmpty($names);
        $this->assertContains('sign_up', $names);
    }

    public function test_catalog_category_returns_string(): void
    {
        $this->assertSame('saas', SaaSEvents::category());
        $this->assertSame('ecommerce', EcommerceEvents::category());
        $this->assertSame('engagement', EngagementEvents::category());
    }

    public function test_catalog_class_for_returns_class_string(): void
    {
        $this->assertSame(OnboardingStartedEvent::class, SaaSEvents::classFor('onboarding_started'));
        $this->assertNull(SaaSEvents::classFor('nonexistent_event'));
    }

    public function test_catalog_provider_name_extractors(): void
    {
        $ga4Names = SaaSEvents::ga4Names();
        $this->assertIsArray($ga4Names);
        $this->assertNotEmpty($ga4Names);
        $this->assertContains('sign_up', $ga4Names);

        $metaNames = SaaSEvents::metaNames();
        $this->assertIsArray($metaNames);
        $this->assertContains('CompleteRegistration', $metaNames);

        $posthogNames = SaaSEvents::posthogNames();
        $this->assertIsArray($posthogNames);
        $this->assertContains('$signup', $posthogNames);
    }
}
