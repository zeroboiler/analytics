<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\RevenueForecastService;
use ZeroBoiler\Analytics\Services\ChurnPredictionService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

// ─── v2.81.0 — Revenue Forecasting + Churn Prediction + Version Unification ───

// ─── Version Consistency ─────────────────────────────────────────────

test('AnalyticsEvent VERSION is 2.82.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('2.94.0');
});

test('AnalyticsEvent VERSION is a valid semver string', function (): void {
    $version = AnalyticsEvent::VERSION;
    expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
    expect($version)->not->toBeEmpty();
});

// ─── Revenue Forecasting: LTV Calculation ────────────────────────────

test('LTV calculation with standard inputs', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->calculateLtv(arpu: 99.0, monthlyChurnRate: 0.03, grossMargin: 0.75);

    // LTV = 99 * (1/0.03) * 0.75 = 99 * 33.33 * 0.75 = 2475.25
    expect($result)->toHaveKey('ltv');
    expect($result)->toHaveKey('ltv_months');
    expect($result)->toHaveKey('arpu_annual');
    expect($result)->toHaveKey('churn_multiplier');
    expect($result['ltv'])->toBeGreaterThan(2000);
    expect($result['ltv_months'])->toBe(33.3);
    expect($result['arpu_annual'])->toBe(1188.0);
});

test('LTV calculation with zero churn rate defaults to 3 years', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->calculateLtv(arpu: 99.0, monthlyChurnRate: 0.0, grossMargin: 0.75);

    expect($result['churn_multiplier'])->toBe(36.0);
    expect($result['ltv'])->toBe(2673.0); // 99 * 36 * 0.75
});

test('LTV calculation with high churn rate', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->calculateLtv(arpu: 49.0, monthlyChurnRate: 0.10, grossMargin: 0.70);

    // LTV = 49 * (1/0.10) * 0.70 = 49 * 10 * 0.70 = 343
    expect($result['ltv'])->toBe(343.0);
    expect($result['ltv_months'])->toBe(10.0);
});

// ─── Revenue Forecasting: LTV:CAC Ratio ──────────────────────────────

test('LTV:CAC ratio is healthy at 3:1', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->ltvCACRatio(ltv: 3000.0, cac: 1000.0);

    expect($result['ratio'])->toBe(3.0);
    expect($result['rating'])->toBe('healthy');
    expect($result['recommendation'])->toContain('healthy');
});

test('LTV:CAC ratio is excellent at 5:1', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->ltvCACRatio(ltv: 5000.0, cac: 1000.0);

    expect($result['ratio'])->toBe(5.0);
    expect($result['rating'])->toBe('excellent');
});

test('LTV:CAC ratio is critical below 1:1', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->ltvCACRatio(ltv: 500.0, cac: 1000.0);

    expect($result['ratio'])->toBe(0.5);
    expect($result['rating'])->toBe('critical');
});

test('LTV:CAC ratio handles zero CAC', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->ltvCACRatio(ltv: 3000.0, cac: 0.0);

    expect($result['ratio'])->toBe(0.0);
    expect($result['rating'])->toBe('unknown');
});

// ─── Revenue Forecasting: Payback Period ──────────────────────────────

test('Payback period with standard inputs', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->paybackPeriod(cac: 500.0, monthlyArpu: 99.0, grossMargin: 0.75);

    // monthly contribution = 99 * 0.75 = 74.25
    // months = 500 / 74.25 = 6.73
    expect($result['months'])->toBe(6.7);
    expect($result['target_months'])->toBe(12);
    expect($result['rating'])->toBe('healthy');
});

test('Payback period with high CAC', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->paybackPeriod(cac: 5000.0, monthlyArpu: 99.0, grossMargin: 0.75);

    // months = 5000 / 74.25 = 67.3
    expect($result['months'])->toBe(67.3);
    expect($result['rating'])->toBe('critical');
});

test('Payback period with excellent CAC recovery', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->paybackPeriod(cac: 200.0, monthlyArpu: 99.0, grossMargin: 0.75);

    // months = 200 / 74.25 = 2.7
    expect($result['months'])->toBe(2.7);
    expect($result['rating'])->toBe('excellent');
});

// ─── Revenue Forecasting: Runway Estimation ───────────────────────────

test('Runway estimation with profitable MRR', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->runway(currentMrr: 20000.0, monthlyExpenses: 15000.0, growthRate: 0.05, churnRate: 0.03);

    // Already profitable — runway should be 1 (immediate breakeven)
    expect($result)->toHaveKey('runway_months');
    expect($result)->toHaveKey('breakeven_date');
    expect($result)->toHaveKey('burn_rate');
    expect($result)->toHaveKey('path_to_profitability');
    expect($result['burn_rate'])->toBe(0.0); // Already profitable
    expect($result['runway_months'])->toBe(1); // Already at breakeven
});

test('Runway estimation with burning cash', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->runway(currentMrr: 5000.0, monthlyExpenses: 15000.0, growthRate: 0.10, churnRate: 0.02);

    expect($result['burn_rate'])->toBe(10000.0);
    // With 10% growth and 2% churn, should reach breakeven eventually
    expect($result['runway_months'])->toBeGreaterThan(0);
});

test('Runway estimation with zero MRR and high expenses', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->runway(currentMrr: 0.0, monthlyExpenses: 15000.0, growthRate: 0.05, churnRate: 0.03);

    expect($result['burn_rate'])->toBe(15000.0);
    expect($result['breakeven_date'])->toBeNull();
});

// ─── Revenue Forecasting: Cohort Retention Curve ──────────────────────

test('Cohort retention curve starts at 100%', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $curve = $service->cohortRetentionCurve(months: 12, monthlyChurnRate: 0.03);

    expect($curve)->not->toBeEmpty();
    expect($curve[0]['month'])->toBe(0);
    expect($curve[0]['retention_rate'])->toBe(100.0);
    expect(count($curve))->toBe(13); // 0 through 12
});

test('Cohort retention curve decreases over time', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $curve = $service->cohortRetentionCurve(months: 6, monthlyChurnRate: 0.05);

    expect($curve[0]['retention_rate'])->toBeGreaterThan($curve[6]['retention_rate']);
    expect($curve[6]['retention_rate'])->toBeLessThan(100.0);
    expect($curve[6]['estimated_subscribers'])->toBeLessThan(100);
});

test('Cohort retention curve respects ARPU for MRR estimation', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([
        'avg_revenue_per_account' => 150.0,
    ]);
    $service = new RevenueForecastService($config);

    $curve = $service->cohortRetentionCurve(months: 1, monthlyChurnRate: 0.0);

    expect($curve[0]['estimated_mrr'])->toBe(15000.0); // 100 * 150
});

// ─── Revenue Forecasting: MRR Movement Breakdown ───────────────────────

test('MRR movement breakdown with positive growth', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->mrrMovementBreakdown([
        'new_mrr' => 5000.0,
        'expansion_mrr' => 2000.0,
        'contraction_mrr' => 500.0,
        'churned_mrr' => 1000.0,
        'previous_mrr' => 10000.0,
    ]);

    expect($result['new'])->toBe(5000.0);
    expect($result['expansion'])->toBe(2000.0);
    expect($result['contraction'])->toBe(500.0);
    expect($result['churn'])->toBe(1000.0);
    expect($result['net_change'])->toBe(5500.0); // 5000 + 2000 - 500 - 1000
    expect($result['new_mrr'])->toBe(15500.0); // 10000 + 5500
});

test('MRR movement breakdown with negative growth', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->mrrMovementBreakdown([
        'new_mrr' => 1000.0,
        'expansion_mrr' => 500.0,
        'contraction_mrr' => 2000.0,
        'churned_mrr' => 3000.0,
        'previous_mrr' => 20000.0,
    ]);

    expect($result['net_change'])->toBe(-3500.0);
    expect($result['new_mrr'])->toBe(16500.0);
});

test('MRR movement breakdown with empty input', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->mrrMovementBreakdown([]);

    expect($result['new'])->toBe(0.0);
    expect($result['expansion'])->toBe(0.0);
    expect($result['contraction'])->toBe(0.0);
    expect($result['churn'])->toBe(0.0);
    expect($result['net_change'])->toBe(0.0);
    expect($result['new_mrr'])->toBe(0.0);
});

// ─── Revenue Forecasting: Project At ─────────────────────────────────

test('Project MRR at future date with growth', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->projectAt(daysOut: 30, currentData: ['mrr' => 10000.0]);

    expect($result)->toHaveKey('date');
    expect($result)->toHaveKey('projected_mrr');
    expect($result)->toHaveKey('projected_arr');
    expect($result)->toHaveKey('cumulative_churn');
    expect($result)->toHaveKey('cumulative_growth');
    // With 5% growth > 3% churn, should grow
    expect($result['projected_mrr'])->toBeGreaterThan(10000.0);
});

test('Project MRR at zero returns same MRR', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.forecasting', [])->andReturn([]);
    $service = new RevenueForecastService($config);

    $result = $service->projectAt(daysOut: 0, currentData: ['mrr' => 10000.0]);

    expect($result['projected_mrr'])->toBe(10000.0);
});

// ─── Churn Prediction: Score User ────────────────────────────────────

test('Churn score for healthy user is low', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.churn_prediction', [])->andReturn([]);
    $service = new ChurnPredictionService($config);

    $result = $service->scoreUser('user-healthy', [
        'days_inactive' => 0,
        'usage_decline_pct' => 0,
        'support_tickets_30d' => 0,
        'failed_payments_90d' => 0,
        'feature_adoption_pct' => 90,
        'engagement_score' => 85,
    ]);

    expect($result['user_id'])->toBe('user-healthy');
    expect($result['overall_score'])->toBeLessThan(30);
    expect($result['risk_level'])->toBe('low');
    expect($result['probability_percent'])->toBeLessThan(35);
    expect($result['recommendation'])->toContain('healthy');
});

test('Churn score for at-risk user is high', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.churn_prediction', [])->andReturn([]);
    $service = new ChurnPredictionService($config);

    $result = $service->scoreUser('user-at-risk', [
        'days_inactive' => 45,
        'usage_decline_pct' => 80,
        'support_tickets_30d' => 4,
        'failed_payments_90d' => 2,
        'feature_adoption_pct' => 10,
        'contract_expiring_30d' => true,
        'billing_disputes' => 1,
        'plan_downgrade_recent' => true,
        'engagement_score' => 15,
    ]);

    expect($result['overall_score'])->toBeGreaterThan(60);
    expect($result['risk_level'])->toBeIn(['high', 'critical']);
    expect($result['signals'])->not->toBeEmpty();
});

test('Churn score for critical user with all red flags', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.churn_prediction', [])->andReturn([]);
    $service = new ChurnPredictionService($config);

    $result = $service->scoreUser('user-critical', [
        'days_inactive' => 90,
        'usage_decline_pct' => 100,
        'support_tickets_30d' => 5,
        'failed_payments_90d' => 3,
        'feature_adoption_pct' => 0,
        'contract_expiring_30d' => true,
        'billing_disputes' => 2,
        'plan_downgrade_recent' => true,
        'login_frequency_decline_pct' => 95,
        'engagement_score' => 0,
    ]);

    expect($result['risk_level'])->toBe('critical');
    expect($result['overall_score'])->toBeGreaterThan(80);
    expect($result['recommendation'])->toContain('Critical');
});

test('Churn score with no signals returns zero', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.churn_prediction', [])->andReturn([]);
    $service = new ChurnPredictionService($config);

    $result = $service->scoreUser('user-no-data', []);

    expect($result['overall_score'])->toBe(0.0);
    expect($result['risk_level'])->toBe('low');
});

// ─── Churn Prediction: Batch Scoring ────────────────────────────────

test('Batch scoring returns ranked results', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.churn_prediction', [])->andReturn([]);
    $service = new ChurnPredictionService($config);

    $users = [
        ['user_id' => 'user-a', 'days_inactive' => 0],
        ['user_id' => 'user-b', 'days_inactive' => 60, 'usage_decline_pct' => 90],
        ['user_id' => 'user-c', 'days_inactive' => 30],
    ];

    $result = $service->scoreBatch($users);

    expect($result)->toHaveKey('ranked');
    expect($result)->toHaveKey('summary');
    expect($result)->toHaveKey('at_risk_count');
    expect($result['summary']['total'])->toBe(3);
    // Ranked should be sorted by score descending
    expect($result['ranked'][0]['overall_score'])->toBeGreaterThanOrEqual($result['ranked'][1]['overall_score']);
});

test('Batch scoring skips users without IDs', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.churn_prediction', [])->andReturn([]);
    $service = new ChurnPredictionService($config);

    $users = [
        ['days_inactive' => 60],
        ['user_id' => 'valid-user', 'days_inactive' => 0],
    ];

    $result = $service->scoreBatch($users);

    expect($result['summary']['total'])->toBe(1);
    expect($result['ranked'][0]['user_id'])->toBe('valid-user');
});

// ─── Churn Prediction: Cohort Risk Summary ───────────────────────────

test('Cohort risk summary returns aggregate data', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.churn_prediction', [])->andReturn([]);
    $service = new ChurnPredictionService($config);

    $users = [
        ['user_id' => 'u1', 'days_inactive' => 0],
        ['user_id' => 'u2', 'days_inactive' => 0],
        ['user_id' => 'u3', 'days_inactive' => 50, 'usage_decline_pct' => 80],
    ];

    $result = $service->cohortRiskSummary($users);

    expect($result)->toHaveKey('total_users');
    expect($result)->toHaveKey('risk_distribution');
    expect($result)->toHaveKey('avg_risk_score');
    expect($result)->toHaveKey('estimated_monthly_churn_revenue');
    expect($result)->toHaveKey('top_risk_factors');
    expect($result['total_users'])->toBe(3);
    expect($result['risk_distribution'])->toHaveKey('low');
    expect($result['risk_distribution'])->toHaveKey('medium');
    expect($result['risk_distribution'])->toHaveKey('high');
    expect($result['risk_distribution'])->toHaveKey('critical');
    expect(count($result['top_risk_factors']))->toBeLessThanOrEqual(5);
});

// ─── Churn Prediction: Signal Weights and Thresholds ─────────────────

test('Signal weights return all 10 configured signals', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.churn_prediction', [])->andReturn([]);
    $service = new ChurnPredictionService($config);

    $weights = $service->getSignalWeights();

    expect($weights)->toHaveCount(10);
    expect($weights)->toHaveKey('days_inactive');
    expect($weights)->toHaveKey('usage_decline_pct');
    expect($weights)->toHaveKey('support_tickets_30d');
    expect($weights)->toHaveKey('failed_payments_90d');
    expect($weights)->toHaveKey('feature_adoption_low');
    expect($weights)->toHaveKey('contract_expiring_30d');
    expect($weights)->toHaveKey('billing_disputes');
    expect($weights)->toHaveKey('login_frequency_decline');
    expect($weights)->toHaveKey('engagement_score_low');
    expect($weights)->toHaveKey('plan_downgrade_recent');
});

test('Signal weights can be overridden via config', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.churn_prediction', [])->andReturn([
        'signal_weights' => [
            'days_inactive' => 50.0,
            'custom_signal' => 15.0,
        ],
    ]);
    $service = new ChurnPredictionService($config);

    $weights = $service->getSignalWeights();

    expect($weights['days_inactive'])->toBe(50.0);
    expect($weights['custom_signal'])->toBe(15.0);
});

test('Thresholds return correct defaults', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.churn_prediction', [])->andReturn([]);
    $service = new ChurnPredictionService($config);

    $thresholds = $service->getThresholds();

    expect($thresholds)->toBe([
        'medium' => 30,
        'high' => 60,
        'critical' => 80,
    ]);
});

test('Thresholds can be overridden via config', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.churn_prediction', [])->andReturn([
        'high_risk_threshold' => 70,
        'medium_risk_threshold' => 40,
        'critical_risk_threshold' => 90,
    ]);
    $service = new ChurnPredictionService($config);

    $thresholds = $service->getThresholds();

    expect($thresholds['medium'])->toBe(40);
    expect($thresholds['high'])->toBe(70);
    expect($thresholds['critical'])->toBe(90);
});

// ─── Config Integration ─────────────────────────────────────────────

test('forecasting config section has all required keys', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';
    $forecasting = $config['analytics']['forecasting'] ?? [];

    expect($forecasting)->toHaveKey('enabled');
    expect($forecasting)->toHaveKey('cache_ttl');
    expect($forecasting)->toHaveKey('monthly_churn_rate');
    expect($forecasting)->toHaveKey('growth_rate');
    expect($forecasting)->toHaveKey('horizon_days');
    expect($forecasting)->toHaveKey('historical_window_days');
    expect($forecasting)->toHaveKey('avg_revenue_per_account');
});

test('churn_prediction config section has all required keys', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';
    $churn = $config['analytics']['churn_prediction'] ?? [];

    expect($churn)->toHaveKey('enabled');
    expect($churn)->toHaveKey('cache_ttl');
    expect($churn)->toHaveKey('high_risk_threshold');
    expect($churn)->toHaveKey('medium_risk_threshold');
    expect($churn)->toHaveKey('critical_risk_threshold');
    expect($churn)->toHaveKey('inactive_days_threshold');
    expect($churn)->toHaveKey('signal_weights');
    expect($churn['signal_weights'])->toHaveCount(10);
});
