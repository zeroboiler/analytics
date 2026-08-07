<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Services\AnalyticsProfileService;
use ZeroBoiler\Analytics\Services\AttributionService;
use ZeroBoiler\Analytics\Services\TrackingPreferenceService;

/**
 * GDPR data erasure service for complete user data removal.
 *
 * Orchestrates deletion of all analytics data for a given user:
 * - Analytics profile (event counts, revenue, traits)
 * - Attribution data (first-touch, touch history)
 * - Tracking preferences
 *
 * Designed for GDPR "right to be forgotten" compliance.
 * Call AnalyticsManager::resetIdentity() separately for provider-side resets.
 */
final class GdprErasureService
{
    private AnalyticsProfileService $profileService;

    private AttributionService $attributionService;

    private TrackingPreferenceService $preferenceService;

    public function __construct(
        AnalyticsProfileService $profileService,
        AttributionService $attributionService,
        TrackingPreferenceService $preferenceService,
    ): void {
        $this->profileService = $profileService;
        $this->attributionService = $attributionService;
        $this->preferenceService = $preferenceService;
    }

    /**
     * Erase all analytics data for a user.
     *
     * @param  string  $userId  The user ID to erase
     * @param  string|null  $clientId  Optional client ID for attribution cleanup
     * @return array{profile_deleted: bool, attribution_deleted: bool, preferences_deleted: bool}
     */
    public function eraseUser(string $userId, ?string $clientId = null): array
    {
        $result = [
            'profile_deleted' => false,
            'attribution_deleted' => false,
            'preferences_deleted' => false,
        ];

        // Delete analytics profile
        try {
            $this->profileService->deleteProfile($userId);
            $result['profile_deleted'] = true;
        } catch (\Throwable) {
            // Continue with other deletions
        }

        // Delete attribution data (by client ID)
        if ($clientId !== null && $clientId !== '') {
            try {
                $this->attributionService->deleteAttribution($clientId);
                $result['attribution_deleted'] = true;
            } catch (\Throwable) {
                // Continue
            }
        }

        // Delete tracking preferences
        try {
            $this->preferenceService->optOut($userId);
            $result['preferences_deleted'] = true;
        } catch (\Throwable) {
            // Continue
        }

        return $result;
    }

    /**
     * Erase only attribution data for a client ID.
     *
     * @param  string  $clientId
     * @return bool
     */
    public function eraseAttribution(string $clientId): bool
    {
        if ($clientId === '') {
            return false;
        }

        try {
            $this->attributionService->deleteAttribution($clientId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
