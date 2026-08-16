<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\EventPluginRegistry;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand;

beforeEach(function (): void {
    $this->config = mock(ConfigRepository::class);
});

describe('EventPluginRegistry', function (): void {
    test('instantiates with empty config', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'plugins' => []]);

        $registry = new EventPluginRegistry($this->config);

        expect($registry)->toBeInstanceOf(EventPluginRegistry::class)
            ->and($registry->pluginCount())->toBe(0)
            ->and($registry->totalEventCount())->toBe(0);
    });

    test('registers a plugin with valid events', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'plugins' => []]);

        $registry = new EventPluginRegistry($this->config);

        $registry->registerPlugin([
            'package' => 'acme/billing',
            'version' => '2.0.0',
            'priority' => 10,
            'events' => [
                [
                    'name' => 'invoice_paid',
                    'class' => AnalyticsEvent::class,
                    'ga4' => 'invoice_paid',
                    'meta' => 'Purchase',
                    'category' => 'billing',
                ],
                [
                    'name' => 'invoice_failed',
                    'class' => AnalyticsEvent::class,
                    'ga4' => 'invoice_failed',
                    'meta' => null,
                    'category' => 'billing',
                ],
            ],
        ]);

        expect($registry->pluginCount())->toBe(1)
            ->and($registry->totalEventCount())->toBe(2)
            ->and($registry->hasPlugin('acme/billing'))->toBeTrue()
            ->and($registry->hasEvent('invoice_paid'))->toBeTrue()
            ->and($registry->hasEvent('invoice_failed'))->toBeTrue()
            ->and($registry->countPluginEvents('acme/billing'))->toBe(2);
    });

    test('skips plugin with empty events', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'plugins' => []]);

        $registry = new EventPluginRegistry($this->config);

        $registry->registerPlugin([
            'package' => 'test/empty',
            'version' => '1.0.0',
            'events' => [],
        ]);

        expect($registry->pluginCount())->toBe(0);
    });

    test('skips events without name or class', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'plugins' => []]);

        $registry = new EventPluginRegistry($this->config);

        $registry->registerPlugin([
            'package' => 'test/invalid',
            'version' => '1.0.0',
            'events' => [
                ['name' => '', 'class' => AnalyticsEvent::class],
                ['name' => 'valid_event', 'class' => ''],
                'not_an_array',
            ],
        ]);

        expect($registry->totalEventCount())->toBe(0)
            ->and($registry->pluginCount())->toBe(0);
    });

    test('catalogEvents returns proper format', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'plugins' => []]);

        $registry = new EventPluginRegistry($this->config);

        $registry->registerPlugin([
            'package' => 'test/pkg',
            'version' => '1.0.0',
            'events' => [
                [
                    'name' => 'custom_event',
                    'class' => AnalyticsEvent::class,
                    'ga4' => 'custom_event',
                    'meta' => null,
                    'category' => 'custom',
                ],
            ],
        ]);

        $events = $registry->catalogEvents();
        expect($events)->toHaveKey('custom_event')
            ->and($events['custom_event']['name'])->toBe('custom_event')
            ->and($events['custom_event']['class'])->toBe(AnalyticsEvent::class)
            ->and($events['custom_event']['category'])->toBe('custom');
    });

    test('eventsByPlugin groups events by package', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'plugins' => []]);

        $registry = new EventPluginRegistry($this->config);

        $registry->registerPlugin([
            'package' => 'pkg/a',
            'version' => '1.0.0',
            'events' => [
                ['name' => 'event_a', 'class' => AnalyticsEvent::class, 'ga4' => 'event_a'],
            ],
        ]);

        $registry->registerPlugin([
            'package' => 'pkg/b',
            'version' => '1.0.0',
            'events' => [
                ['name' => 'event_b', 'class' => AnalyticsEvent::class, 'ga4' => 'event_b'],
            ],
        ]);

        $grouped = $registry->eventsByPlugin();
        expect($grouped)->toHaveKeys(['pkg/a', 'pkg/b'])
            ->and(count($grouped['pkg/a']))->toBe(1)
            ->and(count($grouped['pkg/b']))->toBe(1);
    });

    test('eventsByCategory groups events by category', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'plugins' => []]);

        $registry = new EventPluginRegistry($this->config);

        $registry->registerPlugin([
            'package' => 'test/multi',
            'version' => '1.0.0',
            'events' => [
                ['name' => 'billing_event', 'class' => AnalyticsEvent::class, 'ga4' => 'billing', 'category' => 'billing'],
                ['name' => 'crm_event', 'class' => AnalyticsEvent::class, 'ga4' => 'crm', 'category' => 'crm'],
            ],
        ]);

        $byCategory = $registry->eventsByCategory();
        expect($byCategory)->toHaveKeys(['billing', 'crm']);
    });

    test('validate returns correct results for existing classes', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'plugins' => []]);

        $registry = new EventPluginRegistry($this->config);

        $registry->registerPlugin([
            'package' => 'test/valid',
            'version' => '1.0.0',
            'events' => [
                ['name' => 'valid_event', 'class' => AnalyticsEvent::class, 'ga4' => 'valid_event'],
            ],
        ]);

        $result = $registry->validate();
        expect($result['valid'])->toBe(1)
            ->and($result['invalid'])->toBe(0)
            ->and($result['errors'])->toBe([]);
    });

    test('validate detects non-existent classes', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'plugins' => []]);

        $registry = new EventPluginRegistry($this->config);

        $registry->registerPlugin([
            'package' => 'test/invalid',
            'version' => '1.0.0',
            'events' => [
                ['name' => 'bad_event', 'class' => 'NonExistent\Class\Here', 'ga4' => 'bad_event'],
            ],
        ]);

        $result = $registry->validate();
        expect($result['valid'])->toBe(0)
            ->and($result['invalid'])->toBe(1)
            ->and(count($result['errors']))->toBe(1);
    });

    test('summary returns structured data', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'plugins' => []]);

        $registry = new EventPluginRegistry($this->config);

        $registry->registerPlugin([
            'package' => 'test/pkg',
            'version' => '1.0.0',
            'priority' => 5,
            'events' => [
                ['name' => 'event_1', 'class' => AnalyticsEvent::class, 'ga4' => 'e1', 'category' => 'billing'],
                ['name' => 'event_2', 'class' => AnalyticsEvent::class, 'ga4' => 'e2', 'category' => 'billing'],
            ],
        ]);

        $summary = $registry->summary();
        expect($summary['total_plugins'])->toBe(1)
            ->and($summary['total_events'])->toBe(2)
            ->and($summary['categories'])->toHaveKey('billing')
            ->and($summary['plugins'][0]['package'])->toBe('test/pkg')
            ->and($summary['plugins'][0]['version'])->toBe('1.0.0')
            ->and($summary['plugins'][0]['events'])->toBe(2);
    });

    test('unregisterPlugin removes events', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'plugins' => []]);

        $registry = new EventPluginRegistry($this->config);

        $registry->registerPlugin([
            'package' => 'test/remove',
            'version' => '1.0.0',
            'events' => [
                ['name' => 'remove_me', 'class' => AnalyticsEvent::class, 'ga4' => 'remove_me'],
            ],
        ]);

        expect($registry->hasEvent('remove_me'))->toBeTrue();

        $registry->unregisterPlugin('test/remove');

        expect($registry->hasEvent('remove_me'))->toBeFalse()
            ->and($registry->pluginCount())->toBe(0);
    });

    test('clear removes all plugins and events', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'plugins' => []]);

        $registry = new EventPluginRegistry($this->config);

        $registry->registerPlugin([
            'package' => 'test/a',
            'version' => '1.0.0',
            'events' => [
                ['name' => 'a1', 'class' => AnalyticsEvent::class, 'ga4' => 'a1'],
            ],
        ]);

        $registry->registerPlugin([
            'package' => 'test/b',
            'version' => '1.0.0',
            'events' => [
                ['name' => 'b1', 'class' => AnalyticsEvent::class, 'ga4' => 'b1'],
            ],
        ]);

        $registry->clear();

        expect($registry->pluginCount())->toBe(0)
            ->and($registry->totalEventCount())->toBe(0);
    });

    test('getEvent returns event or null', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'plugins' => []]);

        $registry = new EventPluginRegistry($this->config);

        $registry->registerPlugin([
            'package' => 'test/get',
            'version' => '1.0.0',
            'events' => [
                ['name' => 'findable', 'class' => AnalyticsEvent::class, 'ga4' => 'findable'],
            ],
        ]);

        expect($registry->getEvent('findable'))->not->toBeNull()
            ->and($registry->getEvent('nonexistent'))->toBeNull();
    });

    test('loads plugins from config', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.event_plugins', [])
            ->andReturn([
                'enabled' => true,
                'debug' => false,
                'plugins' => [
                    'acme/pkg' => [
                        'package' => 'acme/pkg',
                        'version' => '1.0.0',
                        'events' => [
                            ['name' => 'cfg_event', 'class' => AnalyticsEvent::class, 'ga4' => 'cfg_event'],
                        ],
                    ],
                ],
            ]);

        $registry = new EventPluginRegistry($this->config);

        expect($registry->hasPlugin('acme/pkg'))->toBeTrue()
            ->and($registry->hasEvent('cfg_event'))->toBeTrue();
    });

    test('is final class with strict types', function (): void {
        $ref = new ReflectionClass(EventPluginRegistry::class);

        expect($ref->isFinal())->toBeTrue()
            ->and($ref->getFileName())->toContain('EventPluginRegistry.php');
    });
});

describe('EventCatalog::allWithPlugins', function (): void {
    test('returns built-in events when no plugins provided', function (): void {
        $all = EventCatalog::allWithPlugins([]);
        $builtin = EventCatalog::all();

        expect($all)->toBe($builtin);
    });

    test('merges plugin events without conflicts', function (): void {
        $pluginEvents = [
            'custom_billing_event' => [
                'name' => 'custom_billing_event',
                'class' => AnalyticsEvent::class,
                'ga4' => 'custom_billing_event',
                'meta' => null,
                'category' => 'billing',
            ],
        ];

        $all = EventCatalog::allWithPlugins($pluginEvents);
        $builtin = EventCatalog::all();

        expect($all)->toHaveCount(count($builtin) + 1)
            ->and($all)->toHaveKey('custom_billing_event');
    });

    test('built-in events take precedence on name conflict', function (): void {
        $builtin = EventCatalog::all();
        $firstBuiltinName = array_key_first($builtin);

        $pluginEvents = [
            $firstBuiltinName => [
                'name' => $firstBuiltinName,
                'class' => 'Some\Other\Class',
                'ga4' => 'conflict_event',
                'meta' => null,
                'category' => 'custom',
            ],
        ];

        $all = EventCatalog::allWithPlugins($pluginEvents);

        // Built-in entry should be preserved
        expect($all[$firstBuiltinName]['class'])->toBe($builtin[$firstBuiltinName]['class']);
    });

    test('works with empty plugin events array', function (): void {
        $all = EventCatalog::allWithPlugins([]);
        $count = EventCatalog::count();

        expect($all)->toHaveCount($count);
    });
});

describe('Version Consistency', function (): void {
    test('AnalyticsEvent::VERSION is 7.8.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('7.8.0');
    });

    test('composer.json version is 7.8.0', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['version'])->toBe('7.8.0');
    });

    test('JS client version is 7.8.0', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain('@version 7.8.0');
    });

    test('Svelte composable version is 7.8.0', function (): void {
        $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
        expect($svelte)->toContain('@version 7.8.0');
    });

    test('TypeScript definitions version is 7.8.0', function (): void {
        $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
        expect($dts)->toContain('@version 7.8.0');
    });

    test('Event catalog contains events from all categories', function (): void {
        expect(EcommerceEvents::count())->toBeGreaterThan(0)
            ->and(SaaSEvents::count())->toBeGreaterThan(0)
            ->and(EngagementEvents::count())->toBeGreaterThan(0)
            ->and(EventCatalog::count())->toBeGreaterThan(0);
    });

    test('event_plugins config section exists', function (): void {
        $config = require __DIR__ . '/../config/zeroboiler.php';
        expect($config)->toBeArray();
        expect($config['analytics'])->toHaveKey('event_plugins');
        expect($config['analytics']['event_plugins'])->toHaveKeys(['enabled', 'debug', 'plugins']);
    });

    test('EventPluginRegistry has @since 7.8.0 annotation', function (): void {
        $ref = new ReflectionClass(EventPluginRegistry::class);
        $doc = $ref->getDocComment();
        expect($doc)->toContain('@since 7.8.0');
    });

    test('AnalyticsIntegrityCommand has @since 7.8.0 annotation', function (): void {
        $ref = new ReflectionClass(AnalyticsIntegrityCommand::class);
        $doc = $ref->getDocComment();
        expect($doc)->toContain('@since 7.8.0');
    });

    test('EventCatalog::allWithPlugins has @since 7.8.0 annotation', function (): void {
        $ref = new ReflectionMethod(EventCatalog::class, 'allWithPlugins');
        $doc = $ref->getDocComment();
        expect($doc)->toContain('@since 7.8.0');
    });
});

describe('PHP 8.5 Compliance', function (): void {
    test('EventPluginRegistry uses strict types', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/Events/EventPluginRegistry.php');
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('EventPluginRegistry is final', function (): void {
        $ref = new ReflectionClass(EventPluginRegistry::class);
        expect($ref->isFinal())->toBeTrue();
    });

    test('EventPluginRegistry methods have return type declarations', function (): void {
        $ref = new ReflectionClass(EventPluginRegistry::class);
        $methods = ['registerPlugin', 'catalogEvents', 'plugins', 'eventsByPlugin',
            'hasPlugin', 'hasEvent', 'getEvent', 'totalEventCount', 'countPluginEvents',
            'pluginCount', 'eventsByCategory', 'validate', 'summary', 'unregisterPlugin', 'clear',
        ];

        foreach ($methods as $method) {
            $m = $ref->getMethod($method);
            expect($m->hasReturnType())->toBeTrue()->failOnFalse("Method {$method} missing return type");
        }
    });

    test('AnalyticsIntegrityCommand uses strict types', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsIntegrityCommand.php');
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('AnalyticsIntegrityCommand is final', function (): void {
        $ref = new ReflectionClass(AnalyticsIntegrityCommand::class);
        expect($ref->isFinal())->toBeTrue();
    });

    test('AnalyticsIntegrityCommand has Override attribute on handle', function (): void {
        $ref = new ReflectionMethod(AnalyticsIntegrityCommand::class, 'handle');
        $attrs = $ref->getAttributes();
        $hasOverride = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue();
    });

    test('ServiceProvider registers EventPluginRegistry as singleton', function (): void {
        $sp = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        expect($sp)->toContain('EventPluginRegistry::class');
        expect($sp)->toContain('singleton(EventPluginRegistry::class');
    });

    test('ServiceProvider registers AnalyticsIntegrityCommand', function (): void {
        $sp = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        expect($sp)->toContain('AnalyticsIntegrityCommand::class');
    });
});
