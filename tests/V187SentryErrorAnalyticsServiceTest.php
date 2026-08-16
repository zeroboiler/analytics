<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Mockery;
use ZeroBoiler\Analytics\Services\SentryErrorAnalyticsService;

/**
 * Tests for SentryErrorAnalyticsService — Sentry error analytics integration.
 *
 * @covers \ZeroBoiler\Analytics\Services\SentryErrorAnalyticsService
 *
 * @since 187.0.0
 */

test('SentryErrorAnalyticsService constructs with defaults', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    expect($service->isEnabled())->toBeTrue();
    $summary = $service->quickSummary();
    expect($summary['environment'])->toBe('production');
    expect($summary['error_count'])->toBe(0);
    expect($summary['critical_count'])->toBe(0);
    expect($summary['status'])->toBe('healthy');
});

test('SentryErrorAnalyticsService disabled state', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn(['enabled' => false]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    expect($service->isEnabled())->toBeFalse();
    expect($service->quickSummary()['status'])->toBe('disabled');
});

test('SentryErrorAnalyticsService fingerprint is deterministic', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $fp1 = $service->buildFingerprint('TypeError', 'app/Services/User.php:42', ['transaction' => 'checkout']);
    $fp2 = $service->buildFingerprint('TypeError', 'app/Services/User.php:42', ['transaction' => 'checkout']);

    expect($fp1)->toBe($fp2);
    expect(strlen($fp1))->toBe(32); // md5 hex
});

test('SentryErrorAnalyticsService fingerprint differs for different inputs', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $fp1 = $service->buildFingerprint('TypeError', 'app/Services/User.php:42', []);
    $fp2 = $service->buildFingerprint('RuntimeException', 'app/Services/User.php:42', []);

    expect($fp1)->not->toBe($fp2);
});

test('SentryErrorAnalyticsService detects critical path checkout', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $path = $service->detectCriticalPath(
        'Error in checkout',
        'app/Http/Controllers/CheckoutController.php',
        ['route' => '/checkout'],
        ['transaction' => '/api/checkout'],
    );

    expect($path)->toBe('checkout');
});

test('SentryErrorAnalyticsService detects critical path payment', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $path = $service->detectCriticalPath('Payment failed', 'app/Services/PaymentService.php', [], []);

    expect($path)->toBe('payment');
});

test('SentryErrorAnalyticsService detects no critical path', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $path = $service->detectCriticalPath('Some random error', 'app/Utils/Helper.php', [], []);

    expect($path)->toBeNull();
});

test('SentryErrorAnalyticsService impact score basic error', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $score = $service->computeImpactScore([
        'event_count' => 1,
        'level' => 'error',
        'critical_path' => null,
        'last_seen' => date('c'),
    ]);

    expect($score)->toBeGreaterThan(0);
    expect($score)->toBeLessThanOrEqual(100.0);
});

test('SentryErrorAnalyticsService impact score fatal critical path higher than basic', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $fatalScore = $service->computeImpactScore([
        'event_count' => 5,
        'level' => 'fatal',
        'critical_path' => 'checkout',
        'last_seen' => date('c'),
    ]);

    $basicScore = $service->computeImpactScore([
        'event_count' => 5,
        'level' => 'error',
        'critical_path' => null,
        'last_seen' => date('c'),
    ]);

    expect($fatalScore)->toBeGreaterThan($basicScore);
});

test('SentryErrorAnalyticsService impact score capped at 100', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $score = $service->computeImpactScore([
        'event_count' => 999,
        'level' => 'fatal',
        'critical_path' => 'checkout',
        'last_seen' => date('c'),
    ]);

    expect($score)->toBeLessThanOrEqual(100.0);
});

test('SentryErrorAnalyticsService ingests Sentry webhook payload', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $payload = [
        'action' => 'created',
        'data' => [
            'issue' => [
                'id' => '12345',
                'shortId' => 'PROJ-123',
                'title' => 'TypeError: Cannot read property "id" of undefined',
                'level' => 'error',
                'type' => 'error',
                'count' => 5,
                'firstSeen' => '2026-08-15T10:00:00Z',
                'lastSeen' => '2026-08-16T10:00:00Z',
                'culprit' => 'app/Http/Controllers/CheckoutController.php:42',
                'tags' => [['key' => 'transaction', 'value' => 'checkout']],
                'context' => ['route' => '/checkout/process'],
            ],
        ],
    ];

    $event = $service->ingestSentryPayload($payload);

    expect($event)->not->toBeNull();
    expect($event->name)->toBe('sentry_error');
    expect($event->category)->toBe('security');
    expect($event->params['issue_id'])->toBe('12345');
    expect($event->params['short_id'])->toBe('PROJ-123');
    expect($event->params['title'])->toBe('TypeError: Cannot read property "id" of undefined');
    expect($event->params['level'])->toBe('error');
    expect($event->params['event_count'])->toBe(5);
});

test('SentryErrorAnalyticsService rejects payload without action', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $event = $service->ingestSentryPayload(['data' => ['issue' => ['id' => '1']]]);

    expect($event)->toBeNull();
});

test('SentryErrorAnalyticsService rejects payload without issue', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $event = $service->ingestSentryPayload(['action' => 'created']);

    expect($event)->toBeNull();
});

test('SentryErrorAnalyticsService ingestion returns null when disabled', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn(['enabled' => false]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $event = $service->ingestSentryPayload(['action' => 'created', 'data' => ['issue' => ['id' => '1']]]);

    expect($event)->toBeNull();
});

test('SentryErrorAnalyticsService ingestion detects critical path', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $payload = [
        'action' => 'created',
        'data' => [
            'issue' => [
                'id' => '99',
                'title' => 'Payment processing failed',
                'level' => 'error',
                'type' => 'error',
                'count' => 1,
                'culprit' => 'PaymentService.php',
            ],
        ],
    ];

    $event = $service->ingestSentryPayload($payload);

    expect($event)->not->toBeNull();
    expect($event->params['critical_path'])->toBe('payment');
});

test('SentryErrorAnalyticsService error cohorts aggregation', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $service->recordError('fp1', [
        'title' => 'Error A',
        'level' => 'error',
        'critical_path' => null,
        'event_count' => 3,
    ]);
    $service->recordError('fp1', [
        'title' => 'Error A',
        'level' => 'error',
        'critical_path' => null,
        'event_count' => 2,
    ]);

    $cohorts = $service->errorCohorts();

    expect($cohorts['cohorts'])->toBe(1);
    expect($cohorts['total_errors'])->toBe(2);
    expect($cohorts['top_errors'])->toHaveCount(1);
    expect($cohorts['top_errors'][0]['count'])->toBe(2);
    expect($cohorts['top_errors'][0]['fingerprint'])->toBe('fp1');
});

test('SentryErrorAnalyticsService error cohorts with critical path', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $service->recordError('fp1', [
        'title' => 'Checkout Error',
        'level' => 'error',
        'critical_path' => 'checkout',
        'event_count' => 5,
    ]);
    $service->recordError('fp2', [
        'title' => 'Dashboard Warning',
        'level' => 'warning',
        'critical_path' => null,
        'event_count' => 1,
    ]);

    $cohorts = $service->errorCohorts();

    expect($cohorts['cohorts'])->toBe(2);
    expect($cohorts['total_errors'])->toBe(2);
    expect($cohorts['critical_path_count'])->toBe(1);
});

test('SentryErrorAnalyticsService revenue impact no critical errors', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $impact = $service->revenueImpact(100.0);

    expect($impact['estimated_loss'])->toBe(0.0);
    expect($impact['at_risk_revenue'])->toBe(0.0);
    expect($impact['critical_errors'])->toBe(0);
    expect($impact['affected_paths'])->toBe([]);
    expect($impact['conversion_drop_risk'])->toBe(0.0);
});

test('SentryErrorAnalyticsService revenue impact with critical errors', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $service->recordError('fp_checkout', [
        'title' => 'Checkout Error',
        'level' => 'error',
        'critical_path' => 'checkout',
        'event_count' => 10,
    ]);

    $impact = $service->revenueImpact(50.0);

    expect($impact['at_risk_revenue'])->toBeGreaterThan(0);
    expect($impact['critical_errors'])->toBe(1);
    expect($impact['affected_paths'])->toContain('checkout');
    expect($impact['conversion_drop_risk'])->toBeGreaterThan(0);
});

test('SentryErrorAnalyticsService revenue impact zero order value', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $service->recordError('fp_payment', [
        'title' => 'Payment Error',
        'level' => 'error',
        'critical_path' => 'payment',
        'event_count' => 5,
    ]);

    $impact = $service->revenueImpact(0.0);

    expect($impact['at_risk_revenue'])->toBe(0.0);
    expect($impact['estimated_loss'])->toBe(0.0);
});

test('SentryErrorAnalyticsService funnel analysis empty', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $analysis = $service->funnelAnalysis();

    expect($analysis['stages'])->toHaveKey('awareness');
    expect($analysis['stages']['awareness']['errors'])->toBe(0);
    expect($analysis['stages']['conversion']['errors'])->toBe(0);
    expect($analysis['highest_impact_stage'])->toBeNull();
});

test('SentryErrorAnalyticsService funnel analysis with errors', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $service->recordError('fp_signup', [
        'title' => 'Signup Page Crash',
        'level' => 'error',
        'critical_path' => 'signup',
        'event_count' => 5,
    ]);
    $service->recordError('fp_checkout', [
        'title' => 'Checkout failure',
        'level' => 'fatal',
        'critical_path' => 'checkout',
        'event_count' => 10,
    ]);
    $service->recordError('fp_random', [
        'title' => 'Dashboard slow loading',
        'level' => 'warning',
        'critical_path' => null,
        'event_count' => 2,
    ]);

    $analysis = $service->funnelAnalysis();

    expect($analysis['stages']['awareness']['errors'])->toBe(1);
    expect($analysis['stages']['conversion']['errors'])->toBe(1);
    expect($analysis['highest_impact_stage'])->not->toBeNull();
});

test('SentryErrorAnalyticsService clear removes all errors', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $service->recordError('fp1', ['title' => 'Test', 'level' => 'error', 'critical_path' => null, 'event_count' => 1]);
    expect($service->quickSummary()['error_count'])->toBe(1);

    $service->clear();

    expect($service->quickSummary()['error_count'])->toBe(0);
    expect($service->quickSummary()['status'])->toBe('healthy');
});

test('SentryErrorAnalyticsService quick summary degraded with critical errors', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sentry_error_analytics', [])
        ->andReturn([]);

    $service = new SentryErrorAnalyticsService($cache, $config);

    $service->recordError('fp1', [
        'title' => 'Checkout crash',
        'level' => 'error',
        'critical_path' => 'checkout',
        'event_count' => 5,
    ]);

    $summary = $service->quickSummary();

    expect($summary['error_count'])->toBe(1);
    expect($summary['critical_count'])->toBe(1);
    expect($summary['status'])->toBe('degraded');
});

test('SentryErrorAnalyticsService source quality checks', function (): void {
    $reflection = new \ReflectionClass(SentryErrorAnalyticsService::class);
    expect($reflection->isFinal())->toBeTrue();

    $file = $reflection->getFileName();
    $contents = (string) file_get_contents((string) $file);
    expect($contents)->toContain('declare(strict_types=1)');
    expect($contents)->toContain('MIT');
});

test('SentryErrorAnalyticsService public methods have return types', function (): void {
    $reflection = new \ReflectionClass(SentryErrorAnalyticsService::class);

    foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull("Method {$method->getName()} is missing return type declaration");
    }
});
