<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Middleware;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Interface for analytics event middleware.
 *
 * Middleware can intercept events before dispatch, modifying or
 * filtering them. Multiple middleware are executed in priority order
 * (lower number = higher priority, executed first).
 *
 * @see AnalyticsMiddlewareStack
 */
interface AnalyticsMiddlewareInterface
{
    /**
     * Process an analytics event.
     *
     * Return the (possibly modified) event to continue the chain,
     * or return null to filter/drop the event entirely.
     */
    public function process(AnalyticsEvent $event): ?AnalyticsEvent;

    /**
     * Get the middleware priority (lower = executed first).
     * Default is 100. Critical middleware should use 10-50.
     */
    public function priority(): int;

    /**
     * Get the middleware name for logging/debugging.
     */
    public function name(): string;
}
