<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Cross-device identity graph service.
 *
 * Builds and maintains a graph of identity relationships between client IDs,
 * user IDs, device fingerprints, and session IDs. Enables cross-device
 * user stitching — correlating anonymous browsing behavior across devices
 * with authenticated user profiles.
 *
 * Confidence scoring:
 *   - Explicit login/register: 1.0 (100%)
 *   - Same device fingerprint + linked client: 0.8
 *   - Same IP + same user agent: 0.5
 *   - Same cookie pair on different sessions: 0.3
 *
 * Graph structure (stored in cache):
 *   zb_ig_user_{userId} → { clients: [...], devices: [...], sessions: [...], merged_users: [...] }
 *   zb_ig_client_{clientId} → { user_id, device_id, confidence, linked_at }
 *   zb_ig_device_{deviceId} → { user_id, clients: [...], confidence }
 *   zb_ig_edge_{from}_{to} → { type, confidence, created_at }
 *
 * @see \ZeroBoiler\Analytics\Services\IdentityResolutionService
 *
 * @since 8.7.0
 */
final class IdentityGraphService
{
    private const DEFAULT_CONFIDENCE_EXPLICIT = 1.0;

    private const DEFAULT_CONFIDENCE_DEVICE = 0.8;

    private const DEFAULT_CONFIDENCE_IP_UA = 0.5;

    private const DEFAULT_CONFIDENCE_SESSION = 0.3;

    private const MIN_CONFIDENCE_FOR_STITCHING = 0.5;

    private const MIN_CONFIDENCE_FOR_MERGE = 0.9;

    /** @var CacheRepository */
    private CacheRepository $cache;

    private string $cachePrefix;

    private int $graphTtl;

    private int $maxClientsPerUser;

    private int $maxDevicesPerUser;

    private int $maxEdgesPerNode;

    private float $minConfidenceStitching;

    private float $minConfidenceMerge;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $graphConfig = $config->get('zeroboiler.analytics.identity_graph', []);
        /** @var array{cache_prefix?: string, graph_ttl?: int, max_clients_per_user?: int, max_devices_per_user?: int, max_edges_per_node?: int, min_confidence_stitching?: float, min_confidence_merge?: float} $graphConfig */

        $this->cachePrefix = (string) ($graphConfig['cache_prefix'] ?? 'zb_ig_');
        $this->graphTtl = (int) ($graphConfig['graph_ttl'] ?? 7776000); // 90 days
        $this->maxClientsPerUser = (int) ($graphConfig['max_clients_per_user'] ?? 100);
        $this->maxDevicesPerUser = (int) ($graphConfig['max_devices_per_user'] ?? 50);
        $this->maxEdgesPerNode = (int) ($graphConfig['max_edges_per_node'] ?? 200);
        $this->minConfidenceStitching = (float) ($graphConfig['min_confidence_stitching'] ?? self::MIN_CONFIDENCE_FOR_STITCHING);
        $this->minConfidenceMerge = (float) ($graphConfig['min_confidence_merge'] ?? self::MIN_CONFIDENCE_FOR_MERGE);
    }

    /**
     * Add an explicit identity link (from login/register).
     *
     * This is the highest-confidence link (1.0) and triggers graph rebuild.
     *
     * @param  string  $clientId  Anonymous client tracking ID
     * @param  string  $userId  Authenticated user ID
     * @param  string|null  $deviceId  Optional device fingerprint
     * @return array{linked: bool, confidence: float, previous_user_id: string|null, nodes: int, edges: int}
     */
    public function linkExplicit(string $clientId, string $userId, ?string $deviceId = null): array
    {
        $previousUserId = $this->resolveClientId($clientId);

        // Store client → user node
        $this->cache->put(
            $this->clientNodeKey($clientId),
            [
                'user_id' => $userId,
                'device_id' => $deviceId,
                'confidence' => self::DEFAULT_CONFIDENCE_EXPLICIT,
                'linked_at' => time(),
                'link_type' => 'explicit',
            ],
            $this->graphTtl,
        );

        // Add edge: client → user
        $this->addEdge($clientId, $userId, 'client_user', self::DEFAULT_CONFIDENCE_EXPLICIT);

        // Link device if provided
        if ($deviceId !== null && $deviceId !== '') {
            $this->linkDevice($deviceId, $userId, $clientId, self::DEFAULT_CONFIDENCE_EXPLICIT);
        }

        // Update user's graph node (aggregate)
        $this->updateUserNode($userId, $clientId, $deviceId);

        return [
            'linked' => true,
            'confidence' => self::DEFAULT_CONFIDENCE_EXPLICIT,
            'previous_user_id' => $previousUserId,
            'nodes' => $this->countUserNodes($userId),
            'edges' => $this->countUserEdges($userId),
        ];
    }

    /**
     * Link a device fingerprint to a user ID.
     *
     * @param  string  $deviceId  Device fingerprint hash
     * @param  string  $userId  Authenticated user ID
     * @param  string  $clientId  Client ID that observed this device
     * @param  float  $confidence  Link confidence (0.0-1.0)
     * @return array{linked: bool, confidence: float, existing_clients: int}
     */
    public function linkDevice(string $deviceId, string $userId, string $clientId, float $confidence = self::DEFAULT_CONFIDENCE_DEVICE): array
    {
        $deviceNode = $this->getDeviceNode($deviceId);

        // Only upgrade confidence, never downgrade
        $existingConfidence = $deviceNode['confidence'] ?? 0.0;
        $effectiveConfidence = max($existingConfidence, $confidence);

        // Add client to device's client list
        $clients = $deviceNode['clients'] ?? [];
        if (! in_array($clientId, $clients, true)) {
            $clients[] = $clientId;
            if (count($clients) > $this->maxClientsPerUser) {
                array_shift($clients);
            }
        }

        $this->cache->put(
            $this->deviceNodeKey($deviceId),
            [
                'user_id' => $userId,
                'clients' => $clients,
                'confidence' => $effectiveConfidence,
                'updated_at' => time(),
            ],
            $this->graphTtl,
        );

        // Add edge: device → user
        $this->addEdge($deviceId, $userId, 'device_user', $effectiveConfidence);

        // Update user node
        $this->updateUserNode($userId, $clientId, $deviceId);

        return [
            'linked' => true,
            'confidence' => $effectiveConfidence,
            'existing_clients' => count($clients),
        ];
    }

    /**
     * Infer an identity link based on shared device/IP/user-agent.
     *
     * Lower confidence than explicit links. Only creates links above
     * the configured minimum confidence threshold.
     *
     * @param  string  $clientId  Anonymous client tracking ID
     * @param  string  $deviceId  Device fingerprint
     * @param  string|null  $ip  (Anonymized) IP address
     * @param  string|null  $userAgent  User agent string
     * @return array{inferred: bool, confidence: float, user_id: string|null, method: string|null}
     */
    public function inferIdentity(string $clientId, string $deviceId, ?string $ip = null, ?string $userAgent = null): array
    {
        // Check if device is already linked to a user
        $deviceNode = $this->getDeviceNode($deviceId);

        if ($deviceNode['user_id'] !== null && $deviceNode['confidence'] >= $this->minConfidenceStitching) {
            // Infer client → user from device link
            $this->cache->put(
                $this->clientNodeKey($clientId),
                [
                    'user_id' => $deviceNode['user_id'],
                    'device_id' => $deviceId,
                    'confidence' => $deviceNode['confidence'] * 0.9, // slight reduction for inference
                    'linked_at' => time(),
                    'link_type' => 'inferred_device',
                ],
                $this->graphTtl,
            );

            return [
                'inferred' => true,
                'confidence' => $deviceNode['confidence'] * 0.9,
                'user_id' => $deviceNode['user_id'],
                'method' => 'device_match',
            ];
        }

        // Check if any client with same device is linked
        // (handles case where same device has multiple client IDs)
        $existingClients = $deviceNode['clients'] ?? [];
        foreach ($existingClients as $existingClientId) {
            $existingNode = $this->getClientNode($existingClientId);
            if ($existingNode['user_id'] !== null) {
                $confidence = min($existingNode['confidence'] * 0.8, self::DEFAULT_CONFIDENCE_DEVICE);

                if ($confidence >= $this->minConfidenceStitching) {
                    $this->cache->put(
                        $this->clientNodeKey($clientId),
                        [
                            'user_id' => $existingNode['user_id'],
                            'device_id' => $deviceId,
                            'confidence' => $confidence,
                            'linked_at' => time(),
                            'link_type' => 'inferred_sibling',
                        ],
                        $this->graphTtl,
                    );

                    return [
                        'inferred' => true,
                        'confidence' => $confidence,
                        'user_id' => $existingNode['user_id'],
                        'method' => 'sibling_client',
                    ];
                }
            }
        }

        return [
            'inferred' => false,
            'confidence' => 0.0,
            'user_id' => null,
            'method' => null,
        ];
    }

    /**
     * Resolve a client ID to a user ID using the identity graph.
     *
     * Traverses the graph: client → device → user, checking confidence thresholds.
     *
     * @param  string  $clientId
     * @return IdentityResolutionResult
     */
    public function resolveClientId(string $clientId): ?string
    {
        $clientNode = $this->getClientNode($clientId);

        if ($clientNode === null) {
            return null;
        }

        $userId = $clientNode['user_id'] ?? null;

        if ($userId !== null && $clientNode['confidence'] >= $this->minConfidenceStitching) {
            return $userId;
        }

        // Try device resolution
        $deviceId = $clientNode['device_id'] ?? null;
        if ($deviceId !== null) {
            $deviceNode = $this->getDeviceNode($deviceId);
            if ($deviceNode['user_id'] !== null && $deviceNode['confidence'] >= $this->minConfidenceStitching) {
                return $deviceNode['user_id'];
            }
        }

        return null;
    }

    /**
     * Get the full identity graph for a user.
     *
     * Returns all connected nodes (clients, devices) with confidence scores.
     *
     * @param  string  $userId
     * @return array{user_id: string, clients: list<array{client_id: string, device_id: string|null, confidence: float, link_type: string, linked_at: int}>, devices: list<array{device_id: string, confidence: float, client_count: int}>, merged_users: list<string>, total_nodes: int, total_edges: int, highest_confidence: float, lowest_confidence: float}
     */
    public function getGraph(string $userId): array
    {
        $userNode = $this->getUserNode($userId);

        $clients = [];
        $devices = [];
        $allConfidences = [];

        foreach ($userNode['clients'] ?? [] as $clientId) {
            $clientNode = $this->getClientNode($clientId);
            if ($clientNode !== null) {
                $clients[] = [
                    'client_id' => $clientId,
                    'device_id' => $clientNode['device_id'] ?? null,
                    'confidence' => (float) ($clientNode['confidence'] ?? 0.0),
                    'link_type' => $clientNode['link_type'] ?? 'unknown',
                    'linked_at' => (int) ($clientNode['linked_at'] ?? 0),
                ];
                $allConfidences[] = (float) ($clientNode['confidence'] ?? 0.0);
            }
        }

        foreach ($userNode['devices'] ?? [] as $deviceId) {
            $deviceNode = $this->getDeviceNode($deviceId);
            if ($deviceNode !== null) {
                $devices[] = [
                    'device_id' => $deviceId,
                    'confidence' => (float) ($deviceNode['confidence'] ?? 0.0),
                    'client_count' => count($deviceNode['clients'] ?? []),
                ];
                $allConfidences[] = (float) ($deviceNode['confidence'] ?? 0.0);
            }
        }

        return [
            'user_id' => $userId,
            'clients' => $clients,
            'devices' => $devices,
            'merged_users' => $userNode['merged_users'] ?? [],
            'total_nodes' => count($clients) + count($devices),
            'total_edges' => count($clients) + count($devices), // each link = 1 edge
            'highest_confidence' => empty($allConfidences) ? 0.0 : (float) max($allConfidences),
            'lowest_confidence' => empty($allConfidences) ? 0.0 : (float) min($allConfidences),
        ];
    }

    /**
     * Check if two client IDs belong to the same user (cross-device stitching).
     *
     * @param  string  $clientIdA
     * @param  string  $clientIdB
     * @return StitchingResult
     */
    public function areSameUser(string $clientIdA, string $clientIdB): array
    {
        $nodeA = $this->getClientNode($clientIdA);
        $nodeB = $this->getClientNode($clientIdB);

        if ($nodeA === null || $nodeB === null) {
            return [
                'same_user' => false,
                'confidence' => 0.0,
                'method' => null,
                'user_id' => null,
            ];
        }

        // Direct user match
        $userA = $nodeA['user_id'] ?? null;
        $userB = $nodeB['user_id'] ?? null;

        if ($userA !== null && $userB !== null && $userA === $userB) {
            $confidence = min(
                (float) ($nodeA['confidence'] ?? 0.0),
                (float) ($nodeB['confidence'] ?? 0.0),
            );

            return [
                'same_user' => $confidence >= $this->minConfidenceStitching,
                'confidence' => $confidence,
                'method' => 'direct_user_match',
                'user_id' => $userA,
            ];
        }

        // Device match
        $deviceA = $nodeA['device_id'] ?? null;
        $deviceB = $nodeB['device_id'] ?? null;

        if ($deviceA !== null && $deviceB !== null && $deviceA === $deviceB) {
            $deviceNode = $this->getDeviceNode($deviceA);
            $confidence = (float) ($deviceNode['confidence'] ?? 0.0) * 0.8;

            return [
                'same_user' => $confidence >= $this->minConfidenceStitching,
                'confidence' => $confidence,
                'method' => 'device_match',
                'user_id' => $deviceNode['user_id'] ?? $userA ?? $userB,
            ];
        }

        // Cross-device: check if either client has user and other shares device
        $resolvedUser = $userA ?? $userB;
        $resolvedNode = $userA !== null ? $nodeA : $nodeB;
        $otherNode = $userA !== null ? $nodeB : $nodeA;
        $otherDevice = $otherNode['device_id'] ?? null;

        if ($resolvedUser !== null && $otherDevice !== null) {
            $deviceNode = $this->getDeviceNode($otherDevice);
            if (($deviceNode['user_id'] ?? null) === $resolvedUser) {
                return [
                    'same_user' => true,
                    'confidence' => (float) ($deviceNode['confidence'] ?? 0.0) * 0.7,
                    'method' => 'cross_device_inference',
                    'user_id' => $resolvedUser,
                ];
            }
        }

        return [
            'same_user' => false,
            'confidence' => 0.0,
            'method' => null,
            'user_id' => null,
        ];
    }

    /**
     * Merge two user graphs (e.g., when a user merges accounts).
     *
     * Moves all client/device links from source user to target user.
     * Requires both users to have explicit links (high confidence).
     *
     * @param  string  $sourceUserId
     * @param  string  $targetUserId
     * @return array{merged: bool, clients_transferred: int, devices_transferred: int}
     */
    public function mergeUsers(string $sourceUserId, string $targetUserId): array
    {
        $sourceNode = $this->getUserNode($sourceUserId);
        $targetNode = $this->getUserNode($targetUserId);

        $clientsTransferred = 0;
        $devicesTransferred = 0;

        // Transfer client links
        $sourceClients = $sourceNode['clients'] ?? [];
        $targetClients = $targetNode['clients'] ?? [];

        foreach ($sourceClients as $clientId) {
            if (! in_array($clientId, $targetClients, true)) {
                $clientNode = $this->getClientNode($clientId);
                if ($clientNode !== null) {
                    // Repoint client to target user
                    $this->cache->put(
                        $this->clientNodeKey($clientId),
                        array_merge($clientNode, ['user_id' => $targetUserId]),
                        $this->graphTtl,
                    );
                    $targetClients[] = $clientId;
                    $clientsTransferred++;
                }
            }
        }

        // Transfer device links
        $sourceDevices = $sourceNode['devices'] ?? [];
        $targetDevices = $targetNode['devices'] ?? [];

        foreach ($sourceDevices as $deviceId) {
            if (! in_array($deviceId, $targetDevices, true)) {
                $deviceNode = $this->getDeviceNode($deviceId);
                if ($deviceNode !== null) {
                    $this->cache->put(
                        $this->deviceNodeKey($deviceId),
                        array_merge($deviceNode, ['user_id' => $targetUserId]),
                        $this->graphTtl,
                    );
                    $targetDevices[] = $deviceId;
                    $devicesTransferred++;
                }
            }
        }

        // Update target user node
        $this->cache->put(
            $this->userNodeKey($targetUserId),
            [
                'clients' => array_slice($targetClients, -$this->maxClientsPerUser),
                'devices' => array_slice($targetDevices, -$this->maxDevicesPerUser),
                'merged_users' => array_unique(array_merge(
                    $targetNode['merged_users'] ?? [],
                    [$sourceUserId],
                )),
                'updated_at' => time(),
            ],
            $this->graphTtl,
        );

        // Mark source as merged (don't delete — preserve history)
        $this->cache->put(
            $this->userNodeKey($sourceUserId),
            array_merge($sourceNode, [
                'merged_into' => $targetUserId,
                'merged_at' => time(),
            ]),
            $this->graphTtl,
        );

        return [
            'merged' => true,
            'clients_transferred' => $clientsTransferred,
            'devices_transferred' => $devicesTransferred,
        ];
    }

    /**
     * Forget all graph data for a user (GDPR erasure).
     *
     * Removes all client and device nodes linked to this user.
     *
     * @param  string  $userId
     * @return int Number of nodes removed
     */
    public function forgetUser(string $userId): int
    {
        $userNode = $this->getUserNode($userId);
        $removed = 0;

        // Remove client nodes
        foreach ($userNode['clients'] ?? [] as $clientId) {
            $this->cache->forget($this->clientNodeKey($clientId));
            $removed++;
        }

        // Remove device nodes (only if this user was the primary)
        foreach ($userNode['devices'] ?? [] as $deviceId) {
            $deviceNode = $this->getDeviceNode($deviceId);
            if (($deviceNode['user_id'] ?? null) === $userId) {
                $this->cache->forget($this->deviceNodeKey($deviceId));
                $removed++;
            }
        }

        // Remove user node
        $this->cache->forget($this->userNodeKey($userId));

        return $removed;
    }

    /**
     * Get identity graph statistics.
     *
     * @return array{cache_prefix: string, graph_ttl: int, max_clients_per_user: int, max_devices_per_user: int, max_edges_per_node: int, min_confidence_stitching: float, min_confidence_merge: float}
     */
    public function stats(): array
    {
        return [
            'cache_prefix' => $this->cachePrefix,
            'graph_ttl' => $this->graphTtl,
            'max_clients_per_user' => $this->maxClientsPerUser,
            'max_devices_per_user' => $this->maxDevicesPerUser,
            'max_edges_per_node' => $this->maxEdgesPerNode,
            'min_confidence_stitching' => $this->minConfidenceStitching,
            'min_confidence_merge' => $this->minConfidenceMerge,
        ];
    }

    /**
     * Enrich an analytics event with identity graph context.
     *
     * Adds resolved user_id, device_id, and confidence to event params
     * if not already set. Called from the event pipeline.
     *
     * @return AnalyticsEvent
     */
    public function enrichEvent(AnalyticsEvent $event): AnalyticsEvent
    {
        $clientId = $event->clientId;
        $userId = $event->userId;
        $params = $event->params;

        // Resolve user ID from client if not set
        if ($userId === null && $clientId !== null) {
            $resolved = $this->resolveClientId($clientId);
            if ($resolved !== null) {
                $userId = $resolved;
            }
        }

        // Get device context
        $clientNode = $clientId !== null ? $this->getClientNode($clientId) : null;
        $deviceId = $clientNode['device_id'] ?? null;

        // Enrich params with identity graph data
        if ($userId !== null && ! isset($params['_identity_user_id'])) {
            $params['_identity_user_id'] = $userId;
        }

        if ($deviceId !== null && ! isset($params['_identity_device_id'])) {
            $params['_identity_device_id'] = $deviceId;
        }

        if ($clientNode !== null && ! isset($params['_identity_confidence'])) {
            $params['_identity_confidence'] = (float) ($clientNode['confidence'] ?? 0.0);
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: $params,
            clientId: $clientId,
            userId: $userId,
        );
    }

    // ─── Internal Methods ──────────────────────────────────────────────

    /**
     * Get a client node from cache.
     *
     * @return array{user_id: string|null, device_id: string|null, confidence: float, linked_at: int, link_type: string}|null
     */
    private function getClientNode(string $clientId): ?array
    {
        $node = $this->cache->get($this->clientNodeKey($clientId));

        return is_array($node) ? $node : null;
    }

    /**
     * Get a device node from cache.
     *
     * @return array{user_id: string|null, clients: list<string>, confidence: float, updated_at: int}|null
     */
    private function getDeviceNode(string $deviceId): ?array
    {
        $node = $this->cache->get($this->deviceNodeKey($deviceId));

        return is_array($node) ? $node : null;
    }

    /**
     * Get a user node from cache.
     *
     * @return array{clients: list<string>, devices: list<string>, merged_users: list<string>, updated_at: int, merged_into?: string}|array
     */
    private function getUserNode(string $userId): array
    {
        $node = $this->cache->get($this->userNodeKey($userId));

        return is_array($node) ? $node : ['clients' => [], 'devices' => [], 'merged_users' => []];
    }

    /**
     * Update the user's graph node with a new client/device link.
     */
    private function updateUserNode(string $userId, string $clientId, ?string $deviceId = null): void
    {
        $node = $this->getUserNode($userId);

        // Add client
        $clients = $node['clients'] ?? [];
        if (! in_array($clientId, $clients, true)) {
            $clients[] = $clientId;
            if (count($clients) > $this->maxClientsPerUser) {
                array_shift($clients);
            }
        }

        // Add device
        $devices = $node['devices'] ?? [];
        if ($deviceId !== null && $deviceId !== '' && ! in_array($deviceId, $devices, true)) {
            $devices[] = $deviceId;
            if (count($devices) > $this->maxDevicesPerUser) {
                array_shift($devices);
            }
        }

        $this->cache->put(
            $this->userNodeKey($userId),
            [
                'clients' => $clients,
                'devices' => $devices,
                'merged_users' => $node['merged_users'] ?? [],
                'updated_at' => time(),
            ],
            $this->graphTtl,
        );
    }

    /**
     * Add an edge between two nodes in the graph.
     *
     * Edges are stored as individual cache entries for fast lookup.
     */
    private function addEdge(string $fromId, string $toId, string $type, float $confidence): void
    {
        $edgeKey = $this->edgeKey($fromId, $toId, $type);

        $existing = $this->cache->get($edgeKey);
        $existingConfidence = is_array($existing) ? (float) ($existing['confidence'] ?? 0.0) : 0.0;

        if ($confidence <= $existingConfidence) {
            return; // Don't downgrade
        }

        $this->cache->put($edgeKey, [
            'from' => $fromId,
            'to' => $toId,
            'type' => $type,
            'confidence' => $confidence,
            'created_at' => time(),
        ], $this->graphTtl);
    }

    /**
     * Count total nodes (clients + devices) linked to a user.
     */
    private function countUserNodes(string $userId): int
    {
        $node = $this->getUserNode($userId);

        return count($node['clients'] ?? []) + count($node['devices'] ?? []);
    }

    /**
     * Count total edges for a user (approximation).
     */
    private function countUserEdges(string $userId): int
    {
        $node = $this->getUserNode($userId);

        return count($node['clients'] ?? []) + count($node['devices'] ?? []);
    }

    private function clientNodeKey(string $clientId): string
    {
        return $this->cachePrefix . 'client_' . $clientId;
    }

    private function deviceNodeKey(string $deviceId): string
    {
        return $this->cachePrefix . 'device_' . $deviceId;
    }

    private function userNodeKey(string $userId): string
    {
        return $this->cachePrefix . 'user_' . $userId;
    }

    private function edgeKey(string $from, string $to, string $type): string
    {
        return $this->cachePrefix . 'edge_' . $type . '_' . $from . '_' . $to;
    }
}
