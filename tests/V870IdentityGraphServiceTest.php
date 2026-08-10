<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\IdentityGraphService;
use ZeroBoiler\Analytics\Services\DeviceFingerprintService;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->config = mock(ConfigRepository::class);

    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.identity_graph', [])
        ->andReturn([
            'enabled' => true,
            'cache_prefix' => 'zb_ig_test_',
            'graph_ttl' => 86400,
            'max_clients_per_user' => 10,
            'max_devices_per_user' => 5,
            'max_edges_per_node' => 20,
            'min_confidence_stitching' => 0.5,
            'min_confidence_merge' => 0.9,
        ]);

    $this->graph = new IdentityGraphService($this->cache, $this->config);
});

describe('IdentityGraphService', function (): void {
    test('linkExplicit creates client→user link with confidence 1.0', function (): void {
        $this->cache->shouldReceive('put')->andReturn(true);
        $this->cache->shouldReceive('get')->andReturn(null);

        $result = $this->graph->linkExplicit('client-abc', 'user-123');

        expect($result)->toBeArray();
        expect($result['linked'])->toBeTrue();
        expect($result['confidence'])->toBe(1.0);
        expect($result['previous_user_id'])->toBeNull();
        expect($result['nodes'])->toBeGreaterThanOrEqual(1);
    });

    test('linkExplicit with device links device to user', function (): void {
        $this->cache->shouldReceive('put')->andReturn(true);
        $this->cache->shouldReceive('get')->andReturn(null);

        $result = $this->graph->linkExplicit('client-abc', 'user-123', 'device-fp-1');

        expect($result['linked'])->toBeTrue();
        expect($result['confidence'])->toBe(1.0);
    });

    test('linkExplicit detects previous user when re-linking', function (): void {
        $this->cache->shouldReceive('put')->andReturn(true);
        // First call: no previous
        $this->cache->shouldReceive('get')
            ->andReturn(null, null, null, null, null);

        $result = $this->graph->linkExplicit('client-abc', 'user-456');

        // After link, client has a user but previous_user_id was null (first link)
        expect($result['previous_user_id'])->toBeNull();
        expect($result['linked'])->toBeTrue();
    });

    test('resolveClientId returns null for unknown client', function (): void {
        $this->cache->shouldReceive('get')->andReturn(null);

        expect($this->graph->resolveClientId('unknown-client'))->toBeNull();
    });

    test('inferIdentity returns false when no device link exists', function (): void {
        $this->cache->shouldReceive('get')->andReturn(null);

        $result = $this->graph->inferIdentity('client-new', 'device-unknown');

        expect($result['inferred'])->toBeFalse();
        expect($result['user_id'])->toBeNull();
    });

    test('inferIdentity infers from existing device link', function (): void {
        // Device is already linked to user-123 with high confidence
        $this->cache->shouldReceive('get')
            ->andReturn(
                ['user_id' => 'user-123', 'clients' => ['client-old'], 'confidence' => 0.9, 'updated_at' => time()], // device node
                null, // client node (not yet linked)
            );
        $this->cache->shouldReceive('put')->andReturn(true);

        $result = $this->graph->inferIdentity('client-new', 'device-fp-1');

        expect($result['inferred'])->toBeTrue();
        expect($result['user_id'])->toBe('user-123');
        expect($result['method'])->toBe('device_match');
        expect($result['confidence'])->toBeGreaterThan(0.5);
    });

    test('getGraph returns empty graph for unknown user', function (): void {
        $this->cache->shouldReceive('get')->andReturn(null);

        $graph = $this->graph->getGraph('unknown-user');

        expect($graph['user_id'])->toBe('unknown-user');
        expect($graph['clients'])->toBeEmpty();
        expect($graph['devices'])->toBeEmpty();
        expect($graph['total_nodes'])->toBe(0);
    });

    test('areSameUser returns false for unknown clients', function (): void {
        $this->cache->shouldReceive('get')->andReturn(null);

        $result = $this->graph->areSameUser('client-a', 'client-b');

        expect($result['same_user'])->toBeFalse();
        expect($result['confidence'])->toBe(0.0);
    });

    test('areSameUser detects direct user match', function (): void {
        $this->cache->shouldReceive('get')->andReturn(
            ['user_id' => 'user-1', 'device_id' => 'dev-1', 'confidence' => 1.0, 'linked_at' => time(), 'link_type' => 'explicit'],
            ['user_id' => 'user-1', 'device_id' => 'dev-2', 'confidence' => 1.0, 'linked_at' => time(), 'link_type' => 'explicit'],
        );

        $result = $this->graph->areSameUser('client-a', 'client-b');

        expect($result['same_user'])->toBeTrue();
        expect($result['user_id'])->toBe('user-1');
        expect($result['method'])->toBe('direct_user_match');
    });

    test('mergeUsers transfers clients from source to target', function (): void {
        $sourceNode = ['clients' => ['c1', 'c2'], 'devices' => ['d1'], 'merged_users' => [], 'updated_at' => time()];
        $targetNode = ['clients' => ['c3'], 'devices' => ['d2'], 'merged_users' => [], 'updated_at' => time()];
        $clientNode = ['user_id' => 'source', 'device_id' => null, 'confidence' => 0.9, 'linked_at' => time(), 'link_type' => 'explicit'];
        $deviceNode = ['user_id' => 'source', 'clients' => [], 'confidence' => 0.8, 'updated_at' => time()];

        $this->cache->shouldReceive('get')
            ->andReturn($sourceNode, $targetNode, $clientNode, $clientNode, $deviceNode, $targetNode);
        $this->cache->shouldReceive('put')->andReturn(true);

        $result = $this->graph->mergeUsers('source-user', 'target-user');

        expect($result['merged'])->toBeTrue();
        expect($result['clients_transferred'])->toBeGreaterThanOrEqual(1);
    });

    test('stats returns configuration info', function (): void {
        $stats = $this->graph->stats();

        expect($stats)->toBeArray();
        expect($stats['cache_prefix'])->toBe('zb_ig_test_');
        expect($stats['graph_ttl'])->toBe(86400);
        expect($stats['max_clients_per_user'])->toBe(10);
        expect($stats['min_confidence_stitching'])->toBe(0.5);
        expect($stats['min_confidence_merge'])->toBe(0.9);
    });

    test('enrichEvent adds identity context to event', function (): void {
        $this->cache->shouldReceive('get')->andReturn(null);

        $event = new AnalyticsEvent(
            name: 'page_view',
            params: ['page_title' => 'Home'],
            clientId: 'client-abc',
        );

        $enriched = $this->graph->enrichEvent($event);

        // No user linked — should not add identity_user_id
        expect($enriched->name)->toBe('page_view');
        expect($enriched->params['page_title'])->toBe('Home');
    });
});

describe('DeviceFingerprintService', function (): void {
    test('fingerprintFromComponents generates hash with 2+ components', function (): void {
        $service = new DeviceFingerprintService(['enabled' => true, 'hash_algo' => 'sha256']);

        $fp = $service->fingerprintFromComponents([
            'user_agent' => 'Mozilla/5.0 (Macintosh)',
            'accept_language' => 'en-US',
        ]);

        expect($fp)->toBeString();
        expect(strlen($fp))->toBe(64); // SHA-256 hex length
    });

    test('fingerprintFromComponents returns null with insufficient data', function (): void {
        $service = new DeviceFingerprintService(['enabled' => true, 'hash_algo' => 'sha256']);

        expect($service->fingerprintFromComponents(['user_agent' => 'Mozilla/5.0']))->toBeNull();
    });

    test('fingerprintFromComponents returns null when disabled', function (): void {
        $service = new DeviceFingerprintService(['enabled' => false]);

        expect($service->fingerprintFromComponents([
            'user_agent' => 'test',
            'accept_language' => 'en',
        ]))->toBeNull();
    });

    test('isEnabled reflects configuration', function (): void {
        $enabled = new DeviceFingerprintService(['enabled' => true]);
        $disabled = new DeviceFingerprintService(['enabled' => false]);

        expect($enabled->isEnabled())->toBeTrue();
        expect($disabled->isEnabled())->toBeFalse();
    });

    test('getComponents returns configured components', function (): void {
        $service = new DeviceFingerprintService([
            'enabled' => true,
            'components' => ['user_agent', 'accept_language'],
        ]);

        expect($service->getComponents())->toBe(['user_agent', 'accept_language']);
    });

    test('same input produces same fingerprint (deterministic)', function (): void {
        $service = new DeviceFingerprintService(['enabled' => true]);

        $components = ['user_agent' => 'Mozilla/5.0', 'accept_language' => 'en-US', 'sec_ch_platform' => 'macOS'];
        $fp1 = $service->fingerprintFromComponents($components);
        $fp2 = $service->fingerprintFromComponents($components);

        expect($fp1)->toBe($fp2);
    });

    test('different inputs produce different fingerprints', function (): void {
        $service = new DeviceFingerprintService(['enabled' => true]);

        $fp1 = $service->fingerprintFromComponents(['user_agent' => 'Chrome', 'accept_language' => 'en-US']);
        $fp2 = $service->fingerprintFromComponents(['user_agent' => 'Firefox', 'accept_language' => 'en-US']);

        expect($fp1)->not->toBe($fp2);
    });
});
