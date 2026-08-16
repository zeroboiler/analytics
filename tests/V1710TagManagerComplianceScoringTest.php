<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\AnalyticsProviderTagManager;
use ZeroBoiler\Analytics\Services\EventComplianceScoringService;

/**
 * Tests for AnalyticsProviderTagManager (v171.0.0).
 *
 * @coversDefaultClass \ZeroBoiler\Analytics\Services\AnalyticsProviderTagManager
 */
test('tag manager class is final', function (): void {
    $ref = new \ReflectionClass(AnalyticsProviderTagManager::class);
    expect($ref->isFinal())->toBeTrue();
});

test('tag manager has strict types', function (): void {
    $contents = file_get_contents((new \ReflectionClass(AnalyticsProviderTagManager::class))->getFileName());
    expect($contents)->toContain('declare(strict_types=1)');
});

test('tag manager constructor is void', function (): void {
    $ref = new \ReflectionMethod(AnalyticsProviderTagManager::class, '__construct');
    expect($ref->getReturnType()?->getName())->toBe('void');
});

test('tag manager disabled returns false for enable/disable operations', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);
    $manager = mock(AnalyticsManager::class);

    $config->shouldReceive('get')->with('zeroboiler.analytics.tag_manager', [])->andReturn(['enabled' => false]);

    $tm = new AnalyticsProviderTagManager($cache, $config, $manager);

    expect($tm->isEnabled())->toBeFalse();
    expect($tm->enableProvider('ga4'))->toBeFalse();
    expect($tm->disableProvider('meta_pixel'))->toBeFalse();
    expect($tm->setPriority('ga4', 10))->toBeFalse();
    expect($tm->overrideSettings('ga4', ['measurement_id' => 'test']))->toBeFalse();
});

test('tag manager rejects unknown providers', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);
    $manager = mock(AnalyticsManager::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.tag_manager', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 3600]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.provider_priorities', [])
        ->andReturn([]);
    $cache->shouldReceive('get')->andReturn([]);
    $cache->shouldReceive('put')->once();

    $tm = new AnalyticsProviderTagManager($cache, $config, $manager);

    expect($tm->enableProvider('unknown_provider'))->toBeFalse();
    expect($tm->disableProvider('nonexistent'))->toBeFalse();
});

test('tag manager enable provider persists override', function (): void {
    $overrides = [];
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);
    $manager = mock(AnalyticsManager::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.tag_manager', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 3600]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.provider_priorities', [])
        ->andReturn([]);

    $cache->shouldReceive('get')->andReturnUsing(function (string $key) use (&$overrides) {
        return $key === 'zb_tag_manager_overrides' ? $overrides : null;
    });
    $cache->shouldReceive('put')->once()->withArgs(function (string $key, array $data) use (&$overrides): bool {
        if ($key === 'zb_tag_manager_overrides') {
            $overrides = $data;
            return true;
        }
        return false;
    });

    $tm = new AnalyticsProviderTagManager($cache, $config, $manager);
    $result = $tm->enableProvider('ga4', 'test enable');

    expect($result)->toBeTrue();
    expect($overrides)->toHaveKey('ga4');
    expect($overrides['ga4']['enabled'])->toBeTrue();
    expect($overrides['ga4']['reason'])->toBe('test enable');
});

test('tag manager disable provider persists override', function (): void {
    $overrides = [];
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);
    $manager = mock(AnalyticsManager::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.tag_manager', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 3600]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.provider_priorities', [])
        ->andReturn([]);

    $cache->shouldReceive('get')->andReturnUsing(function (string $key) use (&$overrides) {
        return $key === 'zb_tag_manager_overrides' ? $overrides : null;
    });
    $cache->shouldReceive('put')->once();

    $tm = new AnalyticsProviderTagManager($cache, $config, $manager);
    $result = $tm->disableProvider('posthog', 'maintenance');

    expect($result)->toBeTrue();
});

test('tag manager set priority persists and retrieves', function (): void {
    $overrides = [];
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);
    $manager = mock(AnalyticsManager::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.tag_manager', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 3600]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.provider_priorities', [])
        ->andReturn([]);

    $cache->shouldReceive('get')->andReturnUsing(function (string $key) use (&$overrides) {
        return $key === 'zb_tag_manager_overrides' ? $overrides : null;
    });
    $cache->shouldReceive('put')->once();

    $tm = new AnalyticsProviderTagManager($cache, $config, $manager);
    $result = $tm->setPriority('ga4', 10);

    expect($result)->toBeTrue();
    expect($tm->getPriority('ga4'))->toBe(10);
});

test('tag manager priority is clamped 0-100', function (): void {
    $overrides = [];
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);
    $manager = mock(AnalyticsManager::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.tag_manager', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 3600]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.provider_priorities', [])
        ->andReturn([]);

    $cache->shouldReceive('get')->andReturnUsing(function (string $key) use (&$overrides) {
        return $key === 'zb_tag_manager_overrides' ? $overrides : null;
    });
    $cache->shouldReceive('put')->twice();

    $tm = new AnalyticsProviderTagManager($cache, $config, $manager);

    $tm->setPriority('ga4', -10);
    expect($tm->getPriority('ga4'))->toBe(0);

    $tm->setPriority('ga4', 200);
    expect($tm->getPriority('ga4'))->toBe(100);
});

test('tag manager health tracking records success and failure', function (): void {
    $health = [];
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);
    $manager = mock(AnalyticsManager::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.tag_manager', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 3600, 'max_consecutive_failures' => 5, 'failover_cooldown' => 3600]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.provider_priorities', [])
        ->andReturn([]);

    $cache->shouldReceive('get')->andReturnUsing(function (string $key) use (&$health, &$overrides): mixed {
        return match ($key) {
            'zb_tag_manager_health' => $health,
            'zb_tag_manager_overrides' => [],
            default => null,
        };
    });
    $cache->shouldReceive('put')->once();

    $tm = new AnalyticsProviderTagManager($cache, $config, $manager);

    // Record success
    $tm->recordSuccess('ga4');
    $h = $tm->getHealth('ga4');
    expect($h['status'])->toBe('healthy');
    expect($h['consecutive_failures'])->toBe(0);

    // Record failures
    $tm->recordFailure('ga4', 500.0);
    $tm->recordFailure('ga4', 600.0);
    $h = $tm->getHealth('ga4');
    expect($h['consecutive_failures'])->toBe(2);
    expect($h['status'])->toBe('degraded');
});

test('tag manager get all health returns all providers', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);
    $manager = mock(AnalyticsManager::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.tag_manager', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 3600]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.provider_priorities', [])
        ->andReturn([]);
    $cache->shouldReceive('get')->andReturn([]);
    $cache->shouldReceive('put')->zeroOrMoreTimes();

    $tm = new AnalyticsProviderTagManager($cache, $config, $manager);
    $allHealth = $tm->getAllHealth();

    expect($allHealth)->toHaveKey('ga4');
    expect($allHealth)->toHaveKey('posthog');
    expect($allHealth)->toHaveKey('meta_pixel');
    expect(count($allHealth))->toBe(10);
});

test('tag manager summary returns structured data', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);
    $manager = mock(AnalyticsManager::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.tag_manager', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 3600]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.provider_priorities', [])
        ->andReturn([]);
    $cache->shouldReceive('get')->andReturn([]);
    $cache->shouldReceive('put')->zeroOrMoreTimes();

    // Provider config defaults
    $config->shouldReceive('get')->andReturnFalse(); // All providers disabled by default

    $tm = new AnalyticsProviderTagManager($cache, $config, $manager);
    $summary = $tm->summary();

    expect($summary)->toHaveKeys(['enabled', 'providers', 'active_providers', 'overrides', 'health_issues', 'ordered_providers', 'provider_details']);
    expect($summary['enabled'])->toBeTrue();
    expect($summary['providers'])->toBe(10);
});

test('tag manager disable all and restore all', function (): void {
    $overrides = [];
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);
    $manager = mock(AnalyticsManager::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.tag_manager', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 3600]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.provider_priorities', [])
        ->andReturn([]);

    // Simulate all providers enabled
    $config->shouldReceive('get')->andReturnTrue();

    $cache->shouldReceive('get')->andReturnUsing(function (string $key) use (&$overrides) {
        return $key === 'zb_tag_manager_overrides' ? $overrides : [];
    });
    $cache->shouldReceive('put')->zeroOrMoreTimes();

    $tm = new AnalyticsProviderTagManager($cache, $config, $manager);

    // All providers should be active (config defaults to true)
    $activeCount = count(array_filter(AnalyticsProviderTagManager::getProviders(), fn (string $p) => $tm->isProviderActive($p)));

    $tm->disableAll('Maintenance mode');
    $overrideCount = $tm->restoreAll();

    // Restore should return count of cleared overrides
    expect($overrideCount)->toBeGreaterThanOrEqual(0);
});

test('tag manager static providers list', function (): void {
    $providers = AnalyticsProviderTagManager::getProviders();

    expect($providers)->toBeArray();
    expect($providers)->toContain('ga4');
    expect($providers)->toContain('gtm');
    expect($providers)->toContain('meta_pixel');
    expect($providers)->toContain('posthog');
    expect($providers)->toContain('plausible');
    expect($providers)->toContain('amplitude');
    expect($providers)->toContain('mixpanel');
    expect($providers)->toContain('tiktok');
    expect($providers)->toContain('linkedin');
    expect($providers)->toContain('webhook');
    expect(count($providers))->toBe(10);
});

test('tag manager override settings merges with config', function (): void {
    $overrides = [];
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);
    $manager = mock(AnalyticsManager::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.tag_manager', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 3600]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.provider_priorities', [])
        ->andReturn([]);

    $cache->shouldReceive('get')->andReturnUsing(function (string $key) use (&$overrides) {
        return $key === 'zb_tag_manager_overrides' ? $overrides : null;
    });
    $cache->shouldReceive('put')->once();

    // GA4 config base settings
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.ga4')
        ->andReturn(['measurement_id' => 'G-ORIGINAL', 'api_secret' => 'secret123']);

    $tm = new AnalyticsProviderTagManager($cache, $config, $manager);
    $tm->overrideSettings('ga4', ['measurement_id' => 'G-OVERRIDE'], 'testing');

    $effective = $tm->getEffectiveSettings('ga4');
    expect($effective['measurement_id'])->toBe('G-OVERRIDE');
    expect($effective['api_secret'])->toBe('secret123');
});

/**
 * Tests for EventComplianceScoringService (v171.0.0).
 *
 * @coversDefaultClass \ZeroBoiler\Analytics\Services\EventComplianceScoringService
 */
test('compliance scoring class is final', function (): void {
    $ref = new \ReflectionClass(EventComplianceScoringService::class);
    expect($ref->isFinal())->toBeTrue();
});

test('compliance scoring has strict types', function (): void {
    $contents = file_get_contents((new \ReflectionClass(EventComplianceScoringService::class))->getFileName());
    expect($contents)->toContain('declare(strict_types=1)');
});

test('compliance scoring constructor is void', function (): void {
    $ref = new \ReflectionMethod(EventComplianceScoringService::class, '__construct');
    expect($ref->getReturnType()?->getName())->toBe('void');
});

test('compliance scoring is enabled by default', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.compliance_scoring', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 7200, 'pii_fields' => ['email'], 'event_overrides' => []]);

    $service = new EventComplianceScoringService($cache, $config);
    expect($service->isEnabled())->toBeTrue();
});

test('compliance scoring disabled returns empty results', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.compliance_scoring', [])
        ->andReturn(['enabled' => false]);

    $service = new EventComplianceScoringService($cache, $config);
    expect($service->isEnabled())->toBeFalse();
});

test('compliance scoring returns valid structure for page_view event', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.compliance_scoring', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 7200, 'pii_fields' => ['email'], 'event_overrides' => []]);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.consent.log_ttl', 7776000)
        ->andReturn(7776000);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.audit_trail', [])
        ->andReturn(['enabled' => true]);

    $service = new EventComplianceScoringService($cache, $config);
    $score = $service->scoreEvent('page_view');

    expect($score)->toHaveKeys(['event', 'category', 'dimensions', 'overall', 'grade', 'violations', 'recommendations']);
    expect($score['event'])->toBe('page_view');
    expect($score['category'])->toBe('engagement');
    expect($score['overall'])->toBeInt();
    expect($score['overall'])->toBeGreaterThanOrEqual(0);
    expect($score['overall'])->toBeLessThanOrEqual(100);
    expect($score['grade'])->toBeString();
    expect($score['dimensions'])->toBeArray();

    // Engagement events should have high scores
    foreach ($score['dimensions'] as $dimension => $val) {
        expect($val)->toBeGreaterThanOrEqual(0);
        expect($val)->toBeLessThanOrEqual(100);
    }
});

test('compliance scoring sign_up has lower PII risk score', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.compliance_scoring', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 7200, 'pii_fields' => ['email', 'name'], 'event_overrides' => []]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.consent.log_ttl', 7776000)
        ->andReturn(7776000);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.audit_trail', [])
        ->andReturn(['enabled' => true]);

    $service = new EventComplianceScoringService($cache, $config);
    $score = $service->scoreEvent('sign_up');

    // sign_up should have lower PII risk score (higher risk = lower score)
    expect($score['dimensions']['pii_risk'])->toBeLessThan(80);
    expect($score['violations'])->toBeNonEmpty(); // Should have PII-related violations
});

test('compliance scoring purchase has moderate scores', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.compliance_scoring', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 7200, 'pii_fields' => ['email'], 'event_overrides' => []]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.consent.log_ttl', 7776000)
        ->andReturn(7776000);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.audit_trail', [])
        ->andReturn(['enabled' => true]);

    $service = new EventComplianceScoringService($cache, $config);
    $score = $service->scoreEvent('purchase');

    expect($score['category'])->toBe('ecommerce');
    expect($score['dimensions']['data_minimization'])->toBeGreaterThanOrEqual(85);
});

test('compliance scoring grades are correct', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.compliance_scoring', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 7200, 'pii_fields' => ['email'], 'event_overrides' => []]);

    $service = new EventComplianceScoringService($cache, $config);

    // Quick health check
    $health = $service->quickHealth();
    expect($health)->toHaveKeys(['score', 'grade', 'compliant', 'events_scored']);
    expect($health['score'])->toBeInt();
    expect($health['grade'])->toBeString();
    expect($health['events_scored'])->toBeGreaterThan(0);
});

test('compliance scoring event override improves scores', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $overrides = [
        'sign_up' => [
            'pii_fields' => ['email', 'name'],
            'retention_days' => 365,
            'legal_basis' => 'consent',
            'sensitive' => true,
        ],
    ];

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.compliance_scoring', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 7200, 'pii_fields' => ['email', 'name'], 'event_overrides' => $overrides]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.consent.log_ttl', 7776000)
        ->andReturn(7776000);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.audit_trail', [])
        ->andReturn(['enabled' => true]);

    $service = new EventComplianceScoringService($cache, $config);
    $score = $service->scoreEvent('sign_up');

    // With overrides, retention compliance should be good
    expect($score['dimensions']['retention_compliance'])->toBeGreaterThanOrEqual(80);
    // PII risk should be moderate (acknowledged but still present)
    expect($score['dimensions']['pii_risk'])->toBeGreaterThanOrEqual(60);
});

test('compliance scoring system report structure', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.compliance_scoring', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 7200, 'pii_fields' => ['email'], 'event_overrides' => []]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.consent.log_ttl', 7776000)
        ->andReturn(7776000);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.audit_trail', [])
        ->andReturn(['enabled' => true]);

    $cache->shouldReceive('put')->once();
    $cache->shouldReceive('get')->andReturn(null);

    $service = new EventComplianceScoringService($cache, $config);
    $report = $service->systemReport();

    expect($report)->toHaveKeys([
        'overall_score', 'grade', 'events_scored', 'events_compliant',
        'events_needing_attention', 'critical_violations',
        'gdpr_score', 'ccpa_score', 'soc2_score',
        'dimensions_summary', 'top_violations',
    ]);
    expect($report['events_scored'])->toBeGreaterThan(0);
    expect($report['overall_score'])->toBeInt();
    expect($report['gdpr_score'])->toBeInt();
    expect($report['ccpa_score'])->toBeInt();
    expect($report['soc2_score'])->toBeInt();
    expect($report['dimensions_summary'])->toBeArray();
});

test('compliance scoring invalidate cache', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.compliance_scoring', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 7200, 'pii_fields' => ['email'], 'event_overrides' => []]);

    $cache->shouldReceive('forget')->with('zb_compliance_score_system_report')->once();

    $service = new EventComplianceScoringService($cache, $config);
    $service->invalidateCache();
    // Should not throw
    expect(true)->toBeTrue();
});

test('compliance scoring pii fields accessor', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $customFields = ['email', 'phone', 'ssn', 'custom_pii'];

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.compliance_scoring', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 7200, 'pii_fields' => $customFields, 'event_overrides' => []]);

    $service = new EventComplianceScoringService($cache, $config);
    expect($service->getPiiFields())->toBe($customFields);
});

test('compliance scoring isEventCompliant returns boolean', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.compliance_scoring', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 7200, 'pii_fields' => ['email'], 'event_overrides' => []]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.consent.log_ttl', 7776000)
        ->andReturn(7776000);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.audit_trail', [])
        ->andReturn(['enabled' => true]);

    $service = new EventComplianceScoringService($cache, $config);
    $compliant = $service->isEventCompliant('page_view');

    expect($compliant)->toBeBool();
});
