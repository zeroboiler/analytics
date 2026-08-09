<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Queue\EventReplayQueue;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Real-time event aggregation and analytics health diagnostic service.
 *
 * Provides time-windowed event counting, top events ranking, provider
 * health monitoring, and a comprehensive health check report. Designed
 * for admin dashboards and monitoring integrations.
 *
 * @since 1.0.0
 */
final class EventAggregationService
{
    /** @var array<string, int> Event name → count */
    private array $eventCounts = [];

    /** @var array<string, array<string, int>> Category → event name → count */
    private array $categoryCounts = [];

    /** @var int Total events tracked in current window */
    private int $totalTracked = 0;

    /** @var int Maximum events to track in a window before rotation */
    private int $windowSize;

    private AnalyticsManager $manager;

    private AnalyticsMetrics $metrics;

    private EventReplayQueue $replayQueue;

    private ConfigRepository $config;

    /**
     * @param  AnalyticsManager  $manager
     * @param  AnalyticsMetrics  $metrics
     * @param  EventReplayQueue  $replayQueue
     * @param  ConfigRepository  $config
     * @param  int  $windowSize  Max events before rotation (0 = unlimited)
     */
    public function __construct(
        AnalyticsManager $manager,
        AnalyticsMetrics $metrics,
        EventReplayQueue $replayQueue,
        ConfigRepository $config,
        int $windowSize = 0,
    ): void {
        $this->manager = $manager;
        $this->metrics = $metrics;
        $this->replayQueue = $replayQueue;
        $this->config = $config;
        $this->windowSize = $windowSize;
    }

    /**
     * Record an event occurrence for aggregation.
     *
     * @param  string  $eventName  Event name
     * @param  string|null  $category  Optional category (ecommerce, saas, engagement)
     */
    public function record(string $eventName, ?string $category = null): void
    {
        $this->eventCounts[$eventName] = ($this->eventCounts[$eventName] ?? 0) + 1;
        $this->totalTracked++;

        if ($category !== null) {
            if (! isset($this->categoryCounts[$category])) {
                $this->categoryCounts[$category] = [];
            }
            $this->categoryCounts[$category][$eventName] = ($this->categoryCounts[$category][$eventName] ?? 0) + 1;
        }

        // Window rotation
        if ($this->windowSize > 0 && $this->totalTracked >= $this->windowSize) {
            $this->rotate();
        }
    }

    /**
     * Get the top N most frequent events.
     *
     * @return list<array{event: string, count: int}>
     */
    public function topEvents(int $limit = 10): array
    {
        $sorted = $this->eventCounts;
        arsort($sorted);

        $result = [];
        $count = 0;

        foreach ($sorted as $event => $cnt) {
            $result[] = ['event' => $event, 'count' => $cnt];
            $count++;

            if ($count >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Get event counts grouped by category.
     *
     * @return array<string, array<string, int>>
     */
    public function byCategory(): array
    {
        return $this->categoryCounts;
    }

    /**
     * Get the total number of tracked events in the current window.
     */
    public function totalTracked(): int
    {
        return $this->totalTracked;
    }

    /**
     * Get the count for a specific event.
     */
    public function countFor(string $eventName): int
    {
        return $this->eventCounts[$eventName] ?? 0;
    }

    /**
     * Get all event counts.
     *
     * @return array<string, int>
     */
    public function allCounts(): array
    {
        return $this->eventCounts;
    }

    /**
     * Rotate the aggregation window (reset counts).
     */
    public function rotate(): void
    {
        $this->eventCounts = [];
        $this->categoryCounts = [];
        $this->totalTracked = 0;
    }

    /**
     * Get a comprehensive health report for all analytics components.
     *
     * Checks providers, queue, replay, consent, validation, sampling,
     * PII sanitization, and overall system health. Returns actionable
     * warnings and recommendations.
     *
     * @return array{status: string, providers: array<string, mixed>, queue: array<string, mixed>, replay: array<string, mixed>, consent: array<string, mixed>, validation: array<string, mixed>, sampling: array<string, mixed>, pii: array<string, mixed>, metrics: array<string, mixed>, catalog: array<string, mixed>, aggregation: array<string, mixed>, warnings: list<string>, recommendations: list<string>, version: string}
     */
    public function healthReport(): array
    {
        $warnings = [];
        $recommendations = [];

        // ── Providers ──
        $providerHealth = [];
        $providerConfigs = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'webhook'];
        $anyEnabled = false;

        foreach ($providerConfigs as $key) {
            $config = $this->config->get("zeroboiler.analytics.{$key}", []);
            $enabled = (bool) ($config['enabled'] ?? false);

            if ($enabled) {
                $anyEnabled = true;
            }

            $providerHealth[$key] = [
                'enabled' => $enabled,
                'configured' => $this->isProviderConfigured($key, $config),
            ];

            // Warnings
            if ($enabled && ! $this->isProviderConfigured($key, $config)) {
                $warnings[] = "{$key} is enabled but not fully configured";
            }
        }

        if (! $anyEnabled) {
            $warnings[] = 'No analytics providers are enabled';
            $recommendations[] = 'Enable at least one provider (GA4, GTM, Meta Pixel) for analytics to function';
        }

        // ── Queue ──
        $queueConfig = $this->config->get('zeroboiler.analytics.queue', []);
        $queueEnabled = (bool) ($queueConfig['enabled'] ?? true);

        $queueHealth = [
            'enabled' => $queueEnabled,
            'queue_name' => $queueConfig['queue'] ?? 'analytics',
            'connection' => $queueConfig['connection'] ?? 'default',
        ];

        // ── Replay Queue ──
        $replayConfig = $this->config->get('zeroboiler.analytics.replay', []);
        $replaySummary = $this->replayQueue->summary();

        $replayHealth = [
            'enabled' => (bool) ($replayConfig['enabled'] ?? true),
            'max_attempts' => (int) ($replayConfig['max_attempts'] ?? 3),
            'queued' => $replaySummary['queued'] ?? 0,
            'failed' => $replaySummary['failed'] ?? 0,
            'retried' => $replaySummary['retried'] ?? 0,
        ];

        if (($replaySummary['failed'] ?? 0) > 0) {
            $warnings[] = "{$replaySummary['failed']} events permanently failed in replay queue";
            $recommendations[] = 'Check provider credentials and network connectivity for failed replay events';
        }

        // ── Consent ──
        $consentConfig = $this->config->get('zeroboiler.analytics.consent', []);
        $consentHealth = [
            'default' => $consentConfig['default'] ?? 'granted',
        ];

        if (($consentConfig['default'] ?? 'granted') === 'granted') {
            $recommendations[] = 'Consider setting ANALYTICS_CONSENT_DEFAULT=denied for GDPR compliance';
        }

        // ── Validation ──
        $validationConfig = $this->config->get('zeroboiler.analytics.validation', []);
        $validationHealth = [
            'strict' => (bool) ($validationConfig['strict'] ?? false),
            'dedup_window' => (int) ($validationConfig['deduplication_window'] ?? 10),
        ];

        // ── Sampling ──
        $samplingConfig = $this->config->get('zeroboiler.analytics.sampling', []);
        $samplingEnabled = (bool) ($samplingConfig['enabled'] ?? false);

        $samplingHealth = [
            'enabled' => $samplingEnabled,
            'rate' => (float) ($samplingConfig['rate'] ?? 1.0),
        ];

        if ($samplingEnabled && ($samplingConfig['rate'] ?? 1.0) < 0.5) {
            $warnings[] = 'Sampling rate is below 50% — significant data loss';
        }

        // ── PII ──
        $piiConfig = $this->config->get('zeroboiler.analytics.pii_sanitization', []);
        $piiHealth = [
            'enabled' => (bool) ($piiConfig['enabled'] ?? false),
            'strategy' => $piiConfig['strategy'] ?? 'hash',
        ];

        if (! ((bool) ($piiConfig['enabled'] ?? false))) {
            $recommendations[] = 'Enable PII sanitization (ANALYTICS_PII_ENABLED=true) for GDPR compliance';
        }

        // ── Metrics ──
        $metricsHealth = [
            'dispatched' => $this->metrics->totalDispatched(),
            'failed' => $this->metrics->totalFailed(),
            'per_provider' => $this->metrics->summary(),
        ];

        // ── Catalog ──
        $catalogHealth = $this->manager->eventCatalogSummary();

        // ── Aggregation ──
        $aggregationHealth = [
            'tracked_in_window' => $this->totalTracked,
            'unique_events' => count($this->eventCounts),
            'top_events' => $this->topEvents(5),
        ];

        // ── Overall Status ──
        $hasWarnings = count($warnings) > 0;
        $status = ! $anyEnabled ? 'error' : ($hasWarnings ? 'warning' : 'healthy');

        return [
            'status' => $status,
            'providers' => $providerHealth,
            'queue' => $queueHealth,
            'replay' => $replayHealth,
            'consent' => $consentHealth,
            'validation' => $validationHealth,
            'sampling' => $samplingHealth,
            'pii' => $piiHealth,
            'metrics' => $metricsHealth,
            'catalog' => $catalogHealth,
            'aggregation' => $aggregationHealth,
            'warnings' => $warnings,
            'recommendations' => $recommendations,
            'version' => $this->manager->version(),
        ];
    }

    /**
     * Check if a provider has all required configuration values set.
     *
     * @param  string  $key  Provider config key
     * @param  array<string, mixed>  $config  Provider config values
     */
    private function isProviderConfigured(string $key, array $config): bool
    {
        return match ($key) {
            'ga4' => ($config['measurement_id'] ?? '') !== '',
            'gtm' => ($config['container_id'] ?? '') !== '',
            'meta_pixel' => ($config['id'] ?? '') !== '',
            'plausible' => ($config['domain'] ?? '') !== '',
            'posthog' => ($config['api_key'] ?? '') !== '',
            'webhook' => ($config['url'] ?? '') !== '',
            default => false,
        };
    }
}
