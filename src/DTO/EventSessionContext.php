<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing the full session/device context of an analytics event.
 *
 * Enriches AnalyticsEvent with structured session, device, platform,
 * geolocation, and attribution metadata. Used by the pipeline enrichers
 * and persisted in the event store for retrospective analysis.
 *
 * @since 63.0.0
 */
final readonly class EventSessionContext
{
    /**
     * @param  string|null  $sessionId  Server-generated session identifier
     * @param  string|null  $clientId  Analytics client ID (GA4 client ID or cookie-stored tracking ID)
     * @param  string|null  $userId  Authenticated user ID (null for anonymous)
     * @param  string|null  $fingerprint  Device fingerprint hash (SHA-256)
     * @param  string|null  $ip  Client IP address (may be anonymized)
     * @param  string|null  $userAgent  Raw User-Agent header
     * @param  string|null  $browser  Parsed browser name (e.g. 'Chrome 126')
     * @param  string|null  $os  Parsed OS name (e.g. 'macOS 14.5')
     * @param  string|null  $deviceType  Device type category: 'desktop'|'mobile'|'tablet'|'bot'|'unknown'
     * @param  string|null  $screenWidth  Screen width in pixels
     * @param  string|null  $screenHeight  Screen height in pixels
     * @param  string|null  $viewportWidth  Viewport width in pixels
     * @param  string|null  $viewportHeight  Viewport height in pixels
     * @param  string|null  $language  Browser language (e.g. 'en-US')
     * @param  string|null  $timezone  Client timezone (e.g. 'America/New_York')
     * @param  string|null  $country  ISO 3166-1 alpha-2 country code
     * @param  string|null  $region  Region/state code
     * @param  string|null  $city  City name
     * @param  string|null  $pageUrl  Current page URL
     * @param  string|null  $pageTitle  Current page title
     * @param  string|null  $referrer  Referrer URL
     * @param  string|null  $utmSource  UTM source parameter
     * @param  string|null  $utmMedium  UTM medium parameter
     * @param  string|null  $utmCampaign  UTM campaign parameter
     * @param  string|null  $utmTerm  UTM term parameter
     * @param  string|null  $utmContent  UTM content parameter
     * @param  array<string, mixed>  $extra  Additional custom context properties
     */
    public function __construct(
        public ?string $sessionId = null,
        public ?string $clientId = null,
        public ?string $userId = null,
        public ?string $fingerprint = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $browser = null,
        public ?string $os = null,
        public ?string $deviceType = null,
        public ?string $screenWidth = null,
        public ?string $screenHeight = null,
        public ?string $viewportWidth = null,
        public ?string $viewportHeight = null,
        public ?string $language = null,
        public ?string $timezone = null,
        public ?string $country = null,
        public ?string $region = null,
        public ?string $city = null,
        public ?string $pageUrl = null,
        public ?string $pageTitle = null,
        public ?string $referrer = null,
        public ?string $utmSource = null,
        public ?string $utmMedium = null,
        public ?string $utmCampaign = null,
        public ?string $utmTerm = null,
        public ?string $utmContent = null,
        public array $extra = [],
    ): void {}

    /**
     * Create an EventSessionContext from an HTTP request.
     *
     * Extracts IP, User-Agent, screen info from headers, and UTM from query params.
     * Does not perform geolocation lookup (that is handled by GeolocationEnricher).
     *
     * @param  \Illuminate\Http\Request  $request  The incoming HTTP request
     * @param  string|null  $clientId  Analytics client ID (from cookie or header)
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $sessionId  Session ID
     * @return self
     */
    public static function fromRequest(
        \Illuminate\Http\Request $request,
        ?string $clientId = null,
        ?string $userId = null,
        ?string $sessionId = null,
    ): self {
        return new self(
            sessionId: $sessionId,
            clientId: $clientId,
            userId: $userId,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            language: $request->header('Accept-Language'),
            pageUrl: $request->fullUrl(),
            pageTitle: null, // Cannot extract from request alone
            referrer: $request->header('referer'),
            utmSource: $request->query('utm_source'),
            utmMedium: $request->query('utm_medium'),
            utmCampaign: $request->query('utm_campaign'),
            utmTerm: $request->query('utm_term'),
            utmContent: $request->query('utm_content'),
            screenWidth: $request->header('X-Screen-Width'),
            screenHeight: $request->header('X-Screen-Height'),
            viewportWidth: $request->header('X-Viewport-Width'),
            viewportHeight: $request->header('X-Viewport-Height'),
            timezone: $request->header('X-Timezone'),
        );
    }

    /**
     * Convert to a flat array representation for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'session_id' => $this->sessionId,
            'client_id' => $this->clientId,
            'user_id' => $this->userId,
            'fingerprint' => $this->fingerprint,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'browser' => $this->browser,
            'os' => $this->os,
            'device_type' => $this->deviceType,
            'screen_width' => $this->screenWidth,
            'screen_height' => $this->screenHeight,
            'viewport_width' => $this->viewportWidth,
            'viewport_height' => $this->viewportHeight,
            'language' => $this->language,
            'timezone' => $this->timezone,
            'country' => $this->country,
            'region' => $this->region,
            'city' => $this->city,
            'page_url' => $this->pageUrl,
            'page_title' => $this->pageTitle,
            'referrer' => $this->referrer,
        ];

        // Only include UTM fields if at least one is present
        if ($this->utmSource !== null || $this->utmMedium !== null || $this->utmCampaign !== null) {
            $data['utm_source'] = $this->utmSource;
            $data['utm_medium'] = $this->utmMedium;
            $data['utm_campaign'] = $this->utmCampaign;
            $data['utm_term'] = $this->utmTerm;
            $data['utm_content'] = $this->utmContent;
        }

        if ($this->extra !== []) {
            $data['extra'] = $this->extra;
        }

        return $data;
    }

    /**
     * Create from a stored array representation.
     *
     * @param  array<string, mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: isset($data['session_id']) ? (string) $data['session_id'] : null,
            clientId: isset($data['client_id']) ? (string) $data['client_id'] : null,
            userId: isset($data['user_id']) ? (string) $data['user_id'] : null,
            fingerprint: isset($data['fingerprint']) ? (string) $data['fingerprint'] : null,
            ip: isset($data['ip']) ? (string) $data['ip'] : null,
            userAgent: isset($data['user_agent']) ? (string) $data['user_agent'] : null,
            browser: isset($data['browser']) ? (string) $data['browser'] : null,
            os: isset($data['os']) ? (string) $data['os'] : null,
            deviceType: isset($data['device_type']) ? (string) $data['device_type'] : null,
            screenWidth: isset($data['screen_width']) ? (string) $data['screen_width'] : null,
            screenHeight: isset($data['screen_height']) ? (string) $data['screen_height'] : null,
            viewportWidth: isset($data['viewport_width']) ? (string) $data['viewport_width'] : null,
            viewportHeight: isset($data['viewport_height']) ? (string) $data['viewport_height'] : null,
            language: isset($data['language']) ? (string) $data['language'] : null,
            timezone: isset($data['timezone']) ? (string) $data['timezone'] : null,
            country: isset($data['country']) ? (string) $data['country'] : null,
            region: isset($data['region']) ? (string) $data['region'] : null,
            city: isset($data['city']) ? (string) $data['city'] : null,
            pageUrl: isset($data['page_url']) ? (string) $data['page_url'] : null,
            pageTitle: isset($data['page_title']) ? (string) $data['page_title'] : null,
            referrer: isset($data['referrer']) ? (string) $data['referrer'] : null,
            utmSource: isset($data['utm_source']) ? (string) $data['utm_source'] : null,
            utmMedium: isset($data['utm_medium']) ? (string) $data['utm_medium'] : null,
            utmCampaign: isset($data['utm_campaign']) ? (string) $data['utm_campaign'] : null,
            utmTerm: isset($data['utm_term']) ? (string) $data['utm_term'] : null,
            utmContent: isset($data['utm_content']) ? (string) $data['utm_content'] : null,
            extra: isset($data['extra']) && is_array($data['extra']) ? $data['extra'] : [],
        );
    }

    /**
     * Create a copy with updated fields.
     *
     * Returns a new instance with the specified fields replaced.
     *
     * @param  array<string, mixed>  $overrides  Key-value pairs to override
     * @return self
     */
    public function with(array $overrides): self
    {
        return new self(
            sessionId: $overrides['session_id'] ?? $this->sessionId,
            clientId: $overrides['client_id'] ?? $this->clientId,
            userId: $overrides['user_id'] ?? $this->userId,
            fingerprint: $overrides['fingerprint'] ?? $this->fingerprint,
            ip: $overrides['ip'] ?? $this->ip,
            userAgent: $overrides['user_agent'] ?? $this->userAgent,
            browser: $overrides['browser'] ?? $this->browser,
            os: $overrides['os'] ?? $this->os,
            deviceType: $overrides['device_type'] ?? $this->deviceType,
            screenWidth: $overrides['screen_width'] ?? $this->screenWidth,
            screenHeight: $overrides['screen_height'] ?? $this->screenHeight,
            viewportWidth: $overrides['viewport_width'] ?? $this->viewportWidth,
            viewportHeight: $overrides['viewport_height'] ?? $this->viewportHeight,
            language: $overrides['language'] ?? $this->language,
            timezone: $overrides['timezone'] ?? $this->timezone,
            country: $overrides['country'] ?? $this->country,
            region: $overrides['region'] ?? $this->region,
            city: $overrides['city'] ?? $this->city,
            pageUrl: $overrides['page_url'] ?? $this->pageUrl,
            pageTitle: $overrides['page_title'] ?? $this->pageTitle,
            referrer: $overrides['referrer'] ?? $this->referrer,
            utmSource: $overrides['utm_source'] ?? $this->utmSource,
            utmMedium: $overrides['utm_medium'] ?? $this->utmMedium,
            utmCampaign: $overrides['utm_campaign'] ?? $this->utmCampaign,
            utmTerm: $overrides['utm_term'] ?? $this->utmTerm,
            utmContent: $overrides['utm_content'] ?? $this->utmContent,
            extra: array_merge($this->extra, $overrides['extra'] ?? []),
        );
    }

    /**
     * Check whether this context has any UTM attribution data.
     */
    public function hasUtmData(): bool
    {
        return $this->utmSource !== null
            || $this->utmMedium !== null
            || $this->utmCampaign !== null;
    }

    /**
     * Extract UTM parameters as a plain associative array.
     *
     * @return array{utm_source?: string, utm_medium?: string, utm_campaign?: string, utm_term?: string, utm_content?: string}
     */
    public function utmArray(): array
    {
        $utm = [];

        if ($this->utmSource !== null) {
            $utm['utm_source'] = $this->utmSource;
        }
        if ($this->utmMedium !== null) {
            $utm['utm_medium'] = $this->utmMedium;
        }
        if ($this->utmCampaign !== null) {
            $utm['utm_campaign'] = $this->utmCampaign;
        }
        if ($this->utmTerm !== null) {
            $utm['utm_term'] = $this->utmTerm;
        }
        if ($this->utmContent !== null) {
            $utm['utm_content'] = $this->utmContent;
        }

        return $utm;
    }
}
