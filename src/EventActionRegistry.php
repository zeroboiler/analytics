<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventAction;

/**
 * Event Action Registry — registers and manages side-effect actions for analytics events.
 *
 * When an analytics event is dispatched, registered actions (callables) are executed
 * as side effects. Actions can match events by:
 *
 * - **Exact name**: `'purchase'` → matches `purchase` event only
 * - **Glob pattern**: `'saas.*'` → matches all SaaS events
 * - **Category prefix**: `'category:ecommerce'` → matches all ecommerce events
 *
 * Actions support:
 * - Priority ordering (lower number = runs first)
 * - Per-action cooldown (prevents rapid re-execution)
 * - Conditional execution (param-based expressions)
 * - Config-driven registration + programmatic API
 *
 * Inspired by Segment's Event Actions, Customer.io's Campaign Triggers,
 * and PostHog's Action Matching.
 *
 * @see \ZeroBoiler\Analytics\DTO\EventAction
 *
 * @since 230.0.0
 */
final class EventActionRegistry
{
    private const COOLDOWN_PREFIX = 'zb_event_action_cooldown_';
    private const LAST_EXECUTION_PREFIX = 'zb_event_action_last_';

    /** @var array<string, EventAction> Registered actions keyed by ID */
    private array $actions = [];

    /** @var array<string, int> Execution count per action ID */
    private array $executionCounts = [];

    private bool $enabled;

    private bool $debug;

    private ConfigRepository $config;

    private ?CacheRepository $cache;

    /**
     * @param  ConfigRepository  $config
     * @param  CacheRepository|null  $cache  Optional cache for cooldown tracking
     */
    public function __construct(ConfigRepository $config, ?CacheRepository $cache = null){
        $this->config = $config;
        $this->cache = $cache;

        $actionsConfig = $config->get('zeroboiler.analytics.event_actions', []);
        /** @var array{enabled?: bool, debug?: bool, actions?: list<array{id: string, on: string, handler?: string, priority?: int, cooldown?: int|null, condition?: string|null, metadata?: array<string, mixed>}>} $actionsConfig */
        $this->enabled = (bool) ($actionsConfig['enabled'] ?? false);
        $this->debug = (bool) ($actionsConfig['debug'] ?? false);

        if ($this->enabled) {
            $this->loadFromConfig($actionsConfig['actions'] ?? []);
        }
    }

    /**
     * Register an event action programmatically.
     *
     * @param  EventAction  $action  The action to register
     */
    public function register(EventAction $action): void
    {
        $this->actions[$action->id] = $action;

        if ($this->debug) {
            Log::debug("[ZeroBoiler] Event action registered: {$action->id} (on: {$action->on})", $action->toArray());
        }
    }

    /**
     * Unregister an action by ID.
     */
    public function unregister(string $actionId): void
    {
        unset($this->actions[$actionId]);
    }

    /**
     * Find and execute all matching actions for a dispatched event.
     *
     * Actions are sorted by priority (ascending) before execution.
     * Cooldown and condition checks are applied before each execution.
     *
     * @param  AnalyticsEvent  $event  The dispatched analytics event
     * @return array{executed: list<string>, skipped: list<string>, errors: list<string>}
     */
    public function dispatch(AnalyticsEvent $event): array
    {
        if (! $this->enabled || $this->actions === []) {
            return ['executed' => [], 'skipped' => [], 'errors' => []];
        }

        $matching = $this->findMatchingActions($event);
        $executed = [];
        $skipped = [];
        $errors = [];

        usort($matching, fn (EventAction $a, EventAction $b): int => $a->priority <=> $b->priority);

        foreach ($matching as $action) {
            if (! $action->conditionSatisfied($event)) {
                $skipped[] = $action->id;
                continue;
            }

            if ($action->cooldownSeconds !== null && $this->isOnCooldown($action)) {
                $skipped[] = $action->id;
                continue;
            }

            // Execute
            try {
                ($action->handler)($event);

                $this->markExecuted($action);
                $this->executionCounts[$action->id] = ($this->executionCounts[$action->id] ?? 0) + 1;
                $executed[] = $action->id;

                if ($this->debug) {
                    Log::debug("[ZeroBoiler] Event action executed: {$action->id} for event: {$event->name}");
                }
            } catch (\Throwable $e) {
                $errors[] = $action->id . ': ' . $e->getMessage();

                if ($this->debug) {
                    Log::debug("[ZeroBoiler] Event action error: {$action->id}", [
                        'error' => $e->getMessage(),
                        'event' => $event->name,
                    ]);
                }

                // Actions must not break the dispatch chain
            }
        }

        return ['executed' => $executed, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Find all actions that match a given event name.
     *
     * @return list<EventAction>
     */
    public function findMatchingActions(AnalyticsEvent $event): array
    {
        $matching = [];

        foreach ($this->actions as $action) {
            if ($action->matches($event->name)) {
                $matching[] = $action;
            }
        }

        return $matching;
    }

    /**
     * Get all registered actions.
     *
     * @return array<string, EventAction>
     */
    public function all(): array
    {
        return $this->actions;
    }

    /**
     * Get an action by ID.
     */
    public function get(string $id): ?EventAction
    {
        return $this->actions[$id] ?? null;
    }

    /**
     * Check if an action is registered.
     */
    public function has(string $id): bool
    {
        return isset($this->actions[$id]);
    }

    /**
     * Count registered actions.
     */
    public function count(): int
    {
        return count($this->actions);
    }

    /**
     * Get execution count for a specific action.
     */
    public function executionCount(string $actionId): int
    {
        return $this->executionCounts[$actionId] ?? 0;
    }

    /**
     * Get total execution counts across all actions.
     */
    public function totalExecutions(): int
    {
        return array_sum($this->executionCounts);
    }

    /**
     * Get actions grouped by event pattern.
     *
     * @return array<string, list<EventAction>>
     */
    public function groupedByPattern(): array
    {
        $grouped = [];

        foreach ($this->actions as $action) {
            $grouped[$action->on][] = $action;
        }

        return $grouped;
    }

    /**
     * Get a summary of registered actions.
     *
     * @return array{enabled: bool, total_actions: int, total_executions: int, patterns: int, actions: list<array{id: string, on: string, priority: int, cooldown: int|null, condition: string|null, executions: int}>}
     */
    public function summary(): array
    {
        $actionList = [];
        $patterns = [];

        foreach ($this->actions as $action) {
            $patterns[$action->on] = true;
            $actionList[] = [
                'id' => $action->id,
                'on' => $action->on,
                'priority' => $action->priority,
                'cooldown' => $action->cooldownSeconds,
                'condition' => $action->condition,
                'executions' => $this->executionCounts[$action->id] ?? 0,
            ];
        }

        usort($actionList, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return [
            'enabled' => $this->enabled,
            'total_actions' => count($this->actions),
            'total_executions' => $this->totalExecutions(),
            'patterns' => count($patterns),
            'actions' => $actionList,
        ];
    }

    /**
     * Clear all registered actions and execution counts.
     */
    public function flush(): void
    {
        $this->actions = [];
        $this->executionCounts = [];
    }

    /**
     * Check if an action is on cooldown.
     */
    private function isOnCooldown(EventAction $action): bool
    {
        if ($this->cache === null || $action->cooldownSeconds === null) {
            return false;
        }

        $key = self::COOLDOWN_PREFIX . $action->id;
        $lastExecution = $this->cache->get($key);

        if ($lastExecution === null) {
            return false;
        }

        return (time() - (int) $lastExecution) < $action->cooldownSeconds;
    }

    /**
     * Mark an action as executed (set cooldown).
     */
    private function markExecuted(EventAction $action): void
    {
        if ($this->cache === null) {
            return;
        }

        $this->cache->put(
            self::COOLDOWN_PREFIX . $action->id,
            time(),
            $action->cooldownSeconds ?? 60,
        );
    }

    /**
     * Load actions from config.
     *
     * Config-defined actions use class@method or invokable class references
     * that are resolved from the Laravel container.
     *
     * @param  list<array{id: string, on: string, handler?: string, priority?: int, cooldown?: int|null, condition?: string|null, metadata?: array<string, mixed>}>  $configActions
     */
    private function loadFromConfig(array $configActions): void
    {
        foreach ($configActions as $actionConfig) {
            $id = $actionConfig['id'] ?? '';
            $on = $actionConfig['on'] ?? '';

            if ($id === '' || $on === '') {
                continue;
            }

            $handler = $actionConfig['handler'] ?? null;

            if ($handler !== null && is_string($handler)) {
                $handler = $this->resolveHandler($handler);
            }

            if ($handler === null || ! is_callable($handler)) {
                continue;
            }

            $this->register(new EventAction(
                id: $id,
                on: $on,
                handler: $handler,
                priority: (int) ($actionConfig['priority'] ?? 100),
                cooldownSeconds: $actionConfig['cooldown'] ?? null,
                condition: $actionConfig['condition'] ?? null,
                metadata: (array) ($actionConfig['metadata'] ?? []),
            ));
        }
    }

    /**
     * Resolve a handler string to a callable.
     *
     * Supports formats:
     * - 'Class@method' → resolves [app(Class), 'method']
     * - 'Class' → resolves app(Class) (invokable)
     *
     * @return callable(mixed): void|null
     */
    private function resolveHandler(string $handler): ?callable
    {
        try {
            if (str_contains($handler, '@')) {
                [$class, $method] = explode('@', $handler, 2);

                $instance = app($class);

                if (method_exists($instance, $method)) {
                    return [$instance, $method];
                }

                return null;
            }

            $instance = app($handler);

            if (is_callable($instance)) {
                return $instance;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
