<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks an error encountered by the user.
 *
 * GA4: (custom error event)
 * Meta: (custom)
 */
final readonly class ErrorEvent extends AnalyticsEvent
{
    /**
     * @param  string  $errorType  Error type (e.g. '404', '500', 'validation', 'network')
     * @param  string  $message  Error message or description
     * @param  string  $pagePath  Page where the error occurred
     * @param  bool|null  $fatal  Whether the error was fatal/unrecoverable
     */
    public function __construct(
        string $errorType = '',
        string $message = '',
        string $pagePath = '',
        ?bool $fatal = null,
    ) {
        parent::__construct('error', array_filter([
            'error_type' => $errorType,
            'message' => $message,
            'page_path' => $pagePath,
            'fatal' => $fatal,
        ], fn (mixed $v): bool => $v !== null));
    }
}
