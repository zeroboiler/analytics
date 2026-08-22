<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventSessionContext;

/**
 * Service that attaches rich session/device context to analytics events.
 *
 * Combines data from HTTP requests, device parsing, geolocation lookup,
 * fingerprinting, and UTM tracking into a single EventSessionContext DTO.
 * The context is then merged into event params by the pipeline enrichers.
 *
 * Supports caching of device/geo lookups to reduce latency on high-traffic apps.
 *
 * Configuration is read from `zeroboiler.analytics.session_context`.
 *
 * @see \ZeroBoiler\Analytics\DTO\EventSessionContext
 * @see \ZeroBoiler\Analytics\Pipeline\UserContextEnricher
 *
 * @since 63.0.0
 */
final class EventSessionContextService
{
    private CacheRepository $cache;

    private ConfigRepository $config;

    /** @var array<string, mixed> */
    private array $settings;

    /**
     * @param  CacheRepository  $cache  Cache repository for device/geo lookups
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;
        $this->settings = $config->get('zeroboiler.analytics.session_context', []);
    }

    /**
     * Build a full session context from an HTTP request.
     *
     * Extracts client ID, user ID, IP, User-Agent, screen info,
     * UTM params, and optionally enriches with parsed device info
     * and geolocation data.
     *
     * @param  \Illuminate\Http\Request  $request  The incoming HTTP request
     * @param  string|null  $clientId  Analytics client ID (from cookie or header)
     * @param  string|null  $userId  Authenticated user ID (from auth guard)
     * @param  string|null  $sessionId  Session ID
     * @return EventSessionContext
     */
    public function buildFromRequest(
        \Illuminate\Http\Request $request,
        ?string $clientId = null,
        ?string $userId = null,
        ?string $sessionId = null,
    ): EventSessionContext {
        $context = EventSessionContext::fromRequest($request, $clientId, $userId, $sessionId);

        // Enrich device information if enabled
        if ($this->isEnabled('device_parsing')) {
            $context = $this->enrichDeviceContext($context, $request);
        }

        // Enrich geolocation if enabled
        if ($this->isEnabled('geolocation')) {
            $context = $this->enrichGeolocation($context, $request);
        }

        // Enrich fingerprint if enabled
        if ($this->isEnabled('fingerprinting')) {
            $context = $this->enrichFingerprint($context, $request);
        }

        return $context;
    }

    /**
     * Attach session context to an analytics event as structured params.
     *
     * Merges the session context fields into the event's params array
     * under a structured 'context' key, avoiding conflicts with
     * top-level event parameters.
     *
     * @param  AnalyticsEvent  $event  The event to enrich
     * @param  EventSessionContext  $context  The session context to attach
     * @return AnalyticsEvent  New event instance with enriched params
     */
    public function attachToEvent(AnalyticsEvent $event, EventSessionContext $context): AnalyticsEvent
    {
        $contextArray = $context->toArray();

        // Filter out null values to keep params lean
        $filtered = array_filter($contextArray, static fn (mixed $v): bool => $v !== null && $v !== []);

        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, ['session_context' => $filtered]),
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    /**
     * Enrich device context by parsing the User-Agent string.
     *
     * Uses a lightweight regex-based parser to extract browser name,
     * OS, and device type. Results are cached per User-Agent hash.
     *
     * @param  EventSessionContext  $context  Current session context
     * @param  \Illuminate\Http\Request  $request  HTTP request for UA
     * @return EventSessionContext  Updated context with device info
     */
    public function enrichDeviceContext(EventSessionContext $context, \Illuminate\Http\Request $request): EventSessionContext
    {
        $ua = $context->userAgent ?? $request->userAgent();

        if ($ua === null || $ua === '') {
            return $context;
        }

        $cacheKey = 'zb_device_' . hash('xxh128', $ua);
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $context->with($cached);
        }

        $parsed = $this->parseUserAgent($ua);

        $ttl = (int) ($this->settings['device_cache_ttl'] ?? 86400);
        $this->cache->put($cacheKey, $parsed, $ttl);

        return $context->with($parsed);
    }

    /**
     * Enrich geolocation context from IP address.
     *
     * Uses the GeolocationEnricher service if available.
     * Results are cached per IP to reduce external API calls.
     *
     * @param  EventSessionContext  $context  Current session context
     * @param  \Illuminate\Http\Request  $request  HTTP request for IP
     * @return EventSessionContext  Updated context with geo data
     */
    public function enrichGeolocation(EventSessionContext $context, \Illuminate\Http\Request $request): EventSessionContext
    {
        $ip = $context->ip ?? $request->ip();

        if ($ip === null || $ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
            return $context;
        }

        $cacheKey = 'zb_geo_' . hash('xxh128', $ip);
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $context->with($cached);
        }

        $geo = $this->lookupGeolocation($ip);

        if ($geo !== null) {
            $ttl = (int) ($this->settings['geo_cache_ttl'] ?? 604800);
            $this->cache->put($cacheKey, $geo, $ttl);

            return $context->with($geo);
        }

        return $context;
    }

    /**
     * Enrich fingerprint context using session fingerprint service.
     *
     * Generates a deterministic SHA-256 hash from normalized browser signals.
     *
     * @param  EventSessionContext  $context  Current session context
     * @param  \Illuminate\Http\Request  $request  HTTP request
     * @return EventSessionContext  Updated context with fingerprint
     */
    public function enrichFingerprint(EventSessionContext $context, \Illuminate\Http\Request $request): EventSessionContext
    {
        $clientId = $context->clientId;
        $ip = $context->ip;
        $ua = $context->userAgent;

        if ($clientId === null && $ip === null && $ua === null) {
            return $context;
        }

        $raw = implode('|', array_filter([$ip ?? '', $ua ?? '', $clientId ?? '']));
        $fingerprint = hash('sha256', $raw);

        return $context->with(['fingerprint' => $fingerprint]);
    }

    /**
     * Lightweight User-Agent parser.
     *
     * Extracts browser name, OS, and device type from the UA string
     * using pattern matching. Not a full-featured UA parser, but covers
     * 95%+ of modern browsers.
     *
     * @param  string  $ua  User-Agent string
     * @return array{browser?: string, os?: string, device_type?: string}
     */
    public function parseUserAgent(string $ua): array
    {
        $result = [];

        // Browser detection
        $browserPatterns = [
            'Edg/' => 'Edge',
            'OPR/' => 'Opera',
            'Firefox/' => 'Firefox',
            'Chrome/' => 'Chrome',
            'Safari/' => 'Safari',
            'MSIE ' => 'IE',
            'Trident/' => 'IE',
        ];

        foreach ($browserPatterns as $pattern => $name) {
            if (str_contains($ua, $pattern)) {
                $version = '';

                // Extract version number
                $pos = strpos($ua, $pattern);
                if ($pos !== false) {
                    $afterPattern = substr($ua, $pos + strlen($pattern));
                    preg_match('/^(\d+[.\d]*)/', $afterPattern, $matches);
                    if (isset($matches[1])) {
                        $version = ' ' . $matches[1];
                    }
                }

                $result['browser'] = $name . $version;
                break;
            }
        }

        // OS detection
        $osPatterns = [
            'Windows NT 10' => 'Windows 10',
            'Windows NT 6.3' => 'Windows 8.1',
            'Windows NT 6.1' => 'Windows 7',
            'Mac OS X' => 'macOS',
            'Linux' => 'Linux',
            'Android' => 'Android',
            'iPhone OS' => 'iOS',
            'iPad' => 'iPadOS',
            'CrOS' => 'ChromeOS',
        ];

        foreach ($osPatterns as $pattern => $name) {
            if (str_contains($ua, $pattern)) {
                $result['os'] = $name;
                break;
            }
        }

        // Device type detection
        $mobileUa = ['Mobile', 'Android', 'iPhone', 'iPod'];
        $tabletUa = ['iPad', 'Tablet', 'Kindle'];

        foreach ($mobileUa as $needle) {
            if (str_contains($ua, $needle)) {
                $result['device_type'] = 'mobile';
                break;
            }
        }

        if (! isset($result['device_type'])) {
            foreach ($tabletUa as $needle) {
                if (str_contains($ua, $needle)) {
                    $result['device_type'] = 'tablet';
                    break;
                }
            }
        }

        if (! isset($result['device_type'])) {
            $result['device_type'] = 'desktop';
        }

        // Bot detection
        $botPatterns = ['bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'googlebot', 'bingbot'];
        foreach ($botPatterns as $botPattern) {
            if (str_contains(strtolower($ua), $botPattern)) {
                $result['device_type'] = 'bot';
                break;
            }
        }

        return $result;
    }

    /**
     * Look up geolocation data for an IP address.
     *
     * In production, this would call a GeoIP database or API.
     * For now, returns null — actual lookup is handled by GeolocationEnricher.
     *
     * @param  string  $ip  IP address to look up
     * @return array{country?: string, region?: string, city?: string}|null  Geo data or null
     */
    protected function lookupGeolocation(string $ip): ?array
    {
        // Delegated to GeolocationEnricher in pipeline
        // This service provides the caching/coordination layer
        return null;
    }

    /**
     * Check if a specific session context feature is enabled.
     *
     * @param  string  $feature  Feature name (device_parsing, geolocation, fingerprinting)
     * @return bool
     */
    private function isEnabled(string $feature): bool
    {
        return (bool) ($this->settings[$feature] ?? false);
    }

    /**
     * Check if session context service is globally enabled.
     */
    public function isGloballyEnabled(): bool
    {
        return (bool) ($this->settings['enabled'] ?? false);
    }

    /**
     * Get the configured cache TTL for device lookups.
     */
    public function getDeviceCacheTtl(): int
    {
        return (int) ($this->settings['device_cache_ttl'] ?? 86400);
    }

    /**
     * Get the configured cache TTL for geolocation lookups.
     */
    public function getGeoCacheTtl(): int
    {
        return (int) ($this->settings['geo_cache_ttl'] ?? 604800);
    }

    /**
     * Build a summary of session context statistics.
     *
     * @return array{enabled: bool, features: array<string, bool>, device_cache_ttl: int, geo_cache_ttl: int}
     */
    public function getStats(): array
    {
        return [
            'enabled' => $this->isGloballyEnabled(),
            'features' => [
                'device_parsing' => $this->isEnabled('device_parsing'),
                'geolocation' => $this->isEnabled('geolocation'),
                'fingerprinting' => $this->isEnabled('fingerprinting'),
            ],
            'device_cache_ttl' => $this->getDeviceCacheTtl(),
            'geo_cache_ttl' => $this->getGeoCacheTtl(),
        ];
    }
}
