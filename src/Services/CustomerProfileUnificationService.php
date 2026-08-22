<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * CDP-style customer profile unification service.
 *
 * Builds a single unified customer profile by aggregating identity data,
 * event history, user properties, and attribution context from multiple
 * analytics sources. Inspired by Segment, mParticle, and RudderStack CDPs.
 *
 * Profile structure:
 *   - identity: canonical user_id, client_ids, device_ids, external_ids
 *   - traits: aggregated user properties (plan, email, name, company, etc.)
 *   - segments: computed segment memberships (e.g., "trial_user", "power_user")
 *   - attribution: first-touch and last-touch UTM data
 *   - events: recent event summary (counts by category, last event timestamps)
 *   - lifetime: account age, session count, total events, total revenue
 *
 * Profiles are stored in cache with configurable TTL and can be:
 * - Built on-demand via `getProfile()`
 * - Updated incrementally via `updateFromEvent()`
 * - Merged when identities link via `mergeProfiles()`
 * - Exported via `exportProfile()` for API responses
 *
 * Configuration: `zeroboiler.analytics.cdp`
 *
 * @see \ZeroBoiler\Analytics\Services\UserPropertiesStore
 * @see \ZeroBoiler\Analytics\Services\IdentityResolutionService
 * @see \ZeroBoiler\Analytics\Services\IdentityGraphService
 *
 * @since 29.0.0
 */
final class CustomerProfileUnificationService
{
    private const CACHE_PREFIX = 'zb_cdp_profile_';
    private const SEGMENTS_KEY = 'zb_cdp_segments_';
    private const EXTERNAL_IDS_KEY = 'zb_cdp_external_';
    private const DEFAULT_TTL = 2592000; // 30 days
    private const MAX_RECENT_EVENTS = 50;
    private const MAX_EXTERNAL_IDS = 20;
    private const MAX_SEGMENTS = 30;

    private CacheRepository $cache;

    private UserPropertiesStore $propertiesStore;

    private IdentityResolutionService $identityResolution;

    private string $cachePrefix;

    private int $profileTtl;

    private int $maxRecentEvents;

    private bool $enabled;

    private bool $debug;

    /** @var array<string, callable(string, array<string, mixed>): array<string, mixed>> */
    private array $enrichers = [];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     * @param  UserPropertiesStore  $propertiesStore
     * @param  IdentityResolutionService  $identityResolution
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        UserPropertiesStore $propertiesStore,
        IdentityResolutionService $identityResolution,
    ){
        $this->cache = $cache;
        $this->propertiesStore = $propertiesStore;
        $this->identityResolution = $identityResolution;

        $cdpConfig = $config->get('zeroboiler.analytics.cdp', []);
        /** @var array{enabled?: bool, debug?: bool, cache_prefix?: string, profile_ttl?: int, max_recent_events?: int} $cdpConfig */

        $this->cachePrefix = (string) ($cdpConfig['cache_prefix'] ?? self::CACHE_PREFIX);
        $this->profileTtl = (int) ($cdpConfig['profile_ttl'] ?? self::DEFAULT_TTL);
        $this->maxRecentEvents = (int) ($cdpConfig['max_recent_events'] ?? self::MAX_RECENT_EVENTS);
        $this->enabled = (bool) ($cdpConfig['enabled'] ?? true);
        $this->debug = (bool) ($cdpConfig['debug'] ?? false);
    }

    /**
     * Build or retrieve the unified customer profile for an identity.
     *
     * Aggregates data from UserPropertiesStore, IdentityResolutionService,
     * AttributionService, and recent event history into a single profile object.
     *
     * @param  string  $identity  user_id or client_id
     * @return array{
     *     identity: array{user_id: string|null, client_ids: list<string>, canonical_id: string},
     *     traits: array<string, mixed>,
     *     segments: list<string>,
     *     events: array{total: int, by_category: array<string, int>, recent: list<array{name: string, timestamp: string|null}>},
     *     lifetime: array{account_age_days: int|null, first_seen: string|null, last_active: string|null, session_count: int, total_revenue: float},
     *     computed_at: string
     * }
     */
    public function getProfile(string $identity): array
    {
        if (! $this->enabled) {
            return $this->emptyProfile($identity);
        }

        // Check cache first
        $cacheKey = $this->cachePrefix . $identity;
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached) && isset($cached['computed_at'])) {
            return $cached;
        }

        // Resolve canonical identity
        $userId = $this->resolveUserId($identity);
        $canonicalId = $userId ?? $identity;

        // Build profile from multiple sources
        $profile = $this->buildProfile($canonicalId, $identity, $userId);

        // Run registered enrichers
        foreach ($this->enrichers as $enricher) {
            $profile = $enricher($canonicalId, $profile);
        }

        // Cache the result
        $this->cache->put($cacheKey, $profile, $this->profileTtl);

        if ($this->debug) {
            Log::debug('CDP: profile built', [
                'identity' => $identity,
                'canonical_id' => $canonicalId,
                'trait_count' => count($profile['traits']),
                'segment_count' => count($profile['segments']),
            ]);
        }

        return $profile;
    }

    /**
     * Update a profile incrementally from a new event.
     *
     * Adds the event to recent history, updates event counts,
     * refreshes last-active timestamp, and updates total event count.
     * More efficient than rebuilding the full profile on each event.
     *
     * @param  string  $identity  user_id or client_id
     * @param  string  $eventName  Event name
     * @param  string|null  $category  Event category (ecommerce, saas, engagement)
     * @param  array<string, mixed>  $params  Event parameters
     */
    public function updateFromEvent(string $identity, string $eventName, ?string $category = null, array $params = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $userId = $this->resolveUserId($identity);
        $canonicalId = $userId ?? $identity;

        // Increment event count
        $this->propertiesStore->increment($canonicalId, '_total_events', 1);

        // Update category count
        if ($category !== null) {
            $this->propertiesStore->increment($canonicalId, "_events_{$category}", 1);
        }

        // Update last active timestamp
        $this->propertiesStore->set($canonicalId, '_last_active', date('c'));

        // Update revenue if present
        if (isset($params['value']) && is_numeric($params['value'])) {
            $this->propertiesStore->aggregate($canonicalId, '_total_revenue', (float) $params['value'], 'sum');
        }

        // Track revenue by currency
        if (isset($params['currency']) && is_string($params['currency']) && isset($params['value']) && is_numeric($params['value'])) {
            $revenueKey = "_revenue_{$params['currency']}";
            $this->propertiesStore->aggregate($canonicalId, $revenueKey, (float) $params['value'], 'sum');
        }

        // Add to recent events list
        $recentEvents = $this->propertiesStore->get($canonicalId, '_recent_events', []);
        if (! is_array($recentEvents)) {
            $recentEvents = [];
        }

        $recentEvents[] = [
            'name' => $eventName,
            'category' => $category,
            'timestamp' => date('c'),
            'params_count' => count($params),
        ];

        // Keep only the most recent events
        if (count($recentEvents) > $this->maxRecentEvents) {
            $recentEvents = array_slice($recentEvents, -$this->maxRecentEvents);
        }

        $this->propertiesStore->set($canonicalId, '_recent_events', $recentEvents);

        // Invalidate cached profile so next getProfile() rebuilds
        $this->cache->forget($this->cachePrefix . $identity);
        if ($userId !== null) {
            $this->cache->forget($this->cachePrefix . $userId);
        }

        if ($this->debug) {
            Log::debug('CDP: profile updated from event', [
                'identity' => $identity,
                'canonical_id' => $canonicalId,
                'event' => $eventName,
                'category' => $category,
            ]);
        }
    }

    /**
     * Merge two profiles when an identity link is established.
     *
     * When a client_id is linked to a user_id, the anonymous profile's
     * event history and traits are merged into the authenticated profile.
     * The anonymous profile is then invalidated.
     *
     * @param  string  $clientId  Anonymous client ID
     * @param  string  $userId  Authenticated user ID
     * @return array{merged: bool, traits_merged: int, events_transferred: int}
     */
    public function mergeProfiles(string $clientId, string $userId): array
    {
        $clientProfile = $this->getRawProperties($clientId);
        $userProfile = $this->getRawProperties($userId);

        $traitsMerged = 0;
        $eventsTransferred = 0;

        // Merge traits (client props into user props, user props take precedence)
        foreach ($clientProfile as $key => $value) {
            if (! str_starts_with($key, '_')) {
                if (! isset($userProfile[$key])) {
                    $this->propertiesStore->set($userId, $key, $value);
                    $traitsMerged++;
                }
            }
        }

        // Merge event counts
        $clientTotalEvents = (int) ($clientProfile['_total_events'] ?? 0);
        $userTotalEvents = (int) ($userProfile['_total_events'] ?? 0);
        if ($clientTotalEvents > 0) {
            $this->propertiesStore->set($userId, '_total_events', $userTotalEvents + $clientTotalEvents);
            $eventsTransferred = $clientTotalEvents;
        }

        // Merge category counts
        foreach ($clientProfile as $key => $value) {
            if (str_starts_with($key, '_events_') && is_int($value)) {
                $existing = (int) ($userProfile[$key] ?? 0);
                $this->propertiesStore->set($userId, $key, $existing + $value);
            }
        }

        // Merge revenue
        $clientRevenue = (float) ($clientProfile['_total_revenue'] ?? 0);
        if ($clientRevenue > 0) {
            $userRevenue = (float) ($userProfile['_total_revenue'] ?? 0);
            $this->propertiesStore->set($userId, '_total_revenue', $userRevenue + $clientRevenue);
        }

        // Merge recent events (chronological, deduplicated)
        $clientEvents = is_array($clientProfile['_recent_events'] ?? null) ? $clientProfile['_recent_events'] : [];
        $userEvents = is_array($userProfile['_recent_events'] ?? null) ? $userProfile['_recent_events'] : [];
        $mergedEvents = array_merge($clientEvents, $userEvents);

        // Sort by timestamp descending, keep most recent
        usort($mergedEvents, fn (array $a, array $b): int => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
        $mergedEvents = array_slice($mergedEvents, 0, $this->maxRecentEvents);

        $this->propertiesStore->set($userId, '_recent_events', $mergedEvents);

        // Set first-seen from client if earlier
        $clientFirstSeen = $clientProfile['_first_seen'] ?? null;
        $userFirstSeen = $userProfile['_first_seen'] ?? null;
        if ($clientFirstSeen !== null && ($userFirstSeen === null || $clientFirstSeen < $userFirstSeen)) {
            $this->propertiesStore->set($userId, '_first_seen', $clientFirstSeen);
        }

        // Store the link timestamp
        $this->propertiesStore->set($userId, '_cdp_merged_at', date('c'));
        $this->propertiesStore->set($userId, '_cdp_merged_client', $clientId);

        // Invalidate both cached profiles
        $this->cache->forget($this->cachePrefix . $clientId);
        $this->cache->forget($this->cachePrefix . $userId);

        if ($this->debug) {
            Log::debug('CDP: profiles merged', [
                'client_id' => $clientId,
                'user_id' => $userId,
                'traits_merged' => $traitsMerged,
                'events_transferred' => $eventsTransferred,
            ]);
        }

        return [
            'merged' => true,
            'traits_merged' => $traitsMerged,
            'events_transferred' => $eventsTransferred,
        ];
    }

    /**
     * Get or set a profile trait.
     *
     * Traits are persistent user properties that represent user characteristics
     * (plan, email, company, role, etc.). These are the CDP "traits" concept.
     *
     * @param  string  $identity  user_id or client_id
     * @param  string  $key  Trait name
     * @param  mixed  $value  Trait value
     */
    public function setTrait(string $identity, string $key, mixed $value): void
    {
        if (! $this->enabled) {
            return;
        }

        $canonicalId = $this->resolveUserId($identity) ?? $identity;
        $this->propertiesStore->set($canonicalId, $key, $value);
        $this->invalidateProfile($canonicalId);
    }

    /**
     * Set multiple traits at once.
     *
     * @param  string  $identity  user_id or client_id
     * @param  array<string, mixed>  $traits  Key-value trait pairs
     */
    public function setTraits(string $identity, array $traits): void
    {
        if (! $this->enabled || empty($traits)) {
            return;
        }

        $canonicalId = $this->resolveUserId($identity) ?? $identity;
        $this->propertiesStore->merge($canonicalId, $traits);
        $this->invalidateProfile($canonicalId);
    }

    /**
     * Get a single profile trait.
     *
     * @param  string  $identity  user_id or client_id
     * @param  string  $key  Trait name
     * @param  mixed  $default  Default value if not set
     * @return mixed
     */
    public function getTrait(string $identity, string $key, mixed $default = null): mixed
    {
        $canonicalId = $this->resolveUserId($identity) ?? $identity;

        return $this->propertiesStore->get($canonicalId, $key, $default);
    }

    /**
     * Get all traits for an identity.
     *
     * @param  string  $identity  user_id or client_id
     * @return array<string, mixed>
     */
    public function getTraits(string $identity): array
    {
        $canonicalId = $this->resolveUserId($identity) ?? $identity;
        $all = $this->propertiesStore->all($canonicalId);

        // Filter out internal CDP keys
        return array_filter(
            $all,
            fn (string $key): bool => ! str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Add an external ID for cross-platform identity resolution.
     *
     * External IDs are used to link analytics identities to external
     * platform IDs (Stripe customer ID, HubSpot contact ID, Salesforce ID, etc.)
     *
     * @param  string  $identity  user_id or client_id
     * @param  string  $platform  Platform name (e.g., 'stripe', 'hubspot', 'salesforce')
     * @param  string  $externalId  External platform ID
     */
    public function addExternalId(string $identity, string $platform, string $externalId): void
    {
        if (! $this->enabled || $externalId === '') {
            return;
        }

        $canonicalId = $this->resolveUserId($identity) ?? $identity;
        $cacheKey = self::EXTERNAL_IDS_KEY . $canonicalId;

        /** @var array<string, string>|null $externalIds */
        $externalIds = $this->cache->get($cacheKey) ?? [];

        $externalIds[$platform] = $externalId;

        if (count($externalIds) > self::MAX_EXTERNAL_IDS) {
            $externalIds = array_slice($externalIds, -self::MAX_EXTERNAL_IDS, null, true);
        }

        $this->cache->put($cacheKey, $externalIds, $this->profileTtl);
        $this->invalidateProfile($canonicalId);
    }

    /**
     * Get all external IDs for an identity.
     *
     * @param  string  $identity  user_id or client_id
     * @return array<string, string> Platform → external ID mapping
     */
    public function getExternalIds(string $identity): array
    {
        $canonicalId = $this->resolveUserId($identity) ?? $identity;

        /** @var array<string, string>|null $externalIds */
        $externalIds = $this->cache->get(self::EXTERNAL_IDS_KEY . $canonicalId);

        return is_array($externalIds) ? $externalIds : [];
    }

    /**
     * Export a profile for API responses or provider user properties.
     *
     * Returns a clean, serializable representation without internal keys.
     *
     * @param  string  $identity  user_id or client_id
     * @return array{user_id: string|null, traits: array<string, mixed>, external_ids: array<string, string>, segments: list<string>, computed_at: string}
     */
    public function exportProfile(string $identity): array
    {
        $profile = $this->getProfile($identity);

        return [
            'user_id' => $profile['identity']['user_id'],
            'traits' => $profile['traits'],
            'external_ids' => $profile['identity']['external_ids'] ?? [],
            'segments' => $profile['segments'],
            'computed_at' => $profile['computed_at'],
        ];
    }

    /**
     * Register a profile enricher callback.
     *
     * Enrichers are called after the base profile is built and can add
     * or modify any section of the profile. Use this to integrate with
     * external systems (CRM, billing, etc.)
     *
     * @param  callable(string $canonicalId, array<string, mixed> $profile): array<string, mixed>  $enricher
     */
    public function registerEnricher(callable $enricher): void
    {
        $this->enrichers[] = $enricher;
    }

    /**
     * Delete a profile and all associated data (GDPR right to erasure).
     *
     * @param  string  $identity  user_id or client_id
     * @return array{deleted: bool, identity: string, external_ids_removed: int}
     */
    public function deleteProfile(string $identity): array
    {
        $canonicalId = $this->resolveUserId($identity) ?? $identity;

        // Delete cached profile
        $this->cache->forget($this->cachePrefix . $canonicalId);

        // Delete properties
        $this->propertiesStore->delete($canonicalId);

        // Delete external IDs
        $externalIdsKey = self::EXTERNAL_IDS_KEY . $canonicalId;
        /** @var array<string, string>|null $externalIds */
        $externalIds = $this->cache->get($externalIdsKey);
        $this->cache->forget($externalIdsKey);

        // Delete segments
        $this->cache->forget(self::SEGMENTS_KEY . $canonicalId);

        $externalIdCount = is_array($externalIds) ? count($externalIds) : 0;

        if ($this->debug) {
            Log::debug('CDP: profile deleted', [
                'identity' => $identity,
                'canonical_id' => $canonicalId,
                'external_ids_removed' => $externalIdCount,
            ]);
        }

        return [
            'deleted' => true,
            'identity' => $identity,
            'external_ids_removed' => $externalIdCount,
        ];
    }

    /**
     * Check if the CDP service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get CDP statistics.
     *
     * @return array{enabled: bool, enrichers: int, profile_ttl: int, max_recent_events: int}
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'enrichers' => count($this->enrichers),
            'profile_ttl' => $this->profileTtl,
            'max_recent_events' => $this->maxRecentEvents,
        ];
    }

    /**
     * Resolve a user ID from any identity string.
     *
     * @param  string  $identity
     * @return string|null
     */
    private function resolveUserId(string $identity): ?string
    {
        return $this->identityResolution->getUserIdForClient($identity);
    }

    /**
     * Build the complete unified profile.
     *
     * @param  string  $canonicalId
     * @param  string  $originalIdentity
     * @param  string|null  $userId
     * @return array<string, mixed>
     */
    private function buildProfile(string $canonicalId, string $originalIdentity, ?string $userId): array
    {
        $traits = $this->propertiesStore->all($canonicalId);
        $externalIds = $this->getExternalIds($canonicalId);
        $clientIds = $userId !== null
            ? $this->identityResolution->getClientIdsForUser($userId)
            : [];

        // Extract public traits (filter internal keys)
        $publicTraits = array_filter(
            $traits,
            fn (string $key): bool => ! str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        );

        // Extract lifetime metrics
        $firstSeen = $traits['_first_seen'] ?? null;
        $lastActive = $traits['_last_active'] ?? null;
        $sessionCount = (int) ($traits['_session_count'] ?? 0);
        $totalEvents = (int) ($traits['_total_events'] ?? 0);
        $totalRevenue = (float) ($traits['_total_revenue'] ?? 0);

        $accountAgeDays = null;
        if ($firstSeen !== null && is_string($firstSeen)) {
            $firstTimestamp = strtotime($firstSeen);
            if ($firstTimestamp !== false) {
                $accountAgeDays = (int) ((time() - $firstTimestamp) / 86400);
            }
        }

        // Extract category event counts
        $byCategory = [];
        foreach ($traits as $key => $value) {
            if (str_starts_with($key, '_events_') && is_int($value)) {
                $category = substr($key, 8); // Remove '_events_' prefix
                $byCategory[$category] = $value;
            }
        }

        // Extract recent events
        $recentEvents = is_array($traits['_recent_events'] ?? null) ? $traits['_recent_events'] : [];
        $recentEventsFormatted = array_map(
            fn (array $event): array => [
                'name' => $event['name'] ?? 'unknown',
                'timestamp' => $event['timestamp'] ?? null,
            ],
            array_slice($recentEvents, -$this->maxRecentEvents),
        );

        return [
            'identity' => [
                'user_id' => $userId,
                'client_ids' => $clientIds,
                'canonical_id' => $canonicalId,
                'external_ids' => $externalIds,
            ],
            'traits' => $publicTraits,
            'segments' => $this->getSegments($canonicalId),
            'events' => [
                'total' => $totalEvents,
                'by_category' => $byCategory,
                'recent' => $recentEventsFormatted,
            ],
            'lifetime' => [
                'account_age_days' => $accountAgeDays,
                'first_seen' => is_string($firstSeen) ? $firstSeen : null,
                'last_active' => is_string($lastActive) ? $lastActive : null,
                'session_count' => $sessionCount,
                'total_revenue' => $totalRevenue,
            ],
            'computed_at' => date('c'),
        ];
    }

    /**
     * Get computed segment memberships for an identity.
     *
     * Segments are derived from profile traits using simple rule evaluation.
     * For advanced segment logic, use ComputedTraitsService.
     *
     * @param  string  $canonicalId
     * @return list<string>
     */
    private function getSegments(string $canonicalId): array
    {
        $cached = $this->cache->get(self::SEGMENTS_KEY . $canonicalId);

        if (is_array($cached)) {
            return $cached;
        }

        $traits = $this->propertiesStore->all($canonicalId);
        $segments = $this->evaluateSegments($traits);

        $this->cache->put(self::SEGMENTS_KEY . $canonicalId, $segments, $this->profileTtl);

        return $segments;
    }

    /**
     * Evaluate built-in segment rules against traits.
     *
     * @param  array<string, mixed>  $traits
     * @return list<string>
     */
    private function evaluateSegments(array $traits): array
    {
        $segments = [];

        // Active user: had activity in the last 7 days
        $lastActive = $traits['_last_active'] ?? null;
        if (is_string($lastActive) && strtotime($lastActive) > (time() - 604800)) {
            $segments[] = 'active_user';
        }

        // New user: account less than 7 days old
        $firstSeen = $traits['_first_seen'] ?? null;
        if (is_string($firstSeen) && strtotime($firstSeen) > (time() - 604800)) {
            $segments[] = 'new_user';
        }

        // Power user: 100+ events
        $totalEvents = (int) ($traits['_total_events'] ?? 0);
        if ($totalEvents >= 100) {
            $segments[] = 'power_user';
        }

        // Revenue customer: has any revenue
        $totalRevenue = (float) ($traits['_total_revenue'] ?? 0);
        if ($totalRevenue > 0) {
            $segments[] = 'revenue_customer';
        }

        // Trial user: has trial-related traits
        if (isset($traits['plan']) && (string) $traits['plan'] === 'trial') {
            $segments[] = 'trial_user';
        }

        // Enterprise: has enterprise plan
        if (isset($traits['plan']) && str_contains((string) $traits['plan'], 'enterprise')) {
            $segments[] = 'enterprise';
        }

        // At-risk: no activity in 14+ days but has revenue
        if ($totalRevenue > 0 && is_string($lastActive) && strtotime($lastActive) < (time() - 1209600)) {
            $segments[] = 'at_risk';
        }

        // Churned: no activity in 30+ days
        if (is_string($lastActive) && strtotime($lastActive) < (time() - 2592000)) {
            $segments[] = 'churned';
        }

        return array_slice($segments, 0, self::MAX_SEGMENTS);
    }

    /**
     * Get raw properties for an identity.
     *
     * @param  string  $identity
     * @return array<string, mixed>
     */
    private function getRawProperties(string $identity): array
    {
        return $this->propertiesStore->all($identity);
    }

    /**
     * Invalidate a cached profile.
     *
     * @param  string  $canonicalId
     */
    private function invalidateProfile(string $canonicalId): void
    {
        $this->cache->forget($this->cachePrefix . $canonicalId);
        $this->cache->forget(self::SEGMENTS_KEY . $canonicalId);
    }

    /**
     * Build an empty profile for disabled mode.
     *
     * @param  string  $identity
     * @return array<string, mixed>
     */
    private function emptyProfile(string $identity): array
    {
        return [
            'identity' => [
                'user_id' => null,
                'client_ids' => [],
                'canonical_id' => $identity,
                'external_ids' => [],
            ],
            'traits' => [],
            'segments' => [],
            'events' => [
                'total' => 0,
                'by_category' => [],
                'recent' => [],
            ],
            'lifetime' => [
                'account_age_days' => null,
                'first_seen' => null,
                'last_active' => null,
                'session_count' => 0,
                'total_revenue' => 0.0,
            ],
            'computed_at' => date('c'),
        ];
    }
}
