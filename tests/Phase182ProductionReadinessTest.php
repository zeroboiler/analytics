<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEvents;
use ZeroBoiler\Analytics\Events\SaaS\CustomerSuccessEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\Webhook\WebhookEvents;
use ZeroBoiler\Analytics\Events\Webhook\WebhookEventConstants;
use ZeroBoiler\Analytics\Tracking\TrackerInterface;

/**
 * Phase 182 Production Readiness Test.
 *
 * Validates v182.0.0: 4 new Svelte composables, Webhook event category,
 * EventCatalog integration, version consistency, and file integrity.
 *
 * @since 182.0.0
 */
test('phase 182: version consistency across all entry points', function (): void {
    $expectedVersion = '182.0.0';

    // DTO version constant
    expect(AnalyticsEvent::VERSION)->toBe($expectedVersion);

    // Composer version
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($composer['version'])->toBe($expectedVersion);

    // Package.json version
    $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($pkg['version'])->toBe($expectedVersion);

    // Integrity command expected version
    $doc = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsIntegrityCommand.php');
    expect($doc)->toContain("'{$expectedVersion}'");

    // ServiceProvider version
    $spDoc = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($spDoc)->toContain("@version {$expectedVersion}");

    // JS client version
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain("@version {$expectedVersion}");
    expect($js)->toContain("return '{$expectedVersion}'");

    // Constants version
    $constants = file_get_contents(__DIR__ . '/../resources/js/analytics.constants.js');
    expect($constants)->toContain("@version {$expectedVersion}");

    // All 11 Svelte composables version
    $composables = [
        'useAnalytics.svelte.js',
        'useAnalyticsConfig.svelte.js',
        'useEcommerce.svelte.js',
        'useLifecycle.svelte.js',
        'useSaaSMetrics.svelte.js',
        'usePerformanceTracker.svelte.js',
        'useSessionReplay.svelte.js',
        'useScrollDepth.svelte.js',
        'useConsent.svelte.js',
        'useIdentity.svelte.js',
        'usePageView.svelte.js',
    ];

    foreach ($composables as $composable) {
        $path = __DIR__ . '/../resources/js/' . $composable;
        expect(file_exists($path))->toBeTrue("Missing composable: {$composable}");
        $content = file_get_contents($path);
        expect($content)->toContain("@version {$expectedVersion}", "Composable {$composable} missing version {$expectedVersion}");
    }

    // README version badge
    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain("version-{$expectedVersion}");
});

test('phase 182: webhook event category integration', function (): void {
    // WebhookEvents catalog exists and has 3 events
    expect(WebhookEvents::count())->toBe(3);
    expect(WebhookEvents::names())->toBe([
        'webhook_delivered',
        'webhook_failed',
        'webhook_received',
    ]);

    // Each event has required fields
    foreach (WebhookEvents::names() as $name) {
        $entry = WebhookEvents::find($name);
        expect($entry)->not->toBeNull();
        expect($entry)->toHaveKey('name');
        expect($entry)->toHaveKey('class');
        expect($entry)->toHaveKey('ga4');
        expect($entry)->toHaveKey('posthog');
        expect($entry['name'])->toBe($name);
        expect(class_exists($entry['class']))->toBeTrue("Class {$entry['class']} does not exist");
    }

    // EventCatalog includes webhook category
    $all = EventCatalog::all();
    expect(isset($all['webhook_delivered']))->toBeTrue('EventCatalog missing webhook_delivered');
    expect(isset($all['webhook_failed']))->toBeTrue('EventCatalog missing webhook_failed');
    expect(isset($all['webhook_received']))->toBeTrue('EventCatalog missing webhook_received');

    $categories = EventCatalog::byCategory();
    expect(isset($categories['webhook']))->toBeTrue('EventCatalog::byCategory() missing webhook');
    expect(count($categories['webhook']))->toBe(3);

    // getCategory returns webhook
    expect(EventCatalog::getCategory('webhook_delivered'))->toBe('webhook');
    expect(EventCatalog::getCategory('webhook_failed'))->toBe('webhook');
    expect(EventCatalog::getCategory('webhook_received'))->toBe('webhook');

    // has() includes webhook events
    expect(EventCatalog::has('webhook_delivered'))->toBeTrue();
    expect(EventCatalog::has('webhook_failed'))->toBeTrue();
    expect(EventCatalog::has('webhook_received'))->toBeTrue();
});

test('phase 182: webhook event classes are valid', function (): void {
    // WebhookDeliveredEvent
    $delivered = new \ZeroBoiler\Analytics\Events\Webhook\WebhookDeliveredEvent(
        webhookId: 'wh_123',
        url: 'https://example.com/hook',
        statusCode: 200,
        responseTimeMs: 150,
    );
    expect($delivered->name)->toBe('webhook_delivered');
    expect($delivered->params['webhook_id'])->toBe('wh_123');
    expect($delivered->params['status_code'])->toBe(200);
    expect($delivered->params['response_time_ms'])->toBe(150);

    // WebhookFailedEvent
    $failed = new \ZeroBoiler\Analytics\Events\Webhook\WebhookFailedEvent(
        webhookId: 'wh_456',
        url: 'https://example.com/hook',
        errorType: 'timeout',
        errorMessage: 'Connection timed out',
        attempt: 3,
    );
    expect($failed->name)->toBe('webhook_failed');
    expect($failed->params['error_type'])->toBe('timeout');
    expect($failed->params['attempt'])->toBe(3);

    // WebhookReceivedEvent
    $received = new \ZeroBoiler\Analytics\Events\Webhook\WebhookReceivedEvent(
        source: 'stripe',
        event: 'payment.succeeded',
    );
    expect($received->name)->toBe('webhook_received');
    expect($received->params['source'])->toBe('stripe');
    expect($received->params['event'])->toBe('payment.succeeded');
});

test('phase 182: webhook event constants', function (): void {
    expect(WebhookEventConstants::WEBHOOK_DELIVERED)->toBe('webhook_delivered');
    expect(WebhookEventConstants::WEBHOOK_FAILED)->toBe('webhook_failed');
    expect(WebhookEventConstants::WEBHOOK_RECEIVED)->toBe('webhook_received');
    expect(WebhookEventConstants::all())->toHaveCount(3);
});

test('phase 182: new Svelte composables file integrity', function (): void {
    $base = __DIR__ . '/../resources/js';

    // useScrollDepth.svelte.js
    $scroll = file_get_contents("{$base}/useScrollDepth.svelte.js");
    expect($scroll)->toContain('useScrollDepth');
    expect($scroll)->toContain('scrollPercent');
    expect($scroll)->toContain('milestonesReached');
    expect($scroll)->toContain('forceTrack');
    expect($scroll)->toContain('onDestroy');
    expect($scroll)->toContain('svelte/store');

    // useConsent.svelte.js
    $consent = file_get_contents("{$base}/useConsent.svelte.js");
    expect($consent)->toContain('useConsent');
    expect($consent)->toContain('consentState');
    expect($consent)->toContain('grantAll');
    expect($consent)->toContain('denyAll');
    expect($consent)->toContain('gtag');
    expect($consent)->toContain('analytics_storage');

    // useIdentity.svelte.js
    $identity = file_get_contents("{$base}/useIdentity.svelte.js");
    expect($identity)->toContain('useIdentity');
    expect($identity)->toContain('linkIdentity');
    expect($identity)->toContain('isAuthenticated');
    expect($identity)->toContain('justLoggedIn');
    expect($identity)->toContain('justLoggedOut');

    // usePageView.svelte.js
    $pv = file_get_contents("{$base}/usePageView.svelte.js");
    expect($pv)->toContain('usePageView');
    expect($pv)->toContain('trackVirtualPageView');
    expect($pv)->toContain('pageViewCount');
    expect($pv)->toContain('avgTimeBetweenViews');
});

test('phase 182: JS constants include webhook events', function (): void {
    $constants = file_get_contents(__DIR__ . '/../resources/js/analytics.constants.js');
    expect($constants)->toContain('WebhookEventsConstants');
    expect($constants)->toContain('WEBHOOK_DELIVERED');
    expect($constants)->toContain('WEBHOOK_FAILED');
    expect($constants)->toContain('WEBHOOK_RECEIVED');
    expect($constants)->toContain('...WebhookEventsConstants');
    expect($constants)->toContain("'webhook'");
    expect($constants)->toContain('getCategoryNames');
});

test('phase 182: event catalog has 9 categories', function (): void {
    $categories = EventCatalog::byCategory();
    expect(count($categories))->toBe(9);
    expect(array_keys($categories))->toBe([
        'ecommerce',
        'saas',
        'engagement',
        'security',
        'uptime',
        'infrastructure',
        'marketing',
        'customer_success',
        'webhook',
    ]);

    // All categories have events
    foreach ($categories as $name => $events) {
        expect(count($events))->toBeGreaterThan(0, "Category {$name} has no events");
    }
});

test('phase 182: total event count exceeds 197', function (): void {
    $all = EventCatalog::all();
    expect(count($all))->toBeGreaterThanOrEqual(197);
});

test('phase 182: PHP files have strict types and MIT headers', function (): void {
    $files = [
        'src/Events/Webhook/WebhookEvents.php',
        'src/Events/Webhook/WebhookDeliveredEvent.php',
        'src/Events/Webhook/WebhookFailedEvent.php',
        'src/Events/Webhook/WebhookReceivedEvent.php',
        'src/Events/WebhookEventConstants.php',
    ];

    foreach ($files as $file) {
        $path = __DIR__ . '/../' . $file;
        expect(file_exists($path))->toBeTrue("Missing file: {$file}");

        $content = file_get_contents($path);
        expect($content)->toContain('declare(strict_types=1)', "{$file} missing strict types");
        expect($content)->toContain('MIT', "{$file} missing MIT license reference");
    }
});

test('phase 182: no stale version references in new files', function (): void {
    $newFiles = [
        'src/Events/Webhook/WebhookEvents.php',
        'src/Events/Webhook/WebhookDeliveredEvent.php',
        'src/Events/Webhook/WebhookFailedEvent.php',
        'src/Events/Webhook/WebhookReceivedEvent.php',
        'src/Events/WebhookEventConstants.php',
        'resources/js/useScrollDepth.svelte.js',
        'resources/js/useConsent.svelte.js',
        'resources/js/useIdentity.svelte.js',
        'resources/js/usePageView.svelte.js',
    ];

    foreach ($newFiles as $file) {
        $content = file_get_contents(__DIR__ . '/../' . $file);
        expect($content)->not->toContain('181.0.0', "{$file} contains stale version 181.0.0");
    }
});
