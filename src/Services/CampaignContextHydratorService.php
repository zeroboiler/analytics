<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Campaign context hydrator for automatic UTM/referrer/session enrichment.
 *
 * Reads UTM parameters, referrer, and session data from the incoming request
 * and builds a structured campaign context that can be:
 *
 * - Auto-injected into every analytics event via pipeline enrichment
 * - Exposed to the client via Inertia props for client-side attribution
 * - Cached as first-touch attribution for cross-session tracking
 *
 * This service centralizes campaign context extraction so it's consistent
 * across server-side lifecycle tracking, API event ingestion, and
 * Inertia middleware hydration.
 *
 * @since 195.0.0
 */
final class CampaignContextHydratorService
{
    private const CACHE_PREFIX = 'zb_campaign_';

    private const FIRST_TOUCH_KEY = 'first_touch';

    private const FIRST_TOUCH_TTL = 7776000; // 90 days

    /** @var list<string> Standard UTM parameter names */
    private const UTM_PARAMS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
    ];

    private const TRAFFIC_SOURCES = [
        'direct',
        'organic_search',
        'paid_search',
        'paid_social',
        'organic_social',
        'email',
        'referral',
        'affiliate',
        'other',
    ];

    private ConfigRepository $config;

    private ?int $cacheTtl;

    /**
     * @param  ConfigRepository  $config  Application config repository
     * @param  int|null  $cacheTtl  Cache TTL for campaign context (seconds). Null = disabled.
     */
    public function __construct(ConfigRepository $config, ?int $cacheTtl = null){
        $this->config = $config;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Extract full campaign context from an HTTP request.
     *
     * Reads UTM parameters, referrer, and session data, classifies
     * the traffic source, and optionally persists first-touch data.
     *
     * @return array{
     *     utm_source: string|null,
     *     utm_medium: string|null,
     *     utm_campaign: string|null,
     *     utm_term: string|null,
     *     utm_content: string|null,
     *     referrer: string|null,
     *     referrer_domain: string|null,
     *     landing_page: string,
     *     landing_url: string,
     *     traffic_source: string,
     *     has_utm: bool,
     *     session_id: string|null,
     *     user_agent: string|null,
     *     ip: string|null,
     *     timestamp: string,
     *     geo_country: string|null,
     *     geo_city: string|null,
     *     device_type: string|null,
     * }
     */
    public function extractFromRequest(Request $request): array
    {
        $utm = $this->extractUtm($request);
        $referrer = $request->headers->get('referer');
        $referrerDomain = $this->parseDomain($referrer);

        $context = [
            'utm_source' => $utm['utm_source'] ?? null,
            'utm_medium' => $utm['utm_medium'] ?? null,
            'utm_campaign' => $utm['utm_campaign'] ?? null,
            'utm_term' => $utm['utm_term'] ?? null,
            'utm_content' => $utm['utm_content'] ?? null,
            'referrer' => $referrer,
            'referrer_domain' => $referrerDomain,
            'landing_page' => $request->path(),
            'landing_url' => $request->fullUrl(),
            'traffic_source' => $this->classifyTrafficSource($utm, $referrer),
            'has_utm' => count(array_filter($utm)) > 0,
            'session_id' => $request->session()->getId(),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
            'timestamp' => now()->toIso8601String(),
            'geo_country' => null,
            'geo_city' => null,
            'device_type' => $this->detectDeviceType($request->userAgent()),
        ];

        // Geolocation enrichment (if enabled)
        $geoConfig = $this->config->get('zeroboiler.analytics.geolocation', []);
        /** @var array{enabled?: bool} $geoConfig */
        if ($geoConfig['enabled'] ?? false) {
            $geoHeader = $request->headers->get('X-Geo-Country');
            $geoCityHeader = $request->headers->get('X-Geo-City');
            $context['geo_country'] = is_string($geoHeader) ? $geoHeader : null;
            $context['geo_city'] = is_string($geoCityHeader) ? $geoCityHeader : null;
        }

        return $context;
    }

    /**
     * Get UTM parameters as a flat key-value array.
     *
     * Returns only parameters that are present (non-empty strings).
     *
     * @return array<string, string>
     */
    public function extractUtm(Request $request): array
    {
        $result = [];
        foreach (self::UTM_PARAMS as $param) {
            $value = $request->query($param);
            if (is_string($value) && $value !== '') {
                $result[$param] = $value;
            }
        }

        return $result;
    }

    /**
     * Store first-touch attribution data for a given client ID.
     *
     * First-touch data is persisted once and never overwritten.
     * Used for cross-session attribution reporting.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  array<string, mixed>  $context  Campaign context to store
     */
    public function persistFirstTouch(string $clientId, array $context): void
    {
        if ($clientId === '' || $this->cacheTtl === null) {
            return;
        }

        $key = self::CACHE_PREFIX . self::FIRST_TOUCH_KEY . ':' . $clientId;

        // Only persist if no first-touch exists (don't overwrite)
        if (Cache::has($key)) {
            return;
        }

        Cache::put($key, $this->sanitizeForStorage($context), $this->cacheTtl);
    }

    /**
     * Retrieve first-touch attribution data for a given client ID.
     *
     * @param  string  $clientId  Client tracking ID
     * @return array<string, mixed>|null First-touch context, or null if none exists
     */
    public function getFirstTouch(string $clientId): ?array
    {
        if ($clientId === '') {
            return null;
        }

        $key = self::CACHE_PREFIX . self::FIRST_TOUCH_KEY . ':' . $clientId;
        $data = Cache::get($key);

        return is_array($data) ? $data : null;
    }

    /**
     * Get the full attribution context for a client ID.
     *
     * Merges current session UTM data with persisted first-touch data
     * and adds computed attribution fields.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  array<string, mixed>  $current  Current request campaign context
     * @return array{current: array<string, mixed>, first_touch: array<string, mixed>|null, attribution_summary: string}
     */
    public function getAttributionContext(string $clientId, array $current): array
    {
        $firstTouch = $this->getFirstTouch($clientId);

        $attributionSummary = 'direct';
        if (($firstTouch['utm_source'] ?? '') !== '') {
            $attributionSummary = $firstTouch['utm_source'];
        } elseif (($firstTouch['referrer_domain'] ?? '') !== '') {
            $attributionSummary = 'referral:' . $firstTouch['referrer_domain'];
        }

        return [
            'current' => $current,
            'first_touch' => $firstTouch,
            'attribution_summary' => $attributionSummary,
        ];
    }

    /**
     * Classify the traffic source based on UTM and referrer data.
     *
     * Follows industry-standard classification rules:
     * - UTM medium=paid_search or source contains google/bing → paid_search
     * - UTM medium=paid_social or source contains facebook/instagram/twitter/tiktok → paid_social
     * - UTM medium=organic → organic_search
     * - UTM medium=email → email
     * - UTM source=affiliate → affiliate
     * - Referrer exists and is external → referral
     * - No referrer → direct
     *
     * @param  array<string, string>  $utm  Extracted UTM parameters
     * @param  string|null  $referrer  HTTP referrer URL
     * @return string One of TRAFFIC_SOURCES
     */
    public function classifyTrafficSource(array $utm, ?string $referrer): string
    {
        $medium = $utm['utm_medium'] ?? '';
        $source = $utm['utm_source'] ?? '';

        if ($medium !== '') {
            return match (strtolower($medium)) {
                'cpc', 'ppc', 'paid_search' => 'paid_search',
                'paid_social', 'social', 'display', 'cpm' => 'paid_social',
                'organic', 'organic_search', 'seo' => 'organic_search',
                'email', 'e-mail', 'newsletter' => 'email',
                'affiliate', 'affiliate_paid' => 'affiliate',
                'referral' => 'referral',
                'organic_social' => 'organic_social',
                default => 'other',
            };
        }

        // Source-based classification
        if ($source !== '') {
            $lowerSource = strtolower($source);
            $paidSearchSources = ['google', 'google_ads', 'bing', 'yahoo', 'baidu', 'yandex'];
            $paidSocialSources = ['facebook', 'instagram', 'twitter', 'x', 'tiktok', 'linkedin', 'pinterest', 'snapchat'];

            if (in_array($lowerSource, $paidSearchSources, true)) {
                return 'paid_search';
            }

            if (in_array($lowerSource, $paidSocialSources, true)) {
                return 'paid_social';
            }

            if ($lowerSource === 'affiliate') {
                return 'affiliate';
            }

            // Has source but no recognized pattern
            return $this->hasReferrer($referrer) ? 'referral' : 'other';
        }

        // Referrer-based classification
        if ($this->hasReferrer($referrer)) {
            $domain = strtolower($this->parseDomain($referrer) ?? '');
            $searchEngines = ['google.com', 'bing.com', 'yahoo.com', 'baidu.com', 'yandex.com', 'duckduckgo.com'];
            $socialNetworks = ['facebook.com', 'instagram.com', 'twitter.com', 'x.com', 'tiktok.com', 'linkedin.com'];

            if ($this->domainMatchesAny($domain, $searchEngines)) {
                return 'organic_search';
            }

            if ($this->domainMatchesAny($domain, $socialNetworks)) {
                return 'organic_social';
            }

            return 'referral';
        }

        return 'direct';
    }

    /**
     * Build a minimal client-safe campaign context for Inertia props.
     *
     * Strips sensitive data (IP, user agent) for client-side consumption.
     *
     * @param  array<string, mixed>  $context  Full campaign context from extractFromRequest()
     * @return array{utm: array<string, string>, referrer: string|null, traffic_source: string, has_utm: bool}
     */
    public function toClientSafeContext(array $context): array
    {
        return [
            'utm' => array_filter([
                'source' => $context['utm_source'] ?? null,
                'medium' => $context['utm_medium'] ?? null,
                'campaign' => $context['utm_campaign'] ?? null,
                'term' => $context['utm_term'] ?? null,
                'content' => $context['utm_content'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== ''),
            'referrer' => $context['referrer'],
            'traffic_source' => $context['traffic_source'],
            'has_utm' => (bool) ($context['has_utm'] ?? false),
        ];
    }

    /**
     * Parse domain from a URL string.
     *
     * @param  string|null  $url  Full URL
     * @return string|null  Domain name without scheme/path
     */
    private function parseDomain(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Check if a referrer URL exists and is not empty.
     */
    private function hasReferrer(?string $referrer): bool
    {
        return $referrer !== null && $referrer !== '';
    }

    /**
     * Check if a domain matches any domain in a list (supports subdomains).
     *
     * @param  string  $domain  Domain to check
     * @param  list<string>  $domains  List of domains to match against
     */
    private function domainMatchesAny(string $domain, array $domains): bool
    {
        foreach ($domains as $d) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect device type from user agent string.
     *
     * @param  string|null  $userAgent  User agent string
     * @return string One of: desktop, mobile, tablet, bot, unknown
     */
    private function detectDeviceType(?string $userAgent): string
    {
        if ($userAgent === null || $userAgent === '') {
            return 'unknown';
        }

        $ua = strtolower($userAgent);

        if (str_contains($ua, 'bot') || str_contains($ua, 'crawl') || str_contains($ua, 'spider')) {
            return 'bot';
        }

        if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
            return 'tablet';
        }

        if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * Remove sensitive fields before caching.
     *
     * @param  array<string, mixed>  $context  Raw campaign context
     * @return array<string, mixed>  Sanitized context
     */
    private function sanitizeForStorage(array $context): array
    {
        unset($context['ip'], $context['user_agent'], $context['session_id']);

        return $context;
    }
}
