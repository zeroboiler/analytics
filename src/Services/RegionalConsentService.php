<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Configures analytics consent defaults based on user region.
 *
 * In GDPR/privacy-regulated jurisdictions (EU, UK, Brazil, California),
 * consent defaults to 'denied' (opt-in). In other regions, consent defaults
 * to 'granted' unless overridden by the application config.
 *
 * Uses a region-to-regulation mapping with configurable override lists.
 *
 * @version 5.7.0
 *
 * @since 1.0.0
 */
final class RegionalConsentService
{
    /**
     * GDPR-applicable regions (country codes).
     *
     * Users in these regions will default to consent='denied'.
     *
     * @var list<string>
     */
    private const GDPR_REGIONS = [
        // European Union (27 member states)
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        // United Kingdom
        'GB',
        // European Economic Area
        'IS', 'LI', 'NO',
        // Brazil (LGPD)
        'BR',
        // Switzerland (nFADP)
        'CH',
        // Canada (PIPEDA)
        'CA',
        // India (DPDPA 2023)
        'IN',
        // South Korea (PIPA)
        'KR',
        // Japan (APPI)
        'JP',
        // Argentina (PDPA)
        'AR',
        // Thailand (PDPA)
        'TH',
        // Philippines (DPA)
        'PH',
        // Indonesia (PDP Law)
        'ID',
        // Vietnam (PDPD)
        'VN',
        // UAE (PDPL)
        'AE',
        // Saudi Arabia (PDPL)
        'SA',
        // Turkey (KVKK)
        'TR',
        // South Africa (POPIA)
        'ZA',
    ];

    /**
     * US states with privacy laws requiring opt-in or consent.
     *
     * @var list<string>
     */
    private const US_PRIVACY_STATES = [
        'CA', // California (CCPA/CPRA)
        'CO', // Colorado (CPA)
        'CT', // Connecticut (CTDPA)
        'VA', // Virginia (VCDPA)
        'UT', // Utah (UCPA)
        'IA', // Iowa (Iowa Privacy Act)
        'IN', // Indiana
        'MT', // Montana (CDPA)
        'NH', // New Hampshire
        'NJ', // New Jersey (NJDPP)
        'TN', // Tennessee (TDPA)
    ];

    private bool $enabled;

    /** @var list<string> */
    private array $additionalRegions;

    /** @var list<string> */
    private array $excludedRegions;

    private string $defaultConsent;

    private string $gdprDefault;

    private string $gdprRegionDefault;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
    {
        $regionalConfig = $config->get('zeroboiler.analytics.regional_consent', []);
        /** @var array{enabled?: bool, additional_regions?: list<string>, excluded_regions?: list<string>, default_consent?: string, gdpr_default?: string, gdpr_region_default?: string} $regionalConfig */

        $this->enabled = (bool) ($regionalConfig['enabled'] ?? false);
        $this->additionalRegions = $regionalConfig['additional_regions'] ?? [];
        $this->excludedRegions = $regionalConfig['excluded_regions'] ?? [];
        $this->defaultConsent = $regionalConfig['default_consent'] ?? 'granted';
        $this->gdprDefault = $regionalConfig['gdpr_default'] ?? 'denied';
        $this->gdprRegionDefault = $regionalConfig['gdpr_region_default'] ?? $this->gdprDefault;
    }

    /**
     * Determine the appropriate consent default for a given country code.
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 country code
     * @return 'granted'|'denied'  Consent default
     */
    public function getConsentDefault(string $countryCode): string
    {
        if (! $this->enabled) {
            return $this->defaultConsent;
        }

        $upperCode = strtoupper($countryCode);

        // Check exclusion list first
        if (in_array($upperCode, $this->excludedRegions, true)) {
            return $this->defaultConsent;
        }

        // Check GDPR region list
        if ($this->isGdprRegion($upperCode)) {
            return $this->gdprRegionDefault;
        }

        return $this->defaultConsent;
    }

    /**
     * Determine consent default from an IP address.
     *
     * Extracts country code from request headers (Cloudflare, MaxMind, etc.)
     * and applies regional consent rules.
     *
     * @param  string  $ip  Client IP address
     * @param  array<string, string|null>  $headers  Request headers (e.g. CF-IPCountry, X-GeoIP-Country)
     * @return 'granted'|'denied'
     */
    public function getConsentDefaultFromIp(string $ip, array $headers = []): string
    {
        if (! $this->enabled) {
            return $this->defaultConsent;
        }

        // Check common geo headers in priority order
        $geoHeaders = [
            'cf-ipcountry', // Cloudflare
            'x-geoip-country', // MaxMind
            'x-vercel-ip-country', // Vercel
            'x-country-code', // Generic
            'geoip-country', // Alternative
        ];

        foreach ($geoHeaders as $header) {
            $value = $headers[$header] ?? $headers[strtoupper($header)] ?? null;

            if (is_string($value) && $value !== '') {
                return $this->getConsentDefault($value);
            }
        }

        // Fallback: treat unknown regions as GDPR-safe (denied)
        return $this->defaultConsent;
    }

    /**
     * Check if a country code is in a GDPR-applicable region.
     *
     * @param  string  $countryCode  Uppercase ISO 3166-1 alpha-2
     */
    public function isGdprRegion(string $countryCode): bool
    {
        if (in_array($countryCode, self::GDPR_REGIONS, true)) {
            return true;
        }

        // Check additional regions
        return in_array($countryCode, $this->additionalRegions, true);
    }

    /**
     * Check if a US state requires privacy consent.
     *
     * @param  string  $stateCode  Two-letter US state code
     */
    public function isUsPrivacyState(string $stateCode): bool
    {
        return in_array(strtoupper($stateCode), self::US_PRIVACY_STATES, true);
    }

    /**
     * Get the full list of GDPR-applicable regions.
     *
     * @return list<string>
     */
    public function getGdprRegions(): array
    {
        return array_values(array_unique(array_merge(
            self::GDPR_REGIONS,
            $this->additionalRegions,
        )));
    }

    /**
     * Get the list of US states with privacy laws.
     *
     * @return list<string>
     */
    public function getUsPrivacyStates(): array
    {
        return self::US_PRIVACY_STATES;
    }

    /**
     * Get a summary of the regional consent configuration.
     *
     * @return array{enabled: bool, default_consent: string, gdpr_default: string, gdpr_regions_count: int, us_privacy_states_count: int, additional_regions: list<string>, excluded_regions: list<string>}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'default_consent' => $this->defaultConsent,
            'gdpr_default' => $this->gdprRegionDefault,
            'gdpr_regions_count' => count($this->getGdprRegions()),
            'us_privacy_states_count' => count(self::US_PRIVACY_STATES),
            'additional_regions' => $this->additionalRegions,
            'excluded_regions' => $this->excludedRegions,
        ];
    }

    /**
     * Check if regional consent detection is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
