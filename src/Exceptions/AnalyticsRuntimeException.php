<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Exceptions;

/**
 * Thrown when an analytics operation fails at runtime.
 *
 * Covers event processing failures, export errors, configuration
 * mismatches, and other unexpected runtime conditions.
 *
 * @since 62.0.0
 */
final class AnalyticsRuntimeException extends AnalyticsException
{
}
