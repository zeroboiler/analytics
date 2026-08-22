<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
/**
 * SaaS Onboarding Wizard Service — guides new users through analytics setup.
 *
 * Provides a step-by-step onboarding checklist for setting up ZeroBoiler Analytics
 * in a SaaS application. Each step has a completion status, priority, and
 * recommended configuration. The wizard assesses the current state of the
 * analytics setup and identifies gaps.
 *
 * Steps:
 * 1. Provider configuration (GA4, GTM, Meta Pixel)
 * 2. Consent mode setup (GDPR/CCPA)
 * 3. Event catalog instrumentation (core SaaS events)
 * 4. E-commerce event tracking
 * 5. Identity linking (client ↔ user)
 * 6. Inertia middleware integration
 * 7. Server-side lifecycle tracking
 * 8. Queue configuration for async dispatch
 * 9. API route registration
 * 10. JS client initialization
 * 11. Blade directives (if applicable)
 * 12. Admin dashboard setup
 *
 * Inspired by PostHog's onboarding wizard, Mixpanel's quick start guide,
 * and Amplitude's instrumentation advisor.
 *
 * @since 177.0.0
 */
final class SaaSOnboardingWizardService
{
    private const CACHE_KEY = 'zeroboiler:onboarding:wizard';
    private const CACHE_TTL = 3600; // 1 hour

    private CacheRepository $cache;

    private ConfigRepository $config;

    /** @var list<array{key: string, label: string, description: string, priority: 'critical'|'high'|'medium'|'low', category: string}> */
    private array $steps;

    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;
        $this->steps = $this->defineSteps();
    }

    /**
     * Get the full onboarding wizard state.
     *
     * Returns all steps with their completion status, current configuration,
     * and recommendations for each incomplete step.
     *
     * @return array{steps: list<array{key: string, label: string, description: string, priority: string, category: string, completed: bool, recommendation: string, config_path: string|null}>, summary: array{total: int, completed: int, completion: float, critical_remaining: int, grade: string}}
     */
    public function getState(): array
    {
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            return $this->computeState();
        });
    }

    /**
     * Get just the summary (completion percentage, grade, critical gaps).
     *
     * @return array{total: int, completed: int, completion: float, critical_remaining: int, grade: string}
     */
    public function summary(): array
    {
        $state = $this->getState();

        return $state['summary'];
    }

    /**
     * Get only the incomplete steps, sorted by priority.
     *
     * @return list<array{key: string, label: string, description: string, priority: string, category: string, completed: bool, recommendation: string, config_path: string|null}>
     */
    public function gaps(): array
    {
        $state = $this->getState();
        $incomplete = array_filter(
            $state['steps'],
            static fn (array $step): bool => ! $step['completed']
        );

        // Sort by priority weight
        $priorityWeight = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        uasort($incomplete, static function (array $a, array $b) use ($priorityWeight): int {
            return ($priorityWeight[$a['priority']] ?? 99) - ($priorityWeight[$b['priority']] ?? 99);
        });

        return array_values($incomplete);
    }

    /**
     * Get the next recommended action based on priority and dependencies.
     *
     * @return array{key: string, label: string, recommendation: string, priority: string}|null
     */
    public function nextAction(): ?array
    {
        $gaps = $this->gaps();

        if ($gaps === []) {
            return null;
        }

        $first = $gaps[0];

        return [
            'key' => $first['key'],
            'label' => $first['label'],
            'recommendation' => $first['recommendation'],
            'priority' => $first['priority'],
        ];
    }

    /**
     * Get the onboarding grade (A+ through F).
     */
    public function grade(): string
    {
        $summary = $this->summary();

        return $summary['grade'];
    }

    /**
     * Invalidate the cached wizard state.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Check if a specific step is completed.
     */
    public function isStepCompleted(string $key): bool
    {
        $state = $this->getState();

        foreach ($state['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step['completed'];
            }
        }

        return false;
    }

    /**
     * Get step count by category.
     *
     * @return array<string, array{total: int, completed: int}>
     */
    public function categoryBreakdown(): array
    {
        $state = $this->getState();
        $breakdown = [];

        foreach ($state['steps'] as $step) {
            $cat = $step['category'];
            if (! isset($breakdown[$cat])) {
                $breakdown[$cat] = ['total' => 0, 'completed' => 0];
            }
            $breakdown[$cat]['total']++;
            if ($step['completed']) {
                $breakdown[$cat]['completed']++;
            }
        }

        return $breakdown;
    }

    /**
     * Compute the full wizard state from current configuration.
     *
     * @return array{steps: list<array{key: string, label: string, description: string, priority: string, category: string, completed: bool, recommendation: string, config_path: string|null}>, summary: array{total: int, completed: int, completion: float, critical_remaining: int, grade: string}}
     */
    private function computeState(): array
    {
        $steps = [];

        foreach ($this->steps as $stepDef) {
            $completed = $this->assessStepCompletion($stepDef['key']);
            $steps[] = [
                'key' => $stepDef['key'],
                'label' => $stepDef['label'],
                'description' => $stepDef['description'],
                'priority' => $stepDef['priority'],
                'category' => $stepDef['category'],
                'completed' => $completed,
                'recommendation' => $this->getStepRecommendation($stepDef['key'], $completed),
                'config_path' => $this->getConfigPath($stepDef['key']),
            ];
        }

        $total = count($steps);
        $completedCount = count(array_filter($steps, static fn (array $s): bool => $s['completed']));
        $completion = $total > 0 ? (float) round(($completedCount / $total) * 100, 1) : 0.0;
        $criticalRemaining = count(array_filter(
            $steps,
            static fn (array $s): bool => $s['priority'] === 'critical' && ! $s['completed']
        ));

        return [
            'steps' => $steps,
            'summary' => [
                'total' => $total,
                'completed' => $completedCount,
                'completion' => $completion,
                'critical_remaining' => $criticalRemaining,
                'grade' => $this->calculateGrade($completion, $criticalRemaining),
            ],
        ];
    }

    /**
     * Assess whether a specific step is completed based on config.
     */
    private function assessStepCompletion(string $key): bool
    {
        return match ($key) {
            'provider_ga4' => (bool) $this->config->get('zeroboiler.analytics.ga4.enabled', false),
            'provider_gtm' => (bool) $this->config->get('zeroboiler.analytics.gtm.enabled', false),
            'provider_meta' => (bool) $this->config->get('zeroboiler.analytics.meta_pixel.enabled', false),
            'provider_plausible' => (bool) $this->config->get('zeroboiler.analytics.plausible.enabled', false),
            'provider_posthog' => (bool) $this->config->get('zeroboiler.analytics.posthog.enabled', false),
            'consent_mode' => $this->config->get('zeroboiler.analytics.consent.default') !== null,
            'core_saas_events' => $this->assessSaasEventTracking(),
            'ecommerce_events' => $this->config->get('zeroboiler.analytics.ecommerce') !== null,
            'identity_linking' => $this->config->get('zeroboiler.analytics.identity') !== null,
            'inertia_middleware' => true, // Middleware exists in package
            'lifecycle_tracking' => (bool) $this->config->get('zeroboiler.analytics.lifecycle.enabled', true),
            'queue_config' => (bool) $this->config->get('zeroboiler.analytics.queue.enabled', true),
            'api_routes' => (bool) $this->config->get('zeroboiler.analytics.api.enabled', true),
            'js_client' => true, // JS client ships with package
            'blade_directives' => true, // Blade directives ship with package
            'auto_track' => (bool) $this->config->get('zeroboiler.analytics.auto_track.enabled', true),
            'event_validation' => (bool) $this->config->get('zeroboiler.analytics.validation.enabled', false),
            'error_tracking' => (bool) ($this->config->get('zeroboiler.analytics.client_auto_track.error_tracking', true) ?? true),
            'session_recording' => $this->config->get('zeroboiler.analytics.session_recording.enabled', false) === true,
            default => false,
        };
    }

    /**
     * Assess if core SaaS events are being tracked.
     */
    private function assessSaasEventTracking(): bool
    {
        $autoTrack = $this->config->get('zeroboiler.analytics.auto_track.events', []);

        // At least 3 core SaaS events should be enabled
        $coreKeys = ['auth.login', 'auth.register', 'subscription.created', 'trial.started'];
        $enabledCount = 0;

        foreach ($coreKeys as $key) {
            if (! isset($autoTrack[$key]) || $autoTrack[$key] === true) {
                $enabledCount++;
            }
        }

        return $enabledCount >= 3;
    }

    /**
     * Get a recommendation for a step.
     */
    private function getStepRecommendation(string $key, bool $completed): string
    {
        if ($completed) {
            return '✓ Completed';
        }

        return match ($key) {
            'provider_ga4' => 'Set ANALYTICS_GA4_ENABLED=true and configure ANALYTICS_GA4_MEASUREMENT_ID. Server-side Measurement Protocol events provide the most accurate tracking.',
            'provider_gtm' => 'Set ANALYTICS_GTM_ENABLED=true and configure ANALYTICS_GTM_CONTAINER_ID. GTM enables tag management without code deploys.',
            'provider_meta' => 'Set ANALYTICS_META_PIXEL_ENABLED=true and configure ANALYTICS_META_PIXEL_ID. Required for Meta ad attribution and CAPI events.',
            'provider_plausible' => 'Set ANALYTICS_PLAUSIBLE_ENABLED=true and configure domain. Plausible is privacy-focused and cookieless.',
            'provider_posthog' => 'Set ANALYTICS_POSTHOG_ENABLED=true and configure host/token. PostHog provides product analytics with feature flags.',
            'consent_mode' => 'Configure zeroboiler.analytics.consent.default to "denied" for GDPR-first approach. Set up consent purposes for granular user control.',
            'core_saas_events' => 'Enable auto-tracking for auth.login, auth.register, subscription.created, trial.started in zeroboiler.analytics.auto_track.events.',
            'ecommerce_events' => 'Configure zeroboiler.analytics.ecommerce with currency, brand, and tax settings. Track view_item, add_to_cart, purchase events.',
            'identity_linking' => 'Configure zeroboiler.analytics.identity with cookie_name and link_on_auth. This links anonymous client IDs to authenticated user IDs.',
            'inertia_middleware' => 'Register HandleInertiaAnalytics middleware in bootstrap/app.php. It injects zbAnalytics page props automatically.',
            'lifecycle_tracking' => 'Set ANALYTICS_LIFECYCLE_ENABLED=true. Maps Laravel events (Illuminate\\Auth\\Events\\Login) to analytics events automatically.',
            'queue_config' => 'Set ANALYTICS_QUEUE_ENABLED=true. Analytics events are dispatched to a queue worker for non-blocking processing.',
            'api_routes' => 'Routes are auto-registered by the service provider. Ensure ANALYTICS_API_ENABLED=true and configure rate limits.',
            'js_client' => 'Import from resources/js/analytics.js. Call init(page.props.zbAnalytics) in your root Svelte layout component.',
            'blade_directives' => '@analyticsScripts, @analyticsHead, @analyticsBody directives are auto-registered. Use in your Blade layouts.',
            'auto_track' => 'Configure zeroboiler.analytics.auto_track to automatically track auth, subscription, and trial events server-side.',
            'event_validation' => 'Enable zeroboiler.analytics.validation.enabled=true to enforce schema validation on all tracked events.',
            'error_tracking' => 'Enable zeroboiler.analytics.client_auto_track.error_tracking=true to capture JS errors and client-side exceptions.',
            'session_recording' => 'Configure zeroboiler.analytics.session_recording.enabled=true for session replay integration with PostHog or custom providers.',
            default => 'Complete this step to improve your analytics coverage.',
        };
    }

    /**
     * Get the config path for a step.
     */
    private function getConfigPath(string $key): ?string
    {
        return match ($key) {
            'provider_ga4' => 'zeroboiler.analytics.ga4',
            'provider_gtm' => 'zeroboiler.analytics.gtm',
            'provider_meta' => 'zeroboiler.analytics.meta_pixel',
            'provider_plausible' => 'zeroboiler.analytics.plausible',
            'provider_posthog' => 'zeroboiler.analytics.posthog',
            'consent_mode' => 'zeroboiler.analytics.consent',
            'core_saas_events' => 'zeroboiler.analytics.auto_track',
            'ecommerce_events' => 'zeroboiler.analytics.ecommerce',
            'identity_linking' => 'zeroboiler.analytics.identity',
            'inertia_middleware' => 'bootstrap/app.php (middleware)',
            'lifecycle_tracking' => 'zeroboiler.analytics.lifecycle',
            'queue_config' => 'zeroboiler.analytics.queue',
            'api_routes' => 'zeroboiler.analytics.api',
            'js_client' => 'resources/js/analytics.js',
            'blade_directives' => 'resources/views (auto-registered)',
            'auto_track' => 'zeroboiler.analytics.auto_track',
            'event_validation' => 'zeroboiler.analytics.validation',
            'error_tracking' => 'zeroboiler.analytics.client_auto_track.error_tracking',
            'session_recording' => 'zeroboiler.analytics.session_recording',
            default => null,
        };
    }

    /**
     * Calculate a grade based on completion percentage and critical gaps.
     *
     * @return string Grade from A+ to F
     */
    private function calculateGrade(float $completion, int $criticalRemaining): string
    {
        if ($completion >= 95 && $criticalRemaining === 0) {
            return 'A+';
        }

        if ($completion >= 85 && $criticalRemaining === 0) {
            return 'A';
        }

        if ($completion >= 75 && $criticalRemaining <= 1) {
            return 'B+';
        }

        if ($completion >= 60 && $criticalRemaining <= 2) {
            return 'B';
        }

        if ($completion >= 50) {
            return 'C';
        }

        if ($completion >= 30) {
            return 'D';
        }

        return 'F';
    }

    /**
     * Define the onboarding steps.
     *
     * @return list<array{key: string, label: string, description: string, priority: 'critical'|'high'|'medium'|'low', category: string}>
     */
    private function defineSteps(): array
    {
        return [
            [
                'key' => 'provider_ga4',
                'label' => 'Configure GA4 Provider',
                'description' => 'Enable Google Analytics 4 with Measurement Protocol for server-side event tracking.',
                'priority' => 'critical',
                'category' => 'providers',
            ],
            [
                'key' => 'consent_mode',
                'label' => 'Set Up Consent Mode',
                'description' => 'Configure GDPR/CCPA consent defaults and purposes for compliant tracking.',
                'priority' => 'critical',
                'category' => 'compliance',
            ],
            [
                'key' => 'core_saas_events',
                'label' => 'Track Core SaaS Events',
                'description' => 'Enable auto-tracking for signup, login, trial, and subscription lifecycle events.',
                'priority' => 'critical',
                'category' => 'events',
            ],
            [
                'key' => 'identity_linking',
                'label' => 'Configure Identity Linking',
                'description' => 'Set up client ID ↔ user ID linking for anonymous-to-authenticated user journey tracking.',
                'priority' => 'critical',
                'category' => 'identity',
            ],
            [
                'key' => 'inertia_middleware',
                'label' => 'Register Inertia Middleware',
                'description' => 'Add HandleInertiaAnalytics middleware to inject analytics props into all Inertia pages.',
                'priority' => 'high',
                'category' => 'integration',
            ],
            [
                'key' => 'js_client',
                'label' => 'Initialize JS Client',
                'description' => 'Import and initialize the analytics.js client in your Svelte root layout with Inertia props.',
                'priority' => 'high',
                'category' => 'integration',
            ],
            [
                'key' => 'provider_meta',
                'label' => 'Configure Meta Pixel',
                'description' => 'Enable Meta Pixel for Facebook/Instagram ad attribution and Conversions API (CAPI).',
                'priority' => 'high',
                'category' => 'providers',
            ],
            [
                'key' => 'lifecycle_tracking',
                'label' => 'Enable Lifecycle Tracking',
                'description' => 'Turn on server-side event mapping to auto-track Laravel auth and subscription events.',
                'priority' => 'high',
                'category' => 'events',
            ],
            [
                'key' => 'queue_config',
                'label' => 'Configure Queue Dispatch',
                'description' => 'Enable async queue processing for non-blocking analytics event dispatch.',
                'priority' => 'high',
                'category' => 'infrastructure',
            ],
            [
                'key' => 'ecommerce_events',
                'label' => 'Set Up E-commerce Events',
                'description' => 'Configure currency, brand, and tax settings for product tracking (view_item, purchase, etc.).',
                'priority' => 'medium',
                'category' => 'events',
            ],
            [
                'key' => 'provider_gtm',
                'label' => 'Configure GTM Provider',
                'description' => 'Enable Google Tag Manager for server-side tag management without code deploys.',
                'priority' => 'medium',
                'category' => 'providers',
            ],
            [
                'key' => 'auto_track',
                'label' => 'Configure Auto-Tracking',
                'description' => 'Set up which Laravel events are automatically tracked as analytics events server-side.',
                'priority' => 'medium',
                'category' => 'events',
            ],
            [
                'key' => 'event_validation',
                'label' => 'Enable Event Validation',
                'description' => 'Turn on schema validation to enforce event parameter types and catch malformed events.',
                'priority' => 'medium',
                'category' => 'quality',
            ],
            [
                'key' => 'api_routes',
                'label' => 'Verify API Routes',
                'description' => 'Confirm analytics API endpoints are registered and rate limits are configured.',
                'priority' => 'medium',
                'category' => 'infrastructure',
            ],
            [
                'key' => 'error_tracking',
                'label' => 'Enable Error Tracking',
                'description' => 'Capture JavaScript errors and client-side exceptions as analytics events.',
                'priority' => 'medium',
                'category' => 'quality',
            ],
            [
                'key' => 'provider_plausible',
                'label' => 'Configure Plausible',
                'description' => 'Enable Plausible for privacy-first, cookieless analytics with simple event tracking.',
                'priority' => 'low',
                'category' => 'providers',
            ],
            [
                'key' => 'provider_posthog',
                'label' => 'Configure PostHog',
                'description' => 'Enable PostHog for product analytics with feature flags, session replay, and A/B testing.',
                'priority' => 'low',
                'category' => 'providers',
            ],
            [
                'key' => 'blade_directives',
                'label' => 'Use Blade Directives',
                'description' => 'Add @analyticsScripts to Blade layouts for automatic script injection (if not using Inertia).',
                'priority' => 'low',
                'category' => 'integration',
            ],
            [
                'key' => 'session_recording',
                'label' => 'Enable Session Recording',
                'description' => 'Configure session replay integration for visual user behavior analysis.',
                'priority' => 'low',
                'category' => 'quality',
            ],
        ];
    }
}
