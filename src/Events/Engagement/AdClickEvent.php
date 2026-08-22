<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Paid ad click event for tracking campaign ad engagement.
 *
 * Tracks clicks on paid advertisements (Google Ads, Meta Ads, etc.)
 * with ad metadata for ROI attribution.
 *
 * @see https://support.google.com/analytics/answer/7475744
 *
 * @since 1.0.0
 */
final readonly class AdClickEvent extends AnalyticsEvent
{
    /**
     * @param  non-empty-string  $platform  Ad platform (google, meta, tiktok, linkedin, twitter)
     * @param  non-empty-string  $campaignId  Campaign identifier
     * @param  non-empty-string  $adGroupId  Ad group identifier
     * @param  non-empty-string  $creativeId  Creative/copy identifier
     * @param  string|null  $placement  Placement position (top, sidebar, feed, etc.)
     * @param  string|null  $keyword  Matched keyword (for search ads)
     * @param  float|null  $cost  Cost-per-click in account currency
     */
    public function __construct(
        string $platform,
        string $campaignId,
        string $adGroupId,
        string $creativeId,
        ?string $placement = null,
        ?string $keyword = null,
        ?float $cost = null,
    ){
        parent::__construct(
            name: 'ad_click',
            params: array_filter([
                'platform' => $platform,
                'campaign_id' => $campaignId,
                'ad_group_id' => $adGroupId,
                'creative_id' => $creativeId,
                'placement' => $placement,
                'keyword' => $keyword,
                'cost' => $cost,
            ], fn (mixed $v): bool => $v !== null),
        );
    }
}
