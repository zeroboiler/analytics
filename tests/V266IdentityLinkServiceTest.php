<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\Services\IdentityLinkService;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;

/**
 * Unit tests for IdentityLinkService.
 *
 * Validates client ID ↔ user ID linking, unlinking, resolution,
 * per-user limits, and edge cases with empty/null inputs.
 *
 * @since 266.0.0
 */
final class V266IdentityLinkServiceTest extends TestCase
{
    /** @var array<string, mixed> In-memory cache store */
    private array $cacheStore = [];

    private CacheRepository $cache;

    private IdentityLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheStore = [];
        $this->cache = $this->createMock(CacheRepository::class);

        $configRepo = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'identity' => [
                        'cache_prefix' => 'zb_identity_',
                        'link_ttl' => 7776000,
                        'max_links_per_user' => 3,
                        'max_links_per_client' => 10,
                    ],
                ],
            ],
        ]);

        $analyticsConfig = new AnalyticsConfig($configRepo);
        $this->service = new IdentityLinkService($this->cache, $analyticsConfig);
    }

    // ── Linking ──────────────────────────────────────────────────────

    public function test_link_stores_client_to_user_mapping(): void
    {
        $this->cache->method('put')->willReturnCallback(
            function (string $key, mixed $value, int $ttl): void {
                $this->cacheStore[$key] = $value;
            },
        );
        $this->cache->method('get')->willReturnCallback(
            function (string $key, mixed $default = null) {
                return $this->cacheStore[$key] ?? $default;
            },
        );

        $this->service->link('client-abc', 'user-123');

        $this->assertSame('user-123', $this->service->getUserId('client-abc'));
    }

    public function test_link_tracks_multiple_clients_per_user(): void
    {
        $this->cache->method('put')->willReturnCallback(
            function (string $key, mixed $value, int $ttl): void {
                $this->cacheStore[$key] = $value;
            },
        );
        $this->cache->method('get')->willReturnCallback(
            function (string $key, mixed $default = null) {
                return $this->cacheStore[$key] ?? $default;
            },
        );

        $this->service->link('client-1', 'user-10');
        $this->service->link('client-2', 'user-10');
        $this->service->link('client-3', 'user-10');

        $clients = $this->service->getClientIds('user-10');
        $this->assertCount(3, $clients);
        $this->assertContains('client-1', $clients);
        $this->assertContains('client-2', $clients);
        $this->assertContains('client-3', $clients);
    }

    public function test_link_enforces_per_user_limit(): void
    {
        $this->cache->method('put')->willReturnCallback(
            function (string $key, mixed $value, int $ttl): void {
                $this->cacheStore[$key] = $value;
            },
        );
        $this->cache->method('get')->willReturnCallback(
            function (string $key, mixed $default = null) {
                return $this->cacheStore[$key] ?? $default;
            },
        );

        // Fill up to the limit (3)
        $this->service->link('client-1', 'user-x');
        $this->service->link('client-2', 'user-x');
        $this->service->link('client-3', 'user-x');

        // Adding a 4th should evict the oldest (client-1)
        $this->service->link('client-4', 'user-x');

        $clients = $this->service->getClientIds('user-x');
        $this->assertCount(3, $clients);
        $this->assertNotContains('client-1', $clients);
        $this->assertContains('client-4', $clients);
    }

    public function test_link_overwrites_existing_client_mapping(): void
    {
        $this->cache->method('put')->willReturnCallback(
            function (string $key, mixed $value, int $ttl): void {
                $this->cacheStore[$key] = $value;
            },
        );
        $this->cache->method('get')->willReturnCallback(
            function (string $key, mixed $default = null) {
                return $this->cacheStore[$key] ?? $default;
            },
        );

        $this->service->link('client-abc', 'user-old');
        $this->assertSame('user-old', $this->service->getUserId('client-abc'));

        // Re-link to a different user
        $this->service->link('client-abc', 'user-new');
        $this->assertSame('user-new', $this->service->getUserId('client-abc'));
    }

    // ── Edge Cases ────────────────────────────────────────────────────

    public function test_link_ignores_empty_client_id(): void
    {
        $this->cache->expects($this->never())->method('put');

        $this->service->link('', 'user-123');
    }

    public function test_link_ignores_empty_user_id(): void
    {
        $this->cache->expects($this->never())->method('put');

        $this->service->link('client-abc', '');
    }

    public function test_get_user_id_returns_null_for_unknown_client(): void
    {
        $this->cache->method('get')->willReturn(null);

        $this->assertNull($this->service->getUserId('nonexistent-client'));
    }

    // ── Resolution ────────────────────────────────────────────────────

    public function test_resolve_identity_returns_user_id_when_linked(): void
    {
        $this->cache->method('get')->willReturnCallback(
            function (string $key, mixed $default = null) {
                if ($key === 'zb_identity_client:client-linked') {
                    return 'user-42';
                }
                return $default;
            },
        );

        $this->assertSame('user-42', $this->service->resolveIdentity('client-linked'));
    }

    public function test_resolve_identity_returns_client_id_when_not_linked(): void
    {
        $this->cache->method('get')->willReturn(null);

        $this->assertSame('client-orphan', $this->service->resolveIdentity('client-orphan'));
    }

    public function test_is_linked_returns_true_for_linked_client(): void
    {
        $this->cache->method('get')->willReturn('user-1');

        $this->assertTrue($this->service->isLinked('client-1'));
    }

    public function test_is_linked_returns_false_for_unlinked_client(): void
    {
        $this->cache->method('get')->willReturn(null);

        $this->assertFalse($this->service->isLinked('client-unknown'));
    }

    // ── Unlinking ─────────────────────────────────────────────────────

    public function test_unlink_removes_client_to_user_mapping(): void
    {
        $this->cacheStore['zb_identity_client:client-del'] = 'user-del';
        $this->cacheStore['zb_identity_user:user-del:clients'] = ['client-del', 'client-other'];

        $this->cache->method('get')->willReturnCallback(
            function (string $key, mixed $default = null) {
                return $this->cacheStore[$key] ?? $default;
            },
        );
        $this->cache->method('put')->willReturnCallback(
            function (string $key, mixed $value, int $ttl): void {
                $this->cacheStore[$key] = $value;
            },
        );
        $this->cache->method('forget')->willReturnCallback(
            function (string $key): bool {
                unset($this->cacheStore[$key]);
                return true;
            },
        );

        $this->service->unlink('client-del');

        // Client → user mapping should be removed
        $this->assertFalse(isset($this->cacheStore['zb_identity_client:client-del']));
        // Client should be removed from user's set
        $this->assertNotContains('client-del', $this->cacheStore['zb_identity_user:user-del:clients']);
    }

    public function test_unlink_is_noop_for_unknown_client(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects($this->never())->method('forget');

        $this->service->unlink('nonexistent');
    }

    // ── Stats ─────────────────────────────────────────────────────────

    public function test_get_stats_returns_configured_values(): void
    {
        $stats = $this->service->getStats();

        $this->assertSame(0, $stats['linked_clients']);
        $this->assertSame('zb_identity_', $stats['cache_prefix']);
        $this->assertSame(7776000, $stats['link_ttl']);
        $this->assertSame(3, $stats['max_links_per_user']);
        $this->assertSame(10, $stats['max_links_per_client']);
    }

    // ── getClientIds with non-array cache ────────────────────────────

    public function test_get_client_ids_returns_empty_for_corrupted_cache(): void
    {
        $this->cache->method('get')->willReturn('not-an-array');

        $clients = $this->service->getClientIds('user-corrupt');
        $this->assertSame([], $clients);
    }
}
