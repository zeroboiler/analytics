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
 * Programmatic health-check service for analytics.
 *
 * Provides a structured health report that can be consumed by monitoring
 * dashboards, CI pipelines, and alerting systems. Goes beyond the
 * console command by offering a reusable PHP API.
 *
 * @see \ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand
 */
final class AnalyticsHealthService
{
    private AnalyticsManager $manager;

    private AnalyticsMetrics $metrics;

    private EventReplayQueue $replayQueue;

    private ConfigRepository $config;

    /**
     * @param  AnalyticsManager  $manager
     * @param  AnalyticsMetrics  $metrics
     * @param  EventReplayQueue  $replayQueue
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        AnalyticsMetrics $metrics,
        EventReplayQueue $replayQueue,
        ConfigRepository $config,
    ) {
        $this->manager = $manager;
        $this->metrics = $metrics;
        $this->replayQueue = $replayQueue;
        $this->config = $config;
    }

    /**
     * Generate a full health report.
     *
     * @return array{
     *     status: 'healthy'|'warning'|'error',
     *     version: string,
     *     providers: array<string, array{enabled: bool, configured: bool}>,
     *     consent: array<string, string>,
     *     queue: array{enabled: bool, connection: string, queue: string},
     *     replay: array{enabled: bool, pending: int, max_attempts: int},
     *     metrics: array{enabled: bool, total_dispatched: int, total_failures: int},
     *     catalog: array{total: int, ecommerce: int, saas: int, engagement: int},
     *     validation: array{strict: bool, dedup_window: int},
     *     sampling: array{enabled: bool, rate: float},
     *     pii: array{enabled: bool, strategy: string},
     *     debug: bool,
     *     warnings: list<string>,
     *     recommendations: list<string>,
     * }
     */
    public function report(): array
    {
        $warnings = [];
        $recommendations = [];

        $providers = $this->manager->providerSummary();
        $enabledProviders = array_filter($providers, fn (array $p): bool => $p['enabled']);

        // No providers enabled
        if (count($enabledProviders) === 0) {
            $warnings[] = 'No analytics providers are enabled.';
            $recommendations[] = 'Enable at least one provider (GA4, GTM, Meta, Plausible, PostHog).';
        }

        // Provider-specific warnings
        foreach ($providers as $name => $info) {
            if (! $info['enabled']) {
                continue;
            }

            if (($info['id'] ?? null) === null || $info['id'] === '') {
                $warnings[] = "Provider '{$name}' is enabled but has no ID/credential configured.";
                $recommendations[] = "Set the environment variable for '{$name}' or disable it.";
            }
        }

        // Queue config
        $queueConfig = $this->config->get('zeroboiler.analytics.queue', []);
        /** @var array{enabled?: bool, connection?: string, queue?: string} $queueConfig */
        $queueEnabled = (bool) ($queueConfig['enabled'] ?? true);

        // Replay queue status
        $replayConfig = $this->config->get('zeroboiler.analytics.replay', []);
        /** @var array{enabled?: bool, max_attempts?: int} $replayConfig */
        $replaySummary = $this->replayQueue->summary();

        if ($replaySummary['pending'] > 0) {
            $warnings[] = "Event replay queue has {$replaySummary['pending']} pending event(s).";
        }

        // Metrics
        $metricsEnabled = (bool) $this->config->get('zeroboiler.analytics.metrics.enabled', false);
        $totalDispatched = $this->metrics->totalDispatched();
        $totalFailures = $this->metrics->totalFailed();

        if ($totalFailures > 0 && $totalDispatched > 0) {
            $failureRate = round(($totalFailures / $totalDispatched) * 100, 2);
            if ($failureRate > 10.0) {
                $warnings[] = "High failure rate: {$failureRate}% ({$totalFailures}/{$totalDispatched}).";
                $recommendations[] = 'Check provider credentials and network connectivity.';
            }
        }

        // Validation
        $validationConfig = $this->config->get('zeroboiler.analytics.validation', []);
        /** @var array{strict?: bool, deduplication_window?: int} $validationConfig */

        // Sampling
        $samplingConfig = $this->config->get('zeroboiler.analytics.sampling', []);
        /** @var array{enabled?: bool, rate?: float} $samplingConfig */
        if (($samplingConfig['enabled'] ?? false) && ($samplingConfig['rate'] ?? 1.0) < 0.5) {
            $recommendations[] = 'Sampling rate is below 50% — ensure this is intentional for production.';
        }

        // PII
        $piiConfig = $this->config->get('zeroboiler.analytics.pii_sanitization', []);
        /** @var array{enabled?: bool, strategy?: string} $piiConfig */
        if (! ($piiConfig['enabled'] ?? false)) {
            $recommendations[] = 'Consider enabling PII sanitization for GDPR compliance.';
        }

        // Consent
        $consentState = $this->manager->getConsent();

        // Debug mode
        $debugEnabled = $this->manager->isDebug();
        if ($debugEnabled) {
            $warnings[] = 'Debug mode is enabled — events are logged but not dispatched.';
            $recommendations[] = 'Disable debug mode (ANALYTICS_DEBUG_ENABLED=false) in production.';
        }

        // Catalog
        $catalogSummary = $this->manager->eventCatalogSummary();

        // Determine overall status
        $status = 'healthy';
        if (count($warnings) > 0) {
            $status = 'warning';
        }
        if (count($enabledProviders) === 0 || $debugEnabled) {
            $status = 'error';
        }

        return [
            'status' => $status,
            'version' => $this->manager->version(),
            'providers' => $providers,
            'consent' => $consentState->toArray(),
            'queue' => [
                'enabled' => $queueEnabled,
                'connection' => $queueConfig['connection'] ?? 'default',
                'queue' => $queueConfig['queue'] ?? 'analytics',
            ],
            'replay' => [
                'enabled' => (bool) ($replayConfig['enabled'] ?? true),
                'pending' => $replaySummary['pending'],
                'max_attempts' => (int) ($replayConfig['max_attempts'] ?? 3),
            ],
            'metrics' => [
                'enabled' => $metricsEnabled,
                'total_dispatched' => $totalDispatched,
                'total_failures' => $totalFailures,
            ],
            'catalog' => $catalogSummary,
            'validation' => [
                'strict' => (bool) ($validationConfig['strict'] ?? false),
                'dedup_window' => (int) ($validationConfig['deduplication_window'] ?? 10),
            ],
            'sampling' => [
                'enabled' => (bool) ($samplingConfig['enabled'] ?? false),
                'rate' => (float) ($samplingConfig['rate'] ?? 1.0),
            ],
            'pii' => [
                'enabled' => (bool) ($piiConfig['enabled'] ?? false),
                'strategy' => (string) ($piiConfig['strategy'] ?? 'hash'),
            ],
            'debug' => $debugEnabled,
            'warnings' => $warnings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Check if analytics is healthy (no warnings, providers enabled, not in debug mode).
     */
    public function isHealthy(): bool
    {
        return $this->report()['status'] === 'healthy';
    }

    /**
     * Get only the warnings from the health report.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->report()['warnings'];
    }

    /**
     * Get only the recommendations from the health report.
     *
     * @return list<string>
     */
    public function getRecommendations(): array
    {
        return $this->report()['recommendations'];
    }
}
