<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsReplayAuditor;

/**
 * Displays analytics event replay audit statistics and validates replay operations.
 *
 * Provides operators with visibility into:
 * - Total replay counts, success rates, and failure patterns
 * - Per-provider replay statistics
 * - Replay configuration validation
 * - Audit log management (clear)
 *
 * @since 118.0.0
 */
final class AnalyticsReplayAuditCommand extends Command
{
    protected $signature = 'zb:analytics:replay-audit
        {--json : Output as JSON}
        {--clear : Clear all audit data}
        {--validate : Validate replay configuration}';

    protected $description = 'Display analytics event replay audit statistics';

    private ?AnalyticsReplayAuditor $auditor = null;

    /**
     * Execute the replay audit command.
     */
    #[Override]
    public function handle(): int
    {
        $this->auditor = app(AnalyticsReplayAuditor::class);

        $clear = (bool) $this->option('clear');
        $validate = (bool) $this->option('validate');
        $outputJson = (bool) $this->option('json');

        if ($clear) {
            return $this->clearAudit();
        }

        if ($validate) {
            return $this->validateConfig($outputJson);
        }

        return $this->showSummary($outputJson);
    }

    /**
     * Display replay audit summary.
     *
     * @param  bool  $outputJson
     */
    private function showSummary(bool $outputJson): int
    {
        $summary = $this->auditor->summary();

        if ($outputJson) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('📊 Event Replay Audit Summary');
        $this->newLine();

        $this->line("  Total Replays:   <comment>{$summary['total_replays']}</comment>");
        $this->line("  Successful:      <info>{$summary['successful']}</info>");
        $this->line("  Failed:          <error>{$summary['failed']}</error>");
        $this->line("  Success Rate:    <comment>{$summary['success_rate']}%</comment>");
        $this->newLine();

        if ($summary['by_provider'] !== []) {
            $this->info('  By Provider:');
            $headers = ['Provider', 'Total', 'Success', 'Failed', 'Rate'];
            $rows = [];

            foreach ($summary['by_provider'] as $provider => $stats) {
                $rate = $stats['total'] > 0
                    ? round(($stats['success'] / $stats['total']) * 100, 1) . '%'
                    : '—';
                $rows[] = [
                    $provider,
                    $stats['total'],
                    $stats['success'],
                    $stats['failed'],
                    $rate,
                ];
            }

            $this->table($headers, $rows);
        } else {
            $this->line('  <fg=gray>No replay data available yet.</fg=gray>');
        }

        return self::SUCCESS;
    }

    /**
     * Clear all audit data.
     */
    private function clearAudit(): int
    {
        if (! $this->confirm('Are you sure you want to clear all replay audit data?')) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        $this->auditor->clear();
        $this->info('✅ Replay audit data cleared.');

        return self::SUCCESS;
    }

    /**
     * Validate replay configuration.
     *
     * @param  bool  $outputJson
     */
    private function validateConfig(bool $outputJson): int
    {
        $summary = $this->auditor->summary();

        $issues = [];

        if ($summary['success_rate'] < 50.0 && $summary['total_replays'] > 10) {
            $issues[] = [
                'severity' => 'warning',
                'message' => "Low replay success rate ({$summary['success_rate']}%) across {$summary['total_replays']} attempts.",
            ];
        }

        if ($summary['failed'] > 100) {
            $issues[] = [
                'severity' => 'critical',
                'message' => "High number of replay failures ({$summary['failed']}). Investigate provider configuration.",
            ];
        }

        $result = [
            'valid' => $issues === [],
            'issues' => $issues,
            'summary' => $summary,
        ];

        if ($outputJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $result['valid'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('🔍 Replay Configuration Validation');
        $this->newLine();

        if ($issues === []) {
            $this->info('  ✅ No replay issues detected.');
        } else {
            foreach ($issues as $issue) {
                $severity = $issue['severity'];
                $message = $issue['message'];

                if ($severity === 'critical') {
                    $this->error("  ❌ {$message}");
                } else {
                    $this->warn("  ⚠️  {$message}");
                }
            }
        }

        return $result['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
