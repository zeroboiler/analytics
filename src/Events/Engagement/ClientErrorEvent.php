<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Client error event — tracks client-side errors with structured context.
 *
 * Extends the base ErrorEvent with additional client-specific fields:
 * source file, line number, column number, error stack trace, and
 * whether the error was handled or unhandled. Used by error tracking
 * dashboards and SRE alerting.
 *
 * @since 93.0.0
 */
final readonly class ClientErrorEvent extends AnalyticsEvent
{
    /**
     * Create a new client error event.
     *
     * @param  string  $message  Error message
     * @param  string  $type  Error type (e.g., 'TypeError', 'ReferenceError', 'NetworkError')
     * @param  bool  $unhandled  Whether the error was unhandled (crashed the app)
     * @param  array<string, mixed>  $params  Additional parameters (filename, lineno, colno, stack, etc.)
     */
    public function __construct(
        string $message,
        string $type = 'Error',
        bool $unhandled = false,
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ){
        parent::__construct(
            name: 'client_error',
            params: array_merge($params, [
                'error_message' => $message,
                'error_type' => $type,
                'unhandled' => $unhandled,
            ]),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
