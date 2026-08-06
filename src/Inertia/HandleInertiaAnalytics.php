<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Inertia;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * Inertia middleware that injects analytics configuration into page props.
 *
 * Exposes zbAnalytics as a shared Inertia prop containing:
 * - Whether analytics is enabled
 * - Consent state
 * - Provider IDs (GA4, GTM, Meta) — only if enabled
 * - Server-generated tracking ID (cookie-stored for client/server matching)
 * - Authenticated user ID (when available)
 */
class HandleInertiaAnalytics
{
    private AnalyticsManager $manager;

    private ConfigRepository $config;

    public function __construct(AnalyticsManager $manager, ConfigRepository $config)
    {
        $this->manager = $manager;
        $this->config = $config;
    }

    /**
     * Handle an incoming request and inject analytics data into Inertia props.
     */
    public function handle(Request $request, Closure $next): Response|\Inertia\Response
    {
        $response = $next($request);

        // Only modify Inertia responses
        if (! $response instanceof \Inertia\Response) {
            return $response;
        }

        $trackingId = $this->getOrCreateTrackingId($request);

        $analyticsProps = [
            'enabled' => $this->isAnyProviderEnabled(),
            'consent' => $this->manager->getConsent()->toArray(),
            'trackingId' => $trackingId,
            'userId' => $this->getUserId(),
        ];

        // Only expose provider IDs when the provider is enabled
        if ($this->manager->ga4()->isEnabled()) {
            $analyticsProps['ga4MeasurementId'] = $this->manager->ga4()->getMeasurementId();
        }

        if ($this->manager->gtm()->isEnabled()) {
            $analyticsProps['gtmContainerId'] = $this->manager->gtm()->getContainerId();
        }

        if ($this->manager->meta()->isEnabled()) {
            $analyticsProps['metaPixelId'] = $this->manager->meta()->getPixelId();
        }

        if ($this->manager->plausible()->isEnabled()) {
            $analyticsProps['plausibleDomain'] = $this->manager->plausible()->getDomain();
        }

        if ($this->manager->posthog()->isEnabled()) {
            $analyticsProps['posthogHost'] = $this->manager->posthog()->getHost();
        }

        // Auto-track links configuration
        $trackLinks = $this->config->get('zeroboiler.analytics.track_links', []);
        /** @var array{enabled?: bool, track_external?: bool, track_internal?: bool, external_prefix?: string} $trackLinks */
        $analyticsProps['trackLinks'] = [
            'enabled' => (bool) ($trackLinks['enabled'] ?? false),
            'trackExternal' => (bool) ($trackLinks['track_external'] ?? true),
            'trackInternal' => (bool) ($trackLinks['track_internal'] ?? false),
            'externalPrefix' => (string) ($trackLinks['external_prefix'] ?? 'outbound'),
        ];

        // Device context for client-side analytics enrichment
        $analyticsProps['device'] = [
            'userAgent' => $request->userAgent(),
            'ip' => $request->ip(),
            'locale' => $request->locale(),
        ];

        // API endpoint base URL (for cross-origin or custom API routes)
        $analyticsProps['apiBase'] = $this->config->get('zeroboiler.analytics.api.base_url', '/api/analytics');
        $analyticsProps['apiEnabled'] = (bool) $this->config->get('zeroboiler.analytics.api.enabled', true);

        // Debug mode (client-side should respect server debug setting)
        $debugConfig = $this->config->get('zeroboiler.analytics.debug', []);
        /** @var array{enabled?: bool} $debugConfig */
        $analyticsProps['debug'] = (bool) ($debugConfig['enabled'] ?? false);

        return $response->with('zbAnalytics', $analyticsProps);
    }

    /**
     * Get or create a server-side tracking ID stored in a cookie.
     */
    private function getOrCreateTrackingId(Request $request): string
    {
        $cookieName = $this->config->get('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id');
        $cookieName = is_string($cookieName) ? $cookieName : 'zb_analytics_id';

        $existing = $request->cookie($cookieName);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $newId = $this->generateTrackingId();

        // Queue cookie for the response
        Cookie::queue(
            $cookieName,
            $newId,
            $this->config->get('zeroboiler.analytics.identity.cookie_ttl', 525600),
            '/',
            null,
            $this->config->get('zeroboiler.analytics.identity.cookie_secure', true),
            true, // httpOnly
            false, // raw
            $this->config->get('zeroboiler.analytics.identity.cookie_samesite', 'Lax'),
        );

        return $newId;
    }

    /**
     * Generate a unique tracking ID.
     */
    private function generateTrackingId(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Get the authenticated user ID if available.
     */
    private function getUserId(): ?string
    {
        /** @var \Illuminate\Contracts\Auth\Authenticatable|null $user */
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $key = method_exists($user, 'getKeyName') ? $user->getKeyName() : 'id';

        return method_exists($user, 'getAttribute')
            ? (string) $user->getAttribute($key)
            : (string) $user->getKey();
    }

    /**
     * Check if at least one analytics provider is enabled.
     */
    private function isAnyProviderEnabled(): bool
    {
        return $this->manager->ga4()->isEnabled()
            || $this->manager->gtm()->isEnabled()
            || $this->manager->meta()->isEnabled()
            || $this->manager->plausible()->isEnabled()
            || $this->manager->posthog()->isEnabled();
    }
}
