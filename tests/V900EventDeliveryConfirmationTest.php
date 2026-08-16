<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventDeliveryConfirmationService;

beforeEach(function (): void {
    $this->cache = Cache::getFacadeRoot();
});

test('EventDeliveryConfirmationService instantiates with default config', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn([]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(false);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    expect($service)->toBeInstanceOf(EventDeliveryConfirmationService::class);
});

test('disabled service does not record anything', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn(['enabled' => false]);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    // Should not throw, but should not record
    $service->recordSuccess('ga4', 'test_event', 50);
    $service->recordFailure('ga4', 'test_event', 'error');
    $service->recordReceipt('evt-1', 'ga4', true, 50);

    // Reliability should return perfect score when disabled
    $score = $service->getReliabilityScore();
    expect($score['score'])->toBe(100);
    expect($score['grade'])->toBe('A');
});

test('recordSuccess increments success counter', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn([]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    $service->recordSuccess('ga4', 'page_view', 100);

    $reliability = $service->getReliabilityScore();
    expect($reliability['providers'])->toHaveKey('ga4');
    expect($reliability['providers']['ga4']['success_count'])->toBe(1);
    expect($reliability['providers']['ga4']['failure_count'])->toBe(0);
});

test('recordFailure increments failure counter', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn([]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    $service->recordFailure('ga4', 'page_view', 'Connection timeout', 500);

    $reliability = $service->getReliabilityScore();
    expect($reliability['providers']['ga4']['failure_count'])->toBe(1);
    expect($reliability['providers']['ga4']['success_count'])->toBe(0);
});

test('reliability score reflects delivery rate', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn(['sla_target' => 99.5]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    // Record 9 successes and 1 failure = 90% rate
    for ($i = 0; $i < 9; $i++) {
        $service->recordSuccess('ga4', 'event_'.$i, 50);
    }
    $service->recordFailure('ga4', 'event_fail', 'error');

    $reliability = $service->getReliabilityScore();
    expect($reliability['providers']['ga4']['rate'])->toBe(0.9);
    expect($reliability['score'])->toBeGreaterThanOrEqual(70);
});

test('grade calculation returns correct letter', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn([]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(false);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    // Test with no data — should be A
    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);
    $score = $service->getReliabilityScore();
    expect($score['grade'])->toBe('A');
    expect($score['score'])->toBe(100);
});

test('receipt tracking records per-provider status', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn([]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    $service->recordReceipt('evt-123', 'ga4', true, 42);
    $receipt = $service->checkReceipt('evt-123');

    expect($receipt['delivered'])->toBeTrue();
    expect($receipt['providers'])->toHaveKey('ga4');
    expect($receipt['providers']['ga4']['success'])->toBeTrue();
    expect($receipt['providers']['ga4']['response_time_ms'])->toBe(42);
});

test('receipt shows pending for non-recorded provider', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn([]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    // Check receipt for an event that was never recorded
    $receipt = $service->checkReceipt('evt-unknown');

    expect($receipt['delivered'])->toBeFalse();
    expect($receipt['providers']['ga4']['error'])->toBe('pending');
});

test('response time stats compute percentiles', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn([]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    // Record 10 response times
    for ($i = 1; $i <= 10; $i++) {
        $service->recordSuccess('ga4', 'event_'.$i, $i * 10);
    }

    $stats = $service->getResponseTimeStats('ga4');
    expect($stats['samples'])->toBe(10);
    expect($stats['min'])->toBe(10);
    expect($stats['max'])->toBe(100);
    expect($stats['avg'])->toBe(55);
    expect($stats['p50'])->toBeLessThanOrEqual(60);
    expect($stats['p95'])->toBeGreaterThanOrEqual(90);
});

test('outage detection triggers at threshold', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn(['outage_threshold' => 5]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    // Record 5 consecutive failures (threshold)
    for ($i = 0; $i < 5; $i++) {
        $service->recordFailure('ga4', 'event_'.$i, 'timeout');
    }

    expect($service->isProviderInOutage('ga4'))->toBeTrue();
});

test('success resets consecutive failure counter', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn(['outage_threshold' => 3]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    // 2 failures (below threshold)
    $service->recordFailure('ga4', 'event_1', 'error');
    $service->recordFailure('ga4', 'event_2', 'error');
    expect($service->isProviderInOutage('ga4'))->toBeFalse();

    // Success resets counter
    $service->recordSuccess('ga4', 'event_3', 50);
    expect($service->isProviderInOutage('ga4'))->toBeFalse();
});

test('getRecentDeliveries returns delivery history', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn([]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    $service->recordSuccess('ga4', 'event_1', 50);
    $service->recordFailure('ga4', 'event_2', 'error');

    $recent = $service->getRecentDeliveries('ga4');
    expect($recent)->toHaveCount(2);
    expect($recent[0]['event'])->toBe('event_1');
    expect($recent[0]['success'])->toBeTrue();
    expect($recent[1]['event'])->toBe('event_2');
    expect($recent[1]['success'])->toBeFalse();
});

test('clearStats resets all counters for a provider', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn([]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    $service->recordSuccess('ga4', 'event_1', 50);
    $service->recordFailure('ga4', 'event_2', 'error');

    $service->clearStats('ga4');

    $reliability = $service->getReliabilityScore();
    // With no data, score should be 100
    expect($reliability['providers']['ga4']['success_count'])->toBe(0);
    expect($reliability['providers']['ga4']['failure_count'])->toBe(0);
});

test('getDeliveryDashboard returns comprehensive data', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn(['sla_target' => 99.5]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    $service->recordSuccess('ga4', 'event_1', 50);

    $dashboard = $service->getDeliveryDashboard();

    expect($dashboard)->toHaveKey('reliability');
    expect($dashboard)->toHaveKey('providers');
    expect($dashboard)->toHaveKey('events_tracked');
    expect($dashboard['reliability'])->toHaveKey('score');
    expect($dashboard['reliability'])->toHaveKey('grade');
    expect($dashboard['reliability'])->toHaveKey('sla_met');
    expect($dashboard['reliability'])->toHaveKey('sla_target');
    expect($dashboard['providers'])->toHaveKey('ga4');
    expect($dashboard['providers']['ga4'])->toHaveKey('score');
    expect($dashboard['providers']['ga4'])->toHaveKey('in_outage');
    expect($dashboard['providers']['ga4'])->toHaveKey('response_times');
    expect($dashboard['providers']['ga4']['response_times'])->toHaveKey('p50');
    expect($dashboard['providers']['ga4']['response_times'])->toHaveKey('p95');
    expect($dashboard['providers']['ga4']['response_times'])->toHaveKey('p99');
});

test('response time stats return nulls when empty', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn([]);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    $stats = $service->getResponseTimeStats('ga4');

    expect($stats['p50'])->toBeNull();
    expect($stats['p95'])->toBeNull();
    expect($stats['p99'])->toBeNull();
    expect($stats['avg'])->toBeNull();
    expect($stats['min'])->toBeNull();
    expect($stats['max'])->toBeNull();
    expect($stats['samples'])->toBe(0);
});

test('SLA target tracking works correctly', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn(['sla_target' => 99.0]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    // Record 1 failure — rate drops below 99%
    $service->recordFailure('ga4', 'event_1', 'error');

    $reliability = $service->getReliabilityScore();
    expect($reliability['sla_met'])->toBeFalse();
    expect($reliability['sla_target'])->toBe(99.0);
});

test('getEnabledProviders lists all active providers', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn([]);

    $ga4 = Mockery::mock();
    $ga4->shouldReceive('isEnabled')->andReturn(true);

    $meta = Mockery::mock();
    $meta->shouldReceive('isEnabled')->andReturn(true);

    $gtm = Mockery::mock();
    $gtm->shouldReceive('isEnabled')->andReturn(false);

    $plausible = Mockery::mock();
    $plausible->shouldReceive('isEnabled')->andReturn(false);

    $posthog = Mockery::mock();
    $posthog->shouldReceive('isEnabled')->andReturn(false);

    $manager->shouldReceive('ga4')->andReturn($ga4);
    $manager->shouldReceive('gtm')->andReturn($gtm);
    $manager->shouldReceive('meta')->andReturn($meta);
    $manager->shouldReceive('plausible')->andReturn($plausible);
    $manager->shouldReceive('posthog')->andReturn($posthog);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    $providers = $service->getEnabledProviders();
    expect($providers)->toBe(['ga4', 'meta']);
});

test('consecutive failure penalty reduces score', function (): void {
    $manager = Mockery::mock(AnalyticsManager::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.delivery_confirmation', [])
        ->andReturn([]);

    $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
    $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
    $manager->shouldReceive('meta->isEnabled')->andReturn(false);
    $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    $manager->shouldReceive('posthog->isEnabled')->andReturn(false);

    $service = new EventDeliveryConfirmationService($manager, $this->cache, $config);

    // 5 successes, 5 failures = 50% rate
    for ($i = 0; $i < 5; $i++) {
        $service->recordSuccess('ga4', 'success_'.$i, 50);
    }
    for ($i = 0; $i < 5; $i++) {
        $service->recordFailure('ga4', 'fail_'.$i, 'error');
    }

    $reliability = $service->getReliabilityScore();
    // Rate is 50%, consecutive failures = 5, penalty = min(5*2, 20) = 10
    // Score = 50 - 10 = 40
    expect($reliability['providers']['ga4']['score'])->toBeLessThanOrEqual(40);
});
