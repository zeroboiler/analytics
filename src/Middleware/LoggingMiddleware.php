<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Middleware;

use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Middleware that logs all events passing through (for debugging).
 *
 * Typically used in debug/development mode. Always passes events through.
 */
final readonly class LoggingMiddleware implements AnalyticsMiddlewareInterface
{
    private bool $includeParams;

    /**
     * @param  bool  $includeParams  Whether to include event params in the log
     */
    public function __construct(bool $includeParams = true): void
    {
        $this->includeParams = $includeParams;
    }

    /** {@inheritdoc} */
    #[\Override]
    public function process(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $logData = [
            'event' => $event->name,
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
        ];

        if ($this->includeParams) {
            $logData['params'] = $event->params;
        }

        Log::debug('ZeroBoiler Analytics: event processed', $logData);

        return $event; // Always pass through
    }

    /** {@inheritdoc} */
    #[\Override]
    public function priority(): int
    {
        return 90; // Very low priority — log after everything else
    }

    /** {@inheritdoc} */
    #[\Override]
    public function name(): string
    {
        return 'logging';
    }
}
