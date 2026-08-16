<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Macros\AnalyticsMacro;
use ZeroBoiler\Analytics\Macros\AnalyticsMacroBuilder;
use ZeroBoiler\Analytics\Macros\AnalyticsMacroRegistry;

/**
 * Tests for the AnalyticsMacro, AnalyticsMacroBuilder, and AnalyticsMacroRegistry.
 *
 * @covers \ZeroBoiler\Analytics\Macros\AnalyticsMacro
 * @covers \ZeroBoiler\Analytics\Macros\AnalyticsMacroBuilder
 * @covers \ZeroBoiler\Analytics\Macros\AnalyticsMacroRegistry
 *
 * @since 118.0.0
 */
final class V1180AnalyticsMacroSystemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AnalyticsMacroRegistry::flush();
    }

    // ── AnalyticsMacro Tests ──────────────────────────────────────────

    public function test_macro_creates_with_basic_properties(): void
    {
        $macro = new AnalyticsMacro(
            name: 'feature_used',
            eventName: 'feature_used',
            defaults: ['source' => 'app'],
            requiredKeys: ['feature_name'],
            tags: ['engagement'],
            description: 'Track feature usage',
        );

        $this->assertSame('feature_used', $macro->name());
        $this->assertSame('feature_used', $macro->eventName());
        $this->assertSame(['source' => 'app'], $macro->defaults());
        $this->assertSame(['feature_name'], $macro->requiredKeys());
        $this->assertSame(['engagement'], $macro->tags());
        $this->assertSame('Track feature usage', $macro->description());
    }

    public function test_macro_build_merges_params_with_defaults(): void
    {
        $macro = new AnalyticsMacro(
            name: 'test',
            eventName: 'test_event',
            defaults: ['source' => 'app', 'version' => '1.0'],
            requiredKeys: [],
        );

        $result = $macro->build(['source' => 'cli', 'custom' => 'value']);

        $this->assertSame([
            'params' => ['source' => 'cli', 'version' => '1.0', 'custom' => 'value'],
            'missing' => [],
        ], $result);
    }

    public function test_macro_build_throws_on_missing_required_keys(): void
    {
        $macro = new AnalyticsMacro(
            name: 'test',
            eventName: 'test_event',
            defaults: [],
            requiredKeys: ['user_id', 'feature_name'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'test' requires parameters: user_id, feature_name");

        $macro->build(['user_id' => '123']); // missing feature_name
    }

    public function test_macro_build_succeeds_when_all_required_provided(): void
    {
        $macro = new AnalyticsMacro(
            name: 'test',
            eventName: 'test_event',
            defaults: [],
            requiredKeys: ['user_id'],
        );

        $result = $macro->build(['user_id' => '123']);

        $this->assertSame(['params' => ['user_id' => '123'], 'missing' => []], $result);
    }

    public function test_macro_to_array(): void
    {
        $macro = new AnalyticsMacro(
            name: 'saas_conversion',
            eventName: 'trial_converted',
            defaults: ['currency' => 'USD'],
            requiredKeys: ['plan_name'],
            tags: ['saas', 'conversion'],
            description: 'Track trial conversion',
        );

        $array = $macro->toArray();

        $this->assertSame('saas_conversion', $array['name']);
        $this->assertSame('trial_converted', $array['event_name']);
        $this->assertSame(['currency' => 'USD'], $array['defaults']);
        $this->assertSame(['plan_name'], $array['required_keys']);
        $this->assertSame(['saas', 'conversion'], $array['tags']);
        $this->assertSame('Track trial conversion', $array['description']);
    }

    // ── AnalyticsMacroBuilder Tests ───────────────────────────────────

    public function test_builder_creates_macro(): void
    {
        $macro = AnalyticsMacroBuilder::make('test_macro', 'test_event')
            ->defaults(['env' => 'production'])
            ->required(['user_id'])
            ->tag('engagement', 'product')
            ->description('Test macro')
            ->build();

        $this->assertSame('test_macro', $macro->name());
        $this->assertSame('test_event', $macro->eventName());
        $this->assertSame(['env' => 'production'], $macro->defaults());
        $this->assertSame(['user_id'], $macro->requiredKeys());
        $this->assertSame(['engagement', 'product'], $macro->tags());
    }

    public function test_builder_register_adds_to_registry(): void
    {
        $this->assertFalse(AnalyticsMacroRegistry::has('my_macro'));

        $macro = AnalyticsMacroBuilder::make('my_macro', 'my_event')
            ->register();

        $this->assertTrue(AnalyticsMacroRegistry::has('my_macro'));
        $this->assertSame($macro, AnalyticsMacroRegistry::get('my_macro'));
    }

    public function test_builder_single_default(): void
    {
        $macro = AnalyticsMacroBuilder::make('test', 'event')
            ->default('source', 'web')
            ->build();

        $this->assertSame(['source' => 'web'], $macro->defaults());
    }

    public function test_builder_require_key(): void
    {
        $macro = AnalyticsMacroBuilder::make('test', 'event')
            ->requireKey('id')
            ->requireKey('name')
            ->build();

        $this->assertSame(['id', 'name'], $macro->requiredKeys());
    }

    public function test_builder_tags_are_deduplicated(): void
    {
        $macro = AnalyticsMacroBuilder::make('test', 'event')
            ->tag('a', 'b', 'a')
            ->build();

        $this->assertSame(['a', 'b'], $macro->tags());
    }

    // ── AnalyticsMacroRegistry Tests ───────────────────────────────────

    public function test_registry_define_fluent_api(): void
    {
        $macro = AnalyticsMacroRegistry::define('signup', 'sign_up')
            ->defaults(['method' => 'email'])
            ->required(['user_id'])
            ->tag('saas', 'auth')
            ->description('Track user signup')
            ->register();

        $this->assertTrue(AnalyticsMacroRegistry::has('signup'));
        $this->assertSame('sign_up', $macro->eventName());
    }

    public function test_registry_get_returns_null_for_unknown(): void
    {
        $this->assertNull(AnalyticsMacroRegistry::get('nonexistent'));
    }

    public function test_registry_names_returns_empty_when_empty(): void
    {
        $this->assertSame([], AnalyticsMacroRegistry::names());
    }

    public function test_registry_count(): void
    {
        $this->assertSame(0, AnalyticsMacroRegistry::count());

        AnalyticsMacroRegistry::define('a', 'event_a')->register();
        AnalyticsMacroRegistry::define('b', 'event_b')->register();

        $this->assertSame(2, AnalyticsMacroRegistry::count());
    }

    public function test_registry_forget(): void
    {
        AnalyticsMacroRegistry::define('removable', 'event')->register();
        $this->assertTrue(AnalyticsMacroRegistry::has('removable'));

        AnalyticsMacroRegistry::forget('removable');
        $this->assertFalse(AnalyticsMacroRegistry::has('removable'));
    }

    public function test_registry_flush(): void
    {
        AnalyticsMacroRegistry::define('a', 'event_a')->register();
        AnalyticsMacroRegistry::define('b', 'event_b')->register();
        $this->assertSame(2, AnalyticsMacroRegistry::count());

        AnalyticsMacroRegistry::flush();
        $this->assertSame(0, AnalyticsMacroRegistry::count());
    }

    public function test_registry_by_tag(): void
    {
        AnalyticsMacroRegistry::define('m1', 'e1')->tag('saas')->register();
        AnalyticsMacroRegistry::define('m2', 'e2')->tag('saas', 'auth')->register();
        AnalyticsMacroRegistry::define('m3', 'e3')->tag('engagement')->register();

        $byTag = AnalyticsMacroRegistry::byTag();

        $this->assertContains('m1', $byTag['saas']);
        $this->assertContains('m2', $byTag['saas']);
        $this->assertContains('m2', $byTag['auth']);
        $this->assertContains('m3', $byTag['engagement']);
    }

    public function test_registry_validate_with_valid_macros(): void
    {
        AnalyticsMacroRegistry::define('valid_macro', 'valid_event')
            ->required(['id'])
            ->tag('test')
            ->description('A valid macro')
            ->register();

        $result = AnalyticsMacroRegistry::validate();

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_registry_validate_detects_empty_event_name(): void
    {
        $macro = new AnalyticsMacro(
            name: 'bad_event',
            eventName: '',
            requiredKeys: [],
        );
        AnalyticsMacroRegistry::register($macro);

        $result = AnalyticsMacroRegistry::validate();

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_registry_validate_warns_no_description(): void
    {
        AnalyticsMacroRegistry::define('no_desc', 'event')->register();

        $result = AnalyticsMacroRegistry::validate();

        // Should have warnings but no errors
        $this->assertNotEmpty($result['warnings']);
        $hasDescWarning = false;
        foreach ($result['warnings'] as $w) {
            if (str_contains($w, 'no description')) {
                $hasDescWarning = true;
            }
        }
        $this->assertTrue($hasDescWarning);
    }

    public function test_registry_validate_warns_no_tags(): void
    {
        AnalyticsMacroRegistry::define('no_tags', 'event')
            ->description('Has description but no tags')
            ->register();

        $result = AnalyticsMacroRegistry::validate();

        $hasTagWarning = false;
        foreach ($result['warnings'] as $w) {
            if (str_contains($w, 'no tags')) {
                $hasTagWarning = true;
            }
        }
        $this->assertTrue($hasTagWarning);
    }

    public function test_registry_summary(): void
    {
        AnalyticsMacroRegistry::define('m1', 'e1')->tag('saas')->register();
        AnalyticsMacroRegistry::define('m2', 'e2')->tag('saas', 'engagement')->register();

        $summary = AnalyticsMacroRegistry::summary();

        $this->assertSame(2, $summary['count']);
        $this->assertSame(2, $summary['by_tag']['saas']);
        $this->assertSame(1, $summary['by_tag']['engagement']);
        $this->assertSame(['m1', 'm2'], $summary['names']);
    }

    public function test_registry_load_from_config(): void
    {
        $config = [
            'enabled' => true,
            'definitions' => [
                'config_macro' => [
                    'event' => 'config_event',
                    'defaults' => ['env' => 'test'],
                    'required' => ['id'],
                    'tags' => ['config', 'test'],
                    'description' => 'From config',
                ],
            ],
        ];

        AnalyticsMacroRegistry::loadFromConfig($config);

        $this->assertTrue(AnalyticsMacroRegistry::has('config_macro'));
        $macro = AnalyticsMacroRegistry::get('config_macro');
        $this->assertSame('config_event', $macro->eventName());
        $this->assertSame(['env' => 'test'], $macro->defaults());
        $this->assertSame(['id'], $macro->requiredKeys());
    }

    public function test_registry_config_does_not_override_programmatic(): void
    {
        AnalyticsMacroRegistry::define('prog', 'prog_event')->register();

        $config = [
            'enabled' => true,
            'definitions' => [
                'prog' => [
                    'event' => 'config_event', // should NOT override
                ],
            ],
        ];

        AnalyticsMacroRegistry::loadFromConfig($config);

        $macro = AnalyticsMacroRegistry::get('prog');
        $this->assertSame('prog_event', $macro->eventName());
    }

    public function test_registry_all_returns_all_macros(): void
    {
        AnalyticsMacroRegistry::define('a', 'ea')->register();
        AnalyticsMacroRegistry::define('b', 'eb')->register();

        $all = AnalyticsMacroRegistry::all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('a', $all);
        $this->assertArrayHasKey('b', $all);
    }
}
