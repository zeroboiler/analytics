<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Schema\EventParameterSchemas;
use ZeroBoiler\Analytics\Services\EventSchemaValidationService;
use ZeroBoiler\Analytics\Services\BotDetectionService;
use Illuminate\Support\Facades\Cache;

/**
 * V8.4.0 — Event Schema Validation & Bot Detection Test Suite.
 *
 * Tests the two new v8.4.0 services:
 * 1. EventSchemaValidationService — declarative schema validation with type coercion
 * 2. BotDetectionService — bot detection with UA analysis, client ID rotation,
 *    velocity anomaly, and header scoring
 */
test('EventSchemaValidationService: validates event with correct types passes', function (): void {
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.schema_validation', [
        'enabled' => true,
        'severity' => 'coerce',
        'strip_unknown' => false,
    ]);

    $service = new EventSchemaValidationService($config);

    $event = new AnalyticsEvent('purchase', [
        'transaction_id' => 'txn_123',
        'currency' => 'USD',
        'value' => 29.99,
    ]);

    $result = $service->validate($event);

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
    expect($result['coerced'])->toBeFalse();
});

test('EventSchemaValidationService: detects missing required parameter', function (): void {
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.schema_validation', [
        'enabled' => true,
        'severity' => 'warn',
        'strip_unknown' => false,
    ]);

    $service = new EventSchemaValidationService($config);

    // purchase requires transaction_id
    $event = new AnalyticsEvent('purchase', [
        'currency' => 'USD',
        'value' => 29.99,
    ]);

    $result = $service->validate($event);

    expect($result['errors'])->not->toBeEmpty();
    expect($result['errors'][0])->toContain('transaction_id');
});

test('EventSchemaValidationService: coerces string to float', function (): void {
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.schema_validation', [
        'enabled' => true,
        'severity' => 'coerce',
        'strip_unknown' => false,
    ]);

    $service = new EventSchemaValidationService($config);

    // view_item requires 'value' as float, pass string
    $event = new AnalyticsEvent('view_item', [
        'currency' => 'USD',
        'value' => '49.99',
        'items' => [['item_id' => 'SKU-1']],
    ]);

    $result = $service->validate($event);

    expect($result['valid'])->toBeTrue();
    expect($result['coerced'])->toBeTrue();
    expect($result['event']->params['value'])->toBeFloat();
});

test('EventSchemaValidationService: coerces string integer to int', function (): void {
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.schema_validation', [
        'enabled' => true,
        'severity' => 'coerce',
        'strip_unknown' => false,
    ]);

    $service = new EventSchemaValidationService($config);

    $event = new AnalyticsEvent('checkout_step', [
        'checkout_step' => '3',
    ]);

    $result = $service->validate($event);

    expect($result['valid'])->toBeTrue();
    expect($result['coerced'])->toBeTrue();
    expect($result['event']->params['checkout_step'])->toBeInt();
    expect($result['event']->params['checkout_step'])->toBe(3);
});

test('EventSchemaValidationService: rejects on severity=reject', function (): void {
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.schema_validation', [
        'enabled' => true,
        'severity' => 'reject',
        'strip_unknown' => false,
    ]);

    $service = new EventSchemaValidationService($config);

    // Missing required param, should reject
    $event = new AnalyticsEvent('purchase', [
        'currency' => 'USD',
    ]);

    $result = $service->validate($event);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->not->toBeEmpty();
});

test('EventSchemaValidationService: custom events without schema pass through', function (): void {
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.schema_validation', [
        'enabled' => true,
        'severity' => 'coerce',
        'strip_unknown' => false,
    ]);

    $service = new EventSchemaValidationService($config);

    $event = new AnalyticsEvent('my_custom_event', [
        'foo' => 'bar',
        'baz' => 42,
    ]);

    $result = $service->validate($event);

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
    expect($result['coerced'])->toBeFalse();
});

test('EventSchemaValidationService: batch validation', function (): void {
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.schema_validation', [
        'enabled' => true,
        'severity' => 'coerce',
        'strip_unknown' => false,
    ]);

    $service = new EventSchemaValidationService($config);

    $events = [
        new AnalyticsEvent('purchase', ['transaction_id' => 't1', 'currency' => 'USD', 'value' => 10.0]),
        new AnalyticsEvent('sign_up', ['method' => 'email']),
        new AnalyticsEvent('page_view', ['url' => '/test']),
    ];

    $result = $service->validateBatch($events);

    expect($result['total'])->toBe(3);
    expect($result['valid'])->toBe(3);
    expect($result['rejected'])->toBe(0);
});

test('EventSchemaValidationService: strips unknown params when configured', function (): void {
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.schema_validation', [
        'enabled' => true,
        'severity' => 'coerce',
        'strip_unknown' => true,
    ]);

    $service = new EventSchemaValidationService($config);

    $event = new AnalyticsEvent('login', [
        'method' => 'email',
        'unknown_param' => 'should_be_stripped',
    ]);

    $result = $service->validate($event);

    expect($result['valid'])->toBeTrue();
    expect($result['event']->params)->toHaveKey('method');
    expect($result['event']->params)->not->toHaveKey('unknown_param');
    expect($result['warnings'])->not->toBeEmpty();
});

test('EventSchemaValidationService: stats tracking', function (): void {
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.schema_validation', [
        'enabled' => true,
        'severity' => 'warn',
        'strip_unknown' => false,
    ]);

    $service = new EventSchemaValidationService($config);
    $service->resetStats();

    // Trigger a coercion
    $event = new AnalyticsEvent('view_item', [
        'currency' => 'USD',
        'value' => '10.99',
        'items' => [],
    ]);
    $service->validate($event);

    $stats = $service->getStats();
    expect($stats['coercions'])->toBe(1);
});

test('EventSchemaValidationService: coverage stats', function (): void {
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.schema_validation', [
        'enabled' => true,
        'severity' => 'coerce',
        'strip_unknown' => false,
    ]);

    $service = new EventSchemaValidationService($config);

    $coverage = $service->getCoverageStats();

    expect($coverage['total_schemas'])->toBeGreaterThan(0);
    expect($coverage['catalog_size'])->toBeGreaterThan(0);
    expect($coverage['coverage_percent'])->toBeGreaterThan(0.0);
});

test('EventSchemaValidationService: type coercion for boolean strings', function (): void {
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.schema_validation', [
        'enabled' => true,
        'severity' => 'coerce',
        'strip_unknown' => false,
    ]);

    $service = new EventSchemaValidationService($config);

    $event = new AnalyticsEvent('trial_end', [
        'converted' => 'true',
    ]);

    $result = $service->validate($event);

    expect($result['valid'])->toBeTrue();
    expect($result['coerced'])->toBeTrue();
    expect($result['event']->params['converted'])->toBeBool();
    expect($result['event']->params['converted'])->toBeTrue();
});

test('EventSchemaValidationService: disabled returns clean result', function (): void {
    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.schema_validation', [
        'enabled' => false,
        'severity' => 'coerce',
        'strip_unknown' => false,
    ]);

    $service = new EventSchemaValidationService($config);

    $event = new AnalyticsEvent('purchase', []); // Missing required
    $result = $service->validate($event);

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
    expect($service->isEnabled())->toBeFalse();
});

test('BotDetectionService: clean request returns low score', function (): void {
    Cache::flush();

    $request = new \Illuminate\Http\Request;
    $request->headers->set('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
    $request->headers->set('Accept', 'text/html,application/xhtml+xml');
    $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
    $request->headers->set('Accept-Encoding', 'gzip, deflate, br');
    $request->server->set('REMOTE_ADDR', '192.168.1.1');

    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.bot_detection', [
        'enabled' => true,
        'risk_threshold' => 70,
        'reject_on_bot' => false,
        'max_client_ids_per_ip' => 10,
        'velocity_burst' => 50,
        'velocity_window' => 60,
        'bot_ua_patterns' => ['bot', 'crawl', 'spider', 'curl', 'wget'],
    ]);

    $service = new BotDetectionService(Cache::store('array'), $config);

    $result = $service->analyze($request, 'client_abc');

    expect($result['is_bot'])->toBeFalse();
    expect($result['score'])->toBeLessThan(70);
    expect($result['signals'])->toHaveKeys(['user_agent', 'client_rotation', 'velocity', 'header_score']);
});

test('BotDetectionService: bot user-agent detected', function (): void {
    Cache::flush();

    $request = new \Illuminate\Http\Request;
    $request->headers->set('User-Agent', 'curl/7.88.1');
    $request->server->set('REMOTE_ADDR', '10.0.0.1');

    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.bot_detection', [
        'enabled' => true,
        'risk_threshold' => 70,
        'reject_on_bot' => false,
        'max_client_ids_per_ip' => 10,
        'velocity_burst' => 50,
        'velocity_window' => 60,
        'bot_ua_patterns' => ['bot', 'crawl', 'spider', 'curl', 'wget'],
    ]);

    $service = new BotDetectionService(Cache::store('array'), $config);

    $result = $service->analyze($request, 'client_1');

    // UA score should be high (90 for curl)
    expect($result['signals']['user_agent'])->toBe(90);
    expect($result['details'][0])->toContain('User-Agent');
});

test('BotDetectionService: missing user-agent flagged', function (): void {
    Cache::flush();

    $request = new \Illuminate\Http\Request;
    $request->server->set('REMOTE_ADDR', '10.0.0.1');

    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.bot_detection', [
        'enabled' => true,
        'risk_threshold' => 70,
        'reject_on_bot' => false,
        'max_client_ids_per_ip' => 10,
        'velocity_burst' => 50,
        'velocity_window' => 60,
        'bot_ua_patterns' => ['bot', 'crawl'],
    ]);

    $service = new BotDetectionService(Cache::store('array'), $config);

    $result = $service->analyze($request, 'client_1');

    expect($result['signals']['user_agent'])->toBe(80);
});

test('BotDetectionService: disabled returns clean result', function (): void {
    Cache::flush();

    $request = new \Illuminate\Http\Request;
    $request->headers->set('User-Agent', 'curl/7.88.1');
    $request->server->set('REMOTE_ADDR', '10.0.0.1');

    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.bot_detection', [
        'enabled' => false,
        'risk_threshold' => 70,
        'reject_on_bot' => false,
        'max_client_ids_per_ip' => 10,
        'velocity_burst' => 50,
        'velocity_window' => 60,
        'bot_ua_patterns' => ['curl'],
    ]);

    $service = new BotDetectionService(Cache::store('array'), $config);

    $result = $service->analyze($request, 'client_1');

    expect($result['is_bot'])->toBeFalse();
    expect($result['score'])->toBe(0);
    expect($service->isEnabled())->toBeFalse();
});

test('BotDetectionService: client ID rotation tracking', function (): void {
    Cache::flush();

    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.bot_detection', [
        'enabled' => true,
        'risk_threshold' => 70,
        'reject_on_bot' => false,
        'max_client_ids_per_ip' => 3,
        'velocity_burst' => 50,
        'velocity_window' => 60,
        'bot_ua_patterns' => ['curl'],
    ]);

    $cache = Cache::store('array');
    $service = new BotDetectionService($cache, $config);

    // Simulate multiple client IDs from same IP
    for ($i = 1; $i <= 5; $i++) {
        $request = new \Illuminate\Http\Request;
        $request->headers->set('User-Agent', 'Mozilla/5.0');
        $request->headers->set('Accept', '*/*');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $service->analyze($request, 'client_rotating_' . $i);
    }

    $rotationData = $service->getClientRotationData('192.168.1.1');
    expect($rotationData['count'])->toBeGreaterThanOrEqual(3);
});

test('BotDetectionService: stats tracking', function (): void {
    Cache::flush();

    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.bot_detection', [
        'enabled' => true,
        'risk_threshold' => 70,
        'reject_on_bot' => false,
        'max_client_ids_per_ip' => 10,
        'velocity_burst' => 50,
        'velocity_window' => 60,
        'bot_ua_patterns' => ['curl'],
    ]);

    $service = new BotDetectionService(Cache::store('array'), $config);

    $request = new \Illuminate\Http\Request;
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->headers->set('Accept', '*/*');
    $request->server->set('REMOTE_ADDR', '10.0.0.1');

    $service->analyze($request, 'client_1');

    $stats = $service->getStats();
    expect($stats['total'])->toBe(1);
    expect($stats['avg_score'])->toBeGreaterThanOrEqual(0.0);
});

test('BotDetectionService: risk threshold config works', function (): void {
    Cache::flush();

    $config = app(\Illuminate\Contracts\Config\Repository::class);
    $config->set('zeroboiler.analytics.bot_detection', [
        'enabled' => true,
        'risk_threshold' => 50,
        'reject_on_bot' => false,
        'max_client_ids_per_ip' => 10,
        'velocity_burst' => 50,
        'velocity_window' => 60,
        'bot_ua_patterns' => ['curl'],
    ]);

    $service = new BotDetectionService(Cache::store('array'), $config);

    expect($service->getRiskThreshold())->toBe(50);
});

test('EventSchemaValidationService + BotDetectionService: v8.4.0 services registered in container', function (): void {
    expect(app()->bound(\ZeroBoiler\Analytics\Services\EventSchemaValidationService::class))->toBeTrue();
    expect(app()->bound(\ZeroBoiler\Analytics\Services\BotDetectionService::class))->toBeTrue();

    $schemaService = app(\ZeroBoiler\Analytics\Services\EventSchemaValidationService::class);
    expect($schemaService)->toBeInstanceOf(\ZeroBoiler\Analytics\Services\EventSchemaValidationService::class);

    $botService = app(\ZeroBoiler\Analytics\Services\BotDetectionService::class);
    expect($botService)->toBeInstanceOf(\ZeroBoiler\Analytics\Services\BotDetectionService::class);
});

test('version consistency: v8.8.0 across all entry points', function (): void {

    expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('8.9.0');

    // composer.json version
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($composer['version'])->toBe('8.9.0');

    // package.json version
    $package = json_decode(file_get_contents(base_path('package.json')), true);
    expect($package['version'])->toBe('8.9.0');
});

test('config: schema_validation and bot_detection sections exist', function (): void {
    $config = app(\Illuminate\Contracts\Config\Repository::class);

    $schemaConfig = $config->get('zeroboiler.analytics.schema_validation');
    expect($schemaConfig)->not->toBeNull();
    expect($schemaConfig)->toHaveKey('enabled');
    expect($schemaConfig)->toHaveKey('severity');
    expect($schemaConfig)->toHaveKey('strip_unknown');

    $botConfig = $config->get('zeroboiler.analytics.bot_detection');
    expect($botConfig)->not->toBeNull();
    expect($botConfig)->toHaveKey('enabled');
    expect($botConfig)->toHaveKey('risk_threshold');
    expect($botConfig)->toHaveKey('max_client_ids_per_ip');
    expect($botConfig)->toHaveKey('velocity_burst');
});
