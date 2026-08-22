<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;

/**
 * SDK Scope Token Service — Scoped write tokens for client-side permission management.
 *
 * Generates and validates scoped API tokens that control which analytics
 * operations a client-side SDK is authorized to perform. Tokens define
 * allowed event types, rate limits, and data access boundaries.
 *
 * Each scope token encodes:
 * - Write permissions (events, batch, identify, consent)
 * - Allowed event categories (ecommerce, saas, engagement, custom)
 * - Per-token rate limits
 * - Environment restrictions (production, staging)
 * - Expiration time
 *
 * Inspired by Segment's write keys, PostHog project API keys, and
 * Plausible's site-specific API tokens.
 *
 * Configuration: `zeroboiler.analytics.sdk_tokens`
 *
 * @since 20.0.0
 */
final class SdkScopeTokenService
{
    /** @var string Cache key prefix for token storage */
    private const CACHE_PREFIX = 'zb_sdk_token_';

    /** @var string Cache key prefix for rate tracking */
    private const RATE_PREFIX = 'zb_sdk_rate_';

    /** Scope permissions */
    public const PERM_TRACK = 'track';
    public const PERM_BATCH = 'batch';
    public const PERM_IDENTIFY = 'identify';
    public const PERM_CONSENT = 'consent';
    public const PERM_PAGEVIEW = 'pageview';

    /** All available permissions */
    private const ALL_PERMISSIONS = [
        self::PERM_TRACK,
        self::PERM_BATCH,
        self::PERM_IDENTIFY,
        self::PERM_CONSENT,
        self::PERM_PAGEVIEW,
    ];

    /** All event categories */
    public const CATEGORY_ECOMMERCE = 'ecommerce';
    public const CATEGORY_SAAS = 'saas';
    public const CATEGORY_ENGAGEMENT = 'engagement';
    public const CATEGORY_CUSTOM = 'custom';

    /** @var array<string, string> All valid categories */
    private const ALL_CATEGORIES = [
        self::CATEGORY_ECOMMERCE,
        self::CATEGORY_SAAS,
        self::CATEGORY_ENGAGEMENT,
        self::CATEGORY_CUSTOM,
    ];

    private CacheRepository $cache;

    private bool $enabled;

    private int $tokenTtl;

    private int $defaultRateLimit;

    private int $maxTokensPerScope;

    private string $hashAlgorithm;

    private string $signingKey;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Config repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $tokenConfig = $config->get('zeroboiler.analytics.sdk_tokens', []);
        /** @var array{enabled?: bool, token_ttl?: int, default_rate_limit?: int, max_tokens_per_scope?: int, hash_algorithm?: string, signing_key?: string} $tokenConfig */

        $this->enabled = (bool) ($tokenConfig['enabled'] ?? false);
        $this->tokenTtl = (int) ($tokenConfig['token_ttl'] ?? 7776000); // 90 days
        $this->defaultRateLimit = (int) ($tokenConfig['default_rate_limit'] ?? 100); // per minute
        $this->maxTokensPerScope = (int) ($tokenConfig['max_tokens_per_scope'] ?? 10);
        $this->hashAlgorithm = (string) ($tokenConfig['hash_algorithm'] ?? 'sha256');
        $this->signingKey = (string) ($tokenConfig['signing_key'] ?? '');
    }

    /**
     * Check if the SDK scope token service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Generate a new scoped SDK token.
     *
     * @param  string  $scopeName  Human-readable scope name (e.g., 'web-client', 'mobile-app')
     * @param  list<string>  $permissions  Allowed permissions
     * @param  list<string>  $categories  Allowed event categories
     * @param  array{rate_limit?: int, environment?: string, allowed_origins?: list<string>, metadata?: array<string, mixed>}  $options
     * @return array{token: string, scope: string, permissions: list<string>, categories: list<string>, expires_at: int}
     */
    public function generateToken(
        string $scopeName,
        array $permissions = [self::PERM_TRACK, self::PERM_BATCH],
        array $categories = [self::CATEGORY_ECOMMERCE, self::CATEGORY_SAAS, self::CATEGORY_ENGAGEMENT, self::CATEGORY_CUSTOM],
        array $options = [],
    ): array {
        // Validate permissions
        foreach ($permissions as $perm) {
            if (! in_array($perm, self::ALL_PERMISSIONS, true)) {
                throw new \ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException("Invalid permission: {$perm}");
            }
        }

        // Validate categories
        foreach ($categories as $cat) {
            if (! in_array($cat, self::ALL_CATEGORIES, true)) {
                throw new \ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException("Invalid event category: {$cat}");
            }
        }

        // Check token limit
        $scopeKey = self::CACHE_PREFIX . 'scope_' . md5($scopeName);
        $existingTokens = $this->cache->get($scopeKey, []);

        if (count($existingTokens) >= $this->maxTokensPerScope) {
            throw new \ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException("Maximum tokens per scope reached: {$this->maxTokensPerScope}");
        }

        // Generate token
        $rawToken = $this->generateRawToken();
        $tokenHash = $this->hashToken($rawToken);
        $rateLimit = (int) ($options['rate_limit'] ?? $this->defaultRateLimit);
        $environment = (string) ($options['environment'] ?? 'production');
        $allowedOrigins = (array) ($options['allowed_origins'] ?? []);
        $metadata = (array) ($options['metadata'] ?? []);
        $expiresAt = time() + $this->tokenTtl;

        // Store token data
        $tokenData = [
            'token_hash' => $tokenHash,
            'scope' => $scopeName,
            'permissions' => $permissions,
            'categories' => $categories,
            'rate_limit' => $rateLimit,
            'environment' => $environment,
            'allowed_origins' => $allowedOrigins,
            'metadata' => $metadata,
            'created_at' => time(),
            'expires_at' => $expiresAt,
        ];

        $this->cache->put(
            self::CACHE_PREFIX . 'token_' . $tokenHash,
            $tokenData,
            $this->tokenTtl,
        );

        // Track scope tokens
        $existingTokens[] = $tokenHash;
        $this->cache->put($scopeKey, $existingTokens, $this->tokenTtl);

        return [
            'token' => $rawToken,
            'scope' => $scopeName,
            'permissions' => $permissions,
            'categories' => $categories,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Validate a token and check a specific permission.
     *
     * @param  string  $rawToken  The raw token string
     * @param  string  $permission  The permission to check
     * @return bool Whether the token is valid and has the permission
     */
    public function hasPermission(string $rawToken, string $permission): bool
    {
        $tokenData = $this->getTokenData($rawToken);

        if ($tokenData === null) {
            return false;
        }

        return in_array($permission, $tokenData['permissions'], true);
    }

    /**
     * Validate a token and check category access.
     *
     * @param  string  $rawToken  The raw token string
     * @param  string  $category  Event category to check
     */
    public function hasCategory(string $rawToken, string $category): bool
    {
        $tokenData = $this->getTokenData($rawToken);

        if ($tokenData === null) {
            return false;
        }

        return in_array(self::CATEGORY_CUSTOM, $tokenData['categories'], true)
            || in_array($category, $tokenData['categories'], true);
    }

    /**
     * Check rate limit for a token.
     *
     * @param  string  $rawToken  The raw token string
     * @return array{allowed: bool, remaining: int, reset_at: int}
     */
    public function checkRateLimit(string $rawToken): array
    {
        $tokenData = $this->getTokenData($rawToken);

        if ($tokenData === null) {
            return ['allowed' => false, 'remaining' => 0, 'reset_at' => 0];
        }

        $tokenHash = $this->hashToken($rawToken);
        $rateKey = self::RATE_PREFIX . $tokenHash;
        $now = time();
        $windowStart = (int) floor($now / 60) * 60; // Per-minute window
        $rateLimit = $tokenData['rate_limit'];

        $current = $this->cache->get($rateKey, ['count' => 0, 'window' => 0]);

        if ($current['window'] !== $windowStart) {
            $current = ['count' => 0, 'window' => $windowStart];
        }

        $allowed = $current['count'] < $rateLimit;
        $remaining = max(0, $rateLimit - $current['count']);

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => $windowStart + 60,
        ];
    }

    /**
     * Increment rate limit counter for a token.
     *
     * @param  string  $rawToken  The raw token string
     */
    public function incrementRateLimit(string $rawToken): void
    {
        $tokenHash = $this->hashToken($rawToken);
        $rateKey = self::RATE_PREFIX . $tokenHash;
        $now = time();
        $windowStart = (int) floor($now / 60) * 60;

        $current = $this->cache->get($rateKey, ['count' => 0, 'window' => 0]);

        if ($current['window'] !== $windowStart) {
            $current = ['count' => 0, 'window' => $windowStart];
        }

        $current['count']++;
        $this->cache->put($rateKey, $current, 120); // 2 minutes TTL for rate window
    }

    /**
     * Validate a token without checking any specific permission.
     *
     * @param  string  $rawToken  The raw token string
     * @return bool Whether the token is valid and not expired
     */
    public function isValid(string $rawToken): bool
    {
        return $this->getTokenData($rawToken) !== null;
    }

    /**
     * Revoke a token.
     *
     * @param  string  $rawToken  The raw token string
     */
    public function revokeToken(string $rawToken): bool
    {
        $tokenHash = $this->hashToken($rawToken);
        $cacheKey = self::CACHE_PREFIX . 'token_' . $tokenHash;

        $existing = $this->cache->get($cacheKey);

        if ($existing === null) {
            return false;
        }

        $this->cache->forget($cacheKey);

        return true;
    }

    /**
     * Get token data from cache.
     *
     * @param  string  $rawToken  The raw token string
     * @return array{token_hash: string, scope: string, permissions: list<string>, categories: list<string>, rate_limit: int, environment: string, allowed_origins: list<string>, metadata: array<string, mixed>, created_at: int, expires_at: int}|null
     */
    private function getTokenData(string $rawToken): ?array
    {
        $tokenHash = $this->hashToken($rawToken);
        $cacheKey = self::CACHE_PREFIX . 'token_' . $tokenHash;
        $data = $this->cache->get($cacheKey);

        if (! is_array($data)) {
            return null;
        }

        // Check expiration
        if (time() > ($data['expires_at'] ?? 0)) {
            $this->cache->forget($cacheKey);

            return null;
        }

        return $data;
    }

    /**
     * Generate a cryptographically secure random token.
     */
    private function generateRawToken(): string
    {
        return 'zb_' . Str::random(40);
    }

    /**
     * Hash a token for storage.
     */
    private function hashToken(string $rawToken): string
    {
        $salt = $this->signingKey !== '' ? $this->signingKey : 'zb_analytics_default';

        return hash($this->hashAlgorithm, $salt . $rawToken);
    }

    /**
     * Get all available permissions.
     *
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return self::ALL_PERMISSIONS;
    }

    /**
     * Get all available event categories.
     *
     * @return list<string>
     */
    public static function allCategories(): array
    {
        return self::ALL_CATEGORIES;
    }
}
