<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Exceptions;

/**
 * Thrown when an invalid argument is passed to an analytics method.
 *
 * Replaces generic \InvalidArgumentException with a domain-specific
 * exception for better error handling and debugging.
 *
 * @see \ZeroBoiler\Analytics\Exceptions\AnalyticsException
 *
 * @since 62.0.0
 */
final class InvalidAnalyticsArgumentException extends AnalyticsException
{
    /**
     * Create an argument exception for a generic message.
     *
     * @param  string  $message  Human-readable error description
     * @param  int  $code  Application-specific error code
     * @param  \Throwable|null  $previous  The exception chain predecessor
     * @return self
     */
    public static function forMessage(string $message, int $code = 0, ?\Throwable $previous = null): self
    {
        return new self($message, $code, $previous);
    }
}
