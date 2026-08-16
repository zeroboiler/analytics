<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * UTM Parameter Manager for unified campaign tracking across providers.
 *
 * Provides a single service for extracting, validating, sanitizing, and
 * normalizing UTM parameters from URLs and request objects. Ensures consistent
 * campaign parameter naming, value validation, and cross-domain link decoration.
 *
 * Features:
 * - Extract UTM params from any URL string or Illuminate Request
 * - Validate UTM parameter values (max length, allowed characters, required fields)
 * - Sanitize values (trim, lowercase source/medium, strip tags)
 * - Normalize campaign params to a canonical set (utm_source, utm_medium, etc.)
 * - Decorate URLs with UTM params for outbound link tracking
 * - Clean internal tracking params (fbclid, gclid, msclkid, etc.) from URLs
 * - Check UTM completeness score (how many standard params are present)
 * - Alias support for non-standard param names (source → utm_source)
 *
 * @since 55.0.0
 */
final class UtmParameterManager
{
    /**
     * Standard UTM parameter names recognized by this manager.
     *
     * @var list<string>
     */
    public const STANDARD_PARAMS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_id',
    ];

    /**
     * Internal tracking parameters to strip from URLs.
     * These are platform-specific click IDs that pollute analytics.
     *
     * @var list<string>
     */
    public const INTERNAL_PARAMS = [
        'fbclid',
        'gclid',
        'gclsrc',
        'msclkid',
        'dclid',
        'twclid',
        'li_fat_id',
        'ttclid',
        'igshid',
        'mc_cid',
        'mc_eid',
        '_gl',
        '_ga',
        '_gid',
        '_fbp',
        '_hsenc',
        '_hsmi',
        '_hsopenstat',
        'vero_id',
        'oly_anon_id',
        'oly_enc_id',
        'otc',
        'wickedid',
        'yclid',
        '_openstat',
    ];

    /**
     * Default maximum length for UTM parameter values.
     */
    private const MAX_VALUE_LENGTH = 500;

    /**
     * Default maximum length for UTM parameter keys.
     */
    private const MAX_KEY_LENGTH = 100;

    /** @var bool */
    private bool $enabled;

    /** @var int */
    private int $maxValueLength;

    /** @var int */
    private int $maxKeyLength;

    /** @var bool */
    private bool $lowercaseSourceMedium;

    /** @var bool */
    private bool $trimValues;

    /** @var bool */
    private bool $stripHtml;

    /** @var array<string, string> */
    private array $aliases;

    /** @var list<string> */
    private array $requiredForCompleteness;

    /** @var list<string> */
    private array $customInternalParams;

    /**
     * Create a new UTM Parameter Manager.
     */
    public function __construct(ConfigRepository $config): void
    {
        $utmConfig = $config->get('zeroboiler.analytics.utm_manager', []);
        /** @var array{enabled?: bool, max_value_length?: int, max_key_length?: int, lowercase_source_medium?: bool, trim_values?: bool, strip_html?: bool, aliases?: array<string, string>, required_for_completeness?: list<string>, internal_params?: list<string>} $utmConfig */

        $this->enabled = (bool) ($utmConfig['enabled'] ?? true);
        $this->maxValueLength = (int) ($utmConfig['max_value_length'] ?? self::MAX_VALUE_LENGTH);
        $this->maxKeyLength = (int) ($utmConfig['max_key_length'] ?? self::MAX_KEY_LENGTH);
        $this->lowercaseSourceMedium = (bool) ($utmConfig['lowercase_source_medium'] ?? true);
        $this->trimValues = (bool) ($utmConfig['trim_values'] ?? true);
        $this->stripHtml = (bool) ($utmConfig['strip_html'] ?? true);
        $this->aliases = (array) ($utmConfig['aliases'] ?? []);
        $this->requiredForCompleteness = (array) ($utmConfig['required_for_completeness'] ?? [
            'utm_source',
            'utm_medium',
            'utm_campaign',
        ]);
        $this->customInternalParams = (array) ($utmConfig['internal_params'] ?? []);
    }

    /**
     * Check if the UTM manager is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the list of standard UTM parameter names.
     *
     * @return list<string>
     */
    public function standardParams(): array
    {
        return self::STANDARD_PARAMS;
    }

    /**
     * Get the full list of internal tracking parameters to strip.
     *
     * Includes built-in params plus any custom ones from config.
     *
     * @return list<string>
     */
    public function internalParams(): array
    {
        return array_values(array_unique(array_merge(
            self::INTERNAL_PARAMS,
            $this->customInternalParams,
        )));
    }

    /**
     * Extract UTM parameters from a URL string.
     *
     * Parses the query string of the URL, applies aliases, and returns
     * only the UTM-related parameters (standard + aliased).
     *
     * @return array<string, string>
     */
    public function extractFromUrl(string $url): array
    {
        $query = [];
        $parsed = parse_url($url);

        if (! is_array($parsed) || ! isset($parsed['query'])) {
            return [];
        }

        parse_str($parsed['query'], $query);

        return $this->extractFromArray($query);
    }

    /**
     * Extract UTM parameters from an associative array.
     *
     * Applies aliases and returns only UTM-related parameters.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    public function extractFromArray(array $params): array
    {
        $utm = [];

        foreach ($params as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalizedKey = $this->resolveAlias(strtolower($key));

            if ($this->isUtmParam($normalizedKey)) {
                $utm[$normalizedKey] = $this->sanitizeValue($value);
            }
        }

        return $utm;
    }

    /**
     * Validate a set of UTM parameters.
     *
     * Checks for:
     * - Key validity (length, naming convention)
     * - Value validity (length, no empty required fields)
     * - Aliased keys are resolved before validation
     *
     * @param  array<string, mixed>  $params
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validate(array $params): array
    {
        $errors = [];
        $warnings = [];

        foreach ($params as $key => $value) {
            // Resolve alias
            $normalizedKey = $this->resolveAlias(strtolower($key));

            // Key length
            if (mb_strlen($key) > $this->maxKeyLength) {
                $errors[] = "UTM key '{$key}' exceeds max length ({$this->maxKeyLength})";
            }

            // Only validate UTM-related keys
            if (! $this->isUtmParam($normalizedKey) && ! in_array($key, $this->aliases, true)) {
                continue;
            }

            // Value validation
            if (! is_string($value) || trim($value) === '') {
                if (in_array($normalizedKey, $this->requiredForCompleteness, true)) {
                    $errors[] = "Required UTM param '{$normalizedKey}' is empty";
                } else {
                    $warnings[] = "UTM param '{$normalizedKey}' is empty";
                }
                continue;
            }

            // Value length
            if (mb_strlen($value) > $this->maxValueLength) {
                $errors[] = "UTM param '{$normalizedKey}' value exceeds max length ({$this->maxValueLength})";
            }

            // Check for suspicious characters
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
                $warnings[] = "UTM param '{$normalizedKey}' contains control characters";
            }
        }

        // Check required fields are present
        foreach ($this->requiredForCompleteness as $required) {
            $found = false;
            foreach ($params as $key => $value) {
                $normalizedKey = $this->resolveAlias(strtolower($key));
                if ($normalizedKey === $required && is_string($value) && trim($value) !== '') {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $errors[] = "Missing required UTM param '{$required}'";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Sanitize a single UTM value.
     *
     * Applies trimming, HTML stripping, and lowercasing for source/medium.
     */
    public function sanitizeValue(string $value): string
    {
        if ($this->trimValues) {
            $value = trim($value);
        }

        if ($this->stripHtml) {
            $value = strip_tags($value);
        }

        // Truncate to max length
        if (mb_strlen($value) > $this->maxValueLength) {
            $value = mb_substr($value, 0, $this->maxValueLength);
        }

        return $value;
    }

    /**
     * Sanitize a full set of UTM parameters.
     *
     * Applies sanitizeValue to each value, and lowercases source/medium
     * if configured.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    public function sanitizeParams(array $params): array
    {
        $sanitized = [];

        foreach ($params as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalizedKey = $this->resolveAlias(strtolower($key));

            if (! $this->isUtmParam($normalizedKey)) {
                continue;
            }

            $cleanValue = $this->sanitizeValue($value);

            // Lowercase source and medium
            if ($this->lowercaseSourceMedium) {
                if (in_array($normalizedKey, ['utm_source', 'utm_medium'], true)) {
                    $cleanValue = strtolower($cleanValue);
                }
            }

            $sanitized[$normalizedKey] = $cleanValue;
        }

        return $sanitized;
    }

    /**
     * Decorate a URL with UTM parameters.
     *
     * Adds the given UTM parameters to the URL's query string.
     * If the URL already has some UTM params, they are merged (new values win).
     *
     * @param  array<string, string>  $utmParams
     */
    public function decorateUrl(string $url, array $utmParams): string
    {
        if (empty($utmParams)) {
            return $url;
        }

        $parsed = parse_url($url);

        if (! is_array($parsed)) {
            return $url;
        }

        $existing = [];
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $existing);
        }

        // Merge UTM params (new values override existing)
        $merged = array_merge($existing, $utmParams);

        // Build URL components
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';
        $path = $parsed['path'] ?? '/';
        $fragment = isset($parsed['fragment']) ? "#{$parsed['fragment']}" : '';

        $query = http_build_query($merged, '', '&', PHP_QUERY_RFC3986);

        return "{$scheme}://{$host}{$port}{$path}?{$query}{$fragment}";
    }

    /**
     * Clean internal tracking parameters from a URL.
     *
     * Strips platform-specific click IDs (fbclid, gclid, etc.) from the URL
     * to produce a clean URL suitable for sharing and attribution.
     *
     * Removes only the params listed in internalParams() — all other params
     * are preserved.
     */
    public function cleanUrl(string $url): string
    {
        $parsed = parse_url($url);

        if (! is_array($parsed) || ! isset($parsed['query'])) {
            return $url;
        }

        $params = [];
        parse_str($parsed['query'], $params);

        $internal = $this->internalParams();
        $cleaned = [];

        foreach ($params as $key => $value) {
            if (! in_array(strtolower($key), $internal, true)) {
                $cleaned[$key] = $value;
            }
        }

        if (empty($cleaned)) {
            // No query params left — return URL without ?
            $scheme = $parsed['scheme'] ?? 'https';
            $host = $parsed['host'] ?? '';
            $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';
            $path = $parsed['path'] ?? '/';
            $fragment = isset($parsed['fragment']) ? "#{$parsed['fragment']}" : '';

            return "{$scheme}://{$host}{$port}{$path}{$fragment}";
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';
        $path = $parsed['path'] ?? '/';
        $fragment = isset($parsed['fragment']) ? "#{$parsed['fragment']}" : '';

        $query = http_build_query($cleaned, '', '&', PHP_QUERY_RFC3986);

        return "{$scheme}://{$host}{$port}{$path}?{$query}{$fragment}";
    }

    /**
     * Calculate UTM completeness score (0–100).
     *
     * Returns a score based on how many required parameters are present
     * and non-empty. Also returns the count of present params.
     *
     * @param  array<string, mixed>  $params
     * @return array{score: int, present: int, total: int, missing: list<string>}
     */
    public function completenessScore(array $params): array
    {
        $present = 0;
        $missing = [];

        foreach ($this->requiredForCompleteness as $required) {
            $found = false;
            foreach ($params as $key => $value) {
                $normalizedKey = $this->resolveAlias(strtolower($key));
                if ($normalizedKey === $required && is_string($value) && trim($value) !== '') {
                    $found = true;
                    break;
                }
            }

            if ($found) {
                $present++;
            } else {
                $missing[] = $required;
            }
        }

        $total = count($this->requiredForCompleteness);
        $score = $total > 0 ? (int) round(($present / $total) * 100) : 100;

        return [
            'score' => $score,
            'present' => $present,
            'total' => $total,
            'missing' => $missing,
        ];
    }

    /**
     * Check if a parameter name is a standard UTM parameter.
     */
    public function isUtmParam(string $name): bool
    {
        return in_array($name, self::STANDARD_PARAMS, true);
    }

    /**
     * Resolve an alias to its canonical UTM parameter name.
     *
     * For example: 'source' → 'utm_source', 'campaign' → 'utm_campaign'.
     * Returns the original key if no alias is found.
     */
    public function resolveAlias(string $key): string
    {
        return $this->aliases[$key] ?? $key;
    }

    /**
     * Get the list of configured aliases.
     *
     * @return array<string, string>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * Get the list of required parameters for completeness scoring.
     *
     * @return list<string>
     */
    public function getRequiredForCompleteness(): array
    {
        return $this->requiredForCompleteness;
    }

    /**
     * Get the current configuration as an array.
     *
     * Useful for debugging and admin commands.
     *
     * @return array{enabled: bool, max_value_length: int, max_key_length: int, lowercase_source_medium: bool, trim_values: bool, strip_html: bool, aliases: array<string, string>, required_for_completeness: list<string>, internal_params_count: int, standard_params_count: int}
     */
    public function configSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'max_value_length' => $this->maxValueLength,
            'max_key_length' => $this->maxKeyLength,
            'lowercase_source_medium' => $this->lowercaseSourceMedium,
            'trim_values' => $this->trimValues,
            'strip_html' => $this->stripHtml,
            'aliases' => $this->aliases,
            'required_for_completeness' => $this->requiredForCompleteness,
            'internal_params_count' => count($this->internalParams()),
            'standard_params_count' => count(self::STANDARD_PARAMS),
        ];
    }

    /**
     * Extract and sanitize UTM params from a URL in one step.
     *
     * Convenience method combining extractFromUrl + sanitizeParams.
     *
     * @return array<string, string>
     */
    public function extractAndSanitizeUrl(string $url): array
    {
        return $this->sanitizeParams($this->extractFromUrl($url));
    }

    /**
     * Clean a URL and then decorate it with new UTM params.
     *
     * Useful for re-tagging outbound links: strip old tracking IDs,
     * then add your own UTM params.
     *
     * @param  array<string, string>  $utmParams
     */
    public function cleanAndDecorate(string $url, array $utmParams): string
    {
        return $this->decorateUrl($this->cleanUrl($url), $utmParams);
    }

    /**
     * Get default aliases.
     *
     * Returns the built-in alias mappings for common non-standard UTM keys.
     *
     * @return array<string, string>
     */
    public static function defaultAliases(): array
    {
        return [
            'source' => 'utm_source',
            'medium' => 'utm_medium',
            'campaign' => 'utm_campaign',
            'term' => 'utm_term',
            'content' => 'utm_content',
        ];
    }
}
