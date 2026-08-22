<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Cross-provider identity synchronization service.
 *
 * When a user is identified (login, register, or explicit identify call),
 * this service ensures user identity and traits are propagated to all
 * 10 enabled analytics providers simultaneously.
 *
 * Supports:
 * - User ID ↔ client ID linking for cross-device identity resolution
 * - User properties/traits sync to GA4, Meta CAPI, PostHog, Mixpanel, Amplitude
 * - Alias/merge for identity unification (PostHog $create_alias, Mixpanel $merge)
 * - Configurable per-provider trait mapping
 *
 * @see \ZeroBoiler\Analytics\Tracking\UserIdentityTracker
 *
 * @since 35.0.0
 */
final class CrossProviderIdentityService
{
    private AnalyticsManager $manager;

    private ConfigRepository $config;

    private bool $enabled;

    /** @var array<string, bool> Per-provider sync toggles */
    private array $providerToggles;

    /**
     * @param  AnalyticsManager  $manager
     * @param  ConfigRepository  $config
     */
    public function __construct(AnalyticsManager $manager, ConfigRepository $config){
        $this->manager = $manager;
        $this->config = $config;

        $crossProviderConfig = $config->get('zeroboiler.analytics.cross_provider_identity', []);
        /** @var array{enabled?: bool, provider_sync?: array<string, bool>} $crossProviderConfig */
        $this->enabled = (bool) ($crossProviderConfig['enabled'] ?? true);
        $this->providerToggles = $crossProviderConfig['provider_sync'] ?? [];
    }

    /**
     * Synchronize user identity across all enabled providers.
     *
     * Fires provider-specific identify/alias events so each provider
     * can link the client-side tracking ID with the authenticated user ID.
     *
     * @param  string  $userId  Authenticated user ID
     * @param  string  $clientId  Client-side tracking ID (cookie/header)
     * @param  array<string, mixed>  $traits  User properties to sync (name, email, plan, etc.)
     */
    public function syncIdentity(string $userId, string $clientId, array $traits = []): void
    {
        if (! $this->enabled) {
            return;
        }

        // GA4: set user_id on future events
        if ($this->isProviderEnabled('ga4')) {
            $this->identifyGA4($userId, $traits);
        }

        // Meta Pixel CAPI: set user data for advanced matching
        if ($this->isProviderEnabled('meta')) {
            $this->identifyMeta($userId, $traits);
        }

        // PostHog: $identify + $create_alias
        if ($this->isProviderEnabled('posthog')) {
            $this->identifyPostHog($userId, $clientId, $traits);
        }

        // Mixpanel: $identify + $set + $merge
        if ($this->isProviderEnabled('mixpanel')) {
            $this->identifyMixpanel($userId, $clientId, $traits);
        }

        // Amplitude: identify + set user properties
        if ($this->isProviderEnabled('amplitude')) {
            $this->identifyAmplitude($userId, $traits);
        }

        // TikTok: set user_id for advanced matching
        if ($this->isProviderEnabled('tiktok')) {
            $this->identifyTiktok($userId);
        }

        // LinkedIn: set user_id for conversions tracking
        if ($this->isProviderEnabled('linkedin')) {
            $this->identifyLinkedin($userId);
        }

        // Plausible: server-side identity (no client-side user props)
        if ($this->isProviderEnabled('plausible')) {
            $this->identifyPlausible($userId);
        }

        // Generic identify event for providers without specific identity APIs
        $this->fireGenericIdentify($userId, $clientId, $traits);
    }

    /**
     * Merge two identities (e.g., anonymous → authenticated).
     *
     * Used when a previously anonymous user creates an account or logs in,
     * and their historical events need to be attributed to their new user ID.
     *
     * @param  string  $previousId  Previous anonymous/identity ID
     * @param  string  $newId  New authenticated user ID
     */
    public function mergeIdentity(string $previousId, string $newId): void
    {
        if (! $this->enabled) {
            return;
        }

        // PostHog: $merge
        if ($this->isProviderEnabled('posthog')) {
            $this->manager->trackEvent(new AnalyticsEvent(
                name: '$merge',
                params: [
                    '$distinct_id' => $previousId,
                    '$create_alias' => $newId,
                ],
                userId: $previousId,
            ));
        }

        // Mixpanel: $merge
        if ($this->isProviderEnabled('mixpanel')) {
            $this->manager->trackEvent(new AnalyticsEvent(
                name: '$merge',
                params: [
                    '$distinct_id' => $previousId,
                    '$merge_distinct_id' => $newId,
                ],
                userId: $previousId,
            ));
        }

        // GA4: set_user_id (via GTM or direct)
        if ($this->isProviderEnabled('ga4')) {
            $this->manager->trackEvent(new AnalyticsEvent(
                name: 'identity_merge',
                params: [
                    'previous_id' => $previousId,
                    'new_id' => $newId,
                ],
            ));
        }
    }

    /**
     * Reset user identity across all providers (GDPR logout/erasure).
     *
     * Clears user-specific data and resets to anonymous tracking.
     *
     * @param  string  $userId  User ID to reset
     * @param  string  $clientId  Client tracking ID (preserved)
     */
    public function resetIdentity(string $userId, string $clientId): void
    {
        if (! $this->enabled) {
            return;
        }

        // GA4: reset user_id
        if ($this->isProviderEnabled('ga4')) {
            $this->manager->trackEvent(new AnalyticsEvent(
                name: 'identity_reset',
                params: ['user_id' => $userId],
                clientId: $clientId,
            ));
        }

        // PostHog: $reset
        if ($this->isProviderEnabled('posthog')) {
            $this->manager->trackEvent(new AnalyticsEvent(
                name: '$reset',
                params: [],
                userId: $userId,
                clientId: $clientId,
            ));
        }

        // Mixpanel: $reset
        if ($this->isProviderEnabled('mixpanel')) {
            $this->manager->trackEvent(new AnalyticsEvent(
                name: '$reset',
                params: [],
                userId: $userId,
                clientId: $clientId,
            ));
        }

        // Amplitude: reset
        if ($this->isProviderEnabled('amplitude')) {
            $this->manager->trackEvent(new AnalyticsEvent(
                name: 'identify',
                params: ['reset' => true],
                clientId: $clientId,
            ));
        }
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if cross-provider sync is enabled.
     */
    public function isProviderEnabled(string $provider): bool
    {
        return (bool) ($this->providerToggles[$provider] ?? true);
    }

    /**
     * Get a summary of identity sync status.
     *
     * @return array{enabled: bool, providers: array<string, bool>}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'providers' => [
                'ga4' => $this->isProviderEnabled('ga4'),
                'meta' => $this->isProviderEnabled('meta'),
                'posthog' => $this->isProviderEnabled('posthog'),
                'mixpanel' => $this->isProviderEnabled('mixpanel'),
                'amplitude' => $this->isProviderEnabled('amplitude'),
                'tiktok' => $this->isProviderEnabled('tiktok'),
                'linkedin' => $this->isProviderEnabled('linkedin'),
                'plausible' => $this->isProviderEnabled('plausible'),
            ],
        ];
    }

    // ── Provider-Specific Identify Methods ──────────────────────────────

    /**
     * Identify user in GA4 via set_user_id parameter.
     *
     * @param  string  $userId
     * @param  array<string, mixed>  $traits
     */
    private function identifyGA4(string $userId, array $traits): void
    {
        $params = array_filter([
            'user_id' => $userId,
        ]);

        // Map common traits to GA4 user properties
        if (isset($traits['name'])) {
            $params['user_name'] = (string) $traits['name'];
        }
        if (isset($traits['email'])) {
            $params['user_email'] = (string) $traits['email'];
        }
        if (isset($traits['plan'])) {
            $params['user_plan'] = (string) $traits['plan'];
        }

        $this->manager->trackEvent(new AnalyticsEvent(
            name: 'user_identify',
            params: $params,
            userId: $userId,
        ));
    }

    /**
     * Identify user in Meta Pixel via CAPI user_data for advanced matching.
     *
     * @param  string  $userId
     * @param  array<string, mixed>  $traits
     */
    private function identifyMeta(string $userId, array $traits): void
    {
        $params = array_filter([
            'external_id' => $userId,
            'em' => isset($traits['email']) ? hash('sha256', strtolower(trim((string) $traits['email']))) : null,
            'fn' => isset($traits['name']) ? hash('sha256', strtolower(trim((string) $traits['name']))) : null,
            'ct' => isset($traits['city']) ? hash('sha256', strtolower(trim((string) $traits['city']))) : null,
            'country' => isset($traits['country']) ? hash('sha256', strtolower(trim((string) $traits['country']))) : null,
        ]);

        $this->manager->trackEvent(new AnalyticsEvent(
            name: 'Lead',
            params: $params,
            userId: $userId,
        ));
    }

    /**
     * Identify user in PostHog via $identify + $create_alias.
     *
     * @param  string  $userId
     * @param  string  $clientId
     * @param  array<string, mixed>  $traits
     */
    private function identifyPostHog(string $userId, string $clientId, array $traits): void
    {
        // $set properties
        $setProps = array_filter([
            '$name' => $traits['name'] ?? null,
            '$email' => $traits['email'] ?? null,
            'plan' => $traits['plan'] ?? null,
            'role' => $traits['role'] ?? null,
            'created_at' => $traits['created_at'] ?? null,
        ]);

        // $create_alias to link anonymous → authenticated
        $this->manager->trackEvent(new AnalyticsEvent(
            name: '$create_alias',
            params: [
                'distinct_id' => $clientId,
                'alias' => $userId,
            ],
            clientId: $clientId,
        ));

        // $identify with user properties
        $this->manager->trackEvent(new AnalyticsEvent(
            name: '$identify',
            params: [
                '$distinct_id' => $userId,
                '$set' => $setProps,
            ],
            userId: $userId,
        ));
    }

    /**
     * Identify user in Mixpanel via $identify + $set + $merge.
     *
     * @param  string  $userId
     * @param  string  $clientId
     * @param  array<string, mixed>  $traits
     */
    private function identifyMixpanel(string $userId, string $clientId, array $traits): void
    {
        // $merge to link identities
        $this->manager->trackEvent(new AnalyticsEvent(
            name: '$merge',
            params: [
                '$distinct_id' => $clientId,
                '$merge_distinct_id' => $userId,
            ],
            clientId: $clientId,
        ));

        // $set user properties
        $setProps = array_filter([
            '$name' => $traits['name'] ?? null,
            '$email' => $traits['email'] ?? null,
            'plan' => $traits['plan'] ?? null,
            'role' => $traits['role'] ?? null,
        ]);

        $this->manager->trackEvent(new AnalyticsEvent(
            name: '$set',
            params: $setProps,
            userId: $userId,
        ));
    }

    /**
     * Identify user in Amplitude via identify + setUserProperties.
     *
     * @param  string  $userId
     * @param  array<string, mixed>  $traits
     */
    private function identifyAmplitude(string $userId, array $traits): void
    {
        $userProps = array_filter([
            'name' => $traits['name'] ?? null,
            'email' => $traits['email'] ?? null,
            'plan' => $traits['plan'] ?? null,
            'role' => $traits['role'] ?? null,
        ]);

        $this->manager->trackEvent(new AnalyticsEvent(
            name: '$identify',
            params: array_merge(
                ['user_id' => $userId],
                $userProps,
            ),
            userId: $userId,
        ));
    }

    /**
     * Identify user in TikTok for advanced matching.
     *
     * @param  string  $userId
     */
    private function identifyTiktok(string $userId): void
    {
        $this->manager->trackEvent(new AnalyticsEvent(
            name: 'user_identify',
            params: ['user_id' => $userId],
            userId: $userId,
        ));
    }

    /**
     * Identify user in LinkedIn for conversion tracking.
     *
     * @param  string  $userId
     */
    private function identifyLinkedin(string $userId): void
    {
        $this->manager->trackEvent(new AnalyticsEvent(
            name: 'user_identify',
            params: ['user_id' => $userId],
            userId: $userId,
        ));
    }

    /**
     * Identify user in Plausible (server-side only).
     *
     * @param  string  $userId
     */
    private function identifyPlausible(string $userId): void
    {
        // Plausible doesn't support user-level identify;
        // we store the mapping server-side for attribution
        $this->manager->trackEvent(new AnalyticsEvent(
            name: 'user_identify',
            params: ['user_id' => $userId],
            userId: $userId,
        ));
    }

    /**
     * Fire a generic identify event for Webhook and other providers.
     *
     * @param  string  $userId
     * @param  string  $clientId
     * @param  array<string, mixed>  $traits
     */
    private function fireGenericIdentify(string $userId, string $clientId, array $traits): void
    {
        $params = array_filter(array_merge([
            'user_id' => $userId,
            'client_id' => $clientId,
        ], $traits));

        $this->manager->trackEvent(new AnalyticsEvent(
            name: 'identify',
            params: $params,
            clientId: $clientId,
            userId: $userId,
        ));
    }
}
