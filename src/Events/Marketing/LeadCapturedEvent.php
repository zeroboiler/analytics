<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Marketing;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a new lead is captured through a marketing channel.
 *
 * GA4: generate_lead
 * Meta: Lead
 *
 * @since 121.0.0
 */
final readonly class LeadCapturedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $source  Lead source (e.g. 'form', 'call', 'chat', 'event')
     * @param  string|null  $campaign  Marketing campaign attribution
     * @param  string|null  $formId  Form identifier (if captured via form)
     * @param  string|null  $landingPage  Landing page URL
     */
    public function __construct(
        ?string $source = null,
        ?string $campaign = null,
        ?string $formId = null,
        ?string $landingPage = null,
    ){
        parent::__construct('lead_captured', array_filter([
            'source' => $source,
            'campaign' => $campaign,
            'form_id' => $formId,
            'landing_page' => $landingPage,
        ]));
    }
}
