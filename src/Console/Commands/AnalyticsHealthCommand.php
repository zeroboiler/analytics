<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Services\EventAggregationService;
use ZeroBoiler\Analytics\Queue\EventReplayQueue;

/**
 * Run a comprehensive health diagnostic of the analytics system.
 *
 * Checks all providers, queue, replay, consent, validation, sampling,
 * PII sanitization, metrics, and provides actionable warnings and
 * recommendations. Use for monitoring, CI checks, and debugging.
 *
 * @since 1.0.0
 */
final class AnalyticsHealthCommand extends Command
{
    protected $signature = 'zb:analytics:health
        {--json : Output as JSON}
        {--no-recommendations : Skip recommendations}';

    protected $description = 'Run a comprehensive analytics health diagnostic';

    private const SEPARATOR = '─────────────────────────────';

    #[Override]
    #[Override]
    public function handle(): int
    {
        /** @var AnalyticsManager $manager */
        $manager = app(AnalyticsManager::class);

        /** @var AnalyticsMetrics $metrics */
        $metrics = $manager->metrics();

        /** @var EventReplayQueue $replay */
        $replay = app(EventReplayQueue::class);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = app('config');

        $aggregation = new EventAggregationService($manager, $metrics, $replay, $config);
        $report = $aggregation->healthReport();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return ($report['status'] === 'error') ? self::FAILURE : self::SUCCESS;
        }

        $this->outputReport($report);

        return ($report['status'] === 'error') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Output the health report as a formatted table.
     *
     * @param  array<string, mixed>  $report
     */
    private function outputReport(array $report): void
    {
        $this->newLine();

        // Status banner
        $statusColor = match ($report['status']) {
            'healthy' => 'green',
            'warning' => 'yellow',
            'error' => 'red',
            default => 'white',
        };

        $statusIcon = match ($report['status']) {
            'healthy' => '✅',
            'warning' => '⚠️',
            'error' => '🚫',
            default => '❓',
        };

        $this->line("<fg={$statusColor};options=bold>{$statusIcon} ZeroBoiler Analytics Health — {$report['status']}</>");
        $this->line('  Version: ' . $report['version']);
        $this->newLine();

        // ── Providers ──
        $this->line('<fg=cyan;options=bold>PROVIDERS</>');
        $this->line(self::SEPARATOR);

        foreach ($report['providers'] as $name => $info) {
            $enabled = $info['enabled'];
            $configured = $info['configured'];

            $icon = ! $enabled ? '<fg=yellow>○</>'
                : ($configured ? '<fg=green>●</>' : '<fg=red>●</>');

            $label = $enabled ? ($configured ? 'enabled, configured' : 'enabled, NOT CONFIGURED') : 'disabled';
            $this->line("  {$icon} {$name}: {$label}");
        }

        // ── Queue ──
        $this->newLine();
        $this->line('<fg=cyan;options=bold>QUEUE</>');
        $this->line(self::SEPARATOR);
        $queue = $report['queue'];
        $this->line('  Async: ' . ($queue['enabled'] ? '✅' : '🚫'));
        $this->line('  Queue: ' . $queue['queue_name']);
        $this->line('  Connection: ' . $queue['connection']);

        // ── Replay ──
        $this->newLine();
        $this->line('<fg=cyan;options=bold>REPLAY QUEUE</>');
        $this->line(self::SEPARATOR);
        $replay = $report['replay'];
        $this->line('  Enabled: ' . ($replay['enabled'] ? '✅' : '🚫'));
        $this->line('  Max Attempts: ' . $replay['max_attempts']);
        $this->line('  Queued: ' . ($replay['queued'] ?? 0));
        $this->line('  Failed: ' . ($replay['failed'] ?? 0));
        $this->line('  Retried: ' . ($replay['retried'] ?? 0));

        // ── Consent ──
        $this->newLine();
        $this->line('<fg=cyan;options=bold>CONSENT</>');
        $this->line(self::SEPARATOR);
        $consent = $report['consent'];
        $consentIcon = $consent['default'] === 'granted' ? '<fg=yellow>⚠️</>' : '<fg=green>✅</>';
        $this->line("  {$consentIcon} Default: " . $consent['default']);

        // ── Validation ──
        $this->newLine();
        $this->line('<fg=cyan;options=bold>VALIDATION</>');
        $this->line(self::SEPARATOR);
        $validation = $report['validation'];
        $this->line('  Strict Mode: ' . ($validation['strict'] ? '✅' : '🚫'));
        $this->line('  Dedup Window: ' . $validation['dedup_window'] . 's');

        // ── Sampling ──
        $this->newLine();
        $this->line('<fg=cyan;options=bold>SAMPLING</>');
        $this->line(self::SEPARATOR);
        $sampling = $report['sampling'];
        $this->line('  Enabled: ' . ($sampling['enabled'] ? '✅' : '🚫'));
        if ($sampling['enabled']) {
            $ratePercent = round($sampling['rate'] * 100, 1);
            $this->line('  Rate: ' . $ratePercent . '%');
        }

        // ── PII ──
        $this->newLine();
        $this->line('<fg=cyan;options=bold>PII SANITIZATION</>');
        $this->line(self::SEPARATOR);
        $pii = $report['pii'];
        $this->line('  Enabled: ' . ($pii['enabled'] ? '✅' : '🚫'));
        $this->line('  Strategy: ' . $pii['strategy']);

        // ── Metrics ──
        $this->newLine();
        $this->line('<fg=cyan;options=bold>METRICS</>');
        $this->line(self::SEPARATOR);
        $metrics = $report['metrics'];
        $this->line('  Dispatched: ' . ($metrics['dispatched'] ?? 0));
        $this->line('  Failed: ' . ($metrics['failed'] ?? 0));

        $perProvider = $metrics['per_provider'] ?? [];
        if (! empty($perProvider)) {
            foreach ($perProvider as $provider => $stats) {
                $dispatched = $stats['dispatched'] ?? 0;
                $failed = $stats['failed'] ?? 0;
                $this->line("    {$provider}: {$dispatched} dispatched, {$failed} failed");
            }
        }

        // ── Catalog ──
        $this->newLine();
        $this->line('<fg=cyan;options=bold>EVENT CATALOG</>');
        $this->line(self::SEPARATOR);
        $catalog = $report['catalog'];
        $this->line('  E-commerce: ' . ($catalog['ecommerce'] ?? 0));
        $this->line('  SaaS: ' . ($catalog['saas'] ?? 0));
        $this->line('  Engagement: ' . ($catalog['engagement'] ?? 0));
        $this->line('  <fg=green;options=bold>Total: ' . ($catalog['total'] ?? 0) . '</>');

        // ── Warnings ──
        if (! empty($report['warnings'])) {
            $this->newLine();
            $this->line('<fg=yellow;options=bold>⚠️  WARNINGS</>');
            $this->line(self::SEPARATOR);

            foreach ($report['warnings'] as $warning) {
                $this->line("  <fg=yellow>• {$warning}</>");
            }
        }

        // ── Recommendations ──
        if (! empty($report['recommendations']) && ! $this->option('no-recommendations')) {
            $this->newLine();
            $this->line('<fg=blue;options=bold>💡 RECOMMENDATIONS</>');
            $this->line(self::SEPARATOR);

            foreach ($report['recommendations'] as $rec) {
                $this->line("  <fg=blue>• {$rec}</>");
            }
        }

        $this->newLine();
        $this->info('✨ Health check complete.');
    }
}
