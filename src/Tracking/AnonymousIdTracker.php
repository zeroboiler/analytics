<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tracking;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;

/**
 * Manages anonymous client identity persistence.
 *
 * Generates and persists a UUID-based anonymous ID across requests
 * using httpOnly cookies. Provides server-side generation and validation
 * of client identifiers, with configurable TTL and cookie settings.
 *
 * Complements the UserIdentityTracker by handling anonymous → authenticated
 * identity transitions and providing a stable identifier before login.
 *
 * @since 1.0.0
 */
final class AnonymousIdTracker
{
    private readonly string $cookieName;

    private readonly int $cookieTtl;

    private readonly bool $cookieSecure;

    private readonly string $cookieSameSite;

    private readonly string $cookiePrefix;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
    {
        $identityConfig = $config->get('zeroboiler.analytics.identity', []);
        /** @var array{cookie_name?: string, cookie_ttl?: int, cookie_secure?: bool, cookie_samesite?: string, cookie_prefix?: string} $identityConfig */
        $this->cookieName = $identityConfig['cookie_name'] ?? 'zb_analytics_id';
        $this->cookieTtl = (int) ($identityConfig['cookie_ttl'] ?? 525600); // 365 days in minutes
        $this->cookieSecure = (bool) ($identityConfig['cookie_secure'] ?? true);
        $this->cookieSameSite = $identityConfig['cookie_samesite'] ?? 'Lax';
        $this->cookiePrefix = $identityConfig['cookie_prefix'] ?? '';
    }

    /**
     * Resolve or generate an anonymous ID.
     *
     * Checks for an existing cookie first. If none exists, generates
     * a new UUID and queues it to be set on the response.
     *
     * @param  string|null  $existingCookie  Existing cookie value (from request)
     * @return array{anonymous_id: string, is_new: bool}
     */
    public function resolve(?string $existingCookie = null): array
    {
        if ($existingCookie !== null && $existingCookie !== '' && $this->isValidUuid($existingCookie)) {
            return [
                'anonymous_id' => $existingCookie,
                'is_new' => false,
            ];
        }

        return [
            'anonymous_id' => $this->generate(),
            'is_new' => true,
        ];
    }

    /**
     * Generate a new anonymous ID (UUID v4).
     */
    public function generate(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Check if a string is a valid UUID format.
     */
    public function isValidUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * Get the full cookie name (with optional prefix).
     */
    public function getCookieName(): string
    {
        return $this->cookiePrefix !== '' ? $this->cookiePrefix . '_' . $this->cookieName : $this->cookieName;
    }

    /**
     * Get cookie parameters for setcookie()/Cookie::queue().
     *
     * @return array{name: string, minutes: int, secure: bool, sameSite: string, httpOnly: bool}
     */
    public function getCookieParams(): array
    {
        return [
            'name' => $this->getCookieName(),
            'minutes' => $this->cookieTtl,
            'secure' => $this->cookieSecure,
            'sameSite' => $this->cookieSameSite,
            'httpOnly' => true,
        ];
    }

    /**
     * Queue the anonymous ID cookie on the current response.
     *
     * Call this after generating a new anonymous ID to persist it.
     */
    public function queueCookie(string $anonymousId): void
    {
        $params = $this->getCookieParams();

        cookie()->queue(
            $params['name'],
            $anonymousId,
            $params['minutes'],
            null, // path
            null, // domain
            $params['secure'],
            $params['httpOnly'],
            $params['sameSite'],
        );
    }
}
