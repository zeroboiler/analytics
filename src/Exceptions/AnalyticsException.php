<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Exceptions;

use Exception;

/**
 * Base exception for all analytics domain errors.
 *
 * Provides a consistent root for the analytics exception hierarchy,
 * enabling callers to catch all analytics-specific errors with a single
 * catch block while allowing fine-grained handling of specific subtypes.
 *
 * @since 62.0.0
 */
abstract class AnalyticsException extends Exception
{
    /**
     * Create an analytics exception with an optional previous cause.
     *
     * @param  string  $message  Human-readable error description
     * @param  int  $code  Application-specific error code (default: 0)
     * @param  \Throwable|null  $previous  The exception chain predecessor
     */
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null): void
    {
        parent::__construct($message, $code, $previous);
    }
}
