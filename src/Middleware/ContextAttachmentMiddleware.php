<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Middleware;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Middleware that attaches context properties to all events.
 *
 * Merges a fixed set of properties (e.g. user_id, session_id, UTM params)
 * into every event's params before dispatch.
 */
final readonly class ContextAttachmentMiddleware implements AnalyticsMiddlewareInterface
{
    /** @var array<string, mixed> */
    private array $context;

    /**
     * @param  array<string, mixed>  $context  Properties to attach to every event
     */
    public function __construct(array $context)
    {
        $this->context = $context;
    }

    /** {@inheritdoc} */
    public function process(AnalyticsEvent $event): ?AnalyticsEvent
    {
        // Merge context into event params (event params take precedence)
        $mergedParams = array_merge($this->context, $event->params);

        return new AnalyticsEvent(
            name: $event->name,
            params: $mergedParams,
            clientId: $event->clientId,
            userId: $event->userId,
        );
    }

    /** {@inheritdoc} */
    public function priority(): int
    {
        return 20; // After validation, before filters
    }

    /** {@inheritdoc} */
    public function name(): string
    {
        return 'context_attachment';
    }
}
