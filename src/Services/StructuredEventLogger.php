<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Structured event dispatch logger for analytics observability.
 *
 * Provides a unified, structured logging interface for all analytics event
 * dispatches. Instead of scattered Log::debug() calls, this service produces
 * consistent structured log entries with:
 *
 * - Standard fields: event_name, category, client_id, user_id, source, priority
 * - Dispatch context: provider, latency_ms, success/failure, error message
 * - Pipeline context: deduplicated, sampled, consent_state, cardinality_limited
 * - Trace context: trace_id, span_id (when available)
 *
 * Log entries use a consistent channel ("analytics") and format, making them
 * easy to ingest into observability platforms (Datadog, New Relic, Loki, Elasticsearch).
 *
 * Supports log level configuration per event category and per provider,
 * enabling fine-grained control over log verbosity in production.
 *
 * Inspired by OpenTelemetry's structured logging, Segment's debug logs,
 * and Datadog's log pipeline integration.
 *
 * Configuration: `zeroboiler.analytics.structured_logging`
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsObservabilityService
 *
 * @since 153.0.0
 */
final class StructuredEventLogger
{
    /** @var string Log channel for analytics events */
    private const DEFAULT_CHANNEL = 'analytics';

    /** @var string Log prefix for all analytics log entries */
    private const LOG_PREFIX = 'zb.analytics';

    private ConfigRepository $config;

    private bool $enabled;

    private string $channel;

    /** @var string Minimum log level for dispatch logs (debug|info|warning|error) */
    private string $dispatchLevel;

    /** @var string Minimum log level for error logs */
    private string $errorLevel;

    /** @var array<string, string> Per-category log level overrides */
    private array $categoryLevels;

    /** @var array<string, string> Per-provider log level overrides */
    private array $providerLevels;

    /** @var bool Include full event params in log output */
    private bool $includeParams;

    /** @var list<string> Sensitive param keys to redact */
    private array $sensitiveKeys;

    /** @var int Max param value length in log output (0 = no truncation) */
    private int $maxParamLength;

    /** @var list<string> Events to skip in logging */
    private array $excludedEvents;

    /** @var int Max events per minute before rate-limiting logs */
    private int $logRateLimit;

    /** @var int Counter for current minute's log count */
    private int $minuteLogCount = 0;

    /** @var int Timestamp of last rate limit reset */
    private int $lastRateLimitReset;

    /**
     * @param  ConfigRepository  $config  Analytics configuration
     */
    public function __construct(ConfigRepository $config): void
    {
        $this->config = $config;

        $logConfig = $config->get('zeroboiler.analytics.structured_logging', []);
        /** @var array{enabled?: bool, channel?: string, dispatch_level?: string, error_level?: string, category_levels?: array<string, string>, provider_levels?: array<string, string>, include_params?: bool, sensitive_keys?: list<string>, max_param_length?: int, excluded_events?: list<string>, log_rate_limit?: int} $logConfig */
        $this->enabled = (bool) ($logConfig['enabled'] ?? true);
        $this->channel = (string) ($logConfig['channel'] ?? self::DEFAULT_CHANNEL);
        $this->dispatchLevel = (string) ($logConfig['dispatch_level'] ?? 'debug');
        $this->errorLevel = (string) ($logConfig['error_level'] ?? 'error');
        $this->categoryLevels = (array) ($logConfig['category_levels'] ?? []);
        $this->providerLevels = (array) ($logConfig['provider_levels'] ?? []);
        $this->includeParams = (bool) ($logConfig['include_params'] ?? false);
        $this->sensitiveKeys = (array) ($logConfig['sensitive_keys'] ?? [
            'email', 'password', 'token', 'api_key', 'secret', 'ip_address',
            'credit_card', 'ssn', 'phone',
        ]);
        $this->maxParamLength = (int) ($logConfig['max_param_length'] ?? 100);
        $this->excludedEvents = (array) ($logConfig['excluded_events'] ?? []);
        $this->logRateLimit = (int) ($logConfig['log_rate_limit'] ?? 1000);
        $this->lastRateLimitReset = time();
    }

    /**
     * Log a successful event dispatch.
     *
     * @param  AnalyticsEvent  $event  The dispatched event
     * @param  string  $provider  Provider name (ga4, meta, etc.)
     * @param  float  $latencyMs  Dispatch latency in milliseconds
     * @param  array<string, mixed>  $context  Additional context (deduplicated, sampled, etc.)
     */
    public function logDispatch(AnalyticsEvent $event, string $provider, float $latencyMs, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        if ($this->shouldSkip($event->name)) {
            return;
        }

        if (! $this->checkRateLimit()) {
            return;
        }

        $level = $this->resolveLevel($event->category, $provider, $this->dispatchLevel);

        $entry = $this->buildDispatchEntry($event, $provider, $latencyMs, $context);

        Log::channel($this->channel)->$level(self::LOG_PREFIX . '.dispatch', $entry);
    }

    /**
     * Log a failed event dispatch.
     *
     * @param  AnalyticsEvent  $event  The event that failed
     * @param  string  $provider  Provider name
     * @param  string  $errorMessage  Error message
     * @param  array<string, mixed>  $context  Additional context
     */
    public function logError(AnalyticsEvent $event, string $provider, string $errorMessage, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        if ($this->shouldSkip($event->name)) {
            return;
        }

        $entry = array_merge(
            $this->buildBaseEntry($event),
            [
                'provider' => $provider,
                'error' => $errorMessage,
                'dispatch_status' => 'failed',
            ],
            $context,
        );

        Log::channel($this->channel)->{$this->errorLevel}(self::LOG_PREFIX . '.error', $entry);
    }

    /**
     * Log a dropped event (due to consent, dedup, sampling, cardinality, etc.).
     *
     * @param  AnalyticsEvent  $event  The dropped event
     * @param  string  $reason  Drop reason
     * @param  array<string, mixed>  $context  Additional context
     */
    public function logDropped(AnalyticsEvent $event, string $reason, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        if ($this->shouldSkip($event->name)) {
            return;
        }

        if (! $this->checkRateLimit()) {
            return;
        }

        $entry = array_merge(
            $this->buildBaseEntry($event),
            [
                'dispatch_status' => 'dropped',
                'drop_reason' => $reason,
            ],
            $context,
        );

        Log::channel($this->channel)->debug(self::LOG_PREFIX . '.dropped', $entry);
    }

    /**
     * Log an event pipeline transit (middleware pipeline step).
     *
     * @param  AnalyticsEvent  $event  The event being processed
     * @param  string  $stage  Pipeline stage name
     * @param  string  $action  Action taken (enriched, filtered, transformed, passed)
     * @param  array<string, mixed>  $context  Additional context
     */
    public function logPipelineTransit(AnalyticsEvent $event, string $stage, string $action, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        if ($this->shouldSkip($event->name)) {
            return;
        }

        if (! $this->checkRateLimit()) {
            return;
        }

        $entry = array_merge(
            $this->buildBaseEntry($event),
            [
                'pipeline_stage' => $stage,
                'pipeline_action' => $action,
            ],
            $context,
        );

        Log::channel($this->channel)->debug(self::LOG_PREFIX . '.pipeline', $entry);
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the current configuration summary.
     *
     * @return array{enabled: bool, channel: string, dispatch_level: string, error_level: string, include_params: bool, log_rate_limit: int, excluded_events_count: int}
     */
    public function getConfigSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'channel' => $this->channel,
            'dispatch_level' => $this->dispatchLevel,
            'error_level' => $this->errorLevel,
            'include_params' => $this->includeParams,
            'log_rate_limit' => $this->logRateLimit,
            'excluded_events_count' => count($this->excludedEvents),
        ];
    }

    /**
     * Build a dispatch log entry.
     *
     * @param  AnalyticsEvent  $event  The dispatched event
     * @param  string  $provider  Provider name
     * @param  float  $latencyMs  Dispatch latency in milliseconds
     * @param  array<string, mixed>  $context  Additional context
     * @return array<string, mixed>
     */
    private function buildDispatchEntry(AnalyticsEvent $event, string $provider, float $latencyMs, array $context): array
    {
        return array_merge(
            $this->buildBaseEntry($event),
            [
                'provider' => $provider,
                'latency_ms' => round($latencyMs, 2),
                'dispatch_status' => 'success',
            ],
            $context,
        );
    }

    /**
     * Build base log entry from an event.
     *
     * @return array<string, mixed>
     */
    private function buildBaseEntry(AnalyticsEvent $event): array
    {
        $entry = [
            'event_name' => $event->name,
            'category' => $event->category,
            'client_id' => $this->maskValue($event->clientId),
            'user_id' => $this->maskValue($event->userId),
            'source' => $event->source,
            'priority' => $event->priority,
            'session_id' => $this->maskValue($event->sessionId),
            'timestamp' => $event->timestamp?->format('Y-m-d\TH:i:s.vP'),
            'version' => AnalyticsEvent::VERSION,
        ];

        if ($this->includeParams && $event->params !== []) {
            $entry['params'] = $this->sanitizeParams($event->params);
        }

        return $entry;
    }

    /**
     * Sanitize event params for log output.
     *
     * Redacts sensitive keys and truncates long values.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @return array<string, mixed> Sanitized parameters
     */
    private function sanitizeParams(array $params): array
    {
        $sanitized = [];

        foreach ($params as $key => $value) {
            if (in_array($key, $this->sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if (is_string($value) && $this->maxParamLength > 0 && strlen($value) > $this->maxParamLength) {
                $sanitized[$key] = substr($value, 0, $this->maxParamLength) . '...[truncated]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Mask a value for log output (show first 8 chars).
     */
    private function maskValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (strlen($value) <= 8) {
            return $value;
        }

        return substr($value, 0, 8) . '...';
    }

    /**
     * Resolve the log level for a given category + provider combination.
     */
    private function resolveLevel(?string $category, string $provider, string $defaultLevel): string
    {
        if ($category !== null && isset($this->categoryLevels[$category])) {
            return $this->categoryLevels[$category];
        }

        if (isset($this->providerLevels[$provider])) {
            return $this->providerLevels[$provider];
        }

        return $defaultLevel;
    }

    /**
     * Check if an event should be skipped.
     */
    private function shouldSkip(string $eventName): bool
    {
        return in_array($eventName, $this->excludedEvents, true);
    }

    /**
     * Check and enforce log rate limit.
     */
    private function checkRateLimit(): bool
    {
        $now = time();

        if ($now !== $this->lastRateLimitReset) {
            $this->minuteLogCount = 0;
            $this->lastRateLimitReset = $now;
        }

        if ($this->minuteLogCount >= $this->logRateLimit) {
            return false;
        }

        $this->minuteLogCount++;

        return true;
    }
}
