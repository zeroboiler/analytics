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
use ZeroBoiler\Analytics\Http\HttpMiddlewareContract;
use ZeroBoiler\Analytics\Services\IdentityResolutionService;

/**
 * Inertia middleware that injects analytics configuration into page props.
 *
 * Exposes zbAnalytics as a shared Inertia prop containing:
 * - Whether analytics is enabled
 * - Consent state
 * - Provider IDs (GA4, GTM, Meta) — only if enabled
 * - Server-generated tracking ID (cookie-stored for client/server matching)
 * - Authenticated user ID (when available)
 * - Auth state change flag (when user just logged in/out) for client ID stitching
 *
 * @since 1.0.0
 */
final class HandleInertiaAnalytics implements HttpMiddlewareContract
{
    private AnalyticsManager $manager;

    private ConfigRepository $config;

    public function __construct(AnalyticsManager $manager, ConfigRepository $config): void
    {
        $this->manager = $manager;
        $this->config = $config;
    }

    /**
     * Handle an incoming request and inject analytics data into Inertia props.
     */
    #[\Override]
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

        // Consent purposes (granular GDPR consent for consent banners)
        $consentPurposes = $this->config->get('zeroboiler.analytics.consent.purposes', []);
        /** @var array<string, array{label: string, required: bool, default: bool}> $consentPurposes */
        $analyticsProps['consentPurposes'] = [];
        foreach ($consentPurposes as $key => $purpose) {
            $analyticsProps['consentPurposes'][$key] = [
                'label' => (string) ($purpose['label'] ?? $key),
                'required' => (bool) ($purpose['required'] ?? false),
                'default' => (bool) ($purpose['default'] ?? false),
            ];
        }

        // Debug mode (client-side should respect server debug setting)
        $debugConfig = $this->config->get('zeroboiler.analytics.debug', []);
        /** @var array{enabled?: bool} $debugConfig */
        $analyticsProps['debug'] = (bool) ($debugConfig['enabled'] ?? false);

        // Client-side auto-tracking settings (config-driven)
        $clientAutoTrack = $this->config->get('zeroboiler.analytics.client_auto_track', []);
        /** @var array{page_views?: bool, scroll_depth?: bool, form_tracking?: bool, error_tracking?: bool, link_tracking?: bool, session_tracking?: bool, idle_timeout?: int, error_ignore_patterns?: list<string>} $clientAutoTrack */
        $analyticsProps['autoTrack'] = [
            'pageViews' => (bool) ($clientAutoTrack['page_views'] ?? true),
            'scrollDepth' => (bool) ($clientAutoTrack['scroll_depth'] ?? true),
            'formTracking' => (bool) ($clientAutoTrack['form_tracking'] ?? true),
            'errorTracking' => (bool) ($clientAutoTrack['error_tracking'] ?? true),
            'linkTracking' => (bool) ($clientAutoTrack['link_tracking'] ?? false),
            'sessionTracking' => (bool) ($clientAutoTrack['session_tracking'] ?? true),
            'idleTimeout' => (int) ($clientAutoTrack['idle_timeout'] ?? 1800),
            'errorIgnorePatterns' => (array) ($clientAutoTrack['error_ignore_patterns'] ?? []),
        ];

        // Performance tracking settings (Core Web Vitals)
        $performanceConfig = $this->config->get('zeroboiler.analytics.performance', []);
        /** @var array{enabled?: bool, track_lcp?: bool, track_fid?: bool, track_cls?: bool, track_inp?: bool, track_ttfb?: bool, track_fcp?: bool, send_to_server?: bool} $performanceConfig */
        $analyticsProps['performance'] = [
            'enabled' => (bool) ($performanceConfig['enabled'] ?? false),
            'trackLCP' => (bool) ($performanceConfig['track_lcp'] ?? true),
            'trackFID' => (bool) ($performanceConfig['track_fid'] ?? true),
            'trackCLS' => (bool) ($performanceConfig['track_cls'] ?? true),
            'trackINP' => (bool) ($performanceConfig['track_inp'] ?? true),
            'trackTTFB' => (bool) ($performanceConfig['track_ttfb'] ?? true),
            'trackFCP' => (bool) ($performanceConfig['track_fcp'] ?? false),
            'sendToServer' => (bool) ($performanceConfig['send_to_server'] ?? true),
        ];

        // E-commerce defaults for client-side e-commerce tracking
        $ecommerceConfig = $this->config->get('zeroboiler.analytics.ecommerce', []);
        /** @var array{currency?: string, brand?: string, tax_behavior?: string, shipping_default?: float} $ecommerceConfig */
        $analyticsProps['ecommerce'] = [
            'currency' => (string) ($ecommerceConfig['currency'] ?? 'USD'),
            'brand' => (string) ($ecommerceConfig['brand'] ?? ''),
            'taxBehavior' => (string) ($ecommerceConfig['tax_behavior'] ?? 'inclusive'),
            'shippingDefault' => (float) ($ecommerceConfig['shipping_default'] ?? 0.0),
        ];

        // Consent log enabled flag (for consent banner display on client)
        $consentConfig = $this->config->get('zeroboiler.analytics.consent', []);
        /** @var array{log_enabled?: bool} $consentConfig */
        $analyticsProps['consentLogEnabled'] = (bool) ($consentConfig['log_enabled'] ?? false);

        // Consent version hash — enables client to detect server-side consent config changes
        $consentPurposesRaw = $this->config->get('zeroboiler.analytics.consent.purposes', []);
        $consentDefaultRaw = $this->config->get('zeroboiler.analytics.consent.default', 'granted');
        $consentVersionPayload = json_encode([
            'purposes' => $consentPurposesRaw,
            'default' => $consentDefaultRaw,
        ], JSON_THROW_ON_ERROR);
        $analyticsProps['consentVersion'] = hash('xxh128', $consentVersionPayload);

        // Package version for client-side feature detection
        $analyticsProps['version'] = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;

        // Revenue subscription tiers for client-side plan display
        $subscriptionTiers = $this->config->get('zeroboiler.analytics.revenue.subscription_tiers', []);
        /** @var array<string, array{name?: string, price?: int|float, billing_cycle?: string, features?: list<string>}> $subscriptionTiers */
        $analyticsProps['subscriptionTiers'] = $subscriptionTiers;

        // Identity auto-linking flag for client-side identify call
        $identityConfig = $this->config->get('zeroboiler.analytics.identity', []);
        /** @var array{link_on_auth?: bool} $identityConfig */
        $analyticsProps['identityAutoLink'] = (bool) ($identityConfig['link_on_auth'] ?? true);

        // Auth state change detection for client-side identity stitching (v6.9.0)
        // When the user authenticates mid-session, the client JS needs to
        // detect the change and fire an identify call to link client_id ↔ user_id
        $analyticsProps['authStateChanged'] = $this->detectAuthStateChange($request);
        $analyticsProps['previousUserId'] = $this->getPreviousUserId($request);

        // SaaS analytics maturity score (computed on every request — lightweight)
        try {
            $calculator = new \ZeroBoiler\Analytics\Services\EventPriorityCalculator;
            $maturity = $calculator->maturityScore();
            $analyticsProps['maturity'] = [
                'score' => $maturity['score'],
                'grade' => $maturity['grade'],
            ];
        } catch (\Throwable) {
            $analyticsProps['maturity'] = ['score' => 0, 'grade' => 'N/A'];
        }

        // Onboarding checklist gaps for client-side instrumentation guidance
        try {
            $calculator = new \ZeroBoiler\Analytics\Services\EventPriorityCalculator;
            $checklist = $calculator->onboardingChecklist();
            $analyticsProps['onboarding'] = [
                'completion' => $checklist['summary']['completion'],
                'gaps' => $checklist['summary']['gaps'],
            ];
        } catch (\Throwable) {
            $analyticsProps['onboarding'] = ['completion' => 0.0, 'gaps' => []];
        }

        // Funnel readiness scores for client-side instrumentation guidance (v2.84.0)
        try {
            $calculator = new \ZeroBoiler\Analytics\Services\EventPriorityCalculator;
            $funnelReadiness = $calculator->funnelReadiness();
            $analyticsProps['funnelReadiness'] = [
                'signup' => round($funnelReadiness['signup_funnel']['score'] ?? 0.0, 2),
                'purchase' => round($funnelReadiness['purchase_funnel']['score'] ?? 0.0, 2),
                'subscription' => round($funnelReadiness['subscription_funnel']['score'] ?? 0.0, 2),
                'overall' => round($funnelReadiness['overall'] ?? 0.0, 2),
            ];
        } catch (\Throwable) {
            $analyticsProps['funnelReadiness'] = [
                'signup' => 0.0,
                'purchase' => 0.0,
                'subscription' => 0.0,
                'overall' => 0.0,
            ];
        }

        // Recommended next events for client-side instrumentation (v2.84.0)
        try {
            $instrumentation = \ZeroBoiler\Analytics\Events\EventCatalog::recommendedInstrumentation('starter');
            $untrackedEvents = [];
            foreach ($instrumentation['events'] as $entry) {
                $name = $entry['name'];
                // Check if this event appears in the onboarding gaps
                if (in_array($name, $analyticsProps['onboarding']['gaps'], true)) {
                    $untrackedEvents[] = [
                        'name' => $name,
                        'category' => $entry['category'] ?? null,
                        'priority' => \ZeroBoiler\Analytics\Events\EventCatalog::eventPriority($name),
                    ];
                }
            }
            $analyticsProps['recommendedEvents'] = array_slice($untrackedEvents, 0, 10);
        } catch (\Throwable) {
            $analyticsProps['recommendedEvents'] = [];
        }

        // Event deduplication config for client-side debounce tuning (v2.84.0)
        $dedupConfig = $this->config->get('zeroboiler.analytics.dedup', []);
        /** @var array{enabled?: bool, window_seconds?: int} $dedupConfig */
        $analyticsProps['dedup'] = [
            'enabled' => (bool) ($dedupConfig['enabled'] ?? true),
            'windowSeconds' => (int) ($dedupConfig['window_seconds'] ?? 10),
        ];

        // Sampling config for client-side rate control (v6.5.0)
        $samplingConfig = $this->config->get('zeroboiler.analytics.sampling', []);
        /** @var array{enabled?: bool, rate?: float, deterministic?: bool} $samplingConfig */
        $analyticsProps['sampling'] = [
            'enabled' => (bool) ($samplingConfig['enabled'] ?? false),
            'rate' => (float) ($samplingConfig['rate'] ?? 1.0),
            'deterministic' => (bool) ($samplingConfig['deterministic'] ?? true),
        ];

        // Geolocation enrichment status for client-side awareness (v6.5.0)
        $geoConfig = $this->config->get('zeroboiler.analytics.geolocation', []);
        /** @var array{enabled?: bool, strategy?: string} $geoConfig */
        $analyticsProps['geolocation'] = [
            'enabled' => (bool) ($geoConfig['enabled'] ?? false),
            'strategy' => (string) ($geoConfig['strategy'] ?? 'header'),
        ];

        // Regional consent detection status (v6.5.0)
        $regionalConfig = $this->config->get('zeroboiler.analytics.regional_consent', []);
        /** @var array{enabled?: bool, gdpr_default?: string} $regionalConfig */
        $analyticsProps['regionalConsent'] = [
            'enabled' => (bool) ($regionalConfig['enabled'] ?? false),
            'gdprDefault' => (string) ($regionalConfig['gdpr_default'] ?? 'denied'),
        ];

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
        $cookieDomain = $this->config->get('zeroboiler.analytics.identity.cookie_domain');
        Cookie::queue(
            $cookieName,
            $newId,
            $this->config->get('zeroboiler.analytics.identity.cookie_ttl', 525600),
            '/',
            is_string($cookieDomain) && $cookieDomain !== '' ? $cookieDomain : null,
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

    /**
     * Detect if the authentication state changed during this request.
     *
     * Compares the current authenticated user ID against the previous user ID
     * stored in the session. Returns true if the user just logged in or out.
     *
     * Used by the JS client to trigger identity stitching (client ID ↔ user ID).
     *
     * @return bool
     */
    private function detectAuthStateChange(Request $request): bool
    {
        $currentUserId = $this->getUserId();
        $previousUserId = $this->getPreviousUserId($request);

        // Auth state changed if they differ and at least one is non-null
        return $currentUserId !== $previousUserId;
    }

    /**
     * Get the previous authenticated user ID from the session.
     *
     * On the first request after login/logout, the session still contains
     * the previous user ID from the prior request cycle.
     *
     * @return string|null
     */
    private function getPreviousUserId(Request $request): ?string
    {
        $key = 'zb_analytics_previous_user_id';
        $previousUserId = $request->session()->get($key);
        $currentUserId = $this->getUserId();

        // Update the session for the next request comparison
        $request->session()->put($key, $currentUserId);

        return is_string($previousUserId) && $previousUserId !== '' ? $previousUserId : null;
    }
}
