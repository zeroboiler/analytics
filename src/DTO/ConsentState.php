<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable representation of user consent state for analytics providers.
 *
 * Implements Google Consent Mode v2 signal names:
 * ad_storage, ad_user_data, ad_personalization, analytics_storage, functionality_storage,
 * personalization_storage, security_storage.
 *
 * Each signal is one of: 'granted', 'denied', or null (not set / provider default).
 *
 * @since 1.0.0
 */
final readonly class ConsentState
{
    /** @var array<string, string> */
    public array $signals;

    /**
     * @param  array<string, string|null>  $signals  Signal name => 'granted'|'denied'|null
     */
    public function __construct(array $signals = []): void
    {
        $normalized = [];
        foreach ($signals as $key => $value) {
            if ($value === 'granted' || $value === 'denied') {
                $normalized[$key] = $value;
            }
        }

        $this->signals = $normalized;
    }

    /**
     * Create a "all granted" consent state.
     */
    public static function granted(): self
    {
        return new self([
            'ad_storage' => 'granted',
            'ad_user_data' => 'granted',
            'ad_personalization' => 'granted',
            'analytics_storage' => 'granted',
            'functionality_storage' => 'granted',
            'personalization_storage' => 'granted',
            'security_storage' => 'granted',
        ]);
    }

    /**
     * Create a "all denied" consent state (GDPR-safe default).
     */
    public static function denied(): self
    {
        return new self([
            'ad_storage' => 'denied',
            'ad_user_data' => 'denied',
            'ad_personalization' => 'denied',
            'analytics_storage' => 'denied',
            'functionality_storage' => 'denied',
            'personalization_storage' => 'denied',
            'security_storage' => 'granted', // security_storage is always granted per Google spec
        ]);
    }

    /**
     * Check if a specific signal is granted.
     */
    public function isGranted(string $signal): bool
    {
        return ($this->signals[$signal] ?? null) === 'granted';
    }

    /**
     * Check if a specific signal is denied.
     */
    public function isDenied(string $signal): bool
    {
        return ($this->signals[$signal] ?? null) === 'denied';
    }

    /**
     * Check if analytics storage consent is granted.
     */
    public function hasAnalyticsConsent(): bool
    {
        return $this->isGranted('analytics_storage');
    }

    /**
     * Check if ad storage consent is granted.
     */
    public function hasAdConsent(): bool
    {
        return $this->isGranted('ad_storage');
    }

    /**
     * Return a new state with additional/overridden signals.
     *
     * @param  array<string, string|null>  $signals
     */
    public function with(array $signals): self
    {
        return new self(array_merge($this->signals, $signals));
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->signals;
    }
}
