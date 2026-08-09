<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Feature\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\IdentityResolutionService;
use Mockery;
use Mockery\MockInterface;

/**
 * @covers \ZeroBoiler\Analytics\Services\IdentityResolutionService
 */
final class IdentityResolutionServiceTest extends \PHPUnit\Framework\TestCase
{
    private CacheRepository&MockInterface $cache;

    private ConfigRepository&MockInterface $config;

    private IdentityResolutionService $service;

    protected function setUp(): void
    {
        $this->cache = Mockery::mock(CacheRepository::class);
        $this->config = Mockery::mock(ConfigRepository::class);

        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity', [])
            ->andReturn([
                'cache_prefix' => 'zb_identity_',
                'link_ttl' => 7776000,
                'max_links_per_user' => 50,
                'max_links_per_client' => 10,
            ]);

        $this->service = new IdentityResolutionService(
            $this->cache,
            $this->config,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_it_resolves_client_to_user(): void
    {
        $clientId = 'client-uuid-123';
        $userId = 'user-456';

        $this->cache->shouldReceive('put')
            ->with('zb_identity_client_client-uuid-123', 'user-456', 7776000)
            ->once();

        $this->cache->shouldReceive('get')
            ->with('zb_identity_user_user-456')
            ->andReturn([]);

        $this->cache->shouldReceive('put')
            ->with('zb_identity_user_user-456', [$clientId], 7776000)
            ->once();

        $this->cache->shouldReceive('get')
            ->with('zb_identity_client_history_client-uuid-123')
            ->andReturn([]);

        $this->cache->shouldReceive('put')
            ->with('zb_identity_client_history_client-uuid-123', [$userId], 7776000)
            ->once();

        $result = $this->service->resolve($clientId, $userId);

        $this->assertTrue($result['linked']);
        $this->assertNull($result['previous_user_id']);
        $this->assertSame(1, $result['total_client_links']);
        $this->assertSame(1, $result['total_user_links']);
    }

    public function test_it_gets_user_id_for_client(): void
    {
        $clientId = 'client-abc';

        $this->cache->shouldReceive('get')
            ->with('zb_identity_client_client-abc')
            ->andReturn('user-123');

        $result = $this->service->getUserIdForClient($clientId);

        $this->assertSame('user-123', $result);
    }

    public function test_it_returns_null_for_unlinked_client(): void
    {
        $clientId = 'client-unknown';

        $this->cache->shouldReceive('get')
            ->with('zb_identity_client_client-unknown')
            ->andReturn(null);

        $result = $this->service->getUserIdForClient($clientId);

        $this->assertNull($result);
    }

    public function test_it_gets_client_ids_for_user(): void
    {
        $userId = 'user-xyz';

        $this->cache->shouldReceive('get')
            ->with('zb_identity_user_user-xyz')
            ->andReturn(['client-1', 'client-2', 'client-3']);

        $result = $this->service->getClientIdsForUser($userId);

        $this->assertSame(['client-1', 'client-2', 'client-3'], $result);
    }

    public function test_it_checks_if_client_is_linked(): void
    {
        $clientId = 'client-linked';

        $this->cache->shouldReceive('get')
            ->with('zb_identity_client_client-linked')
            ->andReturn('user-1');

        $this->assertTrue($this->service->isClientLinked($clientId));
    }

    public function test_it_checks_if_client_is_not_linked(): void
    {
        $clientId = 'client-unlinked';

        $this->cache->shouldReceive('get')
            ->with('zb_identity_client_client-unlinked')
            ->andReturn(null);

        $this->assertFalse($this->service->isClientLinked($clientId));
    }

    public function test_it_forgets_client_link(): void
    {
        $clientId = 'client-to-forget';
        $userId = 'user-to-forget';

        $this->cache->shouldReceive('get')
            ->with('zb_identity_client_client-to-forget')
            ->andReturn($userId);

        $this->cache->shouldReceive('forget')
            ->with('zb_identity_client_client-to-forget')
            ->once();

        $this->cache->shouldReceive('get')
            ->with('zb_identity_user_user-to-forget')
            ->andReturn([$clientId, 'other-client']);

        $this->cache->shouldReceive('put')
            ->with('zb_identity_user_user-to-forget', ['other-client'], 7776000)
            ->once();

        $this->cache->shouldReceive('forget')
            ->with('zb_identity_client_history_client-to-forget')
            ->once();

        $result = $this->service->forgetClient($clientId);

        $this->assertTrue($result);
    }

    public function test_it_forgets_user_links_for_gdpr(): void
    {
        $userId = 'user-gdpr';
        $clients = ['client-a', 'client-b'];

        $this->cache->shouldReceive('get')
            ->with('zb_identity_user_user-gdpr')
            ->andReturn($clients);

        // Forget each client → user mapping
        $this->cache->shouldReceive('forget')
            ->with('zb_identity_client_client-a')
            ->once();

        $this->cache->shouldReceive('forget')
            ->with('zb_identity_client_client-b')
            ->once();

        // Forget each client history
        $this->cache->shouldReceive('get')
            ->with('zb_identity_client_history_client-a')
            ->andReturn([$userId]);

        $this->cache->shouldReceive('get')
            ->with('zb_identity_client_history_client-b')
            ->andReturn([$userId]);

        $this->cache->shouldReceive('forget')
            ->with('zb_identity_client_history_client-a')
            ->once();

        $this->cache->shouldReceive('forget')
            ->with('zb_identity_client_history_client-b')
            ->once();

        // Forget user → clients mapping
        $this->cache->shouldReceive('forget')
            ->with('zb_identity_user_user-gdpr')
            ->once();

        $count = $this->service->forgetUser($userId);

        $this->assertSame(2, $count);
    }

    public function test_it_returns_identity_summary(): void
    {
        $userId = 'user-summary';
        $clients = ['client-1', 'client-2', 'client-3'];

        $this->cache->shouldReceive('get')
            ->with('zb_identity_user_user-summary')
            ->andReturn($clients);

        $summary = $this->service->identitySummary($userId);

        $this->assertSame($userId, $summary['user_id']);
        $this->assertSame(3, $summary['linked_clients']);
        $this->assertSame('client-3', $summary['primary_client_id']);
        $this->assertSame('client-1', $summary['first_linked']);
    }

    public function test_it_returns_empty_summary_for_unlinked_user(): void
    {
        $userId = 'user-empty';

        $this->cache->shouldReceive('get')
            ->with('zb_identity_user_user-empty')
            ->andReturn([]);

        $summary = $this->service->identitySummary($userId);

        $this->assertSame($userId, $summary['user_id']);
        $this->assertSame(0, $summary['linked_clients']);
        $this->assertNull($summary['primary_client_id']);
        $this->assertNull($summary['first_linked']);
    }

    public function test_it_forgets_nonexistent_client_returns_false(): void
    {
        $clientId = 'client-nonexistent';

        $this->cache->shouldReceive('get')
            ->with('zb_identity_client_client-nonexistent')
            ->andReturn(null);

        $result = $this->service->forgetClient($clientId);

        $this->assertFalse($result);
    }

    public function test_it_gets_primary_client_for_user(): void
    {
        $userId = 'user-primary';

        $this->cache->shouldReceive('get')
            ->with('zb_identity_user_user-primary')
            ->andReturn(['old-client', 'new-client']);

        $primary = $this->service->getPrimaryClientIdForUser($userId);

        $this->assertSame('new-client', $primary);
    }

    public function test_it_filters_empty_strings_from_cache_links(): void
    {
        $userId = 'user-filtered';

        $this->cache->shouldReceive('get')
            ->with('zb_identity_user_user-filtered')
            ->andReturn(['', 'valid-client', null, 0, 'also-valid']);

        $result = $this->service->getClientIdsForUser($userId);

        $this->assertSame(['valid-client', 'also-valid'], $result);
    }
}
