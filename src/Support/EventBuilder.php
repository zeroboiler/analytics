<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Fluent event builder with catalog-aware validation and type safety.
 *
 * Provides a declarative, fluent API for constructing analytics events
 * with automatic catalog validation, provider name resolution, and
 * structured parameter building for common event types.
 *
 * @example
 *   $event = EventBuilder::make('purchase')
 *       ->param('transaction_id', 'TXN-123')
 *       ->param('value', 99.99)
 *       ->param('currency', 'USD')
 *       ->items([$item1, $item2])
 *       ->client($clientId)
 *       ->user($userId)
 *       ->priority('critical')
 *       ->build();
 *
 * @since 1.0.0
 */
final class EventBuilder
{
    private string $name;

    /** @var array<string, mixed> */
    private array $params = [];

    private ?string $clientId = null;

    private ?string $userId = null;

    private ?\DateTimeImmutable $timestamp = null;

    private ?string $priority = null;

    /** @var array<int, array<string, mixed>> */
    private array $items = [];

    private bool $validate = true;

    private ?string $source = null;

    private ?string $sourceId = null;

    private ?string $sessionId = null;

    private ?string $group = null;

    private function __construct(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Create a new event builder for the given event name.
     */
    public static function make(string $name): self
    {
        return new self($name);
    }

    /**
     * Create a builder from an existing catalog event entry.
     *
     * @param  string  $name  Event name (must exist in the catalog)
     * @return self|null  Null if the event is not in the catalog
     */
    public static function fromCatalog(string $name): ?self
    {
        $entry = EventCatalog::get($name);

        if ($entry === null) {
            return null;
        }

        return new self($name);
    }

    /**
     * Create a purchase event builder with required params pre-structured.
     *
     * @param  string  $transactionId  Unique transaction identifier
     * @param  float  $value  Total revenue
     * @param  string  $currency  ISO 4217 currency code
     */
    public static function purchase(string $transactionId, float $value, string $currency = 'USD'): self
    {
        return (new self('purchase'))
            ->param('transaction_id', $transactionId)
            ->param('value', $value)
            ->param('currency', $currency)
            ->priority('critical');
    }

    /**
     * Create a sign_up event builder with optional user context.
     *
     * @param  string|null  $method  Signup method (email, google, github, etc.)
     */
    public static function signUp(?string $method = null): self
    {
        $builder = (new self('sign_up'))->priority('critical');

        if ($method !== null) {
            $builder->param('signup_method', $method);
        }

        return $builder;
    }

    /**
     * Create a page_view event builder.
     *
     * @param  string  $title  Page title
     * @param  string  $location  Full URL
     * @param  string|null  $referrer  Referrer URL
     */
    public static function pageView(string $title = '', string $location = '', ?string $referrer = null): self
    {
        $builder = new self('page_view');

        if ($title !== '') {
            $builder->param('page_title', $title);
        }

        if ($location !== '') {
            $builder->param('page_location', $location);
        }

        if ($referrer !== null) {
            $builder->param('page_referrer', $referrer);
        }

        return $builder;
    }

    /**
     * Add a parameter to the event.
     *
     * @param  string  $key  Parameter key
     * @param  mixed  $value  Parameter value
     */
    public function param(string $key, mixed $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Merge an array of parameters into the event.
     *
     * @param  array<string, mixed>  $params  Parameters to merge
     */
    public function params(array $params): self
    {
        $this->params = array_merge($this->params, $params);

        return $this;
    }

    /**
     * Set the client tracking ID.
     */
    public function client(string $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    /**
     * Set the authenticated user ID.
     */
    public function user(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Set the event priority level.
     *
     * @param  'critical'|'high'|'medium'|'low'|'background'  $priority
     */
    public function priority(string $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Set the event timestamp.
     */
    public function timestamp(\DateTimeImmutable $timestamp): self
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    /**
     * Set the timestamp to now.
     */
    public function now(): self
    {
        $this->timestamp = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Add GA4-format items to the event.
     *
     * @param  array<int, array<string, mixed>>  $items  GA4 items array
     */
    public function items(array $items): self
    {
        $this->items = $items;

        return $this;
    }

    /**
     * Add a single GA4-format item to the event.
     *
     * @param  array{item_id: string, item_name?: string, item_category?: string, price?: float, quantity?: int}  $item
     */
    public function item(array $item): self
    {
        $this->items[] = $item;

        return $this;
    }

    /**
     * Enable or disable catalog validation when building.
     *
     * When enabled (default), the builder will validate that the event
     * name exists in the catalog and log a warning if it doesn't.
     */
    public function validate(bool $enabled): self
    {
        $this->validate = $enabled;

        return $this;
    }

    /**
     * Set the event origin source (api|server|client|webhook|replay|batch).
     *
     * @param  string  $source  Event origin identifier
     */
    public function source(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Set the event source ID for traceability.
     *
     * @param  string  $sourceId  Unique source identifier (e.g., request ID, job ID)
     */
    public function sourceId(string $sourceId): self
    {
        $this->sourceId = $sourceId;

        return $this;
    }

    /**
     * Set the session ID for session-scoped events.
     *
     * @param  string  $sessionId  Session identifier
     */
    public function sessionId(string $sessionId): self
    {
        $this->sessionId = $sessionId;

        return $this;
    }

    /**
     * Set the group/context ID for B2B or multi-tenant analytics.
     *
     * @param  string  $group  Group identifier (e.g., workspace ID, team ID)
     */
    public function group(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    /**
     * Build the AnalyticsEvent DTO.
     *
     * Validates the event name against the catalog (if validation enabled),
     * merges items into params, and returns an immutable AnalyticsEvent.
     *
     * @return AnalyticsEvent
     * @throws \InvalidArgumentException If the event name is empty
     */
    public function build(): AnalyticsEvent
    {
        if ($this->name === '') {
            throw new \ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException('Event name cannot be empty');
        }

        // Catalog validation (soft — logs warning, doesn't throw)
        if ($this->validate && ! EventCatalog::has($this->name)) {
            \Illuminate\Support\Facades\Log::warning("ZeroBoiler Analytics: Event '{$this->name}' is not in the catalog", [
                'hint' => 'Use EventCatalog::names() to see all registered events, or disable validation with ->validate(false)',
            ]);
        }

        // Merge items into params if present
        $params = $this->params;
        if ($this->items !== []) {
            $params['items'] = $this->items;
        }

        // Embed contextual identifiers into params
        if ($this->sourceId !== null) {
            $params['_source_id'] = $this->sourceId;
        }
        if ($this->sessionId !== null) {
            $params['_session_id'] = $this->sessionId;
        }
        if ($this->group !== null) {
            $params['_group'] = $this->group;
        }

        return new AnalyticsEvent(
            name: $this->name,
            params: $params,
            clientId: $this->clientId,
            userId: $this->userId,
            timestamp: $this->timestamp,
            priority: $this->priority,
            source: $this->source,
        );
    }

    /**
     * Build and immediately dispatch via the AnalyticsManager.
     *
     * Shortcut for build + dispatch in a single call.
     */
    public function dispatch(): void
    {
        $event = $this->build();

        try {
            $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
            $manager->trackEvent($event);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ZeroBoiler Analytics: EventBuilder::dispatch failed', [
                'event' => $this->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build and immediately queue via QueuedAnalyticsDispatcher.
     *
     * Shortcut for build + async dispatch.
     */
    public function dispatchAsync(): void
    {
        $event = $this->build();

        try {
            $dispatcher = app(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);
            $dispatcher->dispatch($event);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ZeroBoiler Analytics: EventBuilder::dispatchAsync failed, falling back to sync', [
                'event' => $this->name,
                'error' => $e->getMessage(),
            ]);

            // Fallback to synchronous dispatch
            try {
                $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
                $manager->trackEvent($event);
            } catch (\Throwable) {
                // Silent fail — already logged
            }
        }
    }

    /**
     * Get the event name being built.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the current parameters (without items, which are separate).
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Get the current items array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Check if the event name exists in the catalog.
     */
    public function isInCatalog(): bool
    {
        return EventCatalog::has($this->name);
    }

    /**
     * Get the catalog entry for the event being built.
     *
     * @return array{name: string, class: class-string, ga4: string, meta: string|null, posthog: string, plausible: string|null, category: string}|null
     */
    public function getCatalogEntry(): ?array
    {
        return EventCatalog::get($this->name);
    }

    /**
     * Get the resolved provider event names for this event.
     *
     * @return array{ga4: string, meta: string|null, posthog: string|null, plausible: string|null}
     */
    public function getProviderNames(): array
    {
        $entry = EventCatalog::get($this->name);

        if ($entry === null) {
            return [
                'ga4' => $this->name,
                'meta' => null,
                'posthog' => null,
                'plausible' => null,
            ];
        }

        return [
            'ga4' => $entry['ga4'] ?? $this->name,
            'meta' => $entry['meta'] ?? null,
            'posthog' => $entry['posthog'] ?? null,
            'plausible' => $entry['plausible'] ?? null,
        ];
    }

    /**
     * Get the AARRR category for this event.
     *
     * @return string
     */
    public function getAarrCategory(): string
    {
        return EventCatalog::eventCategory($this->name);
    }

    /**
     * Get the priority level for this event from the catalog.
     *
     * @return string
     */
    public function getCatalogPriority(): string
    {
        return EventCatalog::eventPriority($this->name);
    }
}
