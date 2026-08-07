<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventPriority;
use ZeroBoiler\Analytics\Services\EventPriorityGate;

/**
 * Pipeline filter that evaluates event priority and decides whether
 * the event should proceed through the rest of the pipeline.
 *
 * When priority gate allows the event, it passes through unchanged.
 * When the gate rejects the event (rate limit exceeded, budget exceeded),
 * the event is dropped and the filter returns null.
 *
 * Critical events always pass through this filter regardless of gate state.
 *
 * Usage in EventPipeline:
 *   $pipeline->pipe(new PriorityAwareFilter($priorityGate));
 *
 * @see \ZeroBoiler\Analytics\Services\EventPriorityGate
 * @see \ZeroBoiler\Analytics\DTO\EventPriority
 */
final class PriorityAwareFilter
{
    private EventPriorityGate $gate;

    /** @var int|null Number of events dropped by this filter instance */
    private ?int $droppedCount = null;

    public function __construct(EventPriorityGate $gate): void
    {
        $this->gate = $gate;
    }

    /**
     * Process an event through the priority filter.
     *
     * Returns the event if it passes the priority gate, or null if dropped.
     * When dropped, the event counter is incremented in the gate for diagnostics.
     *
     * @param  AnalyticsEvent  $event
     * @return AnalyticsEvent|null The event if allowed, null if dropped
     */
    public function __invoke(AnalyticsEvent $event): ?AnalyticsEvent
    {
        if ($this->gate->allows($event)) {
            return $event;
        }

        // Track dropped events
        $this->droppedCount = ($this->droppedCount ?? 0) + 1;

        return null;
    }

    /**
     * Get the number of events dropped by this filter.
     */
    public function getDroppedCount(): int
    {
        return $this->droppedCount ?? 0;
    }

    /**
     * Reset the dropped counter.
     */
    public function resetDroppedCount(): void
    {
        $this->droppedCount = null;
    }

    /**
     * Get the priority level that was resolved for a given event.
     *
     * Useful for logging and debugging.
     */
    public function resolvePriority(AnalyticsEvent $event): EventPriority
    {
        return $this->gate->resolvePriority($event);
    }

    /**
     * Check if the priority gate is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->gate->isEnabled();
    }
}
