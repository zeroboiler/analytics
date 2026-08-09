<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventContext;

/**
 * Resolves analytics event context from HTTP requests.
 *
 * Provides centralized, config-driven extraction of client identity,
 * user identity, device info, UTM parameters, referrer, session, and
 * consent state. Used by middleware, API controllers, and the event
 * envelope service for automatic event enrichment.
 *
 * All context extraction is governed by config keys under
 * `zeroboiler.analytics.identity`, `zeroboiler.analytics.consent`,
 * and `zeroboiler.analytics.geolocation`.
 *
 * @version 4.6.0
 */
final class EventContextResolver
{
    /** @var ConfigRepository */
    private ConfigRepository $config;

    /** @var array<string, mixed>|null Cached consent purposes */
    private ?array $consentPurposes = null;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
    {
        $this->config = $config;
    }

    /**
     * Resolve full event context from the current request.
     *
     * Extracts all available context: identity, device, UTM, referrer,
     * session, locale, geolocation, and consent state.
     *
     * @param  Request  $request
     * @return EventContext
     */
    public function resolve(Request $request): EventContext
    {
        $cookieName = $this->config->get('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id');

        return EventContext::fromRequest(
            request: $request,
            cookieName: $cookieName,
            consentGranted: $this->isConsentGranted($request),
        );
    }

    /**
     * Resolve context and enrich an analytics event with contextual params.
     *
     * Merges context-derived params into the event's params array.
     * Client ID and user ID are set as event properties if not already present.
     *
     * @param  AnalyticsEvent  $event
     * @param  Request  $request
     * @return AnalyticsEvent Enriched event with context params
     */
    public function enrichEvent(AnalyticsEvent $event, Request $request): AnalyticsEvent
    {
        $context = $this->resolve($request);
        $enrichedParams = array_merge($event->params, $context->toParams());

        return new AnalyticsEvent(
            name: $event->name,
            params: $enrichedParams,
            clientId: $event->clientId ?? $context->clientId,
            userId: $event->userId ?? $context->userId,
        );
    }

    /**
     * Extract only UTM parameters from the request.
     *
     * @param  Request  $request
     * @return array<string, string> UTM key-value pairs
     */
    public function extractUtm(Request $request): array
    {
        $utm = [];
        $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

        foreach ($keys as $key) {
            $value = $request->query($key);
            if ($value !== null && $value !== '') {
                $utm[$key] = (string) $value;
            }
        }

        return $utm;
    }

    /**
     * Extract the client tracking ID from the request cookie.
     *
     * @param  Request  $request
     * @return string|null Client ID or null if not present
     */
    public function extractClientId(Request $request): ?string
    {
        $cookieName = $this->config->get('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id');

        return $request->cookie($cookieName);
    }

    /**
     * Extract the authenticated user ID from the request.
     *
     * @param  Request  $request
     * @return string|null User ID or null if not authenticated
     */
    public function extractUserId(Request $request): ?string
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return method_exists($user, 'getKey') ? (string) $user->getKey() : (string) $user->id;
    }

    /**
     * Determine if analytics consent is currently granted.
     *
     * Checks the consent configuration default. In production, this
     * should be combined with per-user consent state from a CMP.
     *
     * @param  Request  $request
     * @return bool True if consent is granted
     */
    public function isConsentGranted(Request $request): bool
    {
        // Check explicit consent param from request (e.g. cookie banner)
        $consentParam = $request->header('X-Analytics-Consent');
        if ($consParam === 'denied') {
            return false;
        }

        if ($consent === 'granted') {
            return true;
        }

        // Fall back to config default
        $default = $this->config->get('zeroboiler.analytics.consent.default', 'granted');

        return $default === 'granted';
    }

    /**
     * Get the consent purposes configuration for frontend exposure.
     *
     * Returns the configured consent purposes array from config,
     * suitable for Inertia page props or API responses.
     *
     * @return array<string, array{label: string, required: bool, default: bool}>
     */
    public function getConsentPurposes(): array
    {
        if ($this->consentPurposes !== null) {
            return $this->consentPurposes;
        }

        $this->consentPurposes = $this->config->get('zeroboiler.analytics.consent.purposes', [
            'necessary' => ['label' => 'Necessary', 'required' => true, 'default' => true],
            'analytics' => ['label' => 'Analytics', 'required' => false, 'default' => true],
            'marketing' => ['label' => 'Marketing', 'required' => false, 'default' => false],
            'functional' => ['label' => 'Functional', 'required' => false, 'default' => true],
        ]);

        return $this->consentPurposes;
    }

    /**
     * Generate a new client tracking ID.
     *
     * Uses UUID v4 format for high-entropy, globally unique identifiers.
     *
     * @return string UUID v4
     */
    public function generateClientId(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    /**
     * Get the identity cookie configuration for response headers.
     *
     * Returns the cookie name, TTL, secure, sameSite, and domain
     * settings from config for use in middleware cookie setting.
     *
     * @return array{name: string, ttl: int, secure: bool, sameSite: string, domain: string|null}
     */
    public function getCookieConfig(): array
    {
        return [
            'name' => $this->config->get('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id'),
            'ttl' => (int) $this->config->get('zeroboiler.analytics.identity.cookie_ttl', 525600),
            'secure' => (bool) $this->config->get('zeroboiler.analytics.identity.cookie_secure', true),
            'sameSite' => $this->config->get('zeroboiler.analytics.identity.cookie_samesite', 'Lax'),
            'domain' => $this->config->get('zeroboiler.analytics.identity.cookie_domain'),
        ];
    }

    /**
     * Build analytics props for Inertia page responses.
     *
     * Generates the `zbAnalytics` object containing configuration,
     * provider IDs, consent state, user info, and auto-track settings
     * for exposure to the frontend JS client.
     *
     * @param  Request  $request
     * @return array<string, mixed> Props for Inertia page response
     */
    public function buildInertiaProps(Request $request): array
    {
        $context = $this->resolve($request);
        $config = $this->config->get('zeroboiler.analytics', []);

        return [
            'enabled' => true,
            'trackingId' => $context->clientId,
            'userId' => $context->userId,
            'version' => $this->config->get('zeroboiler.analytics.version', '3.0.0'),
            'apiBase' => $config['api']['base_url'] ?? '/api/analytics',
            'apiEnabled' => (bool) ($config['api']['enabled'] ?? true),
            'debug' => (bool) ($config['debug']['enabled'] ?? false),
            'ga4MeasurementId' => $config['ga4']['measurement_id'] ?? null,
            'gtmContainerId' => $config['gtm']['container_id'] ?? null,
            'metaPixelId' => $config['meta_pixel']['id'] ?? null,
            'plausibleDomain' => $config['plausible']['domain'] ?? null,
            'posthogHost' => $config['posthog']['host'] ?? null,
            'consent' => [
                'granted' => $context->consentGranted,
                'default' => $config['consent']['default'] ?? 'granted',
                'purposes' => $this->getConsentPurposes(),
            ],
            'trackLinks' => $config['track_links'] ?? [],
            'device' => $context->device,
            'autoTrack' => $config['client_auto_track'] ?? [],
            'performance' => $config['performance'] ?? [],
            'identityAutoLink' => (bool) ($config['identity']['link_on_auth'] ?? true),
        ];
    }
}
