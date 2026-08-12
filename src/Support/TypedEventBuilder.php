<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Type-safe event builder with compile-time event name validation.
 *
 * Provides a fluent API for constructing typed events with parameter validation
 * against the registered event catalog schemas. Ensures events conform to
 * expected structure before dispatch.
 *
 * @since 41.0.0
 *
 * @example
 * ```php
 * $event = TypedEventBuilder::for('purchase')
 *     ->param('transaction_id', 'txn_123')
 *     ->param('value', 99.99)
 *     ->param('currency', 'USD')
 *     ->param('items', $items)
 *     ->clientId($trackingId)
 *     ->userId($userId)
 *     ->priority('critical')
 *     ->build();
 *
 * Analytics::trackEvent($event);
 * ```
 */
final class TypedEventBuilder
{
    /** @var string The event name being built */
    private string $name;

    /** @var array<string, mixed> Event parameters */
    private array $params = [];

    /** @var string|null Client ID */
    private ?string $clientId = null;

    /** @var string|null User ID */
    private ?string $userId = null;

    /** @var string|null Event priority */
    private ?string $priority = null;

    /** @var string|null Event source */
    private ?string $source = null;

    /** @var list<string> Validation errors accumulated during building */
    private array $errors = [];

    /**
     * @param  string  $name  Event name
     */
    private function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Create a new typed event builder for a named event.
     *
     * @param  string  $name  Event name (should exist in EventCatalog for full validation)
     * @return self
     */
    public static function for(string $name): self
    {
        return new self($name);
    }

    /**
     * Create a builder for a catalog-validated event.
     *
     * Validates that the event name exists in the catalog and provides
     * type hints for required parameters based on the schema.
     *
     * @param  string  $name  Event name that must exist in EventCatalog
     * @return self
     *
     * @throws \InvalidArgumentException If the event name does not exist in the catalog
     */
    public static function catalogEvent(string $name): self
    {
        if (! EventCatalog::has($name)) {
            throw new \InvalidArgumentException(
                "Event '{$name}' does not exist in the EventCatalog. " .
                "Available events: " . implode(', ', array_slice(EventCatalog::names(), 0, 10)) .
                ' (+' . (EventCatalog::count() - 10) . ' more)',
            );
        }

        return new self($name);
    }

    /**
     * Add a parameter to the event.
     *
     * @param  string  $key  Parameter name
     * @param  mixed  $value  Parameter value
     * @return self
     */
    public function param(string $key, mixed $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Add multiple parameters at once.
     *
     * @param  array<string, mixed>  $params  Key-value pairs
     * @return self
     */
    public function params(array $params): self
    {
        foreach ($params as $key => $value) {
            $this->params[$key] = $value;
        }

        return $this;
    }

    /**
     * Set the client ID for the event.
     *
     * @return self
     */
    public function clientId(string $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    /**
     * Set the user ID for the event.
     *
     * @return self
     */
    public function userId(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Set the event priority.
     *
     * @param  'critical'|'normal'|'low'|'background'  $priority
     * @return self
     */
    public function priority(string $priority): self
    {
        $validPriorities = ['critical', 'normal', 'low', 'background'];

        if (! in_array($priority, $validPriorities, true)) {
            $this->errors[] = "Invalid priority '{$priority}'. Must be one of: " .
                implode(', ', $validPriorities);
        }

        $this->priority = $priority;

        return $this;
    }

    /**
     * Set the event source.
     *
     * @param  'api'|'server'|'client'|'webhook'|'replay'|'batch'  $source
     * @return self
     */
    public function source(string $source): self
    {
        $validSources = ['api', 'server', 'client', 'webhook', 'replay', 'batch'];

        if (! in_array($source, $validSources, true)) {
            $this->errors[] = "Invalid source '{$source}'. Must be one of: " .
                implode(', ', $validSources);
        }

        $this->source = $source;

        return $this;
    }

    /**
     * Merge params from an existing event (for replay/enrichment).
     *
     * @return self
     */
    public function mergeFrom(AnalyticsEvent $event): self
    {
        $this->params = array_merge($event->params, $this->params);

        if ($this->clientId === null && $event->clientId !== null) {
            $this->clientId = $event->clientId;
        }

        if ($this->userId === null && $event->userId !== null) {
            $this->userId = $event->userId;
        }

        if ($this->priority === null && $event->priority !== null) {
            $this->priority = $event->priority;
        }

        if ($this->source === null && $event->source !== null) {
            $this->source = $event->source;
        }

        return $this;
    }

    /**
     * Build the AnalyticsEvent DTO.
     *
     * @return AnalyticsEvent
     *
     * @throws \RuntimeException If validation errors were accumulated
     */
    public function build(): AnalyticsEvent
    {
        if ($this->errors !== []) {
            $errorMsg = implode('; ', $this->errors);
            throw new \RuntimeException(
                "Cannot build event '{$this->name}': {$errorMsg}",
            );
        }

        return new AnalyticsEvent(
            name: $this->name,
            params: $this->params,
            clientId: $this->clientId,
            userId: $this->userId,
            priority: $this->priority,
            source: $this->source,
        );
    }

    /**
     * Build the AnalyticsEvent DTO without throwing on validation errors.
     *
     * Returns the event even if there were validation warnings.
     * Use getErrors() to check for warnings after building.
     *
     * @return AnalyticsEvent
     */
    public function buildUnsafe(): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: $this->name,
            params: $this->params,
            clientId: $this->clientId,
            userId: $this->userId,
            priority: $this->priority,
            source: $this->source,
        );
    }

    /**
     * Get any validation errors accumulated during building.
     *
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if there are any validation errors.
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Get the event name being built.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the current parameters.
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Check if the event exists in the catalog.
     */
    public function isInCatalog(): bool
    {
        return EventCatalog::has($this->name);
    }

    /**
     * Get the catalog category for this event.
     */
    public function getCatalogCategory(): ?string
    {
        return EventCatalog::getCategory($this->name);
    }

    /**
     * Get a human-readable description of the event being built.
     *
     * Useful for debugging and logging.
     */
    public function describe(): string
    {
        $parts = ["TypedEvent(event={$this->name}"];

        if ($this->isInCatalog()) {
            $parts[] = "category={$this->getCatalogCategory()}";
        }

        $parts[] = 'params=' . count($this->params);

        if ($this->clientId !== null) {
            $parts[] = 'client=' . substr($this->clientId, 0, 8) . '...';
        }

        if ($this->userId !== null) {
            $parts[] = 'user=' . $this->userId;
        }

        if ($this->priority !== null) {
            $parts[] = "priority={$this->priority}";
        }

        $parts[] = ')';

        return implode(', ', $parts);
    }
}
