<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\PolicyViolation;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event governance policy engine — declarative compliance policy rules for analytics events.
 *
 * Evaluates dispatched events against configurable governance policies that enforce
 * data quality, privacy compliance, and operational standards. Policies are defined
 * in `zeroboiler.analytics.governance_policies` as declarative rules.
 *
 * Policy types:
 * - **max_params**: Enforce maximum number of event parameters
 * - **max_value_length**: Enforce maximum string length for parameter values
 * - **disallowed_params**: Block events containing specific parameter keys
 * - **required_params**: Block events missing required parameter keys
 * - **allowed_events**: Only allow whitelisted event names
 * - **blocked_events**: Block specific event names
 * - **allowed_categories**: Only allow events from specific categories
 * - **pii_detection**: Auto-detect and sanitize PII in event payloads
 * - **rate_limit_per_event**: Enforce per-event dispatch rate limits
 * - **category_rate_limit**: Enforce per-category dispatch rate limits
 *
 * Each policy has an action: `block` (reject), `warn` (log + proceed),
 * `sanitize` (remove violating data), or `transform` (modify data).
 *
 * Inspired by Datadog's Governance API, Snowflake's Data Governance,
 * and Monte Carlo's data quality rules.
 *
 * Config: `zeroboiler.analytics.governance_policies`
 *
 * @since 84.0.0
 *
 * @see \ZeroBoiler\Analytics\DTO\PolicyViolation
 * @see \ZeroBoiler\Analytics\Services\EventGovernanceService
 */
final class EventPolicyEngine
{
    private const CACHE_PREFIX = 'zb_policy_engine_';
    private const VIOLATION_LOG_KEY = 'zb_policy_violations';
    private const RATE_LIMIT_PREFIX = 'zb_policy_rate_';

    private readonly bool $enabled;
    private readonly string $defaultAction;
    private readonly int $maxViolationHistory;
    private readonly int $cacheTtl;
    private readonly bool $logViolations;

    /** @var array<string, array{type: string, action: string, config: array<string, mixed>, severity: string, description: string|null}> */
    private readonly array $policies;

    /** @var array<string, string> Parameter patterns for PII detection */
    private readonly array $piiPatterns;

    private CacheRepository $cache;

    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $policyConfig = $config->get('zeroboiler.analytics.governance_policies', []);

        $this->enabled = (bool) ($policyConfig['enabled'] ?? true);
        $this->defaultAction = (string) ($policyConfig['default_action'] ?? 'warn');
        $this->maxViolationHistory = (int) ($policyConfig['max_violation_history'] ?? 500);
        $this->cacheTtl = (int) ($policyConfig['cache_ttl'] ?? 3600);
        $this->logViolations = (bool) ($policyConfig['log_violations'] ?? true);
        $this->policies = (array) ($policyConfig['rules'] ?? []);
        $this->piiPatterns = (array) ($policyConfig['pii_patterns'] ?? [
            'email', 'phone', 'ssn', 'credit_card', 'password', 'token',
            'secret', 'api_key', 'authorization', 'cookie', 'ip_address',
        ]);
    }

    /**
     * Evaluate an event against all configured governance policies.
     *
     * Returns the evaluated event (possibly sanitized/transformed) and
     * a list of any policy violations that occurred.
     *
     * @param  AnalyticsEvent  $event  The event to evaluate
     * @return array{event: AnalyticsEvent, violations: list<PolicyViolation>, blocked: bool}
     */
    public function evaluate(AnalyticsEvent $event): array
    {
        if (! $this->enabled) {
            return [
                'event' => $event,
                'violations' => [],
                'blocked' => false,
            ];
        }

        $violations = [];
        $params = $event->params;
        $blocked = false;
        $sanitizedParams = $params;

        foreach ($this->policies as $ruleId => $rule) {
            $violation = $this->evaluateRule($ruleId, $rule, $event, $sanitizedParams);

            if ($violation !== null) {
                $violations[] = $violation;

                if ($violation->isBlocked()) {
                    $blocked = true;
                    break; // Stop evaluating on first block
                }

                if ($violation->action === PolicyViolation::ACTION_SANITIZE) {
                    $sanitizedParams = $this->applySanitization($sanitizedParams, $rule);
                }
            }
        }

        // Apply sanitized params if changed (readonly DTO — create new instance)
        $finalEvent = $sanitizedParams !== $params
            ? new AnalyticsEvent(
                name: $event->name,
                params: $sanitizedParams,
                clientId: $event->clientId,
                userId: $event->userId,
                timestamp: $event->timestamp,
                priority: $event->priority,
                source: $event->source,
            )
            : $event;

        if (! empty($violations) && $this->logViolations) {
            $this->logViolations($violations);
        }

        return [
            'event' => $finalEvent,
            'violations' => $violations,
            'blocked' => $blocked,
        ];
    }

    /**
     * Evaluate a single policy rule against an event.
     *
     * @param  array{type: string, action: string, config: array<string, mixed>, severity: string, description: string|null}  $rule
     * @param  array<string, mixed>  $params  Current event parameters (may be sanitized)
     * @return PolicyViolation|null
     */
    private function evaluateRule(
        string $ruleId,
        array $rule,
        AnalyticsEvent $event,
        array $params,
    ): ?PolicyViolation {
        $type = $rule['type'] ?? '';
        $action = $rule['action'] ?? $this->defaultAction;
        $config = $rule['config'] ?? [];
        $severity = $rule['severity'] ?? PolicyViolation::SEVERITY_MEDIUM;
        $description = $rule['description'] ?? null;

        return match ($type) {
            'max_params' => $this->checkMaxParams($ruleId, $event, $params, $action, $severity, $config, $description),
            'max_value_length' => $this->checkMaxValueLength($ruleId, $event, $params, $action, $severity, $config, $description),
            'disallowed_params' => $this->checkDisallowedParams($ruleId, $event, $params, $action, $severity, $config, $description),
            'required_params' => $this->checkRequiredParams($ruleId, $event, $params, $action, $severity, $config, $description),
            'allowed_events' => $this->checkAllowedEvents($ruleId, $event, $action, $severity, $config, $description),
            'blocked_events' => $this->checkBlockedEvents($ruleId, $event, $action, $severity, $config, $description),
            'allowed_categories' => $this->checkAllowedCategories($ruleId, $event, $action, $severity, $config, $description),
            'pii_detection' => $this->checkPiiDetection($ruleId, $event, $params, $action, $severity, $description),
            'rate_limit_per_event' => $this->checkRateLimitPerEvent($ruleId, $event, $action, $severity, $config, $description),
            default => null,
        };
    }

    /**
     * Check max_params policy.
     */
    private function checkMaxParams(
        string $ruleId,
        AnalyticsEvent $event,
        array $params,
        string $action,
        string $severity,
        array $config,
        ?string $description,
    ): ?PolicyViolation {
        $max = (int) ($config['max'] ?? 50);

        if (count($params) > $max) {
            return new PolicyViolation(
                ruleId: $ruleId,
                eventName: $event->name(),
                action: $action,
                severity: $severity,
                reason: $description ?? "Event has {count} parameters, exceeds maximum of {max}",
                eventSnapshot: ['param_count' => count($params), 'max' => $max],
                context: ['policy_type' => 'max_params'],
                resolvedBy: 'EventPolicyEngine::checkMaxParams',
            );
        }

        return null;
    }

    /**
     * Check max_value_length policy.
     */
    private function checkMaxValueLength(
        string $ruleId,
        AnalyticsEvent $event,
        array $params,
        string $action,
        string $severity,
        array $config,
        ?string $description,
    ): ?PolicyViolation {
        $maxLength = (int) ($config['max_length'] ?? 500);
        $exceededKeys = [];

        foreach ($params as $key => $value) {
            if (is_string($value) && strlen($value) > $maxLength) {
                $exceededKeys[] = $key;
            }
        }

        if (! empty($exceededKeys)) {
            return new PolicyViolation(
                ruleId: $ruleId,
                eventName: $event->name(),
                action: $action,
                severity: $severity,
                reason: $description ?? "Parameter values exceed max length ({max_length} chars)",
                eventSnapshot: ['exceeded_keys' => $exceededKeys, 'max_length' => $maxLength],
                context: ['policy_type' => 'max_value_length'],
                resolvedBy: 'EventPolicyEngine::checkMaxValueLength',
            );
        }

        return null;
    }

    /**
     * Check disallowed_params policy.
     */
    private function checkDisallowedParams(
        string $ruleId,
        AnalyticsEvent $event,
        array $params,
        string $action,
        string $severity,
        array $config,
        ?string $description,
    ): ?PolicyViolation {
        $disallowed = (array) ($config['keys'] ?? []);
        $found = [];

        foreach ($disallowed as $key) {
            if (array_key_exists($key, $params)) {
                $found[] = $key;
            }
        }

        if (! empty($found)) {
            return new PolicyViolation(
                ruleId: $ruleId,
                eventName: $event->name(),
                action: $action,
                severity: PolicyViolation::SEVERITY_HIGH,
                reason: $description ?? "Event contains disallowed parameters: {keys}",
                eventSnapshot: ['disallowed_keys' => $found],
                context: ['policy_type' => 'disallowed_params'],
                resolvedBy: 'EventPolicyEngine::checkDisallowedParams',
            );
        }

        return null;
    }

    /**
     * Check required_params policy.
     */
    private function checkRequiredParams(
        string $ruleId,
        AnalyticsEvent $event,
        array $params,
        string $action,
        string $severity,
        array $config,
        ?string $description,
    ): ?PolicyViolation {
        $required = (array) ($config['keys'] ?? []);
        $missing = [];

        foreach ($required as $key) {
            if (! array_key_exists($key, $params)) {
                $missing[] = $key;
            }
        }

        if (! empty($missing)) {
            return new PolicyViolation(
                ruleId: $ruleId,
                eventName: $event->name(),
                action: $action,
                severity: $severity,
                reason: $description ?? "Event is missing required parameters: {keys}",
                eventSnapshot: ['missing_keys' => $missing],
                context: ['policy_type' => 'required_params'],
                resolvedBy: 'EventPolicyEngine::checkRequiredParams',
            );
        }

        return null;
    }

    /**
     * Check allowed_events whitelist policy.
     */
    private function checkAllowedEvents(
        string $ruleId,
        AnalyticsEvent $event,
        string $action,
        string $severity,
        array $config,
        ?string $description,
    ): ?PolicyViolation {
        $allowed = (array) ($config['events'] ?? []);

        if (! empty($allowed) && ! in_array($event->name(), $allowed, true)) {
            return new PolicyViolation(
                ruleId: $ruleId,
                eventName: $event->name(),
                action: $action,
                severity: PolicyViolation::SEVERITY_HIGH,
                reason: $description ?? "Event '{name}' is not in the allowed events whitelist",
                context: ['policy_type' => 'allowed_events'],
                resolvedBy: 'EventPolicyEngine::checkAllowedEvents',
            );
        }

        return null;
    }

    /**
     * Check blocked_events policy.
     */
    private function checkBlockedEvents(
        string $ruleId,
        AnalyticsEvent $event,
        string $action,
        string $severity,
        array $config,
        ?string $description,
    ): ?PolicyViolation {
        $blocked = (array) ($config['events'] ?? []);

        if (in_array($event->name(), $blocked, true)) {
            return new PolicyViolation(
                ruleId: $ruleId,
                eventName: $event->name(),
                action: PolicyViolation::ACTION_BLOCK,
                severity: PolicyViolation::SEVERITY_CRITICAL,
                reason: $description ?? "Event '{name}' is explicitly blocked by policy",
                context: ['policy_type' => 'blocked_events'],
                resolvedBy: 'EventPolicyEngine::checkBlockedEvents',
            );
        }

        return null;
    }

    /**
     * Check allowed_categories policy.
     */
    private function checkAllowedCategories(
        string $ruleId,
        AnalyticsEvent $event,
        string $action,
        string $severity,
        array $config,
        ?string $description,
    ): ?PolicyViolation {
        $allowedCategories = (array) ($config['categories'] ?? []);

        if (! empty($allowedCategories)) {
            $eventCategory = EventCatalog::getCategory($event->name());

            if ($eventCategory === null || ! in_array($eventCategory, $allowedCategories, true)) {
                return new PolicyViolation(
                    ruleId: $ruleId,
                    eventName: $event->name(),
                    action: $action,
                    severity: $severity,
                    reason: $description ?? "Event category '{category}' is not in allowed list",
                    eventSnapshot: ['category' => $eventCategory],
                    context: ['policy_type' => 'allowed_categories'],
                    resolvedBy: 'EventPolicyEngine::checkAllowedCategories',
                );
            }
        }

        return null;
    }

    /**
     * Check PII detection policy.
     */
    private function checkPiiDetection(
        string $ruleId,
        AnalyticsEvent $event,
        array $params,
        string $action,
        string $severity,
        ?string $description,
    ): ?PolicyViolation {
        $foundPii = [];

        foreach (array_keys($params) as $key) {
            $normalizedKey = strtolower((string) $key);

            foreach ($this->piiPatterns as $pattern) {
                if (str_contains($normalizedKey, strtolower($pattern))) {
                    $foundPii[] = $key;
                    break;
                }
            }
        }

        // Also check values for email-like patterns
        foreach ($params as $key => $value) {
            if (is_string($value) && preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $value)) {
                if (! in_array($key, $foundPii, true)) {
                    $foundPii[] = $key;
                }
            }
        }

        if (! empty($foundPii)) {
            return new PolicyViolation(
                ruleId: $ruleId,
                eventName: $event->name(),
                action: $action,
                severity: PolicyViolation::SEVERITY_HIGH,
                reason: $description ?? "Event potentially contains PII in parameters: {keys}",
                eventSnapshot: ['pii_keys' => $foundPii],
                context: ['policy_type' => 'pii_detection'],
                resolvedBy: 'EventPolicyEngine::checkPiiDetection',
            );
        }

        return null;
    }

    /**
     * Check rate limit per event policy.
     */
    private function checkRateLimitPerEvent(
        string $ruleId,
        AnalyticsEvent $event,
        string $action,
        string $severity,
        array $config,
        ?string $description,
    ): ?PolicyViolation {
        $limit = (int) ($config['limit'] ?? 100);
        $windowSeconds = (int) ($config['window_seconds'] ?? 60);

        $cacheKey = self::RATE_LIMIT_PREFIX . $event->name() . '_' . (int) (time() / $windowSeconds);
        $current = (int) $this->cache->get($cacheKey, 0);

        if ($current >= $limit) {
            return new PolicyViolation(
                ruleId: $ruleId,
                eventName: $event->name(),
                action: PolicyViolation::ACTION_BLOCK,
                severity: PolicyViolation::SEVERITY_HIGH,
                reason: $description ?? "Event '{name}' exceeds rate limit of {limit} per {window}s",
                eventSnapshot: ['current_count' => $current, 'limit' => $limit, 'window' => $windowSeconds],
                context: ['policy_type' => 'rate_limit_per_event'],
                resolvedBy: 'EventPolicyEngine::checkRateLimitPerEvent',
            );
        }

        // Increment counter
        $this->cache->put($cacheKey, $current + 1, $windowSeconds + 1);

        return null;
    }

    /**
     * Apply sanitization to params based on a rule.
     *
     * @param  array<string, mixed>  $params
     * @param  array{type: string, config: array<string, mixed>}  $rule
     * @return array<string, mixed>
     */
    private function applySanitization(array $params, array $rule): array
    {
        $config = $rule['config'] ?? [];

        return match ($rule['type'] ?? '') {
            'max_value_length' => $this->truncateValues($params, (int) ($config['max_length'] ?? 500)),
            'disallowed_params' => $this->removeDisallowed($params, (array) ($config['keys'] ?? [])),
            'pii_detection' => $this->maskPii($params),
            default => $params,
        };
    }

    /**
     * Truncate string values that exceed max length.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function truncateValues(array $params, int $maxLength): array
    {
        foreach ($params as $key => $value) {
            if (is_string($value) && strlen($value) > $maxLength) {
                $params[$key] = substr($value, 0, $maxLength);
            }
        }

        return $params;
    }

    /**
     * Remove disallowed parameter keys.
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function removeDisallowed(array $params, array $keys): array
    {
        foreach ($keys as $key) {
            unset($params[$key]);
        }

        return $params;
    }

    /**
     * Mask PII values in params.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function maskPii(array $params): array
    {
        foreach (array_keys($params) as $key) {
            $normalizedKey = strtolower((string) $key);

            foreach ($this->piiPatterns as $pattern) {
                if (str_contains($normalizedKey, strtolower($pattern))) {
                    $params[$key] = '[REDACTED]';
                    break;
                }
            }
        }

        return $params;
    }

    /**
     * Log policy violations to cache and logger.
     *
     * @param  list<PolicyViolation>  $violations
     */
    private function logViolations(array $violations): void
    {
        /** @var list<array<string, mixed>>|null $history */
        $history = $this->cache->get(self::VIOLATION_LOG_KEY);

        if ($history === null) {
            $history = [];
        }

        foreach ($violations as $violation) {
            $history[] = $violation->toArray();

            if ($violation->isCritical()) {
                Log::warning("Analytics governance: critical policy violation", [
                    'analytics_governance' => true,
                    'rule_id' => $violation->ruleId,
                    'event_name' => $violation->eventName,
                    'action' => $violation->action,
                    'reason' => $violation->reason,
                ]);
            }
        }

        // Trim to max history
        if (count($history) > $this->maxViolationHistory) {
            $history = array_slice($history, -$this->maxViolationHistory);
        }

        $this->cache->put(self::VIOLATION_LOG_KEY, $history, 86400); // 24 hours
    }

    /**
     * Get policy violation history.
     *
     * @return list<array<string, mixed>>
     */
    public function violationHistory(int $limit = 50): array
    {
        /** @var list<array<string, mixed>>|null $history */
        $history = $this->cache->get(self::VIOLATION_LOG_KEY);

        if ($history === null) {
            return [];
        }

        return array_slice($history, 0, $limit);
    }

    /**
     * Get violation statistics.
     *
     * @return array{total: int, blocked: int, critical: int, by_event: array<string, int>, by_rule: array<string, int>}
     */
    public function violationStats(): array
    {
        $history = $this->violationHistory(500);

        $stats = [
            'total' => count($history),
            'blocked' => 0,
            'critical' => 0,
            'by_event' => [],
            'by_rule' => [],
        ];

        foreach ($history as $violation) {
            if (($violation['is_blocked'] ?? false) === true) {
                $stats['blocked']++;
            }

            if (($violation['is_critical'] ?? false) === true) {
                $stats['critical']++;
            }

            $eventName = $violation['event_name'] ?? 'unknown';
            $ruleId = $violation['rule_id'] ?? 'unknown';

            $stats['by_event'][$eventName] = ($stats['by_event'][$eventName] ?? 0) + 1;
            $stats['by_rule'][$ruleId] = ($stats['by_rule'][$ruleId] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * Get a summary of all configured policies.
     *
     * @return array{enabled: bool, policy_count: int, policies: array<string, array{type: string, action: string, severity: string, description: string|null}>}
     */
    public function summary(): array
    {
        $policyList = [];

        foreach ($this->policies as $ruleId => $rule) {
            $policyList[$ruleId] = [
                'type' => $rule['type'] ?? 'unknown',
                'action' => $rule['action'] ?? $this->defaultAction,
                'severity' => $rule['severity'] ?? PolicyViolation::SEVERITY_MEDIUM,
                'description' => $rule['description'] ?? null,
            ];
        }

        return [
            'enabled' => $this->enabled,
            'policy_count' => count($this->policies),
            'policies' => $policyList,
            'violation_stats' => $this->violationStats(),
        ];
    }
}
