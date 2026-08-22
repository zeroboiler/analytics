<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Onboarding Wizard Service for SaaS analytics instrumentation.
 *
 * Provides a step-by-step guided onboarding experience for new SaaS
 * deployments. Tracks instrumentation progress, recommends events by
 * priority, validates configuration completeness, and generates
 * actionable next-step recommendations.
 *
 * Designed for admin dashboards and CLI commands to guide developers
 * through analytics setup from "hello world" to production-grade.
 *
 * @phpstan-type WizardStep array{key: string, label: string, description: string, events: list<string>, required: bool, estimated_minutes: int}
 * @phpstan-type WizardProgress array{step: string, completed: bool, events_instrumented: list<string>, skipped: bool}
 * @phpstan-type WizardState array{started_at: string|null, current_step: string|null, completed_steps: list<string>, total_events_instrumented: int, completion_percentage: float, grade: string}
 *
 * @since 1.0.0
 */
final class OnboardingWizardService
{
    private const CACHE_PREFIX = 'zb_onboarding_wizard_';

    private const CACHE_TTL = 86400; // 24 hours

    private ConfigRepository $config;

    public function __construct(ConfigRepository $config){
        $this->config = $config;
    }

    /**
     * Get the full onboarding wizard state for a given app identifier.
     *
     * Returns the current progress, completion percentage, grade,
     * and recommended next steps.
     *
     * @param  string  $appId  Application identifier (defaults to 'default')
     * @return WizardState
     */
    public function getState(string $appId = 'default'): array
    {
        $cacheKey = $this->cacheKey($appId);
        $cached = $this->config->get('cache.default');

        $cachedState = $this->readCache($cacheKey);
        if ($cachedState !== null) {
            return $cachedState;
        }

        return $this->buildInitialState();
    }

    /**
     * Get all wizard steps in order.
     *
     * Returns 6 steps covering the full SaaS analytics onboarding journey:
     * 1. Core Setup (provider config, consent)
     * 2. Acquisition Tracking (signup, trial)
     * 3. Activation Tracking (feature usage, onboarding)
     * 4. Revenue Tracking (subscription, billing)
     * 5. Retention Tracking (engagement, session)
     * 6. Growth Tracking (referral, expansion)
     *
     * @return list<WizardStep>
     */
    public function getSteps(): array
    {
        return [
            [
                'key' => 'core_setup',
                'label' => 'Core Setup',
                'description' => 'Configure analytics providers (GA4, Meta, PostHog) and consent mode. This is the foundation — without it, no events are dispatched.',
                'events' => ['page_view', 'error'],
                'required' => true,
                'estimated_minutes' => 10,
            ],
            [
                'key' => 'acquisition',
                'label' => 'Acquisition Tracking',
                'description' => 'Track how users find and enter your product: sign-ups, trial starts, and first-touch attribution.',
                'events' => ['sign_up', 'login', 'start_trial', 'email_verified'],
                'required' => true,
                'estimated_minutes' => 15,
            ],
            [
                'key' => 'activation',
                'label' => 'Activation Tracking',
                'description' => 'Track whether users reach the "aha moment": feature usage, onboarding steps, and first meaningful action.',
                'events' => ['feature_used', 'onboarding_step', 'milestone_reached', 'form_submit', 'search'],
                'required' => true,
                'estimated_minutes' => 20,
            ],
            [
                'key' => 'revenue',
                'label' => 'Revenue Tracking',
                'description' => 'Track the monetization funnel: subscriptions, payments, plan changes, and revenue events.',
                'events' => ['subscribe', 'payment_succeeded', 'payment_failed', 'plan_upgrade', 'plan_downgrade', 'cancellation', 'revenue_tracked'],
                'required' => true,
                'estimated_minutes' => 25,
            ],
            [
                'key' => 'retention',
                'label' => 'Retention Tracking',
                'description' => 'Track user engagement and churn signals: session activity, content engagement, scroll depth.',
                'events' => ['scroll_depth', 'time_on_page', 'session_start', 'session_end', 'content_engagement'],
                'required' => false,
                'estimated_minutes' => 15,
            ],
            [
                'key' => 'growth',
                'label' => 'Growth Tracking',
                'description' => 'Track organic growth signals: team invites, integrations, sharing, and expansion revenue.',
                'events' => ['team_created', 'team_member_joined', 'invite_sent', 'share', 'integration_connected', 'expansion_revenue'],
                'required' => false,
                'estimated_minutes' => 20,
            ],
        ];
    }

    /**
     * Get the current step index (0-based) based on catalog coverage.
     *
     * Inspects the EventCatalog recommended instrumentation to determine
     * which step the user should focus on next.
     *
     * @return int
     */
    public function currentStepIndex(): int
    {
        $steps = $this->getSteps();
        $catalogEvents = EventCatalog::names();

        foreach ($steps as $index => $step) {
            $stepEvents = $step['events'];
            $covered = count(array_filter($stepEvents, fn (string $e): bool => in_array($e, $catalogEvents, true)));
            $coverage = count($stepEvents) > 0 ? $covered / count($stepEvents) : 1.0;

            if ($coverage < 0.5) {
                return $index;
            }
        }

        return count($steps) - 1; // All steps covered
    }

    /**
     * Get detailed progress for each wizard step.
     *
     * Returns per-step event coverage, completion status,
     * and estimated time remaining.
     *
     * @return array{steps: list<array{key: string, label: string, total_events: int, covered_events: int, coverage: float, is_complete: bool, estimated_minutes: int}>, total_events: int, covered_events: int, overall_coverage: float}
     */
    public function getDetailedProgress(): array
    {
        $steps = $this->getSteps();
        $catalogEvents = EventCatalog::names();
        $resultSteps = [];
        $totalEvents = 0;
        $coveredEvents = 0;

        foreach ($steps as $step) {
            $stepEvents = $step['events'];
            $total = count($stepEvents);
            $covered = count(array_filter($stepEvents, fn (string $e): bool => in_array($e, $catalogEvents, true)));
            $coverage = $total > 0 ? $covered / $total : 1.0;

            $totalEvents += $total;
            $coveredEvents += $covered;

            $resultSteps[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'total_events' => $total,
                'covered_events' => $covered,
                'coverage' => round($coverage, 2),
                'is_complete' => $coverage >= 0.8,
                'estimated_minutes' => $step['estimated_minutes'],
            ];
        }

        $overallCoverage = $totalEvents > 0 ? $coveredEvents / $totalEvents : 1.0;

        return [
            'steps' => $resultSteps,
            'total_events' => $totalEvents,
            'covered_events' => $coveredEvents,
            'overall_coverage' => round($overallCoverage, 2),
        ];
    }

    /**
     * Get the recommended next events to instrument.
     *
     * Analyzes current catalog coverage and returns the top-priority
     * events that should be instrumented next, grouped by step.
     *
     * @param  int  $limit  Maximum number of events to recommend (default: 10)
     * @return array{events: list<array{name: string, step: string, priority: string, category: string|null}>, estimated_minutes: int}
     */
    public function getRecommendations(int $limit = 10): array
    {
        $steps = $this->getSteps();
        $catalogEvents = EventCatalog::names();
        $recommendations = [];
        $totalMinutes = 0;

        foreach ($steps as $step) {
            foreach ($step['events'] as $eventName) {
                if (in_array($eventName, $catalogEvents, true)) {
                    continue; // Already in catalog
                }

                $entry = EventCatalog::get($eventName);
                $recommendations[] = [
                    'name' => $eventName,
                    'step' => $step['key'],
                    'priority' => $step['required'] ? 'high' : 'medium',
                    'category' => $entry['category'] ?? null,
                ];

                if (count($recommendations) >= $limit) {
                    break 2;
                }
            }

            $totalMinutes += $step['estimated_minutes'];
        }

        return [
            'events' => $recommendations,
            'estimated_minutes' => $totalMinutes,
        ];
    }

    /**
     * Get the configuration readiness checklist.
     *
     * Checks which provider and feature configurations are properly
     * set up for a production SaaS deployment.
     *
     * @return array{items: list<array{key: string, label: string, status: 'configured'|'missing'|'partial', importance: 'critical'|'high'|'medium'|'low'}>, configured_count: int, total_count: int, score: float}
     */
    public function getConfigChecklist(): array
    {
        $items = [
            // Critical providers
            ['key' => 'ga4', 'label' => 'GA4 Measurement Protocol', 'check' => fn (): bool => (bool) $this->config->get('zeroboiler.analytics.ga4.enabled'), 'importance' => 'critical'],
            ['key' => 'consent', 'label' => 'Consent Mode v2', 'check' => fn (): bool => $this->config->get('zeroboiler.analytics.consent.default') !== null, 'importance' => 'critical'],
            ['key' => 'queue', 'label' => 'Async Queue Dispatch', 'check' => fn (): bool => (bool) $this->config->get('zeroboiler.analytics.queue.enabled'), 'importance' => 'critical'],

            // High importance
            ['key' => 'identity', 'label' => 'Identity Tracking Cookie', 'check' => fn (): bool => $this->config->get('zeroboiler.analytics.identity.cookie_name') !== null, 'importance' => 'high'],
            ['key' => 'auto_track', 'label' => 'Server-Side Auto-Track', 'check' => fn (): bool => (bool) $this->config->get('zeroboiler.analytics.auto_track.enabled'), 'importance' => 'high'],
            ['key' => 'api', 'label' => 'API Endpoints', 'check' => fn (): bool => (bool) $this->config->get('zeroboiler.analytics.api.enabled'), 'importance' => 'high'],
            ['key' => 'validation', 'label' => 'Event Validation', 'check' => fn (): bool => $this->config->get('zeroboiler.analytics.validation.strict') !== null, 'importance' => 'high'],

            // Medium importance
            ['key' => 'dedup', 'label' => 'Event Deduplication', 'check' => fn (): bool => (bool) $this->config->get('zeroboiler.analytics.dedup.enabled'), 'importance' => 'medium'],
            ['key' => 'sampling', 'label' => 'Event Sampling', 'check' => fn (): bool => $this->config->get('zeroboiler.analytics.sampling.enabled') !== null, 'importance' => 'medium'],
            ['key' => 'replay', 'label' => 'Event Replay Queue', 'check' => fn (): bool => (bool) $this->config->get('zeroboiler.analytics.replay.enabled'), 'importance' => 'medium'],
            ['key' => 'metrics', 'label' => 'Metrics & Observability', 'check' => fn (): bool => (bool) $this->config->get('zeroboiler.analytics.metrics.enabled'), 'importance' => 'medium'],

            // Low importance (nice-to-have)
            ['key' => 'gtm', 'label' => 'GTM Container', 'check' => fn (): bool => (bool) $this->config->get('zeroboiler.analytics.gtm.enabled'), 'importance' => 'low'],
            ['key' => 'meta_pixel', 'label' => 'Meta Pixel', 'check' => fn (): bool => (bool) $this->config->get('zeroboiler.analytics.meta_pixel.enabled'), 'importance' => 'low'],
            ['key' => 'plausible', 'label' => 'Plausible Analytics', 'check' => fn (): bool => (bool) $this->config->get('zeroboiler.analytics.plausible.enabled'), 'importance' => 'low'],
            ['key' => 'posthog', 'label' => 'PostHog', 'check' => fn (): bool => (bool) $this->config->get('zeroboiler.analytics.posthog.enabled'), 'importance' => 'low'],
            ['key' => 'pii', 'label' => 'PII Sanitization', 'check' => fn (): bool => (bool) $this->config->get('zeroboiler.analytics.pii_sanitization.enabled'), 'importance' => 'low'],
            ['key' => 'broadcast', 'label' => 'Event Broadcasting', 'check' => fn (): bool => (bool) $this->config->get('zeroboiler.analytics.broadcast.enabled'), 'importance' => 'low'],
        ];

        $resultItems = [];
        $configuredCount = 0;

        foreach ($items as $item) {
            $isConfigured = ($item['check'])();
            $status = $isConfigured ? 'configured' : 'missing';
            $configuredCount += (int) $isConfigured;

            $resultItems[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'status' => $status,
                'importance' => $item['importance'],
            ];
        }

        $totalCount = count($resultItems);
        $score = $totalCount > 0 ? $configuredCount / $totalCount : 1.0;

        return [
            'items' => $resultItems,
            'configured_count' => $configuredCount,
            'total_count' => $totalCount,
            'score' => round($score, 2),
        ];
    }

    /**
     * Get the overall onboarding readiness grade.
     *
     * Combines event catalog coverage, config readiness, and provider
     * setup into a single A-F grade with detailed breakdown.
     *
     * @return array{grade: string, score: float, breakdown: array{events: float, config: float, providers: float}, next_steps: list<string>}
     */
    public function getReadinessGrade(): array
    {
        // Event coverage score (0-1)
        $progress = $this->getDetailedProgress();
        $eventScore = $progress['overall_coverage'];

        // Config readiness score (0-1)
        $checklist = $this->getConfigChecklist();
        $configScore = $checklist['score'];

        // Provider setup score (0-1)
        $providerCount = 0;
        $providerEnabled = 0;

        $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog'];
        foreach ($providers as $provider) {
            $providerCount++;
            if ((bool) $this->config->get("zeroboiler.analytics.{$provider}.enabled")) {
                $providerEnabled++;
            }
        }
        $providerScore = $providerCount > 0 ? $providerEnabled / $providerCount : 0;

        // Weighted composite score
        // Events: 40%, Config: 35%, Providers: 25%
        $compositeScore = ($eventScore * 0.40) + ($configScore * 0.35) + ($providerScore * 0.25);

        // Grade mapping
        $grade = match (true) {
            $compositeScore >= 0.90 => 'A',
            $compositeScore >= 0.75 => 'B',
            $compositeScore >= 0.60 => 'C',
            $compositeScore >= 0.40 => 'D',
            default => 'F',
        };

        $nextSteps = [];
        $steps = $this->getSteps();
        $catalogEvents = EventCatalog::names();

        foreach ($steps as $step) {
            $covered = count(array_filter($step['events'], fn (string $e): bool => in_array($e, $catalogEvents, true)));
            $total = count($step['events']);

            if ($total > 0 && $covered / $total < 0.5) {
                $nextSteps[] = "Instrument {$step['label']} events ({$covered}/{$total} done, ~{$step['estimated_minutes']}min)";
            }
        }

        if ($providerEnabled === 0) {
            $nextSteps[] = 'Enable at least one analytics provider (GA4, Meta, PostHog, or Plausible)';
        }

        if ($configScore < 0.5) {
            $nextSteps[] = 'Configure critical settings: queue, consent, identity tracking';
        }

        return [
            'grade' => $grade,
            'score' => round($compositeScore, 2),
            'breakdown' => [
                'events' => round($eventScore, 2),
                'config' => round($configScore, 2),
                'providers' => round($providerScore, 2),
            ],
            'next_steps' => $nextSteps,
        ];
    }

    /**
     * Get a quick-start checklist for day-one instrumentation.
     *
     * Returns the absolute minimum events and config needed to get
     * actionable analytics data on day one. Focuses on 5 essential
     * events + 3 critical config items.
     *
     * @return array{events: list<array{name: string, why: string, provider_coverage: array{ga4: bool, meta: bool, posthog: bool}}>, config: list<array{key: string, label: string, env_var: string}>, estimated_minutes: int}
     */
    public function getQuickStartChecklist(): array
    {
        $quickEvents = [
            ['name' => 'sign_up', 'why' => 'Track acquisition — who signs up and from where'],
            ['name' => 'login', 'why' => 'Track returning users and DAU/MAU calculations'],
            ['name' => 'page_view', 'why' => 'Track user navigation patterns and content engagement'],
            ['name' => 'purchase', 'why' => 'Track revenue and conversion funnel completion'],
            ['name' => 'error', 'why' => 'Track product stability and user-facing issues'],
        ];

        $quickConfig = [
            ['key' => 'ga4', 'label' => 'GA4 Provider', 'env_var' => 'ANALYTICS_GA4_ENABLED=true'],
            ['key' => 'queue', 'label' => 'Async Queue', 'env_var' => 'ANALYTICS_QUEUE_ENABLED=true'],
            ['key' => 'consent', 'label' => 'Consent Mode', 'env_var' => 'ANALYTICS_CONSENT_DEFAULT=granted'],
        ];

        $events = [];
        foreach ($quickEvents as $qe) {
            $entry = EventCatalog::get($qe['name']);
            $events[] = [
                'name' => $qe['name'],
                'why' => $qe['why'],
                'provider_coverage' => [
                    'ga4' => isset($entry['ga4']) && $entry['ga4'] !== '',
                    'meta' => isset($entry['meta']) && $entry['meta'] !== null,
                    'posthog' => isset($entry['posthog']) && $entry['posthog'] !== null,
                ],
            ];
        }

        return [
            'events' => $events,
            'config' => $quickConfig,
            'estimated_minutes' => 15,
        ];
    }

    /**
     * Build the initial wizard state from catalog data.
     *
     * @return WizardState
     */
    private function buildInitialState(): array
    {
        $progress = $this->getDetailedProgress();
        $steps = $this->getSteps();

        $completedSteps = [];
        foreach ($steps as $step) {
            $stepProgress = $progress['steps'][$this->findStepKey($progress['steps'], $step['key'])] ?? null;
            if ($stepProgress !== null && $stepProgress['is_complete']) {
                $completedSteps[] = $step['key'];
            }
        }

        $completionPercentage = $progress['overall_coverage'] * 100.0;
        $grade = match (true) {
            $completionPercentage >= 90.0 => 'A',
            $completionPercentage >= 75.0 => 'B',
            $completionPercentage >= 60.0 => 'C',
            $completionPercentage >= 40.0 => 'D',
            default => 'F',
        };

        return [
            'started_at' => null,
            'current_step' => $steps[$this->currentStepIndex()]['key'] ?? null,
            'completed_steps' => $completedSteps,
            'total_events_instrumented' => $progress['covered_events'],
            'completion_percentage' => round($completionPercentage, 1),
            'grade' => $grade,
        ];
    }

    /**
     * Find step key in progress steps array.
     *
     * @param  list<array{key: string, ...}>  $steps
     */
    private function findStepKey(array $steps, string $key): ?int
    {
        foreach ($steps as $index => $step) {
            if ($step['key'] === $key) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Build cache key for the wizard state.
     */
    private function cacheKey(string $appId): string
    {
        return self::CACHE_PREFIX . $appId;
    }

    /**
     * Attempt to read wizard state from cache.
     *
     * @return WizardState|null
     */
    private function readCache(string $key): ?array
    {
        try {
            $cached = cache()->get($key);

            if (is_array($cached) && isset($cached['grade']) && isset($cached['completion_percentage'])) {
                return $cached;
            }
        } catch (\Throwable $e) {
            // Cache driver not available
        }

        return null;
    }
}
