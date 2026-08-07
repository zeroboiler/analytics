<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event debounce filter for high-frequency events.
 *
 * Suppresses rapid-fire duplicate events (e.g. scroll depth, resize)
 * within a configurable time window. Once a debounce window expires,
 * the last suppressed event is released for processing.
 *
 * This is particularly useful for client-side events like scroll_depth
 * and time_on_page that fire very frequently during user interaction.
 *
 * Usage:
 *   $debounce = new EventDebounceFilter(1000); // 1 second window
 *   $result = $debounce->process($event);
 *   if ($result !== null) {
 *       Analytics::trackEvent($result);
 *   }
 */
final class EventDebounceFilter
{
    /** @var array<string, int> Last dispatch timestamp per event name */
    private array $lastDispatch = [];

    /** @var array<string, AnalyticsEvent> Pending (suppressed) events per name */
    private array $pending = [];

    /** @var array<string, int> Pending dispatch timer IDs */
    private array $timers = [];

    /** @var int Minimum milliseconds between dispatches of the same event name */
    private int $debounceMs;

    /** @var int|null Current fake time (for testing, null = real time) */
    private ?int $testNow = null;

    /**
     * @param  int  $debounceMs  Minimum milliseconds between dispatches (default: 1000ms)
     */
    public function __construct(int $debounceMs = 1000): void
    {
        $this->debounceMs = $debounceMs;
    }

    /**
     * Process an event through the debounce filter.
     *
     * If the event was recently dispatched (within the debounce window),
     * it is suppressed and held as pending. When the debounce window
     * expires, the last pending event is returned.
     *
     * Events with different names are tracked independently.
     *
     * @param  AnalyticsEvent  $event  The event to process
     * @return AnalyticsEvent|null The event to dispatch, or null if suppressed
     */
    public function process(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $now = $this->testNow ?? (int) (microtime(true) * 1000);
        $name = $event->name;

        // Store as the latest pending event for this name
        $this->pending[$name] = $event;

        $lastDispatch = $this->lastDispatch[$name] ?? null;
        $elapsed = ($lastDispatch !== null) ? ($now - $lastDispatch) : PHP_INT_MAX;

        if ($elapsed >= $this->debounceMs) {
            // Debounce window expired — dispatch the latest pending event
            $this->lastDispatch[$name] = $now;
            $pending = $this->pending[$name];
            unset($this->pending[$name]);

            return $pending;
        }

        // Within debounce window — suppress
        return null;
    }

    /**
     * Flush all pending (suppressed) events immediately.
     *
     * Call this when you want to force-dispatch any held events,
     * for example when the user navigates away or the component unmounts.
     *
     * @return list<AnalyticsEvent> Flushed events
     */
    public function flush(): array
    {
        $flushed = array_values($this->pending);
        $now = $this->testNow ?? (int) (microtime(true) * 1000);

        foreach (array_keys($this->pending) as $name) {
            $this->lastDispatch[$name] = $now;
        }

        $this->pending = [];

        return $flushed;
    }

    /**
     * Check if an event name is currently debounced (pending).
     */
    public function isPending(string $eventName): bool
    {
        return isset($this->pending[$eventName]);
    }

    /**
     * Get the count of currently pending (suppressed) events.
     */
    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /**
     * Get the debounce window in milliseconds.
     */
    public function getDebounceMs(): int
    {
        return $this->debounceMs;
    }

    /**
     * Reset all internal state (for testing or reuse).
     */
    public function reset(): void
    {
        $this->lastDispatch = [];
        $this->pending = [];
        $this->timers = [];
    }

    /**
     * Set a fake "now" timestamp for deterministic testing.
     *
     * @param  int  $timestampMs  Fake current time in milliseconds
     */
    public function setTestNow(int $timestampMs): void
    {
        $this->testNow = $timestampMs;
    }
}
