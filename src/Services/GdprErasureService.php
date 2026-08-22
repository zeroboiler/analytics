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
 *
 * @since 1.0.0
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
    ){
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

        try {
            $this->profileService->deleteProfile($userId);
            $result['profile_deleted'] = true;
        } catch (\Throwable $e) {
            // Continue with other deletions
        }

        if ($clientId !== null && $clientId !== '') {
            try {
                $this->attributionService->deleteAttribution($clientId);
                $result['attribution_deleted'] = true;
            } catch (\Throwable $e) {
                // Continue
            }
        }

        try {
            $this->preferenceService->optOut($userId);
            $result['preferences_deleted'] = true;
        } catch (\Throwable $e) {
            // Continue
        }

        return $result;
    }

    /**
     * Export all analytics data for a user (DSAR / GDPR data portability).
     *
     * Collects analytics profile, attribution data, tracking preferences,
     * and consent history into a single exportable array.
     *
     * @param  string  $userId  The user ID to export
     * @param  string|null  $clientId  Optional client ID for attribution data
     * @return array{user_id: string, exported_at: string, profile: array<string, mixed>|null, attribution: array<string, mixed>|null, preferences: array<string, mixed>, consent_history: list<array<string, mixed>>, event_counts: array<string, int>|null}
     */
    public function exportUser(string $userId, ?string $clientId = null): array
    {
        $export = [
            'user_id' => $userId,
            'exported_at' => date('c'),
            'profile' => null,
            'attribution' => null,
            'preferences' => ['status' => 'unknown'],
            'consent_history' => [],
            'event_counts' => null,
        ];

        // Export analytics profile
        try {
            $export['profile'] = $this->profileService->getProfile($userId);
        } catch (\Throwable $e) {
            $export['profile'] = null;
        }

        // Export attribution data
        if ($clientId !== null && $clientId !== '') {
            try {
                $export['attribution'] = $this->attributionService->getAttributionSummary($clientId);
            } catch (\Throwable $e) {
                $export['attribution'] = null;
            }
        }

        // Export tracking preferences
        try {
            $export['preferences'] = [
                'status' => $this->preferenceService->isOptedOut($userId) ? 'opt_out' : 'opt_in',
                'has_preference' => $this->preferenceService->hasPreference($userId),
            ];
        } catch (\Throwable $e) {
            // Keep default
        }

        // Export event counts from profile if available
        if (is_array($export['profile'])) {
            $export['event_counts'] = [
                'total' => (int) ($export['profile']['event_counts']['total'] ?? 0),
                'by_type' => (array) ($export['profile']['event_counts'] ?? []),
            ];
        }

        return $export;
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
        } catch (\Throwable $e) {
            return false;
        }
    }
}
