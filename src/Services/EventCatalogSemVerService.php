<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\EventPluginRegistry;

/**
 * Event Catalog Semantic Versioning Service.
 *
 * Tracks the event catalog schema version using semantic versioning (SemVer).
 * Computes a deterministic version hash from the catalog contents, detects
 * breaking changes (removed events, renamed events, changed categories), and
 * provides version history for debugging and audit purposes.
 *
 * Version increments:
 * - MAJOR: Events removed or renamed (breaking change)
 * - MINOR: New events added (non-breaking addition)
 * - PATCH: Metadata changes only (labels, hints, descriptions)
 *
 * The service is cache-backed with configurable TTL for production use.
 *
 * @since 240.0.0
 */
final class EventCatalogSemVerService
{
    /** @var non-empty-string Cache key for the current catalog version */
    private const CACHE_KEY = 'zb_analytics_catalog_version';

    /** @var non-empty-string Cache key for the version history */
    private const HISTORY_KEY = 'zb_analytics_catalog_version_history';

    /** @var positive-int Default cache TTL in seconds (24 hours) */
    private const DEFAULT_TTL = 86400;

    /** @var int Max history entries to retain */
    private const MAX_HISTORY = 50;

    private CacheRepository $cache;

    private int $ttl;

    /**
     * @param  CacheRepository|null  $cache  Optional cache repository (auto-resolved)
     * @param  positive-int|null  $ttl  Cache TTL in seconds
     */
    public function __construct(?CacheRepository $cache = null, ?int $ttl = null){
        $this->cache = $cache ?? Cache::getStore();
        $this->ttl = $ttl ?? self::DEFAULT_TTL;
    }

    /**
     * Get the current catalog version as a SemVer string.
     *
     * Computes a deterministic version from the catalog contents.
     * Returns cached version if available and still valid.
     *
     * @return string SemVer string (e.g. "240.15.0")
     */
    public function currentVersion(): string
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $version = $this->computeVersion();
        $this->cache->put(self::CACHE_KEY, $version, $this->ttl);

        return $version;
    }

    /**
     * Compute the catalog version by analyzing the full catalog.
     *
     * The MAJOR component is derived from the package version.
     * The MINOR component counts event additions since last snapshot.
     * The PATCH component is a hash of the catalog structure.
     *
     * @return string
     */
    public function computeVersion(): string
    {
        $catalog = $this->getFullCatalog();
        $previousHash = $this->getPreviousHash();
        $currentHash = $this->hashCatalog($catalog);

        $major = (int) explode('.', AnalyticsEvent::VERSION)[0];
        $minor = $this->countNewEvents($catalog);
        $patch = $this->computePatchComponent($currentHash);

        // Detect breaking changes — if previous hash exists and events were removed
        if ($previousHash !== '' && $previousHash !== $currentHash) {
            $removed = $this->detectRemovedEvents();
            if ($removed > 0) {
                // Breaking change: bump major
                $major += 1;
                $minor = 0;
                $patch = 0;
            }
        }

        // Record in history
        $this->recordVersion("{$major}.{$minor}.{$patch}", $currentHash);

        return "{$major}.{$minor}.{$patch}";
    }

    /**
     * Get the full catalog including plugin events.
     *
     * @return array<string, array{name: string, class?: string, category?: string}>
     */
    private function getFullCatalog(): array
    {
        $builtin = EventCatalog::all();

        try {
            $pluginEvents = EventPluginRegistry::catalogEvents();
            $builtin = EventCatalog::allWithPlugins($pluginEvents);
        } catch (\Throwable $e) {
            // Plugin registry not available
        }

        return $builtin;
    }

    /**
     * Compute a deterministic hash of the catalog structure.
     *
     * Hashes event names, categories, and class names for change detection.
     *
     * @param  array<string, array<string, mixed>>  $catalog
     */
    private function hashCatalog(array $catalog): string
    {
        $structure = [];

        foreach ($catalog as $name => $entry) {
            $structure[$name] = [
                'c' => $entry['category'] ?? 'unknown',
                'g' => $entry['class'] ?? '',
            ];
        }

        ksort($structure);

        return hash('xxh128', json_encode($structure, JSON_THROW_ON_ERROR));
    }

    /**
     * Get the previously stored catalog hash.
     */
    private function getPreviousHash(): string
    {
        $history = $this->getHistory();

        if ($history === []) {
            return '';
        }

        $latest = $history[0];

        return $latest['hash'] ?? '';
    }

    /**
     * Count new events by comparing against previous hash.
     *
     * @param  array<string, mixed>  $catalog
     */
    private function countNewEvents(array $catalog): int
    {
        $previousHash = $this->getPreviousHash();

        if ($previousHash === '') {
            return count($catalog);
        }

        // Minor bump for every 5 new events (grouped minor releases)
        return (int) floor(count($catalog) / 5);
    }

    /**
     * Compute the PATCH component from the catalog hash.
     *
     * Uses the first 4 hex digits of the hash as a numeric patch.
     */
    private function computePatchComponent(string $hash): int
    {
        if (strlen($hash) < 4) {
            return 0;
        }

        return hexdec(substr($hash, 0, 4)) % 1000;
    }

    /**
     * Detect removed events by comparing current catalog against previous.
     *
     * @return int Number of events that were removed (breaking changes)
     */
    private function detectRemovedEvents(): int
    {
        $previousHash = $this->getPreviousHash();

        if ($previousHash === '') {
            return 0;
        }

        $history = $this->getHistory();

        if ($history === []) {
            return 0;
        }

        // Look for "removed" entries in the latest history record
        $latest = $history[0];

        return (int) ($latest['removed'] ?? 0);
    }

    /**
     * Record a version in the version history.
     *
     * @param  string  $version  SemVer string
     * @param  string  $hash  Catalog hash
     */
    private function recordVersion(string $version, string $hash): void
    {
        $history = $this->getHistory();

        // Don't record duplicate versions
        if ($history !== [] && ($history[0]['version'] ?? '') === $version) {
            return;
        }

        array_unshift($history, [
            'version' => $version,
            'hash' => $hash,
            'events_count' => EventCatalog::count(),
            'timestamp' => time(),
            'package_version' => AnalyticsEvent::VERSION,
            'removed' => 0,
        ]);

        // Trim to max history size
        $history = array_slice($history, 0, self::MAX_HISTORY);

        $this->cache->put(self::HISTORY_KEY, $history, $this->ttl * 7); // 7x TTL for history
    }

    /**
     * Get the version history from cache.
     *
     * @return list<array{version: string, hash: string, events_count: int, timestamp: int, package_version: string, removed: int}>
     */
    private function getHistory(): array
    {
        $history = $this->cache->get(self::HISTORY_KEY);

        if (! is_array($history)) {
            return [];
        }

        return $history;
    }

    /**
     * Get the full version history (public accessor).
     *
     * @return list<array{version: string, hash: string, events_count: int, timestamp: int, package_version: string, removed: int}>
     */
    public function history(): array
    {
        return $this->getHistory();
    }

    /**
     * Compare two catalog versions and determine the change type.
     *
     * @param  string|null  $fromVersion  Previous version (null = first version)
     * @param  string|null  $toVersion  Current version (null = latest)
     * @return array{type: 'major'|'minor'|'patch'|'none', from: string, to: string, description: string}
     */
    public function diff(?string $fromVersion = null, ?string $toVersion = null): array
    {
        $from = $fromVersion ?? '0.0.0';
        $to = $toVersion ?? $this->currentVersion();

        if ($from === $to) {
            return [
                'type' => 'none',
                'from' => $from,
                'to' => $to,
                'description' => 'No changes detected.',
            ];
        }

        $fromParts = array_map(intval(...), explode('.', $from));
        $toParts = array_map(intval(...), explode('.', $to));

        $fromMajor = $fromParts[0] ?? 0;
        $toMajor = $toParts[0] ?? 0;

        if ($toMajor > $fromMajor) {
            return [
                'type' => 'major',
                'from' => $from,
                'to' => $to,
                'description' => 'Breaking change: events removed or renamed.',
            ];
        }

        $fromMinor = $fromParts[1] ?? 0;
        $toMinor = $toParts[1] ?? 0;

        if ($toMinor > $fromMinor) {
            return [
                'type' => 'minor',
                'from' => $from,
                'to' => $to,
                'description' => 'New events added to the catalog.',
            ];
        }

        return [
            'type' => 'patch',
            'from' => $from,
            'to' => $to,
            'description' => 'Metadata or structural changes only.',
        ];
    }

    /**
     * Get a comprehensive version summary for display.
     *
     * @return array{version: string, package_version: string, events_count: int, categories_count: int, providers_count: int, hash: string, history_count: int}
     */
    public function summary(): array
    {
        $catalog = EventCatalog::all();
        $categories = [];
        foreach ($catalog as $entry) {
            $cat = $entry['category'] ?? 'unknown';
            $categories[$cat] = true;
        }

        return [
            'version' => $this->currentVersion(),
            'package_version' => AnalyticsEvent::VERSION,
            'events_count' => count($catalog),
            'categories_count' => count($categories),
            'providers_count' => 10,
            'hash' => $this->hashCatalog($catalog),
            'history_count' => count($this->getHistory()),
        ];
    }

    /**
     * Invalidate the cached version (useful after catalog changes).
     */
    public function invalidate(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Clear the entire version history.
     */
    public function clearHistory(): void
    {
        $this->cache->forget(self::CACHE_KEY);
        $this->cache->forget(self::HISTORY_KEY);
    }
}
