<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tracking;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

/**
 * SaaS Lifecycle Attribution Context Enricher.
 *
 * Automatically enriches SaaS lifecycle events (sign_up, login, trial_start,
 * subscription, plan_upgrade, cancellation, etc.) with attribution context:
 * UTM parameters, referrer URL, session ID, timestamp, and device context.
 *
 * Inspired by Segment's automatic Context enrichment, RudderStack's automatic
 * traits collection, and PostHog's automatic properties. This ensures every
 * lifecycle event carries the full attribution context needed for accurate
 * first-touch/last-touch attribution, funnel analysis, and cohort building.
 *
 * The enricher is designed to be called from LifecycleEventSubscriber::track()
 * and ServerSideTracker to automatically attach context to all server-side
 * lifecycle events without requiring manual parameter passing.
 *
 * Configuration: `zeroboiler.analytics.lifecycle_attribution`
 *
 * @see \ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber
 * @see \ZeroBoiler\Analytics\Tracking\ServerSideTracker
 *
 * @since 152.0.0
 */
final class LifecycleAttributionEnricher
{
    /**
     * Create a new lifecycle attribution enricher.
     *
     * @param  ConfigRepository  $config  Analytics configuration
     */
    public function __construct(
        private readonly ConfigRepository $config,
    ): void {}

    /**
     * Enrich an event's params with attribution context from the current request.
     *
     * Extracts UTM parameters, referrer, session ID, and device context from
     * the active HTTP request and merges them into the event params. Existing
     * params are preserved (no overwriting).
     *
     * Call this before dispatching any lifecycle event to ensure full
     * attribution context is captured.
     *
     * @param  array<string, mixed>  $params  Original event parameters
     * @return array<string, mixed>  Enriched event parameters
     */
    public function enrich(array $params = []): array
    {
        if (! $this->isEnabled()) {
            return $params;
        }

        $request = $this->getRequest();
        if ($request === null) {
            return $params;
        }

        $enrichment = [];

        // UTM parameters (first-touch attribution)
        if ($this->shouldEnrich('utm')) {
            $enrichment = array_merge($enrichment, $this->extractUtm($request));
        }

        // Referrer (attribution source)
        if ($this->shouldEnrich('referrer')) {
            $enrichment = array_merge($enrichment, $this->extractReferrer($request));
        }

        // Session context
        if ($this->shouldEnrich('session')) {
            $enrichment = array_merge($enrichment, $this->extractSession($request));
        }

        // Device context (platform, browser, locale)
        if ($this->shouldEnrich('device')) {
            $enrichment = array_merge($enrichment, $this->extractDevice($request));
        }

        // Timestamp
        if ($this->shouldEnrich('timestamp')) {
            $enrichment = array_merge($enrichment, $this->extractTimestamp());
        }

        // Page context
        if ($this->shouldEnrich('page')) {
            $enrichment = array_merge($enrichment, $this->extractPage($request));
        }

        // Merge enrichment into params (params take precedence)
        return array_merge($enrichment, $params);
    }

    /**
     * Enrich params with attribution context and also add the attribution summary.
     *
     * In addition to the standard enrichment, adds a computed `attribution_summary`
     * field that categorizes the traffic source into: direct, organic, paid_search,
     * paid_social, referral, email, affiliate, or unknown. This classification
     * is used by downstream funnel and cohort analysis services.
     *
     * @param  array<string, mixed>  $params  Original event parameters
     * @return array<string, mixed>  Enriched event parameters with attribution summary
     */
    public function enrichWithSummary(array $params = []): array
    {
        $enriched = $this->enrich($params);

        if (! $this->isEnabled() || ! $this->shouldEnrich('attribution_summary')) {
            return $enriched;
        }

        $enriched['attribution_summary'] = $this->classifyAttribution($enriched);

        return $enriched;
    }

    /**
     * Check if the lifecycle attribution enricher is enabled.
     */
    public function isEnabled(): bool
    {
        $attributionConfig = $this->config->get('zeroboiler.analytics.lifecycle_attribution', []);
        /** @var array{enabled?: bool} $attributionConfig */

        return (bool) ($attributionConfig['enabled'] ?? true);
    }

    /**
     * Check if a specific enrichment type is enabled.
     *
     * @param  string  $type  Enrichment type (utm, referrer, session, device, timestamp, page, attribution_summary)
     */
    private function shouldEnrich(string $type): bool
    {
        $attributionConfig = $this->config->get('zeroboiler.analytics.lifecycle_attribution', []);
        /** @var array{enrichments?: array<string, bool>} $attributionConfig */
        $enrichments = $attributionConfig['enrichments'] ?? [];

        return (bool) ($enrichments[$type] ?? true);
    }

    /**
     * Get the current HTTP request, or null if not in a request context.
     */
    private function getRequest(): ?Request
    {
        try {
            return request();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extract UTM parameters from the request.
     *
     * @return array<string, mixed>
     */
    private function extractUtm(Request $request): array
    {
        $utm = [];
        $utmFields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

        foreach ($utmFields as $field) {
            $value = $request->query($field);
            if (is_string($value) && $value !== '') {
                $utm[$field] = $value;
            }
        }

        return $utm;
    }

    /**
     * Extract referrer information from the request.
     *
     * @return array<string, mixed>
     */
    private function extractReferrer(Request $request): array
    {
        $referrer = $request->headers->get('referer');

        if (! is_string($referrer) || $referrer === '') {
            return [];
        }

        $parsed = parse_url($referrer);

        return [
            'referrer_url' => $referrer,
            'referrer_host' => $parsed['host'] ?? '',
            'referrer_path' => $parsed['path'] ?? '',
        ];
    }

    /**
     * Extract session context from the request.
     *
     * @return array<string, mixed>
     */
    private function extractSession(Request $request): array
    {
        $context = [];

        try {
            $sessionId = $request->session()->getId();
            if (is_string($sessionId) && $sessionId !== '') {
                $context['session_id'] = $sessionId;
            }
        } catch (\Throwable) {
            // Session may not be available
        }

        $context['ip'] = $request->ip() ?? '';

        return $context;
    }

    /**
     * Extract device context from the request.
     *
     * @return array<string, mixed>
     */
    private function extractDevice(Request $request): array
    {
        $userAgent = $request->userAgent() ?? '';

        return [
            'platform' => $this->detectPlatform($userAgent),
            'browser' => $this->detectBrowser($userAgent),
            'locale' => $request->locale(),
            'user_agent_raw' => mb_strlen($userAgent) > 500 ? mb_substr($userAgent, 0, 500) : $userAgent,
        ];
    }

    /**
     * Extract timestamp context.
     *
     * @return array<string, mixed>
     */
    private function extractTimestamp(): array
    {
        return [
            'server_timestamp' => time(),
            'server_datetime' => date('Y-m-d\TH:i:s\Z'),
            'hour_of_day' => (int) date('G'),
            'day_of_week' => (int) date('N'),
        ];
    }

    /**
     * Extract page context from the request.
     *
     * @return array<string, mixed>
     */
    private function extractPage(Request $request): array
    {
        return [
            'page_url' => $request->fullUrl(),
            'page_path' => $request->path(),
            'page_host' => $request->host(),
        ];
    }

    /**
     * Classify the traffic source from enriched params.
     *
     * Uses a rule-based classification engine that examines UTM parameters
     * and referrer to determine the most likely traffic source category.
     *
     * Classification rules (in priority order):
     * 1. UTM medium=prefixed → paid_search, paid_social, paid_display, etc.
     * 2. Referrer known social domain → paid_social or organic_social
     * 3. Referrer known search engine → organic_search
     * 4. Referrer from email client → email
     * 5. UTM source contains "affiliate" → affiliate
     * 6. Direct traffic (no referrer) → direct
     * 7. Other referrer → referral
     * 8. Fallback → unknown
     *
     * @param  array<string, mixed>  $params  Enriched event params (must contain UTM and referrer)
     * @return string  Classification: direct|organic_search|organic_social|paid_search|paid_social|paid_display|email|affiliate|referral|unknown
     */
    public function classifyAttribution(array $params): string
    {
        $utmMedium = $params['utm_medium'] ?? '';
        $utmSource = $params['utm_source'] ?? '';
        $referrerHost = $params['referrer_host'] ?? '';

        // 1. Paid channels (UTM medium prefix)
        if (str_starts_with($utmMedium, 'paid') || str_starts_with($utmMedium, 'cpc') || str_starts_with($utmMedium, 'ppc')) {
            if ($this->isSocialDomain($referrerHost) || str_contains($utmSource, 'social') || str_contains($utmSource, 'facebook') || str_contains($utmSource, 'instagram') || str_contains($utmSource, 'twitter') || str_contains($utmSource, 'linkedin') || str_contains($utmSource, 'tiktok')) {
                return 'paid_social';
            }

            return 'paid_search';
        }

        if ($utmMedium === 'social') {
            return 'organic_social';
        }

        if ($utmMedium === 'display' || $utmMedium === 'banner') {
            return 'paid_display';
        }

        if ($utmMedium === 'email') {
            return 'email';
        }

        // 2. Affiliate detection
        if (str_contains($utmSource, 'affiliate') || str_contains($utmSource, 'partner') || str_contains($utmSource, 'referral')) {
            return 'affiliate';
        }

        // 3. Referrer-based classification
        if ($referrerHost !== '') {
            if ($this->isSearchEngine($referrerHost)) {
                return 'organic_search';
            }

            if ($this->isSocialDomain($referrerHost)) {
                return 'organic_social';
            }

            if ($this->isEmailClient($referrerHost)) {
                return 'email';
            }

            return 'referral';
        }

        // 4. UTM source without medium
        if ($utmSource !== '') {
            return 'referral';
        }

        // 5. Direct traffic
        return 'direct';
    }

    /**
     * Check if a hostname belongs to a known search engine.
     */
    private function isSearchEngine(string $host): bool
    {
        $searchEngines = [
            'google', 'google.com', 'google.co.uk', 'google.de',
            'bing.com', 'yahoo.com', 'yandex.com', 'yandex.ru',
            'baidu.com', 'duckduckgo.com', 'ecosia.org',
            'naver.com', 'sogou.com', 'ask.com', 'aol.com',
        ];

        $lower = strtolower($host);

        foreach ($searchEngines as $engine) {
            if ($lower === $engine || str_ends_with($lower, '.' . $engine)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a hostname belongs to a known social media platform.
     */
    private function isSocialDomain(string $host): bool
    {
        $socialDomains = [
            'facebook.com', 'instagram.com', 'twitter.com', 'x.com',
            'linkedin.com', 'tiktok.com', 'youtube.com', 'reddit.com',
            'pinterest.com', 'snapchat.com', 'whatsapp.com',
            'threads.net', 'mastodon', 't.me',
        ];

        $lower = strtolower($host);

        foreach ($socialDomains as $domain) {
            if ($lower === $domain || str_ends_with($lower, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a hostname belongs to a known email client.
     */
    private function isEmailClient(string $host): bool
    {
        $emailClients = [
            'mail.google.com', 'outlook.live.com', 'outlook.office.com',
            'mail.yahoo.com', 'mailchimp.com', 'sendgrid.com',
            'substack.com', 'convertkit.com', 'buttondown.com',
        ];

        $lower = strtolower($host);

        foreach ($emailClients as $client) {
            if ($lower === $client || str_ends_with($lower, '.' . $client)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect the platform from user agent string.
     *
     * Returns a generic platform identifier for analytics grouping.
     */
    private function detectPlatform(string $userAgent): string
    {
        $lower = strtolower($userAgent);

        if (str_contains($lower, 'android')) {
            return 'android';
        }

        if (str_contains($lower, 'iphone') || str_contains($lower, 'ipad')) {
            return 'ios';
        }

        if (str_contains($lower, 'macintosh') || str_contains($lower, 'mac os')) {
            return 'macos';
        }

        if (str_contains($lower, 'windows')) {
            return 'windows';
        }

        if (str_contains($lower, 'linux')) {
            return 'linux';
        }

        return 'unknown';
    }

    /**
     * Detect the browser from user agent string.
     *
     * Returns a generic browser identifier for analytics grouping.
     */
    private function detectBrowser(string $userAgent): string
    {
        $lower = strtolower($userAgent);

        if (str_contains($lower, 'edg/')) {
            return 'edge';
        }

        if (str_contains($lower, 'chrome') && ! str_contains($lower, 'edg')) {
            return 'chrome';
        }

        if (str_contains($lower, 'firefox')) {
            return 'firefox';
        }

        if (str_contains($lower, 'safari') && ! str_contains($lower, 'chrome')) {
            return 'safari';
        }

        if (str_contains($lower, 'opera') || str_contains($lower, 'opr/')) {
            return 'opera';
        }

        return 'unknown';
    }

    /**
     * Get a diagnostic summary of the enricher configuration.
     *
     * @return array{enabled: bool, enrichments: array<string, bool>, config_source: string}
     */
    public function diagnosticSummary(): array
    {
        $attributionConfig = $this->config->get('zeroboiler.analytics.lifecycle_attribution', []);
        /** @var array{enabled?: bool, enrichments?: array<string, bool>} $attributionConfig */
        $enrichments = $attributionConfig['enrichments'] ?? [];

        $allTypes = ['utm', 'referrer', 'session', 'device', 'timestamp', 'page', 'attribution_summary'];
        $activeEnrichments = [];

        foreach ($allTypes as $type) {
            $activeEnrichments[$type] = (bool) ($enrichments[$type] ?? true);
        }

        return [
            'enabled' => $this->isEnabled(),
            'enrichments' => $activeEnrichments,
            'config_source' => 'zeroboiler.analytics.lifecycle_attribution',
        ];
    }
}
