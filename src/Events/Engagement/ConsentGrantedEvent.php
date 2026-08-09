<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a user grants consent for analytics/ads tracking.
 *
 * Part of the GDPR consent lifecycle. Captures which purposes
 * were granted and the method of consent (banner, settings page, API).
 *
 * @see https://zeroboiler.dev/docs/analytics/privacy
 */
final class ConsentGrantedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID (null for anonymous)
     * @param  array<string, string>  $purposes  Granted consent purposes (e.g. ['analytics', 'marketing'])
     * @param  string|null  $method  How consent was granted (banner, settings, api, default)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $clientId,
        ?string $userId = null,
        array $purposes = [],
        ?string $method = null,
        array $params = [],
    ): void {
        parent::__construct(
            name: 'consent_granted',
            params: array_merge([
                'purposes' => $purposes,
                'purpose_count' => count($purposes),
                'method' => $method,
            ], $params),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
