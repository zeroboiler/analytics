<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Typed immutable collection of analytics events.
 *
 * Provides a fluent interface for building, filtering, and transforming
 * batches of events. Used by the API controller, queue dispatcher,
 * and event pipeline for batch operations.
 *
 * @implements IteratorAggregate<int, AnalyticsEvent>
 *
 * @since 1.0.0
 */
final readonly class EventCollection implements Countable, IteratorAggregate
{
    /**
     * @param  list<AnalyticsEvent>  $events
     */
    public function __construct(
        public array $events = [],
    ): void {}

    /**
     * Create from an array of raw event data.
     *
     * @param  list<array{name: string, params?: array<string, mixed>, client_id?: string|null, user_id?: string|null, priority?: string|null}>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(
                fn (array $item): AnalyticsEvent => AnalyticsEvent::fromArray($item),
                $data,
            ),
        );
    }

    /**
     * Create from an array of AnalyticsEvent objects.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return self
     */
    public static function fromEvents(array $events): self
    {
        return new self($events);
    }

    /**
     * Create an empty collection.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Add an event to the collection, returning a new collection.
     *
     * @return self
     */
    public function add(AnalyticsEvent $event): self
    {
        return new self([...$this->events, $event]);
    }

    /**
     * Add multiple events, returning a new collection.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return self
     */
    public function addMany(array $events): self
    {
        return new self([...$this->events, ...$events]);
    }

    /**
     * Merge another collection into this one.
     *
     * @return self
     */
    public function merge(self $other): self
    {
        return new self([...$this->events, ...$other->events]);
    }

    /**
     * Filter events by name.
     *
     * @return self
     */
    public function byName(string $name): self
    {
        return new self(
            array_values(array_filter(
                $this->events,
                fn (AnalyticsEvent $e): bool => $e->name === $name,
            )),
        );
    }

    /**
     * Filter events matching a predicate.
     *
     * @param  callable(AnalyticsEvent): bool  $predicate
     * @return self
     */
    public function filter(callable $predicate): self
    {
        return new self(array_values(array_filter($this->events, $predicate)));
    }

    /**
     * Map events to a new collection via a transformer.
     *
     * @param  callable(AnalyticsEvent): AnalyticsEvent  $transformer
     * @return self
     */
    public function map(callable $transformer): self
    {
        return new self(array_map($transformer, $this->events));
    }

    /**
     * Get event names as a unique list.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique(array_map(
            fn (AnalyticsEvent $e): string => $e->name,
            $this->events,
        )));
    }

    /**
     * Group events by name.
     *
     * @return array<string, list<AnalyticsEvent>>
     */
    public function groupByName(): array
    {
        $groups = [];

        foreach ($this->events as $event) {
            $groups[$event->name][] = $event;
        }

        return $groups;
    }

    /**
     * Check if the collection is empty.
     */
    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    /**
     * Check if the collection is not empty.
     */
    public function isNotEmpty(): bool
    {
        return $this->events !== [];
    }

    /**
     * Get the first event or null.
     */
    public function first(): ?AnalyticsEvent
    {
        return $this->events[0] ?? null;
    }

    /**
     * Get the last event or null.
     */
    public function last(): ?AnalyticsEvent
    {
        return array_values(array_slice($this->events, -1))[0] ?? null;
    }

    /**
     * Get an event by index.
     */
    public function get(int $index): ?AnalyticsEvent
    {
        return $this->events[$index] ?? null;
    }

    /**
     * Take up to N events from the collection.
     *
     * @return self
     */
    public function take(int $count): self
    {
        return new self(array_slice($this->events, 0, $count));
    }

    /**
     * Skip the first N events.
     *
     * @return self
     */
    public function skip(int $count): self
    {
        return new self(array_slice($this->events, $count));
    }

    /**
     * Convert all events to arrays.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            fn (AnalyticsEvent $e): array => $e->toArray(),
            $this->events,
        );
    }

    /**
     * @return Traversable<int, AnalyticsEvent>
     */
    #[\Override]
    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->events);
    }

    /**
     * Count the events.
     */
    #[\Override]
    public function count(): int
    {
        return count($this->events);
    }
}
