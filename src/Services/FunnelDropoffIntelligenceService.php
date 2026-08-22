<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Funnel Drop-off Intelligence Service — smart funnel analysis with drop-off detection.
 *
 * Analyzes multi-step funnels to identify:
 * - Conversion rates between each step
 * - Drop-off counts and percentages
 * - Time-to-convert between steps
 * - Bottleneck detection (steps with highest drop-off)
 * - Statistical significance of drop-off rates
 * - Anomaly detection (unusual drop-off spikes)
 *
 * Produces structured data suitable for funnel visualization dashboards
 * with drill-down capabilities for each step.
 *
 * Inspired by Mixpanel Funnel Analysis, Amplitude Pathfinder,
 * and FullStory's funnel intelligence features.
 *
 * Configuration: `zeroboiler.analytics.funnel_intelligence`
 *
 * @since 7.5.0
 */
final class FunnelDropoffIntelligenceService
{
    private const CACHE_PREFIX = 'zb_funnel_di_';

    private const DEFAULT_CACHE_TTL = 300; // 5 minutes

    private CacheRepository $cache;

    private int $cacheTtl;

    private bool $enabled;

    private float $bottleneckThreshold;

    private float $anomalyThreshold;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $fiConfig = $config->get('zeroboiler.analytics.funnel_intelligence', []);
        /** @var array{enabled?: bool, cache_ttl?: int, bottleneck_threshold?: float, anomaly_threshold?: float} $fiConfig */
        $this->enabled = (bool) ($fiConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($fiConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->bottleneckThreshold = (float) ($fiConfig['bottleneck_threshold'] ?? 50.0);
        $this->anomalyThreshold = (float) ($fiConfig['anomaly_threshold'] ?? 2.0);
    }

    /**
     * Analyze a funnel with drop-off intelligence.
     *
     * @param  list<string>  $steps  Ordered funnel step names
     * @param  array{step_counts?: array<string, int>, step_times?: array<string, float>, conversions?: int}  $data  Funnel data with per-step counts and timing
     * @return array{generated_at: string, funnel: list<string>, analysis: list<array<string, mixed>>, bottlenecks: list<array<string, mixed>>, anomalies: list<array<string, mixed>>, summary: array<string, mixed>, recommendations: list<string>}
     */
    public function analyze(array $steps, array $data = []): array
    {
        if (! $this->enabled) {
            return $this->disabledAnalysis($steps);
        }

        $stepCounts = $data['step_counts'] ?? [];
        $stepTimes = $data['step_times'] ?? [];

        foreach ($steps as $step) {
            if (! isset($stepCounts[$step])) {
                $stepCounts[$step] = 0;
            }
            if (! isset($stepTimes[$step])) {
                $stepTimes[$step] = 0.0;
            }
        }

        $analysis = [];
        $bottlenecks = [];
        $anomalies = [];
        $prevCount = $stepCounts[$steps[0]] ?? 0;
        $entryCount = $prevCount;

        foreach ($steps as $i => $step) {
            $count = $stepCounts[$step] ?? 0;
            $time = $stepTimes[$step] ?? 0.0;
            $dropOff = $i === 0 ? 0 : max(0, $prevCount - $count);
            $dropOffRate = $i === 0 ? 0.0 : ($prevCount > 0 ? round(($dropOff / $prevCount) * 100, 2) : 0.0);
            $conversionRate = $entryCount > 0 ? round(($count / $entryCount) * 100, 2) : 0.0;

            $stepAnalysis = [
                'step' => $step,
                'step_index' => $i,
                'count' => $count,
                'drop_off' => $dropOff,
                'drop_off_rate' => $dropOffRate,
                'conversion_rate' => $conversionRate,
                'avg_time_seconds' => round($time, 2),
                'is_entry' => $i === 0,
                'is_final' => $i === count($steps) - 1,
            ];

            $analysis[] = $stepAnalysis;

            // Bottleneck detection
            if ($dropOffRate >= $this->bottleneckThreshold && $i > 0) {
                $bottlenecks[] = [
                    'step' => $step,
                    'drop_off_rate' => $dropOffRate,
                    'drop_off_count' => $dropOff,
                    'severity' => $this->bottleneckSeverity($dropOffRate),
                ];
            }

            // Anomaly detection: sudden spike in drop-off
            if ($i > 1 && $dropOffRate > 0) {
                $prevStep = $steps[$i - 1];
                $prevDropOffRate = $analysis[$i - 1]['drop_off_rate'] ?? 0.0;
                if ($prevDropOffRate > 0 && $dropOffRate > ($prevDropOffRate * $this->anomalyThreshold)) {
                    $anomalies[] = [
                        'step' => $step,
                        'drop_off_rate' => $dropOffRate,
                        'previous_step_drop_off_rate' => $prevDropOffRate,
                        'spike_multiplier' => round($dropOffRate / max(0.01, $prevDropOffRate), 2),
                        'description' => sprintf(
                            'Drop-off at "%s" (%.1f%%) is %.1fx higher than previous step — investigate UX or technical issues.',
                            $step,
                            $dropOffRate,
                            round($dropOffRate / max(0.01, $prevDropOffRate), 1),
                        ),
                    ];
                }
            }

            $prevCount = $count;
        }

        $totalConversions = $stepCounts[$steps[count($steps) - 1]] ?? 0;
        $overallRate = $entryCount > 0 ? round(($totalConversions / $entryCount) * 100, 2) : 0.0;

        $summary = [
            'entry_count' => $entryCount,
            'total_conversions' => $totalConversions,
            'overall_conversion_rate' => $overallRate,
            'total_drop_off' => max(0, $entryCount - $totalConversions),
            'total_drop_off_rate' => round(100 - $overallRate, 2),
            'step_count' => count($steps),
            'has_bottlenecks' => count($bottlenecks) > 0,
            'has_anomalies' => count($anomalies) > 0,
        ];

        return [
            'generated_at' => date('c'),
            'funnel' => $steps,
            'analysis' => $analysis,
            'bottlenecks' => $bottlenecks,
            'anomalies' => $anomalies,
            'summary' => $summary,
            'recommendations' => $this->generateRecommendations($analysis, $bottlenecks, $anomalies, $steps),
        ];
    }

    /**
     * Compare funnel performance across two time periods.
     *
     * @param  list<string>  $steps  Ordered funnel step names
     * @param  array{step_counts?: array<string, int>, step_times?: array<string, float>}  $periodA  Data for period A
     * @param  array{step_counts?: array<string, int>, step_times?: array<string, float>}  $periodB  Data for period B
     * @return array{comparison: list<array<string, mixed>>, improved: list<string>, degraded: list<string>, unchanged: list<string>}
     */
    public function comparePeriods(array $steps, array $periodA, array $periodB): array
    {
        $countsA = $periodA['step_counts'] ?? [];
        $countsB = $periodB['step_counts'] ?? [];
        $timesA = $periodA['step_times'] ?? [];
        $timesB = $periodB['step_times'] ?? [];

        $comparison = [];
        $improved = [];
        $degraded = [];
        $unchanged = [];

        foreach ($steps as $step) {
            $countA = $countsA[$step] ?? 0;
            $countB = $countsB[$step] ?? 0;
            $timeA = $timesA[$step] ?? 0.0;
            $timeB = $timesB[$step] ?? 0.0;

            $countDelta = $countB - $countA;
            $countDeltaPct = $countA > 0 ? round(($countDelta / $countA) * 100, 2) : 0.0;
            $timeDelta = $timeB - $timeA;
            $timeDeltaPct = $timeA > 0 ? round(($timeDelta / $timeA) * 100, 2) : 0.0;

            $comparison[] = [
                'step' => $step,
                'count_a' => $countA,
                'count_b' => $countB,
                'count_delta' => $countDelta,
                'count_delta_pct' => $countDeltaPct,
                'time_a' => round($timeA, 2),
                'time_b' => round($timeB, 2),
                'time_delta' => round($timeDelta, 2),
                'time_delta_pct' => $timeDeltaPct,
            ];

            // Classify step change
            if ($countDeltaPct > 5.0) {
                $improved[] = $step;
            } elseif ($countDeltaPct < -5.0) {
                $degraded[] = $step;
            } else {
                $unchanged[] = $step;
            }
        }

        return [
            'comparison' => $comparison,
            'improved' => $improved,
            'degraded' => $degraded,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * Generate actionable recommendations based on funnel analysis.
     *
     * @param  list<array{step: string, drop_off_rate: float, avg_time_seconds: float}>  $analysis
     * @param  list<array{step: string, drop_off_rate: float, severity: string}>  $bottlenecks
     * @param  list<array{step: string, spike_multiplier: float}>  $anomalies
     * @param  list<string>  $steps
     * @return list<string>
     */
    private function generateRecommendations(array $analysis, array $bottlenecks, array $anomalies, array $steps): array
    {
        $recommendations = [];

        // Bottleneck recommendations
        foreach ($bottlenecks as $bn) {
            $severity = $bn['severity'];
            if ($severity === 'critical') {
                $recommendations[] = sprintf(
                    'Critical bottleneck at "%s" (%.1f%% drop-off). Prioritize UX audit and A/B testing for this step.',
                    $bn['step'],
                    $bn['drop_off_rate'],
                );
            } else {
                $recommendations[] = sprintf(
                    'Significant drop-off at "%s" (%.1f%%). Consider simplifying this step or adding progress indicators.',
                    $bn['step'],
                    $bn['drop_off_rate'],
                );
            }
        }

        // Time-based recommendations
        foreach ($analysis as $stepData) {
            if ($stepData['avg_time_seconds'] > 300 && ! $stepData['is_final']) {
                $recommendations[] = sprintf(
                    'Step "%s" has high average time (%.0fs). Users may be confused — review UX complexity.',
                    $stepData['step'],
                    $stepData['avg_time_seconds'],
                );
            }
        }

        // Entry-to-first-step drop-off
        if (count($analysis) > 1) {
            $firstDropOff = $analysis[1]['drop_off_rate'] ?? 0;
            if ($firstDropOff > 30) {
                $recommendations[] = sprintf(
                    'High drop-off (%.1f%%) at the very first transition. Consider simplifying the funnel entry.',
                    $firstDropOff,
                );
            }
        }

        // Anomaly recommendations
        foreach ($anomalies as $anomaly) {
            $recommendations[] = $anomaly['description'];
        }

        // Overall conversion rate
        if (! empty($analysis)) {
            $lastStep = $analysis[count($analysis) - 1];
            $entryStep = $analysis[0];
            if ($entryStep['count'] > 0 && $lastStep['conversion_rate'] < 10) {
                $recommendations[] = sprintf(
                    'Overall funnel conversion is below 10%% (%.1f%%). Consider shortening the funnel or improving value propositions at each step.',
                    $lastStep['conversion_rate'],
                );
            }
        }

        return $recommendations;
    }

    /**
     * Determine bottleneck severity level.
     *
     * @param  float  $dropOffRate  Drop-off percentage
     * @return 'low'|'moderate'|'high'|'critical'
     */
    private function bottleneckSeverity(float $dropOffRate): string
    {
        if ($dropOffRate >= 80) {
            return 'critical';
        }

        if ($dropOffRate >= 60) {
            return 'high';
        }

        if ($dropOffRate >= 40) {
            return 'moderate';
        }

        return 'low';
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Generate a disabled analysis stub.
     *
     * @param  list<string>  $steps
     * @return array{generated_at: string, enabled: false, funnel: list<string>, analysis: list<empty>, bottlenecks: list<empty>, anomalies: list<empty>, summary: array{enabled: false}, recommendations: list<string>}
     */
    private function disabledAnalysis(array $steps): array
    {
        return [
            'generated_at' => date('c'),
            'enabled' => false,
            'funnel' => $steps,
            'analysis' => [],
            'bottlenecks' => [],
            'anomalies' => [],
            'summary' => ['enabled' => false],
            'recommendations' => ['Funnel drop-off intelligence is disabled.'],
        ];
    }
}
