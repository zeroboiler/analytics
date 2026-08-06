<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\EventAlertRulesService;

beforeEach(function (): void {
    $this->manager = Mockery::mock(AnalyticsManager::class);
    $this->metrics = Mockery::mock(AnalyticsMetrics::class);
    $this->queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = Mockery::mock(ConfigRepository::class);

    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.alerts', [])
        ->andReturn([
            'enabled' => true,
            'cooldown' => 0, // No cooldown for testing
            'max_history' => 200,
            'rules' => [
                'high_error_rate' => [
                    'type' => 'error_rate',
                    'condition' => 'gt',
                    'threshold' => 5.0,
                    'severity' => 'elevated',
                    'message' => 'Error rate exceeds 5%',
                    'dispatch' => false,
                ],
                'low_purchase_count' => [
                    'type' => 'count',
                    'event' => 'purchase',
                    'condition' => 'lt',
                    'threshold' => 10,
                    'severity' => 'warning',
                    'message' => 'Purchase count below 10',
                    'dispatch' => false,
                ],
            ],
        ]);

    $this->cache->shouldReceive('get')
        ->with('zeroboiler.analytics.alert_cooldowns', [])
        ->andReturn([]);
});

afterEach(function (): void {
    Mockery::close();
});

test('alert rules service can be instantiated with config', function (): void {
    $service = new EventAlertRulesService(
        $this->manager,
        $this->metrics,
        $this->queue,
        $this->cache,
        $this->config,
    );

    expect($service)->toBeInstanceOf(EventAlertRulesService::class);
});

test('evaluate returns empty array when no rules are triggered', function (): void {
    $this->metrics->shouldReceive('getCounts')
        ->andReturn(['page_view' => 100, 'purchase' => 50]);

    $service = new EventAlertRulesService(
        $this->manager,
        $this->metrics,
        $this->queue,
        $this->cache,
        $this->config,
    );

    $alerts = $service->evaluate();

    expect($alerts)->toBe([]);
});

test('evaluate triggers error_rate rule when errors exceed threshold', function (): void {
    $this->metrics->shouldReceive('getCounts')
        ->andReturn([
            'error' => 100,
            'js_error' => 0,
            'page_view' => 500,
            'purchase' => 50,
        ]);

    $service = new EventAlertRulesService(
        $this->manager,
        $this->metrics,
        $this->queue,
        $this->cache,
        $this->config,
    );

    $alerts = $service->evaluate();

    expect($alerts)->toHaveCount(1);
    expect($alerts[0]['rule'])->toBe('high_error_rate');
    expect($alerts[0]['severity'])->toBe('elevated');
    expect($alerts[0]['event'])->toBe('*');
});

test('evaluate triggers count rule when condition met', function (): void {
    $this->metrics->shouldReceive('getCounts')
        ->andReturn([
            'page_view' => 100,
            'purchase' => 3,
        ]);

    $service = new EventAlertRulesService(
        $this->manager,
        $this->metrics,
        $this->queue,
        $this->cache,
        $this->config,
    );

    $alerts = $service->evaluate();

    $purchaseAlert = null;
    foreach ($alerts as $alert) {
        if ($alert['rule'] === 'low_purchase_count') {
            $purchaseAlert = $alert;
        }
    }

    expect($purchaseAlert)->not->toBeNull();
    expect($purchaseAlert['event'])->toBe('purchase');
    expect($purchaseAlert['severity'])->toBe('warning');
});

test('evaluate respects disabled rules', function (): void {
    $this->metrics->shouldReceive('getCounts')
        ->andReturn([
            'error' => 100,
            'page_view' => 100,
            'purchase' => 3,
        ]);

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.alerts', [])
        ->andReturn([
            'enabled' => true,
            'cooldown' => 0,
            'max_history' => 200,
            'rules' => [
                'disabled_rule' => [
                    'type' => 'count',
                    'condition' => 'gt',
                    'threshold' => 0,
                    'enabled' => false,
                    'dispatch' => false,
                ],
            ],
        ]);

    $service = new EventAlertRulesService(
        $this->manager,
        $this->metrics,
        $this->queue,
        $this->cache,
        $config,
    );

    $alerts = $service->evaluate();

    expect($alerts)->toBe([]);
});

test('evaluate returns empty when system is disabled', function (): void {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.alerts', [])
        ->andReturn([
            'enabled' => false,
            'cooldown' => 0,
            'rules' => [],
        ]);

    $service = new EventAlertRulesService(
        $this->manager,
        $this->metrics,
        $this->queue,
        $this->cache,
        $config,
    );

    $alerts = $service->evaluate();

    expect($alerts)->toBe([]);
});

test('evaluateRuleByName returns null for non-existent rule', function (): void {
    $service = new EventAlertRulesService(
        $this->manager,
        $this->metrics,
        $this->queue,
        $this->cache,
        $this->config,
    );

    $result = $service->evaluateRuleByName('non_existent_rule');

    expect($result)->toBeNull();
});

test('addRule and removeRule work at runtime', function (): void {
    $service = new EventAlertRulesService(
        $this->manager,
        $this->metrics,
        $this->queue,
        $this->cache,
        $this->config,
    );

    $service->addRule('custom_rule', [
        'type' => 'count',
        'condition' => 'gt',
        'threshold' => 100,
        'dispatch' => false,
    ]);

    expect($service->ruleNames())->toContain('custom_rule');
    expect($service->getRule('custom_rule'))->not->toBeNull();

    $service->removeRule('custom_rule');

    expect($service->ruleNames())->not->toContain('custom_rule');
    expect($service->getRule('custom_rule'))->toBeNull();
});

test('summary returns correct structure', function (): void {
    $service = new EventAlertRulesService(
        $this->manager,
        $this->metrics,
        $this->queue,
        $this->cache,
        $this->config,
    );

    $summary = $service->summary();

    expect($summary)->toHaveKey('enabled');
    expect($summary)->toHaveKey('rules_count');
    expect($summary)->toHaveKey('active_rules');
    expect($summary)->toHaveKey('total_alerts');
    expect($summary)->toHaveKey('cooldown_seconds');
    expect($summary)->toHaveKey('rule_names');
    expect($summary['enabled'])->toBeTrue();
    expect($summary['rules_count'])->toBe(2);
});

test('alert history is tracked', function (): void {
    $this->metrics->shouldReceive('getCounts')
        ->andReturn([
            'error' => 100,
            'page_view' => 500,
            'purchase' => 3,
        ]);

    $service = new EventAlertRulesService(
        $this->manager,
        $this->metrics,
        $this->queue,
        $this->cache,
        $this->config,
    );

    $service->evaluate();

    $history = $service->getAlertHistory();

    expect($history)->toHaveCount(2);
});

test('flush clears history and cooldowns', function (): void {
    $this->metrics->shouldReceive('getCounts')
        ->andReturn([
            'error' => 100,
            'page_view' => 500,
            'purchase' => 3,
        ]);

    $this->cache->shouldReceive('put')
        ->with('zeroboiler.analytics.alert_cooldowns', Mockery::any(), Mockery::any())
        ->andReturn(true);

    $service = new EventAlertRulesService(
        $this->manager,
        $this->metrics,
        $this->queue,
        $this->cache,
        $this->config,
    );

    $service->evaluate();
    expect($service->getAlertHistory())->toHaveCount(2);

    $service->flush();
    expect($service->getAlertHistory())->toHaveCount(0);
});

test('setEnabled toggles the system', function (): void {
    $service = new EventAlertRulesService(
        $this->manager,
        $this->metrics,
        $this->queue,
        $this->cache,
        $this->config,
    );

    $summary1 = $service->summary();
    expect($summary1['enabled'])->toBeTrue();

    $service->setEnabled(false);

    $summary2 = $service->summary();
    expect($summary2['enabled'])->toBeFalse();
});

test('gte condition triggers correctly', function (): void {
    $this->metrics->shouldReceive('getCounts')
        ->andReturn(['page_view' => 100, 'purchase' => 10]);

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.alerts', [])
        ->andReturn([
            'enabled' => true,
            'cooldown' => 0,
            'max_history' => 200,
            'rules' => [
                'gte_test' => [
                    'type' => 'count',
                    'event' => 'purchase',
                    'condition' => 'gte',
                    'threshold' => 10,
                    'severity' => 'info',
                    'dispatch' => false,
                ],
            ],
        ]);

    $service = new EventAlertRulesService(
        $this->manager,
        $this->metrics,
        $this->queue,
        $this->cache,
        $config,
    );

    $alerts = $service->evaluate();

    expect($alerts)->toHaveCount(1);
    expect($alerts[0]['rule'])->toBe('gte_test');
});
