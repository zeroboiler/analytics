<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a JavaScript error caught by the client-side error handler.
 *
 * Captures unhandled errors and unhandled promise rejections
 * from the browser window. Distinguished from server-side ErrorEvent
 * by the source parameter.
 *
 * GA4: js_error (custom)
 * Meta: (custom)
 */
final readonly class JSErrorEvent extends AnalyticsEvent
{
    /**
     * @param  string  $message  Error message text
     * @param  string|null  $source  File/script URL where the error occurred
     * @param  int|null  $line  Line number in the source file
     * @param  int|null  $col  Column number in the source file
     * @param  string|null  $errorType  Error type classification (unhandled, rejection, custom)
     * @param  string|null  $pagePath  Page where the error occurred
     * @param  bool|null  $fatal  Whether the error was fatal
     */
    public function __construct(
        string $message,
        ?string $source = null,
        ?int $line = null,
        ?int $col = null,
        ?string $errorType = null,
        ?string $pagePath = null,
        ?bool $fatal = null,
    ) {
        parent::__construct('js_error', array_filter([
            'error_message' => $message,
            'error_source' => $source,
            'error_line' => $line,
            'error_col' => $col,
            'error_type' => $errorType,
            'page_path' => $pagePath,
            'fatal' => $fatal,
        ], fn (mixed $v): bool => $v !== null));
    }
}
