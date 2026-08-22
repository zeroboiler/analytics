<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * SaaS onboarding funnel stage tracker.
 *
 * Defines and tracks the standard SaaS onboarding funnel stages:
 * Signup → Email Verified → First Value → Trial Start → Subscription
 *
 * Provides stage-level conversion rates, drop-off detection, and
 * cohort-based funnel analysis. Used by admin dashboards and
 * product analytics to optimize the user onboarding experience.
 *
 * Features:
 * - 5 standard onboarding stages with configurable definitions
 * - Stage completion tracking and conversion rates
 * - Drop-off detection between stages
 * - Cache-backed stage aggregation
 * - Integration with EventCatalog for stage event validation
 *
 * @since 139.0.0
 */
final class SaaSOnboardingFunnelTracker
{
    /**
     * Standard SaaS onboarding funnel stages.
     *
     * Each stage maps to a catalog event name and has an optional
     * successor stage for funnel progression tracking.
     *
     * @var array<string, array{name: string, event: string, description: string, category: string, successor: string|null}>
     */
    public const STAGES = [
        'signup' => [
            'name' => 'Sign Up',
            'event' => 'sign_up',
            'description' => 'User creates an account',
            'category' => 'saas',
            'successor' => 'email_verified',
        ],
        'email_verified' => [
            'name' => 'Email Verified',
            'event' => 'email_verified',
            'description' => 'User verifies their email address',
            'category' => 'saas',
            'successor' => 'first_value',
        ],
        'first_value' => [
            'name' => 'First Value',
            'event' => 'first_value',
            'description' => 'User experiences the product value (aha moment)',
            'category' => 'saas',
            'successor' => 'trial_start',
        ],
        'trial_start' => [
            'name' => 'Trial Start',
            'event' => 'start_trial',
            'description' => 'User starts a paid trial',
            'category' => 'saas',
            'successor' => 'subscription',
        ],
        'subscription' => [
            'name' => 'Subscription',
            'event' => 'subscribe',
            'description' => 'User converts to a paid subscription',
            'category' => 'saas',
            'successor' => null,
        ],
    ];

    /**
     * Custom stage overrides from config.
     *
     * @var array<string, array{name: string, event: string, description: string, category: string, successor: string|null}>
     */
    private array $customStages;

    private CacheRepository $cache;

    private int $cacheTtl;

    /**
     * @param  ConfigRepository  $config  Application config
     * @param  CacheRepository  $cache  Cache repository
     */
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly AnalyticsManager $manager,
        CacheRepository $cache,
    ){
        $onboardingConfig = $config->get('zeroboiler.analytics.onboarding_funnel', []);
        /** @var array{custom_stages?: array<string, array{name: string, event: string, description: string, successor?: string|null}>, cache_ttl?: int} $onboardingConfig */
        $this->customStages = $onboardingConfig['custom_stages'] ?? [];
        $this->cacheTtl = (int) ($onboardingConfig['cache_ttl'] ?? 3600);
        $this->cache = $cache;
    }

    /**
     * Get all funnel stages (standard + custom overrides).
     *
     * @return array<string, array{name: string, event: string, description: string, category: string, successor: string|null}>
     */
    public function getStages(): array
    {
        return array_merge(self::STAGES, $this->customStages);
    }

    /**
     * Get the ordered list of stage keys in funnel sequence.
     *
     * @return list<string>
     */
    public function getStageSequence(): array
    {
        $stages = $this->getStages();
        $sequence = [];
        $current = 'signup';

        while ($current !== null && isset($stages[$current])) {
            $sequence[] = $current;
            $current = $stages[$current]['successor'];
        }

        return $sequence;
    }

    /**
     * Get a specific stage by key.
     *
     * @return array{name: string, event: string, description: string, category: string, successor: string|null}|null
     */
    public function getStage(string $key): ?array
    {
        return $this->getStages()[$key] ?? null;
    }

    /**
     * Validate that all stage events exist in the EventCatalog.
     *
     * @return array{valid: bool, missing: list<string>, total: int, matched: int}
     */
    public function validateStageEvents(): array
    {
        $stages = $this->getStages();
        $missing = [];
        $matched = 0;

        foreach ($stages as $key => $stage) {
            $eventName = $stage['event'];

            if (EventCatalog::has($eventName)) {
                $matched++;
            } else {
                $missing[] = "{$key} ({$eventName})";
            }
        }

        return [
            'valid' => count($missing) === 0,
            'missing' => $missing,
            'total' => count($stages),
            'matched' => $matched,
        ];
    }

    /**
     * Calculate theoretical conversion rates for the funnel.
     *
     * Given event volumes per stage, computes stage-to-stage conversion.
     *
     * @param  array<string, int>  $stageVolumes  Stage key → event count
     * @return array{stages: array<string, array{volume: int, conversion_from_previous: float|null, conversion_to_subscription: float|null, drop_off_rate: float|null}>}
     */
    public function calculateConversionRates(array $stageVolumes): array
    {
        $sequence = $this->getStageSequence();
        $totalStages = count($sequence);
        $subscriptionVolume = $stageVolumes['subscription'] ?? 0;
        $result = [];

        foreach ($sequence as $index => $stageKey) {
            $volume = $stageVolumes[$stageKey] ?? 0;
            $prevVolume = $index > 0
                ? ($stageVolumes[$sequence[$index - 1]] ?? 0)
                : null;
            $nextVolume = $index < $totalStages - 1
                ? ($stageVolumes[$sequence[$index + 1]] ?? 0)
                : null;

            $result[$stageKey] = [
                'volume' => $volume,
                'conversion_from_previous' => $prevVolume !== null && $prevVolume > 0
                    ? round(($volume / $prevVolume) * 100, 2)
                    : null,
                'conversion_to_subscription' => $subscriptionVolume > 0
                    ? round(($volume > 0 ? min($subscriptionVolume / $volume, 1.0) : 0.0) * 100, 2)
                    : null,
                'drop_off_rate' => $prevVolume !== null && $prevVolume > 0
                    ? round(((1 - ($volume / $prevVolume)) * 100), 2)
                    : null,
            ];
        }

        return ['stages' => $result];
    }

    /**
     * Detect the biggest drop-off point in the funnel.
     *
     * @param  array<string, int>  $stageVolumes  Stage key → event count
     * @return array{stage: string|null, name: string|null, drop_off_rate: float, volume_before: int, volume_after: int}
     */
    public function detectBiggestDropOff(array $stageVolumes): array
    {
        $sequence = $this->getStageSequence();
        $stages = $this->getStages();
        $biggest = [
            'stage' => null,
            'name' => null,
            'drop_off_rate' => 0.0,
            'volume_before' => 0,
            'volume_after' => 0,
        ];

        for ($i = 1; $i < count($sequence); $i++) {
            $prevStage = $sequence[$i - 1];
            $currentStage = $sequence[$i];
            $prevVolume = $stageVolumes[$prevStage] ?? 0;
            $currentVolume = $stageVolumes[$currentStage] ?? 0;

            if ($prevVolume > 0) {
                $dropOff = (1 - ($currentVolume / $prevVolume)) * 100;

                if ($dropOff > $biggest['drop_off_rate']) {
                    $biggest = [
                        'stage' => $currentStage,
                        'name' => $stages[$currentStage]['name'],
                        'drop_off_rate' => round($dropOff, 2),
                        'volume_before' => $prevVolume,
                        'volume_after' => $currentVolume,
                    ];
                }
            }
        }

        return $biggest;
    }

    /**
     * Get the overall funnel completion rate (signup → subscription).
     *
     * @param  array<string, int>  $stageVolumes  Stage key → event count
     */
    public function overallConversionRate(array $stageVolumes): float
    {
        $signupVolume = $stageVolumes['signup'] ?? 0;
        $subscriptionVolume = $stageVolumes['subscription'] ?? 0;

        if ($signupVolume === 0) {
            return 0.0;
        }

        return round(($subscriptionVolume / $signupVolume) * 100, 2);
    }

    /**
     * Get funnel summary for dashboard display.
     *
     * @param  array<string, int>  $stageVolumes  Stage key → event count
     * @return array{stages: list<array{key: string, name: string, event: string, volume: int, conversion_rate: float|null, drop_off: float|null}>, overall_rate: float, biggest_drop_off: array{stage: string|null, name: string|null, drop_off_rate: float}, total_stages: int, stage_sequence: list<string>}
     */
    public function getFunnelSummary(array $stageVolumes): array
    {
        $stages = $this->getStages();
        $sequence = $this->getStageSequence();
        $stageEntries = [];

        foreach ($sequence as $index => $stageKey) {
            $stage = $stages[$stageKey];
            $volume = $stageVolumes[$stageKey] ?? 0;
            $prevVolume = $index > 0
                ? ($stageVolumes[$sequence[$index - 1]] ?? 0)
                : null;

            $stageEntries[] = [
                'key' => $stageKey,
                'name' => $stage['name'],
                'event' => $stage['event'],
                'volume' => $volume,
                'conversion_rate' => $prevVolume !== null && $prevVolume > 0
                    ? round(($volume / $prevVolume) * 100, 2)
                    : null,
                'drop_off' => $prevVolume !== null && $prevVolume > 0
                    ? round(((1 - ($volume / $prevVolume)) * 100), 2)
                    : null,
            ];
        }

        $dropOff = $this->detectBiggestDropOff($stageVolumes);

        return [
            'stages' => $stageEntries,
            'overall_rate' => $this->overallConversionRate($stageVolumes),
            'biggest_drop_off' => $dropOff,
            'total_stages' => count($sequence),
            'stage_sequence' => $sequence,
        ];
    }

    /**
     * Get the number of standard stages.
     */
    public function stageCount(): int
    {
        return count($this->getStages());
    }

    /**
     * Check if a stage key exists.
     */
    public function hasStage(string $key): bool
    {
        return isset($this->getStages()[$key]);
    }

    /**
     * Get the first stage in the funnel.
     *
     * @return array{name: string, event: string, description: string, category: string, successor: string|null}|null
     */
    public function firstStage(): ?array
    {
        $sequence = $this->getStageSequence();

        return $sequence !== [] ? $this->getStage($sequence[0]) : null;
    }

    /**
     * Get the last stage in the funnel.
     *
     * @return array{name: string, event: string, description: string, category: string, successor: string|null}|null
     */
    public function lastStage(): ?array
    {
        $sequence = $this->getStageSequence();

        return $sequence !== [] ? $this->getStage($sequence[count($sequence) - 1]) : null;
    }
}
