<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventSchemaRuntimeValidator;
use ZeroBoiler\Analytics\Services\EventFingerprintService;
use ZeroBoiler\Analytics\Services\ComposableEnrichmentPipeline;
use ZeroBoiler\Analytics\Services\AnalyticsAuditLogService;
use ZeroBoiler\Analytics\Services\ProviderEventCompatibilityMatrix;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

beforeEach(function (): void {
    $this->registry = new EventSchemaRegistry;
});

describe('EventSchemaRuntimeValidator', function (): void {
    it('returns valid for disabled validator', function (): void {
        $validator = new EventSchemaRuntimeValidator(
            $this->registry,
            ['enabled' => false, 'mode' => 'warn', 'enforce_catalog_membership' => true],
        );

        $event = new AnalyticsEvent(name: 'nonexistent_event', params: []);
        $result = $validator->validate($event);

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
        expect($result['score'])->toBe(1.0);
    });

    it('detects events not in catalog when enforcement is on', function (): void {
        $validator = new EventSchemaRuntimeValidator(
            $this->registry,
            ['enabled' => true, 'mode' => 'strict', 'enforce_catalog_membership' => true],
        );

        $event = new AnalyticsEvent(name: 'fake_event_xyz', params: []);
        $result = $validator->validate($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toContain("Event 'fake_event_xyz' is not registered in the event catalog");
    });

    it('allows non-catalog events in warn mode', function (): void {
        $validator = new EventSchemaRuntimeValidator(
            $this->registry,
            ['enabled' => true, 'mode' => 'warn', 'enforce_catalog_membership' => true],
        );

        $event = new AnalyticsEvent(name: 'custom_event', params: []);
        $result = $validator->validate($event);

        expect($result['valid'])->toBeTrue();
        expect($result['warnings'])->toContain("Event 'custom_event' is not registered in the event catalog");
    });

    it('skips catalog enforcement when disabled', function (): void {
        $validator = new EventSchemaRuntimeValidator(
            $this->registry,
            ['enabled' => true, 'mode' => 'strict', 'enforce_catalog_membership' => false],
        );

        $event = new AnalyticsEvent(name: 'custom_event', params: []);
        $result = $validator->validate($event);

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    it('validates event name format', function (): void {
        $validator = new EventSchemaRuntimeValidator(
            $this->registry,
            ['enabled' => true, 'mode' => 'strict', 'enforce_catalog_membership' => false],
        );

        $event = new AnalyticsEvent(name: 'Invalid-Name!', params: []);
        $result = $validator->validate($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toContain("Event name 'Invalid-Name!' contains invalid characters");
    });

    it('validates catalog events correctly', function (): void {
        $validator = new EventSchemaRuntimeValidator(
            $this->registry,
            ['enabled' => true, 'mode' => 'strict', 'enforce_catalog_membership' => true],
        );

        $event = new AnalyticsEvent(name: 'page_view', params: ['page_title' => 'Home']);
        $result = $validator->validate($event);

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
        expect($result['score'])->toBe(1.0);
    });

    it('batch validates multiple events', function (): void {
        $validator = new EventSchemaRuntimeValidator(
            $this->registry,
            ['enabled' => true, 'mode' => 'strict', 'enforce_catalog_membership' => false],
        );

        $events = [
            new AnalyticsEvent(name: 'valid_event', params: []),
            new AnalyticsEvent(name: 'bad name!', params: []),
            new AnalyticsEvent(name: 'another_valid', params: []),
        ];

        $result = $validator->validateBatch($events);

        expect($result['valid'])->toBeFalse();
        expect($result['total'])->toBe(3);
        expect($result['passed'])->toBe(2);
        expect($result['failed'])->toBe(1);
        expect(count($result['results']))->toBe(3);
    });

    it('reports mode correctly', function (): void {
        $strict = new EventSchemaRuntimeValidator($this->registry, ['enabled' => true, 'mode' => 'strict', 'enforce_catalog_membership' => true]);
        $warn = new EventSchemaRuntimeValidator($this->registry, ['enabled' => true, 'mode' => 'warn', 'enforce_catalog_membership' => true]);

        expect($strict->mode())->toBe('strict');
        expect($warn->mode())->toBe('warn');
    });
});

describe('EventFingerprintService', function (): void {
    it('generates consistent fingerprints for identical events', function (): void {
        $service = new EventFingerprintService([
            'time_bucket_seconds' => 0,
            'include_client_id' => true,
            'include_user_id' => true,
            'ignore_internal_params' => false,
            'algorithm' => 'xxh128',
            'max_cache_size' => 100,
        ]);

        $event1 = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99], clientId: 'abc', userId: '123');
        $event2 = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99], clientId: 'abc', userId: '123');

        expect($service->fingerprint($event1))->toBe($service->fingerprint($event2));
    });

    it('generates different fingerprints for different events', function (): void {
        $service = new EventFingerprintService([
            'time_bucket_seconds' => 0,
            'include_client_id' => true,
            'include_user_id' => true,
            'ignore_internal_params' => false,
            'algorithm' => 'xxh128',
            'max_cache_size' => 100,
        ]);

        $event1 = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);
        $event2 = new AnalyticsEvent(name: 'purchase', params: ['value' => 49.99]);

        expect($service->fingerprint($event1))->not->toBe($service->fingerprint($event2));
    });

    it('generates idempotency keys with prefix', function (): void {
        $service = new EventFingerprintService([
            'time_bucket_seconds' => 0,
            'include_client_id' => false,
            'include_user_id' => false,
            'ignore_internal_params' => false,
            'algorithm' => 'sha256',
            'max_cache_size' => 100,
        ]);

        $event = new AnalyticsEvent(name: 'page_view', params: []);
        $key = $service->idempotencyKey($event);

        expect($key)->toStartWith('zb_idem_');
        expect(strlen($key))->toBeGreaterThan(40);
    });

    it('detects same events correctly', function (): void {
        $service = new EventFingerprintService([
            'time_bucket_seconds' => 0,
            'include_client_id' => false,
            'include_user_id' => false,
            'ignore_internal_params' => false,
            'algorithm' => 'xxh128',
            'max_cache_size' => 100,
        ]);

        $a = new AnalyticsEvent(name: 'click', params: ['button' => 'submit']);
        $b = new AnalyticsEvent(name: 'click', params: ['button' => 'submit']);
        $c = new AnalyticsEvent(name: 'click', params: ['button' => 'cancel']);

        expect($service->isSameEvent($a, $b))->toBeTrue();
        expect($service->isSameEvent($a, $c))->toBeFalse();
    });

    it('generates partial fingerprints', function (): void {
        $service = new EventFingerprintService([
            'time_bucket_seconds' => 0,
            'include_client_id' => false,
            'include_user_id' => false,
            'ignore_internal_params' => false,
            'algorithm' => 'xxh128',
            'max_cache_size' => 100,
        ]);

        $fp = $service->partialFingerprint('purchase', ['user_id' => '123']);

        expect($fp)->toBeString();
        expect(strlen($fp))->toBeGreaterThan(0);
    });

    it('ignores internal params when configured', function (): void {
        $serviceWithIgnore = new EventFingerprintService([
            'time_bucket_seconds' => 0,
            'include_client_id' => false,
            'include_user_id' => false,
            'ignore_internal_params' => true,
            'algorithm' => 'xxh128',
            'max_cache_size' => 100,
        ]);

        $serviceWithoutIgnore = new EventFingerprintService([
            'time_bucket_seconds' => 0,
            'include_client_id' => false,
            'include_user_id' => false,
            'ignore_internal_params' => false,
            'algorithm' => 'xxh128',
            'max_cache_size' => 100,
        ]);

        $event = new AnalyticsEvent(name: 'click', params: ['button' => 'submit', '_internal' => 'data']);

        expect($serviceWithIgnore->fingerprint($event))->not->toBe($serviceWithoutIgnore->fingerprint($event));
    });

    it('manages cache correctly', function (): void {
        $service = new EventFingerprintService([
            'time_bucket_seconds' => 0,
            'include_client_id' => false,
            'include_user_id' => false,
            'ignore_internal_params' => false,
            'algorithm' => 'xxh128',
            'max_cache_size' => 100,
        ]);

        $event = new AnalyticsEvent(name: 'page_view', params: []);
        $service->fingerprint($event);

        expect($service->cacheSize())->toBe(1);

        $service->clearCache();
        expect($service->cacheSize())->toBe(0);
    });
});

describe('ComposableEnrichmentPipeline', function (): void {
    it('returns event unchanged when disabled', function (): void {
        $config = mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.enrichment_pipeline', [])
            ->andReturn(['enabled' => false]);

        $pipeline = new ComposableEnrichmentPipeline($config);

        $event = new AnalyticsEvent(name: 'click', params: ['button' => 'submit']);
        $enriched = $pipeline->enrich($event);

        expect($enriched->name)->toBe('click');
        expect($enriched->params)->toBe(['button' => 'submit']);
    });

    it('returns all enabled stage names', function (): void {
        $config = mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.enrichment_pipeline', [])
            ->andReturn([
                'enabled' => true,
                'stages' => [
                    ['stage' => 'source_tag', 'enabled' => true, 'priority' => 10, 'config' => []],
                    ['stage' => 'pii_scrub', 'enabled' => false, 'priority' => 20, 'config' => []],
                ],
            ]);

        $pipeline = new ComposableEnrichmentPipeline($config);

        $enabled = $pipeline->enabledStages();
        expect($enabled)->toContain('source_tag');
        expect($enabled)->not->toContain('pii_scrub');
    });

    it('reports enabled state', function (): void {
        $config = mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.enrichment_pipeline', [])
            ->andReturn(['enabled' => true]);

        $pipeline = new ComposableEnrichmentPipeline($config);
        expect($pipeline->isEnabled())->toBeTrue();
    });

    it('batch enriches events', function (): void {
        $config = mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.enrichment_pipeline', [])
            ->andReturn(['enabled' => false]);

        $pipeline = new ComposableEnrichmentPipeline($config);

        $events = [
            new AnalyticsEvent(name: 'click', params: []),
            new AnalyticsEvent(name: 'page_view', params: []),
        ];

        $enriched = $pipeline->enrichBatch($events);
        expect(count($enriched))->toBe(2);
    });

    it('supports custom handler registration', function (): void {
        $config = mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.enrichment_pipeline', [])
            ->andReturn([
                'enabled' => true,
                'stages' => [
                    ['stage' => 'custom_stage', 'enabled' => true, 'priority' => 50, 'config' => ['tag' => 'custom_value']],
                ],
            ]);

        $pipeline = new ComposableEnrichmentPipeline($config);

        $pipeline->registerHandler('custom_stage', function (AnalyticsEvent $event, array $cfg): AnalyticsEvent {
            return new AnalyticsEvent(
                name: $event->name,
                params: array_merge($event->params, ['custom_tag' => $cfg['tag'] ?? 'default']),
                clientId: $event->clientId,
                userId: $event->userId,
                timestamp: $event->timestamp,
                priority: $event->priority,
                source: $event->source,
            );
        });

        $event = new AnalyticsEvent(name: 'test', params: []);
        $enriched = $pipeline->enrich($event);

        expect($enriched->params['custom_tag'])->toBe('custom_value');
    });

    it('enriches with timestamp normalization', function (): void {
        $config = mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.enrichment_pipeline', [])
            ->andReturn([
                'enabled' => true,
                'stages' => [
                    ['stage' => 'timestamp_normalize', 'enabled' => true, 'priority' => 30, 'config' => ['param_name' => '_ts']],
                ],
            ]);

        $pipeline = new ComposableEnrichmentPipeline($config);

        $event = new AnalyticsEvent(name: 'click', params: []);
        $enriched = $pipeline->enrich($event);

        expect($enriched->params)->toHaveKey('_ts');
        expect($enriched->params['_ts'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    });

    it('enriches with identity link', function (): void {
        $config = mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.enrichment_pipeline', [])
            ->andReturn([
                'enabled' => true,
                'stages' => [
                    ['stage' => 'identity_link', 'enabled' => true, 'priority' => 40, 'config' => []],
                ],
            ]);

        $pipeline = new ComposableEnrichmentPipeline($config);

        $event = new AnalyticsEvent(name: 'purchase', params: [], clientId: 'client_abc', userId: 'user_123');
        $enriched = $pipeline->enrich($event);

        expect($enriched->params['client_id'])->toBe('client_abc');
        expect($enriched->params['user_id'])->toBe('user_123');
        expect($enriched->params['has_identity'])->toBeTrue();
    });

    it('scrubs PII from event parameters', function (): void {
        $config = mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.enrichment_pipeline', [])
            ->andReturn([
                'enabled' => true,
                'stages' => [
                    ['stage' => 'pii_scrub', 'enabled' => true, 'priority' => 100, 'config' => [
                        'mode' => 'remove',
                        'pii_keys' => ['email', 'password'],
                    ]],
                ],
            ]);

        $pipeline = new ComposableEnrichmentPipeline($config);

        $event = new AnalyticsEvent(name: 'sign_up', params: [
            'email' => 'user@example.com',
            'password' => 'secret123',
            'name' => 'John',
        ]);
        $enriched = $pipeline->enrich($event);

        expect($enriched->params)->not->toHaveKey('email');
        expect($enriched->params)->not->toHaveKey('password');
        expect($enriched->params['name'])->toBe('John');
    });
});

describe('AnalyticsAuditLogService', function (): void {
    it('returns empty query when disabled', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->with('zb_audit_index', [])->andReturn([]);

        $service = new AnalyticsAuditLogService($cache, ['enabled' => false]);

        expect($service->isEnabled())->toBeFalse();
        expect($service->query()['entries'])->toBeEmpty();
    });

    it('records entries when enabled', function (): void {
        $entries = [];
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->with('zb_audit_index', [])->andReturn([]);
        $cache->shouldReceive('put')->andReturnTrue();

        $service = new AnalyticsAuditLogService($cache, [
            'enabled' => true,
            'retention_days' => 90,
            'max_entries' => 100,
            'log_success' => true,
            'log_failures' => true,
            'excluded_events' => [],
            'excluded_categories' => [],
        ]);

        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99], clientId: 'abc', userId: '123');
        $service->record($event, [
            'providers' => ['ga4' => true, 'meta' => true],
            'pipeline_passed' => true,
            'enriched' => true,
            'validation_score' => 1.0,
        ]);

        $stats = $service->stats();
        expect($stats['total_entries'])->toBeGreaterThanOrEqual(1);
    });

    it('excludes configured events from audit log', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->with('zb_audit_index', [])->andReturn([]);

        $service = new AnalyticsAuditLogService($cache, [
            'enabled' => true,
            'retention_days' => 90,
            'max_entries' => 100,
            'log_success' => true,
            'log_failures' => true,
            'excluded_events' => ['page_view', 'scroll_depth'],
            'excluded_categories' => [],
        ]);

        $event = new AnalyticsEvent(name: 'page_view', params: []);
        $service->record($event, [
            'providers' => ['ga4' => true],
            'pipeline_passed' => true,
            'enriched' => true,
            'validation_score' => 1.0,
        ]);

        // page_view should be excluded, so no new entries
        $stats = $service->stats();
        expect($stats['total_entries'])->toBe(0);
    });

    it('respects log_success and log_failures settings', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->with('zb_audit_index', [])->andReturn([]);

        $service = new AnalyticsAuditLogService($cache, [
            'enabled' => true,
            'retention_days' => 90,
            'max_entries' => 100,
            'log_success' => false,
            'log_failures' => true,
            'excluded_events' => [],
            'excluded_categories' => [],
        ]);

        // Successful event should NOT be logged
        $successEvent = new AnalyticsEvent(name: 'purchase', params: []);
        $service->record($successEvent, [
            'providers' => ['ga4' => true],
            'pipeline_passed' => true,
            'enriched' => true,
            'validation_score' => 1.0,
        ]);

        // Failed event SHOULD be logged
        $failEvent = new AnalyticsEvent(name: 'purchase', params: []);
        $service->record($failEvent, [
            'providers' => ['ga4' => false],
            'pipeline_passed' => false,
            'enriched' => true,
            'validation_score' => 0.5,
        ]);

        $stats = $service->stats();
        expect($stats['total_entries'])->toBe(1);
    });

    it('clears audit log', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->with('zb_audit_index', [])->andReturn(['key1', 'key2']);
        $cache->shouldReceive('forget')->twice()->andReturnTrue();
        $cache->shouldReceive('put')->andReturnTrue();

        $service = new AnalyticsAuditLogService($cache, [
            'enabled' => true,
            'retention_days' => 90,
            'max_entries' => 100,
            'log_success' => true,
            'log_failures' => true,
            'excluded_events' => [],
            'excluded_categories' => [],
        ]);

        $service->clear();
    });
});

describe('ProviderEventCompatibilityMatrix', function (): void {
    it('analyzes full catalog', function (): void {
        $matrix = new ProviderEventCompatibilityMatrix;
        $result = $matrix->analyze();

        expect($result)->toHaveKey('events');
        expect($result)->toHaveKey('summary');
        expect($result)->toHaveKey('recommendations');
        expect($result['summary']['total_events'])->toBeGreaterThan(0);
        expect($result['summary']['avg_score'])->toBeGreaterThan(0.0);
        expect($result['summary']['avg_score'])->toBeLessThanOrEqual(100.0);
    });

    it('provides per-event scoring', function (): void {
        $matrix = new ProviderEventCompatibilityMatrix;
        $result = $matrix->eventScore('purchase');

        expect($result)->toHaveKey('score');
        expect($result)->toHaveKey('providers');
        expect($result)->toHaveKey('gaps');
        expect($result)->toHaveKey('weighted_breakdown');
        expect($result['providers'])->toHaveKey('ga4');
        expect($result['providers'])->toHaveKey('meta');
    });

    it('returns zero score for unknown events', function (): void {
        $matrix = new ProviderEventCompatibilityMatrix;
        $result = $matrix->eventScore('nonexistent_xyz');

        expect($result['score'])->toBe(0.0);
        expect($result['gaps'])->not->toBeEmpty();
    });

    it('provides category-level scoring', function (): void {
        $matrix = new ProviderEventCompatibilityMatrix;
        $result = $matrix->categoryScore('ecommerce');

        expect($result['category'])->toBe('ecommerce');
        expect($result['total_events'])->toBeGreaterThan(0);
        expect($result['avg_score'])->toBeGreaterThan(0.0);
    });

    it('identifies worst covered events', function (): void {
        $matrix = new ProviderEventCompatibilityMatrix;
        $worst = $matrix->worstCoveredEvents(5);

        expect($worst)->toBeList();
        expect(count($worst))->toBeLessThanOrEqual(5);
        expect($worst[0])->toHaveKey('name');
        expect($worst[0])->toHaveKey('score');
        expect($worst[0])->toHaveKey('gaps');
    });

    it('identifies perfectly covered events', function (): void {
        $matrix = new ProviderEventCompatibilityMatrix;
        $perfect = $matrix->perfectlyCoveredEvents();

        expect($perfect)->toBeList();
        // purchase should have all providers mapped
        $hasPurchase = false;
        foreach ($perfect as $p) {
            if ($p['name'] === 'purchase') {
                $hasPurchase = true;
                break;
            }
        }
        expect($hasPurchase)->toBeTrue();
    });

    it('computes maturity grade', function (): void {
        $matrix = new ProviderEventCompatibilityMatrix;
        $grade = $matrix->maturityGrade();

        expect($grade)->toHaveKey('grade');
        expect($grade)->toHaveKey('score');
        expect($grade)->toHaveKey('description');
        expect($grade['score'])->toBeGreaterThan(0.0);
        expect(in_array($grade['grade'], ['A+', 'A', 'A-', 'B+', 'B', 'C', 'D', 'F'], true))->toBeTrue();
    });

    it('provides provider coverage breakdown', function (): void {
        $matrix = new ProviderEventCompatibilityMatrix;
        $result = $matrix->analyze();

        $coverage = $result['summary']['provider_coverage'];
        expect($coverage)->toHaveKey('ga4');
        expect($coverage)->toHaveKey('meta');
        expect($coverage)->toHaveKey('posthog');
        expect($coverage)->toHaveKey('plausible');
        expect($coverage)->toHaveKey('mixpanel');
        expect($coverage)->toHaveKey('amplitude');

        // GA4 should have 100% coverage (all events have GA4 mapping)
        expect($coverage['ga4'])->toBe(100.0);
    });
});

describe('Version Sweep v21.0.0', function (): void {
    it('AnalyticsEvent version is 21.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('21.0.0');
    });

    it('new service files exist with correct namespace', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Services\EventSchemaRuntimeValidator::class))->toBeTrue();
        expect(class_exists(\ZeroBoiler\Analytics\Services\EventFingerprintService::class))->toBeTrue();
        expect(class_exists(\ZeroBoiler\Analytics\Services\ComposableEnrichmentPipeline::class))->toBeTrue();
        expect(class_exists(\ZeroBoiler\Analytics\Services\AnalyticsAuditLogService::class))->toBeTrue();
        expect(class_exists(\ZeroBoiler\Analytics\Services\ProviderEventCompatibilityMatrix::class))->toBeTrue();
    });

    it('config has new sections', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';

        expect($config['analytics'])->toHaveKey('schema_validation');
        expect($config['analytics'])->toHaveKey('enrichment_pipeline');
        expect($config['analytics'])->toHaveKey('audit_log');
        expect($config['analytics'])->toHaveKey('fingerprinting');

        expect($config['analytics']['schema_validation']['mode'])->toBe('warn');
        expect($config['analytics']['enrichment_pipeline']['enabled'])->toBeTrue();
        expect($config['analytics']['audit_log']['retention_days'])->toBe(90);
        expect($config['analytics']['fingerprinting']['algorithm'])->toBe('xxh128');
    });
});
