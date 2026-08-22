<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Config Drift Detection Service.
 *
 * Monitors analytics configuration for unexpected changes by comparing
 * live configuration values against a captured baseline snapshot. When
 * drift is detected, it generates alerts with severity levels and
 * actionable recommendations.
 *
 * Use cases:
 * - **Pre-deploy checks**: Ensure no critical config changed accidentally
 * - **Post-deploy verification**: Confirm expected config changes landed
 * - **Continuous monitoring**: Scheduled drift checks in production
 * - **Multi-environment audit**: Track config differences between staging/production
 *
 * Features:
 * - Configurable sensitivity levels (ignore_drift) for known-acceptable changes
 * - Per-section baseline comparison (ga4, meta, consent, queue, identity, etc.)
 * - Severity classification: critical, warning, info
 * - Auto-capture baseline from current config
 * - Cache-persisted baselines and drift history
 * - Diff report generation with before/after values
 *
 * Inspired by Terraform drift detection, AWS Config Rules,
 * and Datadog's config audit capabilities.
 *
 * Configuration: `zeroboiler.analytics.config_drift`
 *
 * @see \ZeroBoiler\Analytics\Console\Commands\AnalyticsConfigAuditCommand
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController
 *
 * @since 189.0.0
 */
final class ConfigDriftDetectionService
{
    /** @var string Cache key prefix for baselines and history */
    private const CACHE_PREFIX = 'zb_config_drift_';

    /** @var int Default TTL for cached baselines (24 hours) */
    private const DEFAULT_TTL = 86400;

    /** @var int Max drift history entries to keep */
    private const MAX_HISTORY = 100;

    /** @var string Critical severity */
    public const SEVERITY_CRITICAL = 'critical';

    /** @var string Warning severity */
    public const SEVERITY_WARNING = 'warning';

    /** @var string Info severity */
    public const SEVERITY_INFO = 'info';

    /** @var list<string> Sections whose drift is always critical */
    private const CRITICAL_SECTIONS = [
        'ga4.enabled', 'meta_pixel.enabled', 'gtm.enabled',
        'consent.default', 'queue.enabled', 'api.require_auth',
    ];

    /** @var list<string> Sections whose drift is typically a warning */
    private const WARNING_SECTIONS = [
        'ga4.measurement_id', 'ga4.api_secret', 'meta_pixel.id',
        'queue.queue', 'queue.connection', 'identity.cookie_name',
    ];

    /** @var list<string> Top-level config sections to monitor */
    private const MONITORED_SECTIONS = [
        'ga4', 'gtm', 'meta_pixel', 'consent', 'queue',
        'lifecycle', 'api', 'client_auto_track', 'identity',
        'auto_track', 'revenue_checksum', 'dedup_cache',
        'sampling', 'data_lake', 'trace_context',
    ];

    private CacheRepository $cache;

    private ConfigRepository $config;

    private bool $enabled;

    private int $ttl;

    /** @var list<string> Config keys to ignore when detecting drift */
    private array $ignoreKeys;

    /** @var string Baseline label (environment identifier) */
    private string $baselineLabel;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $cdConfig = $config->get('zeroboiler.analytics.config_drift', []);
        /** @var array{enabled?: bool, ttl?: int, ignore_keys?: list<string>, baseline_label?: string} $cdConfig */
        $this->enabled = (bool) ($cdConfig['enabled'] ?? true);
        $this->ttl = (int) ($cdConfig['ttl'] ?? self::DEFAULT_TTL);
        $this->ignoreKeys = (array) ($cdConfig['ignore_keys'] ?? []);
        $this->baselineLabel = (string) ($cdConfig['baseline_label'] ?? 'default');
    }

    /**
     * Capture current config as a baseline snapshot.
     *
     * Stores the current analytics configuration in cache for future drift comparison.
     *
     * @param  string|null  $label  Baseline label (defaults to configured label)
     * @param  array<string>|null  $sections  Sections to capture (null = all monitored)
     * @return array{label: string, captured_at: string, sections: int, keys: int}
     */
    public function captureBaseline(?string $label = null, ?array $sections = null): array
    {
        $label = $label ?? $this->baselineLabel;
        $sections = $sections ?? self::MONITORED_SECTIONS;
        $snapshot = $this->extractConfig($sections);
        $cacheKey = self::CACHE_PREFIX . 'baseline_' . $label;

        $this->cache->put($cacheKey, [
            'label' => $label,
            'captured_at' => now()->toIso8601String(),
            'snapshot' => $snapshot,
        ], $this->ttl);

        return [
            'label' => $label,
            'captured_at' => now()->toIso8601String(),
            'sections' => count($sections),
            'keys' => $this->countKeys($snapshot),
        ];
    }

    /**
     * Detect configuration drift against a captured baseline.
     *
     * Compares current live config with the stored baseline and returns
     * a detailed drift report with severity levels and recommendations.
     *
     * @param  string|null  $label  Baseline label to compare against
     * @return array{drift_detected: bool, drift_count: int, critical: int, warnings: int, info: int, changes: list<array{key: string, baseline: mixed, current: mixed, severity: string, recommendation: string}>, baseline_info: array{label: string, captured_at: string}|null}
     */
    public function detect(?string $label = null): array
    {
        if (! $this->enabled) {
            return $this->emptyReport();
        }

        $label = $label ?? $this->baselineLabel;
        $cacheKey = self::CACHE_PREFIX . 'baseline_' . $label;
        $baseline = $this->cache->get($cacheKey);

        if (! is_array($baseline) || ! isset($baseline['snapshot'])) {
            return $this->emptyReport();
        }

        $current = $this->extractConfig(self::MONITORED_SECTIONS);
        $changes = $this->computeDiff($baseline['snapshot'], $current);

        if ($changes === []) {
            $this->recordHistory($label, 0);

            return [
                'drift_detected' => false,
                'drift_count' => 0,
                'critical' => 0,
                'warnings' => 0,
                'info' => 0,
                'changes' => [],
                'baseline_info' => [
                    'label' => $baseline['label'],
                    'captured_at' => $baseline['captured_at'],
                ],
            ];
        }

        $categorized = $this->categorizeDrift($changes);

        $this->recordHistory($label, count($changes));

        return [
            'drift_detected' => true,
            'drift_count' => count($changes),
            'critical' => count(array_filter($categorized, fn (array $c): bool => $c['severity'] === self::SEVERITY_CRITICAL)),
            'warnings' => count(array_filter($categorized, fn (array $c): bool => $c['severity'] === self::SEVERITY_WARNING)),
            'info' => count(array_filter($categorized, fn (array $c): bool => $c['severity'] === self::SEVERITY_INFO)),
            'changes' => $categorized,
            'baseline_info' => [
                'label' => $baseline['label'],
                'captured_at' => $baseline['captured_at'],
            ],
        ];
    }

    /**
     * Detect drift for a specific config section only.
     *
     * Useful for targeted checks (e.g., only check ga4 section after GA4 config update).
     *
     * @param  string  $section  Section name (e.g., 'ga4', 'consent')
     * @param  string|null  $label  Baseline label
     * @return array{drift_detected: bool, changes: list<array{key: string, baseline: mixed, current: mixed, severity: string, recommendation: string}>}
     */
    public function detectSection(string $section, ?string $label = null): array
    {
        if (! $this->enabled) {
            return ['drift_detected' => false, 'changes' => []];
        }

        $label = $label ?? $this->baselineLabel;
        $cacheKey = self::CACHE_PREFIX . 'baseline_' . $label;
        $baseline = $this->cache->get($cacheKey);

        if (! is_array($baseline) || ! isset($baseline['snapshot'][$section])) {
            return ['drift_detected' => false, 'changes' => []];
        }

        $currentSection = $this->config->get('zeroboiler.analytics.' . $section, []);
        $changes = $this->computeDiff(
            [$section => $baseline['snapshot'][$section]],
            [$section => $currentSection],
        );

        $categorized = $this->categorizeDrift($changes);

        return [
            'drift_detected' => count($categorized) > 0,
            'changes' => $categorized,
        ];
    }

    /**
     * Get the stored baseline information (without diff).
     *
     * @param  string|null  $label
     * @return array{exists: bool, label: string|null, captured_at: string|null, sections: int, keys: int}|null
     */
    public function getBaseline(?string $label = null): ?array
    {
        $label = $label ?? $this->baselineLabel;
        $cacheKey = self::CACHE_PREFIX . 'baseline_' . $label;
        $baseline = $this->cache->get($cacheKey);

        if (! is_array($baseline) || ! isset($baseline['snapshot'])) {
            return null;
        }

        return [
            'exists' => true,
            'label' => $baseline['label'],
            'captured_at' => $baseline['captured_at'],
            'sections' => count($baseline['snapshot']),
            'keys' => $this->countKeys($baseline['snapshot']),
        ];
    }

    /**
     * Clear a stored baseline.
     *
     * @param  string|null  $label
     */
    public function clearBaseline(?string $label = null): void
    {
        $label = $label ?? $this->baselineLabel;
        $this->cache->forget(self::CACHE_PREFIX . 'baseline_' . $label);
    }

    /**
     * Get drift detection history.
     *
     * @param  string|null  $label
     * @return list<array{detected_at: string, drift_count: int}>
     */
    public function getHistory(?string $label = null): array
    {
        $label = $label ?? $this->baselineLabel;
        $history = $this->cache->get(self::CACHE_PREFIX . 'history_' . $label);

        return is_array($history) ? $history : [];
    }

    /**
     * Clear drift detection history.
     *
     * @param  string|null  $label
     */
    public function clearHistory(?string $label = null): void
    {
        $label = $label ?? $this->baselineLabel;
        $this->cache->forget(self::CACHE_PREFIX . 'history_' . $label);
    }

    /**
     * Get a quick summary of current drift status.
     *
     * @return array{enabled: bool, baseline_exists: bool, drift_detected: bool|null, last_checked: string|null, drift_count: int}
     */
    public function quickSummary(): array
    {
        $baseline = $this->getBaseline();

        if ($baseline === null) {
            return [
                'enabled' => $this->enabled,
                'baseline_exists' => false,
                'drift_detected' => null,
                'last_checked' => null,
                'drift_count' => 0,
            ];
        }

        $report = $this->detect();

        return [
            'enabled' => $this->enabled,
            'baseline_exists' => true,
            'drift_detected' => $report['drift_detected'],
            'last_checked' => now()->toIso8601String(),
            'drift_count' => $report['drift_count'],
        ];
    }

    /**
     * Check if config drift detection is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the list of monitored config sections.
     *
     * @return list<string>
     */
    public function getMonitoredSections(): array
    {
        return self::MONITORED_SECTIONS;
    }

    /**
     * Get the list of ignored config keys.
     *
     * @return list<string>
     */
    public function getIgnoredKeys(): array
    {
        return $this->ignoreKeys;
    }

    /**
     * Get service statistics.
     *
     * @return array{enabled: bool, baseline_label: string, ttl: int, monitored_sections: int, critical_sections: int, warning_sections: int, ignored_keys: int}
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'baseline_label' => $this->baselineLabel,
            'ttl' => $this->ttl,
            'monitored_sections' => count(self::MONITORED_SECTIONS),
            'critical_sections' => count(self::CRITICAL_SECTIONS),
            'warning_sections' => count(self::WARNING_SECTIONS),
            'ignored_keys' => count($this->ignoreKeys),
        ];
    }

    /**
     * Extract current analytics config for the given sections.
     *
     * @param  list<string>  $sections
     * @return array<string, mixed>
     */
    private function extractConfig(array $sections): array
    {
        $extracted = [];

        foreach ($sections as $section) {
            $extracted[$section] = $this->config->get('zeroboiler.analytics.' . $section);
        }

        return $extracted;
    }

    /**
     * Compute diff between baseline and current config.
     *
     * Recursively compares nested arrays and returns flat list of changes.
     *
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $current
     * @return list<array{key: string, baseline: mixed, current: mixed}>
     */
    private function computeDiff(array $baseline, array $current): array
    {
        $changes = [];

        foreach ($baseline as $key => $baselineValue) {
            $currentValue = $current[$key] ?? null;

            if ($this->isIgnoredKey((string) $key)) {
                continue;
            }

            if (is_array($baselineValue) && is_array($currentValue)) {
                // Recurse into nested arrays
                $nestedChanges = $this->computeDiff($this->prefixKeys($baselineValue, (string) $key), $this->prefixKeys($currentValue, (string) $key));
                array_push($changes, ...$nestedChanges);
            } elseif (! $this->valuesEqual($baselineValue, $currentValue)) {
                $changes[] = [
                    'key' => (string) $key,
                    'baseline' => $baselineValue,
                    'current' => $currentValue,
                ];
            }
        }

        // Check for new keys not in baseline
        foreach ($current as $key => $currentValue) {
            if (! array_key_exists($key, $baseline) && ! $this->isIgnoredKey((string) $key)) {
                $changes[] = [
                    'key' => (string) $key,
                    'baseline' => null,
                    'current' => $currentValue,
                ];
            }
        }

        return $changes;
    }

    /**
     * Categorize drift changes with severity and recommendations.
     *
     * @param  list<array{key: string, baseline: mixed, current: mixed}>  $changes
     * @return list<array{key: string, baseline: mixed, current: mixed, severity: string, recommendation: string}>
     */
    private function categorizeDrift(array $changes): array
    {
        $categorized = [];

        foreach ($changes as $change) {
            $severity = $this->classifySeverity($change['key'], $change['baseline'], $change['current']);
            $recommendation = $this->getRecommendation($change['key'], $change['baseline'], $change['current'], $severity);

            $categorized[] = array_merge($change, [
                'severity' => $severity,
                'recommendation' => $recommendation,
            ]);
        }

        return $categorized;
    }

    /**
     * Classify the severity of a config drift.
     *
     * @param  string  $key
     * @param  mixed  $baseline
     * @param  mixed  $current
     * @return string  One of: critical, warning, info
     */
    private function classifySeverity(string $key, mixed $baseline, mixed $current): string
    {
        // Check if it's a critical section key
        foreach (self::CRITICAL_SECTIONS as $criticalKey) {
            if ($key === $criticalKey || str_starts_with($key, $criticalKey . '.')) {
                return self::SEVERITY_CRITICAL;
            }
        }

        // Check if a provider was enabled/disabled
        if (str_ends_with($key, '.enabled') && $this->isToggleChange($baseline, $current)) {
            return self::SEVERITY_CRITICAL;
        }

        // Warning sections
        foreach (self::WARNING_SECTIONS as $warningKey) {
            if ($key === $warningKey || str_starts_with($key, $warningKey . '.')) {
                return self::SEVERITY_WARNING;
            }
        }

        // Value changed from null/empty to something (new config added)
        if ($baseline === null && $current !== null) {
            return self::SEVERITY_INFO;
        }

        // Value removed (set to null)
        if ($baseline !== null && $current === null) {
            return self::SEVERITY_WARNING;
        }

        return self::SEVERITY_INFO;
    }

    /**
     * Generate a recommendation for a drift change.
     *
     * @param  string  $key
     * @param  mixed  $baseline
     * @param  mixed  $current
     * @param  string  $severity
     * @return string
     */
    private function getRecommendation(string $key, mixed $baseline, mixed $current, string $severity): string
    {
        return match (true) {
            $severity === self::SEVERITY_CRITICAL => sprintf(
                'Critical config change detected: "%s" changed from %s to %s. Verify this is intentional and update the baseline.',
                $key,
                $this->formatValue($baseline),
                $this->formatValue($current),
            ),
            $severity === self::SEVERITY_WARNING => sprintf(
                'Config drift: "%s" changed from %s to %s. Review for correctness.',
                $key,
                $this->formatValue($baseline),
                $this->formatValue($current),
            ),
            default => sprintf(
                'Config change: "%s" updated from %s to %s.',
                $key,
                $this->formatValue($baseline),
                $this->formatValue($current),
            ),
        };
    }

    /**
     * Check if a config key should be ignored.
     */
    private function isIgnoredKey(string $key): bool
    {
        foreach ($this->ignoreKeys as $pattern) {
            if ($key === $pattern || str_starts_with($key, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a change is a boolean toggle (true ↔ false).
     *
     * @param  mixed  $baseline
     * @param  mixed  $current
     */
    private function isToggleChange(mixed $baseline, mixed $current): bool
    {
        return is_bool($baseline) && is_bool($current) && $baseline !== $current;
    }

    /**
     * Compare two values for equality (deep comparison for arrays).
     *
     * @param  mixed  $a
     * @param  mixed  $b
     */
    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            return $a === $b;
        }

        return $a === $b;
    }

    /**
     * Prefix all keys in a nested array with a parent key.
     *
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function prefixKeys(array $array, string $prefix): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $prefixedKey = $prefix . '.' . $key;

            if (is_array($value) && ! $this->isAssociative($value)) {
                // Numeric array — store as-is
                $result[$prefixedKey] = $value;
            } elseif (is_array($value)) {
                $nested = $this->prefixKeys($value, $prefixedKey);
                foreach ($nested as $nk => $nv) {
                    $result[$nk] = $nv;
                }
            } else {
                $result[$prefixedKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Check if an array is associative (has string keys).
     *
     * @param  array<mixed>  $array
     */
    private function isAssociative(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Format a value for display in reports.
     *
     * @param  mixed  $value
     */
    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            return strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
        }

        if (is_array($value)) {
            $json = json_encode($value);

            return is_string($json) ? (strlen($json) > 100 ? substr($json, 0, 100) . '...' : $json) : 'array';
        }

        return (string) $value;
    }

    /**
     * Count total keys in a nested config array.
     *
     * @param  array<string, mixed>  $config
     */
    private function countKeys(array $config): int
    {
        $count = 0;

        foreach ($config as $value) {
            $count++;

            if (is_array($value)) {
                $count += $this->countKeys($value);
            }
        }

        return $count;
    }

    /**
     * Record drift detection in history.
     *
     * @param  string  $label
     * @param  int  $driftCount
     */
    private function recordHistory(string $label, int $driftCount): void
    {
        $cacheKey = self::CACHE_PREFIX . 'history_' . $label;
        $history = $this->cache->get($cacheKey, []);

        if (! is_array($history)) {
            $history = [];
        }

        $history[] = [
            'detected_at' => now()->toIso8601String(),
            'drift_count' => $driftCount,
        ];

        // Keep only the last N entries
        if (count($history) > self::MAX_HISTORY) {
            $history = array_slice($history, -self::MAX_HISTORY);
        }

        $this->cache->put($cacheKey, $history, $this->ttl);
    }

    /**
     * Return an empty drift report.
     *
     * @return array{drift_detected: bool, drift_count: int, critical: int, warnings: int, info: int, changes: list<never>, baseline_info: null}
     */
    private function emptyReport(): array
    {
        return [
            'drift_detected' => false,
            'drift_count' => 0,
            'critical' => 0,
            'warnings' => 0,
            'info' => 0,
            'changes' => [],
            'baseline_info' => null,
        ];
    }
}
