<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Analytics configuration audit service.
 *
 * Provides a safe, masked dump of the current analytics configuration
 * for debugging, admin dashboards, and compliance audits. Sensitive values
 * (API keys, secrets, tokens) are automatically masked.
 *
 * Configuration is read from `zeroboiler.analytics`.
 *
 * @version 5.0.0
 *
 * @since 1.0.0
 */
final class AnalyticsConfigAuditService
{
    /**
     * Keys that contain sensitive values and should be masked.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'api_secret',
        'access_token',
        'api_key',
        'secret',
        'token',
        'webhook_secret',
    ];

    /**
     * Keys whose values represent full URLs (safe to show domain, mask path).
     *
     * @var list<string>
     */
    private const URL_KEYS = [
        'url',
        'base_url',
        'host',
    ];

    private ConfigRepository $config;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
    {
        $this->config = $config;
    }

    /**
     * Get a masked dump of the entire analytics configuration.
     *
     * Recursively walks the `zeroboiler.analytics` config tree and masks
     * any values whose keys match known sensitive patterns.
     *
     * @return array{version: string, timestamp: string, config: array<string, mixed>, sections: int, masked_keys: int}
     */
    public function audit(): array
    {
        $fullConfig = $this->config->get('zeroboiler.analytics', []);

        $maskedCount = 0;
        $maskedConfig = $this->maskRecursive($fullConfig, $maskedCount);

        return [
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'config' => $maskedConfig,
            'sections' => count($maskedConfig),
            'masked_keys' => $maskedCount,
        ];
    }

    /**
     * Get a summary of enabled/disabled status for each provider and feature.
     *
     * Returns a flat map of feature → enabled status, useful for
     * admin dashboards and health checks.
     *
     * @return array{providers: array<string, bool>, features: array<string, mixed>, summary: array{total_enabled: int, total_configured: int}}
     */
    public function summary(): array
    {
        $config = $this->config->get('zeroboiler.analytics', []);

        $providers = [];
        $features = [];

        // Provider status
        $providerMap = [
            'ga4' => 'ga4.enabled',
            'gtm' => 'gtm.enabled',
            'meta_pixel' => 'meta_pixel.enabled',
            'plausible' => 'plausible.enabled',
            'posthog' => 'posthog.enabled',
            'webhook' => 'webhook.enabled',
        ];

        foreach ($providerMap as $name => $key) {
            $providers[$name] = (bool) ($this->config->get("zeroboiler.analytics.{$key}", false));
        }

        // Feature status
        $featureMap = [
            'queue' => $config['queue']['enabled'] ?? true,
            'api' => $config['api']['enabled'] ?? true,
            'auto_track' => $config['auto_track']['enabled'] ?? true,
            'track_links' => $config['track_links']['enabled'] ?? false,
            'consent_log' => $config['consent']['log_enabled'] ?? false,
            'sampling' => $config['sampling']['enabled'] ?? false,
            'budget' => $config['budget']['enabled'] ?? false,
            'cost_tracking' => $config['cost_tracking']['enabled'] ?? false,
            'notification_webhooks' => $config['notification_webhooks']['enabled'] ?? false,
            'lifecycle' => $config['lifecycle']['enabled'] ?? true,
            'governance' => $config['governance']['enabled'] ?? false,
            'pii_sanitization' => $config['pii_sanitization']['enabled'] ?? false,
        ];

        foreach ($featureMap as $name => $enabled) {
            $features[$name] = (bool) $enabled;
        }

        $enabledProviders = count(array_filter($providers));
        $enabledFeatures = count(array_filter($features));

        return [
            'providers' => $providers,
            'features' => $features,
            'summary' => [
                'total_enabled' => $enabledProviders + $enabledFeatures,
                'total_configured' => count($providers) + count($features),
            ],
        ];
    }

    /**
     * Get configuration diff between current and a provided snapshot.
     *
     * Compares the current config against a previously saved snapshot
     * to detect configuration drift. Returns added, removed, and changed keys.
     *
     * @param  array<string, mixed>  $snapshot  Previous config snapshot (unmasked)
     * @return array{added: list<string>, removed: list<string>, changed: list<string>, unchanged: list<string>}
     */
    public function diff(array $snapshot): array
    {
        $current = $this->config->get('zeroboiler.analytics', []);
        $currentFlat = $this->flatten($current);
        $snapshotFlat = $this->flatten($snapshot);

        $currentKeys = array_keys($currentFlat);
        $snapshotKeys = array_keys($snapshotFlat);

        return [
            'added' => array_values(array_diff($currentKeys, $snapshotKeys)),
            'removed' => array_values(array_diff($snapshotKeys, $currentKeys)),
            'changed' => array_values(array_filter(
                array_intersect($currentKeys, $snapshotKeys),
                fn (string $key): bool => $currentFlat[$key] !== $snapshotFlat[$key],
            )),
            'unchanged' => array_values(array_filter(
                array_intersect($currentKeys, $snapshotKeys),
                fn (string $key): bool => $currentFlat[$key] === $snapshotFlat[$key],
            )),
        ];
    }

    /**
     * Save a config snapshot to cache for future diff comparison.
     *
     * @param  string|null  $label  Optional label for the snapshot
     * @return array{saved: bool, label: string, timestamp: string, key: string}
     */
    public function saveSnapshot(?string $label = null): array
    {
        $current = $this->config->get('zeroboiler.analytics', []);
        $label = $label ?? 'auto-' . (new \DateTimeImmutable())->format('Y-m-d\\TH:i:s');
        $key = 'zb_analytics_config_snapshot:' . $label;

        try {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = app('cache');
            $cache->put($key, $current, 86400); // 24h TTL

            return [
                'saved' => true,
                'label' => $label,
                'timestamp' => (new \DateTimeImmutable())->format('c'),
                'key' => $key,
            ];
        } catch (\Throwable) {
            return [
                'saved' => false,
                'label' => $label,
                'timestamp' => (new \DateTimeImmutable())->format('c'),
                'key' => $key,
            ];
        }
    }

    /**
     * Load a previously saved config snapshot from cache.
     *
     * @param  string  $label  Snapshot label
     * @return array{found: bool, label: string, snapshot: array<string, mixed>|null}
     */
    public function loadSnapshot(string $label): array
    {
        $key = 'zb_analytics_config_snapshot:' . $label;

        try {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = app('cache');
            $snapshot = $cache->get($key);

            return [
                'found' => $snapshot !== null,
                'label' => $label,
                'snapshot' => is_array($snapshot) ? $snapshot : null,
            ];
        } catch (\Throwable) {
            return [
                'found' => false,
                'label' => $label,
                'snapshot' => null,
            ];
        }
    }

    /**
     * Recursively mask sensitive values in a config array.
     *
     * @param  array<string, mixed>  $config
     * @param  int  $maskedCount  Reference counter for masked keys
     * @return array<string, mixed>
     */
    private function maskRecursive(array $config, int &$maskedCount): array
    {
        $result = [];

        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->maskRecursive($value, $maskedCount);
            } elseif ($this->isSensitiveKey((string) $key)) {
                $result[$key] = $this->maskValue((string) $key, $value);
                $maskedCount++;
            } elseif ($this->isUrlKey((string) $key) && is_string($value)) {
                $result[$key] = $this->maskUrl((string) $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Check if a key name contains a sensitive pattern.
     */
    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a key represents a URL value.
     */
    private function isUrlKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::URL_KEYS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask a sensitive value, preserving type and length hints.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return string
     */
    private function maskValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '(empty)';
        }

        $str = (string) $value;
        $len = strlen($str);

        if ($len <= 4) {
            return '****';
        }

        return substr($str, 0, 2) . str_repeat('*', $len - 4) . substr($str, -2);
    }

    /**
     * Mask sensitive parts of a URL while preserving the domain.
     *
     * @param  string  $url
     * @return string
     */
    private function maskUrl(string $url): string
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['host'])) {
            return '****';
        }

        $host = $parsed['host'];
        $scheme = $parsed['scheme'] ?? 'https';

        return "{$scheme}://{$host}/***";
    }

    /**
     * Flatten a nested array to dot notation keys.
     *
     * @param  array<string, mixed>  $array
     * @param  string  $prefix
     * @return array<string, mixed>
     */
    private function flatten(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $fullKey = $prefix !== '' ? "{$prefix}.{$key}" : (string) $key;

            if (is_array($value) && $this->isAssociative($value)) {
                foreach ($this->flatten($value, $fullKey) as $k => $v) {
                    $result[$k] = $v;
                }
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Check if an array is associative (has string keys).
     *
     * @param  array<string, mixed>  $array
     */
    private function isAssociative(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
