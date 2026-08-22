<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AnalyticsDeployGate;
use ZeroBoiler\Analytics\Services\EventHealthScoringEngine;

/**
 * Analytics Deploy Gate CLI command.
 *
 * Validates analytics instrumentation before deployment.
 * Can be used in CI/CD pipelines to prevent broken analytics from reaching production.
 *
 * Usage:
 *   php artisan zb:analytics:deploy-gate
 *   php artisan zb:analytics:deploy-gate --include-health
 *   php artisan zb:analytics:deploy-gate --json
 *   php artisan zb:analytics:deploy-gate --strict
 *   php artisan zb:analytics:deploy-gate --clear-health
 *   php artisan zb:analytics:deploy-gate --events=sign_up,purchase,login
 *
 * Exit codes:
 *   0 = All checks passed
 *   1 = One or more checks failed
 *
 * @since 80.0.0
 */
final class AnalyticsDeployGateCommand extends Command
{
    /** @var string The console command name */
    protected $signature = 'zb:analytics:deploy-gate
        {--include-health : Include event health baseline check}
        {--json : Output results as JSON}
        {--strict : Treat warnings as failures}
        {--clear-health : Clear all cached health data}
        {--events=* : Specific event names to check (comma-separated)}';

    /** @var string The console command description */
    protected $description = 'Run pre-deployment analytics validation gate (CI/CD integration)';

    private AnalyticsDeployGate $gate;

    private EventHealthScoringEngine $healthEngine;

    /**
     * @param  AnalyticsDeployGate  $gate
     * @param  EventHealthScoringEngine  $healthEngine
     */
    public function __construct(AnalyticsDeployGate $gate, EventHealthScoringEngine $healthEngine){
        parent::__construct();
        $this->gate = $gate;
        $this->healthEngine = $healthEngine;
    }

    /**
     * Execute the console command.
     *
     * @return int Exit code (0 = pass, 1 = fail)
     */
    #[Override]
    public function handle(): int
    {
        $this->outputTitle();

        // Handle clear-health action
        if ($this->option('clear-health')) {
            $this->healthEngine->clearAllStats();
            $this->info('✅ All cached health data cleared.');
            return 0;
        }

        // Build options
        $options = [
            'include_health' => (bool) $this->option('include-health'),
            'event_names' => $this->option('events'),
        ];

        // Run gate evaluation
        $result = $this->gate->evaluate($options);

        // Output results
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->displayResults($result);
        }

        // Display system health if health flag is set
        if ($this->option('include-health')) {
            $this->displaySystemHealth();
        }

        return $result['passed'] ? 0 : 1;
    }

    /**
     * Display the command title banner.
     *
     * @return void
     */
    private function outputTitle(): void
    {
        $this->newLine();
        $this->line('╔══════════════════════════════════════════════════════════════╗');
        $this->line('║          ZeroBoiler Analytics — Deploy Gate                 ║');
        $this->line('║          Pre-deployment Validation Engine                   ║');
        $this->line('╚══════════════════════════════════════════════════════════════╝');
        $this->line('  Version: ' . AnalyticsEvent::VERSION);
        $this->newLine();
    }

    /**
     * Display formatted check results.
     *
     * @param  array{passed: bool, status: string, checks: array<string, array{status: string, message: string, details?: list<string>}>, errors: list<string>, warnings: list<string>, summary: string}  $result
     * @return void
     */
    private function displayResults(array $result): void
    {
        // Display individual checks
        foreach ($result['checks'] as $checkName => $check) {
            $icon = match ($check['status']) {
                'pass' => '✅',
                'fail' => '❌',
                'warn' => '⚠️ ',
                'skip' => '⏭️ ',
                default => '❓',
            };

            $statusColor = match ($check['status']) {
                'pass' => 'info',
                'fail' => 'error',
                'warn' => 'comment',
                'skip' => 'line',
                default => 'line',
            };

            $label = str_replace('_', ' ', $checkName);
            $label = ucwords($label);
            $this->{$statusColor}("  {$icon} {$label}: {$check['message']}");

            // Show details if available
            if (! empty($check['details'])) {
                foreach ($check['details'] as $detail) {
                    $this->line("       └─ {$detail}");
                }
            }
        }

        $this->newLine();

        // Display errors
        if (! empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->error("  🚫 {$error}");
            }
            $this->newLine();
        }

        // Display warnings
        if (! empty($result['warnings'])) {
            foreach ($result['warnings'] as $warning) {
                $this->warn("  ⚠️  {$warning}");
            }
            $this->newLine();
        }

        // Display summary
        $summaryColor = $result['passed'] ? 'info' : 'error';
        $this->{$summaryColor}("  {$result['summary']}");
        $this->newLine();
    }

    /**
     * Display system-wide health summary.
     *
     * @return void
     */
    private function displaySystemHealth(): void
    {
        $system = $this->healthEngine->systemHealth();

        $this->line('  ── System Event Health ─────────────────────────');
        $this->line('  Overall Score: ' . $system['score'] . '/100 (Grade: ' . $system['grade'] . ')');
        $this->line('  Total Events:  ' . $system['total_events']);
        $this->line('  Healthy:       ' . $system['healthy']);
        $this->line('  Degrading:     ' . $system['degrading']);
        $this->line('  Unknown:       ' . $system['unknown']);

        if (! empty($system['critical_events'])) {
            $this->newLine();
            $this->error('  Critical Events:');
            foreach ($system['critical_events'] as $critical) {
                $this->error("    🔴 {$critical}");
            }
        }

        $this->newLine();
    }
}
