<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

/**
 * IP anonymization service for GDPR compliance.
 *
 * Provides configurable IP address anonymization by masking the last octets
 * of IPv4 addresses or the last 48+ bits of IPv6 addresses before they are
 * included in analytics events.
 *
 * This is independent of Laravel's built-in IP anonymization and specifically
 * targets analytics event payloads.
 *
 * Configuration:
 *   zeroboiler.analytics.gdpr.anonymize_ip (default: false)
 *   zeroboiler.analytics.gdpr.ip_mask_v4 (default: 2) — octets to preserve (e.g., 2 = keep 255.255.X.X)
 *   zeroboiler.analytics.gdpr.ip_mask_v6 (default: 48) — bits to preserve
 *
 * @since 1.0.0
 */
final class IpAnonymizationService
{
    private bool $anonymizeIp;

    private int $ipv4Mask;

    private int $ipv6Mask;

    /**
     * @param  ConfigRepository|null  $config  Optional config for testing
     */
    public function __construct(?ConfigRepository $config = null): void
    {
        if ($config !== null) {
            $gdprConfig = $config->get('zeroboiler.analytics.gdpr', []);
            /** @var array{anonymize_ip?: bool, ip_mask_v4?: int, ip_mask_v6?: int} $gdprConfig */
            $this->anonymizeIp = (bool) ($gdprConfig['anonymize_ip'] ?? false);
            $this->ipv4Mask = (int) ($gdprConfig['ip_mask_v4'] ?? 2);
            $this->ipv6Mask = (int) ($gdprConfig['ip_mask_v6'] ?? 48);
        } else {
            $this->anonymizeIp = false;
            $this->ipv4Mask = 2;
            $this->ipv6Mask = 48;
        }
    }

    /**
     * Anonymize an IP address according to configuration.
     *
     * If anonymization is disabled, returns the original IP unchanged.
     *
     * @param  string|null  $ip  IP address to anonymize
     * @return string|null Anonymized IP or null if input is null/empty
     */
    public function anonymize(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (! $this->anonymizeIp) {
            return $ip;
        }

        // Detect IPv6 (contains ':' and not just '::ffff:' mapped IPv4)
        if (str_contains($ip, ':') && ! str_starts_with($ip, '::ffff:')) {
            return $this->anonymizeIpv6($ip);
        }

        return $this->anonymizeIpv4($ip);
    }

    /**
     * Anonymize an IPv4 address by zeroing the last octets.
     *
     * With mask=2 (default): 192.168.1.100 → 192.168.0.0
     * With mask=1: 192.168.1.100 → 192.0.0.0
     */
    private function anonymizeIpv4(string $ip): string
    {
        $parts = explode('.', $ip);

        if (count($parts) !== 4) {
            return '0.0.0.0';
        }

        // Zero out octets beyond the mask
        for ($i = $this->ipv4Mask; $i < 4; $i++) {
            $parts[$i] = '0';
        }

        return implode('.', $parts);
    }

    /**
     * Anonymize an IPv6 address by zeroing the last bits.
     *
     * With mask=48 (default): keeps the first 48 bits (3 groups).
     * Example: 2001:0db8:85a3:0000:0000:8a2e:0370:7334 → 2001:db8:85a3:::0:0:0
     */
    private function anonymizeIpv6(string $ip): string
    {
        // Expand :: notation
        $expanded = $this->expandIpv6($ip);

        $groups = explode(':', $expanded);

        // Zero out groups beyond the bit mask (each group = 16 bits)
        $groupsToPreserve = (int) ceil($this->ipv6Mask / 16);

        for ($i = $groupsToPreserve; $i < 8; $i++) {
            $groups[$i] = '0000';
        }

        // Compress back to shorthand
        return $this->compressIpv6(implode(':', $groups));
    }

    /**
     * Expand an IPv6 address to full 8-group format.
     */
    private function expandIpv6(string $ip): string
    {
        if (str_contains($ip, '::')) {
            $parts = explode('::', $ip);
            $left = explode(':', $parts[0]);
            $right = explode(':', $parts[1] ?? '');

            $missing = 8 - count($left) - count($right);
            $middle = array_fill(0, $missing, '0000');

            $groups = array_merge($left, $middle, $right);
        } else {
            $groups = explode(':', $ip);
        }

        // Pad each group to 4 hex chars
        $groups = array_map(fn (string $g): string => str_pad($g, 4, '0', STR_PAD_LEFT), $groups);

        return implode(':', $groups);
    }

    /**
     * Compress an expanded IPv6 address to shorthand notation.
     */
    private function compressIpv6(string $ip): string
    {
        $groups = explode(':', $ip);

        // Find the longest run of consecutive '0000' groups
        $bestStart = -1;
        $bestLen = 0;
        $currentStart = -1;
        $currentLen = 0;

        foreach ($groups as $i => $group) {
            if ($group === '0000') {
                if ($currentStart === -1) {
                    $currentStart = $i;
                    $currentLen = 1;
                } else {
                    $currentLen++;
                }

                if ($currentLen > $bestLen) {
                    $bestStart = $currentStart;
                    $bestLen = $currentLen;
                }
            } else {
                $currentStart = -1;
                $currentLen = 0;
            }
        }

        // Only compress runs of 2 or more
        if ($bestLen < 2) {
            return implode(':', $groups);
        }

        $left = array_slice($groups, 0, $bestStart);
        $right = array_slice($groups, $bestStart + $bestLen);

        // Remove leading zeros in remaining groups
        $left = array_map(fn (string $g): string => ltrim($g, '0') ?: '0', $left);
        $right = array_map(fn (string $g): string => ltrim($g, '0') ?: '0', $right);

        return implode(':', $left) . '::' . implode(':', $right);
    }

    /**
     * Anonymize the IP from an HTTP request.
     *
     * Convenience method for controllers and middleware.
     */
    public function anonymizeFromRequest(Request $request): ?string
    {
        return $this->anonymize($request->ip());
    }

    /**
     * Check if IP anonymization is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->anonymizeIp;
    }

    /**
     * Get the IPv4 mask (number of preserved octets).
     */
    public function getIpv4Mask(): int
    {
        return $this->ipv4Mask;
    }

    /**
     * Get the IPv6 mask (number of preserved bits).
     */
    public function getIpv6Mask(): int
    {
        return $this->ipv6Mask;
    }
}
