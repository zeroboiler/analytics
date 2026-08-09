<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Config-driven behavioral event rules engine.
 *
 * Defines rules that trigger automated analytics events based on user behavior.
 * Supports three rule types:
 *
 * - **event_trigger**: When event X fires, also fire event Y with enriched params
 * - **absence_trigger**: When event X has NOT fired for user U within N seconds, fire event Y
 * - **property_trigger**: When a user property reaches a threshold, fire event Y
 *
 * Rules are defined in config under `zeroboiler.analytics.rules`.
 * Engine checks triggers on every dispatched event (event_trigger) and
 * on a schedule (absence_trigger via artisan command).
 *
 * @phpstan-type EventTriggerRule array{type: 'event_trigger', on: string, then: string, enrich?: array<string, string>, condition?: string}
 * @phpstan-type AbsenceTriggerRule array{type: 'absence_trigger', event: string, absent_for: int, trigger: string, params?: array<string, mixed>}
 * @phpstan-type PropertyTriggerRule array{type: 'property_trigger', property: string, operator: 'gte'|'gt'|'lte'|'lt'|'eq', value: int|float, trigger: string, params?: array<string, mixed>}
 * @phpstan-type Rule EventTriggerRule|AbsenceTriggerRule|PropertyTriggerRule
 *
 * @since 1.0.0
 */
final class EventRulesEngine
{
    private const LAST_SEEN_KEY = 'zb_rules_last_seen_';
    private const RULE_CACHE_KEY = 'zb_rules_definitions';

    private AnalyticsManager $manager;

    private CacheRepository $cache;

    private ConfigRepository $config;

    private bool $enabled;

    private bool $debug;

    /** @var array<string, Rule> */
    private array $rules = [];

    /** @var array<string, int> */
    private array $triggerCounts = [];

    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->cache = $cache;
        $this->config = $config;

        $rulesConfig = $config->get('zeroboiler.analytics.rules', []);
        /** @var array{enabled?: bool, debug?: bool, rules?: array<string, Rule>} $rulesConfig */
        $this->enabled = (bool) ($rulesConfig['enabled'] ?? false);
        $this->debug = (bool) ($rulesConfig['debug'] ?? false);

        if ($this->enabled) {
            $this->loadRules($rulesConfig['rules'] ?? []);
        }
    }

    /**
     * Evaluate all rules against a dispatched event.
     *
     * Called by AnalyticsManager after every trackEvent() call.
     * Checks event_trigger rules that match the event name.
     *
     * @param  AnalyticsEvent  $event  The event that was just dispatched
     * @return list<AnalyticsEvent>  Additional events triggered by rules
     */
    public function evaluate(AnalyticsEvent $event): array
    {
        if (! $this->enabled || $this->rules === []) {
            return [];
        }

        $triggered = [];

        foreach ($this->rules as $ruleId => $rule) {
            if ($rule['type'] !== 'event_trigger') {
                continue;
            }

            if ($rule['on'] !== $event->name) {
                continue;
            }

            $triggeredEvent = $this->evaluateEventTrigger($rule, $event);

            if ($triggeredEvent !== null) {
                $triggered[] = $triggeredEvent;
                $this->triggerCounts[$ruleId] = ($this->triggerCounts[$ruleId] ?? 0) + 1;
            }
        }

        // Update last-seen timestamp for absence detection
        $this->updateLastSeen($event);

        return $triggered;
    }

    /**
     * Evaluate absence-trigger rules (call on schedule).
     *
     * Scans all absence_trigger rules and fires trigger events for users
     * who haven't performed the target action within the configured window.
     *
     * @return list<AnalyticsEvent>  Events triggered by absence rules
     */
    public function evaluateAbsenceRules(): array
    {
        if (! $this->enabled) {
            return [];
        }

        $triggered = [];

        foreach ($this->rules as $ruleId => $rule) {
            if ($rule['type'] !== 'absence_trigger') {
                continue;
            }

            $absentEvents = $this->findAbsentUsers($rule);

            foreach ($absentEvents as $clientId => $lastSeen) {
                $params = $rule['params'] ?? [];
                $params = is_array($params) ? $params : [];

                $triggeredEvent = new AnalyticsEvent(
                    name: $rule['trigger'],
                    params: array_merge($params, [
                        'absent_event' => $rule['event'],
                        'last_seen_seconds_ago' => time() - $lastSeen,
                        'rule_id' => $ruleId,
                    ]),
                    clientId: $clientId,
                );

                $triggered[] = $triggeredEvent;
                $this->triggerCounts[$ruleId] = ($this->triggerCounts[$ruleId] ?? 0) + 1;

                if ($this->debug) {
                    Log::debug('EventRulesEngine: absence trigger fired', [
                        'rule_id' => $ruleId,
                        'client_id' => $clientId,
                        'trigger' => $rule['trigger'],
                    ]);
                }
            }
        }

        return $triggered;
    }

    /**
     * Evaluate property-trigger rules against current user properties.
     *
     * @param  string|null  $clientId  Client ID to evaluate against
     * @param  string|null  $userId  User ID to evaluate against
     * @param  array<string, mixed>  $properties  Current user properties
     * @return list<AnalyticsEvent>  Events triggered by property rules
     */
    public function evaluatePropertyRules(
        ?string $clientId,
        ?string $userId,
        array $properties,
    ): array {
        if (! $this->enabled) {
            return [];
        }

        $triggered = [];

        foreach ($this->rules as $ruleId => $rule) {
            if ($rule['type'] !== 'property_trigger') {
                continue;
            }

            $propertyValue = $properties[$rule['property']] ?? null;

            if ($propertyValue === null) {
                continue;
            }

            if (! $this->compareProperty($propertyValue, $rule['operator'], $rule['value'])) {
                continue;
            }

            $params = $rule['params'] ?? [];
            $params = is_array($params) ? $params : [];

            $triggeredEvent = new AnalyticsEvent(
                name: $rule['trigger'],
                params: array_merge($params, [
                    'trigger_property' => $rule['property'],
                    'trigger_value' => $propertyValue,
                    'trigger_operator' => $rule['operator'],
                    'rule_id' => $ruleId,
                ]),
                clientId: $clientId,
                userId: $userId,
            );

            $triggered[] = $triggeredEvent;
            $this->triggerCounts[$ruleId] = ($this->triggerCounts[$ruleId] ?? 0) + 1;
        }

        return $triggered;
    }

    /**
     * Get trigger counts for all rules (since last reset).
     *
     * @return array<string, int>
     */
    public function triggerCounts(): array
    {
        return $this->triggerCounts;
    }

    /**
     * Get all loaded rules.
     *
     * @return array<string, Rule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Check if the rules engine is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Reset trigger counts (useful for periodic reporting).
     */
    public function resetTriggerCounts(): void
    {
        $this->triggerCounts = [];
    }

    /**
     * Load rules from config.
     *
     * @param  array<string, Rule>  $rules
     */
    private function loadRules(array $rules): void
    {
        foreach ($rules as $id => $rule) {
            $type = $rule['type'] ?? null;

            if (! in_array($type, ['event_trigger', 'absence_trigger', 'property_trigger'], true)) {
                continue;
            }

            $this->rules[$id] = $rule;
        }

        if ($this->debug) {
            Log::debug('EventRulesEngine: loaded rules', [
                'count' => count($this->rules),
                'types' => array_count_values(array_map(
                    fn (array $r): string => $r['type'],
                    $this->rules,
                )),
            ]);
        }
    }

    /**
     * Evaluate an event_trigger rule.
     *
     * @param  EventTriggerRule  $rule
     */
    private function evaluateEventTrigger(array $rule, AnalyticsEvent $source): ?AnalyticsEvent
    {
        // Check optional condition against event params
        if (isset($rule['condition'])) {
            $paramKey = $rule['condition'];
            $paramValue = $source->params[$paramKey] ?? null;

            if ($paramValue === null || $paramValue === '' || $paramValue === 0 || $paramValue === false) {
                return null;
            }
        }

        // Build enriched params from source event
        $enrichedParams = $source->params;

        if (isset($rule['enrich']) && is_array($rule['enrich'])) {
            foreach ($rule['enrich'] as $targetKey => $sourceKey) {
                $enrichedParams[$targetKey] = $enrichedParams[$sourceKey] ?? null;
            }
        }

        $enrichedParams['triggered_by'] = $source->name;
        $enrichedParams['source_event_id'] = $source->id;

        if ($this->debug) {
            Log::debug('EventRulesEngine: event trigger fired', [
                'on' => $rule['on'],
                'then' => $rule['then'],
                'source_event' => $source->name,
                'source_id' => $source->id,
            ]);
        }

        return new AnalyticsEvent(
            name: $rule['then'],
            params: $enrichedParams,
            clientId: $source->clientId,
            userId: $source->userId,
        );
    }

    /**
     * Update last-seen timestamp for a client/event pair.
     */
    private function updateLastSeen(AnalyticsEvent $event): void
    {
        if ($event->clientId === null || $event->clientId === '') {
            return;
        }

        $key = self::LAST_SEEN_KEY . $event->clientId . '_' . $event->name;
        $this->cache->put($key, time(), 7776000); // 90 days
    }

    /**
     * Find users who haven't performed an event within the absence window.
     *
     * @param  AbsenceTriggerRule  $rule
     * @return array<string, int>  client_id => last_seen_timestamp
     */
    private function findAbsentUsers(array $rule): array
    {
        $absentUsers = [];
        $threshold = time() - $rule['absent_for'];

        // Scan recent client IDs from the event stream
        $recentClients = $this->getRecentClientIds($rule['event']);

        foreach ($recentClients as $clientId) {
            $lastSeen = $this->cache->get(self::LAST_SEEN_KEY . $clientId . '_' . $rule['event']);

            if ($lastSeen === null || (int) $lastSeen < $threshold) {
                $absentUsers[$clientId] = (int) ($lastSeen ?? 0);
            }
        }

        return $absentUsers;
    }

    /**
     * Get recent client IDs that have fired a specific event.
     *
     * Uses a set-based cache key to track known client IDs per event.
     *
     * @return list<string>
     */
    private function getRecentClientIds(string $eventName): array
    {
        $key = self::LAST_SEEN_KEY . 'clients_' . $eventName;
        $clients = $this->cache->get($key);

        if (is_array($clients)) {
            return $clients;
        }

        return [];
    }

    /**
     * Compare a property value against a threshold using an operator.
     */
    private function compareProperty(mixed $value, string $operator, int|float $threshold): bool
    {
        return match ($operator) {
            'gte' => (float) $value >= $threshold,
            'gt' => (float) $value > $threshold,
            'lte' => (float) $value <= $threshold,
            'lt' => (float) $value < $threshold,
            'eq' => (float) $value === $threshold,
            default => false,
        };
    }
}
