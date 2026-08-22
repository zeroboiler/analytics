<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Store\DatabaseEventStore;

/**
 * Fluent query builder for analytics event searches.
 *
 * Provides a chainable, type-safe API for constructing event queries
 * with filtering, sorting, pagination, and aggregation. Designed for
 * admin dashboards, debug tools, and event exploration interfaces.
 *
 * Queries are built lazily and executed via get() / count() / first().
 * Results are returned as plain arrays for flexibility.
 *
 * @example
 *   $events = EventQueryBuilder::make()
 *       ->name('purchase')
 *       ->category('ecommerce')
 *       ->param('currency', 'USD')
 *       ->where('value', '>', 50.0)
 *       ->since(now()->subDays(7))
 *       ->until(now())
 *       ->orderBy('timestamp', 'desc')
 *       ->limit(50)
 *       ->offset(0)
 *       ->get();
 *
 * @since 231.0.0
 *
 * @see \ZeroBoiler\Analytics\Store\DatabaseEventStore
 */
final class EventQueryBuilder
{
    /** @var list<string> */
    private array $eventNames = [];

    /** @var list<string> */
    private array $categories = [];

    /** @var array<string, mixed> Exact-match parameter filters */
    private array $paramFilters = [];

    /** @var array<string, array{op: string, value: mixed}> Comparison filters */
    private array $comparisonFilters = [];

    private ?string $clientId = null;

    private ?string $userId = null;

    private ?\DateTimeImmutable $since = null;

    private ?\DateTimeImmutable $until = null;

    /** @var array<string, 'asc'|'desc'> */
    private array $orderBy = [];

    private int $limit = 100;

    private int $offset = 0;

    private ?string $source = null;

    private ?string $priority = null;

    private ?string $sessionId = null;

    private bool $includeSchema = false;

    private bool $countOnly = false;

    private function __construct(){}

    /**
     * Create a new query builder instance.
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * Filter by exact event name(s).
     *
     * @param  string|list<string>  $names  One or more event names
     */
    public function name(string|array $names): self
    {
        $this->eventNames = is_array($names) ? $names : [$names];

        return $this;
    }

    /**
     * Filter by event category/categories.
     *
     * @param  string|list<string>  $categories  One or more categories (ecommerce, saas, engagement, etc.)
     */
    public function category(string|array $categories): self
    {
        $this->categories = is_array($categories) ? $categories : [$categories];

        return $this;
    }

    /**
     * Filter by an exact parameter value.
     *
     * @param  string  $key  Parameter key
     * @param  mixed  $value  Expected value (strict equality)
     */
    public function param(string $key, mixed $value): self
    {
        $this->paramFilters[$key] = $value;

        return $this;
    }

    /**
     * Add a comparison filter for a parameter.
     *
     * @param  string  $key  Parameter key
     * @param  string  $op  Comparison operator: =, !=, >, >=, <, <=, like, in, not_in
     * @param  mixed  $value  Comparison value
     */
    public function where(string $key, string $op, mixed $value): self
    {
        $this->comparisonFilters[$key] = ['op' => $op, 'value' => $value];

        return $this;
    }

    /**
     * Filter by client ID.
     */
    public function clientId(string $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    /**
     * Filter by user ID.
     */
    public function userId(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Filter events from this timestamp onward (inclusive).
     */
    public function since(\DateTimeImmutable $date): self
    {
        $this->since = $date;

        return $this;
    }

    /**
     * Filter events up to this timestamp (inclusive).
     */
    public function until(\DateTimeImmutable $date): self
    {
        $this->until = $date;

        return $this;
    }

    /**
     * Order results by a field.
     *
     * @param  string  $field  Field name (timestamp, name, etc.)
     * @param  'asc'|'desc'  $direction  Sort direction
     */
    public function orderBy(string $field, string $direction = 'desc'): self
    {
        $this->orderBy[$field] = $direction === 'asc' ? 'asc' : 'desc';

        return $this;
    }

    /**
     * Limit the number of results.
     */
    public function limit(int $limit): self
    {
        $this->limit = max(1, min($limit, 10000));

        return $this;
    }

    /**
     * Offset for pagination.
     */
    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * Filter by event source (api, server, client, webhook, replay, batch).
     */
    public function source(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Filter by event priority (critical, normal, low, background).
     */
    public function priority(string $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Filter by session ID.
     */
    public function sessionId(string $sessionId): self
    {
        $this->sessionId = $sessionId;

        return $this;
    }

    /**
     * Include schema metadata for each matched event (category, provider mappings).
     */
    public function withSchema(): self
    {
        $this->includeSchema = true;

        return $this;
    }

    /**
     * Build the query as a count-only query.
     */
    public function countOnly(): self
    {
        $this->countOnly = true;

        return $this;
    }

    /**
     * Get all matching events as array representation.
     *
     * Returns a summary structure when no event store is available,
     * or executes against a DatabaseEventStore if one is resolved.
     *
     * @return array{query: array<string, mixed>, total: int, results: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function get(): array
    {
        $queryDescription = $this->buildQueryDescription();
        $results = $this->executeQuery();

        return [
            'query' => $queryDescription,
            'total' => count($results),
            'results' => $results,
            'meta' => [
                'limit' => $this->limit,
                'offset' => $this->offset,
                'has_more' => count($results) >= $this->limit,
            ],
        ];
    }

    /**
     * Get the count of matching events.
     */
    public function count(): int
    {
        $results = $this->executeQuery();

        return count($results);
    }

    /**
     * Get the first matching event, or null.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $originalLimit = $this->limit;
        $originalOffset = $this->offset;
        $this->limit = 1;
        $this->offset = 0;

        $results = $this->executeQuery();

        $this->limit = $originalLimit;
        $this->offset = $originalOffset;

        return $results[0] ?? null;
    }

    /**
     * Build a human-readable query description.
     *
     * @return array<string, mixed>
     */
    public function buildQueryDescription(): array
    {
        return [
            'event_names' => $this->eventNames,
            'categories' => $this->categories,
            'param_filters' => $this->paramFilters,
            'comparison_filters' => $this->comparisonFilters,
            'client_id' => $this->clientId,
            'user_id' => $this->userId,
            'since' => $this->since?->format('Y-m-d\TH:i:s\Z'),
            'until' => $this->until?->format('Y-m-d\TH:i:s\Z'),
            'order_by' => $this->orderBy,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'source' => $this->source,
            'priority' => $this->priority,
            'session_id' => $this->sessionId,
            'with_schema' => $this->includeSchema,
        ];
    }

    /**
     * Convert the query to its filter array representation.
     *
     * Useful for passing to event store implementations or API requests.
     *
     * @return array<string, mixed>
     */
    public function toFilters(): array
    {
        $filters = [];

        if (! empty($this->eventNames)) {
            $filters['event_names'] = $this->eventNames;
        }

        if (! empty($this->categories)) {
            $filters['categories'] = $this->categories;
        }

        if (! empty($this->paramFilters)) {
            $filters['params'] = $this->paramFilters;
        }

        if (! empty($this->comparisonFilters)) {
            $filters['where'] = array_map(
                fn (array $f): string => "{$f['op']} {$f['value']}",
                $this->comparisonFilters,
            );
        }

        if ($this->clientId !== null) {
            $filters['client_id'] = $this->clientId;
        }

        if ($this->userId !== null) {
            $filters['user_id'] = $this->userId;
        }

        if ($this->since !== null) {
            $filters['since'] = $this->since->getTimestamp();
        }

        if ($this->until !== null) {
            $filters['until'] = $this->until->getTimestamp();
        }

        if ($this->source !== null) {
            $filters['source'] = $this->source;
        }

        if ($this->priority !== null) {
            $filters['priority'] = $this->priority;
        }

        if ($this->sessionId !== null) {
            $filters['session_id'] = $this->sessionId;
        }

        return $filters;
    }

    /**
     * Execute the query against the event store.
     *
     * When no database event store is available, returns an empty result set
     * with the query description. The query can be hydrated later.
     *
     * @return list<array<string, mixed>>
     */
    private function executeQuery(): array
    {
        try {
            if (class_exists(DatabaseEventStore::class) && function_exists('app')) {
                /** @var DatabaseEventStore|null $store */
                $store = app(DatabaseEventStore::class);
                if ($store !== null && method_exists($store, 'query')) {
                    return $store->query($this->toFilters(), $this->limit, $this->offset);
                }
            }
        } catch (\Throwable $e) {
            // Container not available (e.g., unit tests without Laravel)
        }

        // Fallback: return empty — query description is still useful for API/debug
        return [];
    }
}
