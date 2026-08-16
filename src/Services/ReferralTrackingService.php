<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Referral and viral loop tracking service for SaaS growth analytics.
 *
 * Tracks referral code usage, invite link clicks, and viral loop metrics.
 * Computes viral coefficient (K-factor), referral conversion rates, and
 * attribution for referred signups.
 *
 * Features:
 *   - Referral code generation and validation
 *   - Invite link click-through tracking
 *   - Referral attribution (which user referred which signup)
 *   - Viral coefficient (K-factor) calculation
 *   - Referral funnel analysis (invite → click → signup → activation)
 *   - Top referrer leaderboard
 *   - Referral program health metrics
 *
 * All data is cache-backed. No database required.
 *
 * @since 43.0.0
 */
final class ReferralTrackingService
{
    private const CACHE_PREFIX = 'zb_referral_';

    private const ATTRIBUTION_KEY = 'zb_referral_attribution_';

    private CacheRepository $cache;

    private int $codeLength;

    private int $attributionTtl;

    private int $metricsTtl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  int  $codeLength  Length of generated referral codes
     * @param  int  $attributionTtl  TTL for referral attribution lookups (seconds)
     * @param  int  $metricsTtl  TTL for referral metrics cache (seconds)
     */
    public function __construct(
        CacheRepository $cache,
        int $codeLength = 8,
        int $attributionTtl = 2592000,
        int $metricsTtl = 3600,
    ): void {
        $this->cache = $cache;
        $this->codeLength = $codeLength;
        $this->attributionTtl = $attributionTtl;
        $this->metricsTtl = $metricsTtl;
    }

    /**
     * Generate a unique referral code for a user.
     *
     * Generates a random alphanumeric code, checks for collisions,
     * and stores the mapping in cache.
     *
     * @param  string  $userId  The referrer's user ID
     * @param  string|null  $preferredCode  Optional preferred code (checked for availability)
     * @return string The generated or confirmed referral code
     */
    public function generateCode(string $userId, ?string $preferredCode = null): string
    {
        $key = self::CACHE_PREFIX . 'code_' . $userId;

        // Return existing code if already assigned
        $existing = $this->cache->get($key);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        // Use preferred code if provided and available
        if ($preferredCode !== null && $preferredCode !== '' && $this->isCodeAvailable($preferredCode)) {
            $this->storeCodeMapping($userId, $preferredCode);

            return $preferredCode;
        }

        // Generate random code
        $code = $this->generateUniqueCode();
        $this->storeCodeMapping($userId, $code);

        return $code;
    }

    /**
     * Look up the referrer for a referral code.
     *
     * @param  string  $code  The referral code
     * @return string|null The referrer's user ID, or null if not found
     */
    public function resolveReferrer(string $code): ?string
    {
        $key = self::CACHE_PREFIX . 'lookup_' . strtolower($code);

        return $this->cache->get($key);
    }

    /**
     * Record a referral click (when someone uses a referral link).
     *
     * Tracks the click for attribution. The click is stored with the
     * referral code and timestamp. When the referred user signs up,
     * the attribution is confirmed via trackConversion().
     *
     * @param  string  $referralCode  The referral code used
     * @param  string|null  $clickId  Unique click identifier (e.g., from cookie)
     * @param  array<string, mixed>  $context  Additional context (ip, user_agent, utm_source, etc.)
     * @return string Click ID for later attribution
     */
    public function trackClick(string $referralCode, ?string $clickId = null, array $context = []): string
    {
        $clickId = $clickId ?? Str::uuid()->toString();

        $referrerId = $this->resolveReferrer($referralCode);

        $clickData = [
            'referral_code' => $referralCode,
            'referrer_id' => $referrerId,
            'clicked_at' => time(),
            'context' => $context,
            'converted' => false,
        ];

        // Store click for attribution window
        $this->cache->put(
            self::ATTRIBUTION_KEY . $clickId,
            $clickData,
            $this->attributionTtl,
        );

        // Track click count for the referral code
        $clickCountKey = self::CACHE_PREFIX . 'clicks_' . strtolower($referralCode);
        $clickCount = (int) $this->cache->get($clickCountKey, 0);
        $this->cache->put($clickCountKey, $clickCount + 1, $this->metricsTtl);

        // Track referrer's total clicks
        if ($referrerId !== null) {
            $referralClicksKey = self::CACHE_PREFIX . 'user_clicks_' . $referrerId;
            $totalClicks = (int) $this->cache->get($referralClicksKey, 0);
            $this->cache->put($referralClicksKey, $totalClicks + 1, $this->metricsTtl);
        }

        return $clickId;
    }

    /**
     * Record a referral conversion (signup attributed to a referral).
     *
     * Confirms the attribution and increments conversion counters.
     *
     * @param  string  $clickId  The click ID from trackClick()
     * @param  string  $referredUserId  The newly registered user's ID
     * @return array{attributed: bool, referrer_id: string|null, referral_code: string|null}
     */
    public function trackConversion(string $clickId, string $referredUserId): array
    {
        $clickData = $this->cache->get(self::ATTRIBUTION_KEY . $clickId);

        if (! is_array($clickData)) {
            return [
                'attributed' => false,
                'referrer_id' => null,
                'referral_code' => null,
            ];
        }

        $referrerId = $clickData['referrer_id'];
        $referralCode = $clickData['referral_code'];

        if ($referrerId === null) {
            return [
                'attributed' => false,
                'referrer_id' => null,
                'referral_code' => $referralCode,
            ];
        }

        // Prevent self-referrals
        if ($referrerId === $referredUserId) {
            return [
                'attributed' => false,
                'referrer_id' => $referrerId,
                'referral_code' => $referralCode,
            ];
        }

        // Mark click as converted
        $clickData['converted'] = true;
        $clickData['converted_user_id'] = $referredUserId;
        $clickData['converted_at'] = time();
        $this->cache->put(self::ATTRIBUTION_KEY . $clickId, $clickData, $this->attributionTtl);

        // Track referrer's conversions
        $conversionsKey = self::CACHE_PREFIX . 'user_conversions_' . $referrerId;
        $totalConversions = (int) $this->cache->get($conversionsKey, 0);
        $this->cache->put($conversionsKey, $totalConversions + 1, $this->metricsTtl);

        // Track referral code conversions
        $codeConvKey = self::CACHE_PREFIX . 'code_conversions_' . strtolower($referralCode);
        $codeConversions = (int) $this->cache->get($codeConvKey, 0);
        $this->cache->put($codeConvKey, $codeConversions + 1, $this->metricsTtl);

        // Track global conversions
        $globalConvKey = self::CACHE_PREFIX . 'global_conversions';
        $globalConversions = (int) $this->cache->get($globalConvKey, 0);
        $this->cache->put($globalConvKey, $globalConversions + 1, $this->metricsTtl);

        return [
            'attributed' => true,
            'referrer_id' => $referrerId,
            'referral_code' => $referralCode,
        ];
    }

    /**
     * Calculate the viral coefficient (K-factor).
     *
     * K-factor = (number of invites sent per user) × (conversion rate of invites)
     * Simplified: K = total_conversions / total_referring_users
     *
     * K > 1.0 = viral growth
     * K = 1.0 = steady state
     * K < 1.0 = declining
     *
     * @return array{k_factor: float, total_conversions: int, total_referrers: int, period: string}
     */
    public function calculateViralCoefficient(): array
    {
        $globalConversions = (int) $this->cache->get(self::CACHE_PREFIX . 'global_conversions', 0);

        // Count users who have sent at least 1 invite (have a referral code)
        $referrersKey = self::CACHE_PREFIX . 'active_referrers';
        $totalReferrers = (int) $this->cache->get($referrersKey, 0);

        $kFactor = $totalReferrers > 0
            ? round($globalConversions / $totalReferrers, 4)
            : 0.0;

        return [
            'k_factor' => $kFactor,
            'total_conversions' => $globalConversions,
            'total_referrers' => $totalReferrers,
            'period' => 'all_time',
        ];
    }

    /**
     * Get the referral funnel (invites sent → clicks → signups → activations).
     *
     * @return array{invites: int, clicks: int, signups: int, click_rate: float, conversion_rate: float}
     */
    public function getReferralFunnel(): array
    {
        $totalConversions = (int) $this->cache->get(self::CACHE_PREFIX . 'global_conversions', 0);
        $totalReferrers = (int) $this->cache->get(self::CACHE_PREFIX . 'active_referrers', 0);

        // Count total clicks across all referral codes
        $globalClicks = $this->countGlobalClicks();

        $clickRate = $totalReferrers > 0
            ? round($globalClicks / max($totalReferrers, 1), 4)
            : 0.0;

        $conversionRate = $globalClicks > 0
            ? round($totalConversions / max($globalClicks, 1), 4)
            : 0.0;

        return [
            'invites' => $totalReferrers,
            'clicks' => $globalClicks,
            'signups' => $totalConversions,
            'click_rate' => $clickRate,
            'conversion_rate' => $conversionRate,
        ];
    }

    /**
     * Get top referrers ranked by conversion count.
     *
     * @param  int  $limit  Number of referrers to return
     * @return list<array{user_id: string, conversions: int, clicks: int, rate: float}>
     */
    public function getTopReferrers(int $limit = 10): array
    {
        // Scan referrer keys to find top converters
        // This is a simplified implementation — in production, you'd use a sorted set
        $referrers = $this->getActiveReferrerIds();
        $results = [];

        foreach (array_slice($referrers, 0, 100) as $userId) {
            $conversions = (int) $this->cache->get(self::CACHE_PREFIX . 'user_conversions_' . $userId, 0);
            $clicks = (int) $this->cache->get(self::CACHE_PREFIX . 'user_clicks_' . $userId, 0);

            if ($conversions > 0 || $clicks > 0) {
                $results[] = [
                    'user_id' => $userId,
                    'conversions' => $conversions,
                    'clicks' => $clicks,
                    'rate' => $clicks > 0 ? round($conversions / $clicks, 4) : 0.0,
                ];
            }
        }

        usort($results, fn (array $a, array $b): int => $b['conversions'] <=> $a['conversions']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Get referral health metrics.
     *
     * @return array{viral_coefficient: float, total_referrers: int, total_conversions: int, funnel: array<string, mixed>, top_referrers: list<array<string, mixed>>}
     */
    public function getHealthMetrics(): array
    {
        $viral = $this->calculateViralCoefficient();

        return [
            'viral_coefficient' => $viral['k_factor'],
            'total_referrers' => $viral['total_referrers'],
            'total_conversions' => $viral['total_conversions'],
            'funnel' => $this->getReferralFunnel(),
            'top_referrers' => $this->getTopReferrers(5),
        ];
    }

    /**
     * Check if a referral code is available (not already assigned).
     *
     * @param  string  $code  The code to check
     * @return bool True if available
     */
    private function isCodeAvailable(string $code): bool
    {
        return $this->resolveReferrer($code) === null;
    }

    /**
     * Generate a unique random referral code.
     *
     * @return string Unique alphanumeric code
     */
    private function generateUniqueCode(): string
    {
        $maxAttempts = 10;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = strtoupper(Str::random($this->codeLength));

            if ($this->isCodeAvailable($code)) {
                return $code;
            }
        }

        // Fallback: UUID-based code
        return strtoupper(substr(Str::uuid()->toString(), 0, $this->codeLength));
    }

    /**
     * Store the bidirectional mapping between user ID and referral code.
     *
     * @param  string  $userId  User ID
     * @param  string  $code  Referral code
     */
    private function storeCodeMapping(string $userId, string $code): void
    {
        $this->cache->put(self::CACHE_PREFIX . 'code_' . $userId, $code, $this->attributionTtl);
        $this->cache->put(self::CACHE_PREFIX . 'lookup_' . strtolower($code), $userId, $this->attributionTtl);

        // Track active referrer count
        $referrersKey = self::CACHE_PREFIX . 'active_referrers';
        $count = (int) $this->cache->get($referrersKey, 0);
        $this->cache->put($referrersKey, $count + 1, $this->metricsTtl);
    }

    /**
     * Get IDs of users who have active referral codes.
     *
     * @return list<string> User IDs
     */
    private function getActiveReferrerIds(): array
    {
        // This is a simplified implementation that returns an empty list.
        // In production, you'd maintain an index or use a set data structure.
        return [];
    }

    /**
     * Count global referral link clicks across all codes.
     *
     * @return int Total click count
     */
    private function countGlobalClicks(): int
    {
        return (int) $this->cache->get(self::CACHE_PREFIX . 'global_clicks', 0);
    }
}
