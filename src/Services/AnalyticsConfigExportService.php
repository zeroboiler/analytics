<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Analytics configuration export service.
 *
 * Provides safe, redacted exports of the analytics configuration for
 * debugging, dashboards, and support workflows. Secrets are automatically
 * redacted unless explicitly requested.
 *
 * Supports snapshot comparison for detecting config drift between deployments.
 *
 * @since 6.5.0
 */
final class AnalyticsConfigExportService
{
    /**
     * Config keys that contain secrets and must be redacted in exports.
     *
     * @var list<string>
     */
    private const SECRET_KEYS = [
        'api_secret',
        'access_token',
        'api_key',
        'secret',
        'token',
        'write_key',
    ];

    private const REDACTED_VALUE = '********';

    private ConfigRepository $config;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
    {
        $this->config = $config;
    }

    /**
     * Export the full analytics configuration with secrets redacted.
     *
     * @return array<string, mixed>
     */
    public function exportRedacted(): array
    {
        $raw = $this->config->get('zeroboiler.analytics', []);

        return $this->redactRecursive($raw);
    }

    /**
     * Export only the enabled/disabled status of each provider and feature.
     *
     * Useful for admin dashboards and health checks.
     *
     * @return array{providers: array<string, bool>, features: array<string, bool>, version: string}
     */
    public function exportStatusSummary(): array
    {
        $raw = $this->config->get('zeroboiler.analytics', []);
        /** @var array<string, mixed> $raw */

        return [
            'providers' => [
                'ga4' => (bool) ($raw['ga4']['enabled'] ?? false),
                'gtm' => (bool) ($raw['gtm']['enabled'] ?? false),
                'meta_pixel' => (bool) ($raw['meta_pixel']['enabled'] ?? false),
                'plausible' => (bool) ($raw['plausible']['enabled'] ?? false),
                'posthog' => (bool) ($raw['posthog']['enabled'] ?? false),
                'webhook' => (bool) ($raw['webhook']['enabled'] ?? false),
            ],
            'features' => [
                'consent' => (bool) ($raw['consent']['log_enabled'] ?? false),
                'auto_track' => (bool) ($raw['auto_track']['enabled'] ?? true),
                'queue' => (bool) ($raw['queue']['enabled'] ?? true),
                'sampling' => (bool) ($raw['sampling']['enabled'] ?? false),
                'pii_sanitization' => (bool) ($raw['pii_sanitization']['enabled'] ?? false),
                'replay' => (bool) ($raw['replay']['enabled'] ?? true),
                'metrics' => (bool) ($raw['metrics']['enabled'] ?? false),
                'debug' => (bool) ($raw['debug']['enabled'] ?? false),
                'pipeline_auto_utm' => (bool) ($raw['pipeline']['auto_utm'] ?? true),
                'enrichment' => (bool) ($raw['enrichment']['enabled'] ?? true),
                'geolocation' => (bool) ($raw['geolocation']['enabled'] ?? false),
                'forwarding' => (bool) ($raw['forwarding']['enabled'] ?? false),
                'budget' => (bool) ($raw['budget']['enabled'] ?? false),
                'performance_budget' => (bool) ($raw['performance_budget']['enabled'] ?? false),
                'routing' => (bool) ($raw['routing']['enabled'] ?? false),
                'lifecycle' => (bool) ($raw['lifecycle']['enabled'] ?? true),
                'retention_policy' => (bool) ($raw['retention_policy']['enabled'] ?? false),
                'gate' => (bool) ($raw['gate']['enabled'] ?? false),
                'realtime' => (bool) ($raw['realtime']['enabled'] ?? true),
                'ab_tests' => (bool) ($raw['ab_tests']['enabled'] ?? true),
                'broadcast' => (bool) ($raw['broadcast']['enabled'] ?? false),
                'tenant' => (bool) ($raw['tenant']['enabled'] ?? false),
                'event_cache' => (bool) ($raw['event_cache']['enabled'] ?? true),
                'event_buckets' => (bool) ($raw['event_buckets']['enabled'] ?? true),
                'health_score' => (bool) ($raw['health_score']['enabled'] ?? true),
                'envelope' => (bool) ($raw['envelope']['enabled'] ?? true),
                'consent_purposes' => (bool) ($raw['consent_purposes']['enabled'] ?? false),
                'journeys' => (bool) ($raw['journeys']['enabled'] ?? true),
                'plg_scoring' => true, // always active when PLGScoringService is resolved
                'aarrr' => (bool) ($raw['aarrr']['enabled'] ?? true),
                'tracing' => (bool) ($raw['tracing']['enabled'] ?? true),
            ],
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
        ];
    }

    /**
     * Export a single config section (redacted).
     *
     * @param  string  $section  The config section key (e.g. 'ga4', 'queue', 'identity')
     * @return array<string, mixed>|null
     */
    public function exportSection(string $section): ?array
    {
        $raw = $this->config->get("zeroboiler.analytics.{$section}");

        if (! is_array($raw)) {
            return null;
        }

        /** @var array<string, mixed> $raw */

        return $this->redactRecursive($raw);
    }

    /**
     * Compute a diff between two config snapshots.
     *
     * Useful for detecting config drift between deployments or environments.
     * Returns added, removed, and changed keys with old/new values.
     *
     * @param  array<string, mixed>  $baseline  The previous config snapshot
     * @param  array<string, mixed>  $current  The current config snapshot
     * @return array{added: int, removed: int, changed: int, changes: list<array{key: string, old: mixed, new: mixed}>}
     */
    public function diff(array $baseline, array $current): array
    {
        $changes = [];
        $allKeys = array_unique(array_merge(array_keys($baseline), array_keys($current)));

        foreach ($allKeys as $key) {
            $inBaseline = array_key_exists($key, $baseline);
            $inCurrent = array_key_exists($key, $current);

            if ($inCurrent && ! $inBaseline) {
                continue; // added — skip for brevity
            }

            if (! $inCurrent && $inBaseline) {
                continue; // removed — skip for brevity
            }

            // Both exist — check for changes
            if ($baseline[$key] !== $current[$key]) {
                $oldVal = is_array($baseline[$key]) ? json_encode($baseline[$key]) : $baseline[$key];
                $newVal = is_array($current[$key]) ? json_encode($current[$key]) : $current[$key];

                $changes[] = [
                    'key' => $key,
                    'old' => $oldVal,
                    'new' => $newVal,
                ];
            }
        }

        return [
            'added' => count(array_diff(array_keys($current), array_keys($baseline))),
            'removed' => count(array_diff(array_keys($baseline), array_keys($current))),
            'changed' => count($changes),
            'changes' => $changes,
        ];
    }

    /**
     * Recursively redact secret values from a config array.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redactRecursive(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->redactRecursive($value);
            } elseif ($this->isSecretKey($key) && is_string($value) && $value !== '') {
                $result[$key] = self::REDACTED_VALUE;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Check if a config key name matches a known secret pattern.
     */
    private function isSecretKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SECRET_KEYS as $secretKey) {
            if (str_contains($lower, $secretKey)) {
                return true;
            }
        }

        return false;
    }
}
