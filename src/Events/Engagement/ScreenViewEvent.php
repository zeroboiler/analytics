<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Screen view event for multi-page / multi-screen SaaS applications.
 *
 * Use this to track navigation between distinct screens or views
 * within a single-page app (e.g. "Dashboard", "Settings", "Billing").
 * Complements page_view which tracks URL-based navigation.
 *
 * @since 1.0.0
 */
final class ScreenViewEvent extends AnalyticsEvent
{
    /**
     * @param  string  $screenName  Screen or view name (e.g. 'dashboard', 'settings', 'billing')
     * @param  string|null  $screenClass  Optional screen class/type (e.g. 'main', 'modal', 'sidebar')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $screenName,
        ?string $screenClass = null,
        array $params = [],
    ): void {
        parent::__construct(
            name: 'screen_view',
            params: array_filter([
                'screen_name' => $screenName,
                'screen_class' => $screenClass,
                ...$params,
            ]),
        );
    }
}
