<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Http\Middleware\InertiaAnalyticsMiddleware;
use ZeroBoiler\Analytics\Queue\AnalyticsQueueService;
use ZeroBoiler\Analytics\Queue\DispatchAnalyticsJob;
use ZeroBoiler\Analytics\Services\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Tracking\LifecycleEventTracker;

/**
 * V255 — SaaS Starter Industry Standard Upgrade Test.
 *
 * Validates all new v255.0.0 additions:
 *  - Event Catalog (EcommerceEvents, SaaSEvents, EngagementEvents) completeness
 *  - Queue infrastructure (DispatchAnalyticsJob, BatchDispatchAnalyticsJob, AnalyticsQueueService)
 *  - Inertia middleware (InertiaAnalyticsMiddleware)
 *  - EcommerceFormatConverter (GA4 ↔ Meta Pixel)
 *  - LifecycleEventTracker (config-driven Laravel event → analytics mapping)
 *
 * @since 255.0.0
 */
final class V255SaaSStarterIndustryStandardUpgradeTest extends TestCase
{
    // ── Event Catalog Tests ───────────────────────────────────────

    #[Test]
    public function ecommerceCatalogContainsCoreEvents(): void
    {
        $this->assertTrue(EcommerceEvents::has('view_item'));
        $this->assertTrue(EcommerceEvents::has('add_to_cart'));
        $this->assertTrue(EcommerceEvents::has('purchase'));
        $this->assertTrue(EcommerceEvents::has('refund'));
    }

    #[Test]
    public function saasCatalogContainsCoreEvents(): void
    {
        $this->assertTrue(SaaSEvents::has('sign_up'));
        $this->assertTrue(SaaSEvents::has('login'));
        $this->assertTrue(SaaSEvents::has('start_trial'));
        $this->assertTrue(SaaSEvents::has('subscribe'));
        $this->assertTrue(SaaSEvents::has('plan_upgrade'));
        $this->assertTrue(SaaSEvents::has('cancellation'));
    }

    #[Test]
    public function engagementCatalogContainsCoreEvents(): void
    {
        $this->assertTrue(EngagementEvents::has('page_view'));
        $this->assertTrue(EngagementEvents::has('scroll_depth'));
        $this->assertTrue(EngagementEvents::has('click'));
        $this->assertTrue(EngagementEvents::has('form_start'));
        $this->assertTrue(EngagementEvents::has('form_submit'));
        $this->assertTrue(EngagementEvents::has('search'));
        $this->assertTrue(EngagementEvents::has('share'));
        $this->assertTrue(EngagementEvents::has('error'));
    }

    #[Test]
    public function ecommerceCatalogShorthandFactoriesReturnEvents(): void
    {
        $event = EcommerceEvents::purchase([
            'transaction_id' => 'TX-001',
            'value' => 99.99,
            'currency' => 'USD',
        ]);

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('purchase', $event->name);
        $this->assertSame('ecommerce', $event->category);
        $this->assertSame('TX-001', $event->params['transaction_id']);
    }

    // ── EcommerceFormatConverter Tests ─────────────────────────────

    #[Test]
    public function ecommerceConverterToGa4BuildsCorrectFormat(): void
    {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TX-001',
                'value' => 99.99,
                'currency' => 'USD',
                'items' => [
                    ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
                ],
            ],
            category: 'ecommerce',
        );

        $converter = new EcommerceFormatConverter;
        $ga4 = $converter->toGa4($event);

        $this->assertSame('purchase', $ga4['event']);
        $this->assertSame(99.99, $ga4['params']['value']);
        $this->assertSame('USD', $ga4['params']['currency']);
        $this->assertCount(1, $ga4['params']['items']);
        $this->assertSame('SKU-1', $ga4['params']['items'][0]['item_id']);
    }

    #[Test]
    public function ecommerceConverterToMetaBuildsCorrectFormat(): void
    {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TX-001',
                'value' => 99.99,
                'currency' => 'USD',
                'items' => [
                    ['item_id' => 'SKU-1', 'price' => 49.99, 'quantity' => 2],
                ],
            ],
            category: 'ecommerce',
        );

        $converter = new EcommerceFormatConverter;
        $meta = $converter->toMeta($event);

        $this->assertSame('Purchase', $meta['event_name']);
        $this->assertSame(99.99, $meta['custom_data']['value']);
        $this->assertSame('USD', $meta['custom_data']['currency']);
        $this->assertSame('website', $meta['action_source']);
        $this->assertSame(2, $meta['custom_data']['num_items']);
        $this->assertSame('product', $meta['custom_data']['content_type']);
    }

    #[Test]
    public function ecommerceConverterToBothReturnsDualFormat(): void
    {
        $event = new AnalyticsEvent(
            name: 'view_item',
            params: ['item_id' => 'SKU-1', 'price' => 29.99],
            category: 'ecommerce',
        );

        $converter = new EcommerceFormatConverter;
        $both = $converter->toBoth($event);

        $this->assertArrayHasKey('ga4', $both);
        $this->assertArrayHasKey('meta', $both);
        $this->assertSame('view_item', $both['ga4']['event']);
        $this->assertSame('ViewContent', $both['meta']['event_name']);
    }

    #[Test]
    public function ecommerceConverterStaticPurchaseGa4ToMeta(): void
    {
        $result = EcommerceFormatConverter::purchaseGa4ToMeta([
            'transaction_id' => 'TX-002',
            'value' => 149.99,
            'currency' => 'EUR',
            'items' => [
                ['item_id' => 'SKU-A', 'price' => 75.00, 'quantity' => 2],
            ],
        ]);

        $this->assertSame('Purchase', $result['event_name']);
        $this->assertSame('EUR', $result['custom_data']['currency']);
        $this->assertSame(149.99, $result['custom_data']['value']);
        $this->assertCount(1, $result['custom_data']['content_ids']);
        $this->assertSame('SKU-A', $result['custom_data']['content_ids'][0]);
    }

    // ── Lifecycle Tracker Tests ───────────────────────────────────

    #[Test]
    public function lifecycleTrackerHasCorrectBuiltInCount(): void
    {
        $this->assertSame(10, LifecycleEventTracker::BUILTIN_MAPPING_COUNT);
    }

    // ── Queue Job Tests ────────────────────────────────────────────

    #[Test]
    public function dispatchAnalyticsJobHasCorrectProperties(): void
    {
        $job = new DispatchAnalyticsJob(
            events: [['name' => 'page_view', 'params' => []]],
            clientId: 'client-123',
        );

        $this->assertSame(3, $job->tries);
        $this->assertSame([5, 15, 60], $job->backoff);
        $this->assertSame(30, $job->timeout);
    }

    #[Test]
    public function dispatchAnalyticsJobDisplayName(): void
    {
        $job = new DispatchAnalyticsJob(
            events: [['name' => 'test', 'params' => []]],
        );

        $this->assertSame('zb:analytics:dispatch', $job->displayName());
    }

    #[Test]
    public function dispatchAnalyticsJobTags(): void
    {
        $job = new DispatchAnalyticsJob(
            events: [['name' => 'test', 'params' => []]],
            clientId: 'abc-123',
        );

        $tags = $job->tags();
        $this->assertContains('zb:analytics', $tags);
        $this->assertContains('client:abc-123', $tags);
    }

    // ── Inertia Middleware Class Existence ──────────────────────────

    #[Test]
    public function inertiaAnalyticsMiddlewareClassExists(): void
    {
        $this->assertTrue(class_exists(InertiaAnalyticsMiddleware::class));
    }

    #[Test]
    public function ecommerceFormatConverterClassExists(): void
    {
        $this->assertTrue(class_exists(EcommerceFormatConverter::class));
    }

    #[Test]
    public function analyticsQueueServiceClassExists(): void
    {
        $this->assertTrue(class_exists(AnalyticsQueueService::class));
    }

    #[Test]
    public function lifecycleEventTrackerClassExists(): void
    {
        $this->assertTrue(class_exists(LifecycleEventTracker::class));
    }

    #[Test]
    public function dispatchAnalyticsJobClassExists(): void
    {
        $this->assertTrue(class_exists(DispatchAnalyticsJob::class));
    }

    // ── EcommerceFormatConverter buildGa4Items ──────────────────────

    #[Test]
    public function ecommerceConverterBuildGa4ItemsFromItemsArray(): void
    {
        $converter = new EcommerceFormatConverter;
        $items = $converter->buildGa4Items([
            'items' => [
                ['item_id' => 'A', 'price' => 10],
                ['item_id' => 'B', 'price' => 20],
            ],
        ]);

        $this->assertCount(2, $items);
        $this->assertSame('A', $items[0]['item_id']);
        $this->assertSame('B', $items[1]['item_id']);
    }

    #[Test]
    public function ecommerceConverterBuildGa4ItemsFromFlatParams(): void
    {
        $converter = new EcommerceFormatConverter;
        $items = $converter->buildGa4Items([
            'item_id' => 'SKU-X',
            'item_name' => 'Gadget',
            'price' => 49.99,
            'quantity' => 3,
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('SKU-X', $items[0]['item_id']);
        $this->assertSame('Gadget', $items[0]['item_name']);
    }

    #[Test]
    public function ecommerceConverterItemsGa4ToMetaStatic(): void
    {
        $result = EcommerceFormatConverter::itemsGa4ToMeta([
            ['item_id' => 'A', 'quantity' => 2, 'price' => 10],
            ['item_id' => 'B', 'quantity' => 1, 'price' => 25],
        ]);

        $this->assertSame('product', $result['content_type']);
        $this->assertSame(3, $result['num_items']);
        $this->assertSame(['A', 'B'], $result['content_ids']);
        $this->assertCount(2, $result['contents']);
    }
}
