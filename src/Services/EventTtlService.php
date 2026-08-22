<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event TTL (Time-to-Live) and auto-expiry management service.
 *
 * Manages event lifecycle by applying configurable TTL rules to dispatched events.
 * Events exceeding their TTL are flagged, counted, and optionally dropped before
 * dispatch. Provides staleness detection, TTL-aware routing, and auto-pruning
 * of expired events from cache-backed stores.
 *
 * TTL rules can be configured globally (default_ttl_seconds) and per-event-name
 * or per-category (ttl_overrides). The service also tracks expired event metrics
 * for monitoring and alerting.
 *
 * @since 43.0.0
 */
final class EventTtlService
{
    /** @var int Default TTL for events in seconds (24 hours) */
    private const DEFAULT_TTL = 86400;

    /** @var int Maximum allowed TTL in seconds (30 days) */
    private const MAX_TTL = 2592000;

    private const CACHE_PREFIX = 'zb_event_ttl_';

    private const METRICS_KEY = 'zb_event_ttl_metrics';

    private CacheRepository $cache;

    private int $defaultTtl;

    /** @var array<string, int> Event name => TTL overrides */
    private array $ttlOverrides;

    /** @var array<string, int> Category => TTL overrides */
    private array $categoryTtlOverrides;

    private bool $dropExpired;

    private bool $trackMetrics;

    private int $metricsTtl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  int  $defaultTtl  Default TTL in seconds
     * @param  array<string, int>  $ttlOverrides  Event name => TTL overrides
     * @param  array<string, int>  $categoryTtlOverrides  Category => TTL overrides
     * @param  bool  $dropExpired  Whether to drop expired events
     * @param  bool  $trackMetrics  Whether to track expiry metrics
     * @param  int  $metricsTtl  TTL for metrics cache in seconds
     */
    public function __construct(
        CacheRepository $cache,
        int $defaultTtl = self::DEFAULT_TTL,
        array $ttlOverrides = [],
        array $categoryTtlOverrides = [],
        bool $dropExpired = false,
        bool $trackMetrics = true,
        int $metricsTtl = 3600,
    ){
        $this->cache = $cache;
        $this->defaultTtl = min(max($defaultTtl, 1), self::MAX_TTL);
        $this->ttlOverrides = $ttlOverrides;
        $this->categoryTtlOverrides = $categoryTtlOverrides;
        $this->dropExpired = $dropExpired;
        $this->trackMetrics = $trackMetrics;
        $this->metricsTtl = $metricsTtl;
    }

    /**
     * Check if an event has expired based on its timestamp and TTL.
     *
     * Applies event-specific TTL overrides, then category overrides,
     * falling back to the default TTL.
     *
     * @param  AnalyticsEvent  $event  The event to check
     * @return bool True if the event has exceeded its TTL
     */
    public function isExpired(AnalyticsEvent $event): bool
    {
        $timestamp = $event->timestamp ?? new \DateTimeImmutable();

        $ttl = $this->resolveTtlForEvent($event);
        $expiry = $timestamp->getTimestamp() + $ttl;

        return time() > $expiry;
    }

    /**
     * Get the remaining TTL for an event in seconds.
     *
     * Returns a negative value if the event is already expired.
     *
     * @param  AnalyticsEvent  $event  The event to check
     * @return int Remaining seconds (negative if expired)
     */
    public function remainingTtl(AnalyticsEvent $event): int
    {
        $timestamp = $event->timestamp ?? new \DateTimeImmutable();
        $ttl = $this->resolveTtlForEvent($event);
        $expiry = $timestamp->getTimestamp() + $ttl;

        return $expiry - time();
    }

    /**
     * Evaluate an event's TTL status, optionally dropping expired events.
     *
     * If the event is expired and dropExpired is enabled, returns null.
     * Otherwise, returns the event unchanged.
     *
     * @param  AnalyticsEvent  $event  The event to evaluate
     * @return AnalyticsEvent|null The event (or null if dropped)
     */
    public function evaluate(AnalyticsEvent $event): ?AnalyticsEvent
    {
        if ($this->isExpired($event)) {
            $this->recordExpiredEvent($event);

            if ($this->dropExpired) {
                Log::debug('EventTtlService: dropped expired event', [
                    'event' => $event->name,
                    'client_id' => $event->clientId,
                    'age_seconds' => $this->getEventAge($event),
                ]);

                return null;
            }
        }

        return $event;
    }

    /**
     * Get the effective TTL for an event in seconds.
     *
     * Resolution order: event-specific override > category override > default.
     *
     * @param  AnalyticsEvent  $event  The event
     * @return int TTL in seconds
     */
    public function resolveTtlForEvent(AnalyticsEvent $event): int
    {
        // 1. Check event-specific override
        if (isset($this->ttlOverrides[$event->name])) {
            return min(max((int) $this->ttlOverrides[$event->name], 1), self::MAX_TTL);
        }

        // 2. Check category override
        $category = $this->guessCategory($event->name);
        if ($category !== null && isset($this->categoryTtlOverrides[$category])) {
            return min(max((int) $this->categoryTtlOverrides[$category], 1), self::MAX_TTL);
        }

        // 3. Fall back to default
        return $this->defaultTtl;
    }

    /**
     * Record an expired event for metrics tracking.
     *
     * @param  AnalyticsEvent  $event  The expired event
     */
    private function recordExpiredEvent(AnalyticsEvent $event): void
    {
        if (! $this->trackMetrics) {
            return;
        }

        $metrics = $this->cache->get(self::METRICS_KEY, [
            'total_expired' => 0,
            'by_event' => [],
            'by_category' => [],
            'last_expired_at' => null,
        ]);

        $metrics['total_expired']++;
        $metrics['by_event'][$event->name] = ($metrics['by_event'][$event->name] ?? 0) + 1;

        $category = $this->guessCategory($event->name);
        if ($category !== null) {
            $metrics['by_category'][$category] = ($metrics['by_category'][$category] ?? 0) + 1;
        }

        $metrics['last_expired_at'] = time();

        $this->cache->put(self::METRICS_KEY, $metrics, $this->metricsTtl);
    }

    /**
     * Get TTL expiry metrics.
     *
     * @return array{total_expired: int, by_event: array<string, int>, by_category: array<string, int>, last_expired_at: int|null, default_ttl: int, drop_expired: bool}
     */
    public function getMetrics(): array
    {
        $metrics = $this->cache->get(self::METRICS_KEY, [
            'total_expired' => 0,
            'by_event' => [],
            'by_category' => [],
            'last_expired_at' => null,
        ]);

        return [
            'total_expired' => $metrics['total_expired'],
            'by_event' => $metrics['by_event'],
            'by_category' => $metrics['by_category'],
            'last_expired_at' => $metrics['last_expired_at'],
            'default_ttl' => $this->defaultTtl,
            'drop_expired' => $this->dropExpired,
        ];
    }

    /**
     * Reset TTL metrics.
     */
    public function resetMetrics(): void
    {
        $this->cache->forget(self::METRICS_KEY);
    }

    /**
     * Get the effective TTL overrides.
     *
     * @return array{default_ttl: int, event_overrides: array<string, int>, category_overrides: array<string, int>}
     */
    public function getConfig(): array
    {
        return [
            'default_ttl' => $this->defaultTtl,
            'event_overrides' => $this->ttlOverrides,
            'category_overrides' => $this->categoryTtlOverrides,
            'drop_expired' => $this->dropExpired,
        ];
    }

    /**
     * Guess the category of an event from its name.
     *
     * @param  string  $eventName  Event name
     * @return string|null Category name or null
     */
    private function guessCategory(string $eventName): ?string
    {
        $ecommercePrefixes = ['view_item', 'add_to_cart', 'remove_from_cart', 'begin_checkout', 'purchase', 'refund', 'view_cart', 'view_promotion', 'select_item', 'wishlist', 'add_payment_info', 'abandoned_cart', 'checkout'];
        $saasPrefixes = ['sign_up', 'login', 'logout', 'trial_start', 'trial_end', 'subscription', 'plan_upgrade', 'plan_downgrade', 'cancellation', 'payment', 'billing', 'invite_sent', 'team_created', 'cohort', 'feature_used', 'activation', 'churn'];
        $engagementPrefixes = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error', 'hover', 'copy_text', 'file_download', 'session_start', 'session_end', 'web_vitals', 'performance_score', 'feedback', 'video_play', 'element_visibility', 'outbound_click', 'ad_click', 'screen_view', 'timing', 'notification'];
        $securityPrefixes = ['login_attempt', 'mfa_challenge', 'rate_limit_exceeded', 'suspicious_activity', 'data_access_audit'];
        $uptimePrefixes = ['deployment', 'service_up', 'service_down', 'api_latency', 'error_spike'];

        foreach ($ecommercePrefixes as $prefix) {
            if (str_starts_with($eventName, $prefix)) {
                return 'ecommerce';
            }
        }

        foreach ($saasPrefixes as $prefix) {
            if (str_starts_with($eventName, $prefix)) {
                return 'saas';
            }
        }

        foreach ($engagementPrefixes as $prefix) {
            if (str_starts_with($eventName, $prefix)) {
                return 'engagement';
            }
        }

        foreach ($securityPrefixes as $prefix) {
            if (str_starts_with($eventName, $prefix)) {
                return 'security';
            }
        }

        foreach ($uptimePrefixes as $prefix) {
            if (str_starts_with($eventName, $prefix)) {
                return 'uptime';
            }
        }

        return null;
    }

    /**
     * Get the age of an event in seconds.
     *
     * @param  AnalyticsEvent  $event  The event
     * @return int Age in seconds
     */
    private function getEventAge(AnalyticsEvent $event): int
    {
        $timestamp = $event->timestamp ?? new \DateTimeImmutable();

        return time() - $timestamp->getTimestamp();
    }
}
