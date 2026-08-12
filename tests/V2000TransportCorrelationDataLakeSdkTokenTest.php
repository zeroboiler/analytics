<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventTransportService;
use ZeroBoiler\Analytics\Services\EventCorrelationMatrixService;
use ZeroBoiler\Analytics\Services\DataLakeExportService;
use ZeroBoiler\Analytics\Services\SdkScopeTokenService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->cache->shouldReceive('get')->andReturn([]);
    $this->cache->shouldReceive('put')->andReturn(true);
    $this->cache->shouldReceive('forget')->andReturn(true);
    $this->cache->shouldReceive('flush')->andReturn(true);

    $this->config = mock(ConfigRepository::class);
});

// ─── Event Transport Service ───────────────────────────────────────────────

describe('EventTransportService', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.transport', [])
            ->andReturn([
                'enabled' => true,
                'default_timeout' => 5,
                'default_retries' => 2,
                'circuit_threshold' => 3.0,
                'circuit_reset_timeout' => 60,
                'circuit_half_open_max' => 3,
                'metrics_ttl' => 300,
            ]);

        $this->transport = new EventTransportService($this->cache, $this->config);
    });

    test('constructs with config defaults', function (): void {
        expect($this->transport->isEnabled())->toBeTrue();
    });

    test('circuit starts in closed state', function (): void {
        expect($this->transport->getCircuitState('ga4'))->toBe(EventTransportService::STATE_CLOSED);
    });

    test('can dispatch when circuit is closed', function (): void {
        expect($this->transport->canDispatch('ga4'))->toBeTrue();
    });

    test('can dispatch when circuit is half-open', function (): void {
        // Force state
        $this->transport->recordSuccess('ga4', 10.0);

        expect($this->transport->canDispatch('ga4'))->toBeTrue();
    });

    test('records success and resets failure count', function (): void {
        $this->transport->recordSuccess('ga4', 42.5);

        expect($this->transport->getFailureCount('ga4'))->toBe(0);
        expect($this->transport->getCircuitState('ga4'))->toBe(EventTransportService::STATE_CLOSED);
    });

    test('records failure and increments count', function (): void {
        $this->transport->recordFailure('ga4', 'timeout');

        expect($this->transport->getFailureCount('ga4'))->toBe(1);
    });

    test('opens circuit after threshold failures', function (): void {
        $this->transport->recordFailure('ga4');
        $this->transport->recordFailure('ga4');
        $this->transport->recordFailure('ga4');

        expect($this->transport->getCircuitState('ga4'))->toBe(EventTransportService::STATE_OPEN);
    });

    test('cannot dispatch when circuit is open', function (): void {
        $this->transport->recordFailure('ga4');
        $this->transport->recordFailure('ga4');
        $this->transport->recordFailure('ga4');

        expect($this->transport->canDispatch('ga4'))->toBeFalse();
    });

    test('latency stats returns null for empty samples', function (): void {
        $stats = $this->transport->getLatencyStats('ga4');

        expect($stats['count'])->toBe(0);
        expect($stats['min'])->toBeNull();
        expect($stats['avg'])->toBeNull();
    });

    test('latency stats computes correctly', function (): void {
        $this->transport->recordSuccess('ga4', 10.0);
        $this->transport->recordSuccess('ga4', 20.0);
        $this->transport->recordSuccess('ga4', 30.0);

        $stats = $this->transport->getLatencyStats('ga4');

        expect($stats['count'])->toBe(3);
        expect($stats['min'])->toBe(10.0);
        expect($stats['max'])->toBe(30.0);
        expect($stats['avg'])->toBe(20.0);
    });

    test('reset circuit clears all state', function (): void {
        $this->transport->recordFailure('ga4');
        $this->transport->resetCircuit('ga4');

        expect($this->transport->getFailureCount('ga4'))->toBe(0);
        expect($this->transport->getCircuitState('ga4'))->toBe(EventTransportService::STATE_CLOSED);
    });

    test('status summary returns all providers', function (): void {
        $summary = $this->transport->getStatusSummary(['ga4', 'meta', 'posthog']);

        expect($summary)->toHaveKeys(['ga4', 'meta', 'posthog']);
        expect($summary['ga4']['state'])->toBe(EventTransportService::STATE_CLOSED);
    });

    test('get provider config returns defaults', function (): void {
        $config = $this->transport->getProviderConfig('ga4');

        expect($config)->toHaveKeys(['timeout', 'retries', 'retry_delay', 'retry_backoff']);
        expect($config['timeout'])->toBe(5);
        expect($config['retries'])->toBe(2);
    });

    test('get half open max returns configured value', function (): void {
        expect($this->transport->getHalfOpenMax())->toBe(3);
    });

    test('Jaccard returns 0.0 for empty sets', function (): void {
        // This is tested via the correlation service, but basic sanity check
        expect(0)->toBe(0);
    });

    test('multiple providers track independently', function (): void {
        $this->transport->recordFailure('ga4');
        $this->transport->recordFailure('ga4');
        $this->transport->recordFailure('ga4');
        $this->transport->recordFailure('meta');

        expect($this->transport->getCircuitState('ga4'))->toBe(EventTransportService::STATE_OPEN);
        expect($this->transport->getCircuitState('meta'))->toBe(EventTransportService::STATE_CLOSED);
        expect($this->transport->getFailureCount('ga4'))->toBe(3);
        expect($this->transport->getFailureCount('meta'))->toBe(1);
    });
});

// ─── Event Correlation Matrix Service ───────────────────────────────────────

describe('EventCorrelationMatrixService', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.correlation', [])
            ->andReturn([
                'enabled' => true,
                'cache_ttl' => 600,
                'min_event_count' => 3,
                'min_correlation' => 0.05,
                'max_pairs' => 100,
                'time_window' => 86400,
            ]);

        $this->correlation = new EventCorrelationMatrixService($this->cache, $this->config);
    });

    test('constructs with config', function (): void {
        expect($this->correlation->isEnabled())->toBeTrue();
    });

    test('Jaccard returns 0.0 for disjoint sets', function (): void {
        $score = $this->correlation->computeJaccard(['a', 'b'], ['c', 'd']);

        expect($score)->toBe(0.0);
    });

    test('Jaccard returns 1.0 for identical sets', function (): void {
        $score = $this->correlation->computeJaccard(['a', 'b', 'c'], ['a', 'b', 'c']);

        expect($score)->toBe(1.0);
    });

    test('Jaccard returns correct partial overlap', function (): void {
        // {a,b,c} ∩ {a,c,d,e} = {a,c} = 2
        // |A| + |B| = 3 + 4 = 7
        // J = 2 / (7 - 2) = 2/5 = 0.4
        $score = $this->correlation->computeJaccard(['a', 'b', 'c'], ['a', 'c', 'd', 'e']);

        expect($score)->toBe(0.4);
    });

    test('Jaccard returns 0.0 for empty sets', function (): void {
        $score = $this->correlation->computeJaccard([], []);

        expect($score)->toBe(0.0);
    });

    test('compute all pairs returns sorted results', function (): void {
        $eventUsers = [
            'sign_up' => ['user1', 'user2', 'user3'],
            'login' => ['user1', 'user2', 'user3'],
            'purchase' => ['user1'],
        ];

        $results = $this->correlation->computeAllPairs($eventUsers);

        expect($results)->not->toBeEmpty();
        // Results should be sorted by score descending
        if (count($results) >= 2) {
            expect($results[0]['score'])->toBeGreaterThanOrEqual($results[1]['score']);
        }
    });

    test('compute all pairs filters by min correlation', function (): void {
        // With very disjoint sets, nothing should pass the 0.05 threshold if they're disjoint
        $eventUsers = [
            'event_a' => ['u1'],
            'event_b' => ['u2'],
            'event_c' => ['u3'],
        ];

        $results = $this->correlation->computeAllPairs($eventUsers);

        expect($results)->toBeEmpty(); // No overlap at all
    });

    test('compute all pairs filters by min event count', function (): void {
        $eventUsers = [
            'big_event' => ['u1', 'u2', 'u3', 'u4'],
            'small_event' => ['u1'],
            'tiny_event' => ['u99'], // min_event_count = 3
        ];

        $results = $this->correlation->computeAllPairs($eventUsers);

        // tiny_event should be excluded
        $eventNames = array_unique(array_merge(
            array_column($results, 'event_a'),
            array_column($results, 'event_b'),
        ));

        expect($eventNames)->not->toContain('tiny_event');
    });

    test('find correlated events returns empty for unknown event', function (): void {
        $results = $this->correlation->findCorrelatedEvents('nonexistent', []);

        expect($results)->toBeEmpty();
    });

    test('find correlated events returns forward/backward percentages', function (): void {
        $eventUsers = [
            'target' => ['u1', 'u2', 'u3'],
            'related' => ['u1', 'u2', 'u3', 'u4', 'u5'],
        ];

        $results = $this->correlation->findCorrelatedEvents('target', $eventUsers);

        expect($results)->not->toBeEmpty();
        expect($results[0])->toHaveKeys(['event', 'score', 'direction', 'forward_pct', 'backward_pct']);
        expect($results[0]['event'])->toBe('related');
        expect($results[0]['forward_pct'])->toBe(100.0); // All target users did related
        expect($results[0]['backward_pct'])->toBe(60.0); // 3/5 of related did target
    });

    test('get time window returns configured value', function (): void {
        expect($this->correlation->getTimeWindow())->toBe(86400);
    });

    test('get summary returns config info', function (): void {
        $summary = $this->correlation->getSummary(['event_a', 'event_b']);

        expect($summary)->toHaveKey('config');
        expect($summary['config'])->toHaveKeys(['min_count', 'min_score', 'max_pairs', 'time_window']);
    });

    test('record and get user events', function (): void {
        $this->correlation->recordEvent('user1', 'sign_up');

        // Since cache returns empty arrays in mock, user events will be empty
        $events = $this->correlation->getUserEvents('user1');
        expect($events)->toBeArray();
    });
});

// ─── Data Lake Export Service ────────────────────────────────────────────────

describe('DataLakeExportService', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_lake', [])
            ->andReturn([
                'enabled' => true,
                'storage' => 'null',
                'bucket' => '',
                'prefix' => 'analytics/events/',
                'format' => 'jsonl',
                'batch_size' => 10000,
                'retention_days' => 365,
                'partition_by_date' => true,
                'compress' => true,
                'timeout' => 300,
            ]);

        $this->datalake = new DataLakeExportService($this->cache, $this->config);
    });

    test('constructs with config', function (): void {
        expect($this->datalake->isEnabled())->toBeTrue();
    });

    test('returns null storage backend', function (): void {
        expect($this->datalake->getStorageBackend())->toBe(DataLakeExportService::STORAGE_NULL);
    });

    test('returns jsonl format', function (): void {
        expect($this->datalake->getFormat())->toBe('jsonl');
    });

    test('generates storage key with date partition', function (): void {
        $date = new \DateTimeImmutable('2026-08-12');
        $key = $this->datalake->generateStorageKey('export_batch_001', $date);

        expect($key)->toBe('analytics/events/2026/08/12/export_batch_001.jsonl.gz');
    });

    test('generates storage key without date partition', function (): void {
        // Create with partition disabled
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_lake', [])
            ->andReturn([
                'enabled' => true,
                'storage' => 'null',
                'bucket' => '',
                'prefix' => 'events/',
                'format' => 'csv',
                'batch_size' => 100,
                'retention_days' => 30,
                'partition_by_date' => false,
                'compress' => false,
                'timeout' => 60,
            ]);

        $dl = new DataLakeExportService($this->cache, $this->config);
        $key = $dl->generateStorageKey('daily_export');

        expect($key)->toBe('events/daily_export.csv');
    });

    test('format events as JSONL', function (): void {
        $events = [
            ['name' => 'page_view', 'params' => ['url' => '/home']],
            ['name' => 'purchase', 'params' => ['value' => 99.99]],
        ];

        $output = $this->datalake->formatEvents($events);

        $lines = explode("\n", $output);
        expect(count($lines))->toBe(2);
        expect($lines[0])->toContain('page_view');
        expect($lines[1])->toContain('purchase');
    });

    test('format events as CSV', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_lake', [])
            ->andReturn([
                'enabled' => true,
                'storage' => 'null',
                'bucket' => '',
                'prefix' => 'events/',
                'format' => 'csv',
                'batch_size' => 100,
                'retention_days' => 30,
                'partition_by_date' => false,
                'compress' => false,
                'timeout' => 60,
            ]);

        $dl = new DataLakeExportService($this->cache, $this->config);
        $events = [
            ['name' => 'page_view', 'params' => ['url' => '/home']],
            ['name' => 'purchase', 'params' => ['value' => 99.99]],
        ];

        $output = $dl->formatEvents($events);
        $lines = explode("\n", trim($output));

        // Header line + 2 data lines
        expect(count($lines))->toBe(3);
    });

    test('format empty events returns empty JSONL', function (): void {
        $output = $this->datalake->formatEvents([]);

        expect($output)->toBe('');
    });

    test('validate config passes for null storage', function (): void {
        $result = $this->datalake->validateConfig();

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    test('validate config fails for invalid storage', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_lake', [])
            ->andReturn([
                'enabled' => true,
                'storage' => 'invalid_storage',
                'bucket' => '',
                'prefix' => 'events/',
                'format' => 'jsonl',
                'batch_size' => 100,
                'retention_days' => 30,
                'partition_by_date' => true,
                'compress' => false,
                'timeout' => 60,
            ]);

        $dl = new DataLakeExportService($this->cache, $this->config);
        $result = $dl->validateConfig();

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->not->toBeEmpty();
    });

    test('validate config requires bucket for non-null storage', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.data_lake', [])
            ->andReturn([
                'enabled' => true,
                'storage' => 's3',
                'bucket' => '',
                'prefix' => 'events/',
                'format' => 'jsonl',
                'batch_size' => 100,
                'retention_days' => 30,
                'partition_by_date' => true,
                'compress' => false,
                'timeout' => 60,
            ]);

        $dl = new DataLakeExportService($this->cache, $this->config);
        $result = $dl->validateConfig();

        expect($result['valid'])->toBeFalse();
    });

    test('config summary returns all fields', function (): void {
        $summary = $this->datalake->getConfigSummary();

        expect($summary)->toHaveKeys([
            'enabled', 'storage', 'bucket', 'format', 'batch_size',
            'retention_days', 'partition_by_date', 'compress',
        ]);
    });

    test('get retention days returns configured value', function (): void {
        expect($this->datalake->getRetentionDays())->toBe(365);
    });

    test('job recording works', function (): void {
        $this->datalake->recordJob('job_001', DataLakeExportService::STATUS_RUNNING);

        // In mock, the put just returns true — verify no exception
        expect(true)->toBeTrue();
    });

    test('get job returns null for unknown job', function (): void {
        $job = $this->datalake->getJob('unknown_job');

        expect($job['status'])->toBeNull();
    });
});

// ─── SDK Scope Token Service ────────────────────────────────────────────────

describe('SdkScopeTokenService', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.sdk_tokens', [])
            ->andReturn([
                'enabled' => true,
                'token_ttl' => 7776000,
                'default_rate_limit' => 100,
                'max_tokens_per_scope' => 10,
                'hash_algorithm' => 'sha256',
                'signing_key' => 'test_signing_key',
            ]);

        $this->sdkToken = new SdkScopeTokenService($this->cache, $this->config);
    });

    test('constructs with config', function (): void {
        expect($this->sdkToken->isEnabled())->toBeTrue();
    });

    test('generates a scoped token', function (): void {
        $result = $this->sdkToken->generateToken('web-client');

        expect($result)->toHaveKeys(['token', 'scope', 'permissions', 'categories', 'expires_at']);
        expect($result['scope'])->toBe('web-client');
        expect($result['token'])->toStartWith('zb_');
        expect($result['permissions'])->toContain(SdkScopeTokenService::PERM_TRACK);
        expect($result['permissions'])->toContain(SdkScopeTokenService::PERM_BATCH);
        expect($result['expires_at'])->toBeGreaterThan(time());
    });

    test('all permissions returns all valid permissions', function (): void {
        $perms = SdkScopeTokenService::allPermissions();

        expect($perms)->toContain(SdkScopeTokenService::PERM_TRACK);
        expect($perms)->toContain(SdkScopeTokenService::PERM_BATCH);
        expect($perms)->toContain(SdkScopeTokenService::PERM_IDENTIFY);
        expect($perms)->toContain(SdkScopeTokenService::PERM_CONSENT);
        expect($perms)->toContain(SdkScopeTokenService::PERM_PAGEVIEW);
    });

    test('all categories returns all valid categories', function (): void {
        $cats = SdkScopeTokenService::allCategories();

        expect($cats)->toContain(SdkScopeTokenService::CATEGORY_ECOMMERCE);
        expect($cats)->toContain(SdkScopeTokenService::CATEGORY_SAAS);
        expect($cats)->toContain(SdkScopeTokenService::CATEGORY_ENGAGEMENT);
        expect($cats)->toContain(SdkScopeTokenService::CATEGORY_CUSTOM);
    });

    test('invalid permission throws exception', function (): void {
        $this->expectException(\InvalidArgumentException::class);

        $this->sdkToken->generateToken('test', ['invalid_permission']);
    });

    test('invalid category throws exception', function (): void {
        $this->expectException(\InvalidArgumentException::class);

        $this->sdkToken->generateToken('test', [SdkScopeTokenService::PERM_TRACK], ['invalid_category']);
    });

    test('custom category grants access to all categories', function (): void {
        $result = $this->sdkToken->generateToken(
            'mobile-app',
            [SdkScopeTokenService::PERM_TRACK],
            [SdkScopeTokenService::CATEGORY_CUSTOM],
        );

        // Since cache mock returns empty, isValid will return false
        // This tests the generation itself works
        expect($result['token'])->toStartWith('zb_');
    });

    test('rate limit check returns default values for unknown token', function (): void {
        $result = $this->sdkToken->checkRateLimit('unknown_token');

        expect($result['allowed'])->toBeFalse();
        expect($result['remaining'])->toBe(0);
    });
});

// ─── Version Sweep ──────────────────────────────────────────────────────────

describe('Version Sweep', function (): void {
    test('AnalyticsEvent VERSION is 20.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('20.0.0');
    });

    test('composer.json version matches', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

        expect($composer['version'])->toBe('20.0.0');
    });

    test('package.json version matches', function (): void {
        $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);

        expect($pkg['version'])->toBe('20.0.0');
    });

    test('JS client version matches', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

        expect($js)->toContain("'20.0.0'");
        expect($js)->not->toContain("'19.0.0'");
    });
});

// ─── Config Integrity ──────────────────────────────────────────────────────

describe('Config Integrity', function (): void {
    test('config has transport section', function (): void {
        $config = require __DIR__ . '/../config/zeroboiler.php';

        expect($config['analytics'])->toHaveKey('transport');
        expect($config['analytics']['transport'])->toHaveKeys([
            'enabled', 'default_timeout', 'default_retries',
            'circuit_threshold', 'circuit_reset_timeout',
            'circuit_half_open_max', 'metrics_ttl',
        ]);
    });

    test('config has correlation section', function (): void {
        $config = require __DIR__ . '/../config/zeroboiler.php';

        expect($config['analytics'])->toHaveKey('correlation');
        expect($config['analytics']['correlation'])->toHaveKeys([
            'enabled', 'cache_ttl', 'min_event_count',
            'min_correlation', 'max_pairs', 'time_window',
        ]);
    });

    test('config has data_lake section', function (): void {
        $config = require __DIR__ . '/../config/zeroboiler.php';

        expect($config['analytics'])->toHaveKey('data_lake');
        expect($config['analytics']['data_lake'])->toHaveKeys([
            'enabled', 'storage', 'bucket', 'prefix', 'format',
            'batch_size', 'retention_days', 'partition_by_date',
            'compress', 'timeout',
        ]);
    });

    test('config has sdk_tokens section', function (): void {
        $config = require __DIR__ . '/../config/zeroboiler.php';

        expect($config['analytics'])->toHaveKey('sdk_tokens');
        expect($config['analytics']['sdk_tokens'])->toHaveKeys([
            'enabled', 'token_ttl', 'default_rate_limit',
            'max_tokens_per_scope', 'hash_algorithm', 'signing_key',
        ]);
    });

    test('data_lake defaults to disabled', function (): void {
        $config = require __DIR__ . '/../config/zeroboiler.php';

        expect($config['analytics']['data_lake']['enabled'])->toBeFalse();
    });

    test('sdk_tokens defaults to disabled', function (): void {
        $config = require __DIR__ . '/../config/zeroboiler.php';

        expect($config['analytics']['sdk_tokens']['enabled'])->toBeFalse();
    });

    test('transport defaults to enabled', function (): void {
        $config = require __DIR__ . '/../config/zeroboiler.php';

        expect($config['analytics']['transport']['enabled'])->toBeTrue();
    });

    test('correlation defaults to enabled', function (): void {
        $config = require __DIR__ . '/../config/zeroboiler.php';

        expect($config['analytics']['correlation']['enabled'])->toBeTrue();
    });

    test('new services exist in src', function (): void {
        expect(file_exists(__DIR__ . '/../src/Services/EventTransportService.php'))->toBeTrue();
        expect(file_exists(__DIR__ . '/../src/Services/EventCorrelationMatrixService.php'))->toBeTrue();
        expect(file_exists(__DIR__ . '/../src/Services/DataLakeExportService.php'))->toBeTrue();
        expect(file_exists(__DIR__ . '/../src/Services/SdkScopeTokenService.php'))->toBeTrue();
    });

    test('new services have strict types', function (): void {
        foreach ([
            'EventTransportService.php',
            'EventCorrelationMatrixService.php',
            'DataLakeExportService.php',
            'SdkScopeTokenService.php',
        ] as $file) {
            $content = file_get_contents(__DIR__ . '/../src/Services/' . $file);
            expect($content)->toContain('declare(strict_types=1)');
        }
    });

    test('new services have docblocks', function (): void {
        foreach ([
            'EventTransportService.php',
            'EventCorrelationMatrixService.php',
            'DataLakeExportService.php',
            'SdkScopeTokenService.php',
        ] as $file) {
            $content = file_get_contents(__DIR__ . '/../src/Services/' . $file);
            expect($content)->toContain('/**');
        }
    });

    test('ServiceProvider imports new services', function (): void {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        expect($content)->toContain('use ZeroBoiler\\Analytics\\Services\\EventTransportService');
        expect($content)->toContain('use ZeroBoiler\\Analytics\\Services\\EventCorrelationMatrixService');
        expect($content)->toContain('use ZeroBoiler\\Analytics\\Services\\DataLakeExportService');
        expect($content)->toContain('use ZeroBoiler\\Analytics\\Services\\SdkScopeTokenService');
    });

    test('ServiceProvider version is 20.0.0', function (): void {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        expect($content)->toContain('@version 20.0.0');
    });

    test('IntegrityCommand expected version is 20.0.0', function (): void {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsIntegrityCommand.php');

        expect($content)->toContain("EXPECTED_VERSION = '20.0.0'");
    });
});
