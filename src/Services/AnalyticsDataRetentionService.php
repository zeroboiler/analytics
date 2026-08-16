<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\Services\EventArchiveService;

/**
 * Configurable data retention service for SaaS analytics.
 *
 * Manages the lifecycle of archived analytics events with per-category
 * retention policies, automatic expiry enforcement, GDPR-compliant purge,
 * and retention statistics for compliance dashboards.
 *
 * Features:
 * - Per-category retention periods (ecommerce: 90d, saas: 180d, engagement: 30d, etc.)
 * - Global default retention with fallback
 * - Automatic purge of expired events on configurable schedule
 * - GDPR right-to-erasure: purge all events for a specific client_id or user_id
 * - Retention statistics with per-category breakdowns
 * - Purge audit logging
 * - Configurable dry-run mode for preview before purge
 *
 * Configuration is read from `zeroboiler.analytics.data_retention`.
 *
 * @since 39.0.0
 */
final class AnalyticsDataRetentionService
{
    /** @var bool Whether data retention enforcement is enabled */
    private bool $enabled;

    /** @var int Default retention period in seconds (90 days) */
    private int $defaultRetentionSeconds;

    /** @var array<string, int> Per-category retention periods in seconds */
    private array $categoryRetention;

    /** @var string Cache key prefix for retention metadata */
    private string $cachePrefix;

    /** @var int Cache TTL for retention metadata */
    private int $cacheTtl;

    /** @var bool Whether GDPR erase is enabled */
    private bool $gdprEraseEnabled;

    /** @var int Maximum events to process per purge cycle */
    private int $purgeBatchSize;

    /** @var bool Log purge operations */
    private bool $logPurge;

    private CacheRepository $cache;

    private EventArchiveService $archive;

    /**
     * Create a new AnalyticsDataRetentionService instance.
     *
     * @param  CacheRepository  $cache  Cache repository instance
     * @param  EventArchiveService  $archive  Event archive service
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        CacheRepository $cache,
        EventArchiveService $archive,
        ConfigRepository $config,
    ): void {
        $retentionConfig = $config->get('zeroboiler.analytics.data_retention', []);
        /** @var array{enabled?: bool, default_days?: int, categories?: array<string, int>, cache_prefix?: string, cache_ttl?: int, gdpr_erase_enabled?: bool, purge_batch_size?: int, log_purge?: bool} $retentionConfig */

        $this->cache = $cache;
        $this->archive = $archive;
        $this->enabled = (bool) ($retentionConfig['enabled'] ?? true);
        $this->defaultRetentionSeconds = (int) (($retentionConfig['default_days'] ?? 90) * 86400);
        $this->cachePrefix = (string) ($retentionConfig['cache_prefix'] ?? 'zb_retention_');
        $this->cacheTtl = (int) ($retentionConfig['cache_ttl'] ?? 3600);
        $this->gdprEraseEnabled = (bool) ($retentionConfig['gdpr_erase_enabled'] ?? true);
        $this->purgeBatchSize = (int) ($retentionConfig['purge_batch_size'] ?? 500);
        $this->logPurge = (bool) ($retentionConfig['log_purge'] ?? true);

        // Per-category retention (in days → converted to seconds internally)
        $rawCategories = $retentionConfig['categories'] ?? [];
        $this->categoryRetention = [];

        foreach ($rawCategories as $category => $days) {
            $this->categoryRetention[$category] = (int) ($days * 86400);
        }
    }

    /**
     * Get the retention period for a specific category in seconds.
     *
     * Falls back to the global default if the category has no specific rule.
     *
     * @param  string  $category  Event category (ecommerce, saas, engagement, security, uptime)
     * @return int Retention period in seconds
     */
    public function retentionFor(string $category): int
    {
        return $this->categoryRetention[$category] ?? $this->defaultRetentionSeconds;
    }

    /**
     * Get the retention period for a specific category in days.
     */
    public function retentionDaysFor(string $category): int
    {
        return (int) round($this->retentionFor($category) / 86400);
    }

    /**
     * Check if an event timestamp has exceeded its retention period.
     *
     * @param  string  $timestamp  ISO 8601 timestamp string
     * @param  string  $category  Event category for category-specific retention
     */
    public function isExpired(string $timestamp, string $category = 'engagement'): bool
    {
        try {
            $eventTime = strtotime($timestamp);

            if ($eventTime === false) {
                return false;
            }

            $retentionSeconds = $this->retentionFor($category);
            $expiryTime = $eventTime + $retentionSeconds;

            return time() > $expiryTime;
        } catch (\Throwable $e) {
            Log::warning('ZeroBoiler Analytics: Failed to check event expiry', [
                'timestamp' => $timestamp,
                'category' => $category,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Purge expired events from the archive.
     *
     * Scans archived events and removes those that have exceeded their
     * retention period. Optionally runs in dry-run mode to preview
     * the purge without actually deleting.
     *
     * @param  bool  $dryRun  If true, count expired events without deleting
     * @param  string|null  $category  Restrict to a specific category (null = all)
     * @return array{purged: int, scanned: int, dry_run: bool, category: string|null, timestamp: string}
     */
    public function purgeExpired(bool $dryRun = false, ?string $category = null): array
    {
        $result = [
            'purged' => 0,
            'scanned' => 0,
            'dry_run' => $dryRun,
            'category' => $category,
            'timestamp' => now()->format('c'),
        ];

        if (! $this->enabled) {
            return $result;
        }

        $totalArchived = $this->archive->totalArchived();
        $purgeCount = 0;

        // Scan from oldest to newest
        for ($id = 1; $id <= $totalArchived; $id++) {
            if ($purgeCount >= $this->purgeBatchSize) {
                break;
            }

            $entry = $this->archive->get($id);

            if ($entry === null) {
                $result['scanned']++;
                continue;
            }

            $result['scanned']++;

            $eventCategory = $this->resolveCategory($entry['name'] ?? '');
            $eventTimestamp = $entry['timestamp'] ?? $entry['archived_at'] ?? '';

            // Category filter
            if ($category !== null && $eventCategory !== $category) {
                continue;
            }

            if ($this->isExpired($eventTimestamp, $eventCategory)) {
                if ($dryRun) {
                    $purgeCount++;
                } else {
                    $this->archive->delete($id);
                    $purgeCount++;
                }
            }
        }

        $result['purged'] = $purgeCount;

        if ($this->logPurge && $purgeCount > 0) {
            $this->recordPurgeLog($dryRun ? 0 : $purgeCount, $purgeCount, $category);
        }

        return $result;
    }

    /**
     * GDPR right-to-erasure: purge all events for a specific client ID.
     *
     * Removes all archived events associated with the given client_id.
     *
     * @param  string  $clientId  Client tracking ID
     * @return array{purged: int, client_id: string, timestamp: string}
     */
    public function purgeForClientId(string $clientId): array
    {
        $result = [
            'purged' => 0,
            'client_id' => $clientId,
            'timestamp' => now()->format('c'),
        ];

        if (! $this->gdprEraseEnabled) {
            return $result;
        }

        $totalArchived = $this->archive->totalArchived();
        $purgeCount = 0;

        for ($id = 1; $id <= $totalArchived; $id++) {
            if ($purgeCount >= $this->purgeBatchSize) {
                break;
            }

            $entry = $this->archive->get($id);

            if ($entry === null) {
                continue;
            }

            if (($entry['client_id'] ?? '') === $clientId) {
                $this->archive->delete($id);
                $purgeCount++;
            }
        }

        $result['purged'] = $purgeCount;

        Log::info('ZeroBoiler Analytics: GDPR purge for client_id', [
            'client_id' => $clientId,
            'purged' => $purgeCount,
        ]);

        return $result;
    }

    /**
     * GDPR right-to-erasure: purge all events for a specific user ID.
     *
     * @param  string  $userId  User ID
     * @return array{purged: int, user_id: string, timestamp: string}
     */
    public function purgeForUserId(string $userId): array
    {
        $result = [
            'purged' => 0,
            'user_id' => $userId,
            'timestamp' => now()->format('c'),
        ];

        if (! $this->gdprEraseEnabled) {
            return $result;
        }

        $totalArchived = $this->archive->totalArchived();
        $purgeCount = 0;

        for ($id = 1; $id <= $totalArchived; $id++) {
            if ($purgeCount >= $this->purgeBatchSize) {
                break;
            }

            $entry = $this->archive->get($id);

            if ($entry === null) {
                continue;
            }

            if (($entry['user_id'] ?? '') === $userId) {
                $this->archive->delete($id);
                $purgeCount++;
            }
        }

        $result['purged'] = $purgeCount;

        Log::info('ZeroBoiler Analytics: GDPR purge for user_id', [
            'user_id' => $userId,
            'purged' => $purgeCount,
        ]);

        return $result;
    }

    /**
     * Get retention statistics for compliance dashboards.
     *
     * @return array{enabled: bool, default_days: int, categories: array<string, int>, total_archived: int, gdpr_erase_enabled: bool, purge_batch_size: int}
     */
    public function statistics(): array
    {
        return [
            'enabled' => $this->enabled,
            'default_days' => (int) round($this->defaultRetentionSeconds / 86400),
            'categories' => array_map(
                fn (int $seconds): int => (int) round($seconds / 86400),
                $this->categoryRetention,
            ),
            'total_archived' => $this->archive->totalArchived(),
            'gdpr_erase_enabled' => $this->gdprEraseEnabled,
            'purge_batch_size' => $this->purgeBatchSize,
        ];
    }

    /**
     * Check if retention enforcement is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get service summary for health checks.
     *
     * @return array{enabled: bool, gdpr_erase_enabled: bool, default_days: int, category_count: int, cache_prefix: string}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'gdpr_erase_enabled' => $this->gdprEraseEnabled,
            'default_days' => (int) round($this->defaultRetentionSeconds / 86400),
            'category_count' => count($this->categoryRetention),
            'cache_prefix' => $this->cachePrefix,
        ];
    }

    /**
     * Get all configured retention categories and their periods in days.
     *
     * @return array<string, int>
     */
    public function configuredCategories(): array
    {
        $days = [];

        foreach ($this->categoryRetention as $category => $seconds) {
            $days[$category] = (int) round($seconds / 86400);
        }

        return $days;
    }

    /**
     * Record a purge operation in the cache for audit trail.
     */
    private function recordPurgeLog(int $actualPurged, int $expiredFound, ?string $category): void
    {
        $logs = $this->getPurgeLogs();
        $logs[] = [
            'timestamp' => now()->format('c'),
            'purged' => $actualPurged,
            'expired_found' => $expiredFound,
            'category' => $category,
        ];

        // Keep only last 100 purge logs
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }

        $this->cache->put($this->purgeLogKey(), $logs, $this->cacheTtl * 24);
    }

    /**
     * Get recent purge log entries.
     *
     * @return list<array{timestamp: string, purged: int, expired_found: int, category: string|null}>
     */
    public function getPurgeLogs(): array
    {
        /** @var list<array{timestamp: string, purged: int, expired_found: int, category: string|null}>|null $logs */
        $logs = $this->cache->get($this->purgeLogKey());

        return is_array($logs) ? $logs : [];
    }

    /**
     * Resolve the event category from event name using EventCatalog.
     *
     * Falls back to 'engagement' if the category cannot be determined.
     */
    private function resolveCategory(string $eventName): string
    {
        if ($eventName === '') {
            return 'engagement';
        }

        // Try EventCatalog if available
        if (class_exists(\ZeroBoiler\Analytics\Events\EventCatalog::class)) {
            $category = \ZeroBoiler\Analytics\Events\EventCatalog::getCategory($eventName);

            if ($category !== null) {
                return $category;
            }
        }

        // Heuristic: infer from event name
        $ecommercePrefixes = ['view_item', 'add_to_cart', 'remove_from_cart', 'begin_checkout', 'purchase', 'refund', 'view_cart', 'select_item', 'select_promotion', 'view_promotion', 'add_payment_info', 'wishlist', 'checkout_step', 'checkout_abandon', 'abandoned_cart'];
        $saasPrefixes = ['sign_up', 'login', 'logout', 'trial_start', 'trial_end', 'trial_converted', 'trial_expired', 'subscription', 'plan_upgrade', 'plan_downgrade', 'plan_changed', 'cancellation', 'feature_used', 'feature_adopted', 'invite_sent', 'team_', 'workspace_', 'account_', 'billing_', 'payment_', 'invoice_', 'cohort_', 'revenue_', 'milestone_', 'retention_', ' churn'];

        $name = strtolower($eventName);

        foreach ($ecommercePrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return 'ecommerce';
            }
        }

        foreach ($saasPrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return 'saas';
            }
        }

        return 'engagement';
    }

    private function purgeLogKey(): string
    {
        return "{$this->cachePrefix}purge_logs";
    }
}
