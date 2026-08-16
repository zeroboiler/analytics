<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Device fingerprint generation service.
 *
 * Creates stable, privacy-safe device fingerprints from HTTP request
 * headers. Used by IdentityGraphService for cross-device user stitching.
 *
 * Fingerprint components (all server-side, no JS required):
 *   - User-Agent (normalized)
 *   - Accept-Language
 *   - Screen resolution (from client hint headers or JS-reported)
 *   - Platform (OS)
 *   - Color depth / pixel ratio (from client hints)
 *
 * Privacy: The fingerprint is a SHA-256 hash — no raw header values are stored.
 * IP addresses are excluded from fingerprinting by default.
 *
 * @see \ZeroBoiler\Analytics\Services\IdentityGraphService
 *
 * @since 8.7.0
 */
final class DeviceFingerprintService
{
    private bool $enabled;

    private string $hashAlgo;

    private bool $includeIp;

    /** @var list<string> */
    private array $components;

    /**
     * @param  array{enabled?: bool, hash_algo?: string, include_ip?: bool, components?: list<string>}  $config
     */
    public function __construct(array $config = []): void
    {
        $this->enabled = (bool) ($config['enabled'] ?? true);
        $this->hashAlgo = (string) ($config['hash_algo'] ?? 'sha256');
        $this->includeIp = (bool) ($config['include_ip'] ?? false);
        $this->components = (array) ($config['components'] ?? [
            'user_agent',
            'accept_language',
            'sec_ch_platform',
            'sec_ch_mobile',
            'viewport_width',
            'viewport_height',
        ]);
    }

    /**
     * Generate a device fingerprint from an HTTP request.
     *
     * Uses request headers and optional client-reported parameters.
     * Returns null if fingerprinting is disabled or insufficient data.
     *
     * @param  Request  $request
     * @param  array{viewport_width?: int, viewport_height?: int, color_depth?: int, pixel_ratio?: float}  $clientHints  Optional client-reported hints
     * @return string|null SHA-256 hash or null
     */
    public function fingerprint(Request $request, array $clientHints = []): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        $parts = [];

        foreach ($this->components as $component) {
            $value = $this->extractComponent($request, $component, $clientHints);

            if ($value !== null && $value !== '') {
                $parts[] = $component . '=' . $value;
            }
        }

        if ($this->includeIp) {
            $ip = $request->ip();
            if ($ip !== null) {
                $parts[] = 'ip=' . $ip;
            }
        }

        if (count($parts) < 2) {
            return null; // Insufficient data for a meaningful fingerprint
        }

        $raw = implode('|', $parts);

        return hash($this->hashAlgo, $raw);
    }

    /**
     * Generate a fingerprint from raw component values.
     *
     * Useful for testing or when request is not available.
     *
     * @param  array<string, string>  $components  Key-value pairs of fingerprint components
     * @return string|null
     */
    public function fingerprintFromComponents(array $components): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        $parts = [];
        foreach ($components as $key => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        if (count($parts) < 2) {
            return null;
        }

        return hash($this->hashAlgo, implode('|', $parts));
    }

    /**
     * Extract a single fingerprint component from the request.
     *
     * @param  Request  $request
     * @param  string  $component
     * @param  array<string, mixed>  $clientHints
     * @return string|null
     */
    private function extractComponent(Request $request, string $component, array $clientHints): ?string
    {
        return match ($component) {
            'user_agent' => $this->normalizeUserAgent($request->userAgent()),
            'accept_language' => $request->header('Accept-Language'),
            'sec_ch_platform' => $request->header('Sec-CH-Platform'),
            'sec_ch_mobile' => $request->header('Sec-CH-Mobile'),
            'viewport_width' => isset($clientHints['viewport_width']) ? (string) $clientHints['viewport_width'] : null,
            'viewport_height' => isset($clientHints['viewport_height']) ? (string) $clientHints['viewport_height'] : null,
            'color_depth' => isset($clientHints['color_depth']) ? (string) $clientHints['color_depth'] : null,
            'pixel_ratio' => isset($clientHints['pixel_ratio']) ? (string) $clientHints['pixel_ratio'] : null,
            default => null,
        };
    }

    /**
     * Normalize a user-agent string for fingerprinting.
     *
     * Removes version numbers and patch levels to reduce fingerprint
     * instability across minor browser updates.
     *
     * @param  string|null  $ua
     * @return string|null
     */
    private function normalizeUserAgent(?string $ua): ?string
    {
        if ($ua === null || $ua === '') {
            return null;
        }

        // Normalize: remove specific version numbers
        $normalized = preg_replace(
            '/\/\d+(\.\d+)*/',
            '/X',
            $ua,
        );

        return is_string($normalized) ? $normalized : $ua;
    }

    /**
     * Get the list of fingerprint components being used.
     *
     * @return list<string>
     */
    public function getComponents(): array
    {
        return $this->components;
    }

    /**
     * Check if fingerprinting is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
