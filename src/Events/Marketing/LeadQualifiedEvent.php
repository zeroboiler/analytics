<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Marketing;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Marketing analytics event.
 *
 * @since 121.0.0
 */
final readonly class LeadQualifiedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $campaign  Marketing campaign name/ID
     * @param  string|null  $source  Marketing source (e.g. 'email', 'social', 'paid_search')
     * @param  string|null  $medium  Marketing medium (e.g. 'cpc', 'email', 'organic')
     * @param  array<string, mixed>  $extra  Additional event parameters
     */
    public function __construct(
        ?string $campaign = null,
        ?string $source = null,
        ?string $medium = null,
        array $extra = [],
    ){
        parent::__construct('lead_qualified', array_filter(array_merge([
            'campaign' => $campaign,
            'source' => $source,
            'medium' => $medium,
        ], $extra)));
    }
}
