<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Http\HttpMiddlewareContract;

/**
 * Inertia.js middleware that shares analytics configuration as page props.
 *
 * Injects `zbAnalytics` into every Inertia response, providing the frontend
 * with all configuration needed for the JS client library (trackEvent,
 * trackPageView, initInertiaPageViewTracker, etc.).
 *
 * Also manages the client ID cookie: generates a UUID on first visit,
 * returns it on every response, and links it to the authenticated user
 * when identity.link_on_auth is enabled.
 *
 * Register as global middleware: `$middleware->web(InertiaAnalyticsMiddleware::class)`
 * or as route middleware: `analytics.inertia`
 *
 * @since 255.0.0
 */
final class InertiaAnalyticsMiddleware implements HttpMiddlewareContract
{
    /** @var array<string, mixed> */
    private array $analyticsConfig;

    private string $cookieName;

    private int $cookieTtl;

    private bool $cookieSecure;

    private string $cookieSameSite;

    private ?string $cookieDomain;

    private bool $linkOnAuth;

    private bool $autoLink;

    /** @var array{page_views: bool, scroll_depth: bool, form_tracking: bool, error_tracking: bool, link_tracking: bool, session_tracking: bool, idle_timeout: int, error_ignore_patterns: list<string>} */
    private array $clientAutoTrack;

    /**
     * @var array{default: string, purposes: array<string, array{label: string, required: bool, default: bool}>}
     */
    private array $consentConfig;

    public function __construct(
        private AnalyticsManager $manager,
        ConfigRepository $config,
    ): void {
        $this->analyticsConfig = $config->get('zeroboiler.analytics', []);

        $identityConfig = $this->analyticsConfig['identity'] ?? [];
        /** @var array{cookie_name?: string, cookie_ttl?: int, cookie_secure?: bool, cookie_samesite?: string, cookie_domain?: string|null, link_on_auth?: bool, auto_link?: bool} $identityConfig */
        $this->cookieName = (string) ($identityConfig['cookie_name'] ?? 'zb_analytics_id');
        $this->cookieTtl = (int) ($identityConfig['cookie_ttl'] ?? 525600);
        $this->cookieSecure = (bool) ($identityConfig['cookie_secure'] ?? true);
        $this->cookieSameSite = (string) ($identityConfig['cookie_samesite'] ?? 'Lax');
        $this->cookieDomain = $identityConfig['cookie_domain'] ?? null;
        $this->linkOnAuth = (bool) ($identityConfig['link_on_auth'] ?? true);
        $this->autoLink = (bool) ($identityConfig['auto_link'] ?? true);

        $this->clientAutoTrack = $this->analyticsConfig['client_auto_track'] ?? [
            'page_views' => true,
            'scroll_depth' => true,
            'form_tracking' => true,
            'error_tracking' => true,
            'link_tracking' => false,
            'session_tracking' => true,
            'idle_timeout' => 1800,
            'error_ignore_patterns' => [],
        ];

        $this->consentConfig = $this->analyticsConfig['consent'] ?? [
            'default' => 'granted',
            'purposes' => [],
        ];
    }

    /**
     * Handle an incoming request.
     *
     * 1. Ensure client ID cookie exists (generate if missing)
     * 2. Link client ID to authenticated user when appropriate
     * 3. Share analytics config as Inertia page props
     *
     * @param  Closure(Request): Response  $next
     */
    #[\Override]
    public function handle(Request $request, Closure $next): Response
    {
        $clientId = $this->ensureClientId($request);

        // Detect auth state change for identity stitching
        $authStateChanged = false;
        $previousUserId = $request->session()->get('zb_analytics_user_id');
        $currentUserId = $request->user()?->getKey();
        $currentUserIdStr = is_int($currentUserId) || is_string($currentUserId) ? (string) $currentUserId : null;

        if ($previousUserId !== $currentUserIdStr) {
            $authStateChanged = true;
            $request->session()->put('zb_analytics_user_id', $currentUserIdStr);
        }

        // Link client ID to user on authentication
        if ($this->linkOnAuth && $currentUserIdStr !== null && $clientId !== null) {
            $this->linkIdentity($clientId, $currentUserIdStr);
        }

        /** @var Response $response */
        $response = $next($request);

        // Attach client ID cookie to response
        $this->attachClientIdCookie($response, $clientId);

        // Share analytics config as Inertia page props
        $this->shareInertiaProps($clientId, $currentUserIdStr, $authStateChanged, $previousUserId);

        return $response;
    }

    /**
     * Ensure a client tracking ID cookie exists.
     *
     * Generates a v4 UUID if no cookie is present.
     *
     * @return string The client ID
     */
    private function ensureClientId(Request $request): string
    {
        $clientId = $request->cookie($this->cookieName);

        if (is_string($clientId) && $clientId !== '') {
            return $clientId;
        }

        // Generate a UUID v4
        $clientId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );

        $request->cookies->set($this->cookieName, $clientId);

        return $clientId;
    }

    /**
     * Attach the client ID cookie to the response.
     */
    private function attachClientIdCookie(Response $response, string $clientId): void
    {
        $response->cookie(
            cookie(
                name: $this->cookieName,
                value: $clientId,
                minutes: $this->cookieTtl,
                secure: $this->cookieSecure,
                sameSite: $this->cookieSameSite,
                domain: $this->cookieDomain,
            ),
        );
    }

    /**
     * Link a client ID to a user ID via the IdentityGraphService.
     *
     * Skipped if auto_link is disabled or if the link already exists.
     */
    private function linkIdentity(string $clientId, string $userId): void
    {
        if (! $this->autoLink) {
            return;
        }

        try {
            $identityGraph = app(\ZeroBoiler\Analytics\Services\IdentityGraphService::class);
            $identityGraph->linkExplicit($clientId, $userId);
        } catch (\Throwable $e) {
            logger()->debug('ZeroBoiler: identity link failed', [
                'client_id' => $clientId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Share analytics configuration as Inertia page props.
     *
     * The `zbAnalytics` prop is consumed by the JS client library's
     * `init(pageProps)` and `initInertiaPageViewTracker()` functions.
     */
    private function shareInertiaProps(
        string $clientId,
        ?string $userId,
        bool $authStateChanged,
        ?string $previousUserId,
    ): void {
        $ga4Config = $this->analyticsConfig['ga4'] ?? [];
        /** @var array{enabled?: bool, measurement_id?: string} $ga4Config */
        $gtmConfig = $this->analyticsConfig['gtm'] ?? [];
        /** @var array{enabled?: bool, container_id?: string} $gtmConfig */
        $metaConfig = $this->analyticsConfig['meta_pixel'] ?? [];
        /** @var array{enabled?: bool, id?: string} $metaConfig */
        $plausibleConfig = $this->analyticsConfig['plausible'] ?? [];
        /** @var array{enabled?: bool, domain?: string, custom_domain?: string|null, api_host?: string|null} $plausibleConfig */
        $posthogConfig = $this->analyticsConfig['posthog'] ?? [];
        /** @var array{enabled?: bool, api_key?: string, host?: string} $posthogConfig */
        $apiConfig = $this->analyticsConfig['api'] ?? [];
        /** @var array{enabled?: bool, base_url?: string} $apiConfig */
        $queueConfig = $this->analyticsConfig['queue'] ?? [];
        /** @var array{enabled?: bool} $queueConfig */
        $samplingConfig = $this->analyticsConfig['sampling'] ?? [];
        /** @var array{enabled?: bool, rate?: float, deterministic?: bool} $samplingConfig */

        $props = [
            'enabled' => true,
            'trackingId' => $clientId,
            'userId' => $userId,
            'authStateChanged' => $authStateChanged,
            'previousUserId' => $previousUserId,
            'identityAutoLink' => $this->autoLink,

            // Provider configuration
            'ga4MeasurementId' => ($ga4Config['enabled'] ?? false) ? ($ga4Config['measurement_id'] ?? '') : null,
            'gtmContainerId' => ($gtmConfig['enabled'] ?? false) ? ($gtmConfig['container_id'] ?? '') : null,
            'metaPixelId' => ($metaConfig['enabled'] ?? false) ? ($metaConfig['id'] ?? '') : null,
            'plausibleDomain' => ($plausibleConfig['enabled'] ?? false) ? ($plausibleConfig['domain'] ?? '') : null,
            'posthogHost' => ($posthogConfig['enabled'] ?? false) ? ($posthogConfig['host'] ?? '') : null,

            // API configuration
            'apiBase' => ($apiConfig['enabled'] ?? true) ? ($apiConfig['base_url'] ?? '/api/analytics') : null,

            // Auto-track settings
            'autoTrack' => $this->clientAutoTrack,

            // Consent configuration
            'consent' => [
                'default' => $this->consentConfig['default'] ?? 'granted',
                'purposes' => $this->consentConfig['purposes'] ?? [],
            ],

            // Queue mode
            'asyncDispatch' => $queueConfig['enabled'] ?? true,

            // Sampling
            'sampling' => [
                'enabled' => (bool) ($samplingConfig['enabled'] ?? false),
                'rate' => (float) ($samplingConfig['rate'] ?? 1.0),
                'deterministic' => (bool) ($samplingConfig['deterministic'] ?? true),
            ],
        ];

        \Inertia\Inertia::share('zbAnalytics', $props);
    }
}
