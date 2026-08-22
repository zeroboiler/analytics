<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when an API rate limit is hit.
 *
 * Critical telemetry for understanding API usage patterns and identifying
 * power users who may benefit from plan upgrades. Combined with
 * UsageQuotaReachedEvent for comprehensive usage analytics.
 *
 * GA4: api_rate_limited (custom)
 * Meta: null (custom)
 *
 * @since 27.0.0
 */
final readonly class ApiRateLimitedEvent extends AnalyticsEvent
{
    /**
     * @param  string  $endpoint  The API endpoint that was rate-limited
     * @param  string|null  $method  HTTP method (GET, POST, etc.)
     * @param  int|null  $limit  Rate limit threshold that was exceeded
     * @param  string|null  $window  Rate limit window (per_minute, per_hour, per_day)
     * @param  string|null  $userId  Authenticated user ID (if available)
     */
    public function __construct(
        string $endpoint,
        ?string $method = null,
        ?int $limit = null,
        ?string $window = null,
        ?string $userId = null,
    ){
        parent::__construct('api_rate_limited', array_filter([
            'endpoint' => $endpoint,
            'method' => $method,
            'limit' => $limit,
            'window' => $window,
            'user_id' => $userId,
        ], fn (mixed $v): bool => $v !== null));
    }
}
