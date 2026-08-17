<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventVolumeAnomalyDetectionService;
use ZeroBoiler\Analytics\Services\ProviderResilienceService;

/**
 * Analytics resilience and anomaly detection command.
 *
 * Provides a unified CLI for monitoring provider health (circuit breaker
 * states), detecting event volume anomalies, and managing resilience
 * lifecycle (reset, force-check).
 *
 * Subcommands:
 *   Default     — Show provider circuit breaker status table
 *   --anomaly   — Run anomaly detection scan
 *   --snapshot  — Show volume window snapshots
 *   --reset     — Reset circuit breaker for a provider
 *   --reset-all — Reset all circuit breakers
 *   --json      — Output as JSON
 *
 * @since 214.0.0
 */
final class AnalyticsResilienceCommand extends Command
{
    protected $signature = 'zb:analytics:resilience
        {--anomaly : Run anomaly detection scan}
        {--snapshot= : Show volume window snapshot for scope (global, category:<name>, event:<name>)}
        {--history : Show anomaly detection history}
        {--reset= : Reset circuit breaker for a specific provider}
        {--reset-all : Reset all circuit breakers}
        {--json : Output as JSON}';

    protected $description = 'Monitor provider resilience (circuit breaker) and event volume anomalies';

    private ?ProviderResilienceService $resilience = null;

    private ?EventVolumeAnomalyDetectionService $anomaly = null;

    private bool $outputJson = false;

    /**
     * Execute the resilience command.
     */
    #[Override]
    public function handle(): int
    {
        $this->outputJson = (bool) $this->option('json');

        // --reset-all
        if ((bool) $this->option('reset-all')) {
            return $this->resetAll();
        }

        // --reset=<provider>
        $resetProvider = $this->option('reset');
        if (is_string($resetProvider) && $resetProvider !== '') {
            return $this->resetProvider($resetProvider);
        }

        // --history
        if ((bool) $this->option('history')) {
            return $this->showHistory();
        }

        // --anomaly
        if ((bool) $this->option('anomaly')) {
            return $this->runAnomalyDetection();
        }

        // --snapshot=<scope>
        $snapshotScope = $this->option('snapshot');
        if (is_string($snapshotScope) && $snapshotScope !== '') {
            return $this->showSnapshot($snapshotScope);
        }

        // Default: show provider status
        return $this->showStatus();
    }

    /**
     * Show circuit breaker status for all providers.
     */
    private function showStatus(): int
    {
        $summary = $this->getResilienceService()->getStatusSummary();

        if ($this->outputJson) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('🛡️  ZeroBoiler Analytics — Provider Resilience');
        $this->line('   Version: ' . AnalyticsEvent::VERSION);
        $this->line('   Resilience: <info>' . ($summary['enabled'] ? 'ENABLED' : 'DISABLED') . '</info>');
        $this->line('   Providers: <info>' . $summary['healthy_count'] . '</info> healthy, <comment>' . $summary['degraded_count'] . '</comment> degraded, <fg=red>' . $summary['down_count'] . '</> down');
        $this->newLine();

        // Provider status table
        $rows = [];
        foreach ($summary['providers'] as $id => $info) {
            $statusIcon = match ($info['status']) {
                'closed' => '<fg=green>● CLOSED</>',
                'half_open' => '<comment>◐ HALF-OPEN</>',
                'open' => '<fg=red>◯ OPEN</>',
                default => '<fg=yellow>? UNKNOWN</>',
            };

            $availability = $info['available']
                ? '<fg=green>✓ Available</>'
                : '<fg=red>✗ Unavailable</>';

            $cooldown = '';
            if (isset($info['cooldown_remaining']) && $info['cooldown_remaining'] > 0) {
                $cooldown = $info['cooldown_remaining'] . 's remaining';
            }

            $rows[] = [
                $info['display_name'],
                $statusIcon,
                $availability,
                $info['failure_count'],
                $info['total_failures'] . '/' . $info['total_successes'],
                $cooldown,
            ];
        }

        $this->table(
            ['Provider', 'Circuit', 'Status', 'Recent Fails', 'Total (F/S)', 'Cooldown'],
            $rows,
        );

        $this->newLine();
        $this->comment('Use --anomaly to scan for volume anomalies.');
        $this->comment('Use --reset=<provider> to manually reset a circuit breaker.');

        return self::SUCCESS;
    }

    /**
     * Run anomaly detection scan.
     */
    private function runAnomalyDetection(): int
    {
        $anomalies = $this->getAnomalyService()->detectAnomalies();

        if ($this->outputJson) {
            $this->line(json_encode([
                'version' => AnalyticsEvent::VERSION,
                'anomalies_found' => count($anomalies),
                'anomalies' => array_map(fn ($a) => $a->toArray(), $anomalies),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('🔍 ZeroBoiler Analytics — Anomaly Detection Scan');
        $this->line('   Version: ' . AnalyticsEvent::VERSION);
        $this->newLine();

        if ($anomalies === []) {
            $this->info('   ✅ No anomalies detected. All event volumes are within normal ranges.');
            $this->newLine();

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($anomalies as $anomaly) {
            $severityIcon = match ($anomaly->severity) {
                'critical' => '<fg=red>🔴 CRITICAL</>',
                'high' => '<comment>🟠 HIGH</>',
                'medium' => '<fg=yellow>🟡 MEDIUM</>',
                default => '⚪ LOW',
            };

            $typeIcon = $anomaly->type === 'spike' ? '📈' : '📉';

            $rows[] = [
                $anomaly->scope,
                $severityIcon,
                $typeIcon . ' ' . ucfirst($anomaly->type),
                number_format($anomaly->zScore, 2),
                number_format($anomaly->current),
                '~' . number_format($anomaly->expected, 1),
                $anomaly->timestamp->format('H:i:s'),
            ];
        }

        $this->table(
            ['Scope', 'Severity', 'Type', 'Z-Score', 'Current', 'Expected', 'Detected At'],
            $rows,
        );

        $this->newLine();
        $this->warn('   ' . count($anomalies) . ' anomaly(ies) detected. Review the details above.');

        return self::SUCCESS;
    }

    /**
     * Show volume window snapshot for a scope.
     *
     * @param  string  $scope  Scope identifier
     */
    private function showSnapshot(string $scope): int
    {
        $snapshot = $this->getAnomalyService()->getWindowSnapshot($scope);

        if ($this->outputJson) {
            $this->line(json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('📊 Volume Window Snapshot — ' . $scope);
        $this->line('   Current bucket: <info>' . number_format($snapshot->current) . '</info> events');
        $this->line('   Mean: <info>' . number_format($snapshot->mean, 2) . '</info> events/bucket');
        $this->line('   Std Dev: <info>' . number_format($snapshot->stdDev, 2) . '</info>');
        $this->line('   Range: <info>' . $snapshot->min . '</info> — <info>' . $snapshot->max . '</info>');
        $this->line('   Trend: <info>' . strtoupper($snapshot->trend) . '</info>');
        $this->line('   Buckets in window: <info>' . $snapshot->bucketCount . '</info>');

        return self::SUCCESS;
    }

    /**
     * Show anomaly detection history.
     */
    private function showHistory(): int
    {
        $history = $this->getAnomalyService()->getHistory(50);

        if ($this->outputJson) {
            $this->line(json_encode([
                'version' => AnalyticsEvent::VERSION,
                'total_records' => count($history),
                'history' => $history,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('📋 Anomaly Detection History');
        $this->newLine();

        if ($history === []) {
            $this->line('   No anomaly records found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach (array_slice($history, 0, 25) as $record) {
            $rows[] = [
                $record['timestamp'],
                $record['scope'],
                ucfirst($record['type']),
                strtoupper($record['severity']),
                $record['z_score'],
                $record['current'],
                '~' . $record['expected'],
            ];
        }

        $this->table(
            ['Time', 'Scope', 'Type', 'Severity', 'Z-Score', 'Current', 'Expected'],
            $rows,
        );

        if (count($history) > 25) {
            $this->line('   Showing 25 of ' . count($history) . ' records. Use --json for full history.');
        }

        return self::SUCCESS;
    }

    /**
     * Reset circuit breaker for a specific provider.
     *
     * @param  string  $provider  Provider identifier
     */
    private function resetProvider(string $provider): int
    {
        $this->getResilienceService()->resetProvider($provider);

        if ($this->outputJson) {
            $this->line(json_encode([
                'action' => 'reset',
                'provider' => $provider,
                'status' => 'reset_successfully',
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info("✅ Circuit breaker reset for '{$provider}'.");
        }

        return self::SUCCESS;
    }

    /**
     * Reset all circuit breakers.
     */
    private function resetAll(): int
    {
        $this->getResilienceService()->resetAll();

        if ($this->outputJson) {
            $this->line(json_encode([
                'action' => 'reset_all',
                'status' => 'all_reset_successfully',
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info('✅ All circuit breakers have been reset.');
        }

        return self::SUCCESS;
    }

    /**
     * Lazily resolve the resilience service.
     */
    private function getResilienceService(): ProviderResilienceService
    {
        if ($this->resilience !== null) {
            return $this->resilience;
        }

        try {
            $this->resilience = app(ProviderResilienceService::class);
        } catch (\Throwable $e) {
            $this->error("Failed to resolve ProviderResilienceService: {$e->getMessage()}");

            exit(1);
        }

        return $this->resilience;
    }

    /**
     * Lazily resolve the anomaly detection service.
     */
    private function getAnomalyService(): EventVolumeAnomalyDetectionService
    {
        if ($this->anomaly !== null) {
            return $this->anomaly;
        }

        try {
            $this->anomaly = app(EventVolumeAnomalyDetectionService::class);
        } catch (\Throwable $e) {
            $this->error("Failed to resolve EventVolumeAnomalyDetectionService: {$e->getMessage()}");

            exit(1);
        }

        return $this->anomaly;
    }
}
