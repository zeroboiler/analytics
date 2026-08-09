<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Bus;

use Closure;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * In-process event bus for decoupled analytics event processing.
 *
 * Provides a lightweight publish/subscribe pattern that allows application
 * code to react to analytics events without coupling to the dispatch layer.
 * Subscribers receive events synchronously in registration order.
 *
 * Use cases:
 * - Auto-enrich events with application context before dispatch
 * - Trigger side effects (CRM sync, webhook notifications, cache updates)
 * - Build custom analytics pipelines without modifying the core tracker
 *
 * Thread-safe for single-process PHP execution (not for multi-process/shared-memory).
 *
 * @version 5.4.0
 *
 * @since 1.0.0
 */
final class AnalyticsEventBus
{
    /** @var array<string, list<Closure(AnalyticsEvent): void>> */
    private array $subscribers = [];

    /** @var array<string, list<Closure(AnalyticsEvent): AnalyticsEvent>> */
    private array $middleware = [];

    /** @var list<Closure(AnalyticsEvent): void> */
    private array $globalSubscribers = [];

    /** @var bool */
    private bool $dispatching = false;

    /** @var list<AnalyticsEvent> */
    private array $queuedDuringDispatch = [];

    /**
     * Subscribe to a specific analytics event.
     *
     * Subscriber receives the event and can perform side effects
     * (logging, caching, external API calls, etc.).
     *
     * @param  string  $eventName  Event name to listen for ('*' for all events)
     * @param  Closure(AnalyticsEvent): void  $handler  Subscriber callback
     */
    public function subscribe(string $eventName, Closure $handler): void
    {
        $this->subscribers[$eventName][] = $handler;
    }

    /**
     * Subscribe to all analytics events (wildcard).
     *
     * Global subscribers receive every event regardless of name.
     *
     * @param  Closure(AnalyticsEvent): void  $handler
     */
    public function subscribeAll(Closure $handler): void
    {
        $this->globalSubscribers[] = $handler;
    }

    /**
     * Register a middleware that can modify the event before subscribers receive it.
     *
     * Middleware receives the event and MUST return the (possibly modified) event.
     * Multiple middleware run in registration order — each receives the output
     * of the previous.
     *
     * @param  string  $eventName  Event name to filter ('*' for all events)
     * @param  Closure(AnalyticsEvent): AnalyticsEvent  $middleware
     */
    public function addMiddleware(string $eventName, Closure $middleware): void
    {
        $this->middleware[$eventName][] = $middleware;
    }

    /**
     * Publish an event to all matching subscribers.
     *
     * Events flow through registered middleware first, then to named
     * subscribers, and finally to global subscribers.
     *
     * Re-entrant: if a subscriber publishes another event during dispatch,
     * the nested event is queued and dispatched after the current event
     * completes (prevents infinite recursion).
     *
     * @param  AnalyticsEvent  $event  The analytics event to publish
     */
    public function publish(AnalyticsEvent $event): void
    {
        // Apply middleware
        $processedEvent = $this->applyMiddleware($event);

        if ($this->dispatching) {
            // Prevent infinite recursion — queue for after current dispatch
            $this->queuedDuringDispatch[] = $processedEvent;

            return;
        }

        $this->dispatching = true;

        try {
            $this->notifyNamedSubscribers($processedEvent);
            $this->notifyGlobalSubscribers($processedEvent);
        } finally {
            $this->dispatching = false;
        }

        // Flush any events queued during dispatch
        $queued = $this->queuedDuringDispatch;
        $this->queuedDuringDispatch = [];

        foreach ($queued as $queuedEvent) {
            $this->publish($queuedEvent);
        }
    }

    /**
     * Publish multiple events sequentially.
     *
     * @param  list<AnalyticsEvent>  $events
     */
    public function publishMany(array $events): void
    {
        foreach ($events as $event) {
            $this->publish($event);
        }
    }

    /**
     * Remove all subscribers and middleware for a specific event.
     *
     * @param  string  $eventName
     */
    public function forget(string $eventName): void
    {
        unset($this->subscribers[$eventName]);
        unset($this->middleware[$eventName]);
    }

    /**
     * Remove all subscribers and middleware.
     */
    public function flush(): void
    {
        $this->subscribers = [];
        $this->middleware = [];
        $this->globalSubscribers = [];
        $this->queuedDuringDispatch = [];
    }

    /**
     * Get the count of subscribers for a specific event.
     *
     * @param  string  $eventName
     */
    public function subscriberCount(string $eventName): int
    {
        return count($this->subscribers[$eventName] ?? []);
    }

    /**
     * Get the total count of all subscribers including global.
     */
    public function totalSubscriberCount(): int
    {
        $named = array_sum(array_map(count(...), $this->subscribers));

        return $named + count($this->globalSubscribers);
    }

    /**
     * Get all registered event names that have subscribers.
     *
     * @return list<string>
     */
    public function registeredEvents(): array
    {
        return array_keys($this->subscribers);
    }

    /**
     * Check if any subscribers are registered for an event.
     */
    public function hasSubscribers(string $eventName): bool
    {
        return isset($this->subscribers[$eventName]) && count($this->subscribers[$eventName]) > 0;
    }

    /**
     * Check if any global subscribers are registered.
     */
    public function hasGlobalSubscribers(): bool
    {
        return count($this->globalSubscribers) > 0;
    }

    /**
     * Apply registered middleware to an event.
     *
     * @return AnalyticsEvent
     */
    private function applyMiddleware(AnalyticsEvent $event): AnalyticsEvent
    {
        $processed = $event;

        // Apply wildcard middleware first
        foreach ($this->middleware['*'] ?? [] as $middleware) {
            $processed = $middleware($processed);
        }

        // Apply event-specific middleware
        foreach ($this->middleware[$event->name] ?? [] as $middleware) {
            $processed = $middleware($processed);
        }

        return $processed;
    }

    /**
     * Notify named subscribers for a specific event.
     *
     * @param  AnalyticsEvent  $event
     */
    private function notifyNamedSubscribers(AnalyticsEvent $event): void
    {
        foreach ($this->subscribers[$event->name] ?? [] as $subscriber) {
            $subscriber($event);
        }

        // Also notify wildcard subscribers
        foreach ($this->subscribers['*'] ?? [] as $subscriber) {
            $subscriber($event);
        }
    }

    /**
     * Notify global (subscribeAll) subscribers.
     *
     * @param  AnalyticsEvent  $event
     */
    private function notifyGlobalSubscribers(AnalyticsEvent $event): void
    {
        foreach ($this->globalSubscribers as $subscriber) {
            $subscriber($event);
        }
    }
}
