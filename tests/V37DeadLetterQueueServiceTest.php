<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\DeadLetterQueueService;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

test('dlq service creates with default config', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.dead_letter_queue', [])->andReturn([]);
    $config->shouldReceive('get')->andReturnNull();

    $service = new DeadLetterQueueService($config);

    expect($service)->toBeInstanceOf(DeadLetterQueueService::class);
    expect($service->isEnabled())->toBeTrue();
    expect($service->count())->toBe(0);
    expect($service->totalSize())->toBe(0);
});

test('dlq summary returns correct structure', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.dead_letter_queue', [])->andReturn([
        'enabled' => true,
        'strategy' => 'null',
        'max_size' => 500,
        'buffer_size' => 10,
    ]);
    $config->shouldReceive('get')->andReturnNull();

    $service = new DeadLetterQueueService($config);
    $summary = $service->summary();

    expect($summary)
        ->toHaveKey('enabled')
        ->toHaveKey('strategy')
        ->toHaveKey('total')
        ->toHaveKey('buffered')
        ->toHaveKey('max_size')
        ->toHaveKey('utilization');

    expect($summary['enabled'])->toBeTrue();
    expect($summary['max_size'])->toBe(500);
    expect($summary['utilization'])->toBe(0.0);
});

test('dlq enqueue with null strategy drops events silently', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.dead_letter_queue', [])->andReturn([
        'enabled' => true,
        'strategy' => 'null',
    ]);
    $config->shouldReceive('get')->andReturnNull();

    $service = new DeadLetterQueueService($config);

    $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);
    $error = new \RuntimeException('Provider timeout');

    // Should not throw — null strategy silently discards
    $service->enqueue($event, $error, 3);
    $service->flush();

    expect($service->totalSize())->toBe(0);
});

test('dlq disabled does not enqueue', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.dead_letter_queue', [])->andReturn([
        'enabled' => false,
    ]);
    $config->shouldReceive('get')->andReturnNull();

    $service = new DeadLetterQueueService($config);

    $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);
    $error = new \RuntimeException('Provider timeout');

    $service->enqueue($event, $error, 3);

    expect($service->totalSize())->toBe(0);
});

test('dlq clear resets all data', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.dead_letter_queue', [])->andReturn([
        'enabled' => true,
        'strategy' => 'null',
    ]);
    $config->shouldReceive('get')->andReturnNull();

    $service = new DeadLetterQueueService($config);

    $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);
    $error = new \RuntimeException('Test error');

    // These go to buffer but null strategy discards on flush
    $service->enqueue($event, $error, 3);
    $service->clear();

    expect($service->count())->toBe(0);
    expect($service->totalSize())->toBe(0);
});

test('dlq get by event name returns empty for no events', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.dead_letter_queue', [])->andReturn([
        'enabled' => true,
        'strategy' => 'null',
    ]);
    $config->shouldReceive('get')->andReturnNull();

    $service = new DeadLetterQueueService($config);

    $results = $service->getByEventName('purchase');

    expect($results)->toBe([]);
});

test('dlq all returns empty array initially', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.dead_letter_queue', [])->andReturn([
        'enabled' => true,
        'strategy' => 'null',
    ]);
    $config->shouldReceive('get')->andReturnNull();

    $service = new DeadLetterQueueService($config);

    expect($service->all())->toBe([]);
});

