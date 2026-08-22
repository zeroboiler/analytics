<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Marketing;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user registers for a webinar or marketing event.
 *
 * GA4: webinar_registered
 * Meta: Lead
 *
 * @since 121.0.0
 */
final readonly class WebinarRegisteredEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $webinarName  Webinar/event name
     * @param  string|null  $campaign  Attributed marketing campaign
     * @param  string|null  $source  Registration source (e.g. 'landing_page', 'email', 'social')
     */
    public function __construct(
        ?string $webinarName = null,
        ?string $campaign = null,
        ?string $source = null,
    ){
        parent::__construct('webinar_registered', array_filter([
            'webinar_name' => $webinarName,
            'campaign' => $campaign,
            'source' => $source,
        ]));
    }
}
