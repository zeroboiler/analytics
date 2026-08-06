<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Middleware;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

/**
 * Middleware that validates events against the schema registry.
 *
 * In strict mode, events with invalid params are dropped.
 * In permissive mode, events are sanitized but still dispatched.
 */
final readonly class SchemaValidationMiddleware implements AnalyticsMiddlewareInterface
{
    private EventSchemaRegistry $registry;

    private bool $strictMode;

    /**
     * @param  EventSchemaRegistry  $registry  Schema registry
     * @param  bool  $strictMode  If true, drop invalid events; if false, sanitize and continue
     */
    public function __construct(EventSchemaRegistry $registry, bool $strictMode = false)
    {
        $this->registry = $registry;
        $this->strictMode = $strictMode;
    }

    public function process(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $result = $this->registry->validate($event->name, $event->params);

        if (! $result['valid'] && $this->strictMode) {
            // Drop event in strict mode
            return null;
        }

        // Return sanitized event
        if (! empty($result['sanitized']) && $result['sanitized'] !== $event->params) {
            return new AnalyticsEvent(
                name: $event->name,
                params: $result['sanitized'],
                clientId: $event->clientId,
                userId: $event->userId,
            );
        }

        return $event;
    }

    public function priority(): int
    {
        return 10; // High priority — validate early
    }

    public function name(): string
    {
        return 'schema_validation';
    }
}
