<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsCostForecastService;
use ZeroBoiler\Analytics\Services\EventPolicyEngine;
use ZeroBoiler\Analytics\Services\ProviderSLAMonitor;

/**
 * Analytics governance overview command — SLA monitoring, cost forecasting, and policy compliance.
 *
 * Displays a comprehensive dashboard of provider SLA status, cost projections,
 * governance policy violations, and optimization recommendations.
 *
 * @since 84.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\ProviderSLAMonitor
 * @see \ZeroBoiler\Analytics\Services\AnalyticsCostForecastService
 * @see \ZeroBoiler\Analytics\Services\EventPolicyEngine
 */
final class AnalyticsGovernanceCommand extends Command
{
    /**
     * The console command name and signature.
     *
     * @var string
     */
    protected $signature = 'analytics:governance
        {--section=all : Section to display (sla|cost|policies|all)}
        {--format=table : Output format (table|json)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display analytics governance dashboard: SLA monitoring, cost forecasting, and policy compliance';

    /**
     * Execute the console command.
     */
    public function handle(
        ProviderSLAMonitor $slaMonitor,
        AnalyticsCostForecastService $costForecast,
        EventPolicyEngine $policyEngine,
    ): int {
        $section = (string) $this->option('section');
        $format = (string) $this->option('format');

        if ($format === 'json') {
            $this->outputJson($section, $slaMonitor, $costForecast, $policyEngine);

            return self::SUCCESS;
        }

        return $this->outputTable($section, $slaMonitor, $costForecast, $policyEngine);
    }

    /**
     * Display output in table format.
     */
    private function outputTable(
        string $section,
        ProviderSLAMonitor $slaMonitor,
        AnalyticsCostForecastService $costForecast,
        EventPolicyEngine $policyEngine,
    ): int {
        $this->components->info('📊 Analytics Governance Dashboard — v84.0.0');

        if ($section === 'all' || $section === 'sla') {
            $this->displaySLA($slaMonitor);
        }

        if ($section === 'all' || $section === 'cost') {
            $this->displayCostForecast($costForecast);
        }

        if ($section === 'all' || $section === 'policies') {
            $this->displayPolicies($policyEngine);
        }

        return self::SUCCESS;
    }

    /**
     * Display SLA monitoring section.
     */
    private function displaySLA(ProviderSLAMonitor $slaMonitor): void
    {
        $this->newLine();
        $this->components->info('🔔 Provider SLA Monitor');

        $healthMatrix = $slaMonitor->healthMatrix();
        $summary = $healthMatrix['summary'];

        $this->line(sprintf(
            '  Total: %d providers | <fg=green>Healthy: %d</> | <fg=yellow>Degraded: %d</> | <fg=red>Down: %d</>',
            $summary['total_providers'],
            $summary['healthy'],
            $summary['degraded'],
            $summary['down'],
        ));

        $slaRows = [];
        foreach ($healthMatrix['providers'] as $provider => $status) {
            $slaRows[] = [
                $provider,
                number_format($status['uptime'], 2) . '%',
                number_format($status['latency'], 1) . 'ms',
                number_format($status['p99'], 1) . 'ms',
                $status['breaches'],
                $status['sla_met'] ? '✅' : '❌',
                number_format($status['compliance'], 1) . '%',
            ];
        }

        $this->table(
            ['Provider', 'Uptime', 'Avg Lat', 'P99 Lat', 'Breaches', 'SLA', 'Compliance'],
            $slaRows,
        );

        $breachCount = $slaMonitor->summary()['breach_count'];
        if ($breachCount > 0) {
            $this->warn("  ⚠️  {$breachCount} SLA breach(es) recorded in history");
        }
    }

    /**
     * Display cost forecast section.
     */
    private function displayCostForecast(AnalyticsCostForecastService $costForecast): void
    {
        $this->newLine();
        $this->components->info('💰 Cost Forecast');

        $summary = $costForecast->summary();

        $this->line(sprintf(
            '  Budget: $%.2f | Projected: $%.2f %s',
            $summary['monthly_budget'],
            $summary['total_projected'],
            $summary['exceeds_budget'] ? '<fg=red>(EXCEEDS BUDGET)</>' : '<fg=green>(within budget)</>',
        ));

        $costRows = [];
        foreach ($summary['projections'] as $provider => $projection) {
            $changeIndicator = $projection->costChangePercentage() > 0 ? '📈' : '📉';
            $costRows[] = [
                $provider,
                '$' . number_format($projection->currentCost, 2),
                '$' . number_format($projection->projectedCost, 2),
                number_format($projection->growthRate, 1) . '%',
                number_format($projection->costChangePercentage(), 1) . '%',
                $changeIndicator,
            ];
        }

        if (! empty($costRows)) {
            $this->table(
                ['Provider', 'Current', 'Projected', 'Growth', 'Change', 'Trend'],
                $costRows,
            );
        }

        $recommendations = $summary['recommendations'];
        if (! empty($recommendations)) {
            $this->newLine();
            $this->components->info('💡 Optimization Recommendations');

            foreach (array_slice($recommendations, 0, 5) as $rec) {
                $priorityColor = $rec['priority'] === 'high' ? 'red' : 'yellow';
                $this->line(sprintf(
                    '  • [<fg=%s>%s</>] %s — est. savings: $%.2f',
                    $priorityColor,
                    strtoupper($rec['priority']),
                    $rec['description'],
                    $rec['estimated_savings'],
                ));
            }
        }
    }

    /**
     * Display governance policies section.
     */
    private function displayPolicies(EventPolicyEngine $policyEngine): void
    {
        $this->newLine();
        $this->components->info('📜 Governance Policies');

        $summary = $policyEngine->summary();

        $this->line(sprintf(
            '  Status: %s | %d policy rules configured',
            $summary['enabled'] ? '<fg=green>Enabled</>' : '<fg=red>Disabled</>',
            $summary['policy_count'],
        ));

        $violationStats = $summary['violation_stats'];

        if ($violationStats['total'] > 0) {
            $this->line(sprintf(
                '  Violations: %d total | %d blocked | %d critical',
                $violationStats['total'],
                $violationStats['blocked'],
                $violationStats['critical'],
            ));

            if (! empty($violationStats['by_event'])) {
                $eventRows = [];
                foreach ($violationStats['by_event'] as $event => $count) {
                    $eventRows[] = [$event, $count];
                }

                $this->table(
                    ['Event', 'Violations'],
                    $eventRows,
                );
            }
        } else {
            $this->info('  ✅ No policy violations recorded');
        }

        if (! empty($summary['policies'])) {
            $policyRows = [];
            foreach ($summary['policies'] as $ruleId => $policy) {
                $policyRows[] = [
                    $ruleId,
                    $policy['type'],
                    $policy['action'],
                    $policy['severity'],
                    $policy['description'] ?? '—',
                ];
            }

            $this->table(
                ['Rule ID', 'Type', 'Action', 'Severity', 'Description'],
                $policyRows,
            );
        }
    }

    /**
     * Display output in JSON format.
     */
    private function outputJson(
        string $section,
        ProviderSLAMonitor $slaMonitor,
        AnalyticsCostForecastService $costForecast,
        EventPolicyEngine $policyEngine,
    ): void {
        $output = [];

        if ($section === 'all' || $section === 'sla') {
            $output['sla'] = $slaMonitor->summary();
        }

        if ($section === 'all' || $section === 'cost') {
            $output['cost_forecast'] = $costForecast->summary();
        }

        if ($section === 'all' || $section === 'policies') {
            $output['policies'] = $policyEngine->summary();
        }

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
