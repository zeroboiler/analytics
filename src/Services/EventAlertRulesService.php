<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Config-driven event alert rules service.
 *
 * Manages user-defined alert rules that trigger when event metrics
 * exceed configured thresholds. Supports rate-based alerts (events/minute),
 * count-based alerts (total events in a window), and anomaly-based
 * alerts (statistical deviation from baseline).
 *
 * Each rule has its own cooldown period to prevent alert fatigue.
 * Alert dispatch is configurable per rule — events can be dispatched
 * as analytics events, logged, or sent via webhook.
 *
 * @see \ZeroBoiler\Analytics\Services\AnomalyDetectionService
 */
final class EventAlertRulesService
{
    /** @var array<string, mixed> Alert rules keyed by rule name */
    private array $rules = [];

    /** @var array<string, int> Rule name → last triggered timestamp (cooldown tracking) */
    private array $lastTriggered = [];

    /** @var list<array{rule: string, event: string, severity: string, message: string, triggered_at: string, value: float|null, threshold: float|null}> */
    private array $alertHistory = [];

    private AnalyticsManager $manager;

    private AnalyticsMetrics $metrics;

    private QueuedAnalyticsDispatcher $queue;

    private CacheRepository $cache;

    private int $maxHistorySize;

    private int $defaultCooldownSeconds;

    private bool $enabled;

    /**
     * @param  AnalyticsManager  $manager
     * @param  AnalyticsMetrics  $metrics
     * @param  QueuedAnalyticsDispatcher  $queue
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        AnalyticsMetrics $metrics,
        QueuedAnalyticsDispatcher $queue,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->metrics = $metrics;
        $this->queue = $queue;
        $this->cache = $cache;

        $alertConfig = $config->get('zeroboiler.analytics.alerts', []);
        /** @var array{enabled?: bool, cooldown?: int, max_history?: int, rules?: array<string, mixed>} $alertConfig */

        $this->enabled = (bool) ($alertConfig['enabled'] ?? true);
        $this->defaultCooldownSeconds = (int) ($alertConfig['cooldown'] ?? 300);
        $this->maxHistorySize = (int) ($alertConfig['max_history'] ?? 200);
        $this->rules = (array) ($alertConfig['rules'] ?? []);

        $this->loadCooldownState();
    }

    /**
     * Evaluate all rules against current metrics.
     *
     * Called periodically (e.g. via scheduled command) to check if any
     * rule conditions are met. Respects cooldown periods.
     *
     * @return list<array{rule: string, event: string, severity: string, message: string, triggered_at: string, value: float|null, threshold: float|null}>
     */
    public function evaluate(): array
    {
        if (! $this->enabled) {
            return [];
        }

        $triggeredAlerts = [];
        $now = time();

        foreach ($this->rules as $ruleName => $rule) {
            /** @var array{type?: string, event?: string, condition?: string, threshold?: float|int, window?: int, cooldown?: int, severity?: string, message?: string, enabled?: bool, dispatch?: bool} $rule */

            if (($rule['enabled'] ?? true) === false) {
                continue;
            }

            if (! $this->isCooldownExpired($ruleName, $now, (int) ($rule['cooldown'] ?? $this->defaultCooldownSeconds))) {
                continue;
            }

            $alert = $this->evaluateRule($ruleName, $rule, $now);

            if ($alert !== null) {
                $triggeredAlerts[] = $alert;
                $this->lastTriggered[$ruleName] = $now;
                $this->recordAlert($alert);

                if (($rule['dispatch'] ?? true) === true) {
                    $this->dispatchAlert($alert, $rule);
                }
            }
        }

        $this->persistCooldownState();

        return $triggeredAlerts;
    }

    /**
     * Evaluate a single named rule against current metrics.
     *
     * @param  string  $ruleName
     * @return array{rule: string, event: string, severity: string, message: string, triggered_at: string, value: float|null, threshold: float|null}|null
     */
    public function evaluateRuleByName(string $ruleName): ?array
    {
        if (! isset($this->rules[$ruleName])) {
            return null;
        }

        $rule = $this->rules[$ruleName];
        /** @var array{enabled?: bool} $rule */

        if (($rule['enabled'] ?? true) === false) {
            return null;
        }

        return $this->evaluateRule($ruleName, $rule, time());
    }

    /**
     * Add a rule at runtime (not persisted to config).
     *
     * @param  string  $ruleName
     * @param  array{type: string, event: string, condition: string, threshold: float|int, window?: int, cooldown?: int, severity?: string, message?: string, dispatch?: bool}  $ruleConfig
     */
    public function addRule(string $ruleName, array $ruleConfig): void
    {
        $this->rules[$ruleName] = $ruleConfig;
    }

    /**
     * Remove a rule at runtime.
     */
    public function removeRule(string $ruleName): void
    {
        unset($this->rules[$ruleName]);
        unset($this->lastTriggered[$ruleName]);
    }

    /**
     * Get all configured rule names.
     *
     * @return list<string>
     */
    public function ruleNames(): array
    {
        return array_keys($this->rules);
    }

    /**
     * Get a specific rule configuration.
     *
     * @return array<string, mixed>|null
     */
    public function getRule(string $ruleName): ?array
    {
        return $this->rules[$ruleName] ?? null;
    }

    /**
     * Get all alert history.
     *
     * @param  int  $limit
     * @return list<array{rule: string, event: string, severity: string, message: string, triggered_at: string, value: float|null, threshold: float|null}>
     */
    public function getAlertHistory(int $limit = 50): array
    {
        return array_slice($this->alertHistory, -$limit);
    }

    /**
     * Get summary of alert rule system.
     *
     * @return array{enabled: bool, rules_count: int, active_rules: int, total_alerts: int, cooldown_seconds: int, rule_names: list<string>}
     */
    public function summary(): array
    {
        $activeCount = 0;

        foreach ($this->rules as $rule) {
            if (($rule['enabled'] ?? true) === true) {
                $activeCount++;
            }
        }

        return [
            'enabled' => $this->enabled,
            'rules_count' => count($this->rules),
            'active_rules' => $activeCount,
            'total_alerts' => count($this->alertHistory),
            'cooldown_seconds' => $this->defaultCooldownSeconds,
            'rule_names' => array_keys($this->rules),
        ];
    }

    /**
     * Check if any rule is currently in cooldown.
     */
    public function hasCooldowns(): bool
    {
        $now = time();

        foreach ($this->lastTriggered as $ruleName => $timestamp) {
            $cooldown = (int) ($this->rules[$ruleName]['cooldown'] ?? $this->defaultCooldownSeconds);

            if ($now - $timestamp < $cooldown) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reset cooldown for a specific rule (force re-evaluation).
     */
    public function resetCooldown(string $ruleName): void
    {
        unset($this->lastTriggered[$ruleName]);
        $this->persistCooldownState();
    }

    /**
     * Clear all alert history and cooldowns.
     */
    public function flush(): void
    {
        $this->alertHistory = [];
        $this->lastTriggered = [];
        $this->persistCooldownState();
    }

    /**
     * Enable or disable the alert rules system.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Evaluate a single rule against current metrics.
     *
     * @param  string  $ruleName
     * @param  array{type: string, event?: string, condition: string, threshold: float|int, window?: int, severity?: string, message?: string}  $rule
     * @param  int  $now
     * @return array{rule: string, event: string, severity: string, message: string, triggered_at: string, value: float|null, threshold: float|null}|null
     */
    private function evaluateRule(string $ruleName, array $rule, int $now): ?array
    {
        $type = (string) ($rule['type'] ?? 'count');
        $eventName = (string) ($rule['event'] ?? '*');
        $condition = (string) ($rule['condition'] ?? 'gt');
        $threshold = (float) ($rule['threshold'] ?? 0);
        $severity = (string) ($rule['severity'] ?? 'warning');
        $message = (string) ($rule['message'] ?? "Rule '{$ruleName}' triggered for event '{$eventName}'");
        $window = (int) ($rule['window'] ?? 60);

        $value = match ($type) {
            'rate' => $this->getEventRate($eventName, $window),
            'count' => $this->getEventCount($eventName),
            'total' => $this->getTotalEventCount($eventName),
            'error_rate' => $this->getErrorRate($window),
            default => null,
        };

        if ($value === null) {
            return null;
        }

        $triggered = match ($condition) {
            'gt' => $value > $threshold,
            'gte' => $value >= $threshold,
            'lt' => $value < $threshold,
            'lte' => $value <= $threshold,
            'eq' => abs($value - $threshold) < 0.001,
            default => false,
        };

        if (! $triggered) {
            return null;
        }

        return [
            'rule' => $ruleName,
            'event' => $eventName,
            'severity' => $severity,
            'message' => $message,
            'triggered_at' => date('c', $now),
            'value' => round($value, 4),
            'threshold' => $threshold,
        ];
    }

    /**
     * Get the dispatch rate for an event (events per minute in the given window).
     */
    private function getEventRate(string $eventName, int $windowMinutes): float
    {
        $counts = $this->metrics->getCounts();

        if ($eventName === '*') {
            $total = array_sum($counts);
        } else {
            $total = $counts[$eventName] ?? 0;
        }

        $windowSeconds = $windowMinutes * 60;

        return $windowSeconds > 0 ? round($total / ($windowSeconds / 60), 4) : 0.0;
    }

    /**
     * Get the current count for a specific event.
     */
    private function getEventCount(string $eventName): float
    {
        $counts = $this->metrics->getCounts();

        if ($eventName === '*') {
            return (float) array_sum($counts);
        }

        return (float) ($counts[$eventName] ?? 0);
    }

    /**
     * Get the total dispatched event count for an event or all events.
     */
    private function getTotalEventCount(string $eventName): float
    {
        $totalDispatched = $this->metrics->totalDispatched();

        if ($eventName === '*') {
            return (float) $totalDispatched;
        }

        return (float) $totalDispatched;
    }

    /**
     * Get the error rate (errors / total events) in the given window.
     */
    private function getErrorRate(int $windowMinutes): float
    {
        $counts = $this->metrics->getCounts();
        $errorCount = $counts['error'] ?? $counts['js_error'] ?? 0;
        $total = array_sum($counts);

        if ($total === 0) {
            return 0.0;
        }

        return round(($errorCount / $total) * 100, 4);
    }

    /**
     * Check if cooldown has expired for a rule.
     */
    private function isCooldownExpired(string $ruleName, int $now, int $cooldownSeconds): bool
    {
        $lastTriggered = $this->lastTriggered[$ruleName] ?? 0;

        return ($now - $lastTriggered) >= $cooldownSeconds;
    }

    /**
     * Record an alert in history.
     *
     * @param  array{rule: string, event: string, severity: string, message: string, triggered_at: string, value: float|null, threshold: float|null}  $alert
     */
    private function recordAlert(array $alert): void
    {
        $this->alertHistory[] = $alert;

        if (count($this->alertHistory) > $this->maxHistorySize) {
            $this->alertHistory = array_slice($this->alertHistory, -$this->maxHistorySize);
        }
    }

    /**
     * Dispatch an alert event.
     *
     * @param  array{rule: string, event: string, severity: string, message: string, triggered_at: string, value: float|null, threshold: float|null}  $alert
     * @param  array<string, mixed>  $rule
     */
    private function dispatchAlert(array $alert, array $rule): void
    {
        try {
            $event = new AnalyticsEvent(
                name: 'analytics_alert_triggered',
                params: [
                    'alert_rule' => $alert['rule'],
                    'alert_event' => $alert['event'],
                    'alert_severity' => $alert['severity'],
                    'alert_message' => $alert['message'],
                    'alert_value' => $alert['value'],
                    'alert_threshold' => $alert['threshold'],
                ],
            );

            $this->queue->dispatch($event);
        } catch (\Throwable $e) {
            try {
                Log::warning('EventAlertRulesService: failed to dispatch alert', [
                    'error' => $e->getMessage(),
                    'alert' => $alert,
                ]);
            } catch (\Throwable) {
                // Log may not be available
            }
        }
    }

    /**
     * Load cooldown state from cache.
     */
    private function loadCooldownState(): void
    {
        try {
            $cached = $this->cache->get('zeroboiler.analytics.alert_cooldowns', []);

            if (is_array($cached)) {
                $this->lastTriggered = $cached;
            }
        } catch (\Throwable) {
            $this->lastTriggered = [];
        }
    }

    /**
     * Persist cooldown state to cache.
     */
    private function persistCooldownState(): void
    {
        try {
            $this->cache->put(
                'zeroboiler.analytics.alert_cooldowns',
                $this->lastTriggered,
                $this->defaultCooldownSeconds + 60,
            );
        } catch (\Throwable) {
            // Cache may not be available
        }
    }
}
