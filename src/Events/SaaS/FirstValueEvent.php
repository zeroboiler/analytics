<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event dispatched when a user reaches their first "aha moment" / first value event.
 *
 * This is the critical activation signal in SaaS products — the moment a user
 * first experiences the core value proposition of your product. Examples:
 * - First report generated in an analytics tool
 * - First API call made in a developer platform
 * - First invoice sent in a billing tool
 * - First collaboration action in a team tool
 *
 * Tracks the time-to-value (TTV) metric used by industry tools like
 * Amplitude, Mixpanel, and Pendo for activation rate analysis.
 *
 * @since 22.0.0
 */
final readonly class FirstValueEvent extends AnalyticsEvent
{
    /**
     * Create a first value / aha moment event.
     *
     * @param  string  $action  The specific action that delivered first value (e.g., 'first_report_generated')
     * @param  array<string, mixed>  $params  Additional context (feature, category, etc.)
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     * @param  int|null  $timeToValue  Seconds from signup to first value (TTV metric)
     */
    public function __construct(
        public string $action = '',
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
        public ?int $timeToValue = null,
    ): void {
        parent::__construct(
            name: 'first_value',
            params: array_merge($params, [
                'action' => $action,
                'time_to_value' => $timeToValue,
            ]),
            clientId: $clientId,
            userId: $userId,
            priority: 'critical',
            source: 'server',
        );
    }
}
