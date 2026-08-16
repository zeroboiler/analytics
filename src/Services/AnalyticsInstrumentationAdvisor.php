<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Analytics instrumentation advisor for SaaS onboarding.
 *
 * Generates actionable, code-snippet level guidance for wiring
 * analytics events into a Laravel application. Analyzes the event
 * catalog against the industry-standard set and produces a prioritized
 * instrumentation plan with ready-to-use code examples.
 *
 * Inspired by Segment's tracking plan and Mixpanel's instrumentation guide.
 *
 * @since 9.7.0
 */
final class AnalyticsInstrumentationAdvisor
{
    /**
     * Generate a complete instrumentation plan.
     *
     * Analyzes all event categories and produces a prioritized list of
     * events to instrument with code examples, parameter specifications,
     * and provider coverage information.
     *
     * @return array{plan: list<array{name: string, category: string, priority: string, params: array<string, string>, code_example: string, providers: array<string, string|null>, description: string}>, summary: array{total: int, critical: int, high: int, medium: int, low: int, by_category: array<string, int>}}
     */
    public function generatePlan(): array
    {
        $standard = EventCatalog::industryStandard();
        $all = [];

        foreach (['critical', 'high', 'medium', 'low'] as $tier) {
            foreach ($standard[$tier] as $entry) {
                $name = $entry['name'];
                $category = $entry['category'] ?? 'unknown';

                $all[] = [
                    'name' => $name,
                    'category' => $category,
                    'priority' => $tier,
                    'params' => $this->getParamSpec($name),
                    'code_example' => $this->generateCodeExample($name, $category),
                    'providers' => [
                        'ga4' => $entry['ga4'] ?? $name,
                        'meta' => $entry['meta'] ?? null,
                        'posthog' => $entry['posthog'] ?? null,
                        'plausible' => $entry['plausible'] ?? null,
                    ],
                    'description' => $this->getEventDescription($name),
                ];
            }
        }

        $summary = [
            'total' => count($all),
            'critical' => count($standard['critical']),
            'high' => count($standard['high']),
            'medium' => count($standard['medium']),
            'low' => count($standard['low']),
            'by_category' => [],
        ];

        foreach ($all as $item) {
            $cat = $item['category'];
            $summary['by_category'][$cat] = ($summary['by_category'][$cat] ?? 0) + 1;
        }

        return [
            'plan' => $all,
            'summary' => $summary,
        ];
    }

    /**
     * Generate a quick-start instrumentation guide for day-one setup.
     *
     * Returns the 12-15 critical events with copy-paste ready code
     * for server-side dispatch, JS client tracking, and auto-track config.
     *
     * @return array{events: list<array{name: string, server_code: string, client_code: string, auto_track_key: string|null}>, config_snippet: string, middleware_snippet: string, js_init_snippet: string}
     */
    public function quickStartGuide(): array
    {
        $quickStart = EventCatalog::quickStart();
        $events = [];

        foreach ($quickStart['events'] as $entry) {
            $name = $entry['name'];
            $category = $entry['category'] ?? 'unknown';

            $events[] = [
                'name' => $name,
                'category' => $category,
                'server_code' => $this->generateServerCode($name),
                'client_code' => $this->generateClientCode($name),
                'auto_track_key' => $this->getAutoTrackKey($name),
            ];
        }

        return [
            'events' => $events,
            'config_snippet' => $this->generateConfigSnippet(),
            'middleware_snippet' => $this->generateMiddlewareSnippet(),
            'js_init_snippet' => $this->generateJsInitSnippet(),
        ];
    }

    /**
     * Generate a gap analysis comparing tracked events vs industry standard.
     *
     * @param  list<string>  $trackedEvents  Event names already instrumented
     * @return array{coverage: float, gaps: list<array{name: string, category: string, priority: string, code_example: string}>, covered: list<string>, score: int}
     */
    public function gapAnalysis(array $trackedEvents): array
    {
        $standard = EventCatalog::industryStandard();
        $trackedSet = array_flip($trackedEvents);

        $gaps = [];
        $covered = [];

        foreach ($standard['all'] as $entry) {
            $name = $entry['name'];
            if (isset($trackedSet[$name])) {
                $covered[] = $name;
            } else {
                $gaps[] = [
                    'name' => $name,
                    'category' => $entry['category'] ?? 'unknown',
                    'priority' => EventCatalog::eventPriority($name),
                    'code_example' => $this->generateCodeExample($name, $entry['category'] ?? 'unknown'),
                ];
            }
        }

        $totalStandard = $standard['count'];
        $coverageCount = count($covered);
        $coverage = $totalStandard > 0 ? $coverageCount / $totalStandard : 0.0;

        return [
            'coverage' => round($coverage, 3),
            'gaps' => $gaps,
            'covered' => $covered,
            'score' => (int) round($coverage * 100),
        ];
    }

    /**
     * Get parameter specification for an event.
     *
     * Returns recommended parameters with types and descriptions.
     *
     * @return array<string, string>
     */
    private function getParamSpec(string $eventName): array
    {
        $specs = [
            'sign_up' => ['method' => 'string', 'user_id' => 'string'],
            'login' => ['method' => 'string', 'guard' => 'string'],
            'start_trial' => ['plan' => 'string', 'trial_days' => 'int'],
            'subscribe' => ['plan' => 'string', 'value' => 'float', 'currency' => 'string', 'billing_cycle' => 'string'],
            'plan_upgrade' => ['from_plan' => 'string', 'to_plan' => 'string', 'value' => 'float'],
            'cancellation' => ['plan' => 'string', 'reason' => 'string'],
            'page_view' => ['page_title' => 'string', 'page_location' => 'string', 'page_referrer' => 'string'],
            'search' => ['search_term' => 'string', 'results_count' => 'int'],
            'form_submit' => ['form_id' => 'string', 'form_name' => 'string'],
            'purchase' => ['transaction_id' => 'string', 'value' => 'float', 'currency' => 'string', 'items' => 'array'],
            'payment_succeeded' => ['amount' => 'float', 'currency' => 'string', 'method' => 'string'],
            'payment_failed' => ['amount' => 'float', 'currency' => 'string', 'reason' => 'string'],
            'trial_converted' => ['plan' => 'string', 'value' => 'float'],
            'feature_used' => ['feature_name' => 'string', 'feature_category' => 'string'],
            'onboarding_step' => ['step_name' => 'string', 'step_number' => 'int', 'total_steps' => 'int'],
            'error' => ['error_message' => 'string', 'error_code' => 'string', 'fatal' => 'bool'],
            'onboarding_completed' => ['steps_completed' => 'int', 'steps_total' => 'int', 'duration_seconds' => 'int'],
        ];

        return $specs[$eventName] ?? [];
    }

    /**
     * Generate a code example for wiring an event.
     */
    private function generateCodeExample(string $name, string $category): string
    {
        $examples = [
            'sign_up' => "// app/Http/Controllers/Auth/RegisterController.php\nEvent::dispatch('auth.register'); // auto-tracked via config",
            'login' => "// Auto-tracked via Illuminate\\Auth\\Events\\Login\n// Or manually:\nAnalytics::track('login', ['method' => 'email']);",
            'start_trial' => "// app/Services/TrialService.php\nEvent::dispatch('trial.started', ['plan' => 'pro', 'trial_days' => 14]);",
            'subscribe' => "// app/Http/Controllers/SubscriptionController.php\nEvent::dispatch('subscription.created', ['plan' => 'pro', 'value' => 49.00]);",
            'plan_upgrade' => "// app/Services/SubscriptionService.php\nEvent::dispatch('subscription.upgraded', ['from_plan' => 'starter', 'to_plan' => 'pro']);",
            'cancellation' => "// app/Http/Controllers/SubscriptionController.php\nEvent::dispatch('subscription.cancelled', ['plan' => 'pro', 'reason' => 'too_expensive']);",
            'page_view' => "// Client-side (automatic via Inertia middleware)\n// Or manual: analytics.trackPageView('Dashboard', window.location.href);",
            'purchase' => "// app/Http/Controllers/OrderController.php\nAnalytics::trackPurchase('TXN-123', 99.99, 'USD', [items]);",
            'feature_used' => "// app/Services/FeatureService.php\nEvent::dispatch('feature.used', ['feature_name' => 'export', 'feature_category' => 'data']);",
            'onboarding_completed' => "// app/Services/OnboardingService.php\nuse ZeroBoiler\\Analytics\\Support\\EventBuilder;\n\nEventBuilder::make('onboarding_completed')\n    ->param('steps_completed', 5)\n    ->param('steps_total', 5)\n    ->param('duration_seconds', 342)\n    ->user(auth()->id())\n    ->dispatch();",
            'error' => "// Client-side (automatic if error_tracking enabled)\n// Or manual: analytics.trackEvent('error', { error_message: '...', error_code: '500' });",
        ];

        return $examples[$name] ?? "// Track via facade\nAnalytics::track('{$name}', [/* params */]);";
    }

    /**
     * Generate server-side dispatch code.
     */
    private function generateServerCode(string $name): string
    {
        $serverCode = [
            'sign_up' => "use Illuminate\\Support\\Facades\\Event;\nEvent::dispatch('auth.register');",
            'login' => "// Auto-tracked — no code needed if auto_track.auth.login = true",
            'start_trial' => "use Illuminate\\Support\\Facades\\Event;\nEvent::dispatch('trial.started', ['plan' => 'pro', 'trial_days' => 14]);",
            'subscribe' => "use ZeroBoiler\\Analytics\\Support\\EventBuilder;\nEventBuilder::make('subscribe')\n    ->param('plan', 'pro')\n    ->param('value', 49.00)\n    ->param('currency', 'USD')\n    ->user(\$user->id)\n    ->dispatch();",
            'purchase' => "use ZeroBoiler\\Analytics\\Support\\EventBuilder;\nEventBuilder::purchase('TXN-123', 99.99, 'USD')\n    ->items(\$items)\n    ->dispatch();",
            'page_view' => "// Server-side: auto-injected via HandleInertiaAnalytics middleware\n// Client-side: analytics.trackPageView();",
            'feature_used' => "use Illuminate\\Support\\Facades\\Event;\nEvent::dispatch('feature.used', ['feature_name' => 'dashboard', 'feature_category' => 'core']);",
            'error' => "// Client-side (automatic if autoTrack.errorTracking = true)\n// analytics.trackEvent('error', { error_message: e.message, error_code: '500' });",
        ];

        return $serverCode[$name] ?? "use ZeroBoiler\\Analytics\\Facades\\Analytics;\nAnalytics::track('{$name}');";
    }

    /**
     * Generate client-side tracking code.
     */
    private function generateClientCode(string $name): string
    {
        $clientCode = [
            'sign_up' => "// Svelte component\nimport { trackEvent } from '@zeroboiler/analytics';\nawait trackEvent('sign_up', { method: 'email' });",
            'login' => "// Automatic via auth state change detection\n// Or manual:\nawait trackEvent('login', { method: 'email' });",
            'page_view' => "// Automatic via initInertiaPageViewTracker()\n// Or manual:\nawait trackPageView(document.title, window.location.href);",
            'search' => "import { trackEvent } from '@zeroboiler/analytics';\nawait trackEvent('search', { search_term: query, results_count: results.length });",
            'form_submit' => "import { trackEvent } from '@zeroboiler/analytics';\nawait trackEvent('form_submit', { form_id: 'contact', form_name: 'Contact Form' });",
            'feature_used' => "import { trackEvent } from '@zeroboiler/analytics';\nawait trackEvent('feature_used', { feature_name: 'export', feature_category: 'data' });",
            'error' => "// Automatic if autoTrack.errorTracking = true\nimport { trackEvent } from '@zeroboiler/analytics';\nawait trackEvent('error', { error_message: e.message });",
            'onboarding_completed' => "import { trackEvent } from '@zeroboiler/analytics';\nawait trackEvent('onboarding_completed', {\n    steps_completed: 5,\n    steps_total: 5,\n    duration_seconds: 342,\n});",
        ];

        return $clientCode[$name] ?? "import { trackEvent } from '@zeroboiler/analytics';\nawait trackEvent('{$name}');";
    }

    /**
     * Get the auto-track config key for an event, or null if not auto-trackable.
     */
    private function getAutoTrackKey(string $name): ?string
    {
        $map = [
            'sign_up' => 'auth.register',
            'login' => 'auth.login',
            'logout' => 'auth.logout',
            'start_trial' => 'trial.started',
            'subscribe' => 'subscription.created',
            'plan_upgrade' => 'subscription.upgraded',
            'plan_downgrade' => 'subscription.downgraded',
            'cancellation' => 'subscription.cancelled',
            'feature_used' => 'feature.used',
        ];

        return $map[$name] ?? null;
    }

    /**
     * Generate a config file snippet for quick-start setup.
     */
    private function generateConfigSnippet(): string
    {
        return <<<'CONFIG'
// config/zeroboiler.php — Analytics quick-start config

'analytics' => [
    'ga4' => [
        'enabled' => env('ANALYTICS_GA4_ENABLED', false),
        'measurement_id' => env('ANALYTICS_GA4_MEASUREMENT_ID', ''),
        'api_secret' => env('ANALYTICS_GA4_API_SECRET', ''),
    ],
    'auto_track' => [
        'enabled' => true,
        'events' => [
            'auth.login' => true,
            'auth.register' => true,
            'subscription.created' => true,
            'subscription.upgraded' => true,
            'subscription.cancelled' => true,
            'trial.started' => true,
            'feature.used' => true,
        ],
    ],
    'queue' => [
        'enabled' => true,
        'queue' => 'analytics',
    ],
    'identity' => [
        'cookie_name' => 'zb_analytics_id',
        'link_on_auth' => true,
    ],
    'consent' => [
        'default' => env('ANALYTICS_CONSENT_DEFAULT', 'denied'),
        'log_enabled' => true,
    ],
],
CONFIG;
    }

    /**
     * Generate middleware registration snippet.
     */
    private function generateMiddlewareSnippet(): string
    {
        return <<<'MIDDLEWARE'
// app/Http/Kernel.php or bootstrap/app.php

// For Inertia.js apps (recommended):
->middleware([\n    \ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class,\n])

// For traditional Blade apps:
->middleware([\n    \ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts::class,\n])
MIDDLEWARE;
    }

    /**
     * Generate JS initialization snippet.
     */
    private function generateJsInitSnippet(): string
    {
        return <<<'JS'
// resources/js/Layout.svelte (Inertia.js)
import { init, initInertiaPageViewTracker } from '@zeroboiler/analytics';
import { page } from '@inertiajs/svelte';

$: if (page.props.zbAnalytics) {
    init(page.props);
    initInertiaPageViewTracker({ enableScrollDepth: true });
}

// resources/js/Pages/Dashboard.svelte
import { trackEvent, trackPageView } from '@zeroboiler/analytics';

async function handleExport() {
    await trackEvent('feature_used', { feature_name: 'export', feature_category: 'data' });
    // ... export logic
}
JS;
    }

    /**
     * Get a human-readable description for an event.
     */
    private function getEventDescription(string $name): string
    {
        $descriptions = [
            'sign_up' => 'User creates a new account. Critical for acquisition funnel.',
            'login' => 'User authenticates. Tracks session activity and DAU/MAU.',
            'start_trial' => 'User begins a free trial. Key conversion signal.',
            'subscribe' => 'User creates a paid subscription. Primary revenue event.',
            'plan_upgrade' => 'User upgrades to a higher plan. Expansion revenue signal.',
            'plan_downgrade' => 'User downgrades their plan. Churn risk indicator.',
            'cancellation' => 'User cancels their subscription. Critical churn event.',
            'page_view' => 'User views a page. Core engagement metric.',
            'purchase' => 'User completes an e-commerce purchase. Primary revenue event.',
            'payment_succeeded' => 'Payment transaction succeeded. Revenue confirmation.',
            'payment_failed' => 'Payment transaction failed. Dunning trigger.',
            'trial_converted' => 'User converts from trial to paid. Key growth metric.',
            'feature_used' => 'User interacts with a feature. Product engagement signal.',
            'onboarding_step' => 'User completes an onboarding step. Activation funnel.',
            'onboarding_completed' => 'User completes the full onboarding flow. Activation milestone.',
            'error' => 'Application error occurred. Product health signal.',
            'search' => 'User performs a search. Intent and discovery signal.',
            'form_submit' => 'User submits a form. Conversion signal.',
            'share' => 'User shares content. Referral and viral coefficient signal.',
            'revenue_tracked' => 'Revenue event tracked. Aggregate revenue metric.',
            'subscription_renewal' => 'Subscription renewed. Retention signal.',
            'milestone_reached' => 'User reaches a usage milestone. Activation signal.',
            'email_verified' => 'User verifies their email. Account quality signal.',
        ];

        return $descriptions[$name] ?? "Analytics event: {$name}";
    }
}
