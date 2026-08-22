<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Config-driven event sequence rule engine for SaaS analytics.
 *
 * Defines expected event sequences (e.g., "sign_up → start_trial → subscribe")
 * and detects anomalies when real user behavior deviates from the expected path.
 * Used for funnel health monitoring, onboarding drop-off detection, and
 * conversion path analysis.
 *
 * Rules are defined in `zeroboiler.analytics.sequence_rules.rules` and can be:
 * - **Expected sequences**: A must be followed by B within X seconds
 * - **Prohibited sequences**: A must NOT be followed by B within X seconds
 * - **Conversion gates**: A → B → C with min/max duration between steps
 * - **Rate limits**: Event X must not fire more than N times per session
 *
 * The engine maintains a sliding window of recent events per client/user
 * in cache, evaluates rules on each incoming event, and emits
 * rule violation alerts via the AnalyticsMetrics system.
 *
 * Inspired by Segment Protocols, Amplitude Compass, and Mixpanel Signal.
 *
 * Configuration:
 * ```php
 * 'sequence_rules' => [
 *     'enabled' => true,
 *     'rules' => [
 *         [
 *             'name' => 'trial_to_subscribe',
 *             'type' => 'expected',
 *             'from' => 'start_trial',
 *             'to' => 'subscribe',
 *             'window_seconds' => 86400, // 24 hours
 *         ],
 *         [
 *             'name' => 'signup_without_trial',
 *             'type' => 'prohibited',
 *             'from' => 'sign_up',
 *             'to' => 'subscribe',
 *             'window_seconds' => 86400,
 *             'unless' => ['start_trial'],
 *         ],
 *         [
 *             'name' => 'checkout_velocity',
 *             'type' => 'rate_limit',
 *             'event' => 'begin_checkout',
 *             'max_per_session' => 3,
 *         ],
 *     ],
 * ],
 * ```
 *
 * @since 261.0.0
 */
final class EventSequenceRuleEngine
{
    /** Rule type constants */
    private const TYPE_EXPECTED = 'expected';

    private const TYPE_PROHIBITED = 'prohibited';

    private const TYPE_RATE_LIMIT = 'rate_limit';

    private const TYPE_CONVERSION_GATE = 'conversion_gate';

    /** Valid rule types */
    private const VALID_TYPES = [
        self::TYPE_EXPECTED,
        self::TYPE_PROHIBITED,
        self::TYPE_RATE_LIMIT,
        self::TYPE_CONVERSION_GATE,
    ];

    /** Default sliding window size for event history (seconds) */
    private const DEFAULT_HISTORY_TTL = 86400;

    /** Max events stored per identity in the sliding window */
    private const MAX_HISTORY_PER_IDENTITY = 500;

    /** Cache key prefix for event history */
    private const CACHE_PREFIX = 'zb_seq_history_';

    /** Cache key prefix for rate limit counters */
    private const RATE_LIMIT_PREFIX = 'zb_seq_rate_';

    /** Cache key for violation counts */
    private const VIOLATION_COUNT_PREFIX = 'zb_seq_violations_';

    private bool $enabled;

    private CacheRepository $cache;

    private int $historyTtl;

    /** @var list<array{name: string, type: string, from?: string, to?: string, event?: string, window_seconds?: int, max_per_session?: int, unless?: list<string>, steps?: list<array{event: string, min_seconds?: int, max_seconds?: int}>}>} */
    private array $rules;

    /** @var list<array{rule: string, identity: string, event: string, reason: string, detected_at: string}> */
    private array $recentViolations;

    private int $maxRecentViolations;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $seqConfig = $config->get('zeroboiler.analytics.sequence_rules', []);
        /** @var array{enabled?: bool, history_ttl?: int, max_recent_violations?: int, rules?: list<array{name: string, type: string, from?: string, to?: string, event?: string, window_seconds?: int, max_per_session?: int, unless?: list<string>, steps?: list<array{event: string, min_seconds?: int, max_seconds?: int}>}>} $seqConfig */

        $this->cache = $cache;
        $this->enabled = (bool) ($seqConfig['enabled'] ?? false);
        $this->historyTtl = (int) ($seqConfig['history_ttl'] ?? self::DEFAULT_HISTORY_TTL);
        $this->maxRecentViolations = (int) ($seqConfig['max_recent_violations'] ?? 100);
        $this->rules = $this->normalizeRules($seqConfig['rules'] ?? []);
        $this->recentViolations = [];
    }

    /**
     * Evaluate all applicable rules against an incoming event.
     *
     * Called from the event pipeline after enrichment.
     * Records the event in the sliding window history, then checks
     * each rule for violations.
     *
     * @param  AnalyticsEvent  $event  The incoming analytics event
     * @return list<array{rule: string, type: string, severity: string, reason: string}>
     */
    public function evaluate(AnalyticsEvent $event): array
    {
        if (! $this->enabled || $this->rules === []) {
            return [];
        }

        $identity = $this->resolveIdentity($event);

        if ($identity === '') {
            return [];
        }

        $this->recordEvent($identity, $event);

        $violations = [];

        foreach ($this->rules as $rule) {
            $violation = $this->evaluateRule($rule, $identity, $event);

            if ($violation !== null) {
                $violations[] = $violation;
                $this->recordViolation($rule['name'], $identity, $event->name, $violation['reason']);
            }
        }

        return $violations;
    }

    /**
     * Get all registered rules.
     *
     * @return list<array{name: string, type: string}>
     */
    public function getRules(): array
    {
        return array_map(
            fn (array $rule): array => ['name' => $rule['name'], 'type' => $rule['type']],
            $this->rules,
        );
    }

    /**
     * Get the number of registered rules.
     */
    public function getRuleCount(): int
    {
        return count($this->rules);
    }

    /**
     * Check if the sequence rule engine is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get recent violations detected by the engine.
     *
     * @return list<array{rule: string, identity: string, event: string, reason: string, detected_at: string}>
     */
    public function getRecentViolations(): array
    {
        return $this->recentViolations;
    }

    /**
     * Get violation counts for each rule from cache.
     *
     * @return array<string, int>
     */
    public function getViolationCounts(): array
    {
        $counts = [];

        foreach ($this->rules as $rule) {
            $cacheKey = self::VIOLATION_COUNT_PREFIX . $rule['name'];
            $cached = $this->cache->get($cacheKey);

            $counts[$rule['name']] = is_int($cached) ? $cached : 0;
        }

        return $counts;
    }

    /**
     * Get the event history for an identity.
     *
     * @param  string  $identity  Client ID or user ID
     * @return list<array{event: string, timestamp: int, category: string|null}>
     */
    public function getHistory(string $identity): array
    {
        $cacheKey = self::CACHE_PREFIX . $identity;
        $cached = $this->cache->get($cacheKey);

        /** @var list<array{event: string, timestamp: int, category: string|null}>|null $cached */
        return is_array($cached) ? $cached : [];
    }

    /**
     * Clear event history for an identity.
     */
    public function clearHistory(string $identity): void
    {
        $this->cache->forget(self::CACHE_PREFIX . $identity);
    }

    /**
     * Add a rule at runtime.
     *
     * @param  array{name: string, type: string, from?: string, to?: string, event?: string, window_seconds?: int, max_per_session?: int, unless?: list<string>, steps?: list<array{event: string, min_seconds?: int, max_seconds?: int}>}  $rule
     */
    public function addRule(array $rule): void
    {
        $normalized = $this->normalizeRule($rule);

        if ($normalized !== null) {
            $this->rules[] = $normalized;
        }
    }

    /**
     * Remove a rule by name.
     */
    public function removeRule(string $name): void
    {
        $this->rules = array_values(
            array_filter($this->rules, fn (array $rule): bool => $rule['name'] !== $name),
        );
    }

    /**
     * Get rule summary for diagnostics.
     *
     * @return array{enabled: bool, rule_count: int, rules_by_type: array<string, int>, history_ttl: int, violation_counts: array<string, int>}
     */
    public function getSummary(): array
    {
        $rulesByType = [];

        foreach ($this->rules as $rule) {
            $type = $rule['type'];
            $rulesByType[$type] = ($rulesByType[$type] ?? 0) + 1;
        }

        return [
            'enabled' => $this->enabled,
            'rule_count' => count($this->rules),
            'rules_by_type' => $rulesByType,
            'history_ttl' => $this->historyTtl,
            'violation_counts' => $this->getViolationCounts(),
        ];
    }

    /**
     * Evaluate a single rule against the event.
     *
     * @param  array{name: string, type: string, from?: string, to?: string, event?: string, window_seconds?: int, max_per_session?: int, unless?: list<string>, steps?: list<array{event: string, min_seconds?: int, max_seconds?: int}>}  $rule
     * @param  string  $identity
     * @return  array{rule: string, type: string, severity: string, reason: string}|null
     */
    private function evaluateRule(array $rule, string $identity, AnalyticsEvent $event): ?array
    {
        return match ($rule['type']) {
            self::TYPE_EXPECTED => $this->evaluateExpectedRule($rule, $identity, $event),
            self::TYPE_PROHIBITED => $this->evaluateProhibitedRule($rule, $identity, $event),
            self::TYPE_RATE_LIMIT => $this->evaluateRateLimitRule($rule, $identity, $event),
            self::TYPE_CONVERSION_GATE => $this->evaluateConversionGateRule($rule, $identity, $event),
            default => null,
        };
    }

    /**
     * Evaluate an "expected" sequence rule.
     *
     * Checks if `from` event was seen within the window and `to` event
     * has NOT yet occurred. Violation means the window expired without
     * the expected follow-up event.
     *
     * @param  array{from: string, to: string, window_seconds: int, name: string}  $rule
     * @return  array{rule: string, type: string, severity: string, reason: string}|null
     */
    private function evaluateExpectedRule(array $rule, string $identity, AnalyticsEvent $event): ?array
    {
        // Only evaluate when the 'from' event fires (to check if 'to' already happened)
        // or when the 'to' event fires (to mark as completed)
        if ($event->name !== $rule['from'] && $event->name !== $rule['to']) {
            return null;
        }

        // If the 'to' event just fired, mark as completed (no violation)
        if ($event->name === $rule['to']) {
            return null;
        }

        // 'from' event just fired — check if 'to' already happened recently
        $history = $this->getHistory($identity);
        $windowSeconds = $rule['window_seconds'] ?? 3600;
        $cutoff = time() - $windowSeconds;

        foreach ($history as $pastEvent) {
            if (
                $pastEvent['event'] === $rule['to']
                && $pastEvent['timestamp'] >= $cutoff
            ) {
                return null; // Already completed
            }
        }

        // 'from' fired but 'to' not yet seen — this is an expected sequence pending.
        // We don't flag a violation yet; violations are detected when the window expires.
        // Instead, we set a cache key that can be checked by a scheduled job.
        $pendingKey = self::CACHE_PREFIX . 'pending_' . $rule['name'] . '_' . $identity;
        $this->cache->put($pendingKey, time(), $windowSeconds);

        return null;
    }

    /**
     * Evaluate a "prohibited" sequence rule.
     *
     * Checks if `from` event occurred within the window WITHOUT any of the
     * `unless` events in between, and now `to` event is firing.
     *
     * @param  array{from: string, to: string, window_seconds: int, unless: list<string>, name: string}  $rule
     * @return  array{rule: string, type: string, severity: string, reason: string}|null
     */
    private function evaluateProhibitedRule(array $rule, string $identity, AnalyticsEvent $event): ?array
    {
        if ($event->name !== $rule['to']) {
            return null;
        }

        $history = $this->getHistory($identity);
        $windowSeconds = $rule['window_seconds'] ?? 3600;
        $cutoff = time() - $windowSeconds;
        $unlessEvents = $rule['unless'] ?? [];

        $fromFound = false;
        $fromTimestamp = 0;

        foreach ($history as $pastEvent) {
            if (
                $pastEvent['event'] === $rule['from']
                && $pastEvent['timestamp'] >= $cutoff
                && $pastEvent['timestamp'] > $fromTimestamp
            ) {
                $fromFound = true;
                $fromTimestamp = $pastEvent['timestamp'];
            }
        }

        if (! $fromFound) {
            return null;
        }

        // Check if any 'unless' event occurred between 'from' and now
        foreach ($history as $pastEvent) {
            if (
                $pastEvent['timestamp'] >= $fromTimestamp
                && $pastEvent['timestamp'] < time()
                && in_array($pastEvent['event'], $unlessEvents, true)
            ) {
                return null; // Allowed because 'unless' event intervened
            }
        }

        // Prohibited sequence detected
        return [
            'rule' => $rule['name'],
            'type' => 'prohibited_sequence',
            'severity' => 'warning',
            'reason' => sprintf(
                'Prohibited sequence detected: %s → %s within %ds (expected one of: %s)',
                $rule['from'],
                $rule['to'],
                $windowSeconds,
                implode(', ', $unlessEvents) ?: 'none',
            ),
        ];
    }

    /**
     * Evaluate a "rate_limit" rule.
     *
     * Checks if an event has fired more than N times per session/identity.
     *
     * @param  array{event: string, max_per_session: int, name: string}  $rule
     * @return  array{rule: string, type: string, severity: string, reason: string}|null
     */
    private function evaluateRateLimitRule(array $rule, string $identity, AnalyticsEvent $event): ?array
    {
        if ($event->name !== $rule['event']) {
            return null;
        }

        $cacheKey = self::RATE_LIMIT_PREFIX . $rule['name'] . '_' . $identity;
        $currentCount = $this->cache->get($cacheKey);
        $count = is_int($currentCount) ? $currentCount : 0;

        if ($count >= $rule['max_per_session']) {
            return [
                'rule' => $rule['name'],
                'type' => 'rate_limit',
                'severity' => 'warning',
                'reason' => sprintf(
                    'Event "%s" exceeded rate limit (%d/%d) for identity %s',
                    $rule['event'],
                    $count + 1,
                    $rule['max_per_session'],
                    $this->maskIdentity($identity),
                ),
            ];
        }

        return null;
    }

    /**
     * Evaluate a "conversion_gate" rule.
     *
     * Multi-step sequence with min/max duration constraints.
     * E.g., sign_up → (0-300s) → start_trial → (0-86400s) → subscribe.
     *
     * @param  array{name: string, steps: list<array{event: string, min_seconds?: int, max_seconds?: int}>}  $rule
     * @return  array{rule: string, type: string, severity: string, reason: string}|null
     */
    private function evaluateConversionGateRule(array $rule, string $identity, AnalyticsEvent $event): ?array
    {
        $steps = $rule['steps'] ?? [];

        if (count($steps) < 2) {
            return null;
        }

        $stepIndex = null;

        foreach ($steps as $i => $step) {
            if ($step['event'] === $event->name) {
                $stepIndex = $i;

                break;
            }
        }

        if ($stepIndex === null) {
            return null;
        }

        // Not the final step — just record and continue
        if ($stepIndex < count($steps) - 1) {
            return null;
        }

        // Final step fired — validate the full sequence
        $history = $this->getHistory($identity);
        $stepTimestamps = $this->findStepTimestamps($steps, $history);

        if ($stepTimestamps === null) {
            return null; // Not all steps found — not a complete sequence
        }

        $violations = [];

        for ($i = 1; $i < count($steps); $i++) {
            $elapsed = $stepTimestamps[$i] - $stepTimestamps[$i - 1];
            $minSeconds = $steps[$i]['min_seconds'] ?? 0;
            $maxSeconds = $steps[$i]['max_seconds'] ?? PHP_INT_MAX;

            if ($elapsed < $minSeconds) {
                $violations[] = sprintf(
                    'Step %d→%d (%s→%s): %ds is below minimum %ds',
                    $i, $i + 1,
                    $steps[$i - 1]['event'], $steps[$i]['event'],
                    $elapsed, $minSeconds,
                );
            }

            if ($elapsed > $maxSeconds) {
                $violations[] = sprintf(
                    'Step %d→%d (%s→%s): %ds exceeds maximum %ds',
                    $i, $i + 1,
                    $steps[$i - 1]['event'], $steps[$i]['event'],
                    $elapsed, $maxSeconds,
                );
            }
        }

        if ($violations === []) {
            return null; // All timing constraints met
        }

        return [
            'rule' => $rule['name'],
            'type' => 'conversion_gate_timing',
            'severity' => 'info',
            'reason' => sprintf(
                'Conversion gate timing anomaly: %s',
                implode('; ', $violations),
            ),
        ];
    }

    /**
     * Find timestamps for each step in the event history.
     *
     * @param  list<array{event: string, min_seconds?: int, max_seconds?: int}>  $steps
     * @param  list<array{event: string, timestamp: int, category: string|null}>  $history
     * @return  list<int>|null  Timestamp for each step, or null if incomplete
     */
    private function findStepTimestamps(array $steps, array $history): ?array
    {
        $timestamps = [];

        foreach ($steps as $step) {
            $latestTimestamp = null;

            foreach ($history as $pastEvent) {
                if ($pastEvent['event'] === $step['event']) {
                    if (
                        $latestTimestamp === null
                        || $pastEvent['timestamp'] > $latestTimestamp
                    ) {
                        $latestTimestamp = $pastEvent['timestamp'];
                    }
                }
            }

            if ($latestTimestamp === null) {
                return null; // Step not found in history
            }

            $timestamps[] = $latestTimestamp;
        }

        return $timestamps;
    }

    /**
     * Record an event in the sliding window history for an identity.
     */
    private function recordEvent(string $identity, AnalyticsEvent $event): void
    {
        $cacheKey = self::CACHE_PREFIX . $identity;
        $history = $this->getHistory($identity);

        $history[] = [
            'event' => $event->name,
            'timestamp' => time(),
            'category' => $event->category,
        ];

        // Trim to max size and remove expired entries
        $cutoff = time() - $this->historyTtl;
        $history = array_filter(
            $history,
            fn (array $e): bool => $e['timestamp'] >= $cutoff,
        );
        $history = array_values($history);

        if (count($history) > self::MAX_HISTORY_PER_IDENTITY) {
            $history = array_slice($history, -self::MAX_HISTORY_PER_IDENTITY);
        }

        foreach ($this->rules as $rule) {
            if ($rule['type'] === self::TYPE_RATE_LIMIT && $rule['event'] === $event->name) {
                $rateKey = self::RATE_LIMIT_PREFIX . $rule['name'] . '_' . $identity;
                $current = $this->cache->get($rateKey);
                $count = is_int($current) ? $current + 1 : 1;
                $this->cache->put($rateKey, $count, $this->historyTtl);
            }
        }

        $this->cache->put($cacheKey, $history, $this->historyTtl);
    }

    /**
     * Record a rule violation in the in-memory buffer and cache counter.
     */
    private function recordViolation(string $ruleName, string $identity, string $eventName, string $reason): void
    {
        // In-memory buffer
        $this->recentViolations[] = [
            'rule' => $ruleName,
            'identity' => $this->maskIdentity($identity),
            'event' => $eventName,
            'reason' => $reason,
            'detected_at' => now()->toIso8601String(),
        ];

        // Trim in-memory buffer
        if (count($this->recentViolations) > $this->maxRecentViolations) {
            $this->recentViolations = array_slice(
                $this->recentViolations,
                -$this->maxRecentViolations,
            );
        }

        // Cache counter
        $countKey = self::VIOLATION_COUNT_PREFIX . $ruleName;
        $current = $this->cache->get($countKey);
        $count = is_int($current) ? $current + 1 : 1;
        $this->cache->put($countKey, $count, $this->historyTtl);
    }

    /**
     * Resolve the identity (client ID or user ID) from an event.
     */
    private function resolveIdentity(AnalyticsEvent $event): string
    {
        if ($event->userId !== null && $event->userId !== '') {
            return 'user:' . $event->userId;
        }

        if ($event->clientId !== null && $event->clientId !== '') {
            return 'client:' . $event->clientId;
        }

        return '';
    }

    /**
     * Mask identity for safe logging (first 8 chars + ellipsis).
     */
    private function maskIdentity(string $identity): string
    {
        if (strlen($identity) <= 12) {
            return $identity;
        }

        return substr($identity, 0, 12) . '…';
    }

    /**
     * Normalize a list of rules from config.
     *
     * @param  list<array>  $rules
     * @return  list<array{name: string, type: string, from?: string, to?: string, event?: string, window_seconds?: int, max_per_session?: int, unless?: list<string>, steps?: list<array{event: string, min_seconds?: int, max_seconds?: int}>}>
     */
    private function normalizeRules(array $rules): array
    {
        $normalized = [];

        foreach ($rules as $rule) {
            $result = $this->normalizeRule($rule);

            if ($result !== null) {
                $normalized[] = $result;
            }
        }

        return $normalized;
    }

    /**
     * Normalize and validate a single rule.
     *
     * @param  array  $rule
     * @return  array{name: string, type: string, from?: string, to?: string, event?: string, window_seconds?: int, max_per_session?: int, unless?: list<string>, steps?: list<array{event: string, min_seconds?: int, max_seconds?: int}>}|null
     */
    private function normalizeRule(array $rule): ?array
    {
        $name = $rule['name'] ?? '';
        $type = $rule['type'] ?? '';

        if ($name === '' || $type === '') {
            return null;
        }

        if (! in_array($type, self::VALID_TYPES, true)) {
            return null;
        }

        $normalized = [
            'name' => (string) $name,
            'type' => (string) $type,
        ];

        return match ($type) {
            self::TYPE_EXPECTED => $this->normalizeSequenceRule($rule, $normalized),
            self::TYPE_PROHIBITED => $this->normalizeSequenceRule($rule, $normalized),
            self::TYPE_RATE_LIMIT => $this->normalizeRateLimitRule($rule, $normalized),
            self::TYPE_CONVERSION_GATE => $this->normalizeConversionGateRule($rule, $normalized),
            default => null,
        };
    }
    /**
     * Normalize a sequence rule (expected or prohibited).
     *
     * @param  array  $rule
     * @param  array{name: string, type: string}  $normalized
     * @return  array{name: string, type: string, from?: string, to?: string, window_seconds?: int, unless?: list<string>}|null
     */
    private function normalizeSequenceRule(array $rule, array $normalized): ?array
    {
        $from = $rule['from'] ?? null;
        $to = $rule['to'] ?? null;

        if (! is_string($from) || $from === '' || ! is_string($to) || $to === '') {
            return null;
        }

        $normalized['from'] = $from;
        $normalized['to'] = $to;
        $normalized['window_seconds'] = (int) ($rule['window_seconds'] ?? 3600);

        if (isset($rule['unless']) && is_array($rule['unless'])) {
            /** @var list<string> $unless */
            $normalized['unless'] = $rule['unless'];
        }

        return $normalized;
    }

    /**
     * Normalize a rate limit rule.
     *
     * @param  array  $rule
     * @param  array{name: string, type: string}  $normalized
     * @return  array{name: string, type: string, event: string, max_per_session: int}|null
     */
    private function normalizeRateLimitRule(array $rule, array $normalized): ?array
    {
        $event = $rule['event'] ?? null;

        if (! is_string($event) || $event === '') {
            return null;
        }

        $normalized['event'] = $event;
        $normalized['max_per_session'] = (int) ($rule['max_per_session'] ?? 10);

        return $normalized;
    }

    /**
     * Normalize a conversion gate rule.
     *
     * @param  array  $rule
     * @param  array{name: string, type: string}  $normalized
     * @return  array{name: string, type: string, steps: list<array{event: string, min_seconds?: int, max_seconds?: int}>}|null
     */
    private function normalizeConversionGateRule(array $rule, array $normalized): ?array
    {
        $steps = $rule['steps'] ?? [];

        if (! is_array($steps) || count($steps) < 2) {
            return null;
        }

        $normalizedSteps = [];

        foreach ($steps as $step) {
            $event = $step['event'] ?? null;

            if (! is_string($event) || $event === '') {
                return null;
            }

            $normalizedStep = ['event' => $event];

            if (isset($step['min_seconds'])) {
                $normalizedStep['min_seconds'] = (int) $step['min_seconds'];
            }

            if (isset($step['max_seconds'])) {
                $normalizedStep['max_seconds'] = (int) $step['max_seconds'];
            }

            $normalizedSteps[] = $normalizedStep;
        }

        $normalized['steps'] = $normalizedSteps;

        return $normalized;
    }
}
