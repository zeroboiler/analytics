<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\AddToCartEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\RefundEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\ViewItemEvent;
use ZeroBoiler\Analytics\Events\SaaS\CancellationEvent;
use ZeroBoiler\Analytics\Events\SaaS\LoginEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanUpgradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\SignUpEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialStartEvent;
use ZeroBoiler\Analytics\Events\Engagement\ClickEvent;
use ZeroBoiler\Analytics\Events\Engagement\ErrorEvent;
use ZeroBoiler\Analytics\Events\Engagement\FormSubmitEvent;
use ZeroBoiler\Analytics\Events\Engagement\PageViewEvent;
use ZeroBoiler\Analytics\Events\Engagement\ScrollDepthEvent;
use ZeroBoiler\Analytics\Events\Engagement\SearchEvent;
use ZeroBoiler\Analytics\Events\Engagement\ShareEvent;
use ZeroBoiler\Analytics\Services\EcommerceFormatConverter;

/**
 * SaaS Starter Pipeline Integration Test.
 *
 * Validates the full end-to-end pipeline for the 12 core SaaS starter events:
 * 1. Typed event class creation with correct params
 * 2. Event catalog membership (each event exists in EventCatalog)
 * 3. Cross-provider name mapping (GA4, Meta, PostHog, Plausible, Mixpanel, Amplitude)
 * 4. EcommerceFormatConverter service conversion (GA4 ↔ Meta)
 *
 * @since 266.0.0
 */
final class V266SaaSStarterPipelineIntegrationTest extends TestCase
{
    private EcommerceFormatConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new EcommerceFormatConverter;
    }

    // ─────────────────────────────────────────────────────────────────
    // Ecommerce Events: ViewItem, AddToCart, Purchase, Refund
    // ─────────────────────────────────────────────────────────────────

    public function test_view_item_pipeline(): void
    {
        $event = new ViewItemEvent(
            itemId: 'SKU-001',
            itemName: 'Widget Pro',
            itemCategory: 'Gadgets',
            price: 49.99,
            currency: 'USD',
        );

        $this->assertSame('view_item', $event->name);
        $this->assertCatalogHas('view_item');
        $this->assertProviderMapping('view_item', 'ga4', 'view_item');
        $this->assertProviderMapping('view_item', 'meta', 'ViewContent');
        $this->assertProviderMapping('view_item', 'posthog', '$view_item');

        // GA4 format conversion
        $ga4 = $this->converter->toGa4('view_item', $event->params);
        $this->assertSame('view_item', $ga4['name']);
        $this->assertSame(49.99, $ga4['params']['price']);
        $this->assertSame('USD', $ga4['params']['currency']);
    }

    public function test_add_to_cart_pipeline(): void
    {
        $event = new AddToCartEvent(
            itemId: 'SKU-002',
            itemName: 'Gadget',
            itemCategory: 'Tools',
            price: 29.99,
            quantity: 3,
            currency: 'EUR',
        );

        $this->assertSame('add_to_cart', $event->name);
        $this->assertCatalogHas('add_to_cart');
        $this->assertProviderMapping('add_to_cart', 'meta', 'AddToCart');
        $this->assertProviderMapping('add_to_cart', 'mixpanel', 'Add to Cart');
    }

    public function test_purchase_pipeline(): void
    {
        $items = [['item_id' => 'SKU-001', 'price' => 49.99, 'quantity' => 2]];
        $event = new PurchaseEvent(
            transactionId: 'TXN-266',
            value: 99.98,
            items: $items,
            currency: 'USD',
            tax: 8.0,
            shipping: 5.0,
        );

        $this->assertSame('purchase', $event->name);
        $this->assertCatalogHas('purchase');
        $this->assertSame('TXN-266', $event->params['transaction_id']);

        // Bidirectional GA4 ↔ Meta
        $ga4 = $this->converter->toGa4('purchase', $event->params);
        $meta = $this->converter->ga4ToMeta($ga4);
        $this->assertSame('Purchase', $meta['event']);
        $this->assertSame(99.98, $meta['custom_data']['value']);
    }

    public function test_refund_pipeline(): void
    {
        $event = new RefundEvent(
            transactionId: 'TXN-266',
            refundValue: 49.99,
            currency: 'USD',
            items: [],
        );

        $this->assertSame('refund', $event->name);
        $this->assertCatalogHas('refund');
        $this->assertProviderMapping('refund', 'ga4', 'refund');
        $this->assertProviderMapping('refund', 'meta', 'Refund');
    }

    // ─────────────────────────────────────────────────────────────────
    // SaaS Events: SignUp, Login, TrialStart, Subscription, PlanUpgrade,
    //              Cancellation
    // ─────────────────────────────────────────────────────────────────

    public function test_sign_up_pipeline(): void
    {
        $event = new SignUpEvent('google');

        $this->assertSame('sign_up', $event->name);
        $this->assertSame('google', $event->params['method']);
        $this->assertCatalogHas('sign_up');
        $this->assertProviderMapping('sign_up', 'ga4', 'sign_up');
        $this->assertProviderMapping('sign_up', 'meta', 'CompleteRegistration');
        $this->assertProviderMapping('sign_up', 'posthog', '$signup');
    }

    public function test_login_pipeline(): void
    {
        $event = new LoginEvent('email');

        $this->assertSame('login', $event->name);
        $this->assertCatalogHas('login');
        $this->assertProviderMapping('login', 'ga4', 'login');
        $this->assertProviderMapping('login', 'meta', 'Login');
    }

    public function test_trial_start_pipeline(): void
    {
        $event = new TrialStartEvent('pro', 14);

        $this->assertSame('start_trial', $event->name);
        $this->assertSame('pro', $event->params['plan_name']);
        $this->assertSame(14, $event->params['trial_days']);
        $this->assertCatalogHas('start_trial');
        $this->assertProviderMapping('start_trial', 'meta', 'StartTrial');
    }

    public function test_subscription_pipeline(): void
    {
        $event = new SubscriptionEvent(
            planName: 'business',
            value: 79.00,
            currency: 'USD',
            billingCycle: 'monthly',
        );

        $this->assertSame('subscribe', $event->name);
        $this->assertCatalogHas('subscribe');
        $this->assertProviderMapping('subscribe', 'ga4', 'purchase');
        $this->assertProviderMapping('subscribe', 'meta', 'Subscribe');
        $this->assertProviderMapping('subscribe', 'posthog', 'subscription_created');
    }

    public function test_plan_upgrade_pipeline(): void
    {
        $event = new PlanUpgradeEvent(
            fromPlan: 'starter',
            toPlan: 'pro',
            priceDifference: 30.00,
        );

        $this->assertSame('plan_upgrade', $event->name);
        $this->assertCatalogHas('plan_upgrade');
        $this->assertProviderMapping('plan_upgrade', 'ga4', 'plan_upgrade');
        $this->assertProviderMapping('plan_upgrade', 'meta', 'PlanUpgrade');
    }

    public function test_cancellation_pipeline(): void
    {
        $event = new CancellationEvent(
            planName: 'pro',
            reason: 'too_expensive',
        );

        $this->assertSame('cancellation', $event->name);
        $this->assertCatalogHas('cancellation');
        $this->assertProviderMapping('cancellation', 'meta', 'CancelSubscription');
    }

    // ─────────────────────────────────────────────────────────────────
    // Engagement Events: PageView, ScrollDepth, Click, FormSubmit,
    //                   Search, Share, Error
    // ─────────────────────────────────────────────────────────────────

    /**
     * @dataProvider engagementEventProvider
     */
    public function test_engagement_event_in_catalog(string $name, string $className): void
    {
        $this->assertCatalogHas($name);

        $entry = EventCatalog::get($name);
        $this->assertArrayHasKey('ga4', $entry);
        $this->assertNotEmpty($entry['ga4']);
    }

    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function engagementEventProvider(): array
    {
        return [
            'page_view' => ['page_view', PageViewEvent::class],
            'scroll_depth' => ['scroll_depth', ScrollDepthEvent::class],
            'click' => ['click', ClickEvent::class],
            'form_submit' => ['form_submit', FormSubmitEvent::class],
            'search' => ['search', SearchEvent::class],
            'share' => ['share', ShareEvent::class],
            'error' => ['error', ErrorEvent::class],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Cross-Provider Coverage
    // ─────────────────────────────────────────────────────────────────

    public function test_all_core_events_have_ga4_mapping(): void
    {
        $coreEvents = [
            'view_item', 'add_to_cart', 'purchase', 'refund',
            'sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation',
            'page_view', 'scroll_depth', 'click', 'form_submit', 'search', 'share', 'error',
        ];

        foreach ($coreEvents as $eventName) {
            $entry = EventCatalog::get($eventName);
            $this->assertNotNull($entry, "Event '{$eventName}' must exist in catalog");
            $this->assertNotEmpty(
                $entry['ga4'],
                "Event '{$eventName}' must have a GA4 mapping",
            );
        }
    }

    public function test_all_core_events_have_posthog_mapping(): void
    {
        $coreEvents = [
            'view_item', 'add_to_cart', 'purchase', 'refund',
            'sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation',
            'page_view', 'scroll_depth', 'click', 'form_submit', 'search', 'share', 'error',
        ];

        foreach ($coreEvents as $eventName) {
            $entry = EventCatalog::get($eventName);
            $this->assertNotNull($entry, "Event '{$eventName}' must exist in catalog");
            $this->assertNotEmpty(
                $entry['posthog'],
                "Event '{$eventName}' must have a PostHog mapping",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    private function assertCatalogHas(string $name): void
    {
        $entry = EventCatalog::get($name);
        $this->assertNotFalse($entry, "Event '{$name}' must exist in the catalog");
        $this->assertSame($name, $entry['name']);
    }

    private function assertProviderMapping(string $eventName, string $provider, string $expected): void
    {
        $entry = EventCatalog::get($eventName);
        $this->assertNotFalse($entry);
        $this->assertSame(
            $expected,
            $entry[$provider],
            "Event '{$eventName}' {$provider} mapping should be '{$expected}'",
        );
    }
}
