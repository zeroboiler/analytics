<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventHealthScoringEngine;

/**
 * Analytics Event Health monitoring CLI command.
 *
 * Displays per-event and system-wide health scores with breakdowns
 * across freshness, volume, schema, delivery, and quality dimensions.
 *
 * Usage:
 *   php artisan zb:analytics:event-health
 *   php artisan zb:analytics:event-health --event=sign_up
 *   php artisan zb:analytics:event-health --degrading
 *   php artisan zb:analytics:event-health --system
 *   php artisan zb:analytics:event-health --json
 *   php artisan zb:analytics:event-health --clear
 *   php artisan zb:analytics:event-health --threshold=50
 *   php artisan zb:analytics:event-health --alerts
 *
 * @since 80.0.0
 */
final class AnalyticsEventHealthCommand extends Command
{
    /** @var string The console command name */
    protected $signature = 'zb:analytics:event-health
        {--event= : Show health for a specific event}
        {--degrading : Show only events with degrading health}
        {--system : Show system-wide health summary}
        {--json : Output as JSON}
        {--clear : Clear all health data}
        {--threshold=60 : Health score threshold for degrading detection}
        {--alerts : Show recent health alerts}';

    /** @var string The console command description */
    protected $description = 'Monitor analytics event health scores (freshness, volume, schema, delivery, quality)';

    private EventHealthScoringEngine $engine;

    /**
     * @param  EventHealthScoringEngine  $engine
     */
    public function __construct(EventHealthScoringEngine $engine)
    {
        parent::__construct();
        $this->engine = $engine;
    }

    /**
     * Execute the console command.
     *
     * @return int Exit code (0 = success)
     */
    public function handle(): int
    {
        $this->outputTitle();

        // Handle clear
        if ($this->option('clear')) {
            return $this->handleClear();
        }

        // Handle alerts
        if ($this->option('alerts')) {
            return $this->handleAlerts();
        }

        // Handle system
        if ($this->option('system')) {
            return $this->handleSystem();
        }

        // Handle specific event
        if ($this->option('event')) {
            return $this->handleSingleEvent((string) $this->option('event'));
        }

        // Handle degrading
        if ($this->option('degrading')) {
            return $this->handleDegrading((int) $this->option('threshold'));
        }

        // Default: show system health + all events
        return $this->handleDefault();
    }

    /**
     * Display the command title.
     *
     * @return void
     */
    private function outputTitle(): void
    {
        $this->newLine();
        $this->line('╔══════════════════════════════════════════════════════════════╗');
        $this->line('║          ZeroBoiler Analytics — Event Health Monitor          ║');
        $this->line('╚══════════════════════════════════════════════════════════════╝');
        $this->line('  Version: ' . AnalyticsEvent::VERSION);
        $this->newLine();
    }

    /**
     * Handle --clear action.
     *
     * @return int
     */
    private function handleClear(): int
    {
        $this->engine->clearAllStats();
        $this->info('✅ All cached event health data cleared.');
        return 0;
    }

    /**
     * Handle --alerts action.
     *
     * @return int
     */
    private function handleAlerts(): int
    {
        $alerts = $this->engine->getRecentAlerts();

        if (empty($alerts)) {
            $this->info('✅ No recent health alerts.');
            return 0;
        }

        $this->warn('  🚨 Recent Health Alerts (' . count($alerts) . '):');
        $this->newLine();

        foreach ($alerts as $alert) {
            $severityIcon = match ($alert['severity']) {
                'critical' => '🔴',
                'warning' => '🟡',
                default => '⚪',
            };

            $this->line("  {$severityIcon} [" . strtoupper($alert['severity']) . "] {$alert['event']}");
            $this->line("     {$alert['message']}");
            $this->line("     at: {$alert['timestamp']}");
            $this->newLine();
        }

        return 0;
    }

    /**
     * Handle --system action.
     *
     * @return int
     */
    private function handleSystem(): int
    {
        $system = $this->engine->systemHealth();

        if ($this->option('json')) {
            $this->line(json_encode($system, JSON_PRETTY_PRINT));
            return 0;
        }

        $gradeColor = match (true) {
            str_starts_with($system['grade'], 'A') => 'info',
            str_starts_with($system['grade'], 'B') => 'comment',
            default => 'error',
        };

        $this->{$gradeColor}("  Overall Health Score: {$system['score']}/100 (Grade: {$system['grade']})");
        $this->newLine();
        $this->line('  ┌────────────────────────────────────────┐');
        $this->line('  │  Total Events Tracked:  ' . str_pad((string) $system['total_events'], 10) . ' │');
        $this->line('  │  Healthy Events:        ' . str_pad((string) $system['healthy'], 10) . ' │');
        $this->line('  │  Degrading Events:      ' . str_pad((string) $system['degrading'], 10) . ' │');
        $this->line('  │  Unknown (No Data):      ' . str_pad((string) $system['unknown'], 10) . ' │');
        $this->line('  └────────────────────────────────────────┘');

        if (! empty($system['critical_events'])) {
            $this->newLine();
            $this->error('  🔴 Critical Events:');
            foreach ($system['critical_events'] as $eventName) {
                $this->error("     {$eventName}");
            }
        }

        $this->newLine();
        return 0;
    }

    /**
     * Handle single event health display.
     *
     * @param  string  $eventName
     * @return int
     */
    private function handleSingleEvent(string $eventName): int
    {
        $score = $this->engine->scoreEvent($eventName);

        if ($this->option('json')) {
            $this->line(json_encode($score, JSON_PRETTY_PRINT));
            return 0;
        }

        $this->line("  Event: {$eventName}");
        $this->line("  Score: {$score['score']}/100 — Grade: {$score['grade']}");
        $this->line("  Last Seen: " . ($score['last_seen'] !== null ? gmdate('Y-m-d H:i:s', $score['last_seen']) . ' UTC' : 'Never'));

        if (! empty($score['dimensions'])) {
            $this->newLine();
            $this->line('  Dimensions:');
            foreach ($score['dimensions'] as $dim => $data) {
                $label = str_replace('_', ' ', $dim);
                $label = ucwords($label);
                $bar = $this->buildBar($data['pct']);
                $statusIcon = match ($data['status']) {
                    'healthy' => '✅',
                    'warning' => '⚠️ ',
                    'degraded' => '🟡',
                    'critical' => '🔴',
                    'insufficient_data' => '⏭️ ',
                    default => '❓',
                };
                $this->line("    {$statusIcon} {$label}: {$data['score']}/{$data['max']} ({$data['pct']}%) {$bar}");
            }
        }

        if (! empty($score['recommendations'])) {
            $this->newLine();
            $this->comment('  Recommendations:');
            foreach ($score['recommendations'] as $rec) {
                $this->comment("    💡 {$rec}");
            }
        }

        $this->newLine();
        return 0;
    }

    /**
     * Handle --degrading action.
     *
     * @param  int  $threshold
     * @return int
     */
    private function handleDegrading(int $threshold): int
    {
        $degrading = $this->engine->getDegradingEvents($threshold);

        if ($this->option('json')) {
            $this->line(json_encode($degrading, JSON_PRETTY_PRINT));
            return 0;
        }

        if (empty($degrading)) {
            $this->info("✅ No events degrading below threshold ({$threshold}).");
            return 0;
        }

        $this->warn('  ⚠️  Degrading Events (' . count($degrading) . '):');
        $this->newLine();

        foreach ($degrading as $eventName => $health) {
            $this->line("  {$eventName}: {$health['score']}/100 ({$health['grade']})");
            foreach ($health['dimensions'] as $dim => $data) {
                if ($data['status'] === 'critical' || $data['status'] === 'degraded') {
                    $this->line("    └─ {$dim}: {$data['score']}/{$data['max']} [{$data['status']}]");
                }
            }
        }

        $this->newLine();
        return 0;
    }

    /**
     * Handle default display (system + all events table).
     *
     * @return int
     */
    private function handleDefault(): int
    {
        // System summary
        $system = $this->engine->systemHealth();

        $gradeColor = match (true) {
            str_starts_with($system['grade'], 'A') => 'info',
            str_starts_with($system['grade'], 'B') => 'comment',
            default => 'error',
        };

        $this->{$gradeColor}("  System Health: {$system['score']}/100 (Grade: {$system['grade']})");
        $this->line("  Events: {$system['total_events']} tracked, {$system['healthy']} healthy, {$system['degrading']} degrading");

        if ($this->option('json')) {
            $this->line(json_encode($system, JSON_PRETTY_PRINT));
            return 0;
        }

        // All events table
        $allScores = $this->engine->scoreAllEvents();

        if (empty($allScores)) {
            $this->newLine();
            $this->comment('  No event data recorded yet. Events will appear after tracking begins.');
            $this->newLine();
            return 0;
        }

        $this->newLine();
        $this->line('  ┌──────────────────────┬───────┬───────┬──────────────────────────┐');
        $this->line('  │ Event                │ Score │ Grade │ Last Seen                 │');
        $this->line('  ├──────────────────────┼───────┼───────┼──────────────────────────┤');

        // Sort by score ascending (worst first)
        uasort($allScores, function (array $a, array $b): int {
            return $a['score'] <=> $b['score'];
        });

        foreach ($allScores as $eventName => $data) {
            $name = str_pad(substr($eventName, 0, 22), 22);
            $score = str_pad((string) $data['score'], 5);
            $grade = str_pad($data['grade'], 5);
            $lastSeen = $data['last_seen'] !== null
                ? gmdate('Y-m-d H:i:s', $data['last_seen']) . ' UTC'
                : 'Never';
            $lastSeen = str_pad(substr($lastSeen, 0, 26), 26);

            $this->line("  │ {$name} │ {$score} │ {$grade} │ {$lastSeen} │");
        }

        $this->line('  └──────────────────────┴───────┴───────┴──────────────────────────┘');
        $this->newLine();

        return 0;
    }

    /**
     * Build a simple progress bar string.
     *
     * @param  float  $pct  Percentage (0-100)
     * @return string
     */
    private function buildBar(float $pct): string
    {
        $width = 20;
        $filled = (int) round(($pct / 100) * $width);
        $empty = $width - $filled;

        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);

        return "[{$bar}]";
    }
}
