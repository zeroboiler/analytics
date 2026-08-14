<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventTemplateEngine;
use ZeroBoiler\Analytics\Services\EventBlueprintBuilder;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Tests for EventTemplateEngine and EventBlueprintBuilder (v140.0.0).
 *
 * @since 140.0.0
 */
final class V140EventTemplateEngineTest extends TestCase
{
    private ConfigRepository $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->createMock(ConfigRepository::class);
        $this->config->method('get')
            ->willReturnMap([
                ['zeroboiler.analytics.event_templates.definitions', [], []],
                ['zeroboiler.analytics.event_templates.default_currency', 'USD', 'USD'],
                ['zeroboiler.analytics.event_templates.auto_utm_attach', true, true],
                ['zeroboiler.analytics.event_templates.auto_user_id_attach', true, true],
                ['zeroboiler.analytics.event_templates.include_provider_params', true, true],
            ]);
    }

    // ─── EventTemplateEngine Tests ─────────────────────────────────────

    public function test_engine_loads_built_in_templates(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $this->assertGreaterThan(10, $engine->count());
        $this->assertTrue($engine->hasTemplate('ecommerce.purchase'));
        $this->assertTrue($engine->hasTemplate('saas.sign_up'));
        $this->assertTrue($engine->hasTemplate('engagement.error'));
    }

    public function test_engine_template_keys_returns_list(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $keys = $engine->templateKeys();

        $this->assertIsArray($keys);
        $this->assertContains('ecommerce.purchase', $keys);
        $this->assertContains('saas.subscription', $keys);
        $this->assertContains('engagement.page_view', $keys);
    }

    public function test_engine_get_template_returns_definition(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $template = $engine->getTemplate('ecommerce.purchase');

        $this->assertNotNull($template);
        $this->assertSame('purchase', $template['name']);
        $this->assertSame('ecommerce', $template['category']);
        $this->assertArrayHasKey('transaction_id', $template['params']);
        $this->assertTrue($template['params']['transaction_id']['required']);
        $this->assertSame('string', $template['params']['transaction_id']['type']);
    }

    public function test_engine_get_template_returns_null_for_unknown(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $this->assertNull($engine->getTemplate('nonexistent.template'));
    }

    public function test_engine_build_ecommerce_purchase(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $event = $engine->build('ecommerce.purchase', [
            'transaction_id' => 'TXN-123',
            'value' => 99.99,
            'items' => [['item_id' => 'SKU-001', 'quantity' => 2]],
        ], 'client-abc', 'user-1');

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('purchase', $event->name);
        $this->assertSame('TXN-123', $event->params['transaction_id']);
        $this->assertSame(99.99, $event->params['value']);
        $this->assertSame('USD', $event->params['currency']);
        $this->assertSame(0.0, $event->params['tax']);
        $this->assertSame('client-abc', $event->clientId);
        $this->assertSame('user-1', $event->userId);
    }

    public function test_engine_build_applies_defaults(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $event = $engine->build('saas.sign_up');

        $this->assertSame('sign_up', $event->name);
        $this->assertSame('email', $event->params['method']);
    }

    public function test_engine_build_throws_for_missing_required(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Required parameter');

        $engine->build('ecommerce.purchase', []);
    }

    public function test_engine_build_throws_for_unknown_template(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $engine->build('nonexistent.template');
    }

    public function test_engine_build_validates_enum(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not one of');

        $engine->build('engagement.error', [
            'message' => 'test',
            'severity' => 'invalid_severity',
        ]);
    }

    public function test_engine_build_coerces_types(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $event = $engine->build('engagement.search', [
            'query' => 'analytics tools',
            'results_count' => '42',
        ]);

        $this->assertSame(42, $event->params['results_count']);
    }

    public function test_engine_register_custom_template(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $engine->registerTemplate('custom.my_event', [
            'name' => 'my_event',
            'category' => 'custom',
            'params' => [
                'action' => ['type' => 'string', 'required' => true, 'description' => 'Action performed'],
            ],
        ]);

        $this->assertTrue($engine->hasTemplate('custom.my_event'));
        $this->assertCount(1, $engine->registeredTemplates());

        $event = $engine->build('custom.my_event', ['action' => 'click']);
        $this->assertSame('my_event', $event->name);
    }

    public function test_engine_by_category_groups_templates(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $categories = $engine->byCategory();

        $this->assertArrayHasKey('ecommerce', $categories);
        $this->assertArrayHasKey('saas', $categories);
        $this->assertArrayHasKey('engagement', $categories);
        $this->assertIsArray($categories['ecommerce']);
        $this->assertGreaterThan(0, count($categories['ecommerce']));
    }

    public function test_engine_validate_event_name(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $result = $engine->validateEventName('purchase');

        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('catalog_match', $result);
        $this->assertArrayHasKey('category', $result);
        $this->assertArrayHasKey('template_match', $result);
        $this->assertNotNull($result['template_match']);
    }

    public function test_engine_summary(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $summary = $engine->summary();

        $this->assertArrayHasKey('total_templates', $summary);
        $this->assertArrayHasKey('categories', $summary);
        $this->assertArrayHasKey('built_in_count', $summary);
        $this->assertArrayHasKey('custom_count', $summary);
        $this->assertArrayHasKey('registered_count', $summary);
        $this->assertArrayHasKey('catalog_coverage', $summary);
        $this->assertGreaterThan(0, $summary['total_templates']);
        $this->assertGreaterThan(0, $summary['catalog_coverage']);
    }

    public function test_engine_get_param_schema(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $schema = $engine->getParamSchema('ecommerce.purchase');

        $this->assertArrayHasKey('transaction_id', $schema);
        $this->assertArrayHasKey('value', $schema);
        $this->assertArrayHasKey('currency', $schema);
        $this->assertSame('string', $schema['transaction_id']['type']);
        $this->assertTrue($schema['transaction_id']['required']);
    }

    public function test_engine_get_param_schema_unknown_returns_empty(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $this->assertSame([], $engine->getParamSchema('nonexistent'));
    }

    public function test_engine_build_saas_trial_start(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $event = $engine->build('saas.trial_start', ['plan' => 'pro']);

        $this->assertSame('start_trial', $event->name);
        $this->assertSame('pro', $event->params['plan']);
        $this->assertSame(14, $event->params['trial_days']);
    }

    public function test_engine_build_saas_cancellation_with_reason(): void
    {
        $engine = new EventTemplateEngine($this->config);

        $event = $engine->build('saas.cancellation', [
            'plan' => 'enterprise',
            'reason' => 'too_expensive',
            'feedback' => 'Need more features for the price',
        ]);

        $this->assertSame('cancellation', $event->name);
        $this->assertSame('enterprise', $event->params['plan']);
        $this->assertSame('too_expensive', $event->params['reason']);
        $this->assertSame('Need more features for the price', $event->params['feedback']);
        $this->assertTrue($event->params['is_churn']);
    }

    // ─── EventBlueprintBuilder Tests ──────────────────────────────────

    public function test_builder_basic_build(): void
    {
        $builder = EventBlueprintBuilder::makeWithConfig('button_click', $this->config);

        $event = $builder->param('element', 'buy_now')
            ->param('page', '/pricing')
            ->build();

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('button_click', $event->name);
        $this->assertSame('buy_now', $event->params['element']);
        $this->assertSame('/pricing', $event->params['page']);
    }

    public function test_builder_with_params_batch(): void
    {
        $builder = EventBlueprintBuilder::makeWithConfig('form_submit', $this->config);

        $event = $builder->params([
            'form_name' => 'contact_form',
            'form_type' => 'lead',
            'success' => true,
        ])->build();

        $this->assertSame('form_submit', $event->name);
        $this->assertSame('contact_form', $event->params['form_name']);
        $this->assertTrue($event->params['success']);
    }

    public function test_builder_with_identity(): void
    {
        $builder = EventBlueprintBuilder::makeWithConfig('api_call', $this->config);

        $event = $builder->clientId('client-xyz')
            ->userId('user-456')
            ->build();

        $this->assertSame('client-xyz', $event->clientId);
        $this->assertSame('user-456', $event->userId);
    }

    public function test_builder_priority_shortcuts(): void
    {
        $critical = EventBlueprintBuilder::makeWithConfig('payment', $this->config)
            ->critical()
            ->build();

        $this->assertSame('critical', $critical->priority);

        $normal = EventBlueprintBuilder::makeWithConfig('log_view', $this->config)
            ->priority('low')
            ->build();

        $this->assertSame('low', $normal->priority);
    }

    public function test_builder_source_shortcuts(): void
    {
        $server = EventBlueprintBuilder::makeWithConfig('auth_event', $this->config)
            ->fromServer()
            ->build();

        $this->assertSame('server', $server->source);

        $api = EventBlueprintBuilder::makeWithConfig('webhook', $this->config)
            ->fromApi()
            ->build();

        $this->assertSame('api', $api->source);
    }

    public function test_builder_to_array(): void
    {
        $builder = EventBlueprintBuilder::makeWithConfig('test_event', $this->config);

        $array = $builder->param('key', 'value')->toArray();

        $this->assertIsArray($array);
        $this->assertSame('test_event', $array['name']);
        $this->assertSame('value', $array['params']['key']);
    }

    public function test_builder_param_count_and_helpers(): void
    {
        $builder = EventBlueprintBuilder::makeWithConfig('multi_param', $this->config);

        $builder->param('a', 1)->param('b', 2)->param('c', 3);

        $this->assertSame(3, $builder->paramCount());
        $this->assertSame('multi_param', $builder->getName());
        $this->assertTrue($builder->hasParam('a'));
        $this->assertFalse($builder->hasParam('z'));
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $builder->getParams());
    }

    public function test_builder_with_all_enrichment_flag(): void
    {
        $builder = EventBlueprintBuilder::makeWithConfig('enriched_event', $this->config);

        // In CLI context, enrichment silently skips (no request available)
        $event = $builder->withAllEnrichment()->build();

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('enriched_event', $event->name);
    }

    public function test_builder_fluent_chaining(): void
    {
        $event = EventBlueprintBuilder::makeWithConfig('chain_test', $this->config)
            ->param('step1', true)
            ->param('step2', 'value')
            ->clientId('c1')
            ->userId('u1')
            ->priority('normal')
            ->source('server')
            ->critical()
            ->build();

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('chain_test', $event->name);
        $this->assertSame('c1', $event->clientId);
        $this->assertSame('u1', $event->userId);
        $this->assertSame('server', $event->source);
    }

    // ─── Integration: Template + Builder ───────────────────────────────

    public function test_template_and_builder_create_compatible_events(): void
    {
        $engine = new EventTemplateEngine($this->config);
        $builder = EventBlueprintBuilder::makeWithConfig('purchase', $this->config);

        $templateEvent = $engine->build('ecommerce.purchase', [
            'transaction_id' => 'TX-1',
            'value' => 50.0,
        ]);

        $builderEvent = $builder
            ->param('transaction_id', 'TX-1')
            ->param('value', 50.0)
            ->param('currency', 'USD')
            ->build();

        $this->assertSame($templateEvent->name, $builderEvent->name);
        $this->assertSame($templateEvent->params['transaction_id'], $builderEvent->params['transaction_id']);
    }

    // ─── Version & Quality Assertions ─────────────────────────────────

    public function test_version_is_140(): void
    {
        $this->assertSame('140.0.0', AnalyticsEvent::VERSION);
    }

    public function test_catalog_has_expected_categories(): void
    {
        $categories = EventCatalog::byCategory();

        $this->assertArrayHasKey('ecommerce', $categories);
        $this->assertArrayHasKey('saas', $categories);
        $this->assertArrayHasKey('engagement', $categories);
        $this->assertGreaterThan(0, count($categories));
    }

    public function test_event_template_has_strict_types(): void
    {
        $reflection = new \ReflectionClass(EventTemplateEngine::class);

        $file = $reflection->getFileName();
        $contents = (string) file_get_contents($file);

        $this->assertStringContainsString('declare(strict_types=1)', $contents);
        $this->assertStringContainsString('@since 140.0.0', $contents);
    }

    public function test_blueprint_builder_has_strict_types(): void
    {
        $reflection = new \ReflectionClass(EventBlueprintBuilder::class);

        $file = $reflection->getFileName();
        $contents = (string) file_get_contents($file);

        $this->assertStringContainsString('declare(strict_types=1)', $contents);
        $this->assertStringContainsString('@since 140.0.0', $contents);
    }

    public function test_template_engine_has_docblocks(): void
    {
        $reflection = new \ReflectionClass(EventTemplateEngine::class);

        $this->assertNotEmpty($reflection->getDocComment());
        $this->assertStringContainsString('Event Template Engine', $reflection->getDocComment());

        // Check constructor has @param docblock
        $constructor = $reflection->getMethod('__construct');
        $this->assertNotEmpty($constructor->getDocComment());

        // Check build method has @return and @throws
        $buildMethod = $reflection->getMethod('build');
        $this->assertNotEmpty($buildMethod->getDocComment());
        $buildDoc = $buildMethod->getDocComment();
        $this->assertStringContainsString('@return', $buildDoc);
        $this->assertStringContainsString('@throws', $buildDoc);
    }

    public function test_blueprint_builder_has_docblocks(): void
    {
        $reflection = new \ReflectionClass(EventBlueprintBuilder::class);

        $this->assertNotEmpty($reflection->getDocComment());
        $this->assertStringContainsString('Blueprint Builder', $reflection->getDocComment());
    }

    public function test_template_engine_is_final(): void
    {
        $reflection = new \ReflectionClass(EventTemplateEngine::class);

        $this->assertTrue($reflection->isFinal());
    }

    public function test_blueprint_builder_is_final(): void
    {
        $reflection = new \ReflectionClass(EventBlueprintBuilder::class);

        $this->assertTrue($reflection->isFinal());
    }
}
