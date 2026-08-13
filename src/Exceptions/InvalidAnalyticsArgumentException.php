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
 * @since 62.0.0
 */
final class InvalidAnalyticsArgumentException extends AnalyticsException
{
}
