<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Industry-standard SaaS analytics glossary and metric reference service.
 *
 * Provides canonical definitions, formulas, benchmarks, and event-to-metric
 * cross-references for all key SaaS metrics. Used by the admin command
 * (`zb:analytics:glossary`), the Inertia props (client-side reference),
 * and the API endpoints for developer documentation.
 *
 * Covers 28 metrics across 6 categories:
 *
 * - **Revenue**: MRR, ARR, ARPU, Expansion Revenue, Contraction Revenue, Revenue Churn
 * - **Growth**: Net Revenue Retention (NRR), Gross Revenue Retention (GRR), Quick Ratio, Rule of 40
 * - **Unit Economics**: LTV, CAC, LTV:CAC Ratio, CAC Payback Period, Burn Multiple
 * - **Engagement**: DAU, MAU, Stickiness (DAU/MAU), Activation Rate, Session Duration
 * - **Retention**: D1/D7/D30 Retention, Churn Rate, Logo Churn, Cohort Retention Curve
 * - **Funnel**: Conversion Rate, Trial-to-Paid Rate, Time-to-Value, Funnel Velocity
 *
 * Each metric entry includes:
 * - Human-readable name and short description
 * - Canonical formula (pseudo-code)
 * - Industry benchmarks (good/acceptable/poor thresholds)
 * - Primary source events (which ZeroBoiler analytics events feed into this metric)
 * - Required config/parameters for computation
 * - Category and tags
 *
 * Inspired by ChartMogul's SaaS Metrics Glossary, ProfitWell's Metric Library,
 * OpenView's SaaS Benchmarks, and Baremetrics' Metric Definitions.
 *
 * @since 217.0.0
 */
final class SaaSAnalyticsGlossaryService
{
    private const CACHE_KEY = 'zb_analytics_glossary';
    private const CACHE_TTL = 86400; // 24 hours

    /** @var array<string, MetricEntry> */
    private array $glossary = [];

    /** @var CacheRepository */
    private CacheRepository $cache;

    /**
     * @param  CacheRepository|null  $cache  Optional cache repository for testing
     */
    public function __construct(?CacheRepository $cache = null){
        $this->cache = $cache ?? Cache::store();
        $this->buildGlossary();
    }

    /**
     * Get all glossary entries.
     *
     * @return array<string, array{name: string, description: string, formula: string, benchmarks: array{good: string, acceptable: string, poor: string}, source_events: list<string>, required_config: list<string>, category: string, tags: list<string>}>
     */
    public function all(): array
    {
        return $this->glossary;
    }

    /**
     * Get a single metric entry by key.
     *
     * @return array{name: string, description: string, formula: string, benchmarks: array{good: string, acceptable: string, poor: string}, source_events: list<string>, required_config: list<string>, category: string, tags: list<string>}|null
     */
    public function get(string $key): ?array
    {
        return $this->glossary[$key] ?? null;
    }

    /**
     * Get all glossary entries grouped by category.
     *
     * @return array<string, array<string, array{name: string, description: string, formula: string, benchmarks: array{good: string, acceptable: string, poor: string}, source_events: list<string>, required_config: list<string>, category: string, tags: list<string>}>>
     */
    public function groupedByCategory(): array
    {
        $groups = [];

        foreach ($this->glossary as $key => $entry) {
            $category = $entry['category'];
            $groups[$category][$key] = $entry;
        }

        return $groups;
    }

    /**
     * Get all metric names (keys).
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->glossary);
    }

    /**
     * Get all category names.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        $cats = [];

        foreach ($this->glossary as $entry) {
            $cats[$entry['category']] = true;
        }

        return array_keys($cats);
    }

    /**
     * Get total metric count.
     */
    public function count(): int
    {
        return count($this->glossary);
    }

    /**
     * Find metrics by tag.
     *
     * @param  string  $tag  Tag to search for (e.g. 'revenue', 'retention', 'growth')
     * @return array<string, array{name: string, description: string, formula: string, benchmarks: array{good: string, acceptable: string, poor: string}, source_events: list<string>, required_config: list<string>, category: string, tags: list<string>}>
     */
    public function byTag(string $tag): array
    {
        $results = [];

        foreach ($this->glossary as $key => $entry) {
            if (in_array($tag, $entry['tags'], true)) {
                $results[$key] = $entry;
            }
        }

        return $results;
    }

    /**
     * Find which events contribute to a given metric.
     *
     * @param  string  $metricKey  Metric key (e.g. 'mrr', 'churn_rate')
     * @return list<string> Event names that feed into this metric
     */
    public function sourceEventsFor(string $metricKey): array
    {
        $entry = $this->glossary[$metricKey] ?? null;

        if ($entry === null) {
            return [];
        }

        return $entry['source_events'];
    }

    /**
     * Find which metrics are computed from a given event.
     *
     * @param  string  $eventName  Event name (e.g. 'purchase', 'sign_up')
     * @return array<string, string> Map of metric_key → metric_name
     */
    public function metricsForEvent(string $eventName): array
    {
        $results = [];

        foreach ($this->glossary as $key => $entry) {
            if (in_array($eventName, $entry['source_events'], true)) {
                $results[$key] = $entry['name'];
            }
        }

        return $results;
    }

    /**
     * Get a quick-reference summary suitable for CLI display.
     *
     * Returns a compact array with metric key, name, and formula for each entry.
     *
     * @return list<array{key: string, name: string, formula: string, category: string}>
     */
    public function quickReference(): array
    {
        $ref = [];

        foreach ($this->glossary as $key => $entry) {
            $ref[] = [
                'key' => $key,
                'name' => $entry['name'],
                'formula' => $entry['formula'],
                'category' => $entry['category'],
            ];
        }

        return $ref;
    }

    /**
     * Get event-to-metric cross-reference map.
     *
     * Useful for understanding which events are required to compute which metrics.
     *
     * @return array<string, list<string>> Map of event_name → [metric_keys]
     */
    public function eventToMetricMap(): array
    {
        $map = [];

        foreach ($this->glossary as $key => $entry) {
            foreach ($entry['source_events'] as $event) {
                $map[$event][] = $key;
            }
        }

        // Sort metric lists for consistent output
        foreach ($map as $event => $metrics) {
            sort($metrics);
            $map[$event] = array_values($metrics);
        }

        return $map;
    }

    /**
     * Get a client-safe summary for Inertia props or API response.
     *
     * Omits internal formula details, returns compact structure.
     *
     * @return list<array{key: string, name: string, description: string, category: string, source_events: list<string>}>
     */
    public function clientSummary(): array
    {
        $summary = [];

        foreach ($this->glossary as $key => $entry) {
            $summary[] = [
                'key' => $key,
                'name' => $entry['name'],
                'description' => $entry['description'],
                'category' => $entry['category'],
                'source_events' => $entry['source_events'],
            ];
        }

        return $summary;
    }

    /**
     * Check if a metric key exists.
     */
    public function has(string $key): bool
    {
        return isset($this->glossary[$key]);
    }

    /**
     * Get coverage analysis — which ZeroBoiler catalog events cover which glossary metrics.
     *
     * @return array{covered: list<string>, uncovered: list<string>, coverage_percent: float, event_count: int, metric_count: int}
     */
    public function coverageAnalysis(): array
    {
        $allMetricKeys = array_keys($this->glossary);
        $coveredMetrics = [];
        $uncoveredMetrics = [];

        foreach ($allMetricKeys as $key) {
            $events = $this->glossary[$key]['source_events'];

            if ($events !== []) {
                $coveredMetrics[] = $key;
            } else {
                $uncoveredMetrics[] = $key;
            }
        }

        $metricCount = count($allMetricKeys);
        $coveredCount = count($coveredMetrics);

        return [
            'covered' => $coveredMetrics,
            'uncovered' => $uncoveredMetrics,
            'coverage_percent' => $metricCount > 0 ? round(($coveredCount / $metricCount) * 100, 1) : 0.0,
            'event_count' => count($this->eventToMetricMap()),
            'metric_count' => $metricCount,
        ];
    }

    /**
     * Build the full glossary.
     */
    private function buildGlossary(): void
    {
        // ── Revenue Metrics ───────────────────────────────────────────

        $this->glossary['mrr'] = [
            'name' => 'Monthly Recurring Revenue',
            'description' => 'The predictable revenue generated each month from active subscriptions. Excludes one-time fees, setup charges, and variable usage-based billing.',
            'formula' => 'MRR = Σ (monthly_price × active_subscriptions)',
            'benchmarks' => [
                'good' => '> $100K or > 15% MoM growth (early stage)',
                'acceptable' => '> $50K or > 10% MoM growth',
                'poor' => '< 5% MoM growth or declining',
            ],
            'source_events' => ['subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation', 'subscription_renewal', 'credit_applied'],
            'required_config' => ['revenue.subscription_tiers', 'saas_kpi_calc.mrr_goal'],
            'category' => 'Revenue',
            'tags' => ['revenue', 'core', 'kpi'],
        ];

        $this->glossary['arr'] = [
            'name' => 'Annual Recurring Revenue',
            'description' => 'MRR annualized. Represents the annualized value of all active subscriptions. The primary valuation metric for SaaS businesses.',
            'formula' => 'ARR = MRR × 12',
            'benchmarks' => [
                'good' => '> $1M ARR (growth stage)',
                'acceptable' => '> $500K ARR',
                'poor' => '< $100K ARR (pre-seed acceptable)',
            ],
            'source_events' => ['subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation'],
            'required_config' => ['revenue.subscription_tiers'],
            'category' => 'Revenue',
            'tags' => ['revenue', 'core', 'kpi', 'valuation'],
        ];

        $this->glossary['arpu'] = [
            'name' => 'Average Revenue Per User',
            'description' => 'Mean monthly revenue per active user or account. Key for understanding per-unit monetization efficiency.',
            'formula' => 'ARPU = MRR / active_customers',
            'benchmarks' => [
                'good' => '> $100/month',
                'acceptable' => '> $50/month',
                'poor' => '< $20/month',
            ],
            'source_events' => ['subscribe', 'plan_upgrade', 'plan_downgrade', 'revenue_tracked'],
            'required_config' => ['saas_kpi_calc'],
            'category' => 'Revenue',
            'tags' => ['revenue', 'unit_economics', 'kpi'],
        ];

        $this->glossary['expansion_revenue'] = [
            'name' => 'Expansion Revenue',
            'description' => 'Additional MRR from existing customers through upsells, cross-sells, and plan upgrades. A critical growth driver for mature SaaS.',
            'formula' => 'Expansion = Σ (upgrade_value - previous_value) for existing customers',
            'benchmarks' => [
                'good' => '> 20% of new MRR',
                'acceptable' => '> 10% of new MRR',
                'poor' => '< 5% of new MRR',
            ],
            'source_events' => ['plan_upgrade', 'feature_limit_reached', 'integration_connected'],
            'required_config' => ['revenue_waterfall'],
            'category' => 'Revenue',
            'tags' => ['revenue', 'growth', 'waterfall'],
        ];

        $this->glossary['contraction_revenue'] = [
            'name' => 'Contraction Revenue',
            'description' => 'MRR lost from existing customers through downgrades without churning. Indicates product-market fit erosion.',
            'formula' => 'Contraction = Σ (previous_value - downgrade_value) for retained customers',
            'benchmarks' => [
                'good' => '< 5% of MRR',
                'acceptable' => '< 10% of MRR',
                'poor' => '> 15% of MRR',
            ],
            'source_events' => ['plan_downgrade', 'feature_limit_reached'],
            'required_config' => ['revenue_waterfall'],
            'category' => 'Revenue',
            'tags' => ['revenue', 'churn', 'waterfall'],
        ];

        $this->glossary['revenue_churn'] = [
            'name' => 'Revenue Churn Rate',
            'description' => 'Percentage of MRR lost from cancellations and downgrades in a given period. Revenue churn captures financial impact, unlike logo churn.',
            'formula' => 'Revenue Churn = (MRR_lost + MRR_contraction) / MRR_start × 100',
            'benchmarks' => [
                'good' => '< 1% monthly',
                'acceptable' => '< 3% monthly',
                'poor' => '> 5% monthly',
            ],
            'source_events' => ['cancellation', 'plan_downgrade', 'subscription_renewal'],
            'required_config' => ['saas_kpi_calc.churn_warning'],
            'category' => 'Revenue',
            'tags' => ['revenue', 'churn', 'kpi'],
        ];

        // ── Growth Metrics ────────────────────────────────────────────

        $this->glossary['nrr'] = [
            'name' => 'Net Revenue Retention',
            'description' => 'Percentage of revenue retained from existing customers over a period, including expansion. The single most important SaaS metric for investors.',
            'formula' => 'NRR = (Starting MRR + Expansion - Contraction - Churn) / Starting MRR × 100',
            'benchmarks' => [
                'good' => '> 120%',
                'acceptable' => '> 100%',
                'poor' => '< 90%',
            ],
            'source_events' => ['subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation', 'subscription_renewal', 'credit_applied'],
            'required_config' => ['saas_kpi_calc'],
            'category' => 'Growth',
            'tags' => ['growth', 'retention', 'kpi', 'investor'],
        ];

        $this->glossary['grr'] = [
            'name' => 'Gross Revenue Retention',
            'description' => 'Percentage of revenue retained from existing customers excluding expansion. Measures baseline churn and contraction.',
            'formula' => 'GRR = (Starting MRR - Contraction - Churn) / Starting MRR × 100',
            'benchmarks' => [
                'good' => '> 95%',
                'acceptable' => '> 90%',
                'poor' => '< 85%',
            ],
            'source_events' => ['plan_downgrade', 'cancellation', 'subscription_renewal'],
            'required_config' => ['saas_kpi_calc'],
            'category' => 'Growth',
            'tags' => ['growth', 'retention', 'kpi'],
        ];

        $this->glossary['quick_ratio'] = [
            'name' => 'Quick Ratio',
            'description' => 'Ratio of new MRR to lost MRR. Measures the efficiency of growth relative to revenue leakage.',
            'formula' => 'Quick Ratio = (New MRR + Expansion MRR) / (Contraction MRR + Churn MRR)',
            'benchmarks' => [
                'good' => '> 4.0',
                'acceptable' => '> 2.0',
                'poor' => '< 1.0',
            ],
            'source_events' => ['subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation'],
            'required_config' => ['saas_kpi_calc.quick_ratio_target'],
            'category' => 'Growth',
            'tags' => ['growth', 'efficiency', 'kpi'],
        ];

        $this->glossary['rule_of_40'] = [
            'name' => 'Rule of 40',
            'description' => 'Combined growth rate + profit margin should exceed 40%. A benchmark for SaaS business health popularized by Brad Feld.',
            'formula' => 'Rule of 40 = Revenue Growth Rate (%) + FCF Margin (%)',
            'benchmarks' => [
                'good' => '> 40%',
                'acceptable' => '> 30%',
                'poor' => '< 20%',
            ],
            'source_events' => ['subscribe', 'plan_upgrade', 'cancellation', 'revenue_tracked'],
            'required_config' => ['saas_kpi_calc.rule_of_40_target'],
            'category' => 'Growth',
            'tags' => ['growth', 'efficiency', 'benchmark', 'investor'],
        ];

        // ── Unit Economics ────────────────────────────────────────────

        $this->glossary['ltv'] = [
            'name' => 'Customer Lifetime Value',
            'description' => 'Total revenue expected from a customer over their entire relationship. Calculated from ARPU and gross churn rate.',
            'formula' => 'LTV = ARPU × Gross Margin / Monthly Churn Rate',
            'benchmarks' => [
                'good' => 'LTV:CAC > 3:1',
                'acceptable' => 'LTV:CAC > 2:1',
                'poor' => 'LTV:CAC < 1:1',
            ],
            'source_events' => ['subscribe', 'plan_upgrade', 'cancellation', 'revenue_tracked'],
            'required_config' => ['saas_kpi_calc.ltv_cac_target'],
            'category' => 'Unit Economics',
            'tags' => ['unit_economics', 'kpi', 'investor'],
        ];

        $this->glossary['cac'] = [
            'name' => 'Customer Acquisition Cost',
            'description' => 'Total sales and marketing cost to acquire one new customer. Includes salaries, tools, ads, and content creation.',
            'formula' => 'CAC = Total S&M Spend / New Customers Acquired',
            'benchmarks' => [
                'good' => 'Payback < 12 months',
                'acceptable' => 'Payback < 18 months',
                'poor' => 'Payback > 24 months',
            ],
            'source_events' => ['sign_up', 'trial_start'],
            'required_config' => [],
            'category' => 'Unit Economics',
            'tags' => ['unit_economics', 'kpi', 'marketing'],
        ];

        $this->glossary['ltv_cac_ratio'] = [
            'name' => 'LTV:CAC Ratio',
            'description' => 'Ratio of lifetime value to acquisition cost. Indicates whether unit economics are sustainable.',
            'formula' => 'LTV:CAC = Customer LTV / Customer CAC',
            'benchmarks' => [
                'good' => '> 3:1',
                'acceptable' => '> 2:1',
                'poor' => '< 1.5:1',
            ],
            'source_events' => ['sign_up', 'subscribe', 'plan_upgrade', 'cancellation'],
            'required_config' => ['saas_kpi_calc.ltv_cac_target'],
            'category' => 'Unit Economics',
            'tags' => ['unit_economics', 'kpi', 'efficiency'],
        ];

        $this->glossary['cac_payback'] = [
            'name' => 'CAC Payback Period',
            'description' => 'Number of months to recover the cost of acquiring a customer. Lower is better for cash flow efficiency.',
            'formula' => 'CAC Payback = CAC / (ARPU × Gross Margin)',
            'benchmarks' => [
                'good' => '< 12 months',
                'acceptable' => '< 18 months',
                'poor' => '> 24 months',
            ],
            'source_events' => ['sign_up', 'subscribe', 'revenue_tracked'],
            'required_config' => ['saas_kpi_calc'],
            'category' => 'Unit Economics',
            'tags' => ['unit_economics', 'cash_flow', 'efficiency'],
        ];

        $this->glossary['burn_multiple'] = [
            'name' => 'Burn Multiple',
            'description' => 'How many dollars of cash are burned to generate each dollar of new ARR. Lower burn multiples indicate more efficient growth.',
            'formula' => 'Burn Multiple = Net Cash Burned / Net New ARR',
            'benchmarks' => [
                'good' => '< 1.0x',
                'acceptable' => '< 1.5x',
                'poor' => '> 2.0x',
            ],
            'source_events' => ['subscribe', 'plan_upgrade', 'cancellation'],
            'required_config' => [],
            'category' => 'Unit Economics',
            'tags' => ['unit_economics', 'cash_flow', 'investor'],
        ];

        // ── Engagement Metrics ───────────────────────────────────────

        $this->glossary['dau'] = [
            'name' => 'Daily Active Users',
            'description' => 'Number of unique users who perform a meaningful action in a 24-hour period. The baseline engagement metric for any SaaS product.',
            'formula' => 'DAU = Count unique users with ≥1 qualifying event in last 24h',
            'benchmarks' => [
                'good' => '> 40% of MAU',
                'acceptable' => '> 20% of MAU',
                'poor' => '< 10% of MAU',
            ],
            'source_events' => ['page_view', 'feature_used', 'login', 'sign_up', 'search', 'form_submit', 'click'],
            'required_config' => ['growth_metrics'],
            'category' => 'Engagement',
            'tags' => ['engagement', 'core', 'kpi'],
        ];

        $this->glossary['mau'] = [
            'name' => 'Monthly Active Users',
            'description' => 'Number of unique users who perform a meaningful action in a 30-day period. The denominator for engagement ratios.',
            'formula' => 'MAU = Count unique users with ≥1 qualifying event in last 30 days',
            'benchmarks' => [
                'good' => '> 60% of total registered users',
                'acceptable' => '> 40%',
                'poor' => '< 20%',
            ],
            'source_events' => ['page_view', 'feature_used', 'login', 'sign_up', 'search', 'form_submit', 'click'],
            'required_config' => ['growth_metrics'],
            'category' => 'Engagement',
            'tags' => ['engagement', 'core', 'kpi'],
        ];

        $this->glossary['stickiness'] = [
            'name' => 'Stickiness (DAU/MAU)',
            'description' => 'Ratio of daily to monthly active users. Measures how habit-forming the product is. Higher stickiness indicates strong product-market fit.',
            'formula' => 'Stickiness = DAU / MAU × 100',
            'benchmarks' => [
                'good' => '> 50%',
                'acceptable' => '> 25%',
                'poor' => '< 15%',
            ],
            'source_events' => ['page_view', 'feature_used', 'login', 'search'],
            'required_config' => ['growth_metrics'],
            'category' => 'Engagement',
            'tags' => ['engagement', 'pmf', 'kpi'],
        ];

        $this->glossary['activation_rate'] = [
            'name' => 'Activation Rate',
            'description' => 'Percentage of new signups who complete a key activation action (aha moment) within a defined time window.',
            'formula' => 'Activation = Users completing activation_event / Total signups × 100',
            'benchmarks' => [
                'good' => '> 60%',
                'acceptable' => '> 40%',
                'poor' => '< 25%',
            ],
            'source_events' => ['sign_up', 'feature_used', 'team_created', 'integration_connected'],
            'required_config' => ['growth_metrics.activation_events'],
            'category' => 'Engagement',
            'tags' => ['engagement', 'onboarding', 'pmf', 'kpi'],
        ];

        $this->glossary['session_duration'] = [
            'name' => 'Average Session Duration',
            'description' => 'Mean time between session start and session end. Longer sessions indicate deeper engagement with the product.',
            'formula' => 'Avg Session Duration = Σ (session_end - session_start) / Total Sessions',
            'benchmarks' => [
                'good' => '> 10 minutes',
                'acceptable' => '> 5 minutes',
                'poor' => '< 2 minutes',
            ],
            'source_events' => ['session_start', 'session_end', 'page_view', 'time_on_page'],
            'required_config' => ['client_auto_track.session_tracking'],
            'category' => 'Engagement',
            'tags' => ['engagement', 'session', 'quality'],
        ];

        // ── Retention Metrics ─────────────────────────────────────────

        $this->glossary['d1_retention'] = [
            'name' => 'Day-1 Retention',
            'description' => 'Percentage of users who return on the day after their first visit. A strong indicator of initial product experience quality.',
            'formula' => 'D1 = Users active on Day 1 / Users active on Day 0 × 100',
            'benchmarks' => [
                'good' => '> 60%',
                'acceptable' => '> 40%',
                'poor' => '< 25%',
            ],
            'source_events' => ['sign_up', 'login', 'feature_used'],
            'required_config' => ['retention_cohort'],
            'category' => 'Retention',
            'tags' => ['retention', 'cohort', 'onboarding'],
        ];

        $this->glossary['d7_retention'] = [
            'name' => 'Day-7 Retention',
            'description' => 'Percentage of users who return within 7 days of first visit. Industry-standard measure of weekly stickiness.',
            'formula' => 'D7 = Users active in Day 1-7 / Users active on Day 0 × 100',
            'benchmarks' => [
                'good' => '> 40%',
                'acceptable' => '> 25%',
                'poor' => '< 15%',
            ],
            'source_events' => ['sign_up', 'login', 'feature_used'],
            'required_config' => ['retention_cohort'],
            'category' => 'Retention',
            'tags' => ['retention', 'cohort', 'weekly'],
        ];

        $this->glossary['d30_retention'] = [
            'name' => 'Day-30 Retention',
            'description' => 'Percentage of users who return within 30 days of first visit. The primary monthly retention benchmark.',
            'formula' => 'D30 = Users active in Day 1-30 / Users active on Day 0 × 100',
            'benchmarks' => [
                'good' => '> 30%',
                'acceptable' => '> 20%',
                'poor' => '< 10%',
            ],
            'source_events' => ['sign_up', 'login', 'feature_used'],
            'required_config' => ['retention_cohort'],
            'category' => 'Retention',
            'tags' => ['retention', 'cohort', 'monthly'],
        ];

        $this->glossary['churn_rate'] = [
            'name' => 'Customer Churn Rate',
            'description' => 'Percentage of customers who cancel or fail to renew in a given period. The inverse of retention.',
            'formula' => 'Churn = Customers Lost / Customers at Start × 100',
            'benchmarks' => [
                'good' => '< 2% monthly (< 24% annually)',
                'acceptable' => '< 5% monthly',
                'poor' => '> 8% monthly',
            ],
            'source_events' => ['cancellation', 'subscription_renewal', 'trial_end'],
            'required_config' => ['saas_kpi_calc.churn_warning'],
            'category' => 'Retention',
            'tags' => ['retention', 'churn', 'kpi'],
        ];

        $this->glossary['logo_churn'] = [
            'name' => 'Logo Churn',
            'description' => 'Number (or percentage) of customer accounts lost, regardless of revenue size. Complements revenue churn.',
            'formula' => 'Logo Churn = Accounts Lost / Accounts at Start × 100',
            'benchmarks' => [
                'good' => '< 3% monthly',
                'acceptable' => '< 5% monthly',
                'poor' => '> 8% monthly',
            ],
            'source_events' => ['cancellation', 'account_deactivated'],
            'required_config' => ['saas_kpi_calc'],
            'category' => 'Retention',
            'tags' => ['retention', 'churn'],
        ];

        // ── Funnel Metrics ────────────────────────────────────────────

        $this->glossary['conversion_rate'] = [
            'name' => 'Conversion Rate',
            'description' => 'Percentage of users who complete a desired action (signup, purchase, upgrade). The universal funnel efficiency metric.',
            'formula' => 'Conversion = Completed Actions / Total Entrants × 100',
            'benchmarks' => [
                'good' => '> 10% (signup-to-paid)',
                'acceptable' => '> 5%',
                'poor' => '< 2%',
            ],
            'source_events' => ['sign_up', 'subscribe', 'purchase', 'begin_checkout', 'add_to_cart'],
            'required_config' => [],
            'category' => 'Funnel',
            'tags' => ['funnel', 'kpi', 'conversion'],
        ];

        $this->glossary['trial_to_paid'] = [
            'name' => 'Trial-to-Paid Conversion Rate',
            'description' => 'Percentage of trial users who convert to a paid subscription. Critical for freemium and trial-based SaaS.',
            'formula' => 'Trial→Paid = Trial Conversions / Trial Starts × 100',
            'benchmarks' => [
                'good' => '> 60%',
                'acceptable' => '> 40%',
                'poor' => '< 25%',
            ],
            'source_events' => ['start_trial', 'subscribe', 'trial_end', 'trial_expired', 'cancellation'],
            'required_config' => ['saas_kpi_calc'],
            'category' => 'Funnel',
            'tags' => ['funnel', 'conversion', 'kpi', 'trial'],
        ];

        $this->glossary['time_to_value'] = [
            'name' => 'Time-to-Value',
            'description' => 'Median time from signup to the first aha moment / activation event. Measures onboarding velocity.',
            'formula' => 'TTV = Median(activation_timestamp - signup_timestamp)',
            'benchmarks' => [
                'good' => '< 5 minutes',
                'acceptable' => '< 30 minutes',
                'poor' => '> 1 hour',
            ],
            'source_events' => ['sign_up', 'feature_used', 'team_created'],
            'required_config' => ['growth_metrics.activation_events'],
            'category' => 'Funnel',
            'tags' => ['funnel', 'onboarding', 'velocity'],
        ];

        $this->glossary['funnel_velocity'] = [
            'name' => 'Funnel Velocity',
            'description' => 'Average time a prospect takes to move through the entire conversion funnel. Lower velocity = faster revenue realization.',
            'formula' => 'Velocity = Median(complete_timestamp - enter_timestamp) for funnel completers',
            'benchmarks' => [
                'good' => '< 3 days (signup-to-paid)',
                'acceptable' => '< 7 days',
                'poor' => '> 14 days',
            ],
            'source_events' => ['sign_up', 'start_trial', 'subscribe', 'begin_checkout', 'add_to_cart', 'purchase'],
            'required_config' => ['saas_kpi_calc'],
            'category' => 'Funnel',
            'tags' => ['funnel', 'velocity', 'revenue'],
        ];
    }
}
