<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\AnalyticsDailyHealthReportService;

test('AnalyticsDailyHealthReportService is final', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportService::class);

    expect($reflection->isFinal())->toBeTrue();
});

test('AnalyticsDailyHealthReportService has strict_types declaration', function (): void {
    $file = file_get_contents((new \ReflectionClass(AnalyticsDailyHealthReportService::class))->getFileName());
    expect($file)->toContain('declare(strict_types=1)');
});

test('AnalyticsDailyHealthReportService has MIT license header', function (): void {
    $file = file_get_contents((new \ReflectionClass(AnalyticsDailyHealthReportService::class))->getFileName());
    expect($file)->toContain('This file is part of ZeroBoiler');
    expect($file)->toContain('MIT license');
});

test('constructor has void return type', function (): void {
    $constructor = new \ReflectionMethod(AnalyticsDailyHealthReportService::class, '__construct');
    expect($constructor->hasReturnType())->toBeTrue();
    expect((string) $constructor->getReturnType())->toBe('void');
});

test('all public methods have return type declarations', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportService::class);

    foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->hasReturnType())
            ->toBeTrue("Method {$method->getName()} is missing return type declaration");
    }
});

test('all private methods have return type declarations', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportService::class);

    foreach ($reflection->getMethods(\ReflectionMethod::IS_PRIVATE) as $method) {
        expect($method->hasReturnType())
            ->toBeTrue("Private method {$method->getName()} is missing return type declaration");
    }
});

test('DOMAIN_WEIGHTS constant has all 7 domains with valid weights', function (): void {
    $weights = AnalyticsDailyHealthReportService::domainWeights();

    expect($weights)->toBeArray()
        ->toHaveCount(7)
        ->toHaveKey('provider_health')
        ->toHaveKey('pipeline_health')
        ->toHaveKey('catalog_integrity')
        ->toHaveKey('data_quality')
        ->toHaveKey('budget_utilization')
        ->toHaveKey('consent_compliance')
        ->toHaveKey('readiness');

    // All weights must be positive integers
    foreach ($weights as $domain => $weight) {
        expect($weight)->toBeInt()
            ->toBeGreaterThan(0, "Weight for {$domain} must be > 0");
    }

    // Total should be 100
    expect(array_sum($weights))->toBe(100);
});

test('healthDomains returns all 7 domain names', function (): void {
    $domains = AnalyticsDailyHealthReportService::healthDomains();

    expect($domains)->toBeArray()
        ->toHaveCount(7);

    foreach ($domains as $domain) {
        expect($domain)->toBeString();
    }
});

test('healthDomains matches DOMAIN_WEIGHTS keys', function (): void {
    $domains = AnalyticsDailyHealthReportService::healthDomains();
    $weights = AnalyticsDailyHealthReportService::domainWeights();

    expect(array_keys($weights))->toEqual($domains);
});

test('supportedGrades returns 12 grade levels', function (): void {
    $grades = AnalyticsDailyHealthReportService::supportedGrades();

    expect($grades)->toBeArray()
        ->toHaveCount(12)
        ->toContain('A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D', 'D-', 'F');
});

test('generate method returns complete report structure', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->once();

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $report = $service->generate(true);

    expect($report)->toBeArray()
        ->toHaveKeys([
            'generated_at',
            'overall_score',
            'grade',
            'domains',
            'critical_issues',
            'warnings',
            'recommendations',
            'metadata',
        ]);

    // Check types
    expect($report['generated_at'])->toBeString();
    expect($report['overall_score'])->toBeInt();
    expect($report['grade'])->toBeString();
    expect($report['domains'])->toBeArray();
    expect($report['critical_issues'])->toBeArray();
    expect($report['warnings'])->toBeArray();
    expect($report['recommendations'])->toBeArray();
    expect($report['metadata'])->toBeArray();

    // Overall score must be 0-100
    expect($report['overall_score'])->toBeGreaterThanOrEqual(0);
    expect($report['overall_score'])->toBeLessThanOrEqual(100);

    // Grade must be a supported grade
    expect(AnalyticsDailyHealthReportService::supportedGrades())
        ->toContain($report['grade']);
});

test('generate respects all 7 domain evaluations', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->once();

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $report = $service->generate(true);

    foreach (AnalyticsDailyHealthReportService::healthDomains() as $domain) {
        expect($report['domains'])->toHaveKey($domain);
        expect($report['domains'][$domain])->toBeArray()
            ->toHaveKeys(['score', 'status', 'details']);
        expect($report['domains'][$domain]['score'])->toBeInt();
        expect($report['domains'][$domain]['status'])->toBeString();
    }
});

test('score returns integer', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->once();

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $score = $service->score();

    expect($score)->toBeInt()
        ->toBeGreaterThanOrEqual(0)
        ->toBeLessThanOrEqual(100);
});

test('status returns valid status string', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->once();

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $status = $service->status();

    expect($status)->toBeString()
        ->toBeIn(['healthy', 'degraded', 'critical']);
});

test('criticalIssues returns array with correct structure', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->once();

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $issues = $service->criticalIssues();

    expect($issues)->toBeArray();
    foreach ($issues as $issue) {
        expect($issue)->toBeArray()
            ->toHaveKeys(['domain', 'severity', 'message', 'recommendation']);
    }
});

test('domainScore returns score and status for valid domain', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->once();

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $result = $service->domainScore('provider_health');

    expect($result)->toBeArray()
        ->toHaveKeys(['score', 'status']);
});

test('domainScore returns unknown for invalid domain', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->once();

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $result = $service->domainScore('nonexistent_domain');

    expect($result)->toBe(['score' => 0, 'status' => 'unknown']);
});

test('clearCache removes cached report', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->with('zb_health_report_keys')->andReturnNull();
    $cache->shouldReceive('forget')->once()->withArgs(function (string $key): bool {
        return str_starts_with($key, 'zb_health_report_');
    });

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $service->clearCache();
})->throwsNoExceptions();

test('cache is used when available and forceRefresh is false', function (): void {
    $cachedReport = [
        'generated_at' => '2026-08-14T00:00:00+00:00',
        'overall_score' => 75,
        'grade' => 'B',
        'domains' => [],
        'critical_issues' => [],
        'warnings' => [],
        'recommendations' => [],
        'metadata' => [],
    ];

    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn($cachedReport);
    $cache->shouldNotReceive('put');

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $report = $service->generate(false);

    expect($report)->toBe($cachedReport);
});

test('metadata has required keys', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->once();

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $report = $service->generate(true);

    expect($report['metadata'])->toBeArray()
        ->toHaveKeys(['catalog_events', 'provider_count', 'config_sections', 'version']);

    expect($report['metadata']['version'])->toBe('116.0.0');
    expect($report['metadata']['provider_count'])->toBeInt();
    expect($report['metadata']['config_sections'])->toBeInt();
});

test('domain scores are clamped to 0-100 range', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->once();

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $report = $service->generate(true);

    foreach ($report['domains'] as $domain => $data) {
        expect($data['score'])->toBeGreaterThanOrEqual(0)
            ->toBeLessThanOrEqual(100, "Domain {$domain} score out of range");
    }
});

test('domain status matches score thresholds', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->once();

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $report = $service->generate(true);

    foreach ($report['domains'] as $domain => $data) {
        $score = $data['score'];
        $status = $data['status'];

        if ($score >= 60) {
            expect($status)->toBe('healthy');
        } elseif ($score >= 30) {
            expect($status)->toBe('degraded');
        } else {
            expect($status)->toBe('critical');
        }
    }
});

test('critical issues severity is always critical', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->once();

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $report = $service->generate(true);

    foreach ($report['critical_issues'] as $issue) {
        expect($issue['severity'])->toBe('critical');
    }
});

test('no providers enabled generates critical issue', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->once();

    $config = mock(ConfigRepository::class);
    // All providers disabled
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);
    $report = $service->generate(true);

    $providerMessages = array_column($report['critical_issues'], 'message');
    expect($providerMessages)->toContain('No analytics providers are enabled');
});

test('class has @since docblock tag', function (): void {
    $reflection = new \ReflectionClass(AnalyticsDailyHealthReportService::class);
    $doc = $reflection->getDocComment();

    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@since');
    expect($doc)->toContain('116.0.0');
});

test('constructor reads daily_health_report config section', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->once();

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.daily_health_report', [])
        ->andReturn([
            'cache_ttl' => 7200,
            'critical_threshold' => 20,
            'warning_threshold' => 50,
        ]);
    $config->shouldReceive('get')->andReturn(false);

    $service = new AnalyticsDailyHealthReportService($cache, $config);

    // With all providers disabled, overall score should be very low
    // But thresholds should now be 20/50
    $status = $service->status();
    expect($status)->toBeIn(['healthy', 'degraded', 'critical']);
});
