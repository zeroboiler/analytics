<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Security;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Static catalog of all security analytics events.
 *
 * Provides a central registry for security event names, classes, and metadata.
 * Use for validation, lookup, and bulk operations in security monitoring contexts.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, posthog: string, plausible: string|null, mixpanel: string, amplitude: string, tiktok: string|null, linkedin: string|null}
 *
 * @since 9.9.0
 */
final class SecurityEvents
{
    /** @var array<string, EventEntry> */
    private static array $catalog = [];

    /**
     * Build the event catalog (lazy initialization).
     *
     * @return array<string, EventEntry>
     */
    private static function catalog(): array
    {
        if (self::$catalog !== []) {
            return self::$catalog;
        }

        self::$catalog = [
            'login_attempt' => [
                'name' => 'login_attempt',
                'class' => LoginAttemptEvent::class,
                'ga4' => 'login_attempt',
                'meta' => 'CustomEvent',
                'posthog' => 'login_attempt',
                'plausible' => null,
                'mixpanel' => 'Login Attempt',
                'amplitude' => 'Login Attempt',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'suspicious_activity' => [
                'name' => 'suspicious_activity',
                'class' => SuspiciousActivityEvent::class,
                'ga4' => 'suspicious_activity',
                'meta' => 'CustomEvent',
                'posthog' => 'suspicious_activity',
                'plausible' => null,
                'mixpanel' => 'Suspicious Activity',
                'amplitude' => 'Suspicious Activity',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'data_access_audit' => [
                'name' => 'data_access_audit',
                'class' => DataAccessAuditEvent::class,
                'ga4' => 'data_access_audit',
                'meta' => 'CustomEvent',
                'posthog' => 'data_access_audit',
                'plausible' => null,
                'mixpanel' => 'Data Access Audit',
                'amplitude' => 'Data Access Audit',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'rate_limit_exceeded' => [
                'name' => 'rate_limit_exceeded',
                'class' => RateLimitExceededEvent::class,
                'ga4' => 'rate_limit_exceeded',
                'meta' => 'CustomEvent',
                'posthog' => 'rate_limit_exceeded',
                'plausible' => null,
                'mixpanel' => 'Rate Limit Exceeded',
                'amplitude' => 'Rate Limit Exceeded',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'mfa_challenge' => [
                'name' => 'mfa_challenge',
                'class' => MfaChallengeEvent::class,
                'ga4' => 'mfa_challenge',
                'meta' => 'CustomEvent',
                'posthog' => 'mfa_challenge',
                'plausible' => null,
                'mixpanel' => 'MFA Challenge',
                'amplitude' => 'MFA Challenge',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // AI agent / automation tool audit trail (v90.0.0)
            'ai_agent_access' => [
                'name' => 'ai_agent_access',
                'class' => AiAgentAccessEvent::class,
                'ga4' => 'ai_agent_access',
                'meta' => 'CustomEvent',
                'posthog' => 'ai_agent_access',
                'plausible' => null,
                'mixpanel' => 'AI Agent Access',
                'amplitude' => 'AI Agent Access',
                'tiktok' => null,
                'linkedin' => null,
            ],
        ];

        return self::$catalog;
    }

    /**
     * Get all security event names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Get all security event entries.
     *
     * @return array<string, EventEntry>
     */
    public static function all(): array
    {
        return self::catalog();
    }

    /**
     * Get a specific event entry by name.
     *
     * @return EventEntry|null
     */
    public static function get(string $name): ?array
    {
        return self::catalog()[$name] ?? null;
    }

    /**
     * Check if an event name exists in the catalog.
     */
    public static function has(string $name): bool
    {
        return isset(self::catalog()[$name]);
    }

    /**
     * Get the total number of security events.
     */
    public static function count(): int
    {
        return count(self::catalog());
    }

    /**
     * Get all GA4 event names in this category.
     *
     * @return list<string>
     */
    public static function ga4Names(): array
    {
        return array_map(
            fn (array $entry): string => $entry['ga4'],
            self::catalog(),
        );
    }

    /**
     * Get all Meta Pixel event names in this category (non-null only).
     *
     * @return list<string>
     */
    public static function metaNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['meta'],
                self::catalog(),
            ),
            fn (?string $meta): bool => $meta !== null,
        ));
    }

    /**
     * Get the event class for a given event name.
     *
     * @return class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>|null
     */
    public static function classFor(string $name): ?string
    {
        return self::catalog()[$name]['class'] ?? null;
    }

    /**
     * Get the category name for this catalog.
     */
    public static function category(): string
    {
        return 'security';
    }

    /**
     * Build a typed login_attempt event.
     *
     * @param  array{user_id?: string, method?: string, success?: bool}  $params
     * @return AnalyticsEvent
     */
    public static function loginAttempt(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'login_attempt', params: $params, category: 'security');
    }

    /**
     * Build a typed suspicious_activity event.
     *
     * @param  array{activity_type?: string, severity?: string, details?: string}  $params
     * @return AnalyticsEvent
     */
    public static function suspiciousActivity(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'suspicious_activity', params: $params, category: 'security');
    }

    /**
     * Build a typed data_access_audit event.
     *
     * @param  array{resource?: string, user_id?: string, action?: string}  $params
     * @return AnalyticsEvent
     */
    public static function dataAccessAudit(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'data_access_audit', params: $params, category: 'security');
    }

    /**
     * Build a typed rate_limit_exceeded event.
     *
     * @param  array{endpoint?: string, limit?: int, window?: string}  $params
     * @return AnalyticsEvent
     */
    public static function rateLimitExceeded(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'rate_limit_exceeded', params: $params, category: 'security');
    }

    /**
     * Build a typed mfa_challenge event.
     *
     * @param  array{method?: string, success?: bool, user_id?: string}  $params
     * @return AnalyticsEvent
     */
    public static function mfaChallenge(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'mfa_challenge', params: $params, category: 'security');
    }

    /**
     * Build a typed ai_agent_access event.
     *
     * @param  array{agent_id?: string, action?: string, resource?: string, authorized?: bool}  $params
     * @return AnalyticsEvent
     */
    public static function aiAgentAccess(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'ai_agent_access', params: $params, category: 'security');
    }

    /**
     * Build a typed AnalyticsEvent from any catalog entry by name.
     *
     * Generic factory — validates the event name against the catalog.
     *
     * @param  array<string, mixed>  $params
     * @return AnalyticsEvent
     *
     * @throws \InvalidArgumentException
     */
    public static function build(string $name, array $params = []): AnalyticsEvent
    {
        if (!self::has($name)) {
            throw new \InvalidArgumentException(
                "Unknown security event: {$name}. Available: ".implode(', ', self::names()),
            );
        }

        return new AnalyticsEvent(name: $name, params: $params, category: 'security');
    }

    /**
     * Get all PostHog event names in this category.
     *
     * @return list<string>
     */
    public static function posthogNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['posthog'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all Plausible event names in this category (non-null only).
     *
     * @return list<string>
     */
    public static function plausibleNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['plausible'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all Mixpanel event names in this category.
     *
     * @return list<string>
     */
    public static function mixpanelNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['mixpanel'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all Amplitude event names in this category.
     *
     * @return list<string>
     */
    public static function amplitudeNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['amplitude'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all TikTok event names in this category (non-null only).
     *
     * @return list<string>
     */
    public static function tiktokNames(): array
    {
        return [];
    }

    /**
     * Get all LinkedIn event names in this category (non-null only).
     *
     * @return list<string>
     */
    public static function linkedinNames(): array
    {
        return [];
    }
}
