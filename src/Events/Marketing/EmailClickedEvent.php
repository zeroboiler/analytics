<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Marketing;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a recipient clicks a link in a marketing email.
 *
 * GA4: email_clicked
 * Meta: ViewContent
 *
 * @since 121.0.0
 */
final readonly class EmailClickedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $campaign  Email campaign name/ID
     * @param  string|null  $url  Clicked URL
     * @param  string|null  $recipient  Recipient identifier (hashed/anonymized)
     * @param  string|null  $cta  Call-to-action text/label
     */
    public function __construct(
        ?string $campaign = null,
        ?string $url = null,
        ?string $recipient = null,
        ?string $cta = null,
    ){
        parent::__construct('email_clicked', array_filter([
            'campaign' => $campaign,
            'url' => $url,
            'recipient' => $recipient,
            'cta' => $cta,
        ]));
    }
}
