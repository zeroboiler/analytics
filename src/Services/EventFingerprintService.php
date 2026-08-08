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
 * Content-aware event fingerprinting service.
 *
 * Generates deterministic fingerprints from event name, client ID, user ID,
 * and parameter content. Uses the cache driver for deduplication window
 * tracking. Supports configurable window TTL and max tracked fingerprints.
 *
 * Designed for use in event deduplication middleware and batch processing
 * to prevent the same event from being dispatched multiple times within
 * a short time window.
 *
 * @since v2.84.0
 */
final class EventFingerprintService
{
    /** @var non-empty-string */
    private string $cachePrefix;

    private int $windowSeconds;

    private int $maxFingerprints;

    private bool $enabled;

    private ?CacheRepository $cache;

    /**
     * @param  CacheRepository|null  $cache  Optional cache repository (injected when available)
     */
    public function __construct(?CacheRepository $cache = null): void
    {
        $this->cache = $cache;

        try {
            $config = app(\Illuminate\Contracts\Config\Repository::class);
            $dedupConfig = $config->get('zeroboiler.analytics.dedup', []);
            /** @var array{enabled?: bool, window_seconds?: int, max_fingerprints?: int, cache_prefix?: string} $dedupConfig */
            $this->enabled = (bool) ($dedupConfig['enabled'] ?? true);
            $this->windowSeconds = (int) ($dedupConfig['window_seconds'] ?? 10);
            $this->maxFingerprints = (int) ($dedupConfig['max_fingerprints'] ?? 10000);
            $this->cachePrefix = (string) ($dedupConfig['cache_prefix'] ?? 'zb_fp_');
        } catch (\Throwable) {
            $this->enabled = true;
            $this->windowSeconds = 10;
            $this->maxFingerprints = 10000;
            $this->cachePrefix = 'zb_fp_';
        }
    }

    /**
     * Generate a deterministic fingerprint for an analytics event.
     *
     * Combines event name, client ID, user ID, and sorted parameter content
     * into a stable hash for deduplication.
     */
    public function fingerprint(AnalyticsEvent $event): string
    {
        $content = json_encode([
            'name' => $event->name,
            'client' => $event->clientId ?? '',
            'user' => $event->userId ?? '',
            'params' => $this->sortedParams($event->params),
        ], JSON_THROW_ON_ERROR);

        return hash('xxh128', $content);
    }

    /**
     * Check if an event fingerprint has been seen within the dedup window.
     *
     * Returns true if the fingerprint is already tracked (duplicate).
     * If not tracked, registers it in the cache for the window duration.
     */
    public function isDuplicate(AnalyticsEvent $event): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $fp = $this->fingerprint($event);

        if ($this->cache === null) {
            return false;
        }

        $key = $this->cachePrefix . $fp;

        if ($this->cache->has($key)) {
            return true;
        }

        $this->cache->put($key, true, $this->windowSeconds);

        return false;
    }

    /**
     * Register an event fingerprint without checking for duplicates.
     *
     * Use this when you want to pre-register a fingerprint for a known
     * event (e.g., after a successful dispatch) to prevent replay.
     */
    public function register(AnalyticsEvent $event): void
    {
        if (! $this->enabled || $this->cache === null) {
            return;
        }

        $fp = $this->fingerprint($event);
        $this->cache->put($this->cachePrefix . $fp, true, $this->windowSeconds);
    }

    /**
     * Check if deduplication is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the dedup window in seconds.
     */
    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /**
     * Get the maximum number of tracked fingerprints.
     */
    public function getMaxFingerprints(): int
    {
        return $this->maxFingerprints;
    }

    /**
     * Sort and normalize event parameters for consistent fingerprinting.
     *
     * Recursively sorts array keys to ensure that parameter order does not
     * affect the fingerprint. Filters out null values for stability.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function sortedParams(array $params): array
    {
        $filtered = array_filter($params, fn (mixed $v): bool => $v !== null);

        ksort($filtered);

        foreach ($filtered as $key => $value) {
            if (is_array($value)) {
                $filtered[$key] = $this->sortedParams($value);
            } elseif (is_bool($value)) {
                $filtered[$key] = $value ? 'true' : 'false';
            } elseif (is_float($value)) {
                $filtered[$key] = round($value, 6);
            }
        }

        return $filtered;
    }

    /**
     * Get a summary of the fingerprint service configuration.
     *
     * @return array{enabled: bool, window_seconds: int, max_fingerprints: int, cache_prefix: string}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'window_seconds' => $this->windowSeconds,
            'max_fingerprints' => $this->maxFingerprints,
            'cache_prefix' => $this->cachePrefix,
        ];
    }
}
