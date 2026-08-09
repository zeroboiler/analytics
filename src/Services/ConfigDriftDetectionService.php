<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Config Drift Detection Service.
 *
 * Captures a snapshot of the analytics configuration and compares it against
 * a stored baseline to detect drift between deployments. Useful for:
 *
 * - CI/CD config validation gates
 * - Post-deployment verification
 * - Ops monitoring for accidental config changes
 * - Multi-environment config consistency checks
 *
 * Configuration: `zeroboiler.analytics.config_drift`
 *
 * The baseline is stored in the cache with a configurable TTL.
 * Call `captureBaseline()` after a verified deployment, then `detectDrift()`
 * to compare the current config against that baseline.
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsConfigValidator
 *
 * @since 1.0.0
 */
final class ConfigDriftDetectionService
{
    private const CACHE_PREFIX = 'zb_config_drift_';

    private const BASELINE_KEY = 'baseline';

    private bool $enabled;

    private CacheRepository $cache;

    private ConfigRepository $config;

    /** @var list<string> Keys to exclude from drift comparison */
    private array $excludeKeys;

    /** @var int Cache TTL for the baseline in seconds */
    private int $cacheTtl;

    /** @var list<string> Sections of the analytics config to monitor */
    private array $monitoredSections;

    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->cache = $cache;
        $this->config = $config;

        $driftConfig = $config->get('zeroboiler.analytics.config_drift', []);
        /** @var array{enabled?: bool, cache_ttl?: int, exclude_keys?: list<string>, monitored_sections?: list<string>} $driftConfig */
        $this->enabled = (bool) ($driftConfig['enabled'] ?? false);
        $this->cacheTtl = (int) ($driftConfig['cache_ttl'] ?? 2592000); // 30 days
        $this->excludeKeys = (array) ($driftConfig['exclude_keys'] ?? []);
        $this->monitoredSections = (array) ($driftConfig['monitored_sections'] ?? []);
    }

    /**
     * Capture the current analytics config as the baseline.
     *
     * Call this after a verified deployment to establish the known-good config.
     *
     * @return array{captured_at: string, version: string, sections: int, keys: int}
     */
    public function captureBaseline(): array
    {
        $snapshot = $this->buildSnapshot();
        $key = self::CACHE_PREFIX . self::BASELINE_KEY;

        $this->cache->put($key, $snapshot, $this->cacheTtl);

        $meta = [
            'captured_at' => now()->toIso8601String(),
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'sections' => count($snapshot),
            'keys' => $this->countKeys($snapshot),
        ];

        Log::info('ZeroBoiler Analytics: config baseline captured', $meta);

        return $meta;
    }

    /**
     * Detect drift between the current config and the stored baseline.
     *
     * @return array{drift_detected: bool, added: list<string>, removed: list<string>, changed: list<string>, unchanged: list<string>, baseline_version: string|null, current_version: string}
     */
    public function detectDrift(): array
    {
        $key = self::CACHE_PREFIX . self::BASELINE_KEY;
        /** @var array<string, mixed>|null $baseline */
        $baseline = $this->cache->get($key);

        $current = $this->buildSnapshot();

        if ($baseline === null) {
            return [
                'drift_detected' => false,
                'added' => [],
                'removed' => [],
                'changed' => [],
                'unchanged' => [],
                'baseline_version' => null,
                'current_version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
                'note' => 'No baseline captured. Call captureBaseline() first.',
            ];
        }

        $baselineFlat = $this->flattenConfig($baseline);
        $currentFlat = $this->flattenConfig($current);

        $baselineKeys = array_flip($baselineFlat);
        $currentKeys = array_flip($currentFlat);

        $added = [];
        $removed = [];
        $changed = [];
        $unchanged = [];

        // Detect added and changed keys
        foreach ($currentFlat as $path => $value) {
            if (! isset($baselineKeys[$path])) {
                $added[] = $path;
            } elseif ($baselineFlat[$path] !== $value) {
                $changed[] = [
                    'key' => $path,
                    'baseline' => $baselineFlat[$path],
                    'current' => $value,
                ];
            } else {
                $unchanged[] = $path;
            }
        }

        // Detect removed keys
        foreach ($baselineFlat as $path => $value) {
            if (! isset($currentKeys[$path])) {
                $removed[] = $path;
            }
        }

        $driftDetected = count($added) > 0 || count($removed) > 0 || count($changed) > 0;

        return [
            'drift_detected' => $driftDetected,
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'unchanged' => $unchanged,
            'baseline_version' => $baseline['_meta']['version'] ?? null,
            'current_version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'total_keys_baseline' => count($baselineFlat),
            'total_keys_current' => count($currentFlat),
        ];
    }

    /**
     * Clear the stored baseline.
     *
     * @return bool
     */
    public function clearBaseline(): bool
    {
        return $this->cache->forget(self::CACHE_PREFIX . self::BASELINE_KEY);
    }

    /**
     * Check if a baseline exists.
     */
    public function hasBaseline(): bool
    {
        return $this->cache->has(self::CACHE_PREFIX . self::BASELINE_KEY);
    }

    /**
     * Get the stored baseline metadata.
     *
     * @return array{captured_at: string|null, version: string|null, exists: bool}
     */
    public function baselineInfo(): array
    {
        $key = self::CACHE_PREFIX . self::BASELINE_KEY;
        /** @var array{captured_at?: string, version?: string}|null $baseline */
        $baseline = $this->cache->get($key);

        if ($baseline === null) {
            return ['captured_at' => null, 'version' => null, 'exists' => false];
        }

        return [
            'captured_at' => $baseline['_meta']['captured_at'] ?? null,
            'version' => $baseline['_meta']['version'] ?? null,
            'exists' => true,
        ];
    }

    /**
     * Build a snapshot of the current analytics config.
     *
     * @return array<string, mixed>
     */
    private function buildSnapshot(): array
    {
        $fullConfig = $this->config->get('zeroboiler.analytics', []);
        /** @var array<string, mixed> $fullConfig */

        // Filter to monitored sections only (if configured)
        if ($this->monitoredSections !== []) {
            $filtered = [];
            foreach ($this->monitoredSections as $section) {
                if (array_key_exists($section, $fullConfig)) {
                    $filtered[$section] = $fullConfig[$section];
                }
            }
            $fullConfig = $filtered;
        }

        // Apply key exclusions
        if ($this->excludeKeys !== []) {
            $fullConfig = $this->excludeKeysRecursive($fullConfig, $this->excludeKeys);
        }

        // Add metadata
        $fullConfig['_meta'] = [
            'captured_at' => now()->toIso8601String(),
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
        ];

        return $fullConfig;
    }

    /**
     * Recursively exclude keys from a nested config array.
     *
     * @param  array<string, mixed>  $config
     * @param  list<string>  $excludeKeys
     * @return array<string, mixed>
     */
    private function excludeKeysRecursive(array $config, array $excludeKeys): array
    {
        foreach ($excludeKeys as $excludeKey) {
            unset($config[$excludeKey]);
        }

        foreach ($config as $key => $value) {
            if (is_array($value) && isset($value['_meta']) === false) {
                $config[$key] = $this->excludeKeysRecursive($value, []);
            }
        }

        return $config;
    }

    /**
     * Flatten a nested config array into dot-notation paths.
     *
     * @param  array<string, mixed>  $config
     * @param  string  $prefix
     * @return array<string, mixed>
     */
    private function flattenConfig(array $config, string $prefix = ''): array
    {
        $flat = [];

        foreach ($config as $key => $value) {
            $path = $prefix !== '' ? $prefix . '.' . $key : (string) $key;

            if (is_array($value) && $this->isAssociative($value)) {
                foreach ($this->flattenConfig($value, $path) as $subPath => $subValue) {
                    $flat[$subPath] = $subValue;
                }
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    /**
     * Check if an array is associative (string keys, not sequential int keys).
     *
     * @param  array<string|int, mixed>  $array
     */
    private function isAssociative(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        foreach (array_keys($array) as $key) {
            if (is_string($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count total leaf keys in a nested config array.
     *
     * @param  array<string, mixed>  $config
     */
    private function countKeys(array $config): int
    {
        $count = 0;

        foreach ($config as $key => $value) {
            if ($key === '_meta') {
                continue;
            }

            if (is_array($value)) {
                $count += $this->countKeys($value);
            } else {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
