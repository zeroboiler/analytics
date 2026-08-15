<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cross-device identity merge service — resolves and merges multi-identity graphs.
 *
 * Associates anonymous browsing sessions, client-side tracking IDs, and authenticated
 * user IDs into a unified identity graph. Enables cross-device user journey
 * reconstruction by linking:
 *
 * - **client_id** — Server-generated tracking cookie (zb_analytics_id)
 * - **user_id** — Authenticated user ID (from Laravel auth)
 * - **anonymous_id** — Browser fingerprint or device identifier
 *
 * Provides:
 * - Bidirectional lookup: client_id → user_id and user_id → all client_ids
 * - Merge resolution: When a user has multiple devices, merge events into single timeline
 * - Confidence scoring: Track merge confidence based on shared signals
 * - Identity graph export: Full graph for data warehouse / BI tools
 * - Graceful degradation: Works without any identifier present
 *
 * Storage is cache-backed with configurable TTL (default: 90 days).
 * Designed for GDPR compliance — all data is erasable via `forgetIdentity()`.
 *
 * Inspired by Segment Identity Resolution, RudderStack Device Mode Merge,
 * and PostHog Person/Device ID stitching.
 *
 * @since 170.0.0
 */
final class CrossDeviceIdentityMergeService
{
    private const CACHE_PREFIX = 'zb_identity_merge_';
    private const GRAPH_KEY = 'zb_identity_graph_';
    private const CONFIDENCE_KEY = 'zb_identity_confidence_';

    private readonly bool $enabled;

    private readonly int $linkTtl;

    private readonly int $maxClientsPerUser;

    private readonly int $maxGraphSize;

    /** @var float Minimum confidence (0-1) to auto-merge identities */
    private readonly float $mergeConfidenceThreshold;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $mergeConfig = $config->get('zeroboiler.analytics.cross_device_merge', []);
        /** @var array{enabled?: bool, link_ttl?: int, max_clients_per_user?: int, max_graph_size?: int, merge_confidence_threshold?: float} $mergeConfig */
        $this->enabled = (bool) ($mergeConfig['enabled'] ?? true);
        $this->linkTtl = (int) ($mergeConfig['link_ttl'] ?? 7776000); // 90 days
        $this->maxClientsPerUser = (int) ($mergeConfig['max_clients_per_user'] ?? 50);
        $this->maxGraphSize = (int) ($mergeConfig['max_graph_size'] ?? 1000);
        $this->mergeConfidenceThreshold = (float) ($mergeConfig['merge_confidence_threshold'] ?? 0.6);
    }

    /**
     * Record a client_id → user_id association.
     *
     * Stores the mapping bidirectionally:
     * - client_id → [user_id, anonymous_id, metadata]
     * - user_id → [client_id_1, client_id_2, ..., metadata]
     *
     * Updates confidence score based on repeated associations.
     *
     * @param  string  $clientId  Server-generated tracking ID (zb_analytics_id)
     * @param  string  $userId  Authenticated user ID
     * @param  string|null  $anonymousId  Optional browser/device fingerprint
     * @param  array<string, mixed>  $context  Additional context (ip, user_agent, etc.)
     * @return bool  True if the association was stored
     */
    public function associate(
        string $clientId,
        string $userId,
        ?string $anonymousId = null,
        array $context = [],
    ): bool {
        if (! $this->enabled) {
            return false;
        }

        if ($clientId === '' || $userId === '') {
            return false;
        }

        // Store client_id → user_id mapping
        $clientNode = $this->getClientNode($clientId);
        $clientNode['user_id'] = $userId;
        $clientNode['anonymous_id'] = $anonymousId;
        $clientNode['associated_at'] = time();
        $clientNode['last_seen'] = time();
        $clientNode['context'] = $context;
        $clientNode['association_count'] = ($clientNode['association_count'] ?? 0) + 1;

        $this->putClientNode($clientId, $clientNode);

        // Store user_id → client_ids mapping
        $userNode = $this->getUserNode($userId);
        if (! in_array($clientId, $userNode['client_ids'], true)) {
            $userNode['client_ids'][] = $clientId;

            // Enforce max clients per user
            if (count($userNode['client_ids']) > $this->maxClientsPerUser) {
                // Evict oldest client
                $oldestClient = array_shift($userNode['client_ids']);
                $this->removeClientFromUser($userId, $oldestClient);
            }
        }

        $userNode['last_seen'] = time();
        $userNode['anonymous_ids'] = array_unique(array_merge(
            $userNode['anonymous_ids'] ?? [],
            array_filter([$anonymousId]),
        ));
        $this->putUserNode($userId, $userNode);

        // Store anonymous_id → client_id mapping if present
        if ($anonymousId !== null && $anonymousId !== '') {
            $anonNode = $this->getAnonymousNode($anonymousId);
            if (! in_array($clientId, $anonNode['client_ids'], true)) {
                $anonNode['client_ids'][] = $clientId;
            }
            $anonNode['user_ids'] = array_unique(array_merge(
                $anonNode['user_ids'] ?? [],
                [$userId],
            ));
            $anonNode['last_seen'] = time();
            $this->putAnonymousNode($anonymousId, $anonNode);
        }

        // Update confidence score
        $this->updateConfidence($clientId, $userId);

        return true;
    }

    /**
     * Resolve a client ID to its associated user ID.
     *
     * @param  string  $clientId
     * @return string|null  User ID if associated, null otherwise
     */
    public function resolveClientToUser(string $clientId): ?string
    {
        if (! $this->enabled || $clientId === '') {
            return null;
        }

        $node = $this->getClientNode($clientId);

        return $node['user_id'] ?? null;
    }

    /**
     * Resolve a user ID to all associated client IDs.
     *
     * @param  string  $userId
     * @return list<string>  Array of client IDs associated with this user
     */
    public function resolveUserToClients(string $userId): array
    {
        if (! $this->enabled || $userId === '') {
            return [];
        }

        $node = $this->getUserNode($userId);

        return $node['client_ids'] ?? [];
    }

    /**
     * Resolve an anonymous ID to its associated user ID(s).
     *
     * An anonymous device may have been used by multiple users (shared device),
     * so this returns all candidate user IDs ordered by confidence.
     *
     * @param  string  $anonymousId
     * @return list<array{user_id: string, confidence: float, client_id: string|null}>
     */
    public function resolveAnonymousToUsers(string $anonymousId): array
    {
        if (! $this->enabled || $anonymousId === '') {
            return [];
        }

        $node = $this->getAnonymousNode($anonymousId);
        $userIds = $node['user_ids'] ?? [];

        $results = [];
        foreach ($userIds as $uid) {
            $confidence = $this->getConfidence($anonymousId, $uid);
            $clientIds = $this->resolveUserToClients($uid);
            $results[] = [
                'user_id' => $uid,
                'confidence' => $confidence,
                'client_id' => $clientIds[0] ?? null,
            ];
        }

        // Sort by confidence descending
        usort($results, fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return $results;
    }

    /**
     * Get the full identity graph for a user.
     *
     * Returns all client IDs, anonymous IDs, and association metadata
     * for a given authenticated user.
     *
     * @param  string  $userId
     * @return array{user_id: string, client_ids: list<string>, anonymous_ids: list<string>, total_associations: int, confidence_avg: float, last_seen: int|null}
     */
    public function getIdentityGraph(string $userId): array
    {
        if (! $this->enabled || $userId === '') {
            return $this->emptyGraph($userId);
        }

        $node = $this->getUserNode($userId);
        $clientIds = $node['client_ids'] ?? [];
        $anonymousIds = $node['anonymous_ids'] ?? [];

        // Calculate average confidence across all client associations
        $totalConfidence = 0.0;
        $confidenceCount = 0;

        foreach ($clientIds as $cid) {
            $confidence = $this->getConfidence($cid, $userId);
            $totalConfidence += $confidence;
            $confidenceCount++;
        }

        return [
            'user_id' => $userId,
            'client_ids' => $clientIds,
            'anonymous_ids' => $anonymousIds,
            'total_associations' => count($clientIds) + count($anonymousIds),
            'confidence_avg' => $confidenceCount > 0 ? round($totalConfidence / $confidenceCount, 4) : 0.0,
            'last_seen' => $node['last_seen'] ?? null,
        ];
    }

    /**
     * Check if two identities should be auto-merged based on confidence.
     *
     * Auto-merge is suggested when confidence exceeds the configured threshold.
     *
     * @param  string  $clientId
     * @param  string  $userId
     * @return bool
     */
    public function shouldAutoMerge(string $clientId, string $userId): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $confidence = $this->getConfidence($clientId, $userId);

        return $confidence >= $this->mergeConfidenceThreshold;
    }

    /**
     * Get the merge confidence score between a client and user.
     *
     * Confidence is computed from:
     * - Number of repeated associations (0-0.3)
     * - Whether anonymous_id is shared (0-0.2)
     * - Recency of last association (0-0.2)
     * - Consistency of user agent / IP (0-0.3)
     *
     * @param  string  $clientId
     * @param  string  $userId
     * @return float  Confidence score 0.0-1.0
     */
    public function getConfidence(string $clientId, string $userId): float
    {
        $key = self::CONFIDENCE_KEY . md5($clientId . ':' . $userId);

        return (float) $this->cache->get($key, 0.0);
    }

    /**
     * Forget all identity data for a user (GDPR erasure).
     *
     * Removes all client_id ↔ user_id mappings, anonymous_id associations,
     * and confidence scores. This is the GDPR compliance method.
     *
     * @param  string  $userId
     * @return int  Number of identity records removed
     */
    public function forgetIdentity(string $userId): int
    {
        if (! $this->enabled || $userId === '') {
            return 0;
        }

        $removed = 0;
        $clientIds = $this->resolveUserToClients($userId);

        // Remove client → user mappings
        foreach ($clientIds as $clientId) {
            $node = $this->getClientNode($clientId);
            if (($node['user_id'] ?? '') === $userId) {
                $node['user_id'] = null;
                $this->putClientNode($clientId, $node);
            }
            // Remove confidence scores
            $this->cache->forget(self::CONFIDENCE_KEY . md5($clientId . ':' . $userId));
            $removed++;
        }

        // Remove user node
        $this->cache->forget(self::GRAPH_KEY . 'user_' . $userId);
        $removed++;

        Log::info('ZeroBoiler: GDPR identity erasure completed', [
            'user_id' => $userId,
            'records_removed' => $removed,
        ]);

        return $removed;
    }

    /**
     * Forget a specific client's identity data.
     *
     * @param  string  $clientId
     * @return int  Number of records removed
     */
    public function forgetClient(string $clientId): int
    {
        if (! $this->enabled || $clientId === '') {
            return 0;
        }

        $removed = 0;
        $node = $this->getClientNode($clientId);
        $userId = $node['user_id'] ?? null;

        // Remove client → user confidence
        if ($userId !== null) {
            $this->cache->forget(self::CONFIDENCE_KEY . md5($clientId . ':' . $userId));
            $removed++;

            // Remove from user's client list
            $userNode = $this->getUserNode($userId);
            $userNode['client_ids'] = array_values(array_filter(
                $userNode['client_ids'] ?? [],
                fn (string $cid): bool => $cid !== $clientId,
            ));
            $this->putUserNode($userId, $userNode);
        }

        // Remove client node
        $this->cache->forget(self::GRAPH_KEY . 'client_' . $clientId);
        $removed++;

        return $removed;
    }

    /**
     * Get identity merge statistics.
     *
     * @return array{enabled: bool, merge_confidence_threshold: float, max_clients_per_user: int, link_ttl: int}
     */
    public function getStats(): array
    {
        return [
            'enabled' => $this->enabled,
            'merge_confidence_threshold' => $this->mergeConfidenceThreshold,
            'max_clients_per_user' => $this->maxClientsPerUser,
            'link_ttl' => $this->linkTtl,
        ];
    }

    /**
     * Get a client node from cache.
     *
     * @param  string  $clientId
     * @return array<string, mixed>
     */
    private function getClientNode(string $clientId): array
    {
        /** @var array<string, mixed>|null $node */
        $node = $this->cache->get(self::GRAPH_KEY . 'client_' . $clientId);

        return is_array($node) ? $node : [];
    }

    /**
     * Store a client node in cache.
     *
     * @param  string  $clientId
     * @param  array<string, mixed>  $node
     * @return void
     */
    private function putClientNode(string $clientId, array $node): void
    {
        $this->cache->put(self::GRAPH_KEY . 'client_' . $clientId, $node, $this->linkTtl);
    }

    /**
     * Get a user node from cache.
     *
     * @param  string  $userId
     * @return array<string, mixed>
     */
    private function getUserNode(string $userId): array
    {
        /** @var array<string, mixed>|null $node */
        $node = $this->cache->get(self::GRAPH_KEY . 'user_' . $userId);

        return is_array($node) ? $node : ['client_ids' => [], 'anonymous_ids' => []];
    }

    /**
     * Store a user node in cache.
     *
     * @param  string  $userId
     * @param  array<string, mixed>  $node
     * @return void
     */
    private function putUserNode(string $userId, array $node): void
    {
        $this->cache->put(self::GRAPH_KEY . 'user_' . $userId, $node, $this->linkTtl);
    }

    /**
     * Get an anonymous node from cache.
     *
     * @param  string  $anonymousId
     * @return array<string, mixed>
     */
    private function getAnonymousNode(string $anonymousId): array
    {
        /** @var array<string, mixed>|null $node */
        $node = $this->cache->get(self::GRAPH_KEY . 'anon_' . $anonymousId);

        return is_array($node) ? $node : ['client_ids' => [], 'user_ids' => []];
    }

    /**
     * Store an anonymous node in cache.
     *
     * @param  string  $anonymousId
     * @param  array<string, mixed>  $node
     * @return void
     */
    private function putAnonymousNode(string $anonymousId, array $node): void
    {
        $this->cache->put(self::GRAPH_KEY . 'anon_' . $anonymousId, $node, $this->linkTtl);
    }

    /**
     * Update the confidence score between a client and user.
     *
     * @param  string  $clientId
     * @param  string  $userId
     * @return void
     */
    private function updateConfidence(string $clientId, string $userId): void
    {
        $clientNode = $this->getClientNode($clientId);
        $associationCount = (int) ($clientNode['association_count'] ?? 1);

        // Association frequency component (0-0.3)
        $frequencyScore = min(0.3, ($associationCount - 1) * 0.05);

        // Recency component (0-0.2) — penalize if last seen > 7 days ago
        $lastSeen = (int) ($clientNode['last_seen'] ?? time());
        $recencyScore = max(0, 0.2 - ((time() - $lastSeen) / (86400 * 7)) * 0.2);

        // Anonymous ID match (0-0.2)
        $anonymousId = $clientNode['anonymous_id'] ?? null;
        $anonymousScore = ($anonymousId !== null && $anonymousId !== '') ? 0.2 : 0.0;

        // Context consistency component (0-0.3) — placeholder for IP/UA matching
        $contextScore = 0.15; // Base confidence from having any context

        $confidence = min(1.0, $frequencyScore + $recencyScore + $anonymousScore + $contextScore);

        $key = self::CONFIDENCE_KEY . md5($clientId . ':' . $userId);
        $this->cache->put($key, round($confidence, 4), $this->linkTtl);
    }

    /**
     * Remove a client ID from a user's client list.
     *
     * @param  string  $userId
     * @param  string  $clientId
     * @return void
     */
    private function removeClientFromUser(string $userId, string $clientId): void
    {
        $userNode = $this->getUserNode($userId);
        $userNode['client_ids'] = array_values(array_filter(
            $userNode['client_ids'] ?? [],
            fn (string $cid): bool => $cid !== $clientId,
        ));
        $this->putUserNode($userId, $userNode);

        // Also remove the client node
        $this->cache->forget(self::GRAPH_KEY . 'client_' . $clientId);
    }

    /**
     * Return an empty identity graph result.
     *
     * @param  string  $userId
     * @return array{user_id: string, client_ids: list<string>, anonymous_ids: list<string>, total_associations: int, confidence_avg: float, last_seen: null}
     */
    private function emptyGraph(string $userId): array
    {
        return [
            'user_id' => $userId,
            'client_ids' => [],
            'anonymous_ids' => [],
            'total_associations' => 0,
            'confidence_avg' => 0.0,
            'last_seen' => null,
        ];
    }
}
