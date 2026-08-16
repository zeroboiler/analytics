<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\CDP;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * CDP (Customer Data Platform) Profile Service — unified user profile management.
 *
 * Central hub for managing user analytics profiles. Combines:
 * - **Static traits**: Identity properties set via `identify()` (name, email, plan, company)
 * - **Computed traits**: Aggregated metrics from event history (total_revenue, session_count)
 * - **Segment memberships**: Dynamic group assignments based on trait conditions
 *
 * Provides:
 * - Profile CRUD operations (create, read, update, delete)
 * - Trait management (set, increment, remove, get)
 * - Profile snapshots for export to analytics providers
 * - Event-to-profile processing (auto-update computed traits from events)
 * - Segment evaluation and caching
 * - Provider sync (GA4 user_properties, PostHog $set, Mixpanel people.set)
 * - GDPR compliance (forget/erase user profile)
 *
 * Storage is cache-backed with configurable TTL. Designed for SaaS products
 * that need rich user profiles for personalization, segmentation, and attribution.
 *
 * Inspired by Segment Person/Group Traits, PostHog Person Properties,
 * Mixpanel User Profiles, and RudderStack User Enrichment.
 *
 * @see \ZeroBoiler\Analytics\CDP\CdpTraitComputer
 * @see \ZeroBoiler\Analytics\CDP\CdpSegmentService
 * @see \ZeroBoiler\Analytics\CDP\CdpProfileSnapshot
 *
 * @since 196.0.0
 */
final class CdpProfileService
{
    private const CACHE_PREFIX = 'zb_cdp_profile_';

    private const PROFILE_INDEX_KEY = 'zb_cdp_index_';

    private int $profileTtl;

    private int $indexTtl;

    private int $maxTraitsPerProfile;

    private int $maxSegmentsPerProfile;

    private readonly bool $enabled;

    private readonly CdpTraitComputer $traitComputer;

    private readonly CdpSegmentService $segmentService;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ) {
        $cdpConfig = $config->get('zeroboiler.analytics.cdp', []);
        /** @var array{enabled?: bool, profile_ttl?: int, index_ttl?: int, max_traits_per_profile?: int, max_segments_per_profile?: int} $cdpConfig */

        $this->enabled = (bool) ($cdpConfig['enabled'] ?? true);
        $this->profileTtl = (int) ($cdpConfig['profile_ttl'] ?? 7776000); // 90 days
        $this->indexTtl = (int) ($cdpConfig['index_ttl'] ?? 7776000); // 90 days
        $this->maxTraitsPerProfile = (int) ($cdpConfig['max_traits_per_profile'] ?? 200);
        $this->maxSegmentsPerProfile = (int) ($cdpConfig['max_segments_per_profile'] ?? 50);

        $this->traitComputer = new CdpTraitComputer($cache, $config);
        $this->segmentService = new CdpSegmentService($cache, $config);
    }

    /**
     * Create a new user profile.
     *
     * @param  string  $userId  Authenticated user ID
     * @param  array<string, mixed>  $initialTraits  Initial static traits (email, name, etc.)
     * @param  string|null  $anonymousId  Optional anonymous device ID to link
     * @return CdpProfileSnapshot  The created profile snapshot
     */
    public function createProfile(string $userId, array $initialTraits = [], ?string $anonymousId = null): CdpProfileSnapshot
    {
        $now = time();

        $profile = [
            'user_id' => $userId,
            'anonymous_id' => $anonymousId,
            'traits' => $this->filterTraits($initialTraits),
            'segments' => [],
            'created_at' => $now,
            'updated_at' => $now,
            'last_event_at' => null,
            'total_events' => 0,
            'total_sessions' => 0,
        ];

        $this->cache->put($this->profileKey($userId), $profile, $this->profileTtl);
        $this->addToIndex($userId);

        Log::info('ZeroBoiler CDP: Profile created', [
            'user_id' => $userId,
            'traits_count' => count($profile['traits']),
        ]);

        return CdpProfileSnapshot::fromArray($profile);
    }

    /**
     * Get a user's profile snapshot.
     *
     * Returns all traits (static + computed), segment memberships,
     * and metadata. Creates profile if it doesn't exist.
     *
     * @param  string  $userId
     * @param  bool  $includeComputed  Whether to include computed trait values
     * @return CdpProfileSnapshot
     */
    public function getProfile(string $userId, bool $includeComputed = true): CdpProfileSnapshot
    {
        if (! $this->enabled) {
            return new CdpProfileSnapshot(userId: $userId);
        }

        $profile = $this->getRawProfile($userId);

        if ($profile === null) {
            return $this->createProfile($userId);
        }

        // Merge computed traits if requested
        if ($includeComputed) {
            $computed = $this->traitComputer->computeAllTraits($userId);
            $profile['traits'] = array_merge($computed, $profile['traits']);
        }

        // Evaluate segments
        $profile['segments'] = $this->segmentService->evaluateSegments($profile['traits'], $userId);

        return CdpProfileSnapshot::fromArray($profile);
    }

    /**
     * Identify a user — set static identity traits.
     *
     * This is the primary method for updating user identity from identify calls.
     * Merges new traits with existing ones (new traits override existing).
     *
     * @param  string  $userId
     * @param  array<string, mixed>  $traits  Static traits to set
     * @param  string|null  $email  User email (also stored at top level for provider sync)
     * @param  string|null  $anonymousId  Optional anonymous ID to link
     * @return CdpProfileSnapshot  Updated profile
     */
    public function identify(string $userId, array $traits, ?string $email = null, ?string $anonymousId = null): CdpProfileSnapshot
    {
        if (! $this->enabled) {
            return new CdpProfileSnapshot(userId: $userId);
        }

        $profile = $this->getRawProfile($userId) ?? $this->createProfile($userId)->toArray();

        // Merge traits (new values override existing)
        $profile['traits'] = array_merge(
            $profile['traits'] ?? [],
            $this->filterTraits($traits),
        );

        // Enforce trait limit
        if (count($profile['traits']) > $this->maxTraitsPerProfile) {
            $profile['traits'] = array_slice($profile['traits'], -$this->maxTraitsPerProfile, null, true);
        }

        // Update email if provided
        if ($email !== null) {
            $profile['traits']['email'] = $email;
        }

        // Link anonymous ID if provided
        if ($anonymousId !== null) {
            $profile['anonymous_id'] = $anonymousId;
        }

        $profile['updated_at'] = time();

        $this->cache->put($this->profileKey($userId), $profile, $this->profileTtl);

        // Invalidate segment cache for re-evaluation
        $this->segmentService->invalidateCache($userId);

        Log::debug('ZeroBoiler CDP: User identified', [
            'user_id' => $userId,
            'traits_updated' => count($traits),
        ]);

        return $this->getProfile($userId);
    }

    /**
     * Set a single trait value.
     *
     * @param  string  $userId
     * @param  string  $name  Trait name
     * @param  mixed  $value  Trait value (null to remove)
     * @return bool
     */
    public function setTrait(string $userId, string $name, mixed $value): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $profile = $this->getRawProfile($userId);

        if ($profile === null) {
            $profile = $this->createProfile($userId)->toArray();
        }

        if ($value === null) {
            unset($profile['traits'][$name]);
        } else {
            $profile['traits'][$name] = $value;
        }

        $profile['updated_at'] = time();

        $this->cache->put($this->profileKey($userId), $profile, $this->profileTtl);
        $this->segmentService->invalidateCache($userId);

        return true;
    }

    /**
     * Increment a numeric trait value.
     *
     * @param  string  $userId
     * @param  string  $name  Trait name
     * @param  float|int  $amount  Amount to increment (can be negative)
     * @return float|null  New value after increment, or null if profile doesn't exist
     */
    public function incrementTrait(string $userId, string $name, float|int $amount = 1): ?float
    {
        if (! $this->enabled) {
            return null;
        }

        $profile = $this->getRawProfile($userId);

        if ($profile === null) {
            return null;
        }

        $current = (float) ($profile['traits'][$name] ?? 0);
        $profile['traits'][$name] = $current + (float) $amount;
        $profile['updated_at'] = time();

        $this->cache->put($this->profileKey($userId), $profile, $this->profileTtl);
        $this->segmentService->invalidateCache($userId);

        return (float) $profile['traits'][$name];
    }

    /**
     * Process an analytics event — update computed traits and profile metadata.
     *
     * Should be called by the event pipeline for every tracked event
     * when CDP processing is enabled.
     *
     * @param  AnalyticsEvent  $event
     * @param  string  $userId
     * @return array{updated_traits: list<string>, segments: list<string>, profile_updated: bool}
     */
    public function processEvent(AnalyticsEvent $event, string $userId): array
    {
        if (! $this->enabled) {
            return ['updated_traits' => [], 'segments' => [], 'profile_updated' => false];
        }

        // Ensure profile exists
        $profile = $this->getRawProfile($userId);
        if ($profile === null) {
            $profile = $this->createProfile($userId)->toArray();
        }

        // Update profile metadata
        $profile['last_event_at'] = time();
        $profile['total_events'] = ($profile['total_events'] ?? 0) + 1;
        $profile['updated_at'] = time();

        if ($event->name === 'session_start') {
            $profile['total_sessions'] = ($profile['total_sessions'] ?? 0) + 1;
        }

        $this->cache->put($this->profileKey($userId), $profile, $this->profileTtl);

        // Process event for computed traits
        $updatedTraits = $this->traitComputer->processEvent($event, $userId);

        // Invalidate segment cache if traits changed
        if ($updatedTraits !== []) {
            $this->segmentService->invalidateCache($userId);
        }

        // Get updated segments
        $allTraits = array_merge(
            $this->traitComputer->computeAllTraits($userId),
            $profile['traits'] ?? [],
        );
        $segments = $this->segmentService->evaluateSegments($allTraits, $userId);

        return [
            'updated_traits' => $updatedTraits,
            'segments' => $segments,
            'profile_updated' => true,
        ];
    }

    /**
     * Get provider-syncable traits for a user.
     *
     * Returns a flat map suitable for GA4 user_properties, PostHog $set,
     * Mixpanel people.set, etc.
     *
     * @param  string  $userId
     * @return array<string, mixed>
     */
    public function getProviderTraits(string $userId): array
    {
        return $this->getProfile($userId)->toProviderTraits();
    }

    /**
     * Get the trait computer for custom trait registration.
     *
     * @return CdpTraitComputer
     */
    public function traitComputer(): CdpTraitComputer
    {
        return $this->traitComputer;
    }

    /**
     * Get the segment service for custom segment registration.
     *
     * @return CdpSegmentService
     */
    public function segmentService(): CdpSegmentService
    {
        return $this->segmentService;
    }

    /**
     * Get a summary of all profiles in the system.
     *
     * @return array{total_profiles: int, total_segments: int, total_trait_definitions: int, enabled: bool}
     */
    public function getSummary(): array
    {
        $index = $this->getIndex();

        return [
            'total_profiles' => count($index),
            'total_segments' => count($this->segmentService->getSegments()),
            'total_trait_definitions' => count($this->traitComputer->getTraitDefinitions()),
            'enabled' => $this->enabled,
        ];
    }

    /**
     * Forget (erase) a user's profile — GDPR compliance.
     *
     * Removes all profile data, trait accumulators, computed values,
     * and segment caches for the user.
     *
     * @param  string  $userId
     * @return bool
     */
    public function forgetProfile(string $userId): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $removed = 0;

        // Remove profile
        if ($this->cache->forget($this->profileKey($userId))) {
            $removed++;
        }

        // Remove from index
        $index = $this->getIndex();
        $index = array_values(array_filter($index, fn (string $id): bool => $id !== $userId));
        $this->cache->put(self::PROFILE_INDEX_KEY, $index, $this->indexTtl);

        // Invalidate segment cache
        $this->segmentService->invalidateCache($userId);

        // Clear computed trait caches
        foreach ($this->traitComputer->getTraitDefinitions() as $name => $_) {
            $this->cache->forget('zb_cdp_traits_' . $userId . '_trait_' . $name);
            $this->cache->forget('zb_cdp_accum_' . $userId . '_' . $name);
        }

        Log::info('ZeroBoiler CDP: Profile erased (GDPR)', [
            'user_id' => $userId,
            'records_removed' => $removed,
        ]);

        return true;
    }

    /**
     * Check if a profile exists for a user.
     *
     * @param  string  $userId
     * @return bool
     */
    public function hasProfile(string $userId): bool
    {
        return $this->getRawProfile($userId) !== null;
    }

    /**
     * Filter traits to enforce type safety and size limits.
     *
     * @param  array<string, mixed>  $traits
     * @return array<string, mixed>
     */
    private function filterTraits(array $traits): array
    {
        $filtered = [];

        foreach ($traits as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            // Skip non-serializable values
            if (is_resource($value)) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * Get raw profile data from cache (without computed traits or segments).
     *
     * @param  string  $userId
     * @return array<string, mixed>|null
     */
    private function getRawProfile(string $userId): ?array
    {
        /** @var array<string, mixed>|null $profile */
        $profile = $this->cache->get($this->profileKey($userId));

        return is_array($profile) ? $profile : null;
    }

    /**
     * Get cache key for a user profile.
     *
     * @param  string  $userId
     * @return string
     */
    private function profileKey(string $userId): string
    {
        return self::CACHE_PREFIX . $userId;
    }

    /**
     * Get the user profile index (list of all known user IDs).
     *
     * @return list<string>
     */
    private function getIndex(): array
    {
        /** @var list<string>|null $index */
        $index = $this->cache->get(self::PROFILE_INDEX_KEY);

        return is_array($index) ? $index : [];
    }

    /**
     * Add a user ID to the profile index.
     *
     * @param  string  $userId
     * @return void
     */
    private function addToIndex(string $userId): void
    {
        $index = $this->getIndex();

        if (! in_array($userId, $index, true)) {
            $index[] = $userId;
            $this->cache->put(self::PROFILE_INDEX_KEY, $index, $this->indexTtl);
        }
    }
}
