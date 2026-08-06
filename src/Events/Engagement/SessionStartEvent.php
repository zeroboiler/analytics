<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks the beginning of a client-side analytics session.
 *
 * A session starts when the user first interacts with the app or
 * when initAll() is called. Used for session-level aggregation
 * and engagement metrics.
 *
 * GA4: session_start (custom)
 * Meta: (custom)
 */
final readonly class SessionStartEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $sessionId  Unique session identifier
     * @param  string|null  $pagePath  Entry page path
     * @param  string|null  $referrer  Session referrer
     * @param  string|null  $source  Traffic source (e.g. 'direct', 'organic', 'referral')
     */
    public function __construct(
        ?string $sessionId = null,
        ?string $pagePath = null,
        ?string $referrer = null,
        ?string $source = null,
    ) {
        parent::__construct('session_start', array_filter([
            'session_id' => $sessionId,
            'page_path' => $pagePath,
            'referrer' => $referrer,
            'source' => $source,
        ], fn (mixed $v): bool => $v !== null));
    }
}
