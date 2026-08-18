<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Blueprints\EventBlueprint;
use ZeroBoiler\Analytics\Blueprints\EventBlueprintRegistry;
use ZeroBoiler\Analytics\Blueprints\EventBlueprintBuilderService;

#[CoversClass(EventBlueprintBuilderService::class)]
#[CoversClass(EventBlueprintRegistry::class)]
#[CoversClass(EventBlueprint::class)]
#[Group('blueprints')]
#[Group('v246')]
class V246BlueprintBuilderServiceTest extends TestCase
{
    private EventBlueprintRegistry $registry;
    private EventBlueprintBuilderService $builder;

    protected function setUp(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturn(null);
        $cache->method('has')->willReturn(false);
        $this->registry = new EventBlueprintRegistry($cache);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.blueprint_builder', null, [
                'enabled' => true,
                'default_provider_format' => 'ga4',
                'auto_coerce' => true,
                'pii_fields' => ['email', 'phone', 'ssn'],
            ]],
        ]);

        $this->builder = new EventBlueprintBuilderService($this->registry, $config);
    }

    // ------------------------------------------------------------------
    // Blueprint Registry
    // ------------------------------------------------------------------

    public function test_register_and_retrieve_blueprint(): void
    {
        $bp = EventBlueprint::make('test.purchase', 'Purchase Event')
            ->description('Tracks a completed purchase')
            ->category('ecommerce')
            ->param('transaction_id', 'string', true)
            ->param('value', 'numeric', true)
            ->param('currency', 'string', false, 'USD');

        $this->registry->register($bp);

        $retrieved = $this->registry->get('test.purchase');
        $this->assertNotNull($retrieved);
        $this->assertSame('Purchase Event', $retrieved->label);
        $this->assertSame('ecommerce', $retrieved->category);
        $this->assertCount(3, $retrieved->params);
    }

    public function test_registry_returns_null_for_unknown(): void
    {
        $this->assertNull($this->registry->get('nonexistent'));
    }

    public function test_registry_all_and_count(): void
    {
        $this->registry->register(EventBlueprint::make('a', 'A'));
        $this->registry->register(EventBlueprint::make('b', 'B'));

        $this->assertCount(2, $this->registry->all());
        $this->assertSame(2, $this->registry->count());
    }

    public function test_registry_has_and_remove(): void
    {
        $bp = EventBlueprint::make('temp', 'Temporary');
        $this->registry->register($bp);

        $this->assertTrue($this->registry->has('temp'));
        $this->registry->remove('temp');
        $this->assertFalse($this->registry->has('temp'));
    }

    // ------------------------------------------------------------------
    // Auto Coercion
    // ------------------------------------------------------------------

    public function test_auto_coercion_casts_strings(): void
    {
        $this->registry->register(
            EventBlueprint::make('coerce.test', 'Coerce')
                ->param('count', 'int', true)
                ->param('price', 'float', true)
                ->param('active', 'bool', true)
                ->param('name', 'string', true)
        );

        $result = $this->builder->build('coerce.test', [
            'count' => '42',
            'price' => '3.14',
            'active' => 'true',
            'name' => 'test',
        ]);

        $params = $result['params'];
        $this->assertSame(42, $params['count']);
        $this->assertSame(3.14, $params['price']);
        $this->assertTrue($params['active']);
        $this->assertSame('test', $params['name']);
    }

    // ------------------------------------------------------------------
    // Required Param Validation
    // ------------------------------------------------------------------

    public function test_build_throws_on_missing_required(): void
    {
        $this->registry->register(
            EventBlueprint::make('req.test', 'Required')
                ->param('email', 'string', true)
                ->param('optional', 'string', false)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('email');

        $this->builder->build('req.test', ['optional' => 'val']);
    }

    // ------------------------------------------------------------------
    // User ID / Session ID
    // ------------------------------------------------------------------

    public function test_user_and_session_ids_in_result(): void
    {
        $this->registry->register(
            EventBlueprint::make('id.test', 'Identity')
                ->param('action', 'string', true)
        );

        $result = $this->builder
            ->withUserId('user-123')
            ->withSessionId('sess-456')
            ->build('id.test', ['action' => 'click']);

        $this->assertSame('user-123', $result['user_id']);
        $this->assertSame('sess-456', $result['session_id']);
    }

    // ------------------------------------------------------------------
    // Default Values
    // ------------------------------------------------------------------

    public function test_default_values_applied(): void
    {
        $this->registry->register(
            EventBlueprint::make('defaults.test', 'Defaults')
                ->param('required', 'string', true)
                ->param('currency', 'string', false, 'USD')
                ->param('locale', 'string', false, 'en-US')
        );

        $result = $this->builder->build('defaults.test', ['required' => 'x']);

        $this->assertSame('USD', $result['params']['currency']);
        $this->assertSame('en-US', $result['params']['locale']);
    }

    // ------------------------------------------------------------------
    // PII Redaction
    // ------------------------------------------------------------------

    public function test_pii_redaction_in_provider_payloads(): void
    {
        $this->registry->register(
            EventBlueprint::make('pii.test', 'PII')
                ->param('email', 'string', true)
                ->param('event_name', 'string', true)
        );

        $payloads = $this->builder
            ->withPiiRedaction()
            ->buildProviderPayloads('pii.test', [
                'email' => 'user@example.com',
                'event_name' => 'signup',
            ]);

        $ga4 = $payloads['ga4'] ?? [];
        $this->assertArrayHasKey('email', $ga4['params']);
        $this->assertSame('[REDACTED]', $ga4['params']['email']);
        $this->assertSame('signup', $ga4['params']['event_name']);
    }

    // ------------------------------------------------------------------
    // Batch Building
    // ------------------------------------------------------------------

    public function test_batch_build_multiple_variations(): void
    {
        $this->registry->register(
            EventBlueprint::make('batch.test', 'Batch')
                ->param('product', 'string', true)
                ->param('price', 'numeric', true)
        );

        $results = $this->builder->buildBatch('batch.test', [
            ['product' => 'A', 'price' => 10],
            ['product' => 'B', 'price' => 20],
            ['product' => 'C', 'price' => 30],
        ]);

        $this->assertCount(3, $results);
        $this->assertSame(10, $results[0]['params']['price']);
        $this->assertSame('C', $results[2]['params']['product']);
    }

    // ------------------------------------------------------------------
    // Provider Payloads
    // ------------------------------------------------------------------

    public function test_provider_payloads_include_ga4_meta_and_posthog(): void
    {
        $this->registry->register(
            EventBlueprint::make('multi.test', 'Multi Provider')
                ->param('event_name', 'string', true)
                ->param('value', 'numeric', true)
        );

        $payloads = $this->builder->buildProviderPayloads('multi.test', [
            'event_name' => 'purchase',
            'value' => 99.99,
        ]);

        $this->assertArrayHasKey('ga4', $payloads);
        $this->assertArrayHasKey('meta', $payloads);
        $this->assertArrayHasKey('posthog', $payloads);

        $this->assertSame('purchase', $payloads['ga4']['event_name']);
        $this->assertSame(99.99, $payloads['ga4']['params']['value']);
    }

    // ------------------------------------------------------------------
    // Dry Run Validation
    // ------------------------------------------------------------------

    public function test_dry_run_validation_passes(): void
    {
        $this->registry->register(
            EventBlueprint::make('valid.test', 'Valid')
                ->param('name', 'string', true)
        );

        $report = $this->builder->dryRunValidate('valid.test', ['name' => 'test']);

        $this->assertTrue($report['valid']);
        $this->assertEmpty($report['errors']);
    }

    public function test_dry_run_validation_fails(): void
    {
        $this->registry->register(
            EventBlueprint::make('invalid.test', 'Invalid')
                ->param('required_field', 'string', true)
                ->param('optional_field', 'string', false)
        );

        $report = $this->builder->dryRunValidate('invalid.test', []);

        $this->assertFalse($report['valid']);
        $this->assertNotEmpty($report['errors']);
        $this->assertStringContainsString('required_field', $report['errors'][0]);
    }

    // ------------------------------------------------------------------
    // Fluent Chaining
    // ------------------------------------------------------------------

    public function test_fluent_chaining_returns_builder(): void
    {
        $result = $this->builder
            ->withUserId('u1')
            ->withSessionId('s1')
            ->withPiiRedaction()
            ->withProviderFormat('meta');

        $this->assertInstanceOf(EventBlueprintBuilderService::class, $result);
    }

    // ------------------------------------------------------------------
    // Unknown Blueprint
    // ------------------------------------------------------------------

    public function test_build_unknown_blueprint_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not registered');

        $this->builder->build('completely.unknown', []);
    }

    // ------------------------------------------------------------------
    // Blueprint toArray
    // ------------------------------------------------------------------

    public function test_blueprint_to_array(): void
    {
        $bp = EventBlueprint::make('arr.test', 'Array Test')
            ->description('Test serialization')
            ->category('test')
            ->param('x', 'int', true);

        $arr = $bp->toArray();

        $this->assertSame('arr.test', $arr['name']);
        $this->assertSame('Array Test', $arr['label']);
        $this->assertSame('Test serialization', $arr['description']);
        $this->assertSame('test', $arr['category']);
        $this->assertCount(1, $arr['params']);
        $this->assertSame('x', $arr['params'][0]['name']);
        $this->assertSame('int', $arr['params'][0]['type']);
        $this->assertTrue($arr['params'][0]['required']);
    }

    public function test_blueprint_version_and_metadata(): void
    {
        $bp = EventBlueprint::make('ver.test', 'Versioned')
            ->version('2.0.0')
            ->metadata(['team' => 'growth', 'experiment' => true]);

        $arr = $bp->toArray();

        $this->assertSame('2.0.0', $arr['version']);
        $this->assertSame('growth', $arr['metadata']['team']);
        $this->assertTrue($arr['metadata']['experiment']);
    }
}
