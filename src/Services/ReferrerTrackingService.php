<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Referrer tracking service for conversion attribution.
 *
 * Captures, parses, and categorizes referral sources from HTTP requests.
 * Automatically detects UTM parameters, organic search, social media,
 * direct traffic, email campaigns, and custom campaigns. Stores first-touch
 * attribution persistently for conversion funnel analysis.
 *
 * Used by the AnalyticsReferrerMiddleware for automatic referrer capture.
 */
final class ReferrerTrackingService
{
    /**
     * Extract referrer information from a request.
     *
     * Returns a structured referrer object with source categorization
     * and UTM parameter extraction.
     *
     * @return array{url: string|null, domain: string|null, source: string|null, medium: string|null, campaign: string|null, term: string|null, content: string|null, is_first_visit: bool, social_network: string|null, search_engine: string|null}
     */
    public function extractReferrer(Request $request): array
    {
        $referrerUrl = $request->headers->get('referer');
        $utmSource = $request->query('utm_source');
        $utmMedium = $request->query('utm_medium');
        $utmCampaign = $request->query('utm_campaign');
        $utmTerm = $request->query('utm_term');
        $utmContent = $request->query('utm_content');

        $referrerDomain = null;
        $source = null;
        $medium = null;
        $socialNetwork = null;
        $searchEngine = null;

        // If UTM parameters are present, they take priority
        if (is_string($utmSource) && $utmSource !== '') {
            $source = $utmSource;
            $medium = is_string($utmMedium) && $utmMedium !== '' ? $utmMedium : $this->guessMedium($utmSource);
        } elseif (is_string($referrerUrl) && $referrerUrl !== '' && $referrerUrl !== '-') {
            $referrerDomain = $this->extractDomain($referrerUrl);

            // Check if the referrer is the same domain (internal)
            $currentDomain = $this->extractDomain($request->fullUrl());

            if ($referrerDomain !== null && $currentDomain !== null && strtolower($referrerDomain) === strtolower($currentDomain)) {
                $source = 'direct';
                $medium = 'none';
            } else {
                $detected = $this->detectSource($referrerDomain);
                $source = $detected['source'];
                $medium = $detected['medium'];
                $socialNetwork = $detected['social_network'];
                $searchEngine = $detected['search_engine'];
            }
        } else {
            $source = 'direct';
            $medium = 'none';
        }

        return [
            'url' => is_string($referrerUrl) ? $referrerUrl : null,
            'domain' => $referrerDomain,
            'source' => $source,
            'medium' => $medium,
            'campaign' => is_string($utmCampaign) && $utmCampaign !== '' ? $utmCampaign : null,
            'term' => is_string($utmTerm) && $utmTerm !== '' ? $utmTerm : null,
            'content' => is_string($utmContent) && $utmContent !== '' ? $utmContent : null,
            'is_first_visit' => false, // Will be set by middleware based on cookie
            'social_network' => $socialNetwork,
            'search_engine' => $searchEngine,
        ];
    }

    /**
     * Extract all UTM parameters from a request as an associative array.
     *
     * @return array{utm_source?: string, utm_medium?: string, utm_campaign?: string, utm_term?: string, utm_content?: string}
     */
    public function extractUtm(Request $request): array
    {
        $utm = [];

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && $value !== '') {
                $utm[$key] = $value;
            }
        }

        return $utm;
    }

    /**
     * Categorize a referrer domain into source/medium/social/search.
     *
     * @param  string|null  $domain
     * @return array{source: string, medium: string, social_network: string|null, search_engine: string|null}
     */
    private function detectSource(?string $domain): array
    {
        if ($domain === null || $domain === '') {
            return [
                'source' => 'direct',
                'medium' => 'none',
                'social_network' => null,
                'search_engine' => null,
            ];
        }

        $domain = strtolower($domain);

        // Social networks
        $socialNetworks = [
            'facebook.com' => 'facebook',
            'fb.com' => 'facebook',
            'instagram.com' => 'instagram',
            'twitter.com' => 'twitter',
            'x.com' => 'twitter',
            't.co' => 'twitter',
            'linkedin.com' => 'linkedin',
            'youtube.com' => 'youtube',
            'tiktok.com' => 'tiktok',
            'reddit.com' => 'reddit',
            'pinterest.com' => 'pinterest',
            'snapchat.com' => 'snapchat',
            'whatsapp.com' => 'whatsapp',
            'threads.net' => 'threads',
            'mastodon' => 'mastodon',
        ];

        foreach ($socialNetworks as $socialDomain => $network) {
            if ($domain === $socialDomain || str_ends_with($domain, '.' . $socialDomain)) {
                return [
                    'source' => $network,
                    'medium' => 'social',
                    'social_network' => $network,
                    'search_engine' => null,
                ];
            }
        }

        // Search engines
        $searchEngines = [
            'google.com' => 'google',
            'google.co' => 'google',
            'bing.com' => 'bing',
            'yahoo.com' => 'yahoo',
            'duckduckgo.com' => 'duckduckgo',
            'baidu.com' => 'baidu',
            'yandex.com' => 'yandex',
            'ecosia.org' => 'ecosia',
            'search.yahoo.com' => 'yahoo',
        ];

        foreach ($searchEngines as $engineDomain => $engine) {
            if ($domain === $engineDomain || str_ends_with($domain, '.' . $engineDomain)) {
                return [
                    'source' => $engine,
                    'medium' => 'organic',
                    'social_network' => null,
                    'search_engine' => $engine,
                ];
            }
        }

        // Email providers (mailto: links)
        if (str_contains($domain, 'mail.') || str_contains($domain, 'email')) {
            return [
                'source' => 'email',
                'medium' => 'email',
                'social_network' => null,
                'search_engine' => null,
            ];
        }

        // Default: referral
        return [
            'source' => $domain,
            'medium' => 'referral',
            'social_network' => null,
            'search_engine' => null,
        ];
    }

    /**
     * Extract domain from a URL.
     */
    private function extractDomain(string $url): ?string
    {
        $parsed = parse_url($url);

        if (! is_array($parsed) || ! isset($parsed['host'])) {
            return null;
        }

        $host = $parsed['host'];

        if (! is_string($host) || $host === '') {
            return null;
        }

        // Remove 'www.' prefix
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Guess the UTM medium from a source name.
     */
    private function guessMedium(string $source): string
    {
        $source = strtolower($source);

        $socialKeywords = ['facebook', 'instagram', 'twitter', 'linkedin', 'youtube', 'tiktok', 'reddit', 'pinterest', 'social'];
        $emailKeywords = ['email', 'mail', 'newsletter'];
        $searchKeywords = ['google', 'bing', 'yahoo', 'search'];
        $cpcKeywords = ['cpc', 'ppc', 'paid', 'adwords', 'ads', 'sponsored'];
        $affiliateKeywords = ['affiliate', 'partner', 'referral'];

        foreach ($socialKeywords as $keyword) {
            if (str_contains($source, $keyword)) {
                return 'social';
            }
        }

        foreach ($emailKeywords as $keyword) {
            if (str_contains($source, $keyword)) {
                return 'email';
            }
        }

        foreach ($searchKeywords as $keyword) {
            if (str_contains($source, $keyword)) {
                return 'organic';
            }
        }

        foreach ($cpcKeywords as $keyword) {
            if (str_contains($source, $keyword)) {
                return 'cpc';
            }
        }

        foreach ($affiliateKeywords as $keyword) {
            if (str_contains($source, $keyword)) {
                return 'affiliate';
            }
        }

        return 'referral';
    }

    /**
     * Build a landing page URL with UTM parameters appended.
     *
     * Useful for generating trackable links in admin interfaces.
     *
     * @param  string  $baseUrl  The landing page URL
     * @param  array<string, string>  $utm  UTM parameters (utm_source, utm_medium, utm_campaign, etc.)
     * @return string
     */
    public function buildTrackedUrl(string $baseUrl, array $utm): string
    {
        if (empty($utm)) {
            return $baseUrl;
        }

        $query = parse_url($baseUrl, PHP_URL_QUERY);
        $separator = $query === null ? '?' : '&';

        $params = [];
        foreach ($utm as $key => $value) {
            if (is_string($value) && $value !== '') {
                $params[] = urlencode($key) . '=' . urlencode($value);
            }
        }

        return $baseUrl . $separator . implode('&', $params);
    }

    /**
     * Normalize and validate a referrer URL.
     *
     * @return string|null Normalized URL or null if invalid
     */
    public function normalizeReferrer(?string $url): ?string
    {
        if ($url === null || $url === '' || $url === '-') {
            return null;
        }

        // Ensure scheme is present
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }

        $parsed = parse_url($url);

        if (! is_array($parsed) || ! isset($parsed['host'])) {
            return null;
        }

        return $url;
    }

    /**
     * Check if a referrer domain matches a known social network.
     */
    public function isSocialReferrer(?string $domain): bool
    {
        if ($domain === null) {
            return false;
        }

        $socialDomains = [
            'facebook.com', 'instagram.com', 'twitter.com', 'x.com',
            'linkedin.com', 'youtube.com', 'tiktok.com', 'reddit.com',
            'pinterest.com', 'snapchat.com', 'whatsapp.com', 'threads.net',
        ];

        $lower = strtolower($domain);

        foreach ($socialDomains as $socialDomain) {
            if ($lower === $socialDomain || str_ends_with($lower, '.' . $socialDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a referrer domain matches a known search engine.
     */
    public function isSearchEngineReferrer(?string $domain): bool
    {
        if ($domain === null) {
            return false;
        }

        $engineDomains = [
            'google.com', 'bing.com', 'yahoo.com', 'duckduckgo.com',
            'baidu.com', 'yandex.com', 'ecosia.org',
        ];

        $lower = strtolower($domain);

        foreach ($engineDomains as $engineDomain) {
            if ($lower === $engineDomain || str_ends_with($lower, '.' . $engineDomain)) {
                return true;
            }
        }

        return false;
    }
}
