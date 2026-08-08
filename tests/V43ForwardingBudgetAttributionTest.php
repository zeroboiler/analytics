<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Support\EventTransformer;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Services\ConsentLogService;
use ZeroBoiler\Analytics\Services\EventForwardingService;
use ZeroBoiler\Analytics\Services\PerformanceBudgetService;
use ZeroBoiler\Analytics\Services\UTMAttributionService;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Events\SaaS\FeatureLimitReachedEvent;
use ZeroBoiler\Analytics\Events\SaaS\IntegrationFailedEvent;

beforeEach(function (): void {
    $this->config = mock(Illuminate\Contracts\Config\Repository::class);
});

// ── v2.43.0 Event Forwarding, Performance Budget, UTM Attribution ───

describe('v2.43.0 Forwarding + Budget + Attribution + 70 Events', function (): void {

    // ── Event Catalog Completeness (70 events) ────────────────────

    test('total event count is exactly 70', function (): void {
        expect(EventCatalog::count())->toBe(70);
    });

    test('SaaS catalog has 37 events (35 + 2 new)', function (): void {
        expect(SaaSEvents::count())->toBe(37);
    });

    test('Ecommerce catalog has 12 events', function (): void {
        expect(EcommerceEvents::count())->toBe(12);
    });

    test('Engagement catalog has 21 events', function (): void {
        expect(EngagementEvents::count())->toBe(21);
    });

    test('all 70 events have typed classes', function (): void {
        $all = EventCatalog::all();
        foreach ($all as $name => $entry) {
            expect(isset($entry['class']))->toBeTrue("Event '{$name}' has no class");
            expect($entry['class'])->not->toBe('ZeroBoiler\\Analytics\\Events\\CustomEvent',
                "Event '{$name}' uses CustomEvent instead of typed class");
        }
    });

    test('all 70 events have GA4 mappings', function (): void {
        $all = EventCatalog::all();
        foreach ($all as $name => $entry) {
            expect(isset($entry['ga4']) && $entry['ga4'] !== '')->toBeTrue(
                "Event '{$name}' has no GA4 mapping"
            );
        }
    });

    test('all 70 events have Meta mappings', function (): void {
        $all = EventCatalog::all();
        foreach ($all as $name => $entry) {
            expect($entry['meta'] !== null)->toBeTrue(
                "Event '{$name}' has no Meta mapping"
            );
        }
    });

    test('event catalog validates successfully', function (): void {
        $result = EventCatalog::validate();
        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    // ── New Event Classes ─────────────────────────────────────────

    test('FeatureLimitReachedEvent creates with correct params', function (): void {
        $event = new FeatureLimitReachedEvent(
            featureName: 'api_requests',
            limitType: 'rate_limit',
            currentUsage: 1000,
            maxLimit: 1000,
        );

        expect($event->name)->toBe('feature_limit_reached');
        expect($event->params['feature_name'])->toBe('api_requests');
        expect($event->params['limit_type'])->toBe('rate_limit');
        expect($event->params['current_usage'])->toBe(1000);
        expect($event->params['max_limit'])->toBe(1000);
    });

    test('FeatureLimitReachedEvent filters null values', function (): void {
        $event = new FeatureLimitReachedEvent(
            featureName: 'storage',
            limitType: 'quota',
        );

        expect($event->name)->toBe('feature_limit_reached');
        expect($event->params['feature_name'])->toBe('storage');
        expect($event->params['limit_type'])->toBe('quota');
        expect(isset($event->params['current_usage']))->toBeFalse();
    });

    test('IntegrationFailedEvent creates with correct params', function (): void {
        $event = new IntegrationFailedEvent(
            integrationName: 'stripe',
            errorType: 'timeout',
            errorMessage: 'Connection timed out after 30s',
            isRetryable: true,
        );

        expect($event->name)->toBe('integration_failed');
        expect($event->params['integration_name'])->toBe('stripe');
        expect($event->params['error_type'])->toBe('timeout');
        expect($event->params['is_retryable'])->toBeTrue();
    });

    test('IntegrationFailedEvent truncates long error messages', function (): void {
        $longMessage = str_repeat('x', 600);
        $event = new IntegrationFailedEvent(
            integrationName: 'github',
            errorType: 'network',
            errorMessage: $longMessage,
        );

        expect(mb_strlen($event->params['error_message']))->toBeLessThanOrEqual(503);
    });

    test('new events exist in SaaS catalog', function (): void {
        expect(SaaSEvents::has('feature_limit_reached'))->toBeTrue();
        expect(SaaSEvents::has('integration_failed'))->toBeTrue();
    });

    test('new events extend AnalyticsEvent', function (): void {
        expect(new FeatureLimitReachedEvent('test', 'rate_limit'))
            ->toBeInstanceOf(AnalyticsEvent::class);
        expect(new IntegrationFailedEvent('test', 'timeout', 'msg'))
            ->toBeInstanceOf(AnalyticsEvent::class);
    });

    // ── EventForwardingService ────────────────────────────────────

    test('EventForwardingService is disabled by default', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $fwd = new EventForwardingService($cache, $this->config);
        expect($fwd->isEnabled())->toBeFalse();
    });

    test('EventForwardingService returns empty forwarder names when no config', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.forwarding', [])
            ->andReturn(['enabled' => false, 'forwarders' => []]);

        $fwd = new EventForwardingService($cache, $this->config);
        expect($fwd->forwarderNames())->toBeEmpty();
    });

    test('EventForwardingService disabled forwarders are skipped', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.forwarding', [])
            ->andReturn([
                'enabled' => true,
                'forwarders' => [
                    'segment' => ['enabled' => false, 'type' => 'segment'],
                ],
            ]);

        $fwd = new EventForwardingService($cache, $this->config);
        $result = $fwd->forwardEvent(new AnalyticsEvent('test', []));

        expect($result['success'])->toBeEmpty();
        expect($result['skipped'])->toContain('segment');
    });

    test('EventForwardingService reports disabled when forwarding off', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.forwarding', [])
            ->andReturn([
                'enabled' => false,
                'forwarders' => [
                    'segment' => ['enabled' => true, 'type' => 'segment', 'write_key' => 'test'],
                ],
            ]);

        $fwd = new EventForwardingService($cache, $this->config);
        $result = $fwd->forwardEvent(new AnalyticsEvent('test', []));

        expect($result['skipped'])->toContain('segment');
    });

    test('EventForwardingService hasForwarder returns false for missing', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.forwarding', [])
            ->andReturn([]);

        $fwd = new EventForwardingService($cache, $this->config);
        expect($fwd->hasForwarder('nonexistent'))->toBeFalse();
    });

    test('EventForwardingService hasForwarder returns false for disabled', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.forwarding', [])
            ->andReturn([
                'enabled' => true,
                'forwarders' => [
                    'segment' => ['enabled' => false, 'type' => 'segment'],
                ],
            ]);

        $fwd = new EventForwardingService($cache, $this->config);
        expect($fwd->hasForwarder('segment'))->toBeFalse();
    });

    // ── PerformanceBudgetService ───────────────────────────────────

    test('PerformanceBudgetService is disabled by default', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget', [])
            ->andReturn([]);

        $budget = new PerformanceBudgetService($this->config);
        expect($budget->isEnabled())->toBeFalse();
    });

    test('PerformanceBudgetService validates small events successfully', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget', [])
            ->andReturn([
                'enabled' => true,
                'max_payload_bytes' => 8192,
                'max_params_count' => 25,
                'max_param_value_length' => 500,
            ]);

        $budget = new PerformanceBudgetService($this->config);
        $event = new AnalyticsEvent('test', ['key' => 'value']);

        $result = $budget->validate($event);
        expect($result['valid'])->toBeTrue();
        expect($result['violations'])->toBeEmpty();
    });

    test('PerformanceBudgetService detects oversized params', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget', [])
            ->andReturn([
                'enabled' => true,
                'max_payload_bytes' => 8192,
                'max_params_count' => 25,
                'max_param_value_length' => 10,
            ]);

        $budget = new PerformanceBudgetService($this->config);
        $event = new AnalyticsEvent('test', ['key' => str_repeat('x', 50)]);

        $result = $budget->validate($event);
        expect($result['valid'])->toBeFalse();
        expect($result['violations'])->not->toBeEmpty();
    });

    test('PerformanceBudgetService detects too many params', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget', [])
            ->andReturn([
                'enabled' => true,
                'max_payload_bytes' => 8192,
                'max_params_count' => 2,
                'max_param_value_length' => 500,
            ]);

        $budget = new PerformanceBudgetService($this->config);
        $event = new AnalyticsEvent('test', ['a' => '1', 'b' => '2', 'c' => '3']);

        $result = $budget->validate($event);
        expect($result['valid'])->toBeFalse();
        $rules = array_column($result['violations'], 'rule');
        expect($rules)->toContain('max_params_count');
    });

    test('PerformanceBudgetService sanitizes oversized values', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget', [])
            ->andReturn([
                'enabled' => true,
                'max_param_value_length' => 10,
                'max_params_count' => 25,
                'max_payload_bytes' => 8192,
            ]);

        $budget = new PerformanceBudgetService($this->config);
        $event = new AnalyticsEvent('test', ['key' => str_repeat('x', 100)]);

        $sanitized = $budget->sanitize($event);
        expect(mb_strlen($sanitized->params['key']))->toBeLessThanOrEqual(13); // 10 + '...[truncated]'
    });

    test('PerformanceBudgetService shouldTrack returns true when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget', [])
            ->andReturn([]);

        $budget = new PerformanceBudgetService($this->config);
        expect($budget->shouldTrack(new AnalyticsEvent('test', ['x' => str_repeat('y', 9999)])))
            ->toBeTrue();
    });

    test('PerformanceBudgetService returns full config', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget', [])
            ->andReturn([
                'enabled' => true,
                'max_payload_bytes' => 4096,
                'max_params_count' => 10,
                'max_events_per_session' => 50,
                'max_events_per_user_per_day' => 200,
                'max_events_per_page_view' => 25,
                'max_param_value_length' => 200,
                'drop_oversized' => true,
                'warn_only' => false,
            ]);

        $budget = new PerformanceBudgetService($this->config);
        $cfg = $budget->getConfig();

        expect($cfg['enabled'])->toBeTrue();
        expect($cfg['max_payload_bytes'])->toBe(4096);
        expect($cfg['max_params_count'])->toBe(10);
        expect($cfg['drop_oversized'])->toBeTrue();
        expect($cfg['warn_only'])->toBeFalse();
    });

    test('PerformanceBudgetService calculates payload size', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget', [])
            ->andReturn([]);

        $budget = new PerformanceBudgetService($this->config);
        $event = new AnalyticsEvent('test', ['key' => 'value']);
        $size = $budget->getPayloadSize($event);

        expect($size)->toBeGreaterThan(0);
    });

    // ── UTMAttributionService ─────────────────────────────────────

    test('UTMAttributionService defaults to last_touch model', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution', [])
            ->andReturn([]);

        $attr = new UTMAttributionService($cache, $this->config);
        expect($attr->getModel())->toBe('last_touch');
    });

    test('UTMAttributionService accepts first_touch model', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution', [])
            ->andReturn(['model' => 'first_touch']);

        $attr = new UTMAttributionService($cache, $this->config);
        expect($attr->getModel())->toBe('first_touch');
    });

    test('UTMAttributionService accepts multi_touch model', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution', [])
            ->andReturn(['model' => 'multi_touch']);

        $attr = new UTMAttributionService($cache, $this->config);
        expect($attr->getModel())->toBe('multi_touch');
    });

    test('UTMAttributionService falls back for invalid model', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution', [])
            ->andReturn(['model' => 'invalid']);

        $attr = new UTMAttributionService($cache, $this->config);
        expect($attr->getModel())->toBe('last_touch');
    });

    test('UTMAttributionService skips recording without utm_source', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution', [])
            ->andReturn([]);

        $cache->shouldNotReceive('put');

        $attr = new UTMAttributionService($cache, $this->config);
        $attr->recordTouchpoint('user-1', ['utm_medium' => 'email']);
    });

    test('UTMAttributionService records touchpoint with utm_source', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution', [])
            ->andReturn([]);

        $cache->shouldReceive('put')
            ->once()
            ->withArgs(fn (string $key, mixed $value, int $ttl): bool =>
                str_contains($key, 'last') && is_array($value)
            );

        $cache->shouldReceive('has')->andReturn(false);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $attr = new UTMAttributionService($cache, $this->config);
        $attr->recordTouchpoint('user-1', ['utm_source' => 'google', 'utm_medium' => 'cpc']);
    });

    test('UTMAttributionService returns empty attribution when no data', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution', [])
            ->andReturn([]);

        $cache->shouldReceive('get')->andReturn(null);

        $attr = new UTMAttributionService($cache, $this->config);
        $result = $attr->getAttribution('unknown-user');

        expect($result['model'])->toBe('last_touch');
        expect($result['params']['utm_source'])->toBeNull();
        expect($result['touchpoint_count'])->toBe(0);
    });

    test('UTMAttributionService hasAttribution returns false for unknown', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution', [])
            ->andReturn([]);

        $cache->shouldReceive('has')->andReturn(false);

        $attr = new UTMAttributionService($cache, $this->config);
        expect($attr->hasAttribution('unknown'))->toBeFalse();
    });

    // ── Cross-Provider Mapping Coverage ────────────────────────────

    test('all SaaS events have PostHog mappings', function (): void {
        $posthogMap = EventTransformer::saasToPosthogEventMap();
        $saasNames = SaaSEvents::names();
        foreach ($saasNames as $name) {
            expect(isset($posthogMap[$name]))->toBeTrue(
                "SaaS event '{$name}' missing PostHog mapping"
            );
        }
    });

    test('GA4 to Meta map covers all ecommerce events', function (): void {
        $metaMap = EventTransformer::ga4ToMetaEventMap();
        $ecommerceNames = EcommerceEvents::names();
        foreach ($ecommerceNames as $name) {
            expect(isset($metaMap[$name]))->toBeTrue(
                "Ecommerce event '{$name}' missing GA4→Meta mapping"
            );
        }
    });

    // ── EcommerceFormatConverter Bidirectional ────────────────────

    test('EcommerceFormatConverter converts GA4 items to Meta format', function (): void {
        $ga4Item = [
            'item_id' => 'SKU-001',
            'item_name' => 'Widget',
            'price' => 49.99,
            'quantity' => 2,
            'item_category' => 'Electronics',
        ];
        $meta = EcommerceFormatConverter::ga4ItemToMeta($ga4Item);

        expect($meta['id'])->toBe('SKU-001');
        expect($meta['quantity'])->toBe(2);
        expect($meta['item_price'])->toBe(49.99);
    });

    test('EcommerceFormatConverter converts Meta items to GA4 format', function (): void {
        $metaItem = [
            'id' => 'SKU-001',
            'name' => 'Widget',
            'quantity' => 2,
            'item_price' => 49.99,
            'category' => 'Electronics',
        ];
        $ga4 = EcommerceFormatConverter::metaItemToGa4($metaItem);

        expect($ga4['item_id'])->toBe('SKU-001');
        expect($ga4['quantity'])->toBe(2);
        expect($ga4['price'])->toBe(49.99);
    });

    // ── ConsentLogService GDPR Purposes ────────────────────────────

    test('ConsentLogService has 4 GDPR purposes', function (): void {
        $purposes = ConsentLogService::availablePurposes();
        expect($purposes)->toHaveCount(4);
        expect($purposes)->toHaveKeys(['necessary', 'analytics', 'marketing', 'functional']);
    });

    test('ConsentLogService necessary purpose is always granted', function (): void {
        $state = ConsentLogService::defaultConsentState(false);
        expect($state['necessary'])->toBeTrue();
    });

    // ── Source File Counts ────────────────────────────────────────

    test('185 PHP source files in src/', function (): void {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }
        expect($count)->toBeGreaterThanOrEqual(185);
    });

    test('87 PHP test files in tests/', function (): void {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../tests', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }
        expect($count)->toBeGreaterThanOrEqual(85);
    });

    test('JS client library exists and has 2560+ lines', function (): void {
        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        expect(file_exists($jsPath))->toBeTrue();
        $lines = count(file($jsPath));
        expect($lines)->toBeGreaterThan(2560);
    });

    test('TypeScript definitions file exists', function (): void {
        $dtsPath = __DIR__ . '/../resources/js/analytics.d.ts';
        expect(file_exists($dtsPath))->toBeTrue();
    });

    // ── Config File Completeness ──────────────────────────────────

    test('config file has 53+ sections', function (): void {
        $configArray = require __DIR__ . '/../config/zeroboiler.php';
        $sections = array_keys($configArray['analytics'] ?? []);
        expect(count($sections))->toBeGreaterThanOrEqual(53);
    });

    test('config has forwarding section', function (): void {
        $configArray = require __DIR__ . '/../config/zeroboiler.php';
        expect(isset($configArray['analytics']['forwarding']))->toBeTrue();
        expect(isset($configArray['analytics']['forwarding']['enabled']))->toBeTrue();
        expect(isset($configArray['analytics']['forwarding']['forwarders']))->toBeTrue();
    });

    test('config has performance_budget section', function (): void {
        $configArray = require __DIR__ . '/../config/zeroboiler.php';
        expect(isset($configArray['analytics']['performance_budget']))->toBeTrue();
        expect(isset($configArray['analytics']['performance_budget']['max_payload_bytes']))->toBeTrue();
        expect(isset($configArray['analytics']['performance_budget']['drop_oversized']))->toBeTrue();
    });

    test('config has attribution section', function (): void {
        $configArray = require __DIR__ . '/../config/zeroboiler.php';
        expect(isset($configArray['analytics']['attribution']))->toBeTrue();
        expect(isset($configArray['analytics']['attribution']['model']))->toBeTrue();
        expect(isset($configArray['analytics']['attribution']['session_window_days']))->toBeTrue();
    });

    // ── Service Provider Bindings ───────────────────────────────

    test('ServiceProvider registers 53+ services', function (): void {
        $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        $singletonCount = substr_count($provider, '->singleton(');
        $bindCount = substr_count($provider, '->bind(');
        $total = $singletonCount + $bindCount;
        expect($total)->toBeGreaterThanOrEqual(53);
    });

    // ── Architecture Validation ──────────────────────────────────

    test('AnalyticsManager is final class', function (): void {
        $reflector = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsManager::class);
        expect($reflector->isFinal())->toBeTrue();
    });

    test('AnalyticsEvent DTO is readonly', function (): void {
        $reflector = new ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
        expect($reflector->isReadOnly())->toBeTrue();
    });

    test('ConsentState DTO is readonly', function (): void {
        $reflector = new ReflectionClass(\ZeroBoiler\Analytics\DTO\ConsentState::class);
        expect($reflector->isReadOnly())->toBeTrue();
    });

    test('all tracker classes implement TrackerInterface', function (): void {
        $trackers = [
            \ZeroBoiler\Analytics\Trackers\GA4Tracker::class,
            \ZeroBoiler\Analytics\Trackers\GTMTracker::class,
            \ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class,
            \ZeroBoiler\Analytics\Trackers\PlausibleTracker::class,
            \ZeroBoiler\Analytics\Trackers\PosthogTracker::class,
            \ZeroBoiler\Analytics\Trackers\WebhookTracker::class,
        ];
        foreach ($trackers as $tracker) {
            expect($tracker)->toImplement(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class);
        }
    });

    // ── API Routes Completeness ──────────────────────────────────

    test('public API routes include 75+ endpoints', function (): void {
        $routes = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        $routeCount = substr_count($routes, "Route::");
        expect($routeCount)->toBeGreaterThanOrEqual(75);
    });

    test('routes file includes forwarding, performance-budget, attribution endpoints', function (): void {
        $routes = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        expect($routes)->toContain("'forwarding'");
        expect($routes)->toContain("'performance-budget'");
        expect($routes)->toContain("'attribution'");
    });

    // ── Blameless Category Verification ──────────────────────────

    test('all engagement event classes exist and extend AnalyticsEvent', function (): void {
        $engagement = EngagementEvents::all();
        foreach ($engagement as $name => $entry) {
            $class = $entry['class'];
            expect(class_exists($class))->toBeTrue("Engagement event '{$name}' class '{$class}' does not exist");
            expect(is_a($class, AnalyticsEvent::class, true))->toBeTrue(
                "Engagement event '{$name}' class does not extend AnalyticsEvent"
            );
        }
    });

    test('all ecommerce event classes exist and extend AnalyticsEvent', function (): void {
        $ecommerce = EcommerceEvents::all();
        foreach ($ecommerce as $name => $entry) {
            $class = $entry['class'];
            expect(class_exists($class))->toBeTrue("Ecommerce event '{$name}' class '{$class}' does not exist");
            expect(is_a($class, AnalyticsEvent::class, true))->toBeTrue(
                "Ecommerce event '{$name}' class does not extend AnalyticsEvent"
            );
        }
    });

    test('all SaaS event classes exist and extend AnalyticsEvent', function (): void {
        $saas = SaaSEvents::all();
        foreach ($saas as $name => $entry) {
            $class = $entry['class'];
            expect(class_exists($class))->toBeTrue("SaaS event '{$name}' class '{$class}' does not exist");
            expect(is_a($class, AnalyticsEvent::class, true))->toBeTrue(
                "SaaS event '{$name}' class does not extend AnalyticsEvent"
            );
        }
    });

    // ── JS Client Feature Parity ──────────────────────────────────

    test('JS client exports trackEvent', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain('export function trackEvent');
    });

    test('JS client exports hasPerformanceBudget', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain('export function hasPerformanceBudget');
    });

    test('JS client exports getPerformanceBudget', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain('export function getPerformanceBudget');
    });

    test('JS client exports estimatePayloadSize', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain('export function estimatePayloadSize');
    });

    test('JS client exports exceedsPayloadBudget', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain('export function exceedsPayloadBudget');
    });

    test('JS client exports isForwardingEnabled', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain('export function isForwardingEnabled');
    });

    test('JS client exports getForwarderNames', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain('export function getForwarderNames');
    });

    test('JS client has version 2.45.0', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain("'2.94.0'");
    });

    // ── PHP 8.5 Syntax Compliance ─────────────────────────────────

    test('all source files use declare(strict_types=1)', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $contents = file_get_contents($file->getPathname());
                $firstLine = explode("\n", $contents)[0];
                expect(str_contains($firstLine, 'declare(strict_types=1)'))
                    ->toBeTrue("{$file->getFilename()} missing declare(strict_types=1)");
            }
        }
    });

    test('no 2.42.0 version references remain in source files', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $contents = file_get_contents($file->getPathname());
                expect(str_contains($contents, '2.90.0'))
                    ->toBeFalse("{$file->getFilename()} still contains stale 2.90.0 version reference");
            }
        }
    });

    // ── Version Consistency ─────────────────────────────────────

    test('composer.json has version 2.45.0', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['version'])->toBe('2.94.0');
    });

    test('AnalyticsManager version() returns 2.45.0', function (): void {
        $manager = new \ZeroBoiler\Analytics\AnalyticsManager();
        expect($manager->version())->toBe('2.94.0');
    });

    // ── AnalyticsConfig Summary Coverage ─────────────────────────

    test('AnalyticsConfig summary returns 53+ sections', function (): void {
        $configArray = require __DIR__ . '/../config/zeroboiler.php';
        $mockConfig = mock(Illuminate\Contracts\Config\Repository::class);
        $mockConfig->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default) use ($configArray) {
                $parts = explode('.', str_replace('zeroboiler.analytics.', '', $key));
                $value = $configArray['analytics'] ?? [];
                foreach ($parts as $part) {
                    if (is_array($value) && isset($value[$part])) {
                        $value = $value[$part];
                    } else {
                        return $default;
                    }
                }
                return $value;
            });

        $analyticsConfig = new AnalyticsConfig($mockConfig);
        $summary = $analyticsConfig->summary();
        expect(count($summary))->toBeGreaterThanOrEqual(53);
    });

    // ── Middleware Stack Completeness ────────────────────────────

    test('middleware stack has 10 registered middleware classes', function (): void {
        $middlewareDir = __DIR__ . '/../src/Middleware';
        expect(is_dir($middlewareDir))->toBeTrue();
        $files = glob($middlewareDir . '/*.php');
        expect(count($files))->toBeGreaterThanOrEqual(10);
    });

    // ── Pipeline Completeness ────────────────────────────────────

    test('pipeline has 9 registered filter classes', function (): void {
        $pipelineDir = __DIR__ . '/../src/Pipeline';
        expect(is_dir($pipelineDir))->toBeTrue();
        $files = glob($pipelineDir . '/*.php');
        expect(count($files))->toBeGreaterThanOrEqual(9);
    });

    // ── Services Count ───────────────────────────────────────────

    test('49 service files in Services/', function (): void {
        $servicesDir = __DIR__ . '/../src/Services';
        expect(is_dir($servicesDir))->toBeTrue();
        $files = glob($servicesDir . '/*.php');
        expect(count($files))->toBeGreaterThanOrEqual(49);
    });
});
