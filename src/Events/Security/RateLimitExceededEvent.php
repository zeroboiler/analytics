<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Security;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a rate limit is exceeded.
 *
 * Tracks API rate limit violations for security monitoring and
 * abuse detection. Includes the endpoint, client identifier, and
 * limit details.
 *
 * @since 9.9.0
 */
final class RateLimitExceededEvent extends AnalyticsEvent
{
    /**
     * @param  string  $endpoint  The endpoint or resource that was rate-limited
     * @param  string  $clientId  Client identifier (IP, API key hash, user ID)
     * @param  int  $limit  The rate limit threshold that was exceeded
     * @param  int  $window  The rate limit window in seconds
     */
    public function __construct(
        string $endpoint = '',
        string $clientId = '',
        int $limit = 60,
        int $window = 60,
    ) {
        parent::__construct('rate_limit_exceeded', [
            'endpoint' => $endpoint,
            'client_id' => $clientId,
            'limit' => $limit,
            'window' => $window,
        ]);
    }
}
