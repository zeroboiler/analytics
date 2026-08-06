<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Trackers\GTMTracker;

class GoogleTagManagerService
{
    public function __construct(
        protected GTMTracker $tracker,
    ) {}

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
