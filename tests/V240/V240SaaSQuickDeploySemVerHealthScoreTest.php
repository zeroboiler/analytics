<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaSStarterEvents;
use ZeroBoiler\Analytics\Services\CustomerHealthScoreService;
use ZeroBoiler\Analytics\Services\EventCatalogSemVerService;

beforeEach(function (): void {
    // Ensure config is available
    config_set([
        'zeroboiler.analytics.ga4.enabled' => true,
        'zeroboiler.analytics.ga4.measurement_id' => 'G-TEST',
        'zeroboiler.analytics.ga4.api_secret' => 'secret',
        'zeroboiler.analytics.gtm.enabled' => false,
        'zeroboiler.analytics.gtm.container_id' => '',
        'zeroboiler.analytics.meta_pixel.enabled' => false,
        'zeroboiler.analytics.meta_pixel.id' => '',
        'zeroboiler.analytics.plausible.enabled' => false,
        'zeroboiler.analytics.plausible.domain' => '',
        'zeroboiler.analytics.posthog.enabled' => false,
        'zeroboiler.analytics.posthog.host' => '',
        'zeroboiler.analytics.mixpanel.enabled' => false,
        'zeroboiler.analytics.amplitude.enabled' => false,
        'zeroboiler.analytics.tiktok.enabled' => false,
        'zeroboiler.analytics.linkedin.enabled' => false,
        'zeroboiler.analytics.webhook.enabled' => false,
        'zeroboiler.analytics.consent.default' => 'granted',
        'zeroboiler.analytics.consent.purposes' => [
            'necessary' => ['label' => 'Necessary', 'required' => true, 'default' => true],
            'analytics' => ['label' => 'Analytics', 'required' => false, 'default' => true],
            'marketing' => ['label' => 'Marketing', 'required' => false, 'default' => false],
            'functional' => ['label' => 'Functional', 'required' => false, 'default' => true],
        ],
        'zeroboiler.analytics.lifecycle.enabled' => true,
        'zeroboiler.analytics.lifecycle.custom_mappings' => [],
        'zeroboiler.analytics.queue.enabled' => true,
        'zeroboiler.analytics.queue.queue' => 'analytics',
        'zeroboiler.analytics.api.enabled' => true,
        'zeroboiler.analytics.api.base_url' => '/api/analytics',
        'zeroboiler.analytics.identity.cookie_name' => 'zb_analytics_id',
        'zeroboiler.analytics.identity.auto_link' => true,
        'zeroboiler.analytics.client_auto_track.page_views' => true,
        'zeroboiler.analytics.client_auto_track.scroll_depth' => true,
        'zeroboiler.analytics.client_auto_track.form_tracking' => true,
        'zeroboiler.analytics.client_auto_track.error_tracking' => true,
        'zeroboiler.analytics.saas_kpi_calc.enabled' => true,
        'zeroboiler.analytics.saas_kpi_calc.mrr_goal' => 10000,
        'zeroboiler.analytics.revenue.subscription_tiers' => [],
        'zeroboiler.analytics.ecommerce.currency' => 'USD',
        'zeroboiler.analytics.ecommerce.brand' => '',
    ]);
});

describe('v240.0.0 — Event Catalog SemVer Service', function (): void {
    it('returns a valid SemVer string', function (): void {
        $service = new EventCatalogSemVerService;

        $version = $service->currentVersion();

        expect($version)->toBeString();
        expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
    });

    it('returns a summary with required fields', function (): void {
        $service = new EventCatalogSemVerService;

        $summary = $service->summary();

        expect($summary)->toBeArray();
        expect($summary)->toHaveKey('version');
        expect($summary)->toHaveKey('package_version');
        expect($summary)->toHaveKey('events_count');
        expect($summary)->toHaveKey('categories_count');
        expect($summary)->toHaveKey('providers_count');
        expect($summary)->toHaveKey('hash');
        expect($summary['events_count'])->toBeGreaterThanOrEqual(100);
        expect($summary['categories_count'])->toBeGreaterThanOrEqual(5);
        expect($summary['providers_count'])->toBe(10);
    });

    it('provides diff with correct change type detection', function (): void {
        $service = new EventCatalogSemVerService;

        $sameDiff = $service->diff('1.0.0', '1.0.0');
        expect($sameDiff['type'])->toBe('none');

        $minorDiff = $service->diff('1.0.0', '1.5.0');
        expect($minorDiff['type'])->toBe('minor');

        $majorDiff = $service->diff('1.5.0', '2.0.0');
        expect($majorDiff['type'])->toBe('major');

        $patchDiff = $service->diff('1.5.0', '1.5.3');
        expect($patchDiff['type'])->toBe('patch');
    });

    it('invalidates cached version', function (): void {
        $service = new EventCatalogSemVerService;

        $before = $service->currentVersion();
        $service->invalidate();
        // After invalidation, should recompute (same result but fresh computation)
        $after = $service->currentVersion();

        expect($after)->toBe($before);
    });

    it('tracks version history', function (): void {
        $service = new EventCatalogSemVerService;

        $service->invalidate();
        $service->currentVersion(); // Generate first entry
        $history = $service->history();

        expect($history)->toBeArray();
        expect(count($history))->toBeGreaterThanOrEqual(1);
        expect($history[0])->toHaveKey('version');
        expect($history[0])->toHaveKey('hash');
        expect($history[0])->toHaveKey('events_count');
        expect($history[0])->toHaveKey('timestamp');
    });

    it('clears history completely', function (): void {
        $service = new EventCatalogSemVerService;

        $service->currentVersion(); // Generate entry
        $service->clearHistory();
        $history = $service->history();

        expect($history)->toBeArray();
        expect($history)->toBeEmpty();
    });
});

describe('v240.0.0 — Customer Health Score Service', function (): void {
    it('computes a health score with required fields', function (): void {
        $service = new CustomerHealthScoreService;

        $result = $service->compute('user-1', [
            'login_count_30d' => 15,
            'features_used_count' => 3,
            'avg_pages_per_session' => 8,
            'days_since_last_login' => 1,
            'successful_payments_count' => 5,
            'failed_payments_count' => 0,
            'mrr' => 99,
            'plan_tier' => 'pro',
            'account_age_days' => 200,
            'churn_risk_score' => 0.2,
            'login_streak_days' => 3,
            'trial_converted' => true,
            'open_tickets_count' => 0,
            'nps_score' => 9,
            'feedback_sentiment' => 0.9,
            'feature_adoption_rate' => 0.6,
            'team_member_count' => 4,
            'upgraded_recently' => false,
            'integrations_count' => 2,
        ]);

        expect($result)->toBeArray();
        expect($result)->toHaveKey('score');
        expect($result)->toHaveKey('tier');
        expect($result)->toHaveKey('tier_label');
        expect($result)->toHaveKey('signals');
        expect($result)->toHaveKey('computed_at');
        expect($result['score'])->toBeInt();
        expect($result['score'])->toBeGreaterThanOrEqual(0);
        expect($result['score'])->toBeLessThanOrEqual(100);
        expect($result['tier'])->toBeString();
        expect(in_array($result['tier'], ['critical', 'at_risk', 'needs_attention', 'healthy', 'thriving'], true))->toBeTrue();
    });

    it('identifies critical health for disengaged customers', function (): void {
        $service = new CustomerHealthScoreService;

        $result = $service->compute('user-critical', [
            'login_count_30d' => 0,
            'features_used_count' => 0,
            'avg_pages_per_session' => 0,
            'days_since_last_login' => 30,
            'successful_payments_count' => 0,
            'failed_payments_count' => 3,
            'mrr' => 0,
            'account_age_days' => 10,
            'churn_risk_score' => 0.9,
            'open_tickets_count' => 5,
            'nps_score' => 2,
            'feedback_sentiment' => 0.1,
        ]);

        expect($result['score'])->toBeLessThan(50);
        expect(in_array($result['tier'], ['critical', 'at_risk'], true))->toBeTrue();
    });

    it('identifies thriving health for highly engaged customers', function (): void {
        $service = new CustomerHealthScoreService;

        $result = $service->compute('user-thriving', [
            'login_count_30d' => 25,
            'features_used_count' => 8,
            'avg_pages_per_session' => 15,
            'days_since_last_login' => 0,
            'successful_payments_count' => 12,
            'failed_payments_count' => 0,
            'mrr' => 500,
            'plan_tier' => 'enterprise',
            'account_age_days' => 400,
            'churn_risk_score' => 0.05,
            'login_streak_days' => 10,
            'trial_converted' => true,
            'open_tickets_count' => 0,
            'nps_score' => 10,
            'feedback_sentiment' => 1.0,
            'feature_adoption_rate' => 0.9,
            'team_member_count' => 15,
            'upgraded_recently' => true,
            'integrations_count' => 5,
        ]);

        expect($result['score'])->toBeGreaterThan(70);
        expect(in_array($result['tier'], ['thriving', 'healthy'], true))->toBeTrue();
    });

    it('computes batch health scores', function (): void {
        $service = new CustomerHealthScoreService;

        $results = $service->computeBatch([
            'user-a' => ['login_count_30d' => 20, 'mrr' => 100, 'account_age_days' => 365, 'features_used_count' => 5],
            'user-b' => ['login_count_30d' => 0, 'mrr' => 0, 'account_age_days' => 5, 'features_used_count' => 0],
        ]);

        expect($results)->toBeArray();
        expect($results)->toHaveKey('user-a');
        expect($results)->toHaveKey('user-b');
        expect($results['user-a'])->toHaveKey('score');
        expect($results['user-b'])->toHaveKey('tier');
    });

    it('computes distribution across customer set', function (): void {
        $service = new CustomerHealthScoreService;

        $distribution = $service->distribution(['user-a', 'user-b', 'user-c']);

        expect($distribution)->toBeArray();
        expect($distribution)->toHaveKey('total');
        expect($distribution)->toHaveKey('average');
        expect($distribution)->toHaveKey('by_tier');
        expect($distribution)->toHaveKey('distribution');
        expect($distribution['total'])->toBe(3);
        expect($distribution['average'])->toBeFloat();
    });

    it('provides tier definitions', function (): void {
        $tiers = CustomerHealthScoreService::tierDefinitions();

        expect($tiers)->toBeArray();
        expect($tiers)->toHaveKey('critical');
        expect($tiers)->toHaveKey('thriving');
        expect($tiers['critical'])->toHaveKey('label');
        expect($tiers['critical'])->toHaveKey('min_score');
        expect($tiers['thriving']['min_score'])->toBe(85);
    });

    it('provides default weights that sum to 1.0', function (): void {
        $weights = CustomerHealthScoreService::defaultWeights();

        $sum = array_sum($weights);
        expect($sum)->toBe(1.0);
        expect($weights)->toHaveKey('engagement');
        expect($weights)->toHaveKey('revenue');
        expect($weights)->toHaveKey('retention');
        expect($weights)->toHaveKey('support');
        expect($weights)->toHaveKey('growth');
    });

    it('caches health score per customer', function (): void {
        $service = new CustomerHealthScoreService;

        $result1 = $service->compute('user-cached');
        $result2 = $service->compute('user-cached'); // Should hit cache

        expect($result1['score'])->toBe($result2['score']);
    });

    it('invalidates cached health score', function (): void {
        $service = new CustomerHealthScoreService;

        $service->compute('user-invalidate');
        $service->invalidate('user-invalidate');

        // After invalidation, next call should recompute
        $result = $service->compute('user-invalidate', ['login_count_30d' => 10]);
        expect($result['score'])->toBeInt();
    });

    it('handles empty signals gracefully', function (): void {
        $service = new CustomerHealthScoreService;

        $result = $service->compute('user-empty', []);

        expect($result['score'])->toBeInt();
        expect($result['tier'])->toBeString();
    });
});

describe('v240.0.0 — SaaS Quick Deploy Integration', function (): void {
    it('SaaS Starter Events contains 20+ events', function (): void {
        $events = SaaSStarterEvents::all();

        expect(count($events))->toBeGreaterThanOrEqual(20);
    });

    it('EventCatalog has 100+ events across 5+ categories', function (): void {
        $total = EventCatalog::count();
        $byCategory = EventCatalog::byCategory();

        expect($total)->toBeGreaterThanOrEqual(100);
        expect(count($byCategory))->toBeGreaterThanOrEqual(5);
    });

    it('AnalyticsEvent VERSION is accessible', function (): void {
        $version = AnalyticsEvent::VERSION;

        expect($version)->toBeString();
        expect($version)->toMatch('/\d+\.\d+\.\d+/');
    });

    it('EventCatalogSemVer and CustomerHealthScore coexist', function (): void {
        $semver = new EventCatalogSemVerService;
        $health = new CustomerHealthScoreService;

        $version = $semver->currentVersion();
        $score = $health->compute('coexistence-test');

        expect($version)->toBeString();
        expect($score['score'])->toBeInt();
    });
});
