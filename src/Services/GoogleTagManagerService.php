<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Trackers\GTMTracker;

/**
 * High-level service wrapper for Google Tag Manager.
 *
 * Provides convenience methods for pushing data to the GTM dataLayer,
 * including ecommerce data, user data, and conversion events.
 *
 * Resolved from the container as a singleton; receives the GTMTracker
 * instance from the AnalyticsManager.
 *
 * @since 1.0.0
 */
final class GoogleTagManagerService
{
    public function __construct(
        protected GTMTracker $tracker,
    ){}

    /**
     * Push data to the dataLayer.
     *
     * @param  array<string, mixed>  $data
     */
    public function push(array $data): void
    {
        $this->tracker->push($data);
    }

    /**
     * Push an ecommerce event to the dataLayer.
     *
     * @param  array<string, mixed>  $ecommerceData
     */
    public function pushEcommerceEvent(string $eventName, array $ecommerceData): void
    {
        $this->tracker->push([
            'event' => $eventName,
            'ecommerce' => $ecommerceData,
        ]);
    }

    /**
     * Push a user data to the dataLayer.
     *
     * @param  array<string, mixed>  $userData
     */
    public function pushUserData(array $userData): void
    {
        $this->tracker->push([
            'user' => $userData,
        ]);
    }

    /**
     * Push a conversion event.
     *
     * @param  array<string, mixed>  $params
     */
    public function pushConversion(string $label, array $params = []): void
    {
        $this->tracker->push(array_merge([
            'event' => 'conversion',
            'conversion_label' => $label,
        ], $params));
    }

    /**
     * Get the current dataLayer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDataLayer(): array
    {
        return $this->tracker->getDataLayer();
    }

    /**
     * Get the underlying tracker.
     */
    public function getTracker(): GTMTracker
    {
        return $this->tracker;
    }
}
