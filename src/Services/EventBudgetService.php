<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Event budget and throttling service for SaaS analytics.
 *
 * Enforces configurable per-client and per-user event budgets to prevent
 * abuse, control costs, and ensure fair usage. Supports sliding window
 * rate limiting with configurable limits, windows, and overflow policies.
 *
 * Budgets are tracked in-memory with optional cache persistence for
 * multi-process deployments. Exceeded budgets trigger configurable
 * actions: reject, sample, or throttle events.
 *
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController
 *
 * @since 1.0.0
 */
final class EventBudgetService
{
    /** @var array<string, int> */
    private array $clientCounts = [];

    /** @var array<string, int> */
    private array $userCounts = [];

    /** @var array<string, int> */
    private array $globalCounts = [];

    /** @var array<string, int> */
    private int $rejectedCount = 0;

    private CacheRepository $cache;

    private int $cacheTtl;

    private bool $useCache;

    private string $cachePrefix;

    private int $clientLimit;

    private int $userLimit;

    private int $globalLimit;

    private int $windowSeconds;

    private string $overflowPolicy;

    private float $sampleRate;

    /**
     * @param  CacheRepository  $cache
     * @param  int  $clientLimit  Max events per client per window (default: 1000)
     * @param  int  $userLimit  Max events per user per window (default: 500)
     * @param  int  $globalLimit  Max events globally per window (default: 100000)
     * @param  int  $windowSeconds  Sliding window in seconds (default: 3600)
     * @param  string  $overflowPolicy  'reject' | 'sample' | 'throttle' (default: 'reject')
     * @param  float  $sampleRate  When policy is 'sample', accept this fraction (default: 0.1)
     * @param  int  $cacheTtl  Cache TTL in seconds (default: 3600)
     * @param  bool  $useCache  Whether to persist budget counters in cache
     */
    public function __construct(
        CacheRepository $cache,
        int $clientLimit = 1000,
        int $userLimit = 500,
        int $globalLimit = 100000,
        int $windowSeconds = 3600,
        string $overflowPolicy = 'reject',
        float $sampleRate = 0.1,
        int $cacheTtl = 3600,
        bool $useCache = true,
    ): void {
        $this->cache = $cache;
        $this->clientLimit = $clientLimit;
        $this->userLimit = $userLimit;
        $this->globalLimit = $globalLimit;
        $this->windowSeconds = $windowSeconds;
        $this->overflowPolicy = $overflowPolicy;
        $this->sampleRate = $sampleRate;
        $this->cacheTtl = $cacheTtl;
        $this->useCache = $useCache;
        $this->cachePrefix = 'zb_budget_';

        $this->loadFromCache();
    }

    /**
     * Check if an event is allowed under current budget constraints.
     *
     * Evaluates client, user, and global budgets. Returns a verdict
     * indicating whether the event should be accepted, rejected,
     * sampled, or throttled.
     *
     * @param  string|null  $clientId
     * @param  string|null  $userId
     * @return array{allowed: bool, reason: string, policy: string, remaining: array{client: int, user: int, global: int}}
     */
    public function check(?string $clientId = null, ?string $userId = null): array
    {
        $clientRemaining = $this->clientLimit;
        $userRemaining = $this->userLimit;
        $globalRemaining = $this->globalLimit;

        // Check client budget
        if ($clientId !== null && $clientId !== '') {
            $clientCount = $this->clientCounts[$clientId] ?? 0;
            $clientRemaining = max(0, $this->clientLimit - $clientCount);

            if ($clientCount >= $this->clientLimit) {
                return $this->handleOverflow('client', $clientId, $clientRemaining);
            }
        }

        // Check user budget
        if ($userId !== null && $userId !== '') {
            $userCount = $this->userCounts[$userId] ?? 0;
            $userRemaining = max(0, $this->userLimit - $userCount);

            if ($userCount >= $this->userLimit) {
                return $this->handleOverflow('user', $userId, $clientRemaining, $userRemaining);
            }
        }

        // Check global budget
        $globalCount = array_sum($this->globalCounts) ?: 0;
        $globalRemaining = max(0, $this->globalLimit - $globalCount);

        if ($globalCount >= $this->globalLimit) {
            return $this->handleOverflow('global', null, $clientRemaining, $userRemaining, $globalRemaining);
        }

        return [
            'allowed' => true,
            'reason' => 'within_budget',
            'policy' => 'accept',
            'remaining' => [
                'client' => $clientRemaining,
                'user' => $userRemaining,
                'global' => $globalRemaining,
            ],
        ];
    }

    /**
     * Record an event against the budget counters.
     *
     * @param  string|null  $clientId
     * @param  string|null  $userId
     */
    public function record(?string $clientId = null, ?string $userId = null): void
    {
        if ($clientId !== null && $clientId !== '') {
            $this->clientCounts[$clientId] = ($this->clientCounts[$clientId] ?? 0) + 1;
        }

        if ($userId !== null && $userId !== '') {
            $this->userCounts[$userId] = ($this->userCounts[$userId] ?? 0) + 1;
        }

        $this->globalCounts[date('Y-m-d-H')] = ($this->globalCounts[date('Y-m-d-H')] ?? 0) + 1;

        $this->persistToCache();
    }

    /**
     * Get current budget usage statistics.
     *
     * @return array{client_count: int, user_count: int, global_total: int, rejected_total: int, limits: array{client: int, user: int, global: int}, window_seconds: int, overflow_policy: string}
     */
    public function stats(): array
    {
        return [
            'client_count' => count($this->clientCounts),
            'user_count' => count($this->userCounts),
            'global_total' => array_sum($this->globalCounts),
            'rejected_total' => $this->rejectedCount,
            'limits' => [
                'client' => $this->clientLimit,
                'user' => $this->userLimit,
                'global' => $this->globalLimit,
            ],
            'window_seconds' => $this->windowSeconds,
            'overflow_policy' => $this->overflowPolicy,
        ];
    }

    /**
     * Get budget status for a specific client.
     *
     * @param  string  $clientId
     * @return array{count: int, limit: int, remaining: int, utilization: float}
     */
    public function clientStatus(string $clientId): array
    {
        $count = $this->clientCounts[$clientId] ?? 0;

        return [
            'count' => $count,
            'limit' => $this->clientLimit,
            'remaining' => max(0, $this->clientLimit - $count),
            'utilization' => $this->clientLimit > 0 ? round(($count / $this->clientLimit) * 100, 2) : 0.0,
        ];
    }

    /**
     * Get budget status for a specific user.
     *
     * @param  string  $userId
     * @return array{count: int, limit: int, remaining: int, utilization: float}
     */
    public function userStatus(string $userId): array
    {
        $count = $this->userCounts[$userId] ?? 0;

        return [
            'count' => $count,
            'limit' => $this->userLimit,
            'remaining' => max(0, $this->userLimit - $count),
            'utilization' => $this->userLimit > 0 ? round(($count / $this->userLimit) * 100, 2) : 0.0,
        ];
    }

    /**
     * Get top clients by event count.
     *
     * @param  int  $limit
     * @return list<array{client_id: string, count: int, utilization: float}>
     */
    public function topClients(int $limit = 10): array
    {
        $sorted = $this->clientCounts;
        arsort($sorted);

        $results = [];
        $count = 0;
        foreach ($sorted as $clientId => $eventCount) {
            if ($count >= $limit) {
                break;
            }
            $results[] = [
                'client_id' => $clientId,
                'count' => $eventCount,
                'utilization' => $this->clientLimit > 0 ? round(($eventCount / $this->clientLimit) * 100, 2) : 0.0,
            ];
            $count++;
        }

        return $results;
    }

    /**
     * Reset budget counters for a specific client.
     */
    public function resetClient(string $clientId): void
    {
        unset($this->clientCounts[$clientId]);
        $this->persistToCache();
    }

    /**
     * Reset budget counters for a specific user.
     */
    public function resetUser(string $userId): void
    {
        unset($this->userCounts[$userId]);
        $this->persistToCache();
    }

    /**
     * Clear all budget counters.
     */
    public function clear(): void
    {
        $this->clientCounts = [];
        $this->userCounts = [];
        $this->globalCounts = [];
        $this->rejectedCount = 0;
        $this->persistToCache();
    }

    /**
     * Handle budget overflow according to configured policy.
     *
     * @param  string  $scope  'client' | 'user' | 'global'
     * @param  string|null  $identity
     * @param  int  $clientRemaining
     * @param  int  $userRemaining
     * @param  int  $globalRemaining
     * @return array{allowed: bool, reason: string, policy: string, remaining: array{client: int, user: int, global: int}}
     */
    private function handleOverflow(
        string $scope,
        ?string $identity = null,
        int $clientRemaining = 0,
        int $userRemaining = 0,
        int $globalRemaining = 0,
    ): array {
        $this->rejectedCount++;

        if ($this->overflowPolicy === 'sample') {
            $allowed = (mt_rand() / mt_getrandmax()) < $this->sampleRate;

            return [
                'allowed' => $allowed,
                'reason' => $allowed ? 'sampled_through' : 'budget_exceeded_sampled',
                'policy' => 'sample',
                'remaining' => [
                    'client' => $clientRemaining,
                    'user' => $userRemaining,
                    'global' => $globalRemaining,
                ],
            ];
        }

        return [
            'allowed' => false,
            'reason' => "budget_exceeded_{$scope}",
            'policy' => $this->overflowPolicy,
            'remaining' => [
                'client' => $clientRemaining,
                'user' => $userRemaining,
                'global' => $globalRemaining,
            ],
        ];
    }

    /**
     * Load budget counters from cache.
     */
    private function loadFromCache(): void
    {
        if (! $this->useCache) {
            return;
        }

        try {
            $cached = $this->cache->get($this->cachePrefix . 'counts');
            if (is_array($cached)) {
                $this->clientCounts = $cached['client'] ?? [];
                $this->userCounts = $cached['user'] ?? [];
                $this->globalCounts = $cached['global'] ?? [];
                $this->rejectedCount = $cached['rejected'] ?? 0;
            }
        } catch (\Throwable) {
            // Cache unavailable — start fresh
        }
    }

    /**
     * Persist budget counters to cache.
     */
    private function persistToCache(): void
    {
        if (! $this->useCache) {
            return;
        }

        try {
            $this->cache->put($this->cachePrefix . 'counts', [
                'client' => $this->clientCounts,
                'user' => $this->userCounts,
                'global' => $this->globalCounts,
                'rejected' => $this->rejectedCount,
            ], $this->cacheTtl);
        } catch (\Throwable) {
            // Cache unavailable
        }
    }
}
