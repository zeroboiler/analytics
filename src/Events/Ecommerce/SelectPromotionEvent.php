<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Ecommerce;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user clicks on a promotion banner or link.
 *
 * Part of the GA4 e-commerce promotion funnel.
 *
 * GA4: select_promotion
 * Meta: (custom)
 */
final readonly class SelectPromotionEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $promotionId  Promotion ID
     * @param  string|null  $promotionName  Promotion name (e.g. 'Summer Sale')
     * @param  string|null  $creativeName  Creative name (e.g. 'hero_banner')
     * @param  string|null  $creativeSlot  Creative slot position (e.g. 'homepage_top')
     * @param  string|null  $locationId  Location ID (e.g. 'hero_banner_1')
     */
    public function __construct(
        ?string $promotionId = null,
        ?string $promotionName = null,
        ?string $creativeName = null,
        ?string $creativeSlot = null,
        ?string $locationId = null,
    ) {
        parent::__construct('select_promotion', array_filter([
            'promotion_id' => $promotionId,
            'promotion_name' => $promotionName,
            'creative_name' => $creativeName,
            'creative_slot' => $creativeSlot,
            'location_id' => $locationId,
        ]));
    }
}
