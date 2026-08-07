<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Services\DeviceContextService;

/**
 * Pipeline stage that enriches events with geolocation data based on the client IP.
 *
 * Adds country, region, city, timezone, and continent to event params.
 * Uses the DeviceContextService for User-Agent parsing and an optional
 * IP geolocation strategy (configurable via the `geolocation` config section).
 *
 * Supports three strategies:
 * - `ip2country`: Simple IP → country lookup (low overhead)
 * - `maxmind`: MaxMind GeoLite2 database (high accuracy, requires extension)
 * - `header`: Read pre-computed geo from reverse proxy headers (e.g., Cloudflare CF-IPCountry)
 */
final class GeolocationEnricher
{
    private const STRATEGY_HEADER = 'header';
    private const STRATEGY_IP2COUNTRY = 'ip2country';
    private const STRATEGY_MAXMIND = 'maxmind';

    /** @var array<string, string> Simple IP-to-country cache for ip2country strategy */
    private static array $ipCountryCache = [];

    private string $strategy;

    private string $countryHeader;

    private string $regionHeader;

    private string $cityHeader;

    private bool $enabled;

    private ?DeviceContextService $deviceService;

    /**
     * @param  string  $strategy  Geolocation strategy: 'header', 'ip2country', 'maxmind'
     * @param  string  $countryHeader  Header name for country (e.g., 'CF-IPCountry')
     * @param  string  $regionHeader  Header name for region
     * @param  string  $cityHeader  Header name for city
     * @param  bool  $enabled  Whether geolocation enrichment is enabled
     */
    public function __construct(
        string $strategy = self::STRATEGY_HEADER,
        string $countryHeader = 'CF-IPCountry',
        string $regionHeader = '',
        string $cityHeader = '',
        bool $enabled = true,
        ?DeviceContextService $deviceService = null,
    ): void {
        $this->strategy = $strategy;
        $this->countryHeader = $countryHeader;
        $this->regionHeader = $regionHeader;
        $this->cityHeader = $cityHeader;
        $this->enabled = $enabled;
        $this->deviceService = $deviceService;
    }

    /**
     * Enrich an event with geolocation data.
     *
     * Returns null if the event should be filtered out (not applicable here).
     */
    public function process(AnalyticsEvent $event): ?AnalyticsEvent
    {
        if (! $this->enabled) {
            return $event;
        }

        $ip = $event->params['__client_ip__'] ?? $event->params['ip'] ?? null;

        if (! is_string($ip) || $ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
            return $event;
        }

        $geo = $this->resolve($ip, $event->params);

        if (empty($geo)) {
            return $event;
        }

        // Merge geolocation data into event params
        $enrichedParams = array_merge($event->params, $geo);

        return new AnalyticsEvent(
            name: $event->name,
            params: $enrichedParams,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            consent: $event->consent,
        );
    }

    /**
     * Resolve geolocation for an IP address.
     *
     * @param  string  $ip  Client IP address
     * @param  array<string, mixed>  $requestParams  Request-level params (may contain headers)
     * @return array{geo_country?: string, geo_region?: string, geo_city?: string, geo_timezone?: string, geo_continent?: string}
     */
    private function resolve(string $ip, array $requestParams): array
    {
        return match ($this->strategy) {
            self::STRATEGY_HEADER => $this->resolveFromHeaders($requestParams),
            self::STRATEGY_IP2COUNTRY => $this->resolveIp2Country($ip),
            self::STRATEGY_MAXMIND => $this->resolveMaxMind($ip),
            default => [],
        };
    }

    /**
     * Read geolocation from reverse proxy headers.
     *
     * @param  array<string, mixed>  $params
     * @return array{geo_country?: string, geo_region?: string, geo_city?: string}
     */
    private function resolveFromHeaders(array $params): array
    {
        $geo = [];

        if ($this->countryHeader !== '' && isset($params['__headers__'][$this->countryHeader])) {
            $value = $params['__headers__'][$this->countryHeader];
            if (is_string($value) && $value !== '') {
                $geo['geo_country'] = $value;
            }
        }

        if ($this->regionHeader !== '' && isset($params['__headers__'][$this->regionHeader])) {
            $value = $params['__headers__'][$this->regionHeader];
            if (is_string($value) && $value !== '') {
                $geo['geo_region'] = $value;
            }
        }

        if ($this->cityHeader !== '' && isset($params['__headers__'][$this->cityHeader])) {
            $value = $params['__headers__'][$this->cityHeader];
            if (is_string($value) && $value !== '') {
                $geo['geo_city'] = $value;
            }
        }

        return $geo;
    }

    /**
     * Simple IP-to-country resolution using the first octet ranges.
     *
     * This is a lightweight, approximation-based approach that covers
     * major IP ranges. For production use, prefer MaxMind or a header-based
     * strategy from your reverse proxy.
     *
     * @return array{geo_country?: string}
     */
    private function resolveIp2Country(string $ip): array
    {
        if (isset(self::$ipCountryCache[$ip])) {
            return ['geo_country' => self::$ipCountryCache[$ip]];
        }

        $country = $this->guessCountryFromIp($ip);

        if ($country !== null) {
            // Cache up to 1000 IPs
            if (count(self::$ipCountryCache) < 1000) {
                self::$ipCountryCache[$ip] = $country;
            }

            return ['geo_country' => $country];
        }

        return [];
    }

    /**
     * Guess country from IP address using well-known ranges.
     *
     * Covers major US, EU, and APAC ranges. Returns null for unrecognized ranges.
     */
    private function guessCountryFromIp(string $ip): ?string
    {
        // Private/local ranges — skip
        if (str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.')) {
            return null;
        }

        if (str_starts_with($ip, '172.')) {
            $octet = (int) explode('.', $ip)[1] ?? 0;
            if ($octet >= 16 && $octet <= 31) {
                return null; // 172.16-31.x.x private
            }
        }

        // AWS/us-east ranges (simplified)
        if (str_starts_with($ip, '3.') || str_starts_with($ip, '34.') || str_starts_with($ip, '35.')) {
            return 'US';
        }

        if (str_starts_with($ip, '52.')) {
            return 'US';
        }

        // Major EU ranges
        if (str_starts_with($ip, '2a01:')) {
            return 'EU';
        }

        if (str_starts_with($ip, '5.')) {
            return 'EU';
        }

        if (str_starts_with($ip, '185.') || str_starts_with($ip, '91.')) {
            return 'EU';
        }

        // Major APAC ranges
        if (str_starts_with($ip, '13.') || str_starts_with($ip, '54.')) {
            return 'APAC';
        }

        if (str_starts_with($ip, '103.') || str_starts_with($ip, '119.')) {
            return 'CN';
        }

        if (str_starts_with($ip, '175.') || str_starts_with($ip, '118.')) {
            return 'KR';
        }

        return null;
    }

    /**
     * MaxMind GeoLite2-based resolution.
     *
     * Requires the maxminddb PHP extension or the GeoIP2 PHP library.
     * Falls back to empty result if not available.
     *
     * @return array{geo_country?: string, geo_region?: string, geo_city?: string, geo_timezone?: string, geo_continent?: string}
     */
    private function resolveMaxMind(string $ip): array
    {
        // Try geoip2-php library if available
        if (class_exists(\GeoIp2\Database\Reader::class)) {
            try {
                $dbPath = '/usr/share/GeoIP/GeoLite2-City.mmdb';

                if (! file_exists($dbPath)) {
                    return [];
                }

                $reader = new \GeoIp2\Database\Reader($dbPath);
                $record = $reader->city($ip);

                return array_filter([
                    'geo_country' => $record->country->isoCode,
                    'geo_region' => $record->mostSpecificSubdivision->isoCode,
                    'geo_city' => $record->city->name,
                    'geo_timezone' => $record->location->timeZone,
                    'geo_continent' => $record->continent->code,
                ]);
            } catch (\Throwable) {
                return [];
            }
        }

        // Try PHP built-in geoip extension
        if (function_exists('geoip_record_by_name')) {
            try {
                $record = @geoip_record_by_name($ip);

                if ($record === false) {
                    return [];
                }

                return array_filter([
                    'geo_country' => $record['country_code'] ?? null,
                    'geo_region' => $record['region'] ?? null,
                    'geo_city' => $record['city'] ?? null,
                ]);
            } catch (\Throwable) {
                return [];
            }
        }

        return [];
    }

    /**
     * Check if this enricher is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the current strategy name.
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Clear the internal IP-to-country cache.
     */
    public static function clearCache(): void
    {
        self::$ipCountryCache = [];
    }
}
