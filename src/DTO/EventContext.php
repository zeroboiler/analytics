<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

use Illuminate\Http\Request;

/**
 * Immutable DTO representing the resolved context of an analytics event.
 *
 * Captures all contextual information from the current HTTP request:
 * client identity, user identity, device info, UTM parameters, referrer,
 * session data, consent state, and geolocation. Used by EventContextBuilder
 * and EventEnvelopeService for automatic event enrichment.
 *
 * Designed for zero-allocation reads — all properties are readonly.
 *
 * @version 5.0.0
 *
 * @since 1.0.0
 */
final readonly class EventContext
{
    /**
     * Create a new event context from resolved parameters.
     *
     * @param  string|null  $clientId  Server-generated client tracking ID (from cookie)
     * @param  string|null  $userId  Authenticated user ID (from auth guard)
     * @param  string|null  $sessionId  Session ID
     * @param  string|null  $ip  Client IP address
     * @param  string|null  $userAgent  Browser user agent string
     * @param  string|null  $url  Current page URL
     * @param  string|null  $referrer  Referrer URL
     * @param  string|null  $path  Request path
     * @param  string|null  $method  HTTP method
     * @param  array<string, string>  $utm  UTM parameters (utm_source, utm_medium, utm_campaign, utm_term, utm_content)
     * @param  array<string, mixed>  $device  Device context (type, os, browser, viewport)
     * @param  string|null  $locale  User locale
     * @param  string|null  $country  Country code (from geolocation or header)
     * @param  bool  $consentGranted  Whether analytics consent is currently granted
     * @param  string|null  $cookieDomain  Cookie domain for identity cookies
     */
    public function __construct(
        public ?string $clientId = null,
        public ?string $userId = null,
        public ?string $sessionId = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $url = null,
        public ?string $referrer = null,
        public ?string $path = null,
        public ?string $method = null,
        public array $utm = [],
        public array $device = [],
        public ?string $locale = null,
        public ?string $country = null,
        public bool $consentGranted = true,
        public ?string $cookieDomain = null,
    ){}

    /**
     * Create an EventContext from an Illuminate HTTP Request.
     *
     * Extracts client ID from the configured cookie, user ID from auth,
     * UTM parameters from query string, and basic device/request info.
     *
     * @param  Request  $request  The current HTTP request
     * @param  string  $cookieName  Name of the analytics identity cookie
     * @param  bool  $consentGranted  Current consent state
     * @return self
     */
    public static function fromRequest(
        Request $request,
        string $cookieName = 'zb_analytics_id',
        bool $consentGranted = true,
    ): self {
        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
            $value = $request->query($key);
            if ($value !== null) {
                $utm[$key] = (string) $value;
            }
        }

        $device = [];
        $userAgent = $request->userAgent();
        if ($userAgent !== null) {
            $device['user_agent'] = $userAgent;
            $device['browser'] = self::detectBrowser($userAgent);
            $device['os'] = self::detectOs($userAgent);
            $device['type'] = self::detectDeviceType($userAgent);
        }

        return new self(
            clientId: $request->cookie($cookieName),
            userId: $request->user()?->id ? (string) $request->user()->id : null,
            sessionId: $request->getSession()->getId(),
            ip: $request->ip(),
            userAgent: $userAgent,
            url: $request->fullUrl(),
            referrer: $request->header('referer'),
            path: $request->path(),
            method: $request->method(),
            utm: $utm,
            device: $device,
            locale: $request->getLocale(),
            country: $request->header('CF-IPCountry'), // Cloudflare country header
            consentGranted: $consentGranted,
        );
    }

    /**
     * Convert context to a flat array for event enrichment.
     *
     * Returns key-value pairs suitable for merging into event params.
     * Internal keys (prefixed with _) are included for pipeline processing.
     *
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        $params = [];

        if ($this->clientId !== null) {
            $params['client_id'] = $this->clientId;
        }

        if ($this->userId !== null) {
            $params['user_id'] = $this->userId;
        }

        if ($this->sessionId !== null) {
            $params['_session_id'] = $this->sessionId;
        }

        if ($this->ip !== null) {
            $params['_ip'] = $this->ip;
        }

        if ($this->referrer !== null) {
            $params['page_referrer'] = $this->referrer;
        }

        if ($this->path !== null) {
            $params['page_path'] = $this->path;
        }

        if ($this->locale !== null) {
            $params['_locale'] = $this->locale;
        }

        if ($this->country !== null) {
            $params['_country'] = $this->country;
        }

        foreach ($this->utm as $key => $value) {
            $params[$key] = $value;
        }

        foreach ($this->device as $key => $value) {
            $params["_device_{$key}"] = $value;
        }

        return $params;
    }

    /**
     * Check if this context has an identified user.
     */
    public function hasUser(): bool
    {
        return $this->userId !== null;
    }

    /**
     * Check if this context has a client tracking ID.
     */
    public function hasClientId(): bool
    {
        return $this->clientId !== null;
    }

    /**
     * Check if this context has UTM attribution data.
     */
    public function hasUtm(): bool
    {
        return $this->utm !== [];
    }

    /**
     * Check if tracking consent is granted.
     */
    public function hasConsent(): bool
    {
        return $this->consentGranted;
    }

    /**
     * Get the identity string (user ID if available, otherwise client ID).
     *
     * Returns null if neither is available.
     */
    public function identity(): ?string
    {
        return $this->userId ?? $this->clientId;
    }

    /**
     * Create a copy with overridden properties.
     *
     * @param  array<string, mixed>  $overrides  Properties to override
     * @return self
     */
    public function with(array $overrides): self
    {
        return new self(
            clientId: $overrides['clientId'] ?? $this->clientId,
            userId: $overrides['userId'] ?? $this->userId,
            sessionId: $overrides['sessionId'] ?? $this->sessionId,
            ip: $overrides['ip'] ?? $this->ip,
            userAgent: $overrides['userAgent'] ?? $this->userAgent,
            url: $overrides['url'] ?? $this->url,
            referrer: $overrides['referrer'] ?? $this->referrer,
            path: $overrides['path'] ?? $this->path,
            method: $overrides['method'] ?? $this->method,
            utm: $overrides['utm'] ?? $this->utm,
            device: $overrides['device'] ?? $this->device,
            locale: $overrides['locale'] ?? $this->locale,
            country: $overrides['country'] ?? $this->country,
            consentGranted: $overrides['consentGranted'] ?? $this->consentGranted,
            cookieDomain: $overrides['cookieDomain'] ?? $this->cookieDomain,
        );
    }

    /**
     * Detect browser name from user agent string.
     *
     * @return string Browser name or 'Unknown'
     */
    private static function detectBrowser(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Chrome/') && ! str_contains($userAgent, 'Edg/') => 'Chrome',
            str_contains($userAgent, 'Safari/') && ! str_contains($userAgent, 'Chrome/') => 'Safari',
            str_contains($userAgent, 'Opera') || str_contains($userAgent, 'OPR/') => 'Opera',
            default => 'Unknown',
        };
    }

    /**
     * Detect OS from user agent string.
     *
     * @return string OS name or 'Unknown'
     */
    private static function detectOs(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') && ! str_contains($userAgent, 'Android') => 'Linux',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            default => 'Unknown',
        };
    }

    /**
     * Detect device type from user agent string.
     *
     * @return string Device type: 'mobile', 'tablet', or 'desktop'
     */
    private static function detectDeviceType(string $userAgent): string
    {
        if (str_contains($userAgent, 'iPad') || (str_contains($userAgent, 'Android') && ! str_contains($userAgent, 'Mobile'))) {
            return 'tablet';
        }

        if (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'Android') || str_contains($userAgent, 'Mobile')) {
            return 'mobile';
        }

        return 'desktop';
    }
}
