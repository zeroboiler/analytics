<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Analytics event query engine for structured data retrieval.
 *
 * Provides high-level query methods for common SaaS analytics patterns:
 * time-series, event breakdowns, user funnels, and conversion metrics.
 * All queries are cache-backed and designed for dashboard widgets.
 *
 * Unlike AnalyticsDataService (which focuses on recording/writing),
 * this engine focuses on reading and composing complex queries.
 *
 * @version 5.9.0
 *
 * @since 1.0.0
 */
final class EventQueryEngine
{
    private const CACHE_PREFIX = 'zb_query_';

    private const DEFAULT_TTL = 300;

    private CacheRepository $cache;

    private AnalyticsMetrics $metrics;

    private int $ttl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  AnalyticsMetrics  $metrics  Metrics instance
     * @param  int  $ttl  Cache TTL for query results in seconds
     */
    public function __construct(
        CacheRepository $cache,
        AnalyticsMetrics $metrics,
        int $ttl = self::DEFAULT_TTL,
    ){
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->ttl = $ttl;
    }

    // ── Time-Series Queries ──────────────────────────────────────────

    /**
     * Get event count time-series for one or more events.
     *
     * Returns per-day event counts for the specified period.
     * Useful for sparkline charts and trend visualization.
     *
     * @param  list<string>  $eventNames  Event names to query
     * @param  int  $days  Number of days to look back (1-90)
     * @return array{dates: list<string>, series: array<string, list<int>>, total: int, trend: float}
     */
    public function timeSeries(array $eventNames, int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $dates = [];
        $series = array_fill_keys($eventNames, []);
        $totals = array_fill_keys($eventNames, 0);

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $dates[] = $date;

            foreach ($eventNames as $eventName) {
                $count = $this->getEventCountForDate($eventName, $date);
                $series[$eventName][] = $count;
                $totals[$eventName] += $count;
            }
        }

        $grandTotal = array_sum($totals);
        $trend = $this->calculateTrend($eventNames, $days);

        return [
            'dates' => $dates,
            'series' => $series,
            'total' => $grandTotal,
            'trend' => $trend,
        ];
    }

    /**
     * Get event counts grouped by hour for the current day.
     *
     * Useful for intraday activity heatmaps.
     *
     * @param  string  $eventName  Event name to query
     * @return array{hours: list<int>, counts: list<int>, peak_hour: int|null, peak_count: int}
     */
    public function hourlyDistribution(string $eventName): array
    {
        $hours = range(0, 23);
        $counts = array_fill(0, 24, 0);
        $peakHour = null;
        $peakCount = 0;

        $report = $this->metrics->report();
        $hourlyData = $report['hourly_distribution'] ?? [];

        foreach ($hours as $hour) {
            $key = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $counts[$hour] = (int) ($hourlyData[$eventName][$key] ?? 0);

            if ($counts[$hour] > $peakCount) {
                $peakCount = $counts[$hour];
                $peakHour = $hour;
            }
        }

        return [
            'hours' => $hours,
            'counts' => $counts,
            'peak_hour' => $peakHour,
            'peak_count' => $peakCount,
        ];
    }

    /**
     * Get day-of-week distribution for an event.
     *
     * Reveals weekly patterns in event activity.
     *
     * @param  string  $eventName  Event name to query
     * @param  int  $weeks  Number of weeks to aggregate (1-12)
     * @return array{days: list<string>, counts: list<int>, totals: array<string, int>, peak_day: string|null}
     */
    public function dayOfWeekDistribution(string $eventName, int $weeks = 4): array
    {
        $weeks = max(1, min(12, $weeks));
        $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $counts = array_fill(0, 7, 0);

        for ($w = 0; $w < $weeks; $w++) {
            for ($d = 0; $d < 7; $d++) {
                $daysAgo = ($w * 7) + (6 - $d);
                $date = date('Y-m-d', strtotime("-{$daysAgo} days"));
                $counts[$d] += $this->getEventCountForDate($eventName, $date);
            }
        }

        $peakDay = null;
        $maxCount = 0;
        foreach ($counts as $idx => $count) {
            if ($count > $maxCount) {
                $maxCount = $count;
                $peakDay = $dayNames[$idx];
            }
        }

        $totals = [];
        foreach ($dayNames as $idx => $name) {
            $totals[$name] = $counts[$idx];
        }

        return [
            'days' => $dayNames,
            'counts' => $counts,
            'totals' => $totals,
            'peak_day' => $peakDay,
        ];
    }

    // ── Event Breakdown Queries ──────────────────────────────────────

    /**
     * Get event counts grouped by category.
     *
     * Aggregates total event counts across all events in each
     * category (ecommerce, saas, engagement) for the given period.
     *
     * @param  int  $days  Number of days to aggregate (1-90)
     * @return array{categories: array<string, int>, total: int, top_category: string|null, percentages: array<string, float>}
     */
    public function categoryBreakdown(int $days = 7): array
    {
        $categories = [
            'ecommerce' => EventCatalog::category('ecommerce'),
            'saas' => EventCatalog::category('saas'),
            'engagement' => EventCatalog::category('engagement'),
        ];

        $counts = [];
        $total = 0;

        foreach ($categories as $categoryName => $events) {
            $categoryTotal = 0;

            foreach (array_keys($events) as $eventName) {
                for ($d = 0; $d < $days; $d++) {
                    $date = date('Y-m-d', strtotime("-{$d} days"));
                    $categoryTotal += $this->getEventCountForDate($eventName, $date);
                }
            }

            $counts[$categoryName] = $categoryTotal;
            $total += $categoryTotal;
        }

        $topCategory = null;
        $maxCount = 0;
        foreach ($counts as $name => $count) {
            if ($count > $maxCount) {
                $maxCount = $count;
                $topCategory = $name;
            }
        }

        $percentages = [];
        foreach ($counts as $name => $count) {
            $percentages[$name] = $total > 0 ? round($count / $total, 4) : 0.0;
        }

        return [
            'categories' => $counts,
            'total' => $total,
            'top_category' => $topCategory,
            'percentages' => $percentages,
        ];
    }

    /**
     * Get top N events by count with metadata.
     *
     * Returns event names, counts, categories, and provider mappings
     * for the most frequently dispatched events.
     *
     * @param  int  $limit  Number of top events (1-50)
     * @return list<array{name: string, count: int, category: string, ga4: string|null, meta: string|null, posthog: string|null, plausible: string|null}>
     */
    public function topEventsWithMeta(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $report = $this->metrics->report();
        $eventCounts = $report['event_counts'] ?? [];
        arsort($eventCounts);

        $result = [];
        $count = 0;

        foreach ($eventCounts as $name => $cnt) {
            $entry = EventCatalog::get($name);
            $result[] = [
                'name' => $name,
                'count' => $cnt,
                'category' => $entry['category'] ?? 'unknown',
                'ga4' => $entry['ga4'] ?? null,
                'meta' => $entry['meta'] ?? null,
                'posthog' => $entry['posthog'] ?? null,
                'plausible' => $entry['plausible'] ?? null,
            ];
            $count++;

            if ($count >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Get events sorted by percentage change (growth/decline).
     *
     * Compares event counts between the current and previous period
     * to identify trending up or down events.
     *
     * @param  int  $periodDays  Period length in days (default: 7)
     * @param  string  $direction  'up', 'down', or 'all'
     * @param  int  $limit  Max results
     * @return list<array{name: string, current: int, previous: int, change_pct: float, direction: 'up'|'down'|'stable'}>
     */
    public function trendingEvents(
        int $periodDays = 7,
        string $direction = 'all',
        int $limit = 10,
    ): array {
        $report = $this->metrics->report();
        $eventCounts = $report['event_counts'] ?? [];
        $results = [];

        foreach ($eventCounts as $name => $_) {
            $current = 0;
            $previous = 0;

            for ($d = 0; $d < $periodDays; $d++) {
                $currentDate = date('Y-m-d', strtotime("-{$d} days"));
                $previousDate = date('Y-m-d', strtotime("-" . ($d + $periodDays) . ' days'));
                $current += $this->getEventCountForDate($name, $currentDate);
                $previous += $this->getEventCountForDate($name, $previousDate);
            }

            $changePct = $previous > 0
                ? round(($current - $previous) / $previous, 4)
                : ($current > 0 ? 1.0 : 0.0);

            $dir = $changePct > 0.05 ? 'up' : ($changePct < -0.05 ? 'down' : 'stable');

            if ($direction !== 'all' && $dir !== $direction) {
                continue;
            }

            $results[] = [
                'name' => $name,
                'current' => $current,
                'previous' => $previous,
                'change_pct' => $changePct,
                'direction' => $dir,
            ];
        }

        usort($results, fn (array $a, array $b): int => abs($b['change_pct']) <=> abs($a['change_pct']));

        return array_slice($results, 0, $limit);
    }

    // ── Funnel Queries ──────────────────────────────────────────────

    /**
     * Analyze a named funnel with step-by-step conversion rates.
     *
     * Uses EventCatalog::checkoutFunnel(), saasFunnelEvents(), etc.
     * for pre-defined funnel steps, or pass custom steps.
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  list<string>  $steps  Ordered step event names
     * @param  int  $days  Analysis period in days (1-90)
     * @return array{funnel: string, period_days: int, steps: list<array{name: string, count: int, conversion_from_first: float, drop_off_from_previous: float}>, overall_conversion: float, total_entered: int, total_completed: int}
     */
    public function funnelAnalysis(string $funnelName, array $steps, int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $stepCounts = [];

        foreach ($steps as $stepEvent) {
            $total = 0;
            for ($d = 0; $d < $days; $d++) {
                $date = date('Y-m-d', strtotime("-{$d} days"));
                $total += $this->getEventCountForDate($stepEvent, $date);
            }
            $stepCounts[$stepEvent] = $total;
        }

        $firstCount = $stepCounts[$steps[0]] ?? 0;
        $result = [];

        foreach ($steps as $index => $stepEvent) {
            $count = $stepCounts[$stepEvent] ?? 0;

            $dropOff = 0.0;
            if ($index > 0) {
                $prevCount = $stepCounts[$steps[$index - 1]] ?? 0;
                $dropOff = $prevCount > 0 ? round(1 - ($count / $prevCount), 4) : 0.0;
            }

            $result[] = [
                'name' => $stepEvent,
                'count' => $count,
                'conversion_from_first' => $firstCount > 0 ? round($count / $firstCount, 4) : 0.0,
                'drop_off_from_previous' => $dropOff,
            ];
        }

        $lastCount = end($stepCounts) ?: 0;
        $overallConversion = $firstCount > 0 ? round($lastCount / $firstCount, 4) : 0.0;

        return [
            'funnel' => $funnelName,
            'period_days' => $days,
            'steps' => $result,
            'overall_conversion' => $overallConversion,
            'total_entered' => $firstCount,
            'total_completed' => $lastCount,
        ];
    }

    /**
     * Compare funnel conversion rates between two periods.
     *
     * Useful for A/B test analysis or month-over-month funnel comparisons.
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  list<string>  $steps  Ordered step event names
     * @param  int  $currentDays  Current period length
     * @param  int  $previousDays  Previous period length (0 = same as current, offset)
     * @return array{funnel: string, current: array{steps: list<array{name: string, count: int, conversion: float}>, overall: float}, previous: array{steps: list<array{name: string, count: int, conversion: float}>, overall: float}, delta: float}
     */
    public function funnelComparison(
        string $funnelName,
        array $steps,
        int $currentDays = 7,
        int $previousDays = 0,
    ): array {
        $previousDays = $previousDays > 0 ? $previousDays : $currentDays;

        $current = $this->funnelAnalysis($funnelName . '_current', $steps, $currentDays);
        $previous = $this->funnelAnalysis(
            $funnelName . '_previous',
            $steps,
            $previousDays,
        );

        // Re-run previous with offset
        $prevStepCounts = [];
        foreach ($steps as $stepEvent) {
            $total = 0;
            for ($d = $currentDays; $d < $currentDays + $previousDays; $d++) {
                $date = date('Y-m-d', strtotime("-{$d} days"));
                $total += $this->getEventCountForDate($stepEvent, $date);
            }
            $prevStepCounts[$stepEvent] = $total;
        }

        $prevFirstCount = $prevStepCounts[$steps[0]] ?? 0;
        $prevSteps = [];
        foreach ($steps as $stepEvent) {
            $count = $prevStepCounts[$stepEvent] ?? 0;
            $prevSteps[] = [
                'name' => $stepEvent,
                'count' => $count,
                'conversion' => $prevFirstCount > 0 ? round($count / $prevFirstCount, 4) : 0.0,
            ];
        }

        $prevLastCount = end($prevStepCounts) ?: 0;
        $prevOverall = $prevFirstCount > 0 ? round($prevLastCount / $prevFirstCount, 4) : 0.0;
        $delta = round($current['overall_conversion'] - $prevOverall, 4);

        return [
            'funnel' => $funnelName,
            'current' => [
                'steps' => array_map(
                    fn (array $s): array => [
                        'name' => $s['name'],
                        'count' => $s['count'],
                        'conversion' => $s['conversion_from_first'],
                    ],
                    $current['steps'],
                ),
                'overall' => $current['overall_conversion'],
            ],
            'previous' => [
                'steps' => $prevSteps,
                'overall' => $prevOverall,
            ],
            'delta' => $delta,
        ];
    }

    // ── Conversion Queries ───────────────────────────────────────────

    /**
     * Calculate conversion rate between two events.
     *
     * Measures how often users who fire event A also fire event B.
     *
     * @param  string  $fromEvent  Source event (e.g. 'sign_up')
     * @param  string  $toEvent  Target event (e.g. 'subscribe')
     * @param  int  $days  Analysis period
     * @return array{from: string, to: string, period_days: int, from_count: int, to_count: int, rate: float}
     */
    public function conversionRate(string $fromEvent, string $toEvent, int $days = 7): array
    {
        $fromCount = 0;
        $toCount = 0;

        for ($d = 0; $d < $days; $d++) {
            $date = date('Y-m-d', strtotime("-{$d} days"));
            $fromCount += $this->getEventCountForDate($fromEvent, $date);
            $toCount += $this->getEventCountForDate($toEvent, $date);
        }

        return [
            'from' => $fromEvent,
            'to' => $toEvent,
            'period_days' => $days,
            'from_count' => $fromCount,
            'to_count' => $toCount,
            'rate' => $fromCount > 0 ? round($toCount / $fromCount, 4) : 0.0,
        ];
    }

    /**
     * Get multi-step conversion rates for the SaaS signup funnel.
     *
     * Pre-configured funnel: sign_up → email_verified → login → subscribe.
     *
     * @param  int  $days  Analysis period
     * @return array{period_days: int, steps: list<array{event: string, count: int, cumulative_rate: float, step_rate: float}>, overall_rate: float}
     */
    public function signupFunnel(int $days = 7): array
    {
        $steps = ['sign_up', 'email_verified', 'login', 'subscribe'];

        return $this->funnelAnalysis('signup_funnel', $steps, $days);
    }

    /**
     * Get multi-step conversion rates for the e-commerce checkout funnel.
     *
     * Pre-configured funnel: view_item → add_to_cart → begin_checkout → purchase.
     *
     * @param  int  $days  Analysis period
     * @return array{period_days: int, steps: list<array{event: string, count: int, cumulative_rate: float, step_rate: float}>, overall_rate: float}
     */
    public function checkoutFunnelAnalysis(int $days = 7): array
    {
        $steps = ['view_item', 'add_to_cart', 'begin_checkout', 'purchase'];

        return $this->funnelAnalysis('checkout_funnel', $steps, $days);
    }

    /**
     * Get trial-to-paid conversion metrics.
     *
     * @param  int  $days  Analysis period
     * @return array{period_days: int, trials: int, converted: int, rate: float, expired: int, expiry_rate: float}
     */
    public function trialConversion(int $days = 30): array
    {
        $trials = 0;
        $converted = 0;
        $expired = 0;

        for ($d = 0; $d < $days; $d++) {
            $date = date('Y-m-d', strtotime("-{$d} days"));
            $trials += $this->getEventCountForDate('start_trial', $date);
            $converted += $this->getEventCountForDate('trial_converted', $date);
            $expired += $this->getEventCountForDate('trial_end', $date);
        }

        return [
            'period_days' => $days,
            'trials' => $trials,
            'converted' => $converted,
            'rate' => $trials > 0 ? round($converted / $trials, 4) : 0.0,
            'expired' => $expired,
            'expiry_rate' => $trials > 0 ? round($expired / $trials, 4) : 0.0,
        ];
    }

    // ── Cohort Queries ──────────────────────────────────────────────

    /**
     * Build a simple retention cohort table.
     *
     * For each cohort day, tracks how many events occurred on day 0,
     * day 1, day 3, day 7, etc.
     *
     * @param  string  $event  Base event name
     * @param  int  $cohortDays  Number of cohorts to include (1-14)
     * @param  list<int>  $retentionDays  Which day offsets to compute
     * @return array{event: string, cohorts: list<array{date: string, day_0: int, retention: array<int, int|null>}>}
     */
    public function retentionCohort(
        string $event,
        int $cohortDays = 7,
        array $retentionDays = [1, 3, 7],
    ): array {
        $cohortDays = max(1, min(14, $cohortDays));
        $cohorts = [];

        for ($c = 0; $c < $cohortDays; $c++) {
            $baseDate = date('Y-m-d', strtotime("-{$c} days"));
            $day0Count = $this->getEventCountForDate($event, $baseDate);

            $retention = [];
            foreach ($retentionDays as $rDay) {
                if ($rDay === 0) {
                    $retention[0] = $day0Count;

                    continue;
                }

                $rDate = date('Y-m-d', strtotime("-{$c} days +{$rDay} days"));

                if ($rDate > date('Y-m-d')) {
                    $retention[$rDay] = null;
                } else {
                    $retention[$rDay] = $this->getEventCountForDate($event, $rDate);
                }
            }

            $cohorts[] = [
                'date' => $baseDate,
                'day_0' => $day0Count,
                'retention' => $retention,
            ];
        }

        return [
            'event' => $event,
            'cohorts' => $cohorts,
        ];
    }

    // ── Provider Queries ─────────────────────────────────────────────

    /**
     * Get dispatch statistics across all providers.
     *
     * Aggregates success/failure/total counts per provider
     * for the specified period.
     *
     * @param  int  $days  Analysis period
     * @return array{period_days: int, providers: array<string, array{success: int, failure: int, total: int, success_rate: float}>, totals: array{success: int, failure: int, total: int}}
     */
    public function providerDispatchStats(int $days = 1): array
    {
        $providers = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook'];
        $results = [];
        $totalSuccess = 0;
        $totalFailure = 0;

        foreach ($providers as $provider) {
            $success = 0;
            $failure = 0;

            for ($d = 0; $d < $days; $d++) {
                $date = date('Y-m-d', strtotime("-{$d} days"));
                $successKey = self::CACHE_PREFIX . "prov_success_{$date}_{$provider}";
                $failureKey = self::CACHE_PREFIX . "prov_fail_{$date}_{$provider}";

                $success += (int) ($this->cache->get($successKey) ?? 0);
                $failure += (int) ($this->cache->get($failureKey) ?? 0);
            }

            $total = $success + $failure;
            $results[$provider] = [
                'success' => $success,
                'failure' => $failure,
                'total' => $total,
                'success_rate' => $total > 0 ? round($success / $total, 4) : 0.0,
            ];

            $totalSuccess += $success;
            $totalFailure += $failure;
        }

        return [
            'period_days' => $days,
            'providers' => $results,
            'totals' => [
                'success' => $totalSuccess,
                'failure' => $totalFailure,
                'total' => $totalSuccess + $totalFailure,
            ],
        ];
    }

    // ── Composite Dashboard Queries ────────────────────────────────

    /**
     * Get a SaaS product analytics summary for dashboard widgets.
     *
     * Combines user, revenue, funnel, and provider metrics into
     * a single response optimized for SaaS product dashboards.
     *
     * @param  string  $currency  ISO 4217 currency code
     * @param  int  $days  Analysis period
     * @return array{users: array{signups: int, logins: int, trial_starts: int}, revenue: array{daily: float, monthly: float, subscriptions: int}, funnel: array{signup_to_subscribe: float, trial_conversion: float, checkout_rate: float}, engagement: array{total_events: int, top_events: list<array{name: string, count: int}>, page_views: int}, providers: array<string, array{success_rate: float}>, generated_at: string, period_days: int}
     */
    public function saasDashboardSummary(string $currency = 'USD', int $days = 7): array
    {
        $signups = 0;
        $logins = 0;
        $trialStarts = 0;
        $subscriptions = 0;
        $pageViews = 0;
        $totalEvents = 0;

        for ($d = 0; $d < $days; $d++) {
            $date = date('Y-m-d', strtotime("-{$d} days"));
            $signups += $this->getEventCountForDate('sign_up', $date);
            $logins += $this->getEventCountForDate('login', $date);
            $trialStarts += $this->getEventCountForDate('start_trial', $date);
            $subscriptions += $this->getEventCountForDate('subscribe', $date);
            $pageViews += $this->getEventCountForDate('page_view', $date);
        }

        $report = $this->metrics->report();
        foreach ($report['event_counts'] ?? [] as $count) {
            $totalEvents += $count;
        }

        $signupSubscribe = $this->conversionRate('sign_up', 'subscribe', $days);
        $trialConv = $this->trialConversion(min($days, 30));
        $checkoutFunnel = $this->checkoutFunnelAnalysis($days);

        $providerStats = $this->providerDispatchStats(1);
        $providerRates = [];
        foreach ($providerStats['providers'] as $name => $stats) {
            if ($stats['total'] > 0) {
                $providerRates[$name] = ['success_rate' => $stats['success_rate']];
            }
        }

        $dailyRevenue = 0.0;
        $monthlyRevenue = 0.0;
        $today = date('Y-m-d');
        $month = date('Y-m');

        $dailyRevKey = self::CACHE_PREFIX . "rev_day_{$today}_{$currency}";
        $monthlyRevKey = self::CACHE_PREFIX . "rev_month_{$month}_{$currency}";

        $dailyRevenue = (float) ($this->cache->get($dailyRevKey) ?? 0);
        $monthlyRevenue = (float) ($this->cache->get($monthlyRevKey) ?? 0);

        $reportData = $this->metrics->report();
        $eventCounts = $reportData['event_counts'] ?? [];
        arsort($eventCounts);
        $topEvents = [];
        $count = 0;
        foreach ($eventCounts as $name => $cnt) {
            $topEvents[] = ['name' => $name, 'count' => $cnt];
            $count++;
            if ($count >= 5) {
                break;
            }
        }

        return [
            'users' => [
                'signups' => $signups,
                'logins' => $logins,
                'trial_starts' => $trialStarts,
            ],
            'revenue' => [
                'daily' => $dailyRevenue,
                'monthly' => $monthlyRevenue,
                'subscriptions' => $subscriptions,
            ],
            'funnel' => [
                'signup_to_subscribe' => $signupSubscribe['rate'],
                'trial_conversion' => $trialConv['rate'],
                'checkout_rate' => $checkoutFunnel['overall_conversion'],
            ],
            'engagement' => [
                'total_events' => $totalEvents,
                'top_events' => $topEvents,
                'page_views' => $pageViews,
            ],
            'providers' => $providerRates,
            'generated_at' => date('c'),
            'period_days' => $days,
        ];
    }

    // ── Private Helpers ───────────────────────────────────────────────

    /**
     * Get event count for a specific event on a specific day.
     *
     * @param  string  $eventName  Event name
     * @param  string  $date  Date in Y-m-d format
     */
    private function getEventCountForDate(string $eventName, string $date): int
    {
        $key = self::CACHE_PREFIX . "evt_day_{$date}_{$eventName}";
        $count = $this->cache->get($key);

        return is_int($count) ? $count : 0;
    }

    /**
     * Calculate trend percentage between current and previous period.
     *
     * @param  list<string>  $eventNames  Event names
     * @param  int  $days  Period days
     * @return float  Trend percentage (positive = growth, negative = decline)
     */
    private function calculateTrend(array $eventNames, int $days): float
    {
        $currentTotal = 0;
        $previousTotal = 0;

        for ($d = 0; $d < $days; $d++) {
            $currentDate = date('Y-m-d', strtotime("-{$d} days"));
            $previousDate = date('Y-m-d', strtotime("-" . ($d + $days) . ' days'));

            foreach ($eventNames as $eventName) {
                $currentTotal += $this->getEventCountForDate($eventName, $currentDate);
                $previousTotal += $this->getEventCountForDate($eventName, $previousDate);
            }
        }

        return $previousTotal > 0
            ? round(($currentTotal - $previousTotal) / $previousTotal, 4)
            : 0.0;
    }
}
