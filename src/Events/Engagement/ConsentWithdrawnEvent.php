<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a user withdraws consent for analytics/ads tracking.
 *
 * Part of the GDPR consent lifecycle. When this event fires, the
 * server should immediately stop tracking the user for withdrawn purposes.
 *
 * @see https://zeroboiler.dev/docs/analytics/privacy
 *
 * @since 1.0.0
 */
final readonly class ConsentWithdrawnEvent extends AnalyticsEvent
{
    /**
     * @param  string  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, string>  $purposes  Withdrawn consent purposes
     * @param  string|null  $method  How consent was withdrawn (banner, settings, api)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $clientId,
        ?string $userId = null,
        array $purposes = [],
        ?string $method = null,
        array $params = [],
    ){
        parent::__construct(
            name: 'consent_withdrawn',
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
