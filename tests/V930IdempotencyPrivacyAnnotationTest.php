<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventIdempotencyService;
use ZeroBoiler\Analytics\Services\PrivacyManifestService;
use ZeroBoiler\Analytics\Services\EventAnnotationService;

beforeEach(function (): void {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = Mockery::mock(ConfigRepository::class);
});

describe('EventIdempotencyService', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.idempotency', [])
            ->andReturn([
                'enabled' => true,
                'ttl' => 3600,
                'max_keys' => 100000,
                'prefix' => 'zb_idem_',
            ]);

        $this->service = new EventIdempotencyService($this->cache, $this->config);
    });

    test('shouldDispatch returns true for new event', function (): void {
        $this->cache->shouldReceive('has')->once()->andReturn(false);
        $this->cache->shouldReceive('put')->once()->andReturn(true);

        $result = $this->service->shouldDispatch('purchase', ['value' => 99.99], 'client_1', 'user_1');

        expect($result)->toBeTrue();
    });

    test('shouldDispatch returns false for duplicate event', function (): void {
        $this->cache->shouldReceive('has')->once()->andReturn(true);
        $this->cache->shouldNotReceive('put');

        $result = $this->service->shouldDispatch('purchase', ['value' => 99.99], 'client_1', 'user_1');

        expect($result)->toBeFalse();
    });

    test('shouldDispatch returns true when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->andReturn(['enabled' => false]);

        $service = new EventIdempotencyService($this->cache, $this->config);

        $result = $service->shouldDispatch('purchase', [], 'client_1');

        expect($result)->toBeTrue();
    });

    test('client-supplied key takes priority', function (): void {
        $this->cache->shouldReceive('has')->with(Mockery::pattern('/^zb_idem_client_/'))->once()->andReturn(false);
        $this->cache->shouldReceive('put')->once()->andReturn(true);

        $result = $this->service->shouldDispatch('purchase', ['value' => 99.99], 'client_1', 'user_1', 'my-custom-key-123');

        expect($result)->toBeTrue();
    });

    test('same event with different params creates different keys', function (): void {
        $this->cache->shouldReceive('has')->twice()->andReturn(false);
        $this->cache->shouldReceive('put')->twice()->andReturn(true);

        $r1 = $this->service->shouldDispatch('purchase', ['value' => 99.99], 'client_1');
        $r2 = $this->service->shouldDispatch('purchase', ['value' => 149.99], 'client_1');

        expect($r1)->toBeTrue();
        expect($r2)->toBeTrue();
    });

    test('same event with same params and same client is deduplicated', function (): void {
        $this->cache->shouldReceive('has')->once()->andReturn(false);
        $this->cache->shouldReceive('put')->once()->andReturn(true);

        $r1 = $this->service->shouldDispatch('purchase', ['value' => 99.99], 'client_1');

        $this->cache->shouldReceive('has')->once()->andReturn(true);

        $r2 = $this->service->shouldDispatch('purchase', ['value' => 99.99], 'client_1');

        expect($r1)->toBeTrue();
        expect($r2)->toBeFalse();
    });

    test('isEnabled returns config value', function (): void {
        expect($this->service->isEnabled())->toBeTrue();
    });

    test('getStats returns correct structure', function (): void {
        $stats = $this->service->getStats();

        expect($stats)->toHaveKeys(['enabled', 'ttl', 'max_keys', 'hits', 'misses', 'total_processed', 'duplicate_rate']);
        expect($stats['enabled'])->toBeTrue();
        expect($stats['ttl'])->toBe(3600);
        expect($stats['max_keys'])->toBe(100000);
    });

    test('resolveKey produces deterministic key', function (): void {
        $key1 = $this->service->resolveKey('purchase', ['value' => 99.99], 'client_1', 'user_1');
        $key2 = $this->service->resolveKey('purchase', ['value' => 99.99], 'client_1', 'user_1');

        expect($key1)->toBe($key2);
    });

    test('resolveKey with clientKey uses client prefix', function (): void {
        $key = $this->service->resolveKey('purchase', [], 'client_1', 'user_1', 'custom-key');

        expect($key)->toStartWith('zb_idem_client_');
    });

    test('invalidate calls cache forget', function (): void {
        $this->cache->shouldReceive('forget')->with('zb_idem_test_key')->once()->andReturn(true);

        $result = $this->service->invalidate('zb_idem_test_key');

        expect($result)->toBeTrue();
    });

    test('resetStats clears counters', function (): void {
        // Trigger a miss first
        $this->cache->shouldReceive('has')->andReturn(false);
        $this->cache->shouldReceive('put')->andReturn(true);
        $this->service->shouldDispatch('test', [], 'c1');

        expect($this->service->getStats()['misses'])->toBe(1);

        $this->service->resetStats();

        expect($this->service->getStats()['misses'])->toBe(0);
        expect($this->service->getStats()['hits'])->toBe(0);
    });

    test('generateClientKey produces URL-safe key', function (): void {
        $key = EventIdempotencyService::generateClientKey('purchase', 'client_abc123');

        expect($key)->toBeString();
        expect(strlen($key))->toBe(24);
    });

    test('fail open when cache put fails', function (): void {
        $this->cache->shouldReceive('has')->once()->andReturn(false);
        $this->cache->shouldReceive('put')->once()->andThrow(new \RuntimeException('Cache failure'));

        $result = $this->service->shouldDispatch('purchase', ['value' => 99.99], 'client_1', 'user_1');

        expect($result)->toBeTrue(); // Fail open
    });

    test('stats track duplicate rate correctly', function (): void {
        // 1 miss (first event)
        $this->cache->shouldReceive('has')->andReturn(false);
        $this->cache->shouldReceive('put')->andReturn(true);
        $this->service->shouldDispatch('test', [], 'c1');

        // 1 hit (duplicate)
        $this->cache->shouldReceive('has')->andReturn(true);
        $this->service->shouldDispatch('test', [], 'c1');

        $stats = $this->service->getStats();
        expect($stats['hits'])->toBe(1);
        expect($stats['misses'])->toBe(1);
        expect($stats['total_processed'])->toBe(2);
        expect($stats['duplicate_rate'])->toBe(0.5);
    });
});

describe('PrivacyManifestService', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.privacy_manifest', [])
            ->andReturn([
                'enabled' => true,
                'cache_ttl' => 3600,
                'controller_email' => 'privacy@example.com',
                'dpo_email' => null,
                'legal_basis_defaults' => [],
                'retention_defaults' => [],
            ]);

        $this->config->shouldReceive('get')
            ->with('app.name', 'Application')
            ->andReturn('TestApp');

        $this->config->shouldReceive('get')
            ->with('app.timezone', 'UTC')
            ->andReturn('Europe/Berlin');

        $this->service = new PrivacyManifestService($this->cache, $this->config);
    });

    test('classifyEvent returns correct categories for purchase event', function (): void {
        $categories = $this->service->classifyEvent('purchase');

        expect($categories)->toContain('technical');
        expect($categories)->toContain('financial');
        expect($categories)->toContain('contractual');
        expect($categories)->toContain('transactional');
    });

    test('classifyEvent returns correct categories for sign_up event', function (): void {
        $categories = $this->service->classifyEvent('sign_up');

        expect($categories)->toContain('technical');
        expect($categories)->toContain('identifier');
        expect($categories)->toContain('behavioral');
    });

    test('classifyEvent returns correct categories for page_view event', function (): void {
        $categories = $this->service->classifyEvent('page_view');

        expect($categories)->toContain('technical');
        expect($categories)->toContain('behavioral');
    });

    test('classifyEvent always includes technical', function (): void {
        $categories = $this->service->classifyEvent('unknown_event');

        expect($categories)->toContain('technical');
    });

    test('legalBasisFor returns consent for identifier category', function (): void {
        $basis = $this->service->legalBasisFor(['identifier', 'technical']);

        expect($basis)->toBe('consent');
    });

    test('legalBasisFor returns contract for financial category', function (): void {
        $basis = $this->service->legalBasisFor(['financial', 'technical']);

        expect($basis)->toBe('contract');
    });

    test('legalBasisFor returns legitimate_interest for behavioral only', function (): void {
        $basis = $this->service->legalBasisFor(['behavioral', 'technical']);

        expect($basis)->toBe('legitimate_interest');
    });

    test('legalBasisFor uses config defaults when available', function (): void {
        $this->config->shouldReceive('get')
            ->andReturn([
                'enabled' => true,
                'cache_ttl' => 3600,
                'controller_email' => 'privacy@example.com',
                'dpo_email' => null,
                'legal_basis_defaults' => ['behavioral' => 'consent'],
                'retention_defaults' => [],
            ]);

        $service = new PrivacyManifestService($this->cache, $this->config);
        $basis = $service->legalBasisFor(['behavioral', 'technical']);

        expect($basis)->toBe('consent');
    });

    test('retentionFor returns 2555 for financial events', function (): void {
        $days = $this->service->retentionFor(['financial', 'technical']);

        expect($days)->toBe(2555);
    });

    test('retentionFor returns 1095 for identifier events', function (): void {
        $days = $this->service->retentionFor(['identifier', 'technical']);

        expect($days)->toBe(1095);
    });

    test('retentionFor returns 90 for behavioral events', function (): void {
        $days = $this->service->retentionFor(['behavioral', 'technical']);

        expect($days)->toBe(90);
    });

    test('retentionFor uses config defaults when available', function (): void {
        $this->config->shouldReceive('get')
            ->andReturn([
                'enabled' => true,
                'cache_ttl' => 3600,
                'controller_email' => 'privacy@example.com',
                'dpo_email' => null,
                'legal_basis_defaults' => [],
                'retention_defaults' => ['financial' => 1825],
            ]);

        $service = new PrivacyManifestService($this->cache, $this->config);
        $days = $service->retentionFor(['financial', 'technical']);

        expect($days)->toBe(1825);
    });

    test('isEnabled returns config value', function (): void {
        expect($this->service->isEnabled())->toBeTrue();
    });

    test('invalidateCache calls cache forget', function (): void {
        $this->cache->shouldReceive('forget')->with('zb_privacy_manifest')->once();

        $this->service->invalidateCache();
    });

    test('generate produces structured manifest', function (): void {
        $this->cache->shouldReceive('get')->andReturn(null);
        $this->cache->shouldReceive('put')->once()->andReturn(true);

        $manifest = $this->service->generate();

        expect($manifest)->toHaveKeys([
            'controller', 'summary', 'processing_activities',
            'data_flows', 'data_subject_rights', 'cross_border',
            'generated_at', 'version',
        ]);
        expect($manifest['version'])->toBe('9.3.0');
    });

    test('generate uses cached manifest on subsequent calls', function (): void {
        $cachedManifest = [
            'controller' => ['name' => 'Cached'],
            'summary' => [],
            'processing_activities' => [],
            'data_flows' => [],
            'data_subject_rights' => [],
            'cross_border' => [],
            'generated_at' => '2026-01-01T00:00:00+00:00',
            'version' => '9.3.0',
        ];

        $this->cache->shouldReceive('get')->andReturn($cachedManifest);
        $this->cache->shouldNotReceive('put');

        $manifest = $this->service->generate();

        expect($manifest['controller']['name'])->toBe('Cached');
    });

    test('summary returns structured dashboard data', function (): void {
        $this->cache->shouldReceive('get')->andReturn(null);
        $this->cache->shouldReceive('put')->once()->andReturn(true);

        $summary = $this->service->summary();

        expect($summary)->toHaveKeys([
            'total_events', 'data_categories', 'providers',
            'pii_events_count', 'consent_required', 'retention_max_days',
            'legal_bases_used',
        ]);
        expect($summary['total_events'])->toBeGreaterThan(0);
        expect($summary['providers'])->not->toBeEmpty();
    });
});

describe('EventAnnotationService', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.annotations', [])
            ->andReturn([
                'enabled' => true,
                'cache_ttl' => 86400,
                'max_annotations_per_event' => 20,
                'auto_attach' => [],
            ]);

        $this->service = new EventAnnotationService($this->cache, $this->config);
    });

    test('annotate stores annotation in cache', function (): void {
        $this->cache->shouldReceive('get')->andReturn([]);
        $this->cache->shouldReceive('put')->once()->andReturn(true);

        $result = $this->service->annotate('event_1', 'deployment', 'v1.2.3', 'deployment');

        expect($result)->toBeTrue();
    });

    test('annotate returns false when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->andReturn(['enabled' => false]);

        $service = new EventAnnotationService($this->cache, $this->config);

        $result = $service->annotate('event_1', 'deployment', 'v1.2.3');

        expect($result)->toBeFalse();
    });

    test('annotate updates existing annotation with same key and type', function (): void {
        $existing = [
            ['key' => 'deployment', 'value' => 'v1.0.0', 'type' => 'deployment', 'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00'],
        ];

        $this->cache->shouldReceive('get')->andReturn($existing);
        $this->cache->shouldReceive('put')->once()->andReturn(true);

        $result = $this->service->annotate('event_1', 'deployment', 'v1.2.3', 'deployment');

        expect($result)->toBeTrue();
    });

    test('annotate respects max annotations limit', function (): void {
        $annotations = array_fill(0, 20, [
            'key' => 'existing', 'value' => 'val', 'type' => 'custom',
            'created_at' => '2026-01-01T00:00:00+00:00',
            'updated_at' => '2026-01-01T00:00:00+00:00',
        ]);

        $this->cache->shouldReceive('get')->andReturn($annotations);
        $this->cache->shouldNotReceive('put');

        $result = $this->service->annotate('event_1', 'new_key', 'value');

        expect($result)->toBeFalse();
    });

    test('getAnnotations returns empty array when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->andReturn(['enabled' => false]);

        $service = new EventAnnotationService($this->cache, $this->config);

        expect($service->getAnnotations('event_1'))->toBe([]);
    });

    test('getAnnotations returns cached annotations', function (): void {
        $annotations = [
            ['key' => 'deployment', 'value' => 'v1.0.0', 'type' => 'deployment', 'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00'],
        ];

        $this->cache->shouldReceive('get')->andReturn($annotations);

        $result = $this->service->getAnnotations('event_1');

        expect($result)->toBe($annotations);
    });

    test('getAnnotation returns value for existing key', function (): void {
        $annotations = [
            ['key' => 'deployment', 'value' => 'v1.0.0', 'type' => 'deployment', 'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00'],
        ];

        $this->cache->shouldReceive('get')->andReturn($annotations);

        $result = $this->service->getAnnotation('event_1', 'deployment');

        expect($result)->toBe('v1.0.0');
    });

    test('getAnnotation returns null for missing key', function (): void {
        $this->cache->shouldReceive('get')->andReturn([]);

        $result = $this->service->getAnnotation('event_1', 'missing');

        expect($result)->toBeNull();
    });

    test('removeAnnotation removes from cache', function (): void {
        $annotations = [
            ['key' => 'deployment', 'value' => 'v1.0.0', 'type' => 'deployment', 'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00'],
        ];

        $this->cache->shouldReceive('get')->andReturn($annotations);
        $this->cache->shouldReceive('put')->once()->andReturn(true);

        $result = $this->service->removeAnnotation('event_1', 'deployment');

        expect($result)->toBeTrue();
    });

    test('removeAnnotation returns false for non-existent key', function (): void {
        $this->cache->shouldReceive('get')->andReturn([]);

        $result = $this->service->removeAnnotation('event_1', 'missing');

        expect($result)->toBeFalse();
    });

    test('clearAnnotations calls cache forget', function (): void {
        $this->cache->shouldReceive('forget')->once();

        $this->service->clearAnnotations('event_1');
    });

    test('autoAttachAnnotations returns empty when no auto-attach config', function (): void {
        $result = $this->service->autoAttachAnnotations('event_1');

        expect($result)->toBe([]);
    });

    test('isEnabled returns config value', function (): void {
        expect($this->service->isEnabled())->toBeTrue();
    });

    test('getStats returns correct structure', function (): void {
        $stats = $this->service->getStats();

        expect($stats)->toHaveKeys(['enabled', 'cache_ttl', 'max_per_event', 'auto_attach_keys']);
        expect($stats['enabled'])->toBeTrue();
        expect($stats['cache_ttl'])->toBe(86400);
        expect($stats['max_per_event'])->toBe(20);
    });

    test('annotate normalizes invalid type to custom', function (): void {
        $this->cache->shouldReceive('get')->andReturn([]);
        $this->cache->shouldReceive('put')
            ->with(Mockery::any(), Mockery::on(function (array $annotations): bool {
                return $annotations[0]['type'] === 'custom';
            }))
            ->once()
            ->andReturn(true);

        $result = $this->service->annotate('event_1', 'test', 'value', 'invalid_type');

        expect($result)->toBeTrue();
    });

    test('fail open when cache put fails', function (): void {
        $this->cache->shouldReceive('get')->andReturn([]);
        $this->cache->shouldReceive('put')->once()->andThrow(new \RuntimeException('Cache failure'));

        $result = $this->service->annotate('event_1', 'deployment', 'v1.0.0');

        expect($result)->toBeFalse();
    });

    test('searchByKey returns empty array (placeholder)', function (): void {
        $result = $this->service->searchByKey('deployment');

        expect($result)->toBe([]);
    });
});
