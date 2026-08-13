<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Factory for creating typed analytics events from the event catalog.
 *
 * Provides static convenience methods for creating strongly-typed event objects
 * from the event catalog. Validates event names against the catalog and
 * returns properly constructed AnalyticsEvent DTOs with optional parameters.
 *
 * This complements EventBuilder with a more concise API for common patterns:
 * - Create events by catalog name with minimal boilerplate
 * - Auto-resolve provider names for dispatch hints
 * - Category-aware creation with defaults
 *
 * @since 9.4.0
 *
 * @see EventBuilder
 * @see EventCatalog
 *
 * @example
 *   // Create a purchase event from catalog
 *   $event = EventCatalogFactory::create('purchase', [
 *       'transaction_id' => 'TXN-123',
 *       'value' => 99.99,
 *       'currency' => 'USD',
 *   ]);
 *
 *   // Create with identity
 *   $event = EventCatalogFactory::create('sign_up', ['method' => 'google'])
 *       ->withClientId('client_123')
 *       ->withUserId('user_456')
 *       ->withPriority('critical');
 */
final class EventCatalogFactory
{
    /** @var array<string, mixed> Event parameters */
    private array $params = [];

    private ?string $clientId = null;

    private ?string $userId = null;

    private ?\DateTimeImmutable $timestamp = null;

    private ?string $priority = null;

    private function __construct(
        private readonly string $eventName,
    ): void {}

    /**
     * Create a factory for the given event name from the catalog.
     *
     * @param  string  $name  Event name (must exist in catalog)
     * @return self|null  Null if event is not in catalog
     */
    public static function create(string $name, array $params = []): ?self
    {
        if (! EventCatalog::has($name)) {
            return null;
        }

        $factory = new self($name);
        $factory->params = $params;

        return $factory;
    }

    /**
     * Create a factory without catalog validation.
     *
     * Use for custom events that are not in the catalog.
     *
     * @param  string  $name  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return self
     */
    public static function raw(string $name, array $params = []): self
    {
        $factory = new self($name);
        $factory->params = $params;

        return $factory;
    }

    /**
     * Set the client tracking ID.
     */
    public function withClientId(string $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    /**
     * Set the authenticated user ID.
     */
    public function withUserId(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Set both client and user IDs.
     */
    public function withIdentity(string $clientId, string $userId): self
    {
        $this->clientId = $clientId;
        $this->userId = $userId;

        return $this;
    }

    /**
     * Set the event timestamp.
     */
    public function withTimestamp(\DateTimeImmutable $timestamp): self
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    /**
     * Set the event priority.
     *
     * @param  'critical'|'normal'|'low'|'background'  $priority
     */
    public function withPriority(string $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Merge additional parameters into the event.
     *
     * @param  array<string, mixed>  $params
     */
    public function mergeParams(array $params): self
    {
        $this->params = array_merge($this->params, $params);

        return $this;
    }

    /**
     * Build the AnalyticsEvent DTO.
     */
    public function build(): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: $this->eventName,
            params: $this->params,
            clientId: $this->clientId,
            userId: $this->userId,
            timestamp: $this->timestamp,
            priority: $this->priority,
        );
    }

    /**
     * Get the event name.
     */
    public function getEventName(): string
    {
        return $this->eventName;
    }

    /**
     * Get the catalog entry for this event.
     *
     * @return array{name: string, class: class-string, ga4: string, meta: string|null, category: string}|null
     */
    public function getCatalogEntry(): ?array
    {
        return EventCatalog::get($this->eventName);
    }

    /**
     * Get the event category.
     */
    public function getCategory(): ?string
    {
        return EventCatalog::getCategory($this->eventName);
    }

    /**
     * Get the resolved GA4 event name for this event.
     */
    public function getGa4Name(): string
    {
        return EventCatalog::get($this->eventName)['ga4'] ?? $this->eventName;
    }

    /**
     * Get the resolved Meta Pixel event name for this event.
     */
    public function getMetaName(): ?string
    {
        return EventCatalog::get($this->eventName)['meta'] ?? null;
    }

    /**
     * Check if this event exists in the catalog.
     */
    public function isInCatalog(): bool
    {
        return EventCatalog::has($this->eventName);
    }

    /**
     * Get all ecommerce event names.
     *
     * @return list<string>
     */
    public static function ecommerceEventNames(): array
    {
        return \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::names();
    }

    /**
     * Get all SaaS event names.
     *
     * @return list<string>
     */
    public static function saasEventNames(): array
    {
        return \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::names();
    }

    /**
     * Get all engagement event names.
     *
     * @return list<string>
     */
    public static function engagementEventNames(): array
    {
        return \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::names();
    }

    /**
     * Get the total number of events in the catalog.
     */
    public static function catalogSize(): int
    {
        return EventCatalog::count();
    }

    /**
     * Build an AnalyticsEvent directly (static shorthand).
     *
     * @param  string  $name  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientId
     * @param  string|null  $userId
     * @return AnalyticsEvent
     */
    public static function event(
        string $name,
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: $name,
            params: $params,
            clientId: $clientId,
            userId: $userId,
        );
    }

    /**
     * Build a critical-priority AnalyticsEvent.
     *
     * Convenience for revenue and identity events that should never be dropped.
     *
     * @param  string  $name  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientId
     * @param  string|null  $userId
     * @return AnalyticsEvent
     */
    public static function critical(
        string $name,
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: $name,
            params: $params,
            clientId: $clientId,
            userId: $userId,
            priority: 'critical',
        );
    }
}
