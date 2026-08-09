<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Feature\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\EventOrchestrationService;
use Mockery;
use Mockery\MockInterface;

/**
 * @covers \ZeroBoiler\Analytics\Services\EventOrchestrationService
 */
final class EventOrchestrationServiceTest extends \PHPUnit\Framework\TestCase
{
    private AnalyticsManager&MockInterface $manager;

    private ConfigRepository&MockInterface $config;

    private EventOrchestrationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = Mockery::mock(AnalyticsManager::class);
        $this->config = Mockery::mock(ConfigRepository::class);

        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.orchestration', Mockery::any())
            ->andReturn(['pipelines' => []]);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.orchestration.cache_ttl', Mockery::any())
            ->andReturn(86400);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.orchestration.scan_limit', Mockery::any())
            ->andReturn(100);

        $this->service = new EventOrchestrationService($this->manager, $this->config);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_registers_built_in_pipelines(): void
    {
        $definitions = $this->service->getPipelineDefinitions();

        $this->assertArrayHasKey('user_acquisition', $definitions);
        $this->assertArrayHasKey('trial_conversion', $definitions);
        $this->assertArrayHasKey('ecommerce_checkout', $definitions);
        $this->assertArrayHasKey('activation', $definitions);
        $this->assertArrayHasKey('retention', $definitions);
        $this->assertCount(5, $definitions);
    }

    public function test_pipeline_exists(): void
    {
        $this->assertTrue($this->service->pipelineExists('user_acquisition'));
        $this->assertFalse($this->service->pipelineExists('nonexistent'));
    }

    public function test_get_pipeline_definition(): void
    {
        $def = $this->service->getPipelineDefinition('user_acquisition');

        $this->assertNotNull($def);
        $this->assertSame('user_acquisition', $def['name']);
        $this->assertNotEmpty($def['steps']);
        $this->assertSame('acquisition_completed', $def['on_complete_event']);
        $this->assertSame('acquisition_timeout', $def['on_timeout_event']);
        $this->assertSame('acquisition_failed', $def['on_failure_event']);
    }

    public function test_get_nonexistent_pipeline_definition_returns_null(): void
    {
        $this->assertNull($this->service->getPipelineDefinition('nonexistent'));
    }

    public function test_start_pipeline_throws_for_nonexistent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'nonexistent' is not defined");

        $this->service->startPipeline('nonexistent', 'client-1');
    }

    public function test_user_acquisition_pipeline_has_correct_steps(): void
    {
        $def = $this->service->getPipelineDefinition('user_acquisition');

        $stepNames = array_column($def['steps'], 'name');
        $this->assertSame(
            ['landing_viewed', 'signup_completed', 'email_verified', 'trial_started', 'subscription_created'],
            $stepNames,
        );
    }

    public function test_user_acquisition_pipeline_required_steps(): void
    {
        $def = $this->service->getPipelineDefinition('user_acquisition');

        $requiredSteps = array_filter($def['steps'], fn (array $s): bool => $s['required']);
        $requiredNames = array_column($requiredSteps, 'name');

        $this->assertContains('landing_viewed', $requiredNames);
        $this->assertContains('signup_completed', $requiredNames);
        $this->assertContains('subscription_created', $requiredNames);
        $this->assertNotContains('email_verified', $requiredNames);
        $this->assertNotContains('trial_started', $requiredNames);
    }

    public function test_ecommerce_checkout_pipeline_events(): void
    {
        $def = $this->service->getPipelineDefinition('ecommerce_checkout');

        $events = array_column($def['steps'], 'event');
        $this->assertContains('view_item', $events);
        $this->assertContains('add_to_cart', $events);
        $this->assertContains('begin_checkout', $events);
        $this->assertContains('purchase', $events);
        $this->assertContains('add_payment_info', $events);
        $this->assertContains('view_cart', $events);
    }

    public function test_activation_pipeline_has_five_steps(): void
    {
        $def = $this->service->getPipelineDefinition('activation');

        $this->assertCount(5, $def['steps']);
        $this->assertSame('user_activated', $def['on_complete_event']);
    }

    public function test_retention_pipeline_has_correct_flow(): void
    {
        $def = $this->service->getPipelineDefinition('retention');

        $events = array_column($def['steps'], 'event');
        $this->assertSame('subscribe', $events[0]);
        $this->assertSame('churn_predicted', $def['on_failure_event']);
    }

    public function test_summary_returns_definition_counts(): void
    {
        $summary = $this->service->summary();

        $this->assertSame(5, $summary['pipelines']);
        $this->assertArrayHasKey('definitions', $summary);
        $this->assertSame(5, $summary['definitions']['user_acquisition']);
        $this->assertSame(6, $summary['definitions']['ecommerce_checkout']);
    }

    public function test_define_runtime_pipeline(): void
    {
        $this->service->definePipeline(
            'custom_flow',
            [
                ['name' => 'step_a', 'event' => 'event_a', 'required' => true, 'timeout_seconds' => 3600],
                ['name' => 'step_b', 'event' => 'event_b', 'required' => false, 'timeout_seconds' => 86400],
            ],
            'flow_complete',
            'flow_timeout',
        );

        $this->assertTrue($this->service->pipelineExists('custom_flow'));

        $def = $this->service->getPipelineDefinition('custom_flow');
        $this->assertSame(2, count($def['steps']));
        $this->assertSame('flow_complete', $def['on_complete_event']);
        $this->assertSame('flow_timeout', $def['on_timeout_event']);
    }

    public function test_remove_runtime_pipeline(): void
    {
        $this->service->definePipeline(
            'removable_flow',
            [
                ['name' => 'step_1', 'event' => 'event_1'],
            ],
        );

        $this->assertTrue($this->service->pipelineExists('removable_flow'));

        $result = $this->service->removePipeline('removable_flow');
        $this->assertTrue($result);
        $this->assertFalse($this->service->pipelineExists('removable_flow'));
    }

    public function test_cannot_remove_built_in_pipeline(): void
    {
        $result = $this->service->removePipeline('user_acquisition');
        $this->assertFalse($result);
        $this->assertTrue($this->service->pipelineExists('user_acquisition'));
    }

    public function test_pipeline_steps_have_expected_structure(): void
    {
        $def = $this->service->getPipelineDefinition('user_acquisition');
        $firstStep = $def['steps'][0];

        $this->assertArrayHasKey('name', $firstStep);
        $this->assertArrayHasKey('event', $firstStep);
        $this->assertArrayHasKey('required', $firstStep);
        $this->assertArrayHasKey('timeout_seconds', $firstStep);
        $this->assertIsString($firstStep['name']);
        $this->assertIsString($firstStep['event']);
        $this->assertIsBool($firstStep['required']);
        $this->assertIsInt($firstStep['timeout_seconds']);
    }

    public function test_trial_conversion_pipeline_structure(): void
    {
        $def = $this->service->getPipelineDefinition('trial_conversion');

        $this->assertSame('trial_conversion_completed', $def['on_complete_event']);
        $this->assertSame('trial_conversion_timeout', $def['on_timeout_event']);
        $this->assertNull($def['on_failure_event']);

        $stepNames = array_column($def['steps'], 'name');
        $this->assertSame('trial_started', $stepNames[0]);
        $this->assertSame('trial_converted', $stepNames[3]);
    }

    public function test_pipeline_metadata(): void
    {
        $def = $this->service->getPipelineDefinition('user_acquisition');

        $this->assertArrayHasKey('metadata', $def);
        $this->assertSame('saas', $def['metadata']['category']);
        $this->assertArrayHasKey('description', $def['metadata']);
    }
}
