<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event Deprecation & Versioning Service.
 *
 * Manages event lifecycle metadata: deprecation detection, stability
 * enforcement, migration suggestions, and deprecation audit logging.
 *
 * Events marked as deprecated emit warnings and can optionally be blocked
 * or silently forwarded to their replacement event. The service reads
 * versioning metadata from the config-driven deprecation registry and
 * cross-references with the event catalog.
 *
 * Features:
 * - Detect usage of deprecated events at dispatch time
 * - Suggest replacement events with parameter mapping hints
 * - Emit structured deprecation warnings to log channel
 * - Cache deprecation warnings to prevent log spam
 * - Provide deprecation audit report for admin dashboards
 * - Enforce stability policies (stable, beta, experimental)
 *
 * Inspired by Segment Event Protocol versioning and PostHog event deprecation.
 *
 * @since 44.0.0
 */
final class EventDeprecationService
{
    /** @var array<string, array{since?: string, deprecated?: string, deprecated_in?: string, replaced_by?: string|null, stability?: string, message?: string}> */
    private array $registry = [];

    private readonly bool $enabled;

    private readonly bool $blockDeprecated;

    private readonly bool $autoRedirect;

    private readonly string $cachePrefix;

    private readonly int $warningTtl;

    private readonly string $logChannel;

    /**
     * @param  CacheRepository  $cache  Cache repository for deprecation warning deduplication
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ): void {
        $versioningConfig = $config->get('zeroboiler.analytics.event_versioning', []);
        /** @var array{enabled?: bool, block_deprecated?: bool, auto_redirect?: bool, cache_prefix?: string, warning_ttl?: int, log_channel?: string, registry?: array<string, mixed>} $versioningConfig */

        $this->enabled = (bool) ($versioningConfig['enabled'] ?? true);
        $this->blockDeprecated = (bool) ($versioningConfig['block_deprecated'] ?? false);
        $this->autoRedirect = (bool) ($versioningConfig['auto_redirect'] ?? false);
        $this->cachePrefix = $versioningConfig['cache_prefix'] ?? 'zb_deprecation_';
        $this->warningTtl = (int) ($versioningConfig['warning_ttl'] ?? 3600);
        $this->logChannel = $versioningConfig['log_channel'] ?? 'daily';
        $this->registry = $versioningConfig['registry'] ?? [];
    }

    /**
     * Check if an event name is deprecated.
     *
     * Returns deprecation metadata if the event is marked deprecated,
     * or null if the event is active or not in the registry.
     *
     * @return array{since: string, deprecated_in: string, replaced_by: string|null, stability: string, message: string}|null
     */
    public function getDeprecation(string $eventName): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $entry = $this->registry[$eventName] ?? null;

        if ($entry === null) {
            return null;
        }

        $deprecated = $entry['deprecated'] ?? false;

        if (! $deprecated) {
            return null;
        }

        return [
            'since' => $entry['since'] ?? 'unknown',
            'deprecated_in' => $entry['deprecated_in'] ?? 'unknown',
            'replaced_by' => $entry['replaced_by'] ?? null,
            'stability' => $entry['stability'] ?? 'deprecated',
            'message' => $entry['message'] ?? "Event '{$eventName}' is deprecated.",
        ];
    }

    /**
     * Check if an event name is deprecated and emit a warning if so.
     *
     * Deduplicates warnings using the cache to prevent log spam.
     * Returns true if the event is deprecated, false otherwise.
     */
    public function checkAndWarn(string $eventName): bool
    {
        $deprecation = $this->getDeprecation($eventName);

        if ($deprecation === null) {
            return false;
        }

        $cacheKey = $this->cachePrefix . md5($eventName);

        if ($this->cache->has($cacheKey)) {
            return true;
        }

        $this->cache->put($cacheKey, true, $this->warningTtl);

        $replacement = $deprecation['replaced_by'];
        $message = $deprecation['message'];

        if ($replacement !== null) {
            $message .= " Use '{$replacement}' instead.";
        }

        Log::channel($this->logChannel)->warning("[ZeroBoiler] Deprecated event dispatched: {$eventName}", [
            'event' => $eventName,
            'deprecated_in' => $deprecation['deprecated_in'],
            'replaced_by' => $replacement,
            'message' => $message,
        ]);

        return true;
    }

    /**
     * Resolve the effective event name, applying auto-redirect for deprecated events.
     *
     * When auto_redirect is enabled and the event has a replacement,
     * returns the replacement event name. Otherwise returns the original.
     */
    public function resolve(string $eventName): string
    {
        if (! $this->autoRedirect) {
            return $eventName;
        }

        $deprecation = $this->getDeprecation($eventName);

        if ($deprecation !== null && $deprecation['replaced_by'] !== null) {
            return $deprecation['replaced_by'];
        }

        return $eventName;
    }

    /**
     * Check if a deprecated event should be blocked from dispatch.
     *
     * When block_deprecated is enabled, deprecated events without a
     * valid replacement are blocked.
     */
    public function shouldBlock(string $eventName): bool
    {
        if (! $this->blockDeprecated) {
            return false;
        }

        $deprecation = $this->getDeprecation($eventName);

        if ($deprecation === null) {
            return false;
        }

        // Block only if no replacement exists
        return $deprecation['replaced_by'] === null;
    }

    /**
     * Get the stability level for an event.
     *
     * @return 'stable'|'beta'|'experimental'|'deprecated'|'unknown'
     */
    public function getStability(string $eventName): string
    {
        if (! $this->enabled) {
            return 'stable';
        }

        $entry = $this->registry[$eventName] ?? null;

        if ($entry === null) {
            return 'stable';
        }

        return $entry['stability'] ?? 'stable';
    }

    /**
     * Check if an event meets a minimum stability requirement.
     *
     * Stability levels (lowest to highest): experimental, beta, stable
     *
     * @param  'experimental'|'beta'|'stable'  $minimum
     */
    public function meetsStability(string $eventName, string $minimum): bool
    {
        $levels = [
            'experimental' => 0,
            'beta' => 1,
            'stable' => 2,
        ];

        $current = $this->getStability($eventName);
        $currentLevel = $levels[$current] ?? 2;
        $minimumLevel = $levels[$minimum] ?? 2;

        return $currentLevel >= $minimumLevel;
    }

    /**
     * Get a full deprecation audit report.
     *
     * Returns all deprecated events with their metadata, replacement
     * suggestions, and catalog status (whether replacement exists in catalog).
     *
     * @return array{total_deprecated: int, total_registry: int, events: list<array{name: string, deprecated_in: string, replaced_by: string|null, replacement_in_catalog: bool, stability: string}>}
     */
    public function auditReport(): array
    {
        $deprecated = [];
        $totalCount = count($this->registry);

        foreach ($this->registry as $name => $entry) {
            if (($entry['deprecated'] ?? false) === true) {
                $replacedBy = $entry['replaced_by'] ?? null;
                $deprecated[] = [
                    'name' => $name,
                    'deprecated_in' => $entry['deprecated_in'] ?? 'unknown',
                    'replaced_by' => $replacedBy,
                    'replacement_in_catalog' => $replacedBy !== null && EventCatalog::has($replacedBy),
                    'stability' => $entry['stability'] ?? 'deprecated',
                ];
            }
        }

        // Sort by deprecated_in descending (most recent first)
        usort($deprecated, fn (array $a, array $b): int => strcmp($b['deprecated_in'], $a['deprecated_in']));

        return [
            'total_deprecated' => count($deprecated),
            'total_registry' => $totalCount,
            'events' => $deprecated,
        ];
    }

    /**
     * Get all events that are in beta or experimental stability.
     *
     * Useful for dashboards showing unstable event usage.
     *
     * @return list<array{name: string, stability: string, since: string}>
     */
    public function unstableEvents(): array
    {
        $unstable = [];

        foreach ($this->registry as $name => $entry) {
            $stability = $entry['stability'] ?? 'stable';

            if (in_array($stability, ['beta', 'experimental'], true)) {
                $unstable[] = [
                    'name' => $name,
                    'stability' => $stability,
                    'since' => $entry['since'] ?? 'unknown',
                ];
            }
        }

        return $unstable;
    }

    /**
     * Register or update an event's versioning metadata.
     *
     * This modifies the in-memory registry only. Persist changes
     * by updating the config file.
     *
     * @param  array{since?: string, deprecated?: bool, deprecated_in?: string, replaced_by?: string|null, stability?: string, message?: string}  $metadata
     */
    public function register(string $eventName, array $metadata): void
    {
        $existing = $this->registry[$eventName] ?? [];
        $this->registry[$eventName] = array_merge($existing, $metadata);
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the total number of events in the deprecation registry.
     */
    public function registryCount(): int
    {
        return count($this->registry);
    }
}
