<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

/**
 * Pipeline filter that validates events against registered schemas.
 *
 * When a schema exists for the event name, validates required parameters.
 * Events missing required params are still dispatched but with a warning
 * flag attached. This is non-blocking by design — analytics events should
 * not break the user experience.
 *
 * @see EventSchemaRegistry
 *
 * @since 1.0.0
 */
final class SchemaEnricher
{
    private EventSchemaRegistry $registry;

    private bool $strict;

    /**
     * @param  EventSchemaRegistry  $registry  Schema registry instance
     * @param  bool  $strict  If true, drop events with schema violations; if false, attach warning flag
     */
    public function __construct(EventSchemaRegistry $registry, bool $strict = false): void
    {
        $this->registry = $registry;
        $this->strict = $strict;
    }

    /**
     * Validate and enrich the event with schema information.
     *
     * If a schema exists for the event:
     * - Validates required parameters
     * - Attaches `_schema_valid` flag (true/false) to params
     * - Attaches `_schema_errors` list (if any violations)
     * - In strict mode, drops events that fail validation
     *
     * If no schema exists, the event passes through unchanged.
     *
     * @return AnalyticsEvent|null The enriched event, or null if dropped in strict mode
     */
    public function __invoke(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $schema = $this->registry->get($event->name);

        if ($schema === null) {
            return $event; // No schema — pass through
        }

        $validation = $this->registry->validate($event->name, $event->params);
        $valid = (bool) ($validation['valid'] ?? true);
        $errors = (array) ($validation['errors'] ?? []);

        if (! $valid && $this->strict) {
            return null; // Drop in strict mode
        }

        $enrichedParams = $event->params;
        $enrichedParams['_schema_valid'] = $valid;

        if (! $valid && ! empty($errors)) {
            $enrichedParams['_schema_errors'] = $errors;
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: $enrichedParams,
            clientId: $event->clientId,
            userId: $event->userId,
        );
    }
}
