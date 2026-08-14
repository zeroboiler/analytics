<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Auto-tracks feature flag evaluation events as analytics events.
 *
 * Integrates with application feature flag systems to capture which flags
 * were evaluated, their variant assignments, and user context. This enables
 * analytics-driven experimentation analysis across GA4, Meta, PostHog, and
 * other providers.
 *
 * Usage:
 *   $observer->recordEvaluation('new_onboarding', 'variant_b', $userId, ['source' => 'launchdarkly']);
 *
 * @since 120.0.0
 */
final class SaaSFeatureFlagObserver
{
    private AnalyticsManager $manager;

    private ConfigRepository $config;

    private bool $enabled;

    private bool $trackExposures;

    private bool $trackConversions;

    /** @var list<string> Flag names to ignore (no analytics events for these) */
    private array $ignoredFlags = [];

    /** @var array<string, bool> In-memory dedup cache for evaluations */
    private array $recordedEvaluations = [];

    /** @var array<string, bool> In-memory dedup cache for conversions */
    private array $recordedConversions = [];

    /**
     * @param  AnalyticsManager  $manager
     * @param  ConfigRepository  $config
     */
    public function __construct(AnalyticsManager $manager, ConfigRepository $config): void
    {
        $this->manager = $manager;
        $this->config = $config;

        $ffConfig = $config->get('zeroboiler.analytics.feature_flags', []);
        /** @var array{enabled?: bool, track_exposures?: bool, track_conversions?: bool, ignored_flags?: list<string>} $ffConfig */
        $this->enabled = (bool) ($ffConfig['enabled'] ?? true);
        $this->trackExposures = (bool) ($ffConfig['track_exposures'] ?? true);
        $this->trackConversions = (bool) ($ffConfig['track_conversions'] ?? true);
        $this->ignoredFlags = (array) ($ffConfig['ignored_flags'] ?? []);
    }

    /**
     * Record a feature flag evaluation (exposure) event.
     *
     * Fires an `ab_test_exposure` analytics event when a user is exposed
     * to a feature flag variant. Deduplicates consecutive identical
     * evaluations for the same user+flag combination.
     *
     * @param  string  $flagName  Feature flag name (e.g., 'new_onboarding')
     * @param  string  $variant  Variant assigned (e.g., 'control', 'variant_b')
     * @param  string|null  $userId  Authenticated user ID (null for anonymous)
     * @param  array<string, mixed>  $context  Additional context (source, environment, etc.)
     * @return bool  Whether the event was dispatched
     */
    public function recordEvaluation(string $flagName, string $variant, ?string $userId = null, array $context = []): bool
    {
        if (! $this->enabled || ! $this->trackExposures) {
            return false;
        }

        if ($this->shouldIgnoreFlag($flagName)) {
            return false;
        }

        // Deduplicate: skip if same user+flag+variant was already recorded in this session
        if ($this->isDuplicateEvaluation($flagName, $variant, $userId)) {
            return false;
        }

        $event = new AnalyticsEvent(
            name: 'ab_test_exposure',
            params: [
                'flag_name' => $flagName,
                'variant' => $variant,
                'source' => $context['source'] ?? 'native',
                'environment' => $context['environment'] ?? $this->detectEnvironment(),
                'flag_type' => $context['flag_type'] ?? 'boolean',
            ],
            userId: $userId,
        );

        $this->manager->trackEvent($event);
        $this->markEvaluationRecorded($flagName, $variant, $userId);

        return true;
    }

    /**
     * Record a feature flag conversion event.
     *
     * Fires when a user completes a conversion action within a feature flag experiment.
     * Used for computing statistical significance and variant performance.
     *
     * @param  string  $flagName  Feature flag name
     * @param  string  $variant  Variant the user was in
     * @param  string  $conversionName  Name of the conversion event (e.g., 'signup_completed')
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $context  Additional context (conversion value, duration, etc.)
     * @return bool  Whether the event was dispatched
     */
    public function recordConversion(string $flagName, string $variant, string $conversionName, ?string $userId = null, array $context = []): bool
    {
        if (! $this->enabled || ! $this->trackConversions) {
            return false;
        }

        if ($this->shouldIgnoreFlag($flagName)) {
            return false;
        }

        $event = new AnalyticsEvent(
            name: 'goal_conversion',
            params: [
                'flag_name' => $flagName,
                'variant' => $variant,
                'conversion_name' => $conversionName,
                'conversion_value' => $context['conversion_value'] ?? null,
                'conversion_duration_ms' => $context['conversion_duration_ms'] ?? null,
                'source' => $context['source'] ?? 'native',
            ],
            userId: $userId,
        );

        $this->manager->trackEvent($event);
        $this->markConversionRecorded($flagName, $conversionName, $userId);

        return true;
    }

    /**
     * Check if a feature flag evaluation is a duplicate (already tracked in this session).
     *
     * Uses a simple in-memory cache keyed by user_id + flag_name + variant.
     *
     * @param  string  $flagName
     * @param  string  $variant
     * @param  string|null  $userId
     * @return bool
     */
    public function isDuplicateEvaluation(string $flagName, string $variant, ?string $userId): bool
    {
        $key = $this->dedupKey($flagName, $variant, $userId);

        return isset($this->recordedEvaluations[$key]);
    }

    /**
     * Check if a flag name should be ignored (not tracked).
     */
    public function shouldIgnoreFlag(string $flagName): bool
    {
        return in_array($flagName, $this->ignoredFlags, true);
    }

    /**
     * Get the list of currently ignored flag names.
     *
     * @return list<string>
     */
    public function getIgnoredFlags(): array
    {
        return $this->ignoredFlags;
    }

    /**
     * Check if the observer is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if exposure tracking is enabled.
     */
    public function isExposureTrackingEnabled(): bool
    {
        return $this->trackExposures;
    }

    /**
     * Check if conversion tracking is enabled.
     */
    public function isConversionTrackingEnabled(): bool
    {
        return $this->trackConversions;
    }

    /**
     * Get summary of feature flag tracking activity.
     *
     * @return array{enabled: bool, exposures_tracked: int, conversions_tracked: int, unique_flags: int, ignored_flags: int}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'exposures_tracked' => count($this->recordedEvaluations),
            'conversions_tracked' => count($this->recordedConversions),
            'unique_flags' => count(array_unique(array_map(
                fn (string $key): string => explode(':', $key)[1] ?? '',
                array_keys($this->recordedEvaluations),
            ))),
            'ignored_flags' => count($this->ignoredFlags),
        ];
    }

    /**
     * Clear all in-memory deduplication state.
     */
    public function reset(): void
    {
        $this->recordedEvaluations = [];
        $this->recordedConversions = [];
    }

    /**
     * Detect the current application environment.
     *
     * @return string
     */
    private function detectEnvironment(): string
    {
        return $this->config->get('app.env', 'production');
    }

    /**
     * Build a deduplication key from flag evaluation parameters.
     *
     * @param  string  $flagName
     * @param  string  $variant
     * @param  string|null  $userId
     * @return string
     */
    private function dedupKey(string $flagName, string $variant, ?string $userId): string
    {
        return sprintf('%s:%s:%s:%s', $userId ?? 'anon', $flagName, $variant, 'eval');
    }

    /**
     * Mark an evaluation as recorded for deduplication.
     *
     * @param  string  $flagName
     * @param  string  $variant
     * @param  string|null  $userId
     */
    private function markEvaluationRecorded(string $flagName, string $variant, ?string $userId): void
    {
        $key = $this->dedupKey($flagName, $variant, $userId);
        $this->recordedEvaluations[$key] = true;
    }

    /**
     * Mark a conversion as recorded for deduplication.
     *
     * @param  string  $flagName
     * @param  string  $conversionName
     * @param  string|null  $userId
     */
    private function markConversionRecorded(string $flagName, string $conversionName, ?string $userId): void
    {
        $key = sprintf('%s:%s:%s:%s', $userId ?? 'anon', $flagName, $conversionName, 'conv');
        $this->recordedConversions[$key] = true;
    }
}
