<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Http\Request;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Server-side event enrichment service.
 *
 * Automatically attaches request context metadata to analytics events
 * dispatched through server-side API endpoints. Enriches events with
 * IP address, user-agent, locale, referrer, screen resolution hints,
 * and session metadata.
 *
 * Respects GDPR IP anonymization settings from configuration.
 * IP addresses are truncated per `zeroboiler.analytics.gdpr` settings.
 *
 * Configuration: `zeroboiler.analytics.enrichment`
 *
 * @version 5.0.0
 *
 * @since 1.0.0
 */
final class EventEnrichmentService
{
    /**
     * Create a new event enrichment service.
     *
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly ConfigRepository $config,
    ): void {}

    /**
     * Enrich event parameters with request context metadata.
     *
     * Adds server-side context that the client cannot spoof:
     * - `_server_ip`: Client IP (anonymized per GDPR settings)
     * - `_server_user_agent`: Browser user-agent
     * - `_server_locale`: Request locale
     * - `_server_referrer`: HTTP referrer
     * - `_server_url`: Request URL
     * - `_server_method`: HTTP method
     * - `_server_timestamp`: Server timestamp (ISO 8601)
     * - `_server_session_id`: Laravel session ID (if available)
     *
     * Existing keys are never overwritten — server context uses `_server_` prefix.
     *
     * @param  array<string, mixed>  $params  Original event parameters
     * @param  Request  $request  Current HTTP request
     * @return array<string, mixed> Enriched event parameters
     */
    public function enrich(array $params, Request $request): array
    {
        $enrichment = $this->extractContext($request);

        // Merge server context — never overwrite client-sent params
        return array_merge($enrichment, $params);
    }

    /**
     * Extract request context metadata.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function extractContext(Request $request): array
    {
        $context = [];

        // IP address (GDPR-anonymized if enabled)
        $ip = $this->anonymizeIp($request->ip());
        $context['_server_ip'] = $ip;

        // User agent (truncated to 500 chars for storage efficiency)
        $ua = $request->userAgent() ?? '';
        $context['_server_user_agent'] = mb_substr($ua, 0, 500);

        // Locale
        $context['_server_locale'] = $request->getLocale();

        // Referrer
        $context['_server_referrer'] = $request->header('referer', '');

        // Request URL
        $context['_server_url'] = $request->fullUrl();

        // HTTP method
        $context['_server_method'] = $request->method();

        // Server timestamp
        $context['_server_timestamp'] = now()->toIso8601String();

        // Session ID (if available, hashed for privacy)
        $sessionId = $request->session()->getId();
        if ($sessionId !== '' && $sessionId !== '0') {
            $context['_server_session_id'] = hash('xxh128', $sessionId);
        }

        // Accept language header for more granular locale data
        $acceptLanguage = $request->header('accept-language', '');
        if ($acceptLanguage !== '') {
            $context['_server_accept_language'] = mb_substr($acceptLanguage, 0, 200);
        }

        // Content type hint (for tracking API vs browser events)
        $contentType = $request->header('content-type', '');
        $context['_server_source'] = str_contains($contentType, 'application/json') ? 'api' : 'browser';

        return $context;
    }

    /**
     * Anonymize IP address per GDPR settings.
     *
     * Reads configuration from `zeroboiler.analytics.gdpr`:
     * - `anonymize_ip`: bool — whether to anonymize
     * - `ip_mask_v4`: int — octets to preserve (default: 2)
     * - `ip_mask_v6`: int — bits to preserve (default: 48)
     *
     * @param  string|null  $ip  Raw IP address
     * @return string Anonymized IP address
     */
    public function anonymizeIp(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return '0.0.0.0';
        }

        $gdpr = $this->config->get('zeroboiler.analytics.gdpr', []);
        /** @var array{anonymize_ip?: bool, ip_mask_v4?: int, ip_mask_v6?: int} $gdpr */

        if (! ($gdpr['anonymize_ip'] ?? false)) {
            return $ip;
        }

        // IPv4
        if (str_contains($ip, '.') && ! str_contains($ip, ':')) {
            return $this->maskIpv4($ip, (int) ($gdpr['ip_mask_v4'] ?? 2));
        }

        // IPv6
        return $this->maskIpv6($ip, (int) ($gdpr['ip_mask_v6'] ?? 48));
    }

    /**
     * Check if event enrichment is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        $enrichment = $this->config->get('zeroboiler.analytics.enrichment', []);
        /** @var array{enabled?: bool} $enrichment */

        return (bool) ($enrichment['enabled'] ?? true);
    }

    /**
     * Get enrichment configuration for diagnostics.
     *
     * @return array{enabled: bool, gdpr_anonymize_ip: bool, ip_mask_v4: int, ip_mask_v6: int}
     */
    public function diagnostics(): array
    {
        $gdpr = $this->config->get('zeroboiler.analytics.gdpr', []);
        /** @var array{anonymize_ip?: bool, ip_mask_v4?: int, ip_mask_v6?: int} $gdpr */

        return [
            'enabled' => $this->isEnabled(),
            'gdpr_anonymize_ip' => (bool) ($gdpr['anonymize_ip'] ?? false),
            'ip_mask_v4' => (int) ($gdpr['ip_mask_v4'] ?? 2),
            'ip_mask_v6' => (int) ($gdpr['ip_mask_v6'] ?? 48),
        ];
    }

    /**
     * Mask an IPv4 address, preserving the first N octets.
     *
     * @param  string  $ip  IPv4 address (e.g. '192.168.1.100')
     * @param  int  $preserveOctets  Number of octets to keep (1-4)
     * @return string Masked IPv4 address
     */
    private function maskIpv4(string $ip, int $preserveOctets): string
    {
        $octets = explode('.', $ip);

        if (count($octets) !== 4) {
            return $ip;
        }

        $preserveOctets = max(0, min(4, $preserveOctets));

        for ($i = $preserveOctets; $i < 4; $i++) {
            $octets[$i] = '0';
        }

        return implode('.', $octets);
    }

    /**
     * Mask an IPv6 address, preserving the first N bits.
     *
     * @param  string  $ip  IPv6 address
     * @param  int  $preserveBits  Number of bits to keep
     * @return string Masked IPv6 address
     */
    private function maskIpv6(string $ip, int $preserveBits): string
    {
        // Expand compressed IPv6
        $expanded = $this->expandIpv6($ip);

        if ($expanded === '') {
            return '::';
        }

        $preserveChars = (int) ceil($preserveBits / 4);

        $masked = '';
        for ($i = 0; $i < 32; $i++) {
            if ($i < $preserveChars) {
                $masked .= $expanded[$i];
            } else {
                $masked .= '0';
            }
        }

        // Compress back to standard notation
        return $this->compressIpv6($masked);
    }

    /**
     * Expand an IPv6 address to 32 hex characters.
     *
     * @param  string  $ip
     * @return string 32-character hex string
     */
    private function expandIpv6(string $ip): string
    {
        // Handle IPv4-mapped IPv6
        if (str_contains($ip, '.')) {
            $ip = '::ffff:' . $ip;
        }

        // Split by ::
        $parts = explode('::', $ip);

        if (count($parts) === 2) {
            $left = explode(':', $parts[0]);
            $right = explode(':', $parts[1]);
            $missing = 8 - count($left) - count($right);
            $groups = [...$left, ...array_fill(0, max(0, $missing), '0000'), ...$right];
        } else {
            $groups = explode(':', $ip);
        }

        $expanded = '';
        foreach ($groups as $group) {
            $expanded .= str_pad($group, 4, '0', STR_PAD_LEFT);
        }

        return str_pad($expanded, 32, '0', STR_PAD_LEFT);
    }

    /**
     * Compress a 32-char IPv6 hex string to standard notation.
     *
     * @param  string  $hex  32-character hex string
     * @return string Compressed IPv6 address
     */
    private function compressIpv6(string $hex): string
    {
        $groups = [];
        for ($i = 0; $i < 32; $i += 4) {
            $groups[] = hexdec(substr($hex, $i, 4));
        }

        // Find longest run of zeros
        $maxRun = 0;
        $maxStart = 0;
        $currentRun = 0;
        $currentStart = 0;

        for ($i = 0; $i < 8; $i++) {
            if ($groups[$i] === 0) {
                if ($currentRun === 0) {
                    $currentStart = $i;
                }
                $currentRun++;
                if ($currentRun > $maxRun) {
                    $maxRun = $currentRun;
                    $maxStart = $currentStart;
                }
            } else {
                $currentRun = 0;
            }
        }

        if ($maxRun >= 2) {
            $left = array_slice($groups, 0, $maxStart);
            $right = array_slice($groups, $maxStart + $maxRun);

            return implode(':', array_map(fn (int $g): string => dechex($g), $left))
                . '::'
                . implode(':', array_map(fn (int $g): string => dechex($g), $right));
        }

        return implode(':', array_map(fn (int $g): string => dechex($g), $groups));
    }
}
