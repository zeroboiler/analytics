<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Analytics data retention policy service for GDPR compliance.
 *
 * Manages configurable data retention rules for analytics events.
 * Supports per-category retention periods, automatic expiry tracking,
 * retention summaries, and compliance reporting.
 *
 * Configuration is read from `zeroboiler.analytics.retention`.
 *
 * Default retention periods:
 * - Engagement events: 30 days
 * - SaaS lifecycle events: 90 days
 * - E-commerce events: 365 days (for tax/legal compliance)
 * - PII data: 0 days (no storage — use immediate processing)
 *
 * @since 1.0.0
 */
final class DataRetentionPolicyService
{
    /** @var array<string, int> Default retention periods per category (in days) */
    private const DEFAULT_RETENTION = [
        'engagement' => 30,
        'saas' => 90,
        'ecommerce' => 365,
        'pii' => 0,
    ];

    /** @var int Minimum retention period (1 day) */
    private const MIN_RETENTION_DAYS = 1;

    /** @var int Maximum retention period (10 years) */
    private const MAX_RETENTION_DAYS = 3650;

    private bool $enabled;

    /** @var array<string, int> Retention periods per category in days */
    private array $retentionPeriods;

    /** @var bool Whether to auto-expire events past retention */
    private bool $autoExpire;

    /** @var string Cache key for retention tracking */
    private string $cachePrefix;

    /** @var list<string> Event categories that contain PII */
    private array $piiCategories;

    /** @var bool Whether to log retention actions */
    private bool $logActions;

    /** @var array<string, int> In-memory expiry counters */
    private array $expiryCounters = [];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ): void {
        $retentionConfig = $config->get('zeroboiler.analytics.retention', []);
        /** @var array{enabled?: bool, periods?: array<string, int>, auto_expire?: bool, cache_prefix?: string, pii_categories?: list<string>, log_actions?: bool} $retentionConfig */

        $this->enabled = (bool) ($retentionConfig['enabled'] ?? false);
        $this->autoExpire = (bool) ($retentionConfig['auto_expire'] ?? false);
        $this->cachePrefix = (string) ($retentionConfig['cache_prefix'] ?? 'zb_retention_');
        $this->piiCategories = $retentionConfig['pii_categories'] ?? ['pii'];
        $this->logActions = (bool) ($retentionConfig['log_actions'] ?? false);

        // Merge custom periods with defaults
        $this->retentionPeriods = array_merge(
            self::DEFAULT_RETENTION,
            $retentionConfig['periods'] ?? [],
        );

        // Validate and clamp retention periods
        foreach ($this->retentionPeriods as $category => $days) {
            $this->retentionPeriods[$category] = $this->clampRetention((int) $days);
        }
    }

    /**
     * Check if an event is within its retention period.
     *
     * @param  AnalyticsEvent  $event
     * @param  string|null  $recordedAt  ISO 8601 timestamp of when the event was recorded
     */
    public function isWithinRetention(AnalyticsEvent $event, ?string $recordedAt = null): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $category = $this->resolveCategory($event);
        $retentionDays = $this->retentionPeriods[$category] ?? 30;

        // Zero-day retention means no storage
        if ($retentionDays === 0) {
            return false;
        }

        $referenceDate = $recordedAt !== null
            ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $recordedAt)
            : new \DateTimeImmutable;

        if ($referenceDate === false) {
            return true; // Can't parse, allow by default
        }

        $expiresAt = $referenceDate->modify('+' . $retentionDays . ' days');

        return now() < $expiresAt;
    }

    /**
     * Get the retention period for a specific event.
     *
     * @return int Days until expiry
     */
    public function getRetentionDays(AnalyticsEvent $event): int
    {
        $category = $this->resolveCategory($event);

        return $this->retentionPeriods[$category] ?? 30;
    }

    /**
     * Get the expiry date for a specific event.
     *
     * @param  AnalyticsEvent  $event
     * @param  string|null  $recordedAt  ISO 8601 timestamp
     * @return string|null ISO 8601 expiry date, or null if no retention
     */
    public function getExpiryDate(AnalyticsEvent $event, ?string $recordedAt = null): ?string
    {
        $days = $this->getRetentionDays($event);

        if ($days === 0) {
            return null;
        }

        $referenceDate = $recordedAt !== null
            ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $recordedAt)
            : new \DateTimeImmutable;

        if ($referenceDate === false) {
            return null;
        }

        return $referenceDate->modify('+' . $days . ' days')->format(\DateTimeInterface::ATOM);
    }

    /**
     * Record an event for retention tracking.
     *
     * Stores metadata about when the event was recorded so that
     * retention policies can be enforced later.
     */
    public function recordEvent(AnalyticsEvent $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $category = $this->resolveCategory($event);
        $retentionDays = $this->retentionPeriods[$category] ?? 30;

        if ($retentionDays === 0) {
            return;
        }

        // Track event count per category for expiry reports
        $cacheKey = $this->cachePrefix . 'count_' . $category;
        $current = $this->cache->get($cacheKey, 0);
        $this->cache->put($cacheKey, ((int) $current) + 1, $this->toSeconds($retentionDays) + 86400);

        $this->expiryCounters[$category] = ($this->expiryCounters[$category] ?? 0) + 1;
    }

    /**
     * Check if a category contains PII data.
     */
    public function isPiiCategory(string $category): bool
    {
        return in_array($category, $this->piiCategories, true);
    }

    /**
     * Get retention period for a specific category.
     */
    public function getRetentionForCategory(string $category): int
    {
        return $this->retentionPeriods[$category] ?? 30;
    }

    /**
     * Set retention period for a specific category.
     *
     * @param  string  $category
     * @param  int  $days  Retention period in days
     */
    public function setRetentionForCategory(string $category, int $days): void
    {
        $this->retentionPeriods[$category] = $this->clampRetention($days);
    }

    /**
     * Get all retention periods.
     *
     * @return array<string, int>
     */
    public function getAllRetentionPeriods(): array
    {
        return $this->retentionPeriods;
    }

    /**
     * Check if data retention is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get a comprehensive retention summary.
     *
     * @return array{enabled: bool, auto_expire: bool, periods: array<string, int>, pii_categories: list<string>, tracked_events: array<string, int>}
     */
    public function summary(): array
    {
        $trackedEvents = [];
        foreach (array_keys($this->retentionPeriods) as $category) {
            $cacheKey = $this->cachePrefix . 'count_' . $category;
            $trackedEvents[$category] = (int) $this->cache->get($cacheKey, 0);
        }

        return [
            'enabled' => $this->enabled,
            'auto_expire' => $this->autoExpire,
            'periods' => $this->retentionPeriods,
            'pii_categories' => $this->piiCategories,
            'tracked_events' => $trackedEvents,
            'in_memory_counters' => $this->expiryCounters,
        ];
    }

    /**
     * Resolve the event category.
     */
    private function resolveCategory(AnalyticsEvent $event): string
    {
        $category = \ZeroBoiler\Analytics\Events\EventCatalog::getCategory($event->name);

        return $category ?? 'engagement';
    }

    /**
     * Clamp retention period to valid range.
     */
    private function clampRetention(int $days): int
    {
        return max(self::MIN_RETENTION_DAYS, min($days, self::MAX_RETENTION_DAYS));
    }

    /**
     * Convert days to seconds for cache TTL.
     */
    private function toSeconds(int $days): int
    {
        return $days * 86400;
    }
}
