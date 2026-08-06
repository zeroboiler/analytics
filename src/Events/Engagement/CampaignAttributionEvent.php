<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks UTM campaign attribution data.
 *
 * Fired when a user arrives with UTM parameters, allowing attribution
 * of downstream conversion events to specific campaigns.
 *
 * GA4: campaign_attribution
 */
final readonly class CampaignAttributionEvent extends AnalyticsEvent
{
    /**
     * @param  string  $source  UTM source
     * @param  string  $medium  UTM medium
     * @param  string  $campaign  UTM campaign name
     * @param  string|null  $term  UTM term
     * @param  string|null  $content  UTM content
     * @param  string|null  $landingPage  Landing page URL
     */
    public function __construct(
        string $source,
        string $medium,
        string $campaign,
        ?string $term = null,
        ?string $content = null,
        ?string $landingPage = null,
    ) {
        parent::__construct('campaign_attribution', array_filter([
            'utm_source' => $source,
            'utm_medium' => $medium,
            'utm_campaign' => $campaign,
            'utm_term' => $term,
            'utm_content' => $content,
            'landing_page' => $landingPage,
        ]));
    }
}
