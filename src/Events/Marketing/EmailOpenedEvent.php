<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Marketing;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a marketing email is opened by the recipient.
 *
 * GA4: email_opened
 * Meta: ViewContent
 *
 * @since 121.0.0
 */
final readonly class EmailOpenedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $campaign  Email campaign name/ID
     * @param  string|null  $recipient  Recipient identifier (hashed/anonymized)
     * @param  string|null  $template  Email template name
     */
    public function __construct(
        ?string $campaign = null,
        ?string $recipient = null,
        ?string $template = null,
    ){
        parent::__construct('email_opened', array_filter([
            'campaign' => $campaign,
            'recipient' => $recipient,
            'template' => $template,
        ]));
    }
}
