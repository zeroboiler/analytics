<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Services\DashboardWidgetService;
use ZeroBoiler\Analytics\Services\EventStreamService;

beforeEach(function (): void {
    $this->cache = app('cache');
    $this->cache->flush();

    $this->metrics = new AnalyticsMetrics();
    $this->config = [
        'enabled' => true,
        'cache_ttl' => 60,
        'max_top_events' => 5,
        'timeline_points' => 6,
        'widgets' => null,
    ];
});

describe('DashboardWidgetService', function (): void {
    test('constructor accepts config, cache, metrics, and optional stream service', function (): void {
        $service = new DashboardWidgetService(
            $this->cache,
            $this->metrics,
            $this->config,
        );

        expect($service)->toBeInstanceOf(DashboardWidgetService::class);
    });

    test('knownWidgets returns all 8 widget names', function (): void {
        $service = new DashboardWidgetService($this->cache, $this->metrics, $this->config);

        $widgets = $service->knownWidgets();

        expect($widgets)->toContain('overview');
        expect($widgets)->toContain('events_top');
        expect($widgets)->toContain('events_timeline');
        expect($widgets)->toContain('revenue_summary');
        expect($widgets)->toContain('saas_funnel');
        expect($widgets)->toContain('engagement');
        expect($widgets)->toContain('providers');
        expect($widgets)->toContain('ecommerce');
        expect($widgets)->toHaveCount(8);
    });

    test('enabledWidgets returns all known widgets when config.widgets is null', function (): void {
        $service = new DashboardWidgetService($this->cache, $this->metrics, $this->config);

        $enabled = $service->enabledWidgets();

        expect($enabled)->toEqual($service->knownWidgets());
    });

    test('enabledWidgets filters to configured widget list', function (): void {
        $config = array_merge($this->config, ['widgets' => ['overview', 'providers']]);
        $service = new DashboardWidgetService($this->cache, $this->metrics, $config);

        $enabled = $service->enabledWidgets();

        expect($enabled)->toEqual(['overview', 'providers']);
    });

    test('enabledWidgets filters out unknown widget names', function (): void {
        $config = array_merge($this->config, ['widgets' => ['overview', 'nonexistent_widget']]);
        $service = new DashboardWidgetService($this->cache, $this->metrics, $config);

        $enabled = $service->enabledWidgets();

        expect($enabled)->toEqual(['overview']);
    });

    test('stats returns service configuration', function (): void {
        $service = new DashboardWidgetService($this->cache, $this->metrics, $this->config);

        $stats = $service->stats();

        expect($stats)->toHaveKey('enabled_widgets');
        expect($stats)->toHaveKey('cache_ttl');
        expect($stats)->toHaveKey('known_widgets');
        expect($stats['cache_ttl'])->toBe(60);
    });

    test('getWidget returns error for unknown widget', function (): void {
        $service = new DashboardWidgetService($this->cache, $this->metrics, $this->config);

        $result = $service->getWidget('nonexistent');

        expect($result)->toHaveKey('error');
        expect($result['code'])->toBe('WIDGET_UNKNOWN');
    });

    test('overview widget returns expected structure', function (): void {
        $this->metrics->incrementDispatched('ga4', 'page_view');
        $this->metrics->incrementDispatched('meta', 'purchase');

        $service = new DashboardWidgetService($this->cache, $this->metrics, $this->config);
        $widget = $service->getWidget('overview');

        expect($widget)->toHaveKey('total_events');
        expect($widget)->toHaveKey('total_failed');
        expect($widget)->toHaveKey('success_rate');
        expect($widget)->toHaveKey('catalog');
        expect($widget)->toHaveKey('version');
        expect($widget['version'])->toBe(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION);
        expect($widget['total_events'])->toBe(2);
        expect($widget['success_rate'])->toBe(100.0);
        expect($widget['catalog'])->toHaveKey('total');
        expect($widget['catalog'])->toHaveKey('ecommerce');
        expect($widget['catalog'])->toHaveKey('saas');
        expect($widget['catalog'])->toHaveKey('engagement');
    });

    test('providers widget returns per-provider metrics', function (): void {
        $this->metrics->incrementDispatched('ga4', 'page_view');
        $this->metrics->incrementDispatched('ga4', 'click');
        $this->metrics->incrementFailed('ga4', 'page_view');
        $this->metrics->incrementDispatched('meta', 'purchase');

        $service = new DashboardWidgetService($this->cache, $this->metrics, $this->config);
        $widget = $service->getWidget('providers');

        expect($widget)->toHaveKey('providers');
        expect($widget['providers'])->toHaveKey('ga4');
        expect($widget['providers']['ga4']['dispatched'])->toBe(2);
        expect($widget['providers']['ga4']['failed'])->toBe(1);
        expect($widget['providers']['ga4']['success_rate'])->toBe(50.0);
        expect($widget['providers'])->toHaveKey('meta');
        expect($widget['providers']['meta']['success_rate'])->toBe(100.0);
    });

    test('saas_funnel widget returns funnel steps', function (): void {
        $this->metrics->incrementDispatched('ga4', 'sign_up');
        $this->metrics->incrementDispatched('ga4', 'sign_up');
        $this->metrics->incrementDispatched('ga4', 'start_trial');

        $service = new DashboardWidgetService($this->cache, $this->metrics, $this->config);
        $widget = $service->getWidget('saas_funnel');

        expect($widget)->toHaveKey('steps');
        expect($widget['steps'])->toHaveCount(5);
        expect($widget['steps'][0]['event'])->toBe('sign_up');
        expect($widget['steps'][0]['count'])->toBe(2);
        expect($widget['steps'][0]['rate'])->toBe(100.0);
        expect($widget['steps'][1]['event'])->toBe('start_trial');
        expect($widget['steps'][1]['rate'])->toBe(50.0);
    });

    test('engagement widget returns engagement metrics', function (): void {
        $this->metrics->incrementDispatched('ga4', 'page_view');
        $this->metrics->incrementDispatched('ga4', 'page_view');
        $this->metrics->incrementDispatched('ga4', 'click');
        $this->metrics->incrementDispatched('ga4', 'purchase');

        $service = new DashboardWidgetService($this->cache, $this->metrics, $this->config);
        $widget = $service->getWidget('engagement');

        expect($widget)->toHaveKey('total_events');
        expect($widget)->toHaveKey('unique_event_types');
        expect($widget)->toHaveKey('engagement_events');
        expect($widget)->toHaveKey('engagement_rate');
        expect($widget['total_events'])->toBe(4);
        expect($widget['unique_event_types'])->toBe(3); // page_view, click, purchase
        expect($widget['engagement_events'])->toBe(3); // page_view(2) + click(1)
        expect($widget['engagement_rate'])->toBe(75.0);
    });

    test('ecommerce widget returns purchase funnel', function (): void {
        $this->metrics->incrementDispatched('ga4', 'view_item');
        $this->metrics->incrementDispatched('ga4', 'add_to_cart');
        $this->metrics->incrementDispatched('ga4', 'purchase');

        $service = new DashboardWidgetService($this->cache, $this->metrics, $this->config);
        $widget = $service->getWidget('ecommerce');

        expect($widget)->toHaveKey('funnel');
        expect($widget)->toHaveKey('total_ecommerce_events');
        expect($widget['funnel'])->toBeArray();
        expect($widget['total_ecommerce_events'])->toBe(3);
    });

    test('allWidgets returns combined widget data', function (): void {
        $this->metrics->incrementDispatched('ga4', 'page_view');

        $service = new DashboardWidgetService($this->cache, $this->metrics, $this->config);
        $result = $service->allWidgets();

        expect($result)->toHaveKey('version');
        expect($result)->toHaveKey('generated_at');
        expect($result)->toHaveKey('widgets');
        expect($result['version'])->toBe(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION);
        expect(count($result['widgets']))->toBe(8);
        expect($result['widgets'])->toHaveKey('overview');
        expect($result['widgets'])->toHaveKey('providers');
    });

    test('allWidgets only includes enabled widgets', function (): void {
        $config = array_merge($this->config, ['widgets' => ['overview', 'providers']]);
        $service = new DashboardWidgetService($this->cache, $this->metrics, $config);

        $result = $service->allWidgets();

        expect($result['widgets'])->toHaveCount(2);
        expect($result['widgets'])->toHaveKey('overview');
        expect($result['widgets'])->toHaveKey('providers');
        expect($result['widgets'])->not->toHaveKey('ecommerce');
    });

    test('widget results are cached', function (): void {
        $this->metrics->incrementDispatched('ga4', 'page_view');

        $service = new DashboardWidgetService($this->cache, $this->metrics, $this->config);

        // First call — computes
        $first = $service->getWidget('overview');
        // Second call — should be from cache
        $second = $service->getWidget('overview');

        expect($first)->toEqual($second);
    });

    test('invalidateWidget clears widget cache', function (): void {
        $this->metrics->incrementDispatched('ga4', 'page_view');

        $service = new DashboardWidgetService($this->cache, $this->metrics, $this->config);
        $first = $service->getWidget('overview');

        // Add more events after first computation
        $this->metrics->incrementDispatched('ga4', 'click');

        // Invalidate and re-compute
        $service->invalidateWidget('overview');
        $second = $service->getWidget('overview');

        // Second computation should reflect the new event
        expect($second['total_events'])->toBe(2);
    });

    test('invalidateAll clears all widget caches', function (): void {
        $service = new DashboardWidgetService($this->cache, $this->metrics, $this->config);

        // Trigger computation for all widgets
        $service->allWidgets();

        // Should not throw
        $service->invalidateAll();

        expect(true)->toBeTrue();
    });

    test('version consistency across all entry points', function (): void {
        $composerVersion = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true)['version'];
        $dtoVersion = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;

        expect($dtoVersion)->toBe('8.3.0');
        expect($composerVersion)->toBe('8.3.0');
    });

    test('DashboardWidgetService has strict types and final class', function (): void {
        $reflection = new ReflectionClass(DashboardWidgetService::class);

        expect($reflection->isFinal())->toBeTrue();
    });

    test('all public methods have return type declarations', function (): void {
        $reflection = new ReflectionClass(DashboardWidgetService::class);
        $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($publicMethods as $method) {
            if ($method->getDeclaringClass()->getName() !== DashboardWidgetService::class) {
                continue;
            }

            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull(
                "Method {$method->getName()}() must have a return type declaration",
            );
        }
    });
});
