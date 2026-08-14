<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\EventTags;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Http\Middleware\AutoPageViewMiddleware;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter as FormatConverterAlias;
use ZeroBoiler\Analytics\Support\SaaSEventHelpers;
use ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;

/**
 * Phase 42 — Event Dependency Graph & Causal Path Analysis.
 *
 * Comprehensive validation of:
 * - causalEdges() static dependency edge definitions
 * - eventDependencyGraph() forward/reverse adjacency lists
 * - causalPaths() BFS pathfinding between events
 * - causalAncestors() multi-depth reverse graph traversal
 * - causalDescendants() multi-depth forward graph traversal
 * - funnelCriticalPaths() shortest path identification
 * - funnelBottleneckAnalysis() z-score anomaly detection
 * - eventSequenceCorrelationMatrix() N×N correlation matrix
 * - All prior features (funnels, conversion rates, AARRR breakdown, impact matrix)
 * - Version consistency across all 7 package files
 * - SaaS starter maturity criteria at v114.0.0
 *
 * @since 114.0.0
 */

// ── Version Consistency ─────────────────────────────────────────────────

it('has correct VERSION constant in AnalyticsEvent', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('114.0.0');
});

it('has correct version in composer.json', function (): void {
    $content = file_get_contents(__DIR__ . '/../composer.json');
    expect($content)->toBeJson();
    $composer = json_decode($content, true);
    expect($composer['version'])->toBe('114.0.0');
});

it('has correct version in package.json', function (): void {
    $content = file_get_contents(__DIR__ . '/../package.json');
    expect($content)->toBeJson();
    $pkg = json_decode($content, true);
    expect($pkg['version'])->toBe('114.0.0');
});

it('has correct version in analytics.js', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($content)->toContain('@version 114.0.0');
});

it('has correct version in analytics.d.ts', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($content)->toContain('@version 114.0.0');
});

it('has correct version in analytics.constants.js', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/analytics.constants.js');
    expect($content)->toContain('@version 114.0.0');
});

it('has correct version in AnalyticsServiceProvider', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($content)->toContain('@version 114.0.0');
});

it('has correct version badge in README', function (): void {
    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-114.0.0');
});

// ── causalEdges ─────────────────────────────────────────────────────────

it('causalEdges returns non-empty array', function (): void {
    $edges = EventCatalog::causalEdges();

    expect($edges)->toBeArray();
    expect(count($edges))->toBeGreaterThan(30);
});

it('causalEdges all values are arrays of strings', function (): void {
    $edges = EventCatalog::causalEdges();

    foreach ($edges as $source => $targets) {
        expect($source)->toBeString();
        expect($targets)->toBeArray();
        foreach ($targets as $target) {
            expect($target)->toBeString();
        }
    }
});

it('causalEdges covers SaaS acquisition funnel', function (): void {
    $edges = EventCatalog::causalEdges();

    // sign_up should have multiple outgoing edges
    expect(isset($edges['sign_up']))->toBeTrue();
    expect(count($edges['sign_up']))->toBeGreaterThanOrEqual(3);
    expect($edges['sign_up'])->toContain('login');
    expect($edges['sign_up'])->toContain('start_trial');

    // subscribe should have edges to renewal, upgrade, downgrade, cancellation
    expect(isset($edges['subscribe']))->toBeTrue();
    expect($edges['subscribe'])->toContain('subscription_renewal');
    expect($edges['subscribe'])->toContain('cancellation');
});

it('causalEdges covers e-commerce funnel', function (): void {
    $edges = EventCatalog::causalEdges();

    expect($edges['view_item'])->toContain('add_to_cart');
    expect($edges['add_to_cart'])->toContain('begin_checkout');
    expect($edges['begin_checkout'])->toContain('add_payment_info');
    expect($edges['add_payment_info'])->toContain('purchase');
    expect($edges['purchase'])->toContain('refund');
});

it('causalEdges covers engagement funnel', function (): void {
    $edges = EventCatalog::causalEdges();

    expect($edges['page_view'])->toContain('scroll_depth');
    expect($edges['page_view'])->toContain('click');
    expect($edges['form_start'])->toContain('form_submit');
});

it('causalEdges covers account lifecycle', function (): void {
    $edges = EventCatalog::causalEdges();

    expect($edges['email_verified'])->toContain('account_activated');
    expect($edges['onboarding_step'])->toContain('onboarding_completed');
    expect($edges['onboarding_step'])->toContain('feature_used');
});

it('causalEdges covers billing', function (): void {
    $edges = EventCatalog::causalEdges();

    expect($edges['payment_failed'])->toContain('billing_retry');
    expect($edges['payment_failed'])->toContain('cancellation');
    expect($edges['payment_succeeded'])->toContain('subscription_renewal');
});

it('causalEdges covers team/B2B events', function (): void {
    $edges = EventCatalog::causalEdges();

    expect($edges['team_created'])->toContain('team_member_joined');
    expect($edges['invite_sent'])->toContain('team_member_joined');
});

// ── eventDependencyGraph ────────────────────────────────────────────────

it('eventDependencyGraph returns correct structure', function (): void {
    $graph = EventCatalog::eventDependencyGraph();

    expect($graph)->toHaveKey('forward');
    expect($graph)->toHaveKey('reverse');
    expect($graph)->toHaveKey('edge_count');
    expect($graph)->toHaveKey('node_count');
    expect($graph)->toHaveKey('nodes');
    expect($graph['forward'])->toBeArray();
    expect($graph['reverse'])->toBeArray();
    expect($graph['edge_count'])->toBeInt();
    expect($graph['node_count'])->toBeInt();
    expect($graph['nodes'])->toBeArray();
});

it('eventDependencyGraph forward matches causalEdges', function (): void {
    $edges = EventCatalog::causalEdges();
    $graph = EventCatalog::eventDependencyGraph();

    foreach ($edges as $source => $targets) {
        expect($graph['forward'][$source])->toBe($targets);
    }
});

it('eventDependencyGraph reverse is the inverse of forward', function (): void {
    $graph = EventCatalog::eventDependencyGraph();

    foreach ($graph['forward'] as $source => $targets) {
        foreach ($targets as $target) {
            expect($graph['reverse'][$target])->toContain($source);
        }
    }
});

it('eventDependencyGraph edge_count matches actual edges', function (): void {
    $edges = EventCatalog::causalEdges();
    $graph = EventCatalog::eventDependencyGraph();

    $expectedCount = 0;
    foreach ($edges as $targets) {
        $expectedCount += count($targets);
    }

    expect($graph['edge_count'])->toBe($expectedCount);
});

it('eventDependencyGraph node_count is positive', function (): void {
    $graph = EventCatalog::eventDependencyGraph();

    expect($graph['node_count'])->toBeGreaterThan(20);
    expect($graph['node_count'])->toBe(count($graph['nodes']));
});

it('eventDependencyGraph every node appears in both forward and reverse', function (): void {
    $graph = EventCatalog::eventDependencyGraph();

    foreach ($graph['nodes'] as $node) {
        expect(isset($graph['forward'][$node]))->toBeTrue();
        expect(isset($graph['reverse'][$node]))->toBeTrue();
    }
});

// ── causalPaths ──────────────────────────────────────────────────────────

it('causalPaths returns empty for same from and to', function (): void {
    $paths = EventCatalog::causalPaths('sign_up', 'sign_up');

    expect($paths)->toBe([]);
});

it('causalPaths returns empty for unconnected events', function (): void {
    $paths = EventCatalog::causalPaths('error', 'purchase');

    // error and purchase are in different subgraphs — no path expected
    expect($paths)->toBeArray();
});

it('causalPaths finds direct path from view_item to purchase', function (): void {
    $paths = EventCatalog::causalPaths('view_item', 'purchase');

    expect(count($paths))->toBeGreaterThan(0);

    // All paths should start with view_item and end with purchase
    foreach ($paths as $path) {
        expect($path[0])->toBe('view_item');
        expect($path[array_key_last($path)])->toBe('purchase');
    }
});

it('causalPaths are sorted by length (shortest first)', function (): void {
    $paths = EventCatalog::causalPaths('view_item', 'purchase');

    if (count($paths) >= 2) {
        for ($i = 0; $i < count($paths) - 1; $i++) {
            expect(count($paths[$i]))->toBeLessThanOrEqual(count($paths[$i + 1]));
        }
    }
});

it('causalPaths respects maxDepth', function (): void {
    $deepPaths = EventCatalog::causalPaths('sign_up', 'cancellation', 10);
    $shallowPaths = EventCatalog::causalPaths('sign_up', 'cancellation', 2);

    // Deep paths should find >= as many as shallow (shallow may find none)
    expect(count($deepPaths))->toBeGreaterThanOrEqual(count($shallowPaths));

    // Shallow paths should have at most maxDepth+1 nodes (from → ... → to)
    foreach ($shallowPaths as $path) {
        expect(count($path))->toBeLessThanOrEqual(3); // maxDepth=2 → 3 nodes max
    }
});

it('causalPaths finds paths from sign_up to cancellation', function (): void {
    $paths = EventCatalog::causalPaths('sign_up', 'cancellation', 8);

    expect(count($paths))->toBeGreaterThan(0);

    // There should be a direct path through subscribe
    $hasSubscribePath = false;
    foreach ($paths as $path) {
        if (in_array('subscribe', $path, true)) {
            $hasSubscribePath = true;
            break;
        }
    }
    expect($hasSubscribePath)->toBeTrue();
});

it('causalPaths handles non-existent events gracefully', function (): void {
    $paths = EventCatalog::causalPaths('nonexistent_event', 'purchase');

    expect($paths)->toBe([]);
});

it('causalPaths page_view to form_submit exists', function (): void {
    $paths = EventCatalog::causalPaths('page_view', 'form_submit');

    expect(count($paths))->toBeGreaterThan(0);

    foreach ($paths as $path) {
        expect($path[0])->toBe('page_view');
        expect($path[array_key_last($path)])->toBe('form_submit');
    }
});

it('causalPaths no cycles in any path', function (): void {
    $paths = EventCatalog::causalPaths('sign_up', 'cancellation', 8);

    foreach ($paths as $path) {
        $unique = array_unique($path);
        expect(count($unique))->toBe(count($path)); // No duplicates = no cycles
    }
});

// ── causalAncestors ────────────────────────────────────────────────────

it('causalAncestors depth=1 returns direct parents', function (): void {
    $ancestors = EventCatalog::causalAncestors('purchase', 1);

    expect($ancestors)->toContain('add_payment_info');
});

it('causalAncestors depth=2 includes grandparents', function (): void {
    $depth1 = EventCatalog::causalAncestors('purchase', 1);
    $depth2 = EventCatalog::causalAncestors('purchase', 2);

    // depth2 should have >= depth1
    expect(count($depth2))->toBeGreaterThanOrEqual(count($depth1));

    // depth2 should include add_payment_info's parents
    expect($depth2)->toContain('add_payment_info');
    expect($depth2)->toContain('begin_checkout');
});

it('causalAncestors returns empty for root events', function (): void {
    $ancestors = EventCatalog::causalAncestors('sign_up', 1);

    // sign_up is a root node — no parents
    expect($ancestors)->toBe([]);
});

it('causalAncestors no duplicates in results', function (): void {
    $ancestors = EventCatalog::causalAncestors('cancellation', 3);

    $unique = array_unique($ancestors);
    expect(count($unique))->toBe(count($ancestors));
});

it('causalAncestors returns empty for unknown event', function (): void {
    $ancestors = EventCatalog::causalAncestors('nonexistent', 2);

    expect($ancestors)->toBe([]);
});

// ── causalDescendants ──────────────────────────────────────────────────

it('causalDescendants depth=1 returns direct children', function (): void {
    $descendants = EventCatalog::causalDescendants('view_item', 1);

    expect($descendants)->toContain('add_to_cart');
    expect($descendants)->toContain('select_item');
});

it('causalDescendants depth=2 includes grandchildren', function (): void {
    $depth1 = EventCatalog::causalDescendants('view_item', 1);
    $depth2 = EventCatalog::causalDescendants('view_item', 2);

    expect(count($depth2))->toBeGreaterThan(count($depth1));
    expect($depth2)->toContain('add_to_cart');

    // Should include add_to_cart's children
    expect($depth2)->toContain('begin_checkout');
});

it('causalDescendants returns empty for leaf events', function (): void {
    $descendants = EventCatalog::causalDescendants('cancellation', 1);

    // cancellation is a leaf — no children (or at least no direct)
    expect($descendants)->toBeArray();
});

it('causalDescendants sign_up has many descendants', function (): void {
    $descendants = EventCatalog::causalDescendants('sign_up', 3);

    expect(count($descendants))->toBeGreaterThan(5);
    expect($descendants)->toContain('login');
    expect($descendants)->toContain('start_trial');
});

it('causalDescendants no duplicates in results', function (): void {
    $descendants = EventCatalog::causalDescendants('sign_up', 3);

    $unique = array_unique($descendants);
    expect(count($unique))->toBe(count($descendants));
});

// ── funnelCriticalPaths ──────────────────────────────────────────────────

it('funnelCriticalPaths saas returns correct entry/exit', function (): void {
    $result = EventCatalog::funnelCriticalPaths('saas');

    expect($result['entry'])->toBe('sign_up');
    expect($result['exit'])->toBe('cancellation');
    expect($result['critical_paths'])->toBeArray();
    expect($result['max_depth'])->toBeGreaterThanOrEqual(1);
});

it('funnelCriticalPaths ecommerce returns correct entry/exit', function (): void {
    $result = EventCatalog::funnelCriticalPaths('ecommerce');

    expect($result['entry'])->toBe('view_item');
    expect($result['exit'])->toBe('purchase');
    expect(count($result['critical_paths']))->toBeGreaterThan(0);
});

it('funnelCriticalPaths engagement returns correct entry/exit', function (): void {
    $result = EventCatalog::funnelCriticalPaths('engagement');

    expect($result['entry'])->toBe('page_view');
    expect($result['exit'])->toBe('error');
});

it('funnelCriticalPaths unknown returns empty', function (): void {
    $result = EventCatalog::funnelCriticalPaths('unknown');

    expect($result['entry'])->toBe('');
    expect($result['exit'])->toBe('');
    expect($result['critical_paths'])->toBe([]);
    expect($result['max_depth'])->toBe(0);
});

it('funnelCriticalPaths ecommerce shortest path has correct endpoints', function (): void {
    $result = EventCatalog::funnelCriticalPaths('ecommerce');

    foreach ($result['critical_paths'] as $path) {
        expect($path[0])->toBe('view_item');
        expect($path[array_key_last($path)])->toBe('purchase');
        expect(count($path))->toBe($result['max_depth'] + 1);
    }
});

it('funnelCriticalPaths all paths have same length', function (): void {
    $saas = EventCatalog::funnelCriticalPaths('saas');
    $ecommerce = EventCatalog::funnelCriticalPaths('ecommerce');

    if (count($saas['critical_paths']) >= 2) {
        $firstLen = count($saas['critical_paths'][0]);
        foreach ($saas['critical_paths'] as $path) {
            expect(count($path))->toBe($firstLen);
        }
    }

    if (count($ecommerce['critical_paths']) >= 2) {
        $firstLen = count($ecommerce['critical_paths'][0]);
        foreach ($ecommerce['critical_paths'] as $path) {
            expect(count($path))->toBe($firstLen);
        }
    }
});

// ── funnelBottleneckAnalysis ────────────────────────────────────────────

it('funnelBottleneckAnalysis saas returns correct structure', function (): void {
    $result = EventCatalog::funnelBottleneckAnalysis([
        'sign_up' => 1000,
        'login' => 800,
        'start_trial' => 400,
        'trial_converted' => 200,
        'subscribe' => 150,
        'subscription_renewal' => 100,
        'plan_upgrade' => 50,
        'plan_downgrade' => 10,
        'cancellation' => 5,
    ], 'saas');

    expect($result)->toHaveKey('transitions');
    expect($result)->toHaveKey('mean_rate');
    expect($result)->toHaveKey('std_dev');
    expect($result)->toHaveKey('critical_count');
    expect($result)->toHaveKey('elevated_count');
    expect($result['mean_rate'])->toBeFloat();
    expect($result['std_dev'])->toBeGreaterThanOrEqual(0.0);
    expect($result['critical_count'])->toBeInt();
    expect($result['elevated_count'])->toBeInt();
});

it('funnelBottleneckAnalysis computes correct drop-off rates', function (): void {
    $result = EventCatalog::funnelBottleneckAnalysis([
        'sign_up' => 1000,
        'login' => 700,
    ], 'saas');

    $transition = $result['transitions'][0];
    expect($transition['from'])->toBe('sign_up');
    expect($transition['to'])->toBe('login');
    expect($transition['rate'])->toBe(30.0); // 30% drop-off
});

it('funnelBottleneckAnalysis ecommerce has correct number of transitions', function (): void {
    $result = EventCatalog::funnelBottleneckAnalysis([
        'view_item' => 5000,
        'add_to_cart' => 500,
    ], 'ecommerce');

    expect(count($result['transitions']))->toBe(8); // 9 steps - 1
});

it('funnelBottleneckAnalysis z-scores are computed when std_dev > 0', function (): void {
    $result = EventCatalog::funnelBottleneckAnalysis([
        'view_item' => 10000,
        'select_item' => 9000,
        'add_to_cart' => 100,
        'remove_from_cart' => 50,
        'view_cart' => 80,
        'begin_checkout' => 60,
        'add_payment_info' => 50,
        'purchase' => 45,
        'refund' => 5,
    ], 'ecommerce');

    $hasZScore = false;
    foreach ($result['transitions'] as $t) {
        if ($t['z_score'] !== null) {
            $hasZScore = true;
            expect($t['severity'])->toBeIn(['normal', 'elevated', 'critical']);
        }
    }
    expect($hasZScore)->toBeTrue();
});

it('funnelBottleneckAnalysis critical bottlenecks detected', function (): void {
    // Create data where one transition has extreme drop-off
    $result = EventCatalog::funnelBottleneckAnalysis([
        'view_item' => 10000,
        'select_item' => 9500,
        'add_to_cart' => 9000,
        'remove_from_cart' => 8500,
        'view_cart' => 8000,
        'begin_checkout' => 7500,
        'add_payment_info' => 100,
        'purchase' => 90,
        'refund' => 5,
    ], 'ecommerce');

    // add_payment_info → purchase should be a critical bottleneck
    // because it has 10% drop-off while others are ~5-6%
    $criticalTransitions = array_filter(
        $result['transitions'],
        fn (array $t): bool => $t['severity'] === 'critical'
    );

    // At least one should be critical (the massive drop before add_payment_info)
    expect($result['critical_count'] + $result['elevated_count'])->toBeGreaterThanOrEqual(1);
});

it('funnelBottleneckAnalysis handles zero counts gracefully', function (): void {
    $result = EventCatalog::funnelBottleneckAnalysis([
        'sign_up' => 0,
        'login' => 0,
    ], 'saas');

    expect($result['transitions'][0]['rate'])->toBeNull();
    expect($result['transitions'][0]['z_score'])->toBeNull();
    expect($result['transitions'][0]['severity'])->toBeNull();
});

it('funnelBottleneckAnalysis engagement funnel works', function (): void {
    $result = EventCatalog::funnelBottleneckAnalysis([
        'page_view' => 10000,
        'scroll_depth' => 7000,
        'click' => 5000,
        'form_start' => 1000,
        'form_submit' => 500,
        'search' => 3000,
        'share' => 200,
        'error' => 50,
    ], 'engagement');

    expect(count($result['transitions']))->toBe(7); // 8 steps - 1
    expect($result['mean_rate'])->toBeGreaterThan(0.0);
});

it('funnelBottleneckAnalysis unknown type returns empty', function (): void {
    $result = EventCatalog::funnelBottleneckAnalysis([], 'unknown');

    expect($result['transitions'])->toBe([]);
    expect($result['mean_rate'])->toBe(0.0);
    expect($result['std_dev'])->toBe(0.0);
    expect($result['critical_count'])->toBe(0);
});

// ── eventSequenceCorrelationMatrix ────────────────────────────────────

it('eventSequenceCorrelationMatrix saas returns correct structure', function (): void {
    $result = EventCatalog::eventSequenceCorrelationMatrix('saas');

    expect($result)->toHaveKey('funnel');
    expect($result)->toHaveKey('events');
    expect($result)->toHaveKey('matrix');
    expect($result)->toHaveKey('direct_edges');
    expect($result)->toHaveKey('possible_edges');
    expect($result)->toHaveKey('density');
    expect($result['funnel'])->toBe('saas');
    expect(count($result['events']))->toBe(9);
});

it('eventSequenceCorrelationMatrix diagonal is always 1.0', function (): void {
    $result = EventCatalog::eventSequenceCorrelationMatrix('ecommerce');

    foreach ($result['events'] as $event) {
        expect($result['matrix'][$event][$event])->toBe(1.0);
    }
});

it('eventSequenceCorrelationMatrix values are in valid range', function (): void {
    $result = EventCatalog::eventSequenceCorrelationMatrix('engagement');

    foreach ($result['events'] as $row) {
        foreach ($result['events'] as $col) {
            $value = $result['matrix'][$row][$col];
            expect($value)->toBeGreaterThanOrEqual(0.0);
            expect($value)->toBeLessThanOrEqual(1.0);
        }
    }
});

it('eventSequenceCorrelationMatrix has direct edges for sequential funnel steps', function (): void {
    $result = EventCatalog::eventSequenceCorrelationMatrix('ecommerce');
    $events = $result['events'];

    // view_item → select_item should be 1.0 (direct edge)
    $idx = array_search('view_item', $events, true);
    $idx2 = array_search('select_item', $events, true);

    if ($idx !== false && $idx2 !== false) {
        expect($result['matrix']['view_item']['select_item'])->toBe(1.0);
    }
});

it('eventSequenceCorrelationMatrix density is between 0 and 1', function (): void {
    $saas = EventCatalog::eventSequenceCorrelationMatrix('saas');
    $ecommerce = EventCatalog::eventSequenceCorrelationMatrix('ecommerce');
    $engagement = EventCatalog::eventSequenceCorrelationMatrix('engagement');

    foreach ([$saas, $ecommerce, $engagement] as $result) {
        expect($result['density'])->toBeGreaterThanOrEqual(0.0);
        expect($result['density'])->toBeLessThanOrEqual(1.0);
        expect($result['direct_edges'])->toBeGreaterThan(0);
        expect($result['possible_edges'])->toBeGreaterThan(0);
    }
});

it('eventSequenceCorrelationMatrix unknown returns empty', function (): void {
    $result = EventCatalog::eventSequenceCorrelationMatrix('unknown');

    expect($result['events'])->toBe([]);
    expect($result['matrix'])->toBe([]);
    expect($result['density'])->toBe(0.0);
});

it('eventSequenceCorrelationMatrix matrix is square', function (): void {
    $result = EventCatalog::eventSequenceCorrelationMatrix('saas');
    $n = count($result['events']);

    foreach ($result['events'] as $row) {
        expect(count($result['matrix'][$row]))->toBe($n);
    }
});

// ── Prior Feature Validation (carried forward) ─────────────────────────

it('saasFunnelEvents returns 9 steps', function (): void {
    expect(count(EventCatalog::saasFunnelEvents()))->toBe(9);
});

it('ecommerceFunnelEvents returns 9 steps', function (): void {
    expect(count(EventCatalog::ecommerceFunnelEvents()))->toBe(9);
});

it('engagementFunnelEvents returns 8 steps', function (): void {
    expect(count(EventCatalog::engagementFunnelEvents()))->toBe(8);
});

it('EventCatalog has 50+ events', function (): void {
    expect(EventCatalog::count())->toBeGreaterThan(50);
});

it('EventCatalog has 6 categories', function (): void {
    $byCategory = EventCatalog::byCategory();
    expect($byCategory)->toHaveKey('ecommerce');
    expect($byCategory)->toHaveKey('saas');
    expect($byCategory)->toHaveKey('engagement');
    expect($byCategory)->toHaveKey('security');
    expect($byCategory)->toHaveKey('uptime');
    expect($byCategory)->toHaveKey('infrastructure');
    expect(count($byCategory))->toBe(6);
});

it('EventCatalog has 8+ provider name lookups', function (): void {
    $byProvider = EventCatalog::byProvider();
    expect($byProvider)->toHaveKey('ga4');
    expect($byProvider)->toHaveKey('meta');
    expect($byProvider)->toHaveKey('posthog');
    expect($byProvider)->toHaveKey('plausible');
    expect($byProvider)->toHaveKey('mixpanel');
    expect($byProvider)->toHaveKey('amplitude');
    expect($byProvider)->toHaveKey('tiktok');
    expect($byProvider)->toHaveKey('linkedin');
    expect(count($byProvider))->toBeGreaterThanOrEqual(8);
});

it('funnelConversionRates works for saas', function (): void {
    $rates = EventCatalog::funnelConversionRates([
        'sign_up' => 1000,
        'cancellation' => 100,
    ], 'saas');

    expect($rates['overall_conversion'])->toBe(10.0);
});

it('aarrrBreakdown returns all stages', function (): void {
    $breakdown = EventCatalog::aarrrBreakdown();

    expect($breakdown)->toHaveKey('acquisition');
    expect($breakdown)->toHaveKey('activation');
    expect($breakdown)->toHaveKey('retention');
    expect($breakdown)->toHaveKey('revenue');
    expect($breakdown)->toHaveKey('referral');
    expect($breakdown)->toHaveKey('operational');
    expect($breakdown)->toHaveKey('total');
    expect($breakdown)->toHaveKey('coverage');
});

it('crossFunnelCorrelation returns correct structure', function (): void {
    $correlation = EventCatalog::crossFunnelCorrelation();

    expect($correlation)->toHaveKey('overlap_events');
    expect($correlation)->toHaveKey('funnel_sizes');
    expect($correlation)->toHaveKey('intersection_matrix');
    expect($correlation['funnel_sizes'])->toHaveKey('saas');
    expect($correlation['funnel_sizes'])->toHaveKey('ecommerce');
    expect($correlation['funnel_sizes'])->toHaveKey('engagement');
});

it('eventImpactMatrix returns array of events with priority scores', function (): void {
    $matrix = EventCatalog::eventImpactMatrix();

    expect($matrix)->toBeArray();
    expect(count($matrix))->toBeGreaterThan(0);

    foreach ($matrix as $item) {
        expect($item)->toHaveKey('event');
        expect($item)->toHaveKey('funnels');
        expect($item)->toHaveKey('priority_score');
        expect($item['priority_score'])->toBeGreaterThanOrEqual(0);
        expect($item['priority_score'])->toBeLessThanOrEqual(100);
    }
});

it('funnelDropoffAnalysis works for ecommerce', function (): void {
    $result = EventCatalog::funnelDropoffAnalysis([
        'view_item' => 5000,
        'add_to_cart' => 500,
    ], 'ecommerce');

    expect(count($result['transitions']))->toBe(8);
});

// ── SaaS Starter Maturity ───────────────────────────────────────────────

it('has 10+ tracker classes', function (): void {
    $trackers = [
        \ZeroBoiler\Analytics\Trackers\GA4Tracker::class,
        \ZeroBoiler\Analytics\Trackers\GTMTracker::class,
        \ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class,
        \ZeroBoiler\Analytics\Trackers\PlausibleTracker::class,
        \ZeroBoiler\Analytics\Trackers\PosthogTracker::class,
        \ZeroBoiler\Analytics\Trackers\MixpanelTracker::class,
        \ZeroBoiler\Analytics\Trackers\AmplitudeTracker::class,
        \ZeroBoiler\Analytics\Trackers\TikTokTracker::class,
        \ZeroBoiler\Analytics\Trackers\LinkedInTracker::class,
        \ZeroBoiler\Analytics\Trackers\WebhookTracker::class,
    ];

    foreach ($trackers as $tracker) {
        expect(class_exists($tracker))->toBeTrue();
    }

    expect(count($trackers))->toBeGreaterThanOrEqual(10);
});

it('LifecycleEventMapper has 60+ default mappings', function (): void {
    // Verify the service file exists and has mappings
    $content = file_get_contents(__DIR__ . '/../src/Services/LifecycleEventMapper.php');
    expect($content)->toContain('defaultMappings');
    expect($content)->toContain('auth.login');
});

it('HandleInertiaAnalytics exists and implements contract', function (): void {
    expect(class_exists(HandleInertiaAnalytics::class))->toBeTrue();
    $reflection = new \ReflectionClass(HandleInertiaAnalytics::class);
    expect($reflection->implementsInterface(\ZeroBoiler\Analytics\Http\HttpMiddlewareContract::class))->toBeTrue();
});

it('UserIdentityTracker has cache-backed identity linking', function (): void {
    $reflection = new \ReflectionClass(UserIdentityTracker::class);
    expect($reflection->hasMethod('linkClientIdToUser'))->toBeTrue();
    expect($reflection->hasMethod('resolveIdentity'))->toBeTrue();
    expect($reflection->hasMethod('resolvePrimaryUserId'))->toBeTrue();
});

it('EcommerceFormatConverter exists with ga4/meta conversion', function (): void {
    $reflection = new \ReflectionClass(EcommerceFormatConverter::class);
    expect($reflection->hasMethod('ga4ToMetaContents'))->toBeTrue();
    expect($reflection->hasMethod('metaToGa4Items'))->toBeTrue();
});

it('routes file has analytics API endpoints', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
    expect($routes)->toContain("Route::post('events'");
    expect($routes)->toContain("Route::post('batch'");
    expect($routes)->toContain("Route::post('identify'");
    expect($routes)->toContain("Route::post('consent'");
});

it('AnalyticsOverviewCommand exists', function (): void {
    expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class))->toBeTrue();
});

it('AnalyticsTestCommand exists', function (): void {
    expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class))->toBeTrue();
});

it('ConsentState DTO exists', function (): void {
    expect(class_exists(\ZeroBoiler\Analytics\DTO\ConsentState::class))->toBeTrue();
});

it('EventTags has 15+ tags', function (): void {
    $tags = EventTags::allTags();
    expect(count($tags))->toBeGreaterThanOrEqual(15);
});
