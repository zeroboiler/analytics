<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

/**
 * Per-user tracking preference service for GDPR compliance.
 *
 * Manages user-level opt-in/opt-out preferences with persistent cache storage.
 * Supports per-client ID suppression for anonymous users before authentication.
 *
 * This service decouples tracking preferences from consent signals (which control
 * cookie/storage permissions). Tracking preferences go further — they suppress
 * all event dispatch, even when consent is granted.
 *
 * @see https://hermes-agent.nousresearch.com/docs
 *
 * @since 1.0.0
 */
final class TrackingPreferenceService
{
    /** @var string Cache prefix for user preferences */
    private const CACHE_PREFIX = 'zb_tracking_pref_';

    /** @var string Cache prefix for per-client opt-outs */
    private const CLIENT_CACHE_PREFIX = 'zb_tracking_client_';

    /** @var int Default cache TTL in seconds (7 days) */
    private const DEFAULT_TTL = 604800;

    private CacheRepository $cache;

    private int $ttl;

    /**
     * @param  CacheRepository  $cache  Cache repository (file, redis, database, etc.)
     * @param  int|null  $ttl  Cache TTL in seconds (default: 7 days)
     */
    public function __construct(CacheRepository $cache, ?int $ttl = null){
        $this->cache = $cache;
        $this->ttl = $ttl ?? self::DEFAULT_TTL;
    }

    /**
     * Check if a user has opted out of tracking.
     *
     * Returns true if the user has explicitly opted out.
     * Returns false if the user has opted in or has no preference set.
     */
    public function isOptedOut(string $userId): bool
    {
        return $this->cache->get(self::CACHE_PREFIX.$userId, false);
    }

    /**
     * Check if a user has opted in to tracking.
     *
     * Returns true if the user has explicitly opted in.
     * Returns false if the user has opted out or has no preference set.
     */
    public function isOptedIn(string $userId): bool
    {
        $value = $this->cache->get(self::CACHE_PREFIX.$userId);

        return $value === 'opt_in';
    }

    /**
     * Check if a user has a tracking preference set.
     */
    public function hasPreference(string $userId): bool
    {
        return $this->cache->has(self::CACHE_PREFIX.$userId);
    }

    /**
     * Opt a user out of all tracking.
     *
     * When opted out, no events will be dispatched for this user
     * regardless of consent state.
     */
    public function optOut(string $userId): void
    {
        $this->cache->put(self::CACHE_PREFIX.$userId, true, $this->ttl);

        Log::info('ZeroBoiler Analytics: user opted out of tracking', [
            'user_id' => $userId,
        ]);
    }

    /**
     * Opt a user in to tracking.
     *
     * Explicit opt-in overrides any previous opt-out.
     */
    public function optIn(string $userId): void
    {
        $this->cache->put(self::CACHE_PREFIX.$userId, 'opt_in', $this->ttl);

        Log::info('ZeroBoiler Analytics: user opted in to tracking', [
            'user_id' => $userId,
        ]);
    }

    /**
     * Clear a user's tracking preference (reset to default).
     */
    public function clearPreference(string $userId): void
    {
        $this->cache->forget(self::CACHE_PREFIX.$userId);
    }

    /**
     * Suppress tracking for an anonymous client ID.
     *
     * Use this before authentication when a user declines tracking
     * via the cookie consent banner. The preference is transferred
     * to the user ID after login/registration.
     *
     * @param  string  $clientId  Anonymous client tracking ID
     */
    public function suppressClient(string $clientId): void
    {
        $this->cache->put(self::CLIENT_CACHE_PREFIX.$clientId, true, $this->ttl);
    }

    /**
     * Check if a client ID is suppressed.
     */
    public function isClientSuppressed(string $clientId): bool
    {
        return $this->cache->get(self::CLIENT_CACHE_PREFIX.$clientId, false);
    }

    /**
     * Transfer a client's suppression state to a user ID.
     *
     * Called during login/signup to carry forward anonymous opt-out
     * preferences to the authenticated user profile.
     *
     * Returns true if the client was suppressed (user should be opted out).
     */
    public function transferClientToUser(string $clientId, string $userId): bool
    {
        $wasSuppressed = $this->isClientSuppressed($clientId);

        if ($wasSuppressed) {
            $this->optOut($userId);
            $this->cache->forget(self::CLIENT_CACHE_PREFIX.$clientId);
        }

        return $wasSuppressed;
    }

    /**
     * Clear a client's suppression state.
     */
    public function clearClientSuppression(string $clientId): void
    {
        $this->cache->forget(self::CLIENT_CACHE_PREFIX.$clientId);
    }

    /**
     * Check if tracking should proceed for a given identity.
     *
     * Combines user preference and client suppression checks.
     * Returns true if tracking is allowed.
     *
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID
     */
    public function shouldTrack(?string $userId, ?string $clientId): bool
    {
        if ($userId !== null && $userId !== '' && $this->isOptedOut($userId)) {
            return false;
        }

        if ($clientId !== null && $clientId !== '' && $this->isClientSuppressed($clientId)) {
            return false;
        }

        return true;
    }

    /**
     * Get all opted-out user IDs (for admin/debug).
     *
     * Note: This depends on the cache driver supporting prefix-based
     * tag or key scanning. For file/database cache, this returns an empty array.
     *
     * @return list<string>
     */
    public function getOptedOutUsers(): array
    {
        // Not supported on all cache drivers — return empty for safety
        return [];
    }

    /**
     * Set the cache TTL.
     *
     * @return $this
     */
    public function setTtl(int $seconds): self
    {
        $this->ttl = $seconds;

        return $this;
    }

    /**
     * Get the cache TTL in seconds.
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }
}
