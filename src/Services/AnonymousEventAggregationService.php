<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Anonymous Event Aggregation Service — Privacy-Safe Aggregate Statistics.
 *
 * Computes aggregate event statistics without storing or exposing any
 * personally identifiable information (PII). Designed for GDPR/CCPA
 * compliance where operators need traffic analytics but cannot process
 * individual user data.
 *
 * Aggregates are stored in cache with configurable TTL and keyed by
 * event name + time window (hourly/daily). All user identifiers (client_id,
 * user_id, email, IP) are stripped before aggregation.
 *
 * Use cases:
 * - Public analytics dashboards (show traffic without exposing users)
 * - Privacy-first product analytics (feature usage counts without tracking who)
 * - Aggregate reporting for investors/stakeholders (no PII exposure)
 * - Internal health metrics without individual event replay
 *
 * @since 148.0.0
 */
final class AnonymousEventAggregationService
{
    private const CACHE_PREFIX = 'zb_anon_agg_';

    private const DEFAULT_TTL = 3600; // 1 hour

    private const MAX_BUCKETS = 1000;

    private const SUPPORTED_WINDOWS = ['hourly', 'daily', 'weekly', 'monthly'];

    /** @var array<string, int> Event name → aggregate count (in-memory accumulator for current window) */
    private array $pendingCounts = [];

    private bool $enabled;

    private int $cacheTtl;

    private string $defaultWindow;

    private int $maxUniqueEvents;

    private CacheRepository $cache;

    /**
     * @param  array{enabled?: bool, cache_ttl?: int, default_window?: string, max_unique_events?: int, strip_fields?: list<string>}  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $configRepo, array $config = []){
        $fullConfig = $configRepo->get('zeroboiler.analytics.anonymous_aggregation', []);
        $merged = array_merge($fullConfig, $config);

        $this->cache = $cache;
        $this->enabled = (bool) ($merged['enabled'] ?? false);
        $this->cacheTtl = (int) ($merged['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->defaultWindow = (string) ($merged['default_window'] ?? 'hourly');
        $this->maxUniqueEvents = (int) ($merged['max_unique_events'] ?? self::MAX_BUCKETS);
    }

    /**
     * Record an anonymous aggregate for the given event name.
     *
     * Strips all params (no PII leakage) and increments the count
     * for the event name in the current time window.
     */
    public function record(string $eventName): void
    {
        if (! $this->enabled) {
            return;
        }

        // Normalize event name
        $eventName = strtolower(trim($eventName));

        if ($eventName === '') {
            return;
        }

        if (! isset($this->pendingCounts[$eventName])) {
            // Enforce max unique events limit
            if (count($this->pendingCounts) >= $this->maxUniqueEvents) {
                return;
            }

            $this->pendingCounts[$eventName] = 0;
        }

        $this->pendingCounts[$eventName]++;
    }

    /**
     * Flush pending in-memory counts to cache.
     *
     * Called automatically on __destruct, but can be called manually
     * for explicit persistence (e.g., at end of request cycle).
     */
    public function flush(): void
    {
        if ($this->pendingCounts === []) {
            return;
        }

        $window = $this->defaultWindow;
        $bucket = $this->getCacheBucket($window);

        $existing = $this->cache->get($bucket, []);

        /** @var array<string, int> $existing */
        foreach ($this->pendingCounts as $eventName => $count) {
            $existing[$eventName] = ($existing[$eventName] ?? 0) + $count;
        }

        $this->cache->put($bucket, $existing, $this->cacheTtl);
        $this->pendingCounts = [];
    }

    /**
     * Get aggregated counts for a specific time window.
     *
     * @return array<string, int> Event name → count
     */
    public function getAggregates(?string $window = null): array
    {
        $window = $window ?? $this->defaultWindow;

        if (! in_array($window, self::SUPPORTED_WINDOWS, true)) {
            return [];
        }

        $bucket = $this->getCacheBucket($window);

        /** @var array<string, int> $aggregates */
        $aggregates = $this->cache->get($bucket, []);

        // Sort by count descending
        arsort($aggregates);

        return $aggregates;
    }

    /**
     * Get the top N events by count for a specific time window.
     *
     * @return list<array{name: string, count: int, percentage: float}>
     */
    public function topEvents(int $limit = 10, ?string $window = null): array
    {
        $aggregates = $this->getAggregates($window);
        $total = array_sum($aggregates);

        if ($total === 0) {
            return [];
        }

        $top = array_slice($aggregates, 0, $limit, true);
        $result = [];

        foreach ($top as $name => $count) {
            $result[] = [
                'name' => $name,
                'count' => $count,
                'percentage' => round(($count / $total) * 100, 2),
            ];
        }

        return $result;
    }

    /**
     * Get total event count across all events in a window.
     */
    public function totalCount(?string $window = null): int
    {
        return array_sum($this->getAggregates($window));
    }

    /**
     * Get the number of unique event types recorded in a window.
     */
    public function uniqueEventCount(?string $window = null): int
    {
        return count($this->getAggregates($window));
    }

    /**
     * Get aggregates grouped by category prefix.
     *
     * Groups events by their category prefix (e.g., 'saas_' events
     * are grouped under 'saas', 'ecommerce_' under 'ecommerce').
     *
     * @return array<string, array{count: int, events: int, percentage: float}>
     */
    public function byCategory(?string $window = null): array
    {
        $aggregates = $this->getAggregates($window);
        $total = array_sum($aggregates);
        $categories = [];

        foreach ($aggregates as $name => $count) {
            $category = $this->inferCategory($name);

            if (! isset($categories[$category])) {
                $categories[$category] = ['count' => 0, 'events' => 0];
            }

            $categories[$category]['count'] += $count;
            $categories[$category]['events']++;
        }

        // Add percentage
        foreach ($categories as &$cat) {
            $cat['percentage'] = $total > 0
                ? round(($cat['count'] / $total) * 100, 2)
                : 0.0;
        }

        arsort($categories);

        return $categories;
    }

    /**
     * Get a full summary report for dashboard display.
     *
     * @return array{enabled: bool, window: string, total_events: int, unique_events: int, top_events: list<array{name: string, count: int, percentage: float}>, categories: array<string, array{count: int, events: int, percentage: float}>, pending_count: int}
     */
    public function summary(): array
    {
        $aggregates = $this->getAggregates();
        $total = array_sum($aggregates);

        return [
            'enabled' => $this->enabled,
            'window' => $this->defaultWindow,
            'total_events' => $total,
            'unique_events' => count($aggregates),
            'top_events' => $this->topEvents(10),
            'categories' => $this->byCategory(),
            'pending_count' => array_sum($this->pendingCounts),
        ];
    }

    /**
     * Clear all aggregated data for all time windows.
     *
     * @return list<string> Cache keys that were cleared
     */
    public function clear(): array
    {
        $cleared = [];

        foreach (self::SUPPORTED_WINDOWS as $window) {
            $bucket = $this->getCacheBucket($window);

            if ($this->cache->forget($bucket)) {
                $cleared[] = $bucket;
            }
        }

        $this->pendingCounts = [];

        return $cleared;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Generate cache bucket key for a time window.
     */
    private function getCacheBucket(string $window): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return match ($window) {
            'hourly' => self::CACHE_PREFIX . 'hourly_' . $now->format('Y-m-d-H'),
            'daily' => self::CACHE_PREFIX . 'daily_' . $now->format('Y-m-d'),
            'weekly' => self::CACHE_PREFIX . 'weekly_' . $now->format('Y-W'),
            'monthly' => self::CACHE_PREFIX . 'monthly_' . $now->format('Y-m'),
            default => self::CACHE_PREFIX . 'hourly_' . $now->format('Y-m-d-H'),
        };
    }

    /**
     * Infer event category from event name.
     *
     * Uses prefix-based heuristics to group events by category.
     */
    private function inferCategory(string $eventName): string
    {
        $prefixes = [
            'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart',
            'begin_checkout', 'purchase', 'refund', 'select_item', 'wishlist',
            'view_promotion', 'select_promotion', 'abandoned_cart',
        ];

        $saasPrefixes = [
            'sign_up', 'login', 'logout', 'trial_start', 'trial_end',
            'subscribe', 'subscription', 'plan_upgrade', 'plan_downgrade',
            'cancellation', 'mrr_movement', 'feature_used', 'activation',
        ];

        $engagementPrefixes = [
            'page_view', 'scroll_depth', 'click', 'form_start', 'form_submit',
            'search', 'share', 'error', 'session_start', 'session_end',
            'web_vitals', 'timing', 'video_play', 'file_download',
        ];

        $marketingPrefixes = [
            'email_', 'campaign_', 'ad_', 'newsletter', 'social_',
            'referral', 'affiliate', 'push_',
        ];

        $securityPrefixes = [
            'login_attempt', 'mfa_', 'rate_limit', 'suspicious',
        ];

        if ($this->startsWithAny($eventName, $prefixes)) {
            return 'ecommerce';
        }

        if ($this->startsWithAny($eventName, $saasPrefixes)) {
            return 'saas';
        }

        if ($this->startsWithAny($eventName, $engagementPrefixes)) {
            return 'engagement';
        }

        if ($this->startsWithAny($eventName, $marketingPrefixes)) {
            return 'marketing';
        }

        if ($this->startsWithAny($eventName, $securityPrefixes)) {
            return 'security';
        }

        return 'other';
    }

    /**
     * Check if a string starts with any of the given prefixes.
     *
     * @param  list<string>  $prefixes
     */
    private function startsWithAny(string $subject, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($subject, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Auto-flush pending counts on destruction.
     */
    public function __destruct()
    {
        if ($this->pendingCounts !== []) {
            try {
                $this->flush();
            } catch (\Throwable $e) {
                // Silent fail on destruction — logging not available
            }
        }
    }
}
