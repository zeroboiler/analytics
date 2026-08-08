<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\Services\ProviderCircuitBreaker;
use ZeroBoiler\Analytics\Services\EventComplianceService;
use ZeroBoiler\Analytics\Services\AnalyticsRecoveryService;
use ZeroBoiler\Analytics\AnalyticsManager;

beforeEach(function (): void {
    Cache::clear();
});

// ─── ProviderCircuitBreaker ─────────────────────────────────────────

describe('ProviderCircuitBreaker', function (): void {
    test('constructs with default config when disabled', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.circuit_breaker', [])
            ->once()
            ->andReturn([]);

        $breaker = new ProviderCircuitBreaker($config);

        expect($breaker->isEnabled())->toBeFalse();
    });

    test('constructs with enabled config', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.circuit_breaker', [])
            ->once()
            ->andReturn([
                'enabled' => true,
                'failure_threshold' => 3,
                'success_threshold' => 2,
                'cooldown_seconds' => 30,
            ]);

        $breaker = new ProviderCircuitBreaker($config);

        expect($breaker->isEnabled())->toBeTrue();
        expect($breaker->getFailureThreshold())->toBe(3);
        expect($breaker->getSuccessThreshold())->toBe(2);
        expect($breaker->getCooldownSeconds())->toBe(30);
    });

    test('shouldDispatch returns true when disabled', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $breaker = new ProviderCircuitBreaker($config);

        expect($breaker->shouldDispatch('ga4'))->toBeTrue();
    });

    test('initial state is closed for all providers', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn(['enabled' => true]);

        $breaker = new ProviderCircuitBreaker($config);

        expect($breaker->getState('ga4'))->toBe(ProviderCircuitBreaker::STATE_CLOSED);
        expect($breaker->getState('meta'))->toBe(ProviderCircuitBreaker::STATE_CLOSED);
    });

    test('circuit opens after exceeding failure threshold', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([
            'enabled' => true,
            'failure_threshold' => 3,
            'success_threshold' => 2,
            'cooldown_seconds' => 60,
        ]);

        $breaker = new ProviderCircuitBreaker($config);

        $breaker->recordFailure('ga4');
        $breaker->recordFailure('ga4');
        expect($breaker->getState('ga4'))->toBe(ProviderCircuitBreaker::STATE_CLOSED);

        $breaker->recordFailure('ga4');
        expect($breaker->getState('ga4'))->toBe(ProviderCircuitBreaker::STATE_OPEN);
    });

    test('shouldDispatch returns false when circuit is open', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([
            'enabled' => true,
            'failure_threshold' => 1,
            'success_threshold' => 1,
            'cooldown_seconds' => 300,
        ]);

        $breaker = new ProviderCircuitBreaker($config);
        $breaker->recordFailure('ga4');

        expect($breaker->shouldDispatch('ga4'))->toBeFalse();
        expect($breaker->shouldDispatch('meta'))->toBeTrue(); // other providers unaffected
    });

    test('reset transitions circuit to closed', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([
            'enabled' => true,
            'failure_threshold' => 1,
            'success_threshold' => 1,
            'cooldown_seconds' => 300,
        ]);

        $breaker = new ProviderCircuitBreaker($config);
        $breaker->recordFailure('ga4');
        expect($breaker->getState('ga4'))->toBe(ProviderCircuitBreaker::STATE_OPEN);

        $breaker->reset('ga4');
        expect($breaker->getState('ga4'))->toBe(ProviderCircuitBreaker::STATE_CLOSED);
    });

    test('trip forces circuit open', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([
            'enabled' => true,
            'failure_threshold' => 5,
            'success_threshold' => 2,
            'cooldown_seconds' => 300,
        ]);

        $breaker = new ProviderCircuitBreaker($config);
        $breaker->trip('ga4');

        expect($breaker->getState('ga4'))->toBe(ProviderCircuitBreaker::STATE_OPEN);
        expect($breaker->shouldDispatch('ga4'))->toBeFalse();
    });

    test('getDashboard returns all providers', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([
            'enabled' => true,
            'failure_threshold' => 5,
            'success_threshold' => 2,
            'cooldown_seconds' => 60,
        ]);

        $breaker = new ProviderCircuitBreaker($config);
        $dashboard = $breaker->getDashboard();

        expect($dashboard['enabled'])->toBeTrue();
        expect(isset($dashboard['providers']['ga4']))->toBeTrue();
        expect(isset($dashboard['providers']['meta']))->toBeTrue();
        expect(isset($dashboard['providers']['webhook']))->toBeTrue();
        expect(count($dashboard['providers']))->toBe(6);
    });

    test('getDashboard returns empty when disabled', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $breaker = new ProviderCircuitBreaker($config);
        $dashboard = $breaker->getDashboard();

        expect($dashboard['enabled'])->toBeFalse();
        expect($dashboard['providers'])->toBeEmpty();
    });

    test('summary returns correct counts', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([
            'enabled' => true,
            'failure_threshold' => 1,
            'success_threshold' => 1,
            'cooldown_seconds' => 300,
        ]);

        $breaker = new ProviderCircuitBreaker($config);
        $breaker->trip('ga4');
        $breaker->trip('meta');

        $summary = $breaker->summary();

        expect($summary['total_open'])->toBe(2);
        expect($summary['total_closed'])->toBe(4);
    });

    test('independent circuits per provider', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([
            'enabled' => true,
            'failure_threshold' => 1,
            'success_threshold' => 1,
            'cooldown_seconds' => 300,
        ]);

        $breaker = new ProviderCircuitBreaker($config);
        $breaker->recordFailure('ga4');

        expect($breaker->getState('ga4'))->toBe(ProviderCircuitBreaker::STATE_OPEN);
        expect($breaker->getState('meta'))->toBe(ProviderCircuitBreaker::STATE_CLOSED);
    });

    test('constants have correct values', function (): void {
        expect(ProviderCircuitBreaker::STATE_CLOSED)->toBe('closed');
        expect(ProviderCircuitBreaker::STATE_OPEN)->toBe('open');
        expect(ProviderCircuitBreaker::STATE_HALF_OPEN)->toBe('half_open');
    });
});

// ─── EventComplianceService ─────────────────────────────────────────

describe('EventComplianceService', function (): void {
    test('constructs with default config', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new EventComplianceService($config);

        expect($service->isEnabled())->toBeTrue(); // default enabled
    });

    test('constructs with disabled config', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn(['enabled' => false]);

        $service = new EventComplianceService($config);

        expect($service->isEnabled())->toBeFalse();
    });

    test('analyzePiiExposure returns correct structure', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new EventComplianceService($config);
        $result = $service->analyzePiiExposure();

        expect($result)->toHaveKeys(['score', 'total_events_analyzed', 'pii_events', 'pii_risk_by_category', 'pii_fields_detected', 'anonymization_enabled', 'ip_anonymization_enabled']);
        expect($result['total_events_analyzed'])->toBe(73);
        expect($result['anonymization_enabled'])->toBeFalse();
        expect($result['ip_anonymization_enabled'])->toBeFalse();
    });

    test('analyzePiiExposure detects enabled anonymization', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.pii_sanitization', [])
            ->andReturn(['enabled' => true, 'strategy' => 'hash']);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr', [])
            ->andReturn(['anonymize_ip' => true]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.anonymization', [])
            ->andReturn(['enabled' => true]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.compliance', [])
            ->andReturn([]);

        $service = new EventComplianceService($config);
        $result = $service->analyzePiiExposure();

        expect($result['anonymization_enabled'])->toBeTrue();
        expect($result['ip_anonymization_enabled'])->toBeTrue();
        expect($result['score'])->toBe(100);
    });

    test('analyzeConsentCoverage returns correct structure', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new EventComplianceService($config);
        $result = $service->analyzeConsentCoverage();

        expect($result)->toHaveKeys(['score', 'total_events_mapped', 'unmapped_events', 'purpose_breakdown', 'default_consent', 'granular_consent_enabled']);
        expect($result['default_consent'])->toBe('granted');
    });

    test('analyzeConsentCoverage scores GDPR-safe defaults higher', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent', [])
            ->andReturn(['default' => 'denied', 'purposes' => []]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent_purposes', [])
            ->andReturn(['enabled' => true]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.compliance', [])
            ->andReturn([]);

        $service = new EventComplianceService($config);
        $result = $service->analyzeConsentCoverage();

        expect($result['score'])->toBe(100);
        expect($result['default_consent'])->toBe('denied');
        expect($result['granular_consent_enabled'])->toBeTrue();
    });

    test('analyzeRetention returns correct structure', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new EventComplianceService($config);
        $result = $service->analyzeRetention();

        expect($result)->toHaveKeys(['score', 'categories', 'global_retention_days', 'archive_action']);
        expect($result['archive_action'])->toBe('delete');
        expect(isset($result['categories']['ecommerce']))->toBeTrue();
        expect(isset($result['categories']['saas']))->toBeTrue();
        expect(isset($result['categories']['engagement']))->toBeTrue();
    });

    test('analyzeDataMinimization returns correct structure', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new EventComplianceService($config);
        $result = $service->analyzeDataMinimization();

        expect($result)->toHaveKeys(['score', 'enabled', 'global_allowlist_count', 'strip_params_count', 'audit_logging', 'strategy']);
    });

    test('analyzeProcessingTransparency returns correct structure', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new EventComplianceService($config);
        $result = $service->analyzeProcessingTransparency();

        expect($result)->toHaveKeys(['score', 'providers_configured', 'providers_total', 'pipeline_steps', 'middleware_registered', 'data_export_available', 'dsar_available']);
        expect($result['data_export_available'])->toBeTrue();
        expect($result['dsar_available'])->toBeTrue();
    });

    test('generateReport returns complete report', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new EventComplianceService($config);
        $report = $service->generateReport();

        expect($report)->toHaveKeys(['generated_at', 'overall_score', 'pii_exposure', 'consent_coverage', 'retention', 'data_minimization', 'processing_transparency', 'recommendations']);
        expect($report['overall_score'])->toBeGreaterThanOrEqual(0);
        expect($report['overall_score'])->toBeLessThanOrEqual(100);
        expect($report['recommendations'])->toBeNonEmpty();
    });

    test('getScore returns integer', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new EventComplianceService($config);
        $score = $service->getScore();

        expect($score)->toBeInt();
        expect($score)->toBeGreaterThanOrEqual(0);
        expect($score)->toBeLessThanOrEqual(100);
    });

    test('static getters return correct data', function (): void {
        expect(EventComplianceService::getPiiPatterns())->toBeNonEmpty();
        expect(in_array('email', EventComplianceService::getPiiPatterns()))->toBeTrue();

        expect(EventComplianceService::getEventPurposes())->toBeNonEmpty();
        expect(isset(EventComplianceService::getEventPurposes()['page_view']))->toBeTrue();

        expect(EventComplianceService::getCategoryPolicies())->toBeNonEmpty();
        expect(isset(EventComplianceService::getCategoryPolicies()['ecommerce']))->toBeTrue();
    });
});

// ─── AnalyticsRecoveryService ──────────────────────────────────────

describe('AnalyticsRecoveryService', function (): void {
    test('constructs with default config', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new AnalyticsRecoveryService($manager, $config);

        expect($service->isEnabled())->toBeTrue(); // default enabled
    });

    test('getBudget returns correct structure', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new AnalyticsRecoveryService($manager, $config);
        $budget = $service->getBudget();

        expect($budget)->toHaveKeys(['remaining', 'max', 'used', 'resets_at']);
        expect($budget['remaining'])->toBeGreaterThan(0);
        expect($budget['used'])->toBe(0);
    });

    test('hasBudgetRemaining returns true when budget is full', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new AnalyticsRecoveryService($manager, $config);

        expect($service->hasBudgetRemaining())->toBeTrue();
    });

    test('recordRecovery increments budget', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([
            'max_recoveries_per_hour' => 5,
        ]);

        $service = new AnalyticsRecoveryService($manager, $config);
        $service->recordRecovery();

        $budget = $service->getBudget();
        expect($budget['used'])->toBe(1);
    });

    test('batchRecover returns empty when no DLQ service', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new AnalyticsRecoveryService($manager, $config, null);
        $result = $service->batchRecover(5);

        expect($result['recovered'])->toBe(0);
        expect($result['failed'])->toBe(0);
        expect($result['details'])->toBeEmpty();
    });

    test('assessHealth returns correct structure', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new AnalyticsRecoveryService($manager, $config);
        $health = $service->assessHealth();

        expect($health)->toHaveKeys(['status', 'dlq_size', 'budget_remaining', 'recovery_rate_24h', 'health_score']);
        expect(in_array($health['status'], ['healthy', 'degraded', 'critical']))->toBeTrue();
        expect($health['health_score'])->toBeGreaterThanOrEqual(0);
        expect($health['health_score'])->toBeLessThanOrEqual(100);
    });

    test('summary returns correct structure', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([
            'batch_size' => 10,
            'max_recoveries_per_hour' => 50,
        ]);

        $service = new AnalyticsRecoveryService($manager, $config);
        $summary = $service->summary();

        expect($summary)->toHaveKeys(['enabled', 'batch_size', 'max_recoveries_per_hour', 'dlq_size', 'health']);
        expect($summary['batch_size'])->toBe(10);
    });

    test('recordHistory stores data', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->andReturn([]);

        $service = new AnalyticsRecoveryService($manager, $config);
        $service->recordHistory(5, 1);

        $history = $service->getHistory();
        expect($history['total_recovered_24h'])->toBe(5);
        expect($history['total_failed_24h'])->toBe(1);
    });
});

// ─── Version Consistency ────────────────────────────────────────────

describe('Version Consistency v2.70.0', function (): void {
    test('composer.json version is 2.70.0', function (): void {
        $json = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

        expect($json['version'])->toBe('2.70.0');
    });

    test('new service files exist', function (): void {
        expect(file_exists(__DIR__ . '/../src/Services/ProviderCircuitBreaker.php'))->toBeTrue();
        expect(file_exists(__DIR__ . '/../src/Services/EventComplianceService.php'))->toBeTrue();
        expect(file_exists(__DIR__ . '/../src/Services/AnalyticsRecoveryService.php'))->toBeTrue();
    });

    test('new service files have strict types', function (): void {
        $files = [
            __DIR__ . '/../src/Services/ProviderCircuitBreaker.php',
            __DIR__ . '/../src/Services/EventComplianceService.php',
            __DIR__ . '/../src/Services/AnalyticsRecoveryService.php',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            expect(str_contains($contents, 'declare(strict_types=1);'))->toBeTrue();
        }
    });

    test('new service files are final classes', function (): void {
        $files = [
            __DIR__ . '/../src/Services/ProviderCircuitBreaker.php',
            __DIR__ . '/../src/Services/EventComplianceService.php',
            __DIR__ . '/../src/Services/AnalyticsRecoveryService.php',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            expect(str_contains($contents, 'final class '))->toBeTrue();
        }
    });

    test('ServiceProvider imports new services', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        expect(str_contains($contents, 'use ZeroBoiler\\Analytics\\Services\\ProviderCircuitBreaker;'))->toBeTrue();
        expect(str_contains($contents, 'use ZeroBoiler\\Analytics\\Services\\EventComplianceService;'))->toBeTrue();
        expect(str_contains($contents, 'use ZeroBoiler\\Analytics\\Services\\AnalyticsRecoveryService;'))->toBeTrue();
    });

    test('ServiceProvider registers new services', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        expect(str_contains($contents, 'ProviderCircuitBreaker::class'))->toBeTrue();
        expect(str_contains($contents, 'EventComplianceService::class'))->toBeTrue();
        expect(str_contains($contents, 'AnalyticsRecoveryService::class'))->toBeTrue();
    });

    test('config has new sections', function (): void {
        $contents = file_get_contents(__DIR__ . '/../config/zeroboiler.php');

        expect(str_contains($contents, "'circuit_breaker'"))->toBeTrue();
        expect(str_contains($contents, "'compliance'"))->toBeTrue();
        expect(str_contains($contents, "'recovery'"))->toBeTrue();
    });

    test('routes file has new endpoints', function (): void {
        $contents = file_get_contents(__DIR__ . '/../routes/analytics.php');

        expect(str_contains($contents, 'circuitBreakerDashboard'))->toBeTrue();
        expect(str_contains($contents, 'circuitBreakerSummary'))->toBeTrue();
        expect(str_contains($contents, 'complianceReport'))->toBeTrue();
        expect(str_contains($contents, 'complianceScore'))->toBeTrue();
        expect(str_contains($contents, 'recoveryBudget'))->toBeTrue();
        expect(str_contains($contents, 'recoveryHealth'))->toBeTrue();
        expect(str_contains($contents, 'recoveryHistory'))->toBeTrue();
        expect(str_contains($contents, 'recoveryBatch'))->toBeTrue();
    });

    test('controller has new methods', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');

        expect(str_contains($contents, 'public function circuitBreakerDashboard'))->toBeTrue();
        expect(str_contains($contents, 'public function circuitBreakerSummary'))->toBeTrue();
        expect(str_contains($contents, 'public function circuitBreakerReset'))->toBeTrue();
        expect(str_contains($contents, 'public function circuitBreakerTrip'))->toBeTrue();
        expect(str_contains($contents, 'public function complianceReport'))->toBeTrue();
        expect(str_contains($contents, 'public function complianceScore'))->toBeTrue();
        expect(str_contains($contents, 'public function complianceInvalidateCache'))->toBeTrue();
        expect(str_contains($contents, 'public function recoveryBudget'))->toBeTrue();
        expect(str_contains($contents, 'public function recoveryHealth'))->toBeTrue();
        expect(str_contains($contents, 'public function recoveryHistory'))->toBeTrue();
        expect(str_contains($contents, 'public function recoveryBatch'))->toBeTrue();
    });

    test('JS client has new functions', function (): void {
        $contents = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

        expect(str_contains($contents, 'fetchCircuitBreakerDashboard'))->toBeTrue();
        expect(str_contains($contents, 'fetchCircuitBreakerSummary'))->toBeTrue();
        expect(str_contains($contents, 'fetchComplianceReport'))->toBeTrue();
        expect(str_contains($contents, 'fetchComplianceScore'))->toBeTrue();
        expect(str_contains($contents, 'fetchRecoveryBudget'))->toBeTrue();
        expect(str_contains($contents, 'fetchRecoveryHealth'))->toBeTrue();
        expect(str_contains($contents, 'fetchRecoveryHistory'))->toBeTrue();
    });

    test('TypeScript definitions have new types', function (): void {
        $contents = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');

        expect(str_contains($contents, 'CircuitBreakerDashboard'))->toBeTrue();
        expect(str_contains($contents, 'ComplianceReport'))->toBeTrue();
        expect(str_contains($contents, 'RecoveryBudget'))->toBeTrue();
        expect(str_contains($contents, 'RecoveryHealth'))->toBeTrue();
        expect(str_contains($contents, 'RecoveryHistory'))->toBeTrue();
    });

    test('controller version strings are 2.70.0 for new endpoints', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');

        // New v2.70.0 endpoints should have version 2.70.0
        expect(str_contains($contents, "'version' => '2.70.0'"))->toBeTrue();
    });

    test('JS version is 2.70.0', function (): void {
        $contents = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

        expect(str_contains($contents, '@version 2.70.0'))->toBeTrue();
    });
});
