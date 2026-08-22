<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * First-Party Data Service — Privacy-First User Data Capture.
 *
 * Captures and manages first-party data signals for analytics in the
 * cookieless tracking era. First-party data is collected directly from
 * user interactions (logged-in users, form submissions, account settings)
 * rather than third-party cookies or tracking pixels.
 *
 * This service provides:
 * - User preference capture (newsletter, theme, language, notifications)
 * - Interest and intent signals (feature interests, content preferences)
 * - Behavioral cohort assignment based on first-party signals
 * - Privacy-compliant data export for analytics enrichment
 * - First-party data readiness scoring
 *
 * All data is stored in cache (production should use a persistent store
 * via a custom FirstPartyDataStoreInterface implementation).
 *
 * @since 148.0.0
 */
final class FirstPartyDataService
{
    private const CACHE_PREFIX = 'zb_fpd_';

    private const DEFAULT_TTL = 7776000; // 90 days

    private const SUPPORTED_PREFERENCE_TYPES = [
        'newsletter',
        'theme',
        'language',
        'notifications',
        'privacy_level',
        'timezone',
        'currency',
    ];

    private const SUPPORTED_INTEREST_TYPES = [
        'feature',
        'content',
        'integration',
        'pricing_tier',
        'use_case',
        'industry',
    ];

    private CacheRepository $cache;

    private int $cacheTtl;

    private int $maxPreferencesPerUser;

    private int $maxInterestsPerUser;

    private bool $enabled;

    /**
     * @param  array{enabled?: bool, cache_ttl?: int, max_preferences_per_user?: int, max_interests_per_user?: int, auto_cohort?: bool}  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $configRepo, array $config = []){
        $fullConfig = $configRepo->get('zeroboiler.analytics.first_party_data', []);
        $merged = array_merge($fullConfig, $config);

        $this->cache = $cache;
        $this->enabled = (bool) ($merged['enabled'] ?? false);
        $this->cacheTtl = (int) ($merged['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->maxPreferencesPerUser = (int) ($merged['max_preferences_per_user'] ?? 50);
        $this->maxInterestsPerUser = (int) ($merged['max_interests_per_user'] ?? 20);
    }

    /**
     * Capture a user preference signal.
     *
     * @param  array<string, mixed>  $metadata  Optional metadata (source, timestamp, etc.)
     */
    public function capturePreference(string $userId, string $type, string $value, array $metadata = []): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if ($userId === '' || $type === '' || $value === '') {
            return false;
        }

        if (! in_array($type, self::SUPPORTED_PREFERENCE_TYPES, true)) {
            return false;
        }

        $key = self::CACHE_PREFIX . 'prefs_' . $userId;
        $prefs = $this->cache->get($key, []);

        /** @var array<string, array{value: string, metadata: array<string, mixed>, updated_at: string}> $prefs */

        if (count($prefs) >= $this->maxPreferencesPerUser && ! isset($prefs[$type])) {
            return false; // Capacity reached for new preference
        }

        $prefs[$type] = [
            'value' => $value,
            'metadata' => $metadata,
            'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
        ];

        return $this->cache->put($key, $prefs, $this->cacheTtl);
    }

    /**
     * Capture a user interest signal.
     *
     * @param  array<string, mixed>  $metadata  Optional metadata (source, weight, etc.)
     */
    public function captureInterest(string $userId, string $type, string $value, array $metadata = []): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if ($userId === '' || $type === '' || $value === '') {
            return false;
        }

        if (! in_array($type, self::SUPPORTED_INTEREST_TYPES, true)) {
            return false;
        }

        $key = self::CACHE_PREFIX . 'interests_' . $userId;
        $interests = $this->cache->get($key, []);

        /** @var list<array{type: string, value: string, metadata: array<string, mixed>, captured_at: string}> $interests */

        if (count($interests) >= $this->maxInterestsPerUser) {
            array_shift($interests);
        }

        // Deduplicate: update existing interest if same type+value
        $existingIndex = null;
        foreach ($interests as $i => $interest) {
            if ($interest['type'] === $type && $interest['value'] === $value) {
                $existingIndex = $i;

                break;
            }
        }

        $entry = [
            'type' => $type,
            'value' => $value,
            'metadata' => $metadata,
            'captured_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
        ];

        if ($existingIndex !== null) {
            $interests[$existingIndex] = $entry;
        } else {
            $interests[] = $entry;
        }

        return $this->cache->put($key, $interests, $this->cacheTtl);
    }

    /**
     * Get all captured preferences for a user.
     *
     * @return array<string, array{value: string, metadata: array<string, mixed>, updated_at: string}>
     */
    public function getPreferences(string $userId): array
    {
        $key = self::CACHE_PREFIX . 'prefs_' . $userId;

        /** @var array<string, array{value: string, metadata: array<string, mixed>, updated_at: string}> $prefs */
        $prefs = $this->cache->get($key, []);

        return $prefs;
    }

    /**
     * Get a specific preference for a user.
     */
    public function getPreference(string $userId, string $type): ?string
    {
        $prefs = $this->getPreferences($userId);

        return $prefs[$type]['value'] ?? null;
    }

    /**
     * Get all captured interests for a user.
     *
     * @return list<array{type: string, value: string, metadata: array<string, mixed>, captured_at: string}>
     */
    public function getInterests(string $userId): array
    {
        $key = self::CACHE_PREFIX . 'interests_' . $userId;

        /** @var list<array{type: string, value: string, metadata: array<string, mixed>, captured_at: string}> $interests */
        $interests = $this->cache->get($key, []);

        return $interests;
    }

    /**
     * Get interests grouped by type.
     *
     * @return array<string, list<string>>
     */
    public function getInterestsByType(string $userId): array
    {
        $interests = $this->getInterests($userId);
        $grouped = [];

        foreach ($interests as $interest) {
            $type = $interest['type'];

            if (! isset($grouped[$type])) {
                $grouped[$type] = [];
            }

            $grouped[$type][] = $interest['value'];
        }

        return $grouped;
    }

    /**
     * Assign a behavioral cohort based on first-party signals.
     *
     * Uses preference and interest data to classify users into
     * behavioral cohorts for targeted analytics and personalization.
     *
     * Cohort assignments:
     * - 'power_user': Has 3+ feature interests + newsletter enabled
     * - 'explorer': Has interests in 2+ categories but no subscription preference
     * - 'pragmatist': Has currency + timezone preferences but few feature interests
     * - 'newcomer': Has fewer than 2 captured signals total
     * - 'enterprise_signal': Has industry or integration interests
     * - 'unknown': Insufficient data for classification
     */
    public function assignCohort(string $userId): string
    {
        $prefs = $this->getPreferences($userId);
        $interests = $this->getInterests($userId);
        $interestTypes = array_unique(array_column($interests, 'type'));

        $totalSignals = count($prefs) + count($interests);

        // Enterprise signal detection
        $hasEnterpriseSignal = false;
        foreach ($interests as $interest) {
            if (in_array($interest['type'], ['integration', 'industry', 'pricing_tier'], true)) {
                $hasEnterpriseSignal = true;

                break;
            }
        }

        // Power user: highly engaged
        $featureInterestCount = count(array_filter($interests, fn (array $i): bool => $i['type'] === 'feature'));
        $hasNewsletter = ($prefs['newsletter']['value'] ?? '') === 'enabled';

        if ($featureInterestCount >= 3 && $hasNewsletter) {
            return 'power_user';
        }

        if ($hasEnterpriseSignal) {
            return 'enterprise_signal';
        }

        if (count($interestTypes) >= 2 && ! isset($prefs['pricing_tier'])) {
            return 'explorer';
        }

        $hasCurrency = isset($prefs['currency']);
        $hasTimezone = isset($prefs['timezone']);

        if (($hasCurrency || $hasTimezone) && $featureInterestCount < 2) {
            return 'pragmatist';
        }

        if ($totalSignals < 2) {
            return 'newcomer';
        }

        return 'unknown';
    }

    /**
     * Export all first-party data for a user as a structured array.
     *
     * Useful for:
     * - GDPR data export requests (right of access)
     * - Analytics enrichment payloads
     * - User profile export
     *
     * @return array{user_id: string, preferences: array<string, array{value: string, metadata: array<string, mixed>, updated_at: string}>, interests: list<array{type: string, value: string, metadata: array<string, mixed>, captured_at: string}>, cohort: string, total_signals: int, exported_at: string}
     */
    public function exportUserData(string $userId): array
    {
        $prefs = $this->getPreferences($userId);
        $interests = $this->getInterests($userId);

        return [
            'user_id' => $userId,
            'preferences' => $prefs,
            'interests' => $interests,
            'cohort' => $this->assignCohort($userId),
            'total_signals' => count($prefs) + count($interests),
            'exported_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
        ];
    }

    /**
     * Delete all first-party data for a user (GDPR right to erasure).
     *
     * @return list<string> Cache keys deleted
     */
    public function deleteUser(string $userId): array
    {
        $deleted = [];

        $prefKey = self::CACHE_PREFIX . 'prefs_' . $userId;
        $interestKey = self::CACHE_PREFIX . 'interests_' . $userId;
        $cohortKey = self::CACHE_PREFIX . 'cohort_' . $userId;

        foreach ([$prefKey, $interestKey, $cohortKey] as $key) {
            if ($this->cache->forget($key)) {
                $deleted[] = $key;
            }
        }

        return $deleted;
    }

    /**
     * Get first-party data readiness score.
     *
     * Measures how well the application is capturing first-party data
     * signals. A higher score indicates better readiness for the
     * cookieless tracking era.
     *
     * @return array{score: int, grade: string, dimensions: array{preference_types: int, interest_types: int, avg_signals_per_user: float, cohort_coverage: float, recommendations: list<string>}}
     */
    public function readinessScore(): array
    {
        // In a real implementation, this would scan the cache/store for aggregates.
        // For now, return a structural assessment based on configuration.

        $dimensions = [
            'preference_types' => count(self::SUPPORTED_PREFERENCE_TYPES),
            'interest_types' => count(self::SUPPORTED_INTEREST_TYPES),
            'avg_signals_per_user' => 0.0,
            'cohort_coverage' => 0.0,
            'recommendations' => [
                'Capture newsletter preference on signup form',
                'Track feature interests via in-app usage patterns',
                'Record timezone/language from user profile settings',
                'Use interest signals for personalized onboarding',
                'Export first-party data for analytics enrichment',
            ],
        ];

        // Score: based on service being enabled + supported types
        $score = $this->enabled ? 40 : 0;
        $score += min(30, count(self::SUPPORTED_PREFERENCE_TYPES) * 2);
        $score += min(30, count(self::SUPPORTED_INTEREST_TYPES) * 3);

        $grade = match (true) {
            $score >= 80 => 'A',
            $score >= 60 => 'B',
            $score >= 40 => 'C',
            $score >= 20 => 'D',
            default => 'F',
        };

        return [
            'score' => min(100, $score),
            'grade' => $grade,
            'dimensions' => $dimensions,
        ];
    }

    /**
     * Get all supported preference types.
     *
     * @return list<string>
     */
    public function getSupportedPreferenceTypes(): array
    {
        return self::SUPPORTED_PREFERENCE_TYPES;
    }

    /**
     * Get all supported interest types.
     *
     * @return list<string>
     */
    public function getSupportedInterestTypes(): array
    {
        return self::SUPPORTED_INTEREST_TYPES;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
