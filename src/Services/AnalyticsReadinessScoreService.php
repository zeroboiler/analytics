<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Comprehensive analytics readiness score for SaaS startups.
 *
 * Computes a 0-100 readiness score across 8 dimensions:
 * 1. Provider configuration (at least 1 provider enabled)
 * 2. Event catalog coverage (critical SaaS events tracked)
 * 3. Identity tracking (client ↔ user linking)
 * 4. Consent compliance (GDPR consent mode)
 * 5. Queue infrastructure (async event dispatch)
 * 6. E-commerce tracking (purchase/refund items)
 * 7. SaaS lifecycle tracking (trial/subscription events)
 * 8. Client-side integration (JS client + auto-tracking)
 *
 * Returns actionable recommendations for each dimension.
 *
 * @since 9.2.0
 */
final class AnalyticsReadinessScoreService
{
    /** @var array<string, array{label: string, max: int, checks: array<string, array{condition: callable(ConfigRepository): bool, weight: int, message: string}>}> */
    private const DIMENSIONS = [
        'providers' => [
            'label' => 'Provider Configuration',
            'max' => 15,
            'checks' => [
                'ga4_enabled' => [
                    'weight' => 8,
                    'message' => 'Enable GA4 Measurement Protocol for server-side tracking',
                ],
                'any_provider' => [
                    'weight' => 7,
                    'message' => 'Enable at least one analytics provider (GA4, GTM, Meta, Plausible, or PostHog)',
                ],
            ],
        ],
        'catalog' => [
            'label' => 'Event Catalog Coverage',
            'max' => 15,
            'checks' => [
                'core_saas' => [
                    'weight' => 8,
                    'message' => 'Implement core SaaS events: sign_up, login, trial_start, subscription, plan_upgrade',
                ],
                'engagement' => [
                    'weight' => 4,
                    'message' => 'Track engagement events: page_view, form_submit, error',
                ],
                'revenue' => [
                    'weight' => 3,
                    'message' => 'Track revenue events: purchase, refund, revenue_tracked',
                ],
            ],
        ],
        'identity' => [
            'label' => 'Identity Tracking',
            'max' => 10,
            'checks' => [
                'cookie' => [
                    'weight' => 5,
                    'message' => 'Enable client ID cookie tracking (zb_analytics_id)',
                ],
                'link_on_auth' => [
                    'weight' => 5,
                    'message' => 'Enable auto-identity linking on authentication',
                ],
            ],
        ],
        'consent' => [
            'label' => 'Consent Compliance',
            'max' => 10,
            'checks' => [
                'gdpr_default' => [
                    'weight' => 5,
                    'message' => 'Set consent default to "denied" for GDPR compliance',
                ],
                'purposes' => [
                    'weight' => 5,
                    'message' => 'Define granular consent purposes (analytics, marketing, functional)',
                ],
            ],
        ],
        'queue' => [
            'label' => 'Queue Infrastructure',
            'max' => 15,
            'checks' => [
                'enabled' => [
                    'weight' => 10,
                    'message' => 'Enable async queue dispatch for analytics events',
                ],
                'connection' => [
                    'weight' => 5,
                    'message' => 'Configure a dedicated queue connection (Redis recommended)',
                ],
            ],
        ],
        'ecommerce' => [
            'label' => 'E-commerce Tracking',
            'max' => 10,
            'checks' => [
                'currency' => [
                    'weight' => 5,
                    'message' => 'Set default currency for e-commerce events',
                ],
                'format_converter' => [
                    'weight' => 5,
                    'message' => 'Use EcommerceFormatConverter for cross-provider compatibility',
                ],
            ],
        ],
        'saas_lifecycle' => [
            'label' => 'SaaS Lifecycle Tracking',
            'max' => 15,
            'checks' => [
                'auto_track' => [
                    'weight' => 8,
                    'message' => 'Enable auto-tracking for auth and subscription lifecycle events',
                ],
                'lifecycle_mapper' => [
                    'weight' => 7,
                    'message' => 'Configure LifecycleEventMapper for config-driven event mapping',
                ],
            ],
        ],
        'client' => [
            'label' => 'Client-Side Integration',
            'max' => 10,
            'checks' => [
                'api_enabled' => [
                    'weight' => 5,
                    'message' => 'Enable the analytics API endpoints for JS client events',
                ],
                'inertia' => [
                    'weight' => 5,
                    'message' => 'Register the Inertia analytics middleware for prop injection',
                ],
            ],
        ],
    ];

    private const TOTAL_MAX = 100;

    private ConfigRepository $config;

    public function __construct(ConfigRepository $config): void
    {
        $this->config = $config;
    }

    /**
     * Compute the full readiness score with per-dimension breakdown.
     *
     * @return array{score: int, grade: string, dimensions: array<string, array{label: string, score: int, max: int, pct: float, gaps: list<string>}>, recommendations: list<array{dimension: string, priority: string, action: string}>}
     */
    public function compute(): array
    {
        $dimensionResults = [];
        $totalScore = 0;
        $totalMax = 0;
        $recommendations = [];

        foreach (self::DIMENSIONS as $key => $dimension) {
            $result = $this->evaluateDimension($key, $dimension);
            $dimensionResults[$key] = $result;
            $totalScore += $result['score'];
            $totalMax += $result['max'];

            // Collect recommendations for gaps
            foreach ($result['gaps'] as $gap) {
                $recommendations[] = [
                    'dimension' => $dimension['label'],
                    'priority' => $this->gapPriority($key, count($result['gaps'])),
                    'action' => $gap,
                ];
            }
        }

        // Normalize to 0-100 scale
        $normalizedScore = $totalMax > 0
            ? (int) round(($totalScore / $totalMax) * self::TOTAL_MAX)
            : 0;

        // Sort recommendations by priority
        usort($recommendations, function (array $a, array $b): int {
            $priorityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            $aPriority = $priorityOrder[$a['priority']] ?? 3;
            $bPriority = $priorityOrder[$b['priority']] ?? 3;

            return $aPriority <=> $bPriority;
        });

        return [
            'score' => $normalizedScore,
            'grade' => $this->scoreToGrade($normalizedScore),
            'dimensions' => $dimensionResults,
            'recommendations' => $recommendations,
            'computed_at' => time(),
        ];
    }

    /**
     * Quick readiness check — returns true if score >= 60.
     */
    public function isReady(): bool
    {
        return $this->compute()['score'] >= 60;
    }

    /**
     * Get only the readiness score without detailed breakdown.
     */
    public function score(): int
    {
        return $this->compute()['score'];
    }

    /**
     * Evaluate a single dimension.
     *
     * @param  string  $key
     * @param  array{label: string, max: int, checks: array<string, array{condition: callable(ConfigRepository): bool, weight: int, message: string}>}  $dimension
     * @return array{label: string, score: int, max: int, pct: float, gaps: list<string>}
     */
    private function evaluateDimension(string $key, array $dimension): array
    {
        $score = 0;
        $maxScore = 0;
        $gaps = [];

        foreach ($dimension['checks'] as $checkKey => $check) {
            $weight = $check['weight'];
            $maxScore += $weight;

            if ($this->runCheck($key, $checkKey)) {
                $score += $weight;
            } else {
                $gaps[] = $check['message'];
            }
        }

        $pct = $maxScore > 0 ? round(($score / $maxScore) * 100, 1) : 0.0;

        return [
            'label' => $dimension['label'],
            'score' => $score,
            'max' => $maxScore,
            'pct' => $pct,
            'gaps' => $gaps,
        ];
    }

    /**
     * Run a specific dimension check.
     */
    private function runCheck(string $dimension, string $check): bool
    {
        return match ("{$dimension}.{$check}") {
            // Providers
            'providers.ga4_enabled' => (bool) $this->config->get('zeroboiler.analytics.ga4.enabled', false),
            'providers.any_provider' => $this->isAnyProviderEnabled(),

            // Catalog (we can't check runtime tracking, so we check config hints)
            'catalog.core_saas' => $this->isAutoTrackCoreEnabled(),
            'catalog.engagement' => $this->isAutoTrackEngagementEnabled(),
            'catalog.revenue' => $this->isAutoTrackRevenueEnabled(),

            // Identity
            'identity.cookie' => $this->config->get('zeroboiler.analytics.identity.cookie_name') !== null,
            'identity.link_on_auth' => (bool) $this->config->get('zeroboiler.analytics.identity.link_on_auth', true),

            // Consent
            'consent.gdpr_default' => $this->config->get('zeroboiler.analytics.consent.default') === 'denied',
            'consent.purposes' => count($this->config->get('zeroboiler.analytics.consent.purposes', [])) >= 3,

            // Queue
            'queue.enabled' => (bool) $this->config->get('zeroboiler.analytics.queue.enabled', true),
            'queue.connection' => $this->config->get('zeroboiler.analytics.queue.connection') !== null,

            // Ecommerce
            'ecommerce.currency' => (bool) $this->config->get('zeroboiler.analytics.ecommerce.currency'),
            'ecommerce.format_converter' => true, // Always available (EcommerceFormatConverter)

            // SaaS Lifecycle
            'saas_lifecycle.auto_track' => (bool) $this->config->get('zeroboiler.analytics.auto_track.enabled', true),
            'saas_lifecycle.lifecycle_mapper' => (bool) $this->config->get('zeroboiler.analytics.lifecycle.enabled', true),

            // Client
            'client.api_enabled' => (bool) $this->config->get('zeroboiler.analytics.api.enabled', true),
            'client.inertia' => true, // Inertia middleware is always available

            default => false,
        };
    }

    /**
     * Check if any analytics provider is enabled.
     */
    private function isAnyProviderEnabled(): bool
    {
        return (bool) $this->config->get('zeroboiler.analytics.ga4.enabled', false)
            || (bool) $this->config->get('zeroboiler.analytics.gtm.enabled', false)
            || (bool) $this->config->get('zeroboiler.analytics.meta_pixel.enabled', false)
            || (bool) $this->config->get('zeroboiler.analytics.plausible.enabled', false)
            || (bool) $this->config->get('zeroboiler.analytics.posthog.enabled', false)
            || (bool) $this->config->get('zeroboiler.analytics.webhook.enabled', false);
    }

    /**
     * Check if core SaaS auto-tracking events are enabled.
     */
    private function isAutoTrackCoreEnabled(): bool
    {
        $events = $this->config->get('zeroboiler.analytics.auto_track.events', []);

        return (bool) ($events['auth.login'] ?? false)
            || (bool) ($events['auth.register'] ?? false)
            || (bool) ($events['subscription.created'] ?? false)
            || (bool) ($events['trial.started'] ?? false);
    }

    /**
     * Check if engagement auto-tracking is enabled.
     */
    private function isAutoTrackEngagementEnabled(): bool
    {
        $events = $this->config->get('zeroboiler.analytics.auto_track.events', []);

        return (bool) ($events['feature.used'] ?? false);
    }

    /**
     * Check if revenue auto-tracking is enabled.
     */
    private function isAutoTrackRevenueEnabled(): bool
    {
        $events = $this->config->get('zeroboiler.analytics.auto_track.events', []);

        return (bool) ($events['subscription.created'] ?? false)
            || (bool) ($events['subscription.upgraded'] ?? false)
            || (bool) ($events['subscription.cancelled'] ?? false);
    }

    /**
     * Convert a score to a grade.
     */
    private function scoreToGrade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A+',
            $score >= 80 => 'A',
            $score >= 70 => 'B+',
            $score >= 60 => 'B',
            $score >= 50 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };
    }

    /**
     * Determine gap priority based on dimension and gap count.
     */
    private function gapPriority(string $dimension, int $gapCount): string
    {
        $criticalDimensions = ['providers', 'identity', 'consent', 'queue'];

        if (in_array($dimension, $criticalDimensions, true) && $gapCount >= 2) {
            return 'critical';
        }

        if (in_array($dimension, $criticalDimensions, true)) {
            return 'high';
        }

        if ($gapCount >= 2) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Get the dimension definitions (for testing and documentation).
     *
     * @return array<string, array{label: string, max: int}>
     */
    public static function dimensionDefinitions(): array
    {
        $result = [];

        foreach (self::DIMENSIONS as $key => $dimension) {
            $result[$key] = [
                'label' => $dimension['label'],
                'max' => $dimension['max'],
            ];
        }

        return $result;
    }
}
