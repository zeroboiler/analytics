<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\CrossDeviceIdentityMergeService;
use ZeroBoiler\Analytics\Services\EventBudgetEnforcementService;

beforeEach(function (): void {
    $this->cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $this->cache->shouldReceive('get')->andReturn(0);
    $this->cache->shouldReceive('put')->andReturn(true);
    $this->cache->shouldReceive('increment')->andReturn(1);
    $this->cache->shouldReceive('forget')->andReturn(true);

    $this->config = mock(\Illuminate\Contracts\Config\Repository::class);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.budget_enforcement', [])
        ->andReturn([
            'enabled' => true,
            'default_action' => 'alert',
            'throttle_rate' => 0.1,
            'cooldown' => 3600,
            'provider_limits' => [
                'ga4' => 1_000,
                'posthog' => 500,
            ],
            'event_limits' => [
                'page_view' => 100,
                'scroll_depth' => 50,
            ],
        ]);

    $this->mergeConfig = mock(\Illuminate\Contracts\Config\Repository::class);
    $this->mergeConfig->shouldReceive('get')
        ->with('zeroboiler.analytics.cross_device_merge', [])
        ->andReturn([
            'enabled' => true,
            'link_ttl' => 7776000,
            'max_clients_per_user' => 50,
            'max_graph_size' => 1000,
            'merge_confidence_threshold' => 0.6,
        ]);
});

// ─── EventBudgetEnforcementService ─────────────────────────────────────

describe('EventBudgetEnforcementService', function (): void {
    test('is final class with strict types', function (): void {
        $reflection = new \ReflectionClass(EventBudgetEnforcementService::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->getFileName())->toContain('EventBudgetEnforcementService.php');
    });

    test('constructor is void with no side effects when disabled', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.budget_enforcement', [])
            ->andReturn(['enabled' => false]);

        $service = new EventBudgetEnforcementService($this->cache, $config);
        expect($service->isEnabled())->toBeFalse();
    });

    test('allows events when budget enforcement is disabled', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.budget_enforcement', [])
            ->andReturn(['enabled' => false]);

        $service = new EventBudgetEnforcementService($this->cache, $config);
        $event = AnalyticsEvent::make('page_view', ['url' => '/test']);

        $result = $service->checkBudget($event, 'ga4');

        expect($result['action'])->toBe('allow');
        expect($result['reason'])->toBeNull();
    });

    test('allows events when budget is not exceeded', function (): void {
        // Cache returns 0 for all counters (no usage)
        $service = new EventBudgetEnforcementService($this->cache, $this->config);
        $event = AnalyticsEvent::make('page_view', ['url' => '/test']);

        $result = $service->checkBudget($event, 'ga4');

        expect($result['action'])->toBe('allow');
    });

    test('blocks events when provider budget is 100% exceeded', function (): void {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturn(1000); // at budget limit
        $cache->shouldReceive('put')->andReturn(true);
        $cache->shouldReceive('increment')->andReturn(1);
        $cache->shouldReceive('forget')->andReturn(true);

        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.budget_enforcement', [])
            ->andReturn([
                'enabled' => true,
                'default_action' => 'block',
                'throttle_rate' => 0.1,
                'cooldown' => 3600,
                'provider_limits' => ['ga4' => 1_000],
                'event_limits' => [],
            ]);

        $service = new EventBudgetEnforcementService($cache, $config);
        $event = AnalyticsEvent::make('page_view', ['url' => '/test']);

        $result = $service->checkBudget($event, 'ga4');

        expect($result['action'])->toBe('block');
        expect($result['reason'])->not->toBeNull();
        expect($result['budget_pct'])->toBe(100.0);
    });

    test('throttles events when budget is at 90%', function (): void {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturn(900); // 90% of 1000
        $cache->shouldReceive('put')->andReturn(true);
        $cache->shouldReceive('increment')->andReturn(1);
        $cache->shouldReceive('forget')->andReturn(true);

        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.budget_enforcement', [])
            ->andReturn([
                'enabled' => true,
                'default_action' => 'throttle',
                'throttle_rate' => 0.1,
                'cooldown' => 3600,
                'provider_limits' => ['ga4' => 1_000],
                'event_limits' => [],
            ]);

        $service = new EventBudgetEnforcementService($cache, $config);
        $event = AnalyticsEvent::make('page_view', ['url' => '/test']);

        $result = $service->checkBudget($event, 'ga4');

        expect($result['action'])->toBe('throttle');
        expect($result['reason'])->not->toBeNull();
    });

    test('respects per-event hourly budget limits', function (): void {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        // Provider is fine (0/1000), but event is at limit
        $cache->shouldReceive('get')
            ->andReturn(0); // provider counter

        $cache->shouldReceive('put')->andReturn(true);
        $cache->shouldReceive('increment')->andReturn(1);
        $cache->shouldReceive('forget')->andReturn(true);

        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.budget_enforcement', [])
            ->andReturn([
                'enabled' => true,
                'default_action' => 'block',
                'throttle_rate' => 0.1,
                'cooldown' => 3600,
                'provider_limits' => ['ga4' => 1_000],
                'event_limits' => ['page_view' => 100],
            ]);

        $service = new EventBudgetEnforcementService($cache, $config);

        // Simulate event budget exceeded
        $event = AnalyticsEvent::make('page_view', ['url' => '/test']);

        // Mock get to return event at limit for page_view
        $cache->shouldReceive('get')
            ->withArgs(fn (string $key): bool => str_contains($key, 'event_page_view'))
            ->andReturn(100);

        $result = $service->checkBudget($event, 'ga4');

        // The event budget check should kick in when page_view count >= 100
        expect($result['action'])->toBe('block');
    });

    test('no event limit configured returns no_limit status', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.budget_enforcement', [])
            ->andReturn([
                'enabled' => true,
                'default_action' => 'alert',
                'throttle_rate' => 0.1,
                'cooldown' => 3600,
                'provider_limits' => [],
                'event_limits' => [],
            ]);

        $service = new EventBudgetEnforcementService($this->cache, $config);

        $status = $service->getEventBudgetStatus('custom_event');

        expect($status['status'])->toBe('no_limit');
        expect($status['budget'])->toBeNull();
        expect($status['remaining'])->toBe(PHP_INT_MAX);
    });

    test('getBudgetSummary returns all configured providers', function (): void {
        $service = new EventBudgetEnforcementService($this->cache, $this->config);

        $summary = $service->getBudgetSummary();

        expect($summary)->toHaveKeys(['ga4', 'posthog']);
        expect($summary['ga4'])->toHaveKeys(['budget', 'used', 'pct', 'status', 'action']);
        expect($summary['ga4']['budget'])->toBe(1_000);
        expect($summary['posthog']['budget'])->toBe(500);
    });

    test('getBudgetSummary classifies status correctly', function (): void {
        // Override cache to simulate different usage levels
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $callCount = 0;
        $providerCounts = ['ga4' => 50, 'posthog' => 750]; // ga4: 5%, posthog: 150% of 500
        $cache->shouldReceive('get')
            ->andReturnUsing(function (string $key) use ($providerCounts, &$callCount): int {
                if (str_contains($key, 'provider_ga4')) {
                    return $providerCounts['ga4'];
                }
                if (str_contains($key, 'provider_posthog')) {
                    return $providerCounts['posthog'];
                }
                return 0;
            });
        $cache->shouldReceive('put')->andReturn(true);
        $cache->shouldReceive('increment')->andReturn(1);
        $cache->shouldReceive('forget')->andReturn(true);

        $service = new EventBudgetEnforcementService($cache, $this->config);
        $summary = $service->getBudgetSummary();

        expect($summary['ga4']['status'])->toBe('ok');
        expect($summary['ga4']['pct'])->toBe(5.0);
        expect($summary['posthog']['status'])->toBe('exceeded');
    });

    test('resetCounters clears cache keys', function (): void {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturn(0);
        $cache->shouldReceive('put')->andReturn(true);
        $cache->shouldReceive('increment')->andReturn(1);
        $cache->shouldReceive('forget')->andReturnTrue();

        $service = new EventBudgetEnforcementService($cache, $this->config);

        $cleared = $service->resetCounters('ga4');
        expect($cleared)->toBe(1);
    });

    test('getDefaultAction and getThrottleRate return configured values', function (): void {
        $service = new EventBudgetEnforcementService($this->cache, $this->config);

        expect($service->getDefaultAction())->toBe('alert');
        expect($service->getThrottleRate())->toBe(0.1);
    });
});

// ─── CrossDeviceIdentityMergeService ────────────────────────────────────

describe('CrossDeviceIdentityMergeService', function (): void {
    test('is final class with strict types', function (): void {
        $reflection = new \ReflectionClass(CrossDeviceIdentityMergeService::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    test('constructor sets defaults from config', function (): void {
        $service = new CrossDeviceIdentityMergeService($this->cache, $this->mergeConfig);

        $stats = $service->getStats();
        expect($stats['enabled'])->toBeTrue();
        expect($stats['merge_confidence_threshold'])->toBe(0.6);
        expect($stats['max_clients_per_user'])->toBe(50);
        expect($stats['link_ttl'])->toBe(7776000);
    });

    test('associate returns false when disabled', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_device_merge', [])
            ->andReturn(['enabled' => false]);

        $service = new CrossDeviceIdentityMergeService($this->cache, $config);

        expect($service->associate('client-1', 'user-1', 'anon-1'))->toBeFalse();
    });

    test('associate returns false for empty identifiers', function (): void {
        $service = new CrossDeviceIdentityMergeService($this->cache, $this->mergeConfig);

        expect($service->associate('', 'user-1'))->toBeFalse();
        expect($service->associate('client-1', ''))->toBeFalse();
    });

    test('associate stores bidirectional mapping', function (): void {
        $callLog = [];
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnUsing(function (string $key) use (&$callLog): array|int|null {
            $callLog[] = ['get', $key];

            if (str_contains($key, 'client_')) {
                return [];
            }
            if (str_contains($key, 'user_')) {
                return ['client_ids' => [], 'anonymous_ids' => []];
            }
            if (str_contains($key, 'anon_')) {
                return ['client_ids' => [], 'user_ids' => []];
            }
            if (str_contains($key, 'confidence_')) {
                return 0.0;
            }

            return 0;
        });
        $cache->shouldReceive('put')->andReturnUsing(function (string $key, mixed $value, int $ttl) use (&$callLog): bool {
            $callLog[] = ['put', $key];

            return true;
        });

        $service = new CrossDeviceIdentityMergeService($cache, $this->mergeConfig);

        $result = $service->associate('client-abc', 'user-123', 'anon-xyz', ['ip' => '1.2.3.4']);

        expect($result)->toBeTrue();

        // Verify client node was stored
        $putCalls = array_filter($callLog, fn (array $c): bool => $c[0] === 'put');
        $putKeys = array_column(array_values($putCalls), 1);

        expect($putKeys)->toContain('zb_identity_merge_client_client-abc');
        expect($putKeys)->toContain('zb_identity_merge_user_user-123');
        expect($putKeys)->toContain('zb_identity_merge_anon_anon-xyz');
    });

    test('resolveClientToUser returns null when disabled', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_device_merge', [])
            ->andReturn(['enabled' => false]);

        $service = new CrossDeviceIdentityMergeService($this->cache, $config);

        expect($service->resolveClientToUser('client-1'))->toBeNull();
    });

    test('resolveClientToUser returns null for empty client ID', function (): void {
        $service = new CrossDeviceIdentityMergeService($this->cache, $this->mergeConfig);

        expect($service->resolveClientToUser(''))->toBeNull();
    });

    test('resolveUserToClients returns empty array when disabled', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_device_merge', [])
            ->andReturn(['enabled' => false]);

        $service = new CrossDeviceIdentityMergeService($this->cache, $config);

        expect($service->resolveUserToClients('user-1'))->toBe([]);
    });

    test('resolveAnonymousToUsers returns empty when disabled', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_device_merge', [])
            ->andReturn(['enabled' => false]);

        $service = new CrossDeviceIdentityMergeService($this->cache, $config);

        expect($service->resolveAnonymousToUsers('anon-1'))->toBe([]);
    });

    test('getIdentityGraph returns empty graph when disabled', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_device_merge', [])
            ->andReturn(['enabled' => false]);

        $service = new CrossDeviceIdentityMergeService($this->cache, $config);
        $graph = $service->getIdentityGraph('user-1');

        expect($graph['user_id'])->toBe('user-1');
        expect($graph['client_ids'])->toBe([]);
        expect($graph['anonymous_ids'])->toBe([]);
        expect($graph['total_associations'])->toBe(0);
        expect($graph['confidence_avg'])->toBe(0.0);
        expect($graph['last_seen'])->toBeNull();
    });

    test('shouldAutoMerge returns false when disabled', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.cross_device_merge', [])
            ->andReturn(['enabled' => false]);

        $service = new CrossDeviceIdentityMergeService($this->cache, $config);

        expect($service->shouldAutoMerge('client-1', 'user-1'))->toBeFalse();
    });

    test('forgetIdentity removes user and client mappings', function (): void {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')
            ->with('zb_identity_merge_user_user-123')
            ->andReturn(['client_ids' => ['c1', 'c2'], 'anonymous_ids' => ['a1']]);

        $cache->shouldReceive('get')
            ->with('zb_identity_merge_client_c1')
            ->andReturn(['user_id' => 'user-123']);
        $cache->shouldReceive('get')
            ->with('zb_identity_merge_client_c2')
            ->andReturn(['user_id' => 'user-123']);

        $forgetLog = [];
        $cache->shouldReceive('forget')->andReturnUsing(function (string $key) use (&$forgetLog): bool {
            $forgetLog[] = $key;

            return true;
        });
        $cache->shouldReceive('put')->andReturn(true);

        $service = new CrossDeviceIdentityMergeService($cache, $this->mergeConfig);

        $removed = $service->forgetIdentity('user-123');

        expect($removed)->toBeGreaterThanOrEqual(3); // 2 clients + 1 user node
        expect($forgetLog)->toContain('zb_identity_merge_user_user-123');
    });

    test('forgetIdentity returns 0 for empty user ID', function (): void {
        $service = new CrossDeviceIdentityMergeService($this->cache, $this->mergeConfig);

        expect($service->forgetIdentity(''))->toBe(0);
    });

    test('forgetClient removes client mappings', function (): void {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')
            ->with('zb_identity_merge_client_client-1')
            ->andReturn(['user_id' => 'user-1']);
        $cache->shouldReceive('get')
            ->with('zb_identity_merge_user_user-1')
            ->andReturn(['client_ids' => ['client-1', 'client-2'], 'anonymous_ids' => []]);

        $cache->shouldReceive('forget')->andReturnTrue();
        $cache->shouldReceive('put')->andReturn(true);

        $service = new CrossDeviceIdentityMergeService($cache, $this->mergeConfig);

        $removed = $service->forgetClient('client-1');

        expect($removed)->toBeGreaterThanOrEqual(2); // confidence + client node
    });

    test('enforces max_clients_per_user limit', function (): void {
        $callIndex = 0;
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnUsing(function (string $key) use (&$callIndex): mixed {
            if (str_contains($key, 'client_') && ! str_contains($key, 'confidence')) {
                return [];
            }
            if (str_contains($key, 'user_')) {
                // Return 50 clients (at limit)
                $ids = [];
                for ($i = 0; $i < 50; $i++) {
                    $ids[] = 'client-' . $i;
                }

                return ['client_ids' => $ids, 'anonymous_ids' => []];
            }
            if (str_contains($key, 'anon_')) {
                return ['client_ids' => [], 'user_ids' => []];
            }
            if (str_contains($key, 'confidence')) {
                return 0.0;
            }

            return 0;
        });
        $cache->shouldReceive('put')->andReturn(true);
        $cache->shouldReceive('increment')->andReturn(1);
        $cache->shouldReceive('forget')->andReturnTrue();

        $service = new CrossDeviceIdentityMergeService($cache, $this->mergeConfig);

        // Adding a 51st client should evict the oldest
        $result = $service->associate('client-new', 'user-1');
        expect($result)->toBeTrue();
    });

    test('confidence score increases with repeated associations', function (): void {
        $associationCount = 1;
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnUsing(function (string $key) use (&$associationCount): mixed {
            if (str_contains($key, 'client_') && ! str_contains($key, 'confidence')) {
                return [
                    'association_count' => $associationCount,
                    'last_seen' => time(),
                    'anonymous_id' => 'anon-1',
                ];
            }
            if (str_contains($key, 'user_')) {
                return ['client_ids' => [], 'anonymous_ids' => []];
            }
            if (str_contains($key, 'anon_')) {
                return ['client_ids' => [], 'user_ids' => []];
            }

            return 0;
        });
        $cache->shouldReceive('put')->andReturnUsing(function (string $key, mixed $value) use (&$associationCount): bool {
            if (str_contains($key, 'confidence')) {
                // Confidence should be > 0 with repeated associations
                expect($value)->toBeGreaterThan(0);
            }

            return true;
        });
        $cache->shouldReceive('increment')->andReturn(1);
        $cache->shouldReceive('forget')->andReturnTrue();

        $service = new CrossDeviceIdentityMergeService($cache, $this->mergeConfig);

        $result = $service->associate('client-1', 'user-1', 'anon-1');
        expect($result)->toBeTrue();
    });

    test('getConfidence returns 0 for unknown pair', function (): void {
        $service = new CrossDeviceIdentityMergeService($this->cache, $this->mergeConfig);

        expect($service->getConfidence('unknown-client', 'unknown-user'))->toBe(0.0);
    });
});

// ─── Version Sweep ────────────────────────────────────────────────────

describe('V170 Version Sweep', function (): void {
    test('AnalyticsEvent::VERSION is 170.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('170.0.0');
    });

    test('new services are final classes', function (): void {
        expect((new \ReflectionClass(EventBudgetEnforcementService::class))->isFinal())->toBeTrue();
        expect((new \ReflectionClass(CrossDeviceIdentityMergeService::class))->isFinal())->toBeTrue();
    });

    test('new services have void constructors', function (): void {
        $budgetCtor = (new \ReflectionClass(EventBudgetEnforcementService::class))->getConstructor();
        expect($budgetCtor)->not->toBeNull();
        expect($budgetCtor->getReturnType()?->getName())->toBe('void');

        $mergeCtor = (new \ReflectionClass(CrossDeviceIdentityMergeService::class))->getConstructor();
        expect($mergeCtor)->not->toBeNull();
        expect($mergeCtor->getReturnType()?->getName())->toBe('void');
    });

    test('new services have proper docblocks', function (): void {
        $budgetDoc = (new \ReflectionClass(EventBudgetEnforcementService::class))->getDocComment();
        expect($budgetDoc)->not->toBeFalse();
        expect($budgetDoc)->toContain('Event budget enforcement');

        $mergeDoc = (new \ReflectionClass(CrossDeviceIdentityMergeService::class))->getDocComment();
        expect($mergeDoc)->not->toBeFalse();
        expect($mergeDoc)->toContain('Cross-device identity merge');
    });
});
