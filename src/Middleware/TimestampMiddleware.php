<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Middleware;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Middleware that adds an auto-generated timestamp to events.
 *
 * Optionally overwrites any existing timestamp param.
 *
 * @since 1.0.0
 */
final readonly class TimestampMiddleware implements AnalyticsMiddlewareInterface
{
    private bool $overwrite;

    private string $paramName;

    /**
     * @param  bool  $overwrite  Whether to overwrite existing timestamp params
     * @param  string  $paramName  The param name to use for the timestamp
     */
    public function __construct(bool $overwrite = false, string $paramName = 'timestamp'): void
    {
        $this->overwrite = $overwrite;
        $this->paramName = $paramName;
    }

    /** {@inheritdoc} */
    public function process(AnalyticsEvent $event): ?AnalyticsEvent
    {
        if (! $this->overwrite && isset($event->params[$this->paramName])) {
            return $event;
        }

        $params = $event->params;
        $params[$this->paramName] = now()->toIso8601String();

        return new AnalyticsEvent(
            name: $event->name,
            params: $params,
            clientId: $event->clientId,
            userId: $event->userId,
        );
    }

    /** {@inheritdoc} */
    public function priority(): int
    {
        return 80; // Low priority — add timestamp near the end
    }

    /** {@inheritdoc} */
    public function name(): string
    {
        return 'timestamp';
    }
}
