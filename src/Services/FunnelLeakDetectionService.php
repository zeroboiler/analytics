<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Funnel Leak Detection Service — Automated Conversion Funnel Analysis.
 *
 * Analyzes multi-step funnels to identify significant drop-off points
 * (leaks) and provides actionable recommendations for improvement.
 *
 * A "leak" is detected when the conversion rate between two consecutive
 * funnel steps falls below a configurable threshold. The service computes
 * step-level conversion rates, identifies the biggest leak, and generates
 * prioritized recommendations.
 *
 * Built-in funnel definitions:
 * - signup_funnel: landing_page → signup_form → email_verify → first_value
 * - purchase_funnel: view_item → add_to_cart → begin_checkout → purchase
 * - trial_funnel: sign_up → trial_start → first_value → subscription
 * - activation_funnel: sign_up → email_verified → first_action → second_action → feature_adopted
 * - retention_funnel: day_1_login → day_3_login → day_7_login → day_14_login
 *
 * All funnel data is stored in cache and can be recorded via the
 * `recordProgress()` method or the `Analytics::trackFunnel()` facade method.
 *
 * @since 148.0.0
 */
final class FunnelLeakDetectionService
{
    private const CACHE_PREFIX = 'zb_funnel_leak_';

    private const DEFAULT_LEAK_THRESHOLD = 0.40; // 40% drop-off = leak

    private const DEFAULT_CRITICAL_THRESHOLD = 0.70; // 70% drop-off = critical

    /** @var array<string, array{steps: list<string>, leak_threshold: float, critical_threshold: float}> */
    private array $funnelDefinitions;

    private CacheRepository $cache;

    private int $cacheTtl;

    private float $leakThreshold;

    private float $criticalThreshold;

    private bool $enabled;

    /**
     * @param  array{enabled?: bool, cache_ttl?: int, leak_threshold?: float, critical_threshold?: float, custom_funnels?: array<string, array{steps: list<string>, leak_threshold?: float}>}  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $configRepo, array $config = []){
        $fullConfig = $configRepo->get('zeroboiler.analytics.funnel_leak_detection', []);
        $merged = array_merge($fullConfig, $config);

        $this->cache = $cache;
        $this->enabled = (bool) ($merged['enabled'] ?? false);
        $this->cacheTtl = (int) ($merged['cache_ttl'] ?? 86400);
        $this->leakThreshold = (float) ($merged['leak_threshold'] ?? self::DEFAULT_LEAK_THRESHOLD);
        $this->criticalThreshold = (float) ($merged['critical_threshold'] ?? self::DEFAULT_CRITICAL_THRESHOLD);

        $this->funnelDefinitions = $this->buildFunnelDefinitions($merged['custom_funnels'] ?? []);
    }

    /**
     * Record a user's progress through a funnel step.
     *
     * Tracks how many unique users reach each step. Used to build
     * the conversion funnel data for leak detection.
     */
    public function recordProgress(string $funnelName, string $stepName, string $identity): void
    {
        if (! $this->enabled) {
            return;
        }

        $funnel = $this->funnelDefinitions[$funnelName] ?? null;

        if ($funnel === null) {
            return;
        }

        // Only track steps defined in the funnel
        if (! in_array($stepName, $funnel['steps'], true)) {
            return;
        }

        $cacheKey = self::CACHE_PREFIX . $funnelName;
        $data = $this->cache->get($cacheKey, []);

        /** @var array<string, array<string, bool>> $data */
        if (! isset($data[$stepName])) {
            $data[$stepName] = [];
        }

        $data[$stepName][$identity] = true;

        $this->cache->put($cacheKey, $data, $this->cacheTtl);
    }

    /**
     * Analyze a funnel for leaks.
     *
     * Returns step-by-step conversion rates, leak detection results,
     * and actionable recommendations.
     *
     * @return array{funnel: string, steps: list<array{name: string, users: int, conversion_rate: float, drop_off: float, is_leak: bool, severity: string|null}>, overall_conversion: float, biggest_leak: array{name: string, drop_off: float, severity: string}|null, recommendations: list<array{priority: string, step: string, message: string, action: string}>}
     */
    public function analyze(string $funnelName): array
    {
        $funnel = $this->funnelDefinitions[$funnelName] ?? null;

        if ($funnel === null) {
            return $this->emptyAnalysis($funnelName);
        }

        $cacheKey = self::CACHE_PREFIX . $funnelName;
        $data = $this->cache->get($cacheKey, []);

        /** @var array<string, array<string, bool>> $data */
        if ($data === []) {
            return $this->emptyAnalysis($funnelName);
        }

        $steps = $funnel['steps'];
        $stepAnalysis = [];
        $biggestLeak = null;
        $recommendations = [];

        foreach ($steps as $i => $stepName) {
            $userCount = isset($data[$stepName]) ? count($data[$stepName]) : 0;

            if ($i === 0) {
                $stepAnalysis[] = [
                    'name' => $stepName,
                    'users' => $userCount,
                    'conversion_rate' => $userCount > 0 ? 100.0 : 0.0,
                    'drop_off' => 0.0,
                    'is_leak' => false,
                    'severity' => null,
                ];

                continue;
            }

            $prevStep = $steps[$i - 1];
            $prevCount = isset($data[$prevStep]) ? count($data[$prevStep]) : 0;
            $conversionRate = $prevCount > 0
                ? round(($userCount / $prevCount) * 100, 2)
                : 0.0;
            $dropOff = $prevCount > 0
                ? round(1 - ($userCount / $prevCount), 4)
                : 0.0;

            $isLeak = $dropOff >= $this->leakThreshold;
            $severity = null;

            if ($dropOff >= $this->criticalThreshold) {
                $severity = 'critical';
            } elseif ($isLeak) {
                $severity = 'warning';
            }

            $stepAnalysis[] = [
                'name' => $stepName,
                'users' => $userCount,
                'conversion_rate' => $conversionRate,
                'drop_off' => $dropOff,
                'is_leak' => $isLeak,
                'severity' => $severity,
            ];

            // Track biggest leak
            if ($isLeak && ($biggestLeak === null || $dropOff > $biggestLeak['drop_off'])) {
                $biggestLeak = [
                    'name' => $stepName,
                    'drop_off' => $dropOff,
                    'severity' => $severity ?? 'warning',
                ];
            }

            if ($isLeak) {
                $recommendations[] = $this->generateRecommendation($funnelName, $prevStep, $stepName, $dropOff, $severity ?? 'warning');
            }
        }

        usort($recommendations, fn (array $a, array $b): int => $a['priority'] === 'critical' ? -1 : 1);

        // Overall conversion (first step → last step)
        $firstStep = $steps[0];
        $lastStep = $steps[array_key_last($steps)];
        $firstCount = isset($data[$firstStep]) ? count($data[$firstStep]) : 0;
        $lastCount = isset($data[$lastStep]) ? count($data[$lastStep]) : 0;
        $overallConversion = $firstCount > 0
            ? round(($lastCount / $firstCount) * 100, 2)
            : 0.0;

        return [
            'funnel' => $funnelName,
            'steps' => $stepAnalysis,
            'overall_conversion' => $overallConversion,
            'biggest_leak' => $biggestLeak,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Analyze all defined funnels and return a summary.
     *
     * @return array<string, array{funnel: string, overall_conversion: float, leak_count: int, biggest_leak: string|null}>
     */
    public function analyzeAll(): array
    {
        $results = [];

        foreach (array_keys($this->funnelDefinitions) as $funnelName) {
            $analysis = $this->analyze($funnelName);
            $leakCount = count(array_filter($analysis['steps'], fn (array $s): bool => $s['is_leak']));

            $results[$funnelName] = [
                'funnel' => $funnelName,
                'overall_conversion' => $analysis['overall_conversion'],
                'leak_count' => $leakCount,
                'biggest_leak' => $analysis['biggest_leak']['name'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * Get all registered funnel definitions.
     *
     * @return array<string, array{steps: list<string>, leak_threshold: float, critical_threshold: float}>
     */
    public function getFunnels(): array
    {
        return $this->funnelDefinitions;
    }

    /**
     * Register a custom funnel definition at runtime.
     *
     * @param  list<string>  $steps
     */
    public function registerFunnel(string $name, array $steps, ?float $leakThreshold = null): void
    {
        $this->funnelDefinitions[$name] = [
            'steps' => $steps,
            'leak_threshold' => $leakThreshold ?? $this->leakThreshold,
            'critical_threshold' => $this->criticalThreshold,
        ];
    }

    /**
     * Clear all funnel progress data for a specific funnel (or all funnels).
     *
     * @return list<string> Cache keys cleared
     */
    public function clear(?string $funnelName = null): array
    {
        $cleared = [];

        if ($funnelName !== null) {
            $key = self::CACHE_PREFIX . $funnelName;
            if ($this->cache->forget($key)) {
                $cleared[] = $key;
            }
        } else {
            foreach (array_keys($this->funnelDefinitions) as $name) {
                $key = self::CACHE_PREFIX . $name;
                if ($this->cache->forget($key)) {
                    $cleared[] = $key;
                }
            }
        }

        return $cleared;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Build built-in funnel definitions merged with custom ones.
     *
     * @param  array<string, array{steps: list<string>, leak_threshold?: float}>  $customFunnels
     * @return array<string, array{steps: list<string>, leak_threshold: float, critical_threshold: float}>
     */
    private function buildFunnelDefinitions(array $customFunnels): array
    {
        $builtIn = [
            'signup_funnel' => [
                'steps' => ['landing_page', 'signup_form', 'email_verify', 'first_value'],
                'leak_threshold' => $this->leakThreshold,
                'critical_threshold' => $this->criticalThreshold,
            ],
            'purchase_funnel' => [
                'steps' => ['view_item', 'add_to_cart', 'begin_checkout', 'purchase'],
                'leak_threshold' => $this->leakThreshold,
                'critical_threshold' => $this->criticalThreshold,
            ],
            'trial_funnel' => [
                'steps' => ['sign_up', 'trial_start', 'first_value', 'subscription'],
                'leak_threshold' => $this->leakThreshold,
                'critical_threshold' => $this->criticalThreshold,
            ],
            'activation_funnel' => [
                'steps' => ['sign_up', 'email_verified', 'first_action', 'second_action', 'feature_adopted'],
                'leak_threshold' => $this->leakThreshold,
                'critical_threshold' => $this->criticalThreshold,
            ],
            'retention_funnel' => [
                'steps' => ['day_1_login', 'day_3_login', 'day_7_login', 'day_14_login'],
                'leak_threshold' => $this->leakThreshold,
                'critical_threshold' => $this->criticalThreshold,
            ],
        ];

        foreach ($customFunnels as $name => $def) {
            $builtIn[$name] = [
                'steps' => $def['steps'],
                'leak_threshold' => $def['leak_threshold'] ?? $this->leakThreshold,
                'critical_threshold' => $this->criticalThreshold,
            ];
        }

        return $builtIn;
    }

    /**
     * Generate a recommendation for a detected leak.
     *
     * @return array{priority: string, step: string, message: string, action: string}
     */
    private function generateRecommendation(
        string $funnelName,
        string $fromStep,
        string $toStep,
        float $dropOff,
        string $severity,
    ): array {
        $dropPercent = round($dropOff * 100, 1);

        $message = "{$dropPercent}% of users drop between '{$fromStep}' and '{$toStep}' in the {$funnelName} funnel.";

        $action = $this->suggestAction($funnelName, $fromStep, $toStep, $severity);

        return [
            'priority' => $severity,
            'step' => $toStep,
            'message' => $message,
            'action' => $action,
        ];
    }

    /**
     * Suggest an actionable fix based on funnel context.
     */
    private function suggestAction(string $funnelName, string $fromStep, string $toStep, string $severity): string
    {
        $suggestions = [
            'signup_funnel' => [
                'landing_page' => [
                    'signup_form' => 'Simplify the signup form, reduce required fields, or add social login.',
                    'email_verify' => 'Improve email copy, add resend button, or use magic links.',
                ],
                'signup_form' => [
                    'email_verify' => 'Check email deliverability, simplify verification flow, add countdown timer.',
                    'first_value' => 'Add onboarding wizard or guided tour after email verification.',
                ],
            ],
            'purchase_funnel' => [
                'view_item' => [
                    'add_to_cart' => 'Improve product page CTAs, add urgency elements, or offer free shipping.',
                ],
                'add_to_cart' => [
                    'begin_checkout' => 'Add cart reminder emails, simplify checkout entry point, show cart summary.',
                ],
                'begin_checkout' => [
                    'purchase' => 'Reduce form fields, add guest checkout, offer multiple payment methods.',
                ],
            ],
            'trial_funnel' => [
                'sign_up' => [
                    'trial_start' => 'Auto-start trial or make trial activation more prominent after signup.',
                ],
                'trial_start' => [
                    'first_value' => 'Improve onboarding experience, add interactive tutorial, or template gallery.',
                ],
                'first_value' => [
                    'subscription' => 'Add in-app upgrade prompts, email nurturing, or trial expiration reminders.',
                ],
            ],
        ];

        return $suggestions[$funnelName][$fromStep][$toStep]
            ?? "Investigate UX friction between '{$fromStep}' and '{$toStep}'. Run user testing and session recordings to identify blockers.";
    }

    /**
     * Return an empty analysis result for unknown/empty funnels.
     *
     * @return array{funnel: string, steps: list<array{name: string, users: int, conversion_rate: float, drop_off: float, is_leak: bool, severity: string|null}>, overall_conversion: float, biggest_leak: null, recommendations: list<empty>}
     */
    private function emptyAnalysis(string $funnelName): array
    {
        return [
            'funnel' => $funnelName,
            'steps' => [],
            'overall_conversion' => 0.0,
            'biggest_leak' => null,
            'recommendations' => [],
        ];
    }
}
