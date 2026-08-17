<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaSStarterEvents;

/**
 * SaaS Starter Instrumentation Wizard — generates copy-paste code snippets
 * for each of the 20 essential SaaS analytics events.
 *
 * Provides PHP server-side, JavaScript client-side, and Blade template
 * snippets for every event in the starter set, along with the required
 * parameters and provider mappings (GA4, Meta, PostHog).
 *
 * Designed for the `zb:analytics:starter --snippets` command and for
 * Inertia props injection during onboarding.
 *
 * @since 211.0.0
 *
 * @see \ZeroBoiler\Analytics\Events\SaaSStarterEvents
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 */
final class SaaSStarterInstrumentationService
{
    /**
     * Instrumentation templates for each of the 20 starter events.
     *
     * Each entry contains:
     * - `params`: Required/optional parameter definitions with types
     * - `php`: Server-side PHP snippet using the Facade
     * - `js`: Client-side JS snippet using analytics.js
     * - `blade`: Blade directive snippet
     *
     * @return array<string, array{params: list<array{name: string, type: string, required: bool, description: string}>, php: string, js: string, blade: string}>
     */
    public static function snippets(): array
    {
        return [
            // ── SaaS Lifecycle (8 events) ──────────────────────────
            'sign_up' => [
                'params' => [
                    ['name' => 'method', 'type' => 'string', 'required' => false, 'description' => 'Registration method (email, google, github)'],
                ],
                'php' => <<<'PHP'
use ZeroBoiler\Analytics\Facades\Analytics;

Analytics::track('sign_up', [
    'method' => $user->provider ?? 'email',
]);
PHP,
                'js' => <<<'JS'
import { trackEvent } from '@zeroboiler/analytics';

trackEvent('sign_up', { method: 'email' });
JS,
                'blade' => <<<'BLADE'
@analytics('sign_up', ['method' => $user->provider ?? 'email'])
BLADE,
            ],

            'login' => [
                'params' => [
                    ['name' => 'method', 'type' => 'string', 'required' => false, 'description' => 'Login method (email, sso, oauth)'],
                ],
                'php' => <<<'PHP'
Analytics::track('login', [
    'method' => $request->attributes->get('auth_method', 'email'),
]);
PHP,
                'js' => <<<'JS'
trackEvent('login', { method: 'email' });
JS,
                'blade' => <<<'BLADE'
@analytics('login', ['method' => 'email'])
BLADE,
            ],

            'start_trial' => [
                'params' => [
                    ['name' => 'plan_name', 'type' => 'string', 'required' => true, 'description' => 'Trial plan name (e.g. Pro Trial)'],
                    ['name' => 'trial_days', 'type' => 'int', 'required' => false, 'description' => 'Number of trial days'],
                ],
                'php' => <<<'PHP'
Analytics::track('start_trial', [
    'plan_name'  => $subscription->plan->name,
    'trial_days' => $subscription->trialDays(),
]);
PHP,
                'js' => <<<'JS'
trackEvent('start_trial', { plan_name: 'Pro Trial', trial_days: 14 });
JS,
                'blade' => <<<'BLADE'
@analytics('start_trial', ['plan_name' => $plan->name, 'trial_days' => $plan->trial_days])
BLADE,
            ],

            'trial_converted' => [
                'params' => [
                    ['name' => 'plan_name', 'type' => 'string', 'required' => false, 'description' => 'Converted plan name'],
                    ['name' => 'amount', 'type' => 'float', 'required' => false, 'description' => 'Conversion amount'],
                    ['name' => 'currency', 'type' => 'string', 'required' => false, 'description' => 'Currency code (ISO 4217)'],
                ],
                'php' => <<<'PHP'
Analytics::track('trial_converted', [
    'plan_name' => $subscription->plan->name,
    'amount'    => $subscription->amount,
    'currency'  => $subscription->currency,
]);
PHP,
                'js' => <<<'JS'
trackEvent('trial_converted', { plan_name: 'Pro', amount: 29.99, currency: 'USD' });
JS,
                'blade' => <<<'BLADE'
@analytics('trial_converted', ['plan_name' => $plan->name, 'amount' => $amount, 'currency' => 'USD'])
BLADE,
            ],

            'subscribe' => [
                'params' => [
                    ['name' => 'plan_name', 'type' => 'string', 'required' => true, 'description' => 'Subscription plan name'],
                    ['name' => 'amount', 'type' => 'float', 'required' => true, 'description' => 'Subscription amount'],
                    ['name' => 'currency', 'type' => 'string', 'required' => false, 'description' => 'Currency code'],
                    ['name' => 'billing_cycle', 'type' => 'string', 'required' => false, 'description' => 'Billing cycle (monthly, yearly)'],
                ],
                'php' => <<<'PHP'
Analytics::track('subscribe', [
    'plan_name'     => $subscription->plan->name,
    'amount'        => $subscription->amount,
    'currency'      => $subscription->currency,
    'billing_cycle' => $subscription->billingCycle,
]);
PHP,
                'js' => <<<'JS'
trackEvent('subscribe', { plan_name: 'Pro', amount: 29.99, currency: 'USD', billing_cycle: 'monthly' });
JS,
                'blade' => <<<'BLADE'
@analytics('subscribe', ['plan_name' => $plan, 'amount' => $amount, 'currency' => 'USD', 'billing_cycle' => 'monthly'])
BLADE,
            ],

            'plan_upgrade' => [
                'params' => [
                    ['name' => 'from_plan', 'type' => 'string', 'required' => true, 'description' => 'Previous plan name'],
                    ['name' => 'to_plan', 'type' => 'string', 'required' => true, 'description' => 'New plan name'],
                    ['name' => 'amount', 'type' => 'float', 'required' => false, 'description' => 'New plan amount'],
                ],
                'php' => <<<'PHP'
Analytics::planUpgrade($previousPlan, $newPlan, [
    'amount' => $newPlan->price,
]);
PHP,
                'js' => <<<'JS'
trackEvent('plan_upgrade', { from_plan: 'Starter', to_plan: 'Pro', amount: 29.99 });
JS,
                'blade' => <<<'BLADE'
@analytics('plan_upgrade', ['from_plan' => $oldPlan, 'to_plan' => $newPlan, 'amount' => $newPrice])
BLADE,
            ],

            'cancellation' => [
                'params' => [
                    ['name' => 'plan_name', 'type' => 'string', 'required' => false, 'description' => 'Cancelled plan name'],
                    ['name' => 'reason', 'type' => 'string', 'required' => false, 'description' => 'Cancellation reason'],
                    ['name' => 'feedback', 'type' => 'string', 'required' => false, 'description' => 'User feedback text'],
                ],
                'php' => <<<'PHP'
Analytics::track('cancellation', [
    'plan_name' => $subscription->plan->name,
    'reason'    => $cancellation->reason,
    'feedback'  => $cancellation->feedback,
]);
PHP,
                'js' => <<<'JS'
trackEvent('cancellation', { plan_name: 'Pro', reason: 'too_expensive', feedback: '' });
JS,
                'blade' => <<<'BLADE'
@analytics('cancellation', ['plan_name' => $plan->name, 'reason' => $reason])
BLADE,
            ],

            'feature_used' => [
                'params' => [
                    ['name' => 'feature_name', 'type' => 'string', 'required' => true, 'description' => 'Feature name (e.g. dashboard, export)'],
                    ['name' => 'first_use', 'type' => 'bool', 'required' => false, 'description' => 'Whether this is the first time using the feature'],
                ],
                'php' => <<<'PHP'
Analytics::track('feature_used', [
    'feature_name' => 'dashboard',
    'first_use'   => $user->hasUsedFeature('dashboard') === false,
]);
PHP,
                'js' => <<<'JS'
trackEvent('feature_used', { feature_name: 'export_csv', first_use: true });
JS,
                'blade' => <<<'BLADE'
@analytics('feature_used', ['feature_name' => $feature, 'first_use' => $isFirstUse])
BLADE,
            ],

            // ── E-commerce (4 events) ────────────────────────────
            'view_item' => [
                'params' => [
                    ['name' => 'item_id', 'type' => 'string', 'required' => true, 'description' => 'Product/plan item ID'],
                    ['name' => 'item_name', 'type' => 'string', 'required' => false, 'description' => 'Product/plan name'],
                    ['name' => 'price', 'type' => 'float', 'required' => false, 'description' => 'Price'],
                    ['name' => 'currency', 'type' => 'string', 'required' => false, 'description' => 'Currency code'],
                ],
                'php' => <<<'PHP'
Analytics::track('view_item', [
    'item_id'   => $plan->id,
    'item_name' => $plan->name,
    'price'     => $plan->price,
    'currency'  => 'USD',
]);
PHP,
                'js' => <<<'JS'
trackEvent('view_item', { item_id: 'plan_pro', item_name: 'Pro Plan', price: 29.99, currency: 'USD' });
JS,
                'blade' => <<<'BLADE'
@analytics('view_item', ['item_id' => $plan->id, 'item_name' => $plan->name, 'price' => $plan->price, 'currency' => 'USD'])
BLADE,
            ],

            'add_to_cart' => [
                'params' => [
                    ['name' => 'item_id', 'type' => 'string', 'required' => true, 'description' => 'Item ID added'],
                    ['name' => 'item_name', 'type' => 'string', 'required' => false, 'description' => 'Item name'],
                    ['name' => 'price', 'type' => 'float', 'required' => false, 'description' => 'Item price'],
                    ['name' => 'quantity', 'type' => 'int', 'required' => false, 'description' => 'Quantity added'],
                ],
                'php' => <<<'PHP'
Analytics::track('add_to_cart', [
    'item_id'   => $item->id,
    'item_name' => $item->name,
    'price'     => $item->price,
    'quantity'  => $quantity,
]);
PHP,
                'js' => <<<'JS'
trackEvent('add_to_cart', { item_id: 'plan_pro', item_name: 'Pro Plan', price: 29.99, quantity: 1 });
JS,
                'blade' => <<<'BLADE'
@analytics('add_to_cart', ['item_id' => $item->id, 'item_name' => $item->name, 'price' => $item->price, 'quantity' => 1])
BLADE,
            ],

            'purchase' => [
                'params' => [
                    ['name' => 'transaction_id', 'type' => 'string', 'required' => true, 'description' => 'Unique transaction ID'],
                    ['name' => 'value', 'type' => 'float', 'required' => true, 'description' => 'Revenue / transaction value'],
                    ['name' => 'currency', 'type' => 'string', 'required' => false, 'description' => 'Currency code'],
                    ['name' => 'items', 'type' => 'array', 'required' => false, 'description' => 'Purchased items array'],
                ],
                'php' => <<<'PHP'
use ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent;

Analytics::trackEvent(new PurchaseEvent(
    transactionId: $order->id,
    value: $order->total,
    items: $order->items->map(fn ($item) => [
        'item_id' => $item->id,
        'item_name' => $item->name,
        'price' => $item->price,
        'quantity' => $item->quantity,
    ])->toArray(),
    currency: $order->currency,
));
PHP,
                'js' => <<<'JS'
trackEvent('purchase', {
    transaction_id: 'txn_12345',
    value: 99.99,
    currency: 'USD',
    items: [{ item_id: 'plan_pro', item_name: 'Pro Plan', price: 99.99, quantity: 1 }],
});
JS,
                'blade' => <<<'BLADE'
@analytics('purchase', ['transaction_id' => $order->id, 'value' => $order->total, 'currency' => 'USD'])
BLADE,
            ],

            'refund' => [
                'params' => [
                    ['name' => 'transaction_id', 'type' => 'string', 'required' => true, 'description' => 'Original transaction ID'],
                    ['name' => 'value', 'type' => 'float', 'required' => true, 'description' => 'Refund amount'],
                    ['name' => 'currency', 'type' => 'string', 'required' => false, 'description' => 'Currency code'],
                    ['name' => 'reason', 'type' => 'string', 'required' => false, 'description' => 'Refund reason'],
                ],
                'php' => <<<'PHP'
Analytics::track('refund', [
    'transaction_id' => $order->id,
    'value'          => $refund->amount,
    'currency'       => $refund->currency,
    'reason'         => $refund->reason,
]);
PHP,
                'js' => <<<'JS'
trackEvent('refund', { transaction_id: 'txn_12345', value: 99.99, currency: 'USD', reason: 'duplicate' });
JS,
                'blade' => <<<'BLADE'
@analytics('refund', ['transaction_id' => $refund->order_id, 'value' => $refund->amount, 'currency' => 'USD', 'reason' => $refund->reason])
BLADE,
            ],

            // ── Engagement (8 events) ───────────────────────────
            'page_view' => [
                'params' => [
                    ['name' => 'title', 'type' => 'string', 'required' => false, 'description' => 'Page title'],
                    ['name' => 'location', 'type' => 'string', 'required' => false, 'description' => 'Page URL'],
                    ['name' => 'referrer', 'type' => 'string', 'required' => false, 'description' => 'Referrer URL'],
                ],
                'php' => <<<'PHP'
Analytics::pageView(
    title: request()->route()?->getName() ?? 'Home',
    location: request()->fullUrl(),
    referrer: request()->headers->get('referer', ''),
);
PHP,
                'js' => <<<'JS'
// Auto-tracked via client autoTrack.pageViews — no manual code needed.
// Override: trackEvent('page_view', { title: 'Pricing', location: '/pricing' });
JS,
                'blade' => <<<'BLADE'
{{-- Auto-tracked via @analyticsScripts or client autoTrack --}}
JS,
            ],

            'scroll_depth' => [
                'params' => [
                    ['name' => 'percent', 'type' => 'int', 'required' => false, 'description' => 'Scroll depth percentage (25, 50, 75, 90)'],
                    ['name' => 'page_path', 'type' => 'string', 'required' => false, 'description' => 'Page path'],
                ],
                'php' => <<<'PHP'
Analytics::track('scroll_depth', [
    'percent'   => 75,
    'page_path' => request()->path(),
]);
PHP,
                'js' => <<<'JS'
// Auto-tracked via client autoTrack.scrollDepth (25%, 50%, 75%, 90% milestones).
// Or use the useScrollDepth() Svelte composable:
// const { scrollDepth } = useScrollDepth({ milestones: [25, 50, 75, 90] });
JS,
                'blade' => <<<'BLADE'
{{-- Auto-tracked via useScrollDepth composable or client autoTrack --}}
BLADE,
            ],

            'click' => [
                'params' => [
                    ['name' => 'element_id', 'type' => 'string', 'required' => false, 'description' => 'Clicked element ID'],
                    ['name' => 'element_text', 'type' => 'string', 'required' => false, 'description' => 'Clicked element text'],
                    ['name' => 'element_type', 'type' => 'string', 'required' => false, 'description' => 'Element type (button, link, nav)'],
                    ['name' => 'url', 'type' => 'string', 'required' => false, 'description' => 'Link URL (if applicable)'],
                ],
                'php' => <<<'PHP'
Analytics::track('click', [
    'element_id'    => 'cta-upgrade',
    'element_text'  => 'Upgrade to Pro',
    'element_type'  => 'button',
    'url'           => '/pricing',
]);
PHP,
                'js' => <<<'JS'
trackEvent('click', {
    element_id: 'cta-upgrade',
    element_text: 'Upgrade to Pro',
    element_type: 'button',
    url: '/pricing',
});
JS,
                'blade' => <<<'BLADE'
<button onclick="trackEvent('click', { element_id: 'cta-upgrade', element_text: 'Upgrade to Pro', element_type: 'button' })">
    Upgrade to Pro
</button>
BLADE,
            ],

            'form_start' => [
                'params' => [
                    ['name' => 'form_id', 'type' => 'string', 'required' => false, 'description' => 'Form identifier'],
                    ['name' => 'form_name', 'type' => 'string', 'required' => false, 'description' => 'Form name/type'],
                ],
                'php' => <<<'PHP'
Analytics::track('form_start', [
    'form_id'   => 'signup-form',
    'form_name' => 'registration',
]);
PHP,
                'js' => <<<'JS'
trackEvent('form_start', { form_id: 'signup-form', form_name: 'registration' });
JS,
                'blade' => <<<'BLADE'
<form onfocus="trackEvent('form_start', { form_id: 'signup-form', form_name: 'registration' })">
BLADE,
            ],

            'form_submit' => [
                'params' => [
                    ['name' => 'form_id', 'type' => 'string', 'required' => false, 'description' => 'Form identifier'],
                    ['name' => 'form_name', 'type' => 'string', 'required' => false, 'description' => 'Form name/type'],
                    ['name' => 'success', 'type' => 'bool', 'required' => false, 'description' => 'Whether submission was successful'],
                ],
                'php' => <<<'PHP'
Analytics::track('form_submit', [
    'form_id'   => 'signup-form',
    'form_name' => 'registration',
    'success'   => true,
]);
PHP,
                'js' => <<<'JS'
trackEvent('form_submit', { form_id: 'signup-form', form_name: 'registration', success: true });
JS,
                'blade' => <<<'BLADE'
@analytics('form_submit', ['form_id' => 'signup-form', 'form_name' => 'registration', 'success' => true])
BLADE,
            ],

            'search' => [
                'params' => [
                    ['name' => 'search_term', 'type' => 'string', 'required' => true, 'description' => 'Search query string'],
                    ['name' => 'results_count', 'type' => 'int', 'required' => false, 'description' => 'Number of results returned'],
                    ['name' => 'category', 'type' => 'string', 'required' => false, 'description' => 'Search category filter'],
                ],
                'php' => <<<'PHP'
Analytics::track('search', [
    'search_term'   => $request->input('q'),
    'results_count' => $results->count(),
    'category'      => $request->input('category'),
]);
PHP,
                'js' => <<<'JS'
trackEvent('search', { search_term: 'analytics dashboard', results_count: 5, category: 'features' });
JS,
                'blade' => <<<'BLADE'
@analytics('search', ['search_term' => $query, 'results_count' => $count])
BLADE,
            ],

            'share' => [
                'params' => [
                    ['name' => 'method', 'type' => 'string', 'required' => false, 'description' => 'Share method (email, twitter, linkedin, copy)'],
                    ['name' => 'content_type', 'type' => 'string', 'required' => false, 'description' => 'Shared content type'],
                    ['name' => 'item_id', 'type' => 'string', 'required' => false, 'description' => 'Shared item ID'],
                ],
                'php' => <<<'PHP'
Analytics::track('share', [
    'method'       => 'twitter',
    'content_type' => 'report',
    'item_id'      => $report->id,
]);
PHP,
                'js' => <<<'JS'
trackEvent('share', { method: 'twitter', content_type: 'report', item_id: 'report_123' });
JS,
                'blade' => <<<'BLADE'
@analytics('share', ['method' => 'twitter', 'content_type' => 'report', 'item_id' => $report->id])
BLADE,
            ],

            'error' => [
                'params' => [
                    ['name' => 'error_message', 'type' => 'string', 'required' => true, 'description' => 'Error message'],
                    ['name' => 'error_code', 'type' => 'string', 'required' => false, 'description' => 'Error code or type'],
                    ['name' => 'fatal', 'type' => 'bool', 'required' => false, 'description' => 'Whether error is fatal'],
                ],
                'php' => <<<'PHP'
Analytics::track('error', [
    'error_message' => $exception->getMessage(),
    'error_code'    => (string) $exception->getCode(),
    'fatal'         => false,
]);
PHP,
                'js' => <<<'JS'
// Auto-captured via client autoTrack.errorTracking (window.onerror + unhandledrejection).
// Manual: trackEvent('error', { error_message: 'API timeout', error_code: 'TIMEOUT', fatal: false });
JS,
                'blade' => <<<'BLADE'
{{-- Auto-captured via client autoTrack.errorTracking --}}
BLADE,
            ],
        ];
    }

    /**
     * Get instrumentation snippets for a specific starter event.
     *
     * @return array{params: list<array{name: string, type: string, required: bool, description: string}>, php: string, js: string, blade: string}|null
     */
    public static function snippetsFor(string $eventName): ?array
    {
        return self::snippets()[$eventName] ?? null;
    }

    /**
     * Get a client-safe instrumentation guide for the starter events.
     *
     * Returns a compact structure suitable for Inertia props or an API
     * response, containing event name, required params, and JS snippet
     * for each of the 20 starter events.
     *
     * @return array{total: int, events: list<array{name: string, label: string, category: string, hint: string, required_params: list<string>, js_snippet: string}>}
     *
     * @since 211.0.0
     */
    public static function clientGuide(): array
    {
        $starter = SaaSStarterEvents::all();
        $snippets = self::snippets();
        $events = [];

        foreach ($starter as $name => $entry) {
            $snippet = $snippets[$name] ?? null;
            $requiredParams = [];

            if ($snippet !== null) {
                foreach ($snippet['params'] as $param) {
                    if ($param['required']) {
                        $requiredParams[] = $param['name'];
                    }
                }
            }

            $events[] = [
                'name' => $entry['name'],
                'label' => $entry['label'],
                'category' => $entry['category'],
                'hint' => $entry['hint'],
                'required_params' => $requiredParams,
                'js_snippet' => $snippet['js'] ?? '// No snippet available',
            ];
        }

        return [
            'total' => count($events),
            'events' => $events,
        ];
    }

    /**
     * Get instrumentation coverage analysis.
     *
     * Returns a summary of which starter events have auto-tracking
     * support (no manual code needed) vs which require manual placement.
     *
     * @return array{auto_tracked: list<string>, manual: list<string>, coverage: float}
     *
     * @since 211.0.0
     */
    public static function coverageAnalysis(): array
    {
        $autoTracked = ['page_view', 'scroll_depth', 'error'];
        $all = SaaSStarterEvents::names();
        $manual = array_values(array_diff($all, $autoTracked));

        return [
            'auto_tracked' => $autoTracked,
            'manual' => $manual,
            'coverage' => self::autoCoveragePercent(),
        ];
    }

    /**
     * Calculate auto-tracking coverage percentage.
     *
     * Returns what fraction of the 20 starter events are auto-tracked
     * by the client library (no manual code needed).
     */
    public static function autoCoveragePercent(): float
    {
        $autoTracked = 3; // page_view, scroll_depth, error
        $total = SaaSStarterEvents::count();

        if ($total === 0) {
            return 0.0;
        }

        return round(($autoTracked / $total) * 100.0, 1);
    }

    /**
     * Get the starter event instrumentation completeness score.
     *
     * Evaluates:
     * - All 20 events have snippet entries
     * - All events exist in EventCatalog
     * - All events have required params documented
     * - All events have JS snippets
     *
     * @return array{score: int, max: int, details: array<string, bool>}
     *
     * @since 211.0.0
     */
    public static function completenessScore(): array
    {
        $snippets = self::snippets();
        $all = SaaSStarterEvents::names();
        $details = [];
        $score = 0;
        $max = 0;

        foreach ($all as $name) {
            $hasSnippets = isset($snippets[$name]);
            $hasCatalog = EventCatalog::has($name);
            $hasParams = $hasSnippets && count($snippets[$name]['params']) > 0;
            $hasJs = $hasSnippets && $snippets[$name]['js'] !== '';

            $max += 4;
            if ($hasSnippets) {
                $score++;
            }
            if ($hasCatalog) {
                $score++;
            }
            if ($hasParams) {
                $score++;
            }
            if ($hasJs) {
                $score++;
            }

            $details[$name] = $hasSnippets && $hasCatalog && $hasParams && $hasJs;
        }

        return [
            'score' => $score,
            'max' => $max,
            'details' => $details,
        ];
    }
}
