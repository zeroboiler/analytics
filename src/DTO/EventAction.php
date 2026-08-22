<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing a registered event action.
 *
 * An event action is a side-effect that should be executed when a specific
 * analytics event is dispatched. Actions are registered via config or
 * programmatically via EventActionRegistry.
 *
 * Supports:
 * - Exact event name matching (on: 'purchase')
 * - Glob pattern matching (on: 'saas.*')
 * - Category-level matching (on: 'category:ecommerce')
 * - Per-action cooldown (prevents duplicate rapid execution)
 * - Priority ordering (lower priority = runs first)
 *
 * @phpstan-type ActionHandler callable(AnalyticsEvent): void
 *
 * @since 230.0.0
 */
final readonly class EventAction
{
    /**
     * Create a new event action.
     *
     * @param  string  $id  Unique action identifier
     * @param  string  $on  Event name, glob pattern, or category prefix (e.g. 'purchase', 'saas.*', 'category:ecommerce')
     * @param  ActionHandler  $handler  Callable to execute when event matches
     * @param  int  $priority  Execution priority (lower = runs first, default: 100)
     * @param  int|null  $cooldownSeconds  Minimum seconds between executions (null = no cooldown)
     * @param  string|null  $condition  Optional expression: 'param.revenue > 100'
     * @param  array<string, mixed>  $metadata  Optional metadata for observability
     */
    public function __construct(
        public string $id,
        public string $on,
        public \Closure $handler,
        public int $priority = 100,
        public ?int $cooldownSeconds = null,
        public ?string $condition = null,
        public array $metadata = [],
    ) {}

    /**
     * Check if this action matches a given event name.
     *
     * Supports:
     * - Exact match: 'purchase' matches 'purchase'
     * - Glob pattern: 'saas.*' matches 'saas.sign_up'
     * - Category prefix: 'category:ecommerce' matches any ecommerce event
     */
    public function matches(string $eventName): bool
    {
        // Category-level matching
        if (str_starts_with($this->on, 'category:')) {
            $category = substr($this->on, 9);

            return $this->resolveCategory($eventName) === $category;
        }

        // Exact match
        if ($this->on === $eventName) {
            return true;
        }

        // Glob pattern matching (* and ? wildcards)
        if (str_contains($this->on, '*') || str_contains($this->on, '?')) {
            return $this->globMatch($this->on, $eventName);
        }

        return false;
    }

    /**
     * Check if the action's condition is satisfied by the event.
     *
     * Evaluates simple dot-notation conditions against event params:
     * - 'param.revenue > 100'
     * - 'param.plan == "pro"'
     * - 'param.count >= 5'
     *
     * Returns true if no condition is set (unconditional action).
     */
    public function conditionSatisfied(AnalyticsEvent $event): bool
    {
        if ($this->condition === null || $this->condition === '') {
            return true;
        }

        return $this->evaluateCondition($this->condition, $event->params);
    }

    /**
     * Serialize to array (excludes handler, includes metadata for persistence).
     *
     * @return array{id: string, on: string, priority: int, cooldown_seconds: int|null, condition: string|null, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'on' => $this->on,
            'priority' => $this->priority,
            'cooldown_seconds' => $this->cooldownSeconds,
            'condition' => $this->condition,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Resolve the event category from EventCatalog.
     */
    private function resolveCategory(string $eventName): ?string
    {
        try {
            $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::class;

            return $catalog::getCategory($eventName);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Simple glob pattern matching.
     *
     * Converts glob patterns to regex: * → .*, ? → .
     */
    private function globMatch(string $pattern, string $subject): bool
    {
        $regex = '/^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';

        return (bool) preg_match($regex, $subject);
    }

    /**
     * Evaluate a simple condition expression against event params.
     *
     * Supports operators: ==, !=, >, <, >=, <=, ===, !==
     * Supports logical: && (AND)
     *
     * @param  array<string, mixed>  $params
     */
    private function evaluateCondition(string $condition, array $params): bool
    {
        $parts = preg_split('/\s*&&\s*/', $condition) ?: [$condition];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            // Match patterns like: param.revenue > 100
            if (! preg_match('/^([\w.]+)\s*(===|!==|==|!=|>=|<=|>|<)\s*(.+)$/i', $part, $matches)) {
                return false;
            }

            $field = $matches[1];
            $operator = $matches[2];
            $expectedRaw = trim($matches[3], ' "\'' );

            $actual = $this->resolveFieldValue($field, $params);

            if ($actual === null) {
                return false;
            }

            // Type coercion for numeric comparisons
            if (is_numeric($expectedRaw) && is_numeric($actual)) {
                $expected = (float) $expectedRaw;
                $actual = (float) $actual;
            } else {
                $expected = $expectedRaw;
            }

            if (! $this->compare($actual, $expected, $operator)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a dot-notation field value from params.
     *
     * @param  array<string, mixed>  $params
     * @return mixed
     */
    private function resolveFieldValue(string $field, array $params): mixed
    {
        // Strip leading 'param.' prefix if present
        if (str_starts_with($field, 'param.')) {
            $field = substr($field, 6);
        }

        $keys = explode('.', $field);
        $value = $params;

        foreach ($keys as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Compare two values using the specified operator.
     */
    private function compare(mixed $actual, mixed $expected, string $operator): bool
    {
        return match ($operator) {
            '===' => $actual === $expected,
            '!==' => $actual !== $expected,
            '==' => $actual == $expected,
            '!=' => $actual != $expected,
            '>' => $actual > $expected,
            '<' => $actual < $expected,
            '>=' => $actual >= $expected,
            '<=' => $actual <= $expected,
            default => false,
        };
    }
}
