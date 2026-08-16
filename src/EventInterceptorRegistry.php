<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Global event interceptor registry.
 *
 * Allows registering before/after hooks that run on every dispatched event.
 * Interceptors can modify, filter, or observe events before/after
 * they are sent to analytics providers.
 *
 * Use cases:
 * - Enrich events with session data
 * - Filter events based on business rules
 * - Log events for audit/compliance
 * - Transform event params for specific providers
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 *
 * @since 1.0.0
 */
final class EventInterceptorRegistry
{
    /** @var array<int, callable(AnalyticsEvent): AnalyticsEvent|null> */
    private array $beforeInterceptors = [];

    /** @var array<int, callable(AnalyticsEvent, bool): void> */
    private array $afterInterceptors = [];

    /**
     * Register a before-dispatch interceptor.
     *
     * Receives the event before it is dispatched to providers.
     * Return the (possibly modified) event to continue dispatch.
     * Return null to cancel dispatch entirely.
     *
     * @param  callable(AnalyticsEvent): AnalyticsEvent|null  $interceptor
     */
    public function before(callable $interceptor): void
    {
        $this->beforeInterceptors[] = $interceptor;
    }

    /**
     * Register an after-dispatch interceptor.
     *
     * Receives the event after dispatch and whether it was successful.
     * Use for logging, side effects, or post-dispatch processing.
     *
     * @param  callable(AnalyticsEvent, bool): void  $interceptor
     */
    public function after(callable $interceptor): void
    {
        $this->afterInterceptors[] = $interceptor;
    }

    /**
     * Run all before-interceptors on an event.
     *
     * @param  AnalyticsEvent  $event
     * @return AnalyticsEvent|null  The processed event, or null if filtered
     */
    public function runBefore(AnalyticsEvent $event): ?AnalyticsEvent
    {
        foreach ($this->beforeInterceptors as $interceptor) {
            $result = $interceptor($event);

            if ($result === null) {
                return null;
            }

            if ($result instanceof AnalyticsEvent) {
                $event = $result;
            }
        }

        return $event;
    }

    /**
     * Run all after-interceptors on a dispatched event.
     *
     * @param  AnalyticsEvent  $event
     * @param  bool  $success  Whether the dispatch was successful
     */
    public function runAfter(AnalyticsEvent $event, bool $success): void
    {
        foreach ($this->afterInterceptors as $interceptor) {
            try {
                $interceptor($event, $success);
            } catch (\Throwable) {
                // After-interceptors must not break the dispatch chain
            }
        }
    }

    /**
     * Get the count of registered before-interceptors.
     */
    public function beforeCount(): int
    {
        return count($this->beforeInterceptors);
    }

    /**
     * Get the count of registered after-interceptors.
     */
    public function afterCount(): int
    {
        return count($this->afterInterceptors);
    }

    /**
     * Clear all registered interceptors.
     */
    public function flush(): void
    {
        $this->beforeInterceptors = [];
        $this->afterInterceptors = [];
    }
}
