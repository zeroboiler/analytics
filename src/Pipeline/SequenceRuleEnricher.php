<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventSequenceRuleEngine;

/**
 * Pipeline enricher that evaluates event sequence rules.
 *
 * Inspects each event passing through the pipeline against the
 * configured sequence rules. If violations are detected, attaches
 * them to the event's `_sequence_violations` parameter for downstream
 * processing (logging, alerting, or enrichment).
 *
 * @since 261.0.0
 */
final class SequenceRuleEnricher
{
    private EventSequenceRuleEngine $engine;

    public function __construct(EventSequenceRuleEngine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * Evaluate sequence rules and attach violations if found.
     *
     * @return AnalyticsEvent|null The event (unchanged or with violations), or null if filtered
     */
    public function __invoke(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $violations = $this->engine->evaluate($event);

        if ($violations === []) {
            return $event;
        }

        $params = array_merge($event->params, [
            '_sequence_violations' => $violations,
        ]);

        return new AnalyticsEvent(
            name: $event->name,
            params: $params,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
            category: $event->category,
            sessionId: $event->sessionId,
        );
    }
}
