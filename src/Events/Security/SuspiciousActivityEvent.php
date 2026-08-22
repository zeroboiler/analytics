<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Security;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when suspicious activity is detected.
 *
 * Covers brute-force attempts, unusual login patterns, permission escalation,
 * data exfiltration signals, and other security anomalies.
 *
 * @since 9.9.0
 */
final readonly class SuspiciousActivityEvent extends AnalyticsEvent
{
    /**
     * @param  string  $type  Activity type (brute_force, unusual_location, permission_escalation, data_exfiltration, etc.)
     * @param  string  $severity  Severity level (low, medium, high, critical)
     * @param  array<string, mixed>  $context  Additional context (ip, user_id, resource, details)
     */
    public function __construct(
        string $type = 'unusual_pattern',
        string $severity = 'medium',
        array $context = [],
    ){
        parent::__construct('suspicious_activity', array_filter([
            'type' => $type,
            'severity' => $severity,
            ...$context,
        ]));
    }
}
