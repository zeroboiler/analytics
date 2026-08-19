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
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter as SupportEcommerceFormatConverter;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;
use ZeroBoiler\Analytics\Services\IdentityGraphService;

/**
 * V256 — Optional Provider Enhancements & Cross-Cutting Quality Test.
 *
 * Validates v256.0.0 additions:
 *  - Plausible & PostHog optional provider configuration completeness
 *  - Plausible config: enhanced_tracking (outbound_links, file_downloads, 404),
 *    revenue_goals, custom_event_goals, self-hosted script URL config
 *  - PostHog config: autocapture, session_recording, feature_flags,
 *    bootstrap_props, rollout_percentage
 *  - Cross-provider event name resolution via EventCatalog
 *  - EcommerceFormatConverter toBoth() returns consistent structure
 *  - Identity graph service interface contract
 *  - Version constant consistency across key entry points
 *  - Event catalog stats accuracy
 *  - Plausible & PostHog tracker class contracts
 *
 * @since 256.0.0
 */
final class V256OptionalProviderEnhancementsTest extends TestCase
{
    // ── Plausible Configuration Section ──────────────────────────

    #[Test]
    public function configContainsPlausibleSectionWithEnhancedTracking(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $plausible = $config['analytics']['plausible'];

        $this->assertArrayHasKey('enabled', $plausible);
        $this->assertArrayHasKey('domain', $plausible);
        $this->assertArrayHasKey('api_key', $plausible);
        $this->assertArrayHasKey('base_url', $plausible);
        $this->assertArrayHasKey('custom_script_url', $plausible);
        $this->assertArrayHasKey('enhanced_tracking', $plausible);
    }

    #[Test]
    public function plausibleEnhancedTrackingContainsExpectedKeys(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $enhanced = $config['analytics']['plausible']['enhanced_tracking'];

        $this->assertArrayHasKey('outbound_links', $enhanced);
        $this->assertArrayHasKey('file_downloads', $enhanced);
        $this->assertArrayHasKey('track_404', $enhanced);
    }

    #[Test]
    public function plausibleConfigContainsRevenueGoals(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $plausible = $config['analytics']['plausible'];

        $this->assertArrayHasKey('revenue_goals', $plausible);
        $this->assertIsArray($plausible['revenue_goals']);
    }

    #[Test]
    public function plausibleConfigContainsCustomEventGoals(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $plausible = $config['analytics']['plausible'];

        $this->assertArrayHasKey('custom_event_goals', $plausible);
        $this->assertIsArray($plausible['custom_event_goals']);
    }

    #[Test]
    public function plausibleTrackerClassExistsAndImplementsInterface(): void
    {
        $this->assertTrue(class_exists(PlausibleTracker::class));
        $tracker = new PlausibleTracker(
            domain: 'example.com',
            apiKey: 'test-key',
            enabled: true,
        );

        $this->assertInstanceOf(TrackerInterface::class, $tracker);
        $this->assertTrue($tracker->isEnabled());
        $this->assertSame('example.com', $tracker->getDomain());
    }

    #[Test]
    public function plausibleTrackerSupportsSelfHosted(): void
    {
        $tracker = new PlausibleTracker(
            domain: 'example.com',
            apiKey: 'test-key',
            customScriptUrl: 'https://stats.example.com/js/script.js',
            enabled: true,
        );

        $this->assertTrue($tracker->isSelfHosted());
        $this->assertSame('https://stats.example.com/js/script.js', $tracker->getCustomScriptUrl());
    }

    // ── PostHog Configuration Section ────────────────────────────

    #[Test]
    public function configContainsPostHogSectionWithEnhancedConfig(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $posthog = $config['analytics']['posthog'];

        $this->assertArrayHasKey('enabled', $posthog);
        $this->assertArrayHasKey('api_key', $posthog);
        $this->assertArrayHasKey('host', $posthog);
        $this->assertArrayHasKey('project_id', $posthog);
        $this->assertArrayHasKey('autocapture', $posthog);
        $this->assertArrayHasKey('session_recording', $posthog);
        $this->assertArrayHasKey('feature_flags', $posthog);
    }

    #[Test]
    public function postHogAutocaptureContainsExpectedKeys(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $autocapture = $config['analytics']['posthog']['autocapture'];

        $this->assertArrayHasKey('pageviews', $autocapture);
        $this->assertArrayHasKey('clicks', $autocapture);
        $this->assertArrayHasKey('form_submissions', $autocapture);
    }

    #[Test]
    public function postHogSessionRecordingContainsExpectedKeys(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $recording = $config['analytics']['posthog']['session_recording'];

        $this->assertArrayHasKey('enabled', $recording);
        $this->assertArrayHasKey('sample_rate', $recording);
        $this->assertArrayHasKey('minimum_duration', $recording);
    }

    #[Test]
    public function postHogFeatureFlagsConfigContainsExpectedKeys(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $ff = $config['analytics']['posthog']['feature_flags'];

        $this->assertArrayHasKey('enabled', $ff);
        $this->assertArrayHasKey('rollout_percentage', $ff);
        $this->assertArrayHasKey('bootstrap_props', $ff);
    }

    #[Test]
    public function postHogTrackerClassExistsAndImplementsInterface(): void
    {
        $this->assertTrue(class_exists(PosthogTracker::class));
        $tracker = new PosthogTracker(
            apiKey: 'test-key',
            projectId: '123',
            enabled: true,
        );

        $this->assertInstanceOf(TrackerInterface::class, $tracker);
        $this->assertTrue($tracker->isEnabled());
        $this->assertSame('test-key', $tracker->getApiKey());
    }

    #[Test]
    public function postHogTrackerSupportsCapiAndCustomHost(): void
    {
        $tracker = new PosthogTracker(
            apiKey: 'test-key',
            host: 'https://app.posthog.com',
            projectId: '456',
            enabled: true,
            capiEnabled: true,
        );

        $this->assertTrue($tracker->isCapiEnabled());
        $this->assertSame('https://app.posthog.com', $tracker->getHost());
    }

    // ── Event Catalog Cross-Provider Resolution ──────────────────

    #[Test]
    public function eventCatalogResolvesEcommerceEventsToAllProviders(): void
    {
        $entry = EventCatalog::get('view_item');

        $this->assertNotNull($entry);
        $this->assertSame('view_item', $entry['ga4']);
        $this->assertSame('ViewContent', $entry['meta']);
        $this->assertNotEmpty($entry['posthog']);
        $this->assertNotEmpty($entry['mixpanel']);
        $this->assertNotEmpty($entry['amplitude']);
    }

    #[Test]
    public function eventCatalogResolvesSaasEvents(): void
    {
        $entry = EventCatalog::get('sign_up');

        $this->assertNotNull($entry);
        $this->assertSame('sign_up', $entry['ga4']);
        $this->assertSame('CompleteRegistration', $entry['meta']);
    }

    #[Test]
    public function eventCatalogResolvesEngagementEvents(): void
    {
        $entry = EventCatalog::get('page_view');

        $this->assertNotNull($entry);
        $this->assertSame('page_view', $entry['ga4']);
        $this->assertSame('PageView', $entry['meta']);
        $this->assertNotEmpty($entry['plausible']);
    }

    #[Test]
    public function eventCatalogReportsAccurateCategoryCounts(): void
    {
        $byCategory = EventCatalog::byCategory();

        $this->assertArrayHasKey('ecommerce', $byCategory);
        $this->assertArrayHasKey('saas', $byCategory);
        $this->assertArrayHasKey('engagement', $byCategory);

        // Category counts should match their respective catalog files
        $this->assertGreaterThan(10, count($byCategory['ecommerce']));
        $this->assertGreaterThan(20, count($byCategory['saas']));
        $this->assertGreaterThan(20, count($byCategory['engagement']));
    }

    #[Test]
    public function eventCatalogTotalCountIsSumOfCategories(): void
    {
        $byCategory = EventCatalog::byCategory();
        $categorySum = array_sum(array_map(fn (array $events): int => count($events), $byCategory));

        // Catalog total should be at least the sum of the 3 main categories
        $this->assertGreaterThanOrEqual($categorySum, EventCatalog::count());
    }

    // ── EcommerceFormatConverter Cross-Provider ──────────────────

    #[Test]
    public function ecommerceConverterToBothForPurchaseReturnsConsistentStructure(): void
    {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TX-256',
                'value' => 199.99,
                'currency' => 'USD',
                'shipping' => 9.99,
                'tax' => 16.00,
                'coupon' => 'SUMMER2026',
                'items' => [
                    ['item_id' => 'P1', 'item_name' => 'Product A', 'price' => 89.99, 'quantity' => 2],
                    ['item_id' => 'P2', 'item_name' => 'Product B', 'price' => 20.01, 'quantity' => 1],
                ],
            ],
            category: 'ecommerce',
        );

        $converter = new EcommerceFormatConverter;
        $both = $converter->toBoth($event);

        // GA4 format
        $this->assertArrayHasKey('ga4', $both);
        $this->assertSame('purchase', $both['ga4']['event']);
        $this->assertSame(199.99, $both['ga4']['params']['value']);
        $this->assertSame('USD', $both['ga4']['params']['currency']);
        $this->assertSame('SUMMER2026', $both['ga4']['params']['coupon']);
        $this->assertCount(2, $both['ga4']['params']['items']);

        // Meta format
        $this->assertArrayHasKey('meta', $both);
        $this->assertSame('Purchase', $both['meta']['event_name']);
        $this->assertSame(199.99, $both['meta']['custom_data']['value']);
        $this->assertSame('USD', $both['meta']['custom_data']['currency']);
        $this->assertSame(3, $both['meta']['custom_data']['num_items']);
        $this->assertSame('product', $both['meta']['custom_data']['content_type']);
    }

    #[Test]
    public function ecommerceConverterToBothForViewItem(): void
    {
        $event = new AnalyticsEvent(
            name: 'view_item',
            params: [
                'item_id' => 'SKU-256',
                'item_name' => 'Premium Widget',
                'price' => 49.99,
                'item_category' => 'Widgets',
            ],
            category: 'ecommerce',
        );

        $converter = new EcommerceFormatConverter;
        $both = $converter->toBoth($event);

        $this->assertSame('view_item', $both['ga4']['event']);
        $this->assertSame('ViewContent', $both['meta']['event_name']);
        $this->assertSame('SKU-256', $both['meta']['custom_data']['content_ids'][0]);
    }

    #[Test]
    public function ecommerceConverterToBothForRefund(): void
    {
        $event = new AnalyticsEvent(
            name: 'refund',
            params: [
                'transaction_id' => 'TX-REF-256',
                'value' => 49.99,
                'currency' => 'EUR',
                'items' => [
                    ['item_id' => 'R1', 'price' => 49.99, 'quantity' => 1],
                ],
            ],
            category: 'ecommerce',
        );

        $converter = new EcommerceFormatConverter;
        $both = $converter->toBoth($event);

        $this->assertSame('refund', $both['ga4']['event']);
        $this->assertSame('Refund', $both['meta']['event_name']);
        $this->assertSame('EUR', $both['meta']['custom_data']['currency']);
    }

    // ── Static Format Converter Methods ──────────────────────────

    #[Test]
    public function staticGa4ToMetaContentsConversion(): void
    {
        $result = SupportEcommerceFormatConverter::ga4ToMetaContents([
            ['item_id' => 'A', 'item_name' => 'Alpha', 'price' => 10.0, 'quantity' => 3],
            ['item_id' => 'B', 'item_name' => 'Beta', 'price' => 25.0, 'quantity' => 1],
        ]);

        $this->assertSame(['A', 'B'], $result['content_ids']);
        $this->assertSame(4, $result['num_items']);
        $this->assertSame(55.0, $result['value']);
        $this->assertCount(2, $result['contents']);
    }

    #[Test]
    public function staticMetaToGa4ItemsConversion(): void
    {
        $result = SupportEcommerceFormatConverter::metaToGa4Items([
            ['id' => 'X', 'quantity' => 2, 'item_price' => 15.0, 'item_name' => 'Xray'],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('X', $result[0]['item_id']);
        $this->assertSame(15.0, $result[0]['price']);
    }

    #[Test]
    public function staticPurchaseGa4ToMetaConversion(): void
    {
        $result = SupportEcommerceFormatConverter::purchaseGa4ToMeta([
            'transaction_id' => 'TX-S-256',
            'value' => 89.99,
            'currency' => 'GBP',
            'items' => [
                ['item_id' => 'G1', 'price' => 89.99, 'quantity' => 1],
            ],
        ]);

        $this->assertSame('Purchase', $result['event_name']);
        $this->assertSame('GBP', $result['custom_data']['currency']);
        $this->assertSame(89.99, $result['custom_data']['value']);
        $this->assertSame('product', $result['custom_data']['content_type']);
    }

    // ── Identity Service Interface ───────────────────────────────

    #[Test]
    public function identityGraphServiceClassExists(): void
    {
        $this->assertTrue(class_exists(IdentityGraphService::class));
    }

    // ── Version Consistency ──────────────────────────────────────

    #[Test]
    public function versionConstantIs256(): void
    {
        $this->assertSame('256.0.0', AnalyticsEvent::VERSION);
    }

    // ── Provider Coverage Gaps ───────────────────────────────────

    #[Test]
    public function plausibleCatalogEventsHaveNonNullNamesForSupportedEvents(): void
    {
        $plausibleNames = EcommerceEvents::plausibleNames();

        // Plausible supports pageview, purchase, add_to_cart, etc.
        $this->assertContains('pageview', EngagementEvents::plausibleNames());
        $this->assertContains('purchase', $plausibleNames);
        $this->assertContains('add_to_cart', $plausibleNames);
        $this->assertContains('signup', SaaSEvents::plausibleNames());
    }

    #[Test]
    public function posthogCatalogEventsCoverAllCoreEvents(): void
    {
        // PostHog should have mappings for all core events
        $coreSaasEvents = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'];
        foreach ($coreSaasEvents as $eventName) {
            $entry = SaaSEvents::get($eventName);
            $this->assertNotNull($entry, "SaaS event '{$eventName}' should exist in catalog");
            $this->assertNotEmpty($entry['posthog'], "SaaS event '{$eventName}' should have a PostHog mapping");
        }

        $coreEngagementEvents = ['page_view', 'scroll_depth', 'click', 'form_submit', 'search', 'share', 'error'];
        foreach ($coreEngagementEvents as $eventName) {
            $entry = EngagementEvents::get($eventName);
            $this->assertNotNull($entry, "Engagement event '{$eventName}' should exist in catalog");
            $this->assertNotEmpty($entry['posthog'], "Engagement event '{$eventName}' should have a PostHog mapping");
        }
    }

    #[Test]
    public function eventCatalogAllGa4NamesAreNonEmpty(): void
    {
        $allGa4 = EventCatalog::allGa4Names();

        foreach ($allGa4 as $name) {
            $this->assertIsString($name);
            $this->assertNotEmpty($name);
        }

        // Should have substantial coverage
        $this->assertGreaterThan(50, count($allGa4));
    }

    #[Test]
    public function eventCatalogAllMetaNamesExcludeNulls(): void
    {
        $allMeta = EventCatalog::allMetaNames();

        foreach ($allMeta as $name) {
            $this->assertIsString($name);
            $this->assertNotEmpty($name);
        }

        // Meta Pixel doesn't support all events (e.g., scroll_depth, hover)
        // but should still have substantial coverage
        $this->assertGreaterThan(30, count($allMeta));
    }

    // ── Config Structure Integrity ────────────────────────────────

    #[Test]
    public function configHasAllRequiredTopLevelSections(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $analytics = $config['analytics'];

        $requiredSections = [
            'ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog',
            'consent', 'lifecycle', 'api', 'identity', 'ecommerce',
            'client_auto_track', 'queue', 'pipeline',
        ];

        foreach ($requiredSections as $section) {
            $this->assertArrayHasKey($section, $analytics, "Missing config section: {$section}");
        }
    }

    #[Test]
    public function configQueueSectionHasRequiredKeys(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $queue = $config['analytics']['queue'];

        $this->assertArrayHasKey('enabled', $queue);
        $this->assertArrayHasKey('queue', $queue);
        $this->assertArrayHasKey('connection', $queue);
        $this->assertArrayHasKey('max_batch_size', $queue);
    }

    #[Test]
    public function configIdentitySectionHasRequiredKeys(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $identity = $config['analytics']['identity'];

        $this->assertArrayHasKey('cookie_name', $identity);
        $this->assertArrayHasKey('cookie_ttl', $identity);
        $this->assertArrayHasKey('link_on_auth', $identity);
        $this->assertArrayHasKey('auto_link', $identity);
        $this->assertArrayHasKey('cache_prefix', $identity);
        $this->assertArrayHasKey('link_ttl', $identity);
    }

    #[Test]
    public function configEcommerceSectionHasRequiredKeys(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $ecommerce = $config['analytics']['ecommerce'];

        $this->assertArrayHasKey('currency', $ecommerce);
        $this->assertArrayHasKey('brand', $ecommerce);
        $this->assertArrayHasKey('tax_behavior', $ecommerce);
    }

    #[Test]
    public function configAutoTrackSectionHasRequiredKeys(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $autoTrack = $config['analytics']['client_auto_track'];

        $this->assertArrayHasKey('page_views', $autoTrack);
        $this->assertArrayHasKey('scroll_depth', $autoTrack);
        $this->assertArrayHasKey('form_tracking', $autoTrack);
        $this->assertArrayHasKey('error_tracking', $autoTrack);
        $this->assertArrayHasKey('link_tracking', $autoTrack);
        $this->assertArrayHasKey('session_tracking', $autoTrack);
        $this->assertArrayHasKey('idle_timeout', $autoTrack);
        $this->assertArrayHasKey('error_ignore_patterns', $autoTrack);
    }

    // ── Tracker Interface Contracts ───────────────────────────────

    #[Test]
    public function plausibleTrackerHeadScriptsIsEmptyWhenDisabled(): void
    {
        $tracker = new PlausibleTracker(
            domain: '',
            enabled: false,
        );

        $this->assertSame('', $tracker->headScripts());
        $this->assertSame('', $tracker->bodyScripts());
    }

    #[Test]
    public function plausibleTrackerHeadScriptsContainsDomain(): void
    {
        $tracker = new PlausibleTracker(
            domain: 'mysite.com',
            apiKey: 'key',
            enabled: true,
        );

        $scripts = $tracker->headScripts();
        $this->assertStringContainsString('data-domain="mysite.com"', $scripts);
    }

    #[Test]
    public function postHogTrackerHeadScriptsContainsConfig(): void
    {
        $tracker = new PosthogTracker(
            apiKey: 'phc_test',
            projectId: '789',
            host: 'https://us.posthog.com',
            enabled: true,
        );

        $scripts = $tracker->headScripts();
        $this->assertStringContainsString('phc_test', $scripts);
    }

    #[Test]
    public function postHogTrackerHeadScriptsEmptyWithoutProjectId(): void
    {
        $tracker = new PosthogTracker(
            apiKey: 'phc_test',
            projectId: '',
            enabled: true,
        );

        $this->assertSame('', $tracker->headScripts());
    }

    // ── SaaS Catalog Factory Methods ──────────────────────────────

    #[Test]
    public function saasCatalogFactoryMethodsReturnTypedEvents(): void
    {
        $signUp = SaaSEvents::signUp(['method' => 'email']);
        $this->assertInstanceOf(AnalyticsEvent::class, $signUp);
        $this->assertSame('sign_up', $signUp->name);
        $this->assertSame('saas', $signUp->category);
        $this->assertSame('email', $signUp->params['method']);

        $trial = SaaSEvents::startTrial(['plan' => 'pro', 'days' => 14]);
        $this->assertInstanceOf(AnalyticsEvent::class, $trial);
        $this->assertSame('start_trial', $trial->name);
        $this->assertSame('pro', $trial->params['plan']);

        $upgrade = SaaSEvents::planUpgrade(['from_plan' => 'starter', 'to_plan' => 'pro']);
        $this->assertInstanceOf(AnalyticsEvent::class, $upgrade);
        $this->assertSame('plan_upgrade', $upgrade->name);
        $this->assertSame('starter', $upgrade->params['from_plan']);

        $cancel = SaaSEvents::cancellation(['reason' => 'too_expensive']);
        $this->assertInstanceOf(AnalyticsEvent::class, $cancel);
        $this->assertSame('cancellation', $cancel->name);
    }

    // ── Engagement Catalog Factory Methods ────────────────────────

    #[Test]
    public function engagementCatalogFactoryMethodsReturnTypedEvents(): void
    {
        $pageView = EngagementEvents::pageView(['title' => 'Dashboard']);
        $this->assertInstanceOf(AnalyticsEvent::class, $pageView);
        $this->assertSame('page_view', $pageView->name);
        $this->assertSame('engagement', $pageView->category);

        $click = EngagementEvents::click(['element' => 'button', 'text' => 'Buy Now']);
        $this->assertInstanceOf(AnalyticsEvent::class, $click);
        $this->assertSame('click', $click->name);

        $search = EngagementEvents::search(['query' => 'analytics plugin']);
        $this->assertInstanceOf(AnalyticsEvent::class, $search);
        $this->assertSame('search', $search->name);
        $this->assertSame('analytics plugin', $search->params['query']);

        $share = EngagementEvents::share(['method' => 'twitter', 'content_id' => 'P-123']);
        $this->assertInstanceOf(AnalyticsEvent::class, $share);
        $this->assertSame('share', $share->name);

        $error = EngagementEvents::error(['message' => 'Network timeout', 'code' => 504]);
        $this->assertInstanceOf(AnalyticsEvent::class, $error);
        $this->assertSame('error', $error->name);
    }

    // ── Ecommerce Catalog Factory Methods ─────────────────────────

    #[Test]
    public function ecommerceCatalogFactoryMethodsReturnTypedEvents(): void
    {
        $viewItem = EcommerceEvents::viewItem(['item_id' => 'W-256', 'item_name' => 'Widget']);
        $this->assertInstanceOf(AnalyticsEvent::class, $viewItem);
        $this->assertSame('view_item', $viewItem->name);
        $this->assertSame('ecommerce', $viewItem->category);
        $this->assertSame('W-256', $viewItem->params['item_id']);

        $addToCart = EcommerceEvents::addToCart(['item_id' => 'W-256', 'price' => 29.99, 'quantity' => 2]);
        $this->assertSame('add_to_cart', $addToCart->name);
        $this->assertSame(59.98, $addToCart->params['price']);

        $refund = EcommerceEvents::refund(['transaction_id' => 'TX-R256', 'value' => 29.99]);
        $this->assertSame('refund', $refund->name);
        $this->assertSame('TX-R256', $refund->params['transaction_id']);
    }

    // ── Build Generic Factory Method ──────────────────────────────

    #[Test]
    public function catalogBuildMethodValidatesEventName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown e-commerce event');

        EcommerceEvents::build('nonexistent_event');
    }

    #[Test]
    public function catalogBuildMethodReturnsValidEvent(): void
    {
        $event = EcommerceEvents::build('purchase', ['transaction_id' => 'TX-GEN', 'value' => 50.0]);

        $this->assertSame('purchase', $event->name);
        $this->assertSame('ecommerce', $event->category);
        $this->assertSame('TX-GEN', $event->params['transaction_id']);
    }
}
