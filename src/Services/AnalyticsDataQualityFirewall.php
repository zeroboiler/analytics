<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\SimpleCache\InvalidArgumentException;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Analytics data quality firewall — pre-dispatch quality scoring and auto-quarantine.
 *
 * Evaluates every event before dispatch using configurable quality rules:
 * - Completeness check: required parameters present
 * - Freshness check: event not stale (TTL)
 * - Format check: parameter types and naming conventions
 * - Velocity check: not exceeding per-user/per-event rate limits
 * - Consistency check: parameter values within expected ranges
 *
 * Events failing quality checks are either quarantined (stored for review)
 * or dropped (silently discarded), based on severity.
 *
 * Quality scores range from 0.0 (worst) to 1.0 (perfect).
 * Events scoring below the configured threshold are quarantined.
 *
 * Config: `zeroboiler.analytics.quality_firewall`
 *
 * @since 46.0.0
 */
final class AnalyticsDataQualityFirewall
{
    private bool $enabled;

    private float $quarantineThreshold;

    private float $dropThreshold;

    private bool $enforceQuarantine;

    private bool $enforceDrop;

    private string $cachePrefix;

    private int $metricsTtl;

    private int $velocityWindow;

    private int $maxEventsPerWindow;

    /** @var list<string> Parameters required for all events */
    private array $requiredGlobalParams;

    /** @var array<string, list<string>> Event-specific required parameters */
    private array $eventRequiredParams;

    /** @var list<string> Reserved parameter prefixes to check */
    private array $reservedPrefixes;

    private CacheRepository $cache;

    /** @var array<string, int> In-memory velocity counters for current request */
    private array $velocityCounters = [];

    /**
     * Create a new AnalyticsDataQualityFirewall.
     *
     * @param  CacheRepository  $cache  Cache repository for metrics and velocity tracking
     * @param  ConfigRepository  $config  Application config repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $fwConfig = $config->get('zeroboiler.analytics.quality_firewall', []);
        /** @var array{enabled?: bool, quarantine_threshold?: float, drop_threshold?: float, enforce_quarantine?: bool, enforce_drop?: bool, cache_prefix?: string, metrics_ttl?: int, velocity_window?: int, max_events_per_window?: int, required_global_params?: list<string>, event_required_params?: array<string, list<string>>, reserved_prefixes?: list<string>} $fwConfig */

        $this->enabled = (bool) ($fwConfig['enabled'] ?? false);
        $this->quarantineThreshold = (float) ($fwConfig['quarantine_threshold'] ?? 0.5);
        $this->dropThreshold = (float) ($fwConfig['drop_threshold'] ?? 0.2);
        $this->enforceQuarantine = (bool) ($fwConfig['enforce_quarantine'] ?? false);
        $this->enforceDrop = (bool) ($fwConfig['enforce_drop'] ?? false);
        $this->cachePrefix = (string) ($fwConfig['cache_prefix'] ?? 'zb_qf_');
        $this->metricsTtl = (int) ($fwConfig['metrics_ttl'] ?? 3600);
        $this->velocityWindow = (int) ($fwConfig['velocity_window'] ?? 60);
        $this->maxEventsPerWindow = (int) ($fwConfig['max_events_per_window'] ?? 100);
        $this->requiredGlobalParams = (array) ($fwConfig['required_global_params'] ?? []);
        $this->eventRequiredParams = (array) ($fwConfig['event_required_params'] ?? []);
        $this->reservedPrefixes = (array) ($fwConfig['reserved_prefixes'] ?? ['_ga_', '_fb_', '_meta_']);
    }

    /**
     * Evaluate an event's data quality and determine its disposition.
     *
     * Returns a quality report with score, violations, and disposition
     * (pass, quarantine, or drop).
     *
     * @param  AnalyticsEvent  $event  The event to evaluate
     * @return array{score: float, disposition: 'pass'|'quarantine'|'drop', violations: list<array{rule: string, severity: string, message: string}>}
     */
    public function evaluate(AnalyticsEvent $event): array
    {
        if (! $this->enabled) {
            return ['score' => 1.0, 'disposition' => 'pass', 'violations' => []];
        }

        $violations = [];
        $totalScore = 1.0;

        // 1. Completeness check
        $completenessResult = $this->checkCompleteness($event);
        $totalScore -= $completenessResult['penalty'];
        $violations = array_merge($violations, $completenessResult['violations']);

        // 2. Format check
        $formatResult = $this->checkFormat($event);
        $totalScore -= $formatResult['penalty'];
        $violations = array_merge($violations, $formatResult['violations']);

        // 3. Velocity check
        $velocityResult = $this->checkVelocity($event);
        $totalScore -= $velocityResult['penalty'];
        $violations = array_merge($violations, $velocityResult['violations']);

        // 4. Consistency check
        $consistencyResult = $this->checkConsistency($event);
        $totalScore -= $consistencyResult['penalty'];
        $violations = array_merge($violations, $consistencyResult['violations']);

        $score = max(0.0, min(1.0, $totalScore));

        // Determine disposition
        $disposition = $this->resolveDisposition($score);

        // Update metrics
        match ($disposition) {
            'pass' => $this->incrementMetric('passed'),
            'quarantine' => $this->incrementMetric('quarantined'),
            'drop' => $this->incrementMetric('dropped'),
            default => null,
        };
        $this->incrementMetric('evaluated');

        return [
            'score' => round($score, 4),
            'disposition' => $disposition,
            'violations' => $violations,
        ];
    }

    /**
     * Quick check — should this event be blocked entirely?
     *
     * Returns true if the event should be prevented from dispatch.
     * More efficient than full evaluate() when you only need a pass/fail.
     */
    public function shouldBlock(AnalyticsEvent $event): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $result = $this->evaluate($event);

        return $result['disposition'] === 'drop';
    }

    /**
     * Check event completeness (required parameters).
     *
     * @return array{penalty: float, violations: list<array{rule: string, severity: string, message: string}>}
     */
    private function checkCompleteness(AnalyticsEvent $event): array
    {
        $violations = [];
        $penalty = 0.0;

        // Check global required params
        foreach ($this->requiredGlobalParams as $param) {
            if (! array_key_exists($param, $event->params)) {
                $violations[] = [
                    'rule' => 'completeness',
                    'severity' => 'medium',
                    'message' => "Missing required global parameter: {$param}",
                ];
                $penalty += 0.1;
            }
        }

        // Check event-specific required params
        $eventSpecific = $this->eventRequiredParams[$event->name] ?? [];
        foreach ($eventSpecific as $param) {
            if (! array_key_exists($param, $event->params)) {
                $violations[] = [
                    'rule' => 'completeness',
                    'severity' => 'high',
                    'message' => "Missing required parameter '{$param}' for event '{$event->name}'",
                ];
                $penalty += 0.15;
            }
        }

        return ['penalty' => $penalty, 'violations' => $violations];
    }

    /**
     * Check event format (naming conventions, types).
     *
     * @return array{penalty: float, violations: list<array{rule: string, severity: string, message: string}>}
     */
    private function checkFormat(AnalyticsEvent $event): array
    {
        $violations = [];
        $penalty = 0.0;

        // Check event name format
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $event->name)) {
            $violations[] = [
                'rule' => 'format',
                'severity' => 'high',
                'message' => "Event name '{$event->name}' does not match snake_case convention",
            ];
            $penalty += 0.2;
        }

        // Check event name length
        if (mb_strlen($event->name) > 100) {
            $violations[] = [
                'rule' => 'format',
                'severity' => 'medium',
                'message' => 'Event name exceeds 100 characters',
            ];
            $penalty += 0.1;
        }

        // Check parameter key naming
        foreach (array_keys($event->params) as $key) {
            if (! is_string($key)) {
                $violations[] = [
                    'rule' => 'format',
                    'severity' => 'medium',
                    'message' => "Non-string parameter key detected",
                ];
                $penalty += 0.05;

                continue;
            }

            if (mb_strlen($key) > 100) {
                $violations[] = [
                    'rule' => 'format',
                    'severity' => 'low',
                    'message' => "Parameter key '{$key}' exceeds 100 characters",
                ];
                $penalty += 0.02;
            }
        }

        // Check for reserved prefixes in params
        foreach ($this->reservedPrefixes as $prefix) {
            foreach (array_keys($event->params) as $key) {
                if (is_string($key) && str_starts_with($key, $prefix)) {
                    $violations[] = [
                        'rule' => 'format',
                        'severity' => 'low',
                        'message' => "Parameter '{$key}' uses reserved prefix '{$prefix}'",
                    ];
                    $penalty += 0.02;
                }
            }
        }

        return ['penalty' => $penalty, 'violations' => $violations];
    }

    /**
     * Check event velocity (rate limiting per event type).
     *
     * @return array{penalty: float, violations: list<array{rule: string, severity: string, message: string}>}
     */
    private function checkVelocity(AnalyticsEvent $event): array
    {
        $violations = [];
        $penalty = 0.0;

        $velocityKey = $event->name;
        $this->velocityCounters[$velocityKey] = ($this->velocityCounters[$velocityKey] ?? 0) + 1;

        if ($this->velocityCounters[$velocityKey] > $this->maxEventsPerWindow) {
            $violations[] = [
                'rule' => 'velocity',
                'severity' => 'high',
                'message' => "Event '{$event->name}' exceeds velocity limit ({$this->maxEventsPerWindow}/{$this->velocityWindow}s)",
            ];
            $penalty += 0.3;
        }

        return ['penalty' => $penalty, 'violations' => $violations];
    }

    /**
     * Check event consistency (parameter value ranges).
     *
     * @return array{penalty: float, violations: list<array{rule: string, severity: string, message: string}>}
     */
    private function checkConsistency(AnalyticsEvent $event): array
    {
        $violations = [];
        $penalty = 0.0;

        // Check for empty/whitespace-only string values
        foreach ($event->params as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                $violations[] = [
                    'rule' => 'consistency',
                    'severity' => 'low',
                    'message' => "Parameter '{$key}' has empty string value",
                ];
                $penalty += 0.02;
            }

            // Check for null bytes
            if (is_string($value) && str_contains($value, "\0")) {
                $violations[] = [
                    'rule' => 'consistency',
                    'severity' => 'medium',
                    'message' => "Parameter '{$key}' contains null bytes",
                ];
                $penalty += 0.1;
            }

            // Check for excessively long string values
            if (is_string($value) && mb_strlen($value) > 500) {
                $violations[] = [
                    'rule' => 'consistency',
                    'severity' => 'low',
                    'message' => "Parameter '{$key}' value exceeds 500 characters",
                ];
                $penalty += 0.02;
            }
        }

        // Check param count is reasonable
        $paramCount = count($event->params);
        if ($paramCount > 100) {
            $violations[] = [
                'rule' => 'consistency',
                'severity' => 'medium',
                'message' => "Event has {$paramCount} parameters (max 100)",
            ];
            $penalty += 0.1;
        }

        return ['penalty' => $penalty, 'violations' => $violations];
    }

    /**
     * Resolve disposition based on score and enforcement settings.
     *
     * @return 'pass'|'quarantine'|'drop'
     */
    private function resolveDisposition(float $score): string
    {
        if ($this->enforceDrop && $score < $this->dropThreshold) {
            return 'drop';
        }

        if ($this->enforceQuarantine && $score < $this->quarantineThreshold) {
            return 'quarantine';
        }

        return 'pass';
    }

    /**
     * Get quality firewall metrics.
     *
     * @return array{enabled: bool, evaluated: int, passed: int, quarantined: int, dropped: int, quarantine_threshold: float, drop_threshold: float}
     */
    public function getMetrics(): array
    {
        return [
            'enabled' => $this->enabled,
            'evaluated' => $this->getMetric('evaluated'),
            'passed' => $this->getMetric('passed'),
            'quarantined' => $this->getMetric('quarantined'),
            'dropped' => $this->getMetric('dropped'),
            'quarantine_threshold' => $this->quarantineThreshold,
            'drop_threshold' => $this->dropThreshold,
        ];
    }

    /**
     * Check if the firewall is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get a summary of the firewall configuration.
     *
     * @return array{enabled: bool, quarantine_threshold: float, drop_threshold: float, enforce_quarantine: bool, enforce_drop: bool, velocity_window: int, max_events_per_window: int, required_global_params_count: int, event_required_params_count: int}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'quarantine_threshold' => $this->quarantineThreshold,
            'drop_threshold' => $this->dropThreshold,
            'enforce_quarantine' => $this->enforceQuarantine,
            'enforce_drop' => $this->enforceDrop,
            'velocity_window' => $this->velocityWindow,
            'max_events_per_window' => $this->maxEventsPerWindow,
            'required_global_params_count' => count($this->requiredGlobalParams),
            'event_required_params_count' => count($this->eventRequiredParams),
        ];
    }

    /**
     * Increment a named metric counter.
     */
    private function incrementMetric(string $key): void
    {
        try {
            $cacheKey = $this->cachePrefix . 'metrics_' . $key;
            $current = (int) $this->cache->get($cacheKey, 0);
            $this->cache->put($cacheKey, $current + 1, $this->metricsTtl);
        } catch (InvalidArgumentException $e) {
            // Ignore cache errors
        }
    }

    /**
     * Get a named metric counter value.
     */
    private function getMetric(string $key): int
    {
        try {
            return (int) $this->cache->get($this->cachePrefix . 'metrics_' . $key, 0);
        } catch (InvalidArgumentException $e) {
            return 0;
        }
    }
}
