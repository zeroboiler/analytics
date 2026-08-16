<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

/**
 * Device context parser — extracts structured device information from User-Agent strings.
 *
 * Parses the User-Agent header to extract device type, operating system,
 * browser name and version, and device brand/model. Used by EventContextBuilder
 * and the analytics pipeline to enrich events with device context without
 * requiring external dependencies.
 *
 * Inspired by Matomo/device-detector and ua-parser-js, but lightweight
 * and purpose-built for analytics event enrichment.
 *
 * @since 9.5.0
 */
final class DeviceContextParser
{
    /**
     * Parse a User-Agent string into structured device context.
     *
     * @param  string  $userAgent  Raw User-Agent header value
     * @return array{device_type: string|null, os: string|null, os_version: string|null, browser: string|null, browser_version: string|null, brand: string|null, is_bot: bool, is_mobile: bool, is_tablet: bool, is_desktop: bool}
     */
    public static function parse(string $userAgent): array
    {
        if ($userAgent === '') {
            return self::emptyResult();
        }

        $lower = strtolower($userAgent);
        $os = self::detectOs($lower);
        $browser = self::detectBrowser($lower);
        $deviceType = self::detectDeviceType($lower);
        $brand = self::detectBrand($lower);
        $isBot = self::detectBot($lower);

        return [
            'device_type' => $deviceType,
            'os' => $os['name'],
            'os_version' => $os['version'],
            'browser' => $browser['name'],
            'browser_version' => $browser['version'],
            'brand' => $brand,
            'is_bot' => $isBot,
            'is_mobile' => $deviceType === 'mobile',
            'is_tablet' => $deviceType === 'tablet',
            'is_desktop' => $deviceType === 'desktop',
        ];
    }

    /**
     * Get a simplified device category string.
     *
     * Returns 'mobile', 'tablet', 'desktop', 'bot', or 'unknown'.
     */
    public static function deviceCategory(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'unknown';
        }

        $lower = strtolower($userAgent);

        if (self::detectBot($lower)) {
            return 'bot';
        }

        return self::detectDeviceType($lower) ?? 'unknown';
    }

    /**
     * Detect the operating system from a User-Agent string.
     *
     * @return array{name: string|null, version: string|null}
     */
    private static function detectOs(string $lower): array
    {
        $osMap = [
            'windows nt 10' => ['Windows', '10'],
            'windows nt 6.3' => ['Windows', '8.1'],
            'windows nt 6.2' => ['Windows', '8'],
            'windows nt 6.1' => ['Windows', '7'],
            'windows nt 6.0' => ['Windows', 'Vista'],
            'windows nt 5.1' => ['Windows', 'XP'],
            'windows' => ['Windows', null],
            'mac os x' => ['macOS', null],
            'macintosh' => ['macOS', null],
            'iphone os' => ['iOS', null],
            'ipad' => ['iPadOS', null],
            'android' => ['Android', null],
            'linux' => ['Linux', null],
            'ubuntu' => ['Ubuntu', null],
            'debian' => ['Debian', null],
            'fedora' => ['Fedora', null],
            'chrome os' => ['ChromeOS', null],
            'cros' => ['ChromeOS', null],
            'harmonyos' => ['HarmonyOS', null],
            'tizen' => ['Tizen', null],
        ];

        foreach ($osMap as $pattern => [$name, $version]) {
            if (str_contains($lower, $pattern)) {
                $detectedVersion = self::extractVersion($lower, $pattern === 'iphone os' ? 'iphone os' : $pattern);

                return [
                    'name' => $name,
                    'version' => $detectedVersion ?? $version,
                ];
            }
        }

        return ['name' => null, 'version' => null];
    }

    /**
     * Detect the browser from a User-Agent string.
     *
     * @return array{name: string|null, version: string|null}
     */
    private static function detectBrowser(string $lower): array
    {
        // Order matters — more specific patterns first
        $browserMap = [
            'opera mini' => 'Opera Mini',
            'opera' => 'Opera',
            'edg/' => 'Edge',
            'oprx/' => 'Opera GX',
            'opr/' => 'Opera',
            'vivaldi' => 'Vivaldi',
            'brave' => 'Brave',
            'whale/' => 'Whale',
            'samsungbrowser' => 'Samsung Internet',
            'ucbrowser' => 'UC Browser',
            'firefox' => 'Firefox',
            'fxios' => 'Firefox iOS',
            'chrome' => 'Chrome',
            'crios' => 'Chrome iOS',
            'safari' => 'Safari',
            'trident' => 'Internet Explorer',
            'msie' => 'Internet Explorer',
        ];

        foreach ($browserMap as $pattern => $name) {
            if (str_contains($lower, $pattern)) {
                $version = self::extractVersion($lower, $pattern);

                return [
                    'name' => $name,
                    'version' => $version,
                ];
            }
        }

        return ['name' => null, 'version' => null];
    }

    /**
     * Detect device type from User-Agent string.
     *
     * @return string|null 'mobile', 'tablet', 'desktop', or null
     */
    private static function detectDeviceType(string $lower): ?string
    {
        // Tablets first (they also contain 'mobile')
        $tabletPatterns = [
            'ipad', 'tablet', 'playbook', 'kindle', 'silk',
            'nook', 'bookeen', 'kobo', 'pixi',
        ];

        foreach ($tabletPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return 'tablet';
            }
        }

        // Mobile devices
        $mobilePatterns = [
            'mobile', 'iphone', 'ipod', 'android', 'blackberry',
            'webos', 'opera mini', 'iemobile', 'windows phone',
            'samsung', 'nokia', 'sony', 'htc', 'huawei',
            'xiaomi', 'oppo', 'vivo', 'oneplus', 'pixel',
            'lg', 'motorola', 'zte', 'alcatel',
        ];

        foreach ($mobilePatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return 'mobile';
            }
        }

        return 'desktop';
    }

    /**
     * Detect device brand from User-Agent string.
     */
    private static function detectBrand(string $lower): ?string
    {
        $brandMap = [
            'apple' => ['iphone', 'ipad', 'macintosh', 'safari'],
            'samsung' => ['samsung', 'galaxy', 'sm-'],
            'google' => ['pixel', 'chromebook', 'cros'],
            'huawei' => ['huawei', 'honor'],
            'xiaomi' => ['xiaomi', 'mi ', 'redmi', 'poco'],
            'oneplus' => ['oneplus'],
            'oppo' => ['oppo'],
            'vivo' => ['vivo'],
            'microsoft' => ['windows', 'lumia', 'nokia', 'trident'],
            'sony' => ['sony', 'xperia'],
            'lg' => ['lg;'],
            'motorola' => ['motorola', 'moto '],
        ];

        foreach ($brandMap as $brand => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($lower, $pattern)) {
                    return $brand;
                }
            }
        }

        return null;
    }

    /**
     * Detect if the User-Agent is a bot/crawler.
     */
    private static function detectBot(string $lower): bool
    {
        $botPatterns = [
            'bot', 'crawl', 'spider', 'scrape', 'slurp',
            'mediapartners', 'adsbot', 'baiduspider', 'bingbot',
            'googlebot', 'yandexbot', 'duckduckbot', 'slurp',
            'facebookexternalhit', 'facebot', 'twitterbot',
            'linkedinbot', 'discordbot', 'telegrambot',
            'semrushbot', 'ahrefsbot', 'mj12bot', 'petalbot',
        ];

        foreach ($botPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract a version number following a pattern in the User-Agent.
     *
     * Looks for the pattern followed by optional separators and a version string.
     */
    private static function extractVersion(string $lower, string $pattern): ?string
    {
        $pos = strpos($lower, $pattern);

        if ($pos === false) {
            return null;
        }

        $after = substr($lower, $pos + strlen($pattern));

        // Match version number: optional separator + digits + optional .digits + optional .digits
        if (preg_match('/[\/\s;:_\-]+(\d+(?:\.\d+){0,3})/', $after, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Return an empty/default result.
     *
     * @return array{device_type: string|null, os: string|null, os_version: string|null, browser: string|null, browser_version: string|null, brand: string|null, is_bot: bool, is_mobile: bool, is_tablet: bool, is_desktop: bool}
     */
    private static function emptyResult(): array
    {
        return [
            'device_type' => null,
            'os' => null,
            'os_version' => null,
            'browser' => null,
            'browser_version' => null,
            'brand' => null,
            'is_bot' => false,
            'is_mobile' => false,
            'is_tablet' => false,
            'is_desktop' => false,
        ];
    }
}
