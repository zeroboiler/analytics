<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Marketing;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user shares a referral link.
 *
 * GA4: share
 * Meta: Share
 *
 * @since 121.0.0
 */
final readonly class ReferralLinkSharedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $channel  Sharing channel (e.g. 'email', 'social', 'direct')
     * @param  string|null  $referrerCode  Referrer's unique code
     * @param  string|null  $campaign  Associated campaign
     */
    public function __construct(
        ?string $channel = null,
        ?string $referrerCode = null,
        ?string $campaign = null,
    ){
        parent::__construct('referral_link_shared', array_filter([
            'channel' => $channel,
            'referrer_code' => $referrerCode,
            'campaign' => $campaign,
        ]));
    }
}
