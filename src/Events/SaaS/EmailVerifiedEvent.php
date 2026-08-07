<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks email verification.
 *
 * GA4: email_verified (custom)
 * Meta: EmailVerified (custom)
 */
final readonly class EmailVerifiedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $method  Verification method ('link', 'otp', 'admin')
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(?string $method = null, array $metadata = []): void
: void {
        parent::__construct('email_verified', array_filter([
            'method' => $method,
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
