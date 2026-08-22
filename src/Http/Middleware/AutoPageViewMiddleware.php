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
 * HTTP middleware that auto-dispatches server-side page_view events.
 *
 * Automatically tracks page views for every qualifying HTTP response.
 * Configurable path filtering, exclusion patterns, and response type
 * filtering ensure only meaningful page views are tracked.
 *
 * This complements the client-side page_view tracking in analytics.js by
 * providing server-side tracking for SEO bots, API-driven navigation,
 * and environments where client-side JS is disabled.
 *
 * Register as route middleware: `analytics.pageview`
 * Register as global middleware for site-wide tracking.
 *
 * Configuration:
 * - `zeroboiler.analytics.auto_pageview.enabled` — master toggle
 * - `zeroboiler.analytics.auto_pageview.exclude_paths` — URI patterns to skip
 * - `zeroboiler.analytics.auto_pageview.exclude_methods` — HTTP methods to skip
 * - `zeroboiler.analytics.auto_pageview.track_api` — also track JSON/API responses
 * - `zeroboiler.analytics.auto_pageview.track_status_codes` — status codes to track
 * - `zeroboiler.analytics.auto_pageview.bot_tracking` — track bot user agents
 * - `zeroboiler.analytics.auto_pageview.strip_query_params` — remove query string from URL
 * - `zeroboiler.analytics.auto_pageview.max_url_length` — truncate long URLs
 * - `zeroboiler.analytics.auto_pageview.sampling_rate` — track only N% of requests
 *
 * @since 92.0.0
 */
final class AutoPageViewMiddleware implements HttpMiddlewareContract
{
    /**
     * @param  AnalyticsManager  $manager  Central analytics manager
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        private readonly AnalyticsManager $manager,
        private readonly ConfigRepository $config,
    ){}

    /**
     * Handle an incoming request and auto-dispatch a page_view event.
     *
     * Only fires for qualifying requests after the response is generated.
     * The event includes URL, referrer, user agent, and response metadata.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $this->shouldTrack($request, $response)) {
            return $response;
        }

        $this->dispatchPageView($request, $response);

        return $response;
    }

    /**
     * Determine if the request qualifies for automatic page_view tracking.
     *
     * Checks enabled flag, HTTP method, status code, path exclusions,
     * bot filtering, and sampling rate.
     *
     * @param  Request  $request
     * @param  Response  $response
     */
    private function shouldTrack(Request $request, Response $response): bool
    {
        $config = $this->getAutoPageViewConfig();

        // Master toggle
        if (! ($config['enabled'] ?? false)) {
            return false;
        }

        $excludeMethods = $config['exclude_methods'] ?? ['OPTIONS', 'HEAD'];
        if (in_array($request->method(), $excludeMethods, true)) {
            return false;
        }

        $trackStatusCodes = $config['track_status_codes'] ?? [200, 301, 302, 303, 307, 308, 404];
        if (! in_array($response->getStatusCode(), $trackStatusCodes, true)) {
            return false;
        }

        $excludePaths = $config['exclude_paths'] ?? [
            '*/_ignition*',
            '*/telescope*',
            '*/horizon*',
            '*/pulse*',
            '*/vendor/*',
            '*/storage/*',
        ];
        $path = '/' . ltrim($request->path(), '/');
        foreach ($excludePaths as $pattern) {
            if (fnmatch($pattern, $path)) {
                return false;
            }
        }

        // Only track HTML responses unless API tracking is enabled
        $contentType = $response->headers->get('Content-Type', '');
        $isHtml = str_contains($contentType, 'text/html') || str_contains($contentType, 'application/xhtml');
        $trackApi = (bool) ($config['track_api'] ?? false);

        if (! $isHtml && ! $trackApi) {
            return false;
        }

        // Bot tracking filter
        $botTracking = (bool) ($config['bot_tracking'] ?? false);
        if (! $botTracking && $this->isBot($request)) {
            return false;
        }

        // Sampling rate (1.0 = 100%, 0.1 = 10%)
        $samplingRate = (float) ($config['sampling_rate'] ?? 1.0);
        if ($samplingRate < 1.0 && (mt_rand() / mt_getrandmax()) > $samplingRate) {
            return false;
        }

        return true;
    }

    /**
     * Dispatch a server-side page_view event.
     *
     * Builds event parameters from the request/response and dispatches
     * through the analytics manager. Silently catches exceptions to
     * never break the request lifecycle.
     *
     * @param  Request  $request
     * @param  Response  $response
     */
    private function dispatchPageView(Request $request, Response $response): void
    {
        $config = $this->getAutoPageViewConfig();

        $url = $request->fullUrl();

        // Strip query params if configured
        if ((bool) ($config['strip_query_params'] ?? true)) {
            $url = $request->url();
        }

        // Truncate long URLs
        $maxLength = (int) ($config['max_url_length'] ?? 2048);
        if (strlen($url) > $maxLength) {
            $url = substr($url, 0, $maxLength);
        }

        $params = [
            'page_url' => $url,
            'page_path' => '/' . ltrim($request->path(), '/'),
            'page_title' => $this->extractTitle($request, $response),
            'referrer' => $request->headers->get('referer', ''),
            'http_method' => $request->method(),
            'status_code' => $response->getStatusCode(),
            'content_type' => $response->headers->get('Content-Type', ''),
            'response_time_ms' => defined('LARAVEL_START')
                ? round((microtime(true) - LARAVEL_START) * 1000, 1)
                : null,
            'source' => 'server_middleware',
            'user_agent' => $request->userAgent(),
            'is_bot' => $this->isBot($request),
        ];

        $user = $request->user();
        if ($user !== null) {
            $key = method_exists($user, 'getKeyName') ? $user->getKeyName() : 'id';
            $params['user_id'] = method_exists($user, 'getAttribute')
                ? (string) $user->getAttribute($key)
                : (string) $user->getKey();
        }

        $tenantId = $this->resolveTenantId($request);
        if ($tenantId !== null) {
            $params['tenant_id'] = $tenantId;
        }

        try {
            $this->manager->trackEvent('page_view', $params);
        } catch (\Throwable $e) {
            // Auto page_view tracking must never break the request lifecycle
        }
    }

    /**
     * Extract the page title from the response content.
     *
     * Parses the HTML response to extract the <title> tag content.
     * Returns null for non-HTML responses.
     *
     * @param  Request  $request
     * @param  Response  $response
     * @return string|null Page title or null
     */
    private function extractTitle(Request $request, Response $response): ?string
    {
        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return null;
        }

        if (! str_contains($content, '<title')) {
            return null;
        }

        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $content, $matches)) {
            $title = trim($matches[1] ?? '');
            $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return $title !== '' ? $title : null;
        }

        return null;
    }

    /**
     * Check if the request comes from a bot/crawler user agent.
     *
     * Uses a lightweight pattern match against common bot user agents.
     * Not intended as a comprehensive bot detection — just enough to
     * filter out obvious crawlers from page_view analytics.
     *
     * @param  Request  $request
     */
    private function isBot(Request $request): bool
    {
        $userAgent = $request->userAgent();

        if ($userAgent === '' || $userAgent === null) {
            return false;
        }

        $botPatterns = [
            'bot', 'crawl', 'spider', 'scrape', 'slurp',
            'mediapartners', 'adsbot', 'preview', 'baiduspider',
            'bingbot', 'googlebot', 'yandexbot', 'duckduckbot',
            'facebot', 'twitterbot', 'linkedinbot', 'semrushbot',
            'ahrefsbot', 'mj12bot', 'petalbot', 'applebot',
        ];

        $lower = strtolower($userAgent);

        foreach ($botPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve tenant ID from the request if multi-tenancy is active.
     *
     * Checks for a `tenant_id` attribute on the request, which may be
     * set by upstream tenant resolution middleware.
     *
     * @param  Request  $request
     * @return string|null Tenant ID or null
     */
    private function resolveTenantId(Request $request): ?string
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_string($tenantId) && $tenantId !== '') {
            return $tenantId;
        }

        $user = $request->user();
        if ($user !== null && method_exists($user, 'getAttribute')) {
            $tid = $user->getAttribute('tenant_id');
            if (is_string($tid) && $tid !== '') {
                return $tid;
            }
        }

        return null;
    }

    /**
     * Get the auto page_view configuration section.
     *
     * @return array{enabled: bool, exclude_paths: list<string>, exclude_methods: list<string>, track_api: bool, track_status_codes: list<int>, bot_tracking: bool, strip_query_params: bool, max_url_length: int, sampling_rate: float}
     */
    private function getAutoPageViewConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = $this->config->get('zeroboiler.analytics.auto_pageview', []);

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'exclude_paths' => (array) ($config['exclude_paths'] ?? []),
            'exclude_methods' => (array) ($config['exclude_methods'] ?? ['OPTIONS', 'HEAD']),
            'track_api' => (bool) ($config['track_api'] ?? false),
            'track_status_codes' => (array) ($config['track_status_codes'] ?? [200, 301, 302, 303, 307, 308, 404]),
            'bot_tracking' => (bool) ($config['bot_tracking'] ?? false),
            'strip_query_params' => (bool) ($config['strip_query_params'] ?? true),
            'max_url_length' => (int) ($config['max_url_length'] ?? 2048),
            'sampling_rate' => (float) ($config['sampling_rate'] ?? 1.0),
        ];
    }
}
