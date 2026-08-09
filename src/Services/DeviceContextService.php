<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Http\Request;

/**
 * Device context service for client-side information extraction.
 *
 * Parses User-Agent strings and request data to provide structured
 * device, browser, and OS information for analytics events.
 *
 * Used by the EventContextBuilder and API controller to enrich
 * server-side events with client context data.
 *
 * All parsing is done without external dependencies — using regex
 * patterns for lightweight, zero-dependency User-Agent parsing.
 *
 * @since 1.0.0
 */
final class DeviceContextService
{
    /**
     * Extract device context from an HTTP request.
     *
     * @return array{browser: string|null, browser_version: string|null, os: string|null, os_version: string|null, device_type: string, device_brand: string|null, is_mobile: bool, is_tablet: bool, is_desktop: bool, is_bot: bool, viewport_width: int|null, viewport_height: int|null, screen_resolution: string|null, language: string, timezone: string|null}
     */
    public function fromRequest(Request $request): array
    {
        $userAgent = $request->userAgent() ?? '';
        $parsed = $this->parseUserAgent($userAgent);

        return array_merge($parsed, [
            'language' => $request->locale(),
            'timezone' => $request->header('X-Timezone'),
        ]);
    }

    /**
     * Parse a User-Agent string into structured components.
     *
     * @return array{browser: string|null, browser_version: string|null, os: string|null, os_version: string|null, device_type: string, device_brand: string|null, is_mobile: bool, is_tablet: bool, is_desktop: bool, is_bot: bool}
     */
    public function parseUserAgent(string $userAgent): array
    {
        if ($userAgent === '') {
            return $this->emptyContext();
        }

        return [
            'browser' => $this->detectBrowser($userAgent),
            'browser_version' => $this->detectBrowserVersion($userAgent),
            'os' => $this->detectOS($userAgent),
            'os_version' => $this->detectOSVersion($userAgent),
            'device_type' => $this->detectDeviceType($userAgent),
            'device_brand' => $this->detectDeviceBrand($userAgent),
            'is_mobile' => $this->isMobile($userAgent),
            'is_tablet' => $this->isTablet($userAgent),
            'is_desktop' => $this->isDesktop($userAgent),
            'is_bot' => $this->isBot($userAgent),
        ];
    }

    /**
     * Detect browser name from User-Agent.
     */
    public function detectBrowser(string $userAgent): ?string
    {
        $browsers = [
            'Opera' => '/\bOpera\b/i',
            'Opera Mini' => '/Opera Mini/i',
            'Samsung Browser' => '/SamsungBrowser/i',
            'UCBrowser' => '/UCBrowser/i',
            'Firefox' => '/\bFirefox\b/i',
            'Brave' => '/\bBrave\b/i',
            'Vivaldi' => '/\bVivaldi\b/i',
            'Arc' => '/\bArc\b/i',
            'Edge' => '/\bEdg(?:e|A|iOS)?\b/i',
            'Chrome' => '/\bChrome(?:\/|\b)/i',
            'Safari' => '/\bSafari\b/i',
            'Internet Explorer' => '/\bMSIE\b|\bTrident\b/i',
            'Lynx' => '/\bLynx\b/i',
        ];

        foreach ($browsers as $name => $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Detect browser version from User-Agent.
     */
    public function detectBrowserVersion(string $userAgent): ?string
    {
        $browser = $this->detectBrowser($userAgent);

        $patterns = [
            'Opera' => '/Opera\/(\d+[\.\d]*)/i',
            'Opera Mini' => '/Opera Mini\/(\d+[\.\d]*)/i',
            'Samsung Browser' => '/SamsungBrowser\/(\d+[\.\d]*)/i',
            'UCBrowser' => '/UCBrowser\/(\d+[\.\d]*)/i',
            'Firefox' => '/Firefox\/(\d+[\.\d]*)/i',
            'Brave' => '/Brave\/(\d+[\.\d]*)/i',
            'Vivaldi' => '/Vivaldi\/(\d+[\.\d]*)/i',
            'Arc' => '/Arc\/(\d+[\.\d]*)/i',
            'Edge' => '/Edg(?:e|A|iOS)?\/(\d+[\.\d]*)/i',
            'Chrome' => '/Chrome\/(\d+[\.\d]*)/i',
            'Safari' => '/Version\/(\d+[\.\d]*)/i',
            'Internet Explorer' => '/(?:MSIE |rv:)(\d+[\.\d]*)/i',
            'Lynx' => '/Lynx\/(\d+[\.\d]*)/i',
        ];

        $pattern = $patterns[$browser] ?? null;

        if ($pattern === null) {
            return null;
        }

        if (preg_match($pattern, $userAgent, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Detect operating system from User-Agent.
     */
    public function detectOS(string $userAgent): ?string
    {
        $osMap = [
            'Windows' => '/Windows/i',
            'macOS' => '/Macintosh|Mac OS X/i',
            'iOS' => '/iPhone|iPad|iPod/i',
            'Android' => '/Android/i',
            'Linux' => '/Linux/i',
            'ChromeOS' => '/CrOS/i',
            'Ubuntu' => '/Ubuntu/i',
            'Fedora' => '/Fedora/i',
            'HarmonyOS' => '/HarmonyOS/i',
        ];

        foreach ($osMap as $name => $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Detect operating system version from User-Agent.
     */
    public function detectOSVersion(string $userAgent): ?string
    {
        $os = $this->detectOS($userAgent);

        $patterns = [
            'Windows' => '/Windows NT (\d+[\.\d]*)/',
            'macOS' => '/Mac OS X (\d+[._\d]*)/',
            'iOS' => '/OS (\d+[_\d]*)/',
            'Android' => '/Android (\d+[\.\d]*)/',
            'ChromeOS' => '/CrOS (\d+[\.\d]*)/',
            'Ubuntu' => '/Ubuntu\/(\d+[\.\d]*)/',
            'HarmonyOS' => '/HarmonyOS (\d+[\.\d]*)/',
        ];

        $pattern = $patterns[$os] ?? null;

        if ($pattern === null) {
            return null;
        }

        if (preg_match($pattern, $userAgent, $matches)) {
            return str_replace('_', '.', $matches[1]);
        }

        return null;
    }

    /**
     * Detect device type from User-Agent.
     *
     * @return string One of: 'mobile', 'tablet', 'desktop', 'bot', 'unknown'
     */
    public function detectDeviceType(string $userAgent): string
    {
        if ($this->isBot($userAgent)) {
            return 'bot';
        }

        if ($this->isTablet($userAgent)) {
            return 'tablet';
        }

        if ($this->isMobile($userAgent)) {
            return 'mobile';
        }

        if ($this->isDesktop($userAgent)) {
            return 'desktop';
        }

        return 'unknown';
    }

    /**
     * Detect device brand from User-Agent.
     */
    public function detectDeviceBrand(string $userAgent): ?string
    {
        $brands = [
            'Apple' => '/iPhone|iPad|iPod|Macintosh/i',
            'Samsung' => '/Samsung/i',
            'Google' => '/Pixel/i',
            'Huawei' => '/Huawei|HUAWEI/i',
            'Xiaomi' => '/Xiaomi|Redmi/i',
            'OnePlus' => '/OnePlus/i',
            'Motorola' => '/Motorola/i',
            'Sony' => '/Sony/i',
            'LG' => '/LG/i',
            'Nokia' => '/Nokia/i',
            'Asus' => '/Asus/i',
            'Microsoft' => '/Surface/i',
        ];

        foreach ($brands as $name => $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Check if the User-Agent indicates a mobile device.
     */
    public function isMobile(string $userAgent): bool
    {
        return (bool) preg_match('/Android(?!.*Tablet)|iPhone|iPod|Mobile|BlackBerry|Opera Mini|IEMobile|WPDesktop/i', $userAgent);
    }

    /**
     * Check if the User-Agent indicates a tablet device.
     */
    public function isTablet(string $userAgent): bool
    {
        return (bool) preg_match('/iPad|Android(?!.*Mobile)|Tablet|Silk|Kindle|PlayBook/i', $userAgent);
    }

    /**
     * Check if the User-Agent indicates a desktop device.
     */
    public function isDesktop(string $userAgent): bool
    {
        return ! $this->isMobile($userAgent) && ! $this->isTablet($userAgent) && ! $this->isBot($userAgent);
    }

    /**
     * Check if the User-Agent indicates a bot/crawler.
     */
    public function isBot(string $userAgent): bool
    {
        return (bool) preg_match('/bot|crawl|spider|slurp|mediapartners|preview\.ninja|preview|facebookexternalhit|twitterbot|linkedinbot|slackbot|discordbot|whatsapp/i', $userAgent);
    }

    /**
     * Get an empty context array (for empty User-Agent).
     *
     * @return array{browser: null, browser_version: null, os: null, os_version: null, device_type: string, device_brand: null, is_mobile: bool, is_tablet: bool, is_desktop: bool, is_bot: bool}
     */
    private function emptyContext(): array
    {
        return [
            'browser' => null,
            'browser_version' => null,
            'os' => null,
            'os_version' => null,
            'device_type' => 'unknown',
            'device_brand' => null,
            'is_mobile' => false,
            'is_tablet' => false,
            'is_desktop' => false,
            'is_bot' => false,
        ];
    }
}
