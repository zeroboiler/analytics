<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Marketing;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a marketing email is sent to a recipient.
 *
 * GA4: email_sent
 *
 * @since 121.0.0
 */
final readonly class EmailSentEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $campaign  Email campaign name/ID
     * @param  string|null  $subject  Email subject line
     * @param  string|null  $recipient  Recipient identifier (hashed/anonymized)
     * @param  string|null  $template  Email template name
     */
    public function __construct(
        ?string $campaign = null,
        ?string $subject = null,
        ?string $recipient = null,
        ?string $template = null,
    ){
        parent::__construct('email_sent', array_filter([
            'campaign' => $campaign,
            'subject' => $subject,
            'recipient' => $recipient,
            'template' => $template,
        ]));
    }
}
