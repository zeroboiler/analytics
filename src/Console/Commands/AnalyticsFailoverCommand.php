<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\ProviderFailoverService;

/**
 * Analytics failover management command.
 *
 * Displays current failover configuration, provider fallback chains,
 * audit trail, health scores, and allows manual failover management.
 *
 * @since 145.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\ProviderFailoverService
 */
final class AnalyticsFailoverCommand extends Command
{
    protected $signature = 'zb:analytics:failover
        {--config : Show failover configuration}
        {--audit : Show today\'s failover audit trail}
        {--summary : Show failover summary statistics}
        {--health : Show provider health scores}
        {--status : Show current failover status (default)}
        {--json : Output as JSON}';

    protected $description = 'Manage analytics provider auto-failover configuration and audit trail';

    /**
     * Execute the failover command.
     */
    #[\Override]
    public function handle(): int
    {
        if (! $this->option('json')) {
            $this->info('⚡ ZeroBoiler Analytics — Provider Auto-Failover');
            $this->newLine();
        }

        $showConfig = $this->option('config');
        $showAudit = $this->option('audit');
        $showSummary = $this->option('summary');
        $showHealth = $this->option('health');
        $showStatus = $this->option('status') || (! $showConfig && ! $showAudit && ! $showSummary && ! $showHealth);

        /** @var ProviderFailoverService $failoverService */
        $failoverService = app(ProviderFailoverService::class);

        $output = [];

        if ($showStatus) {
            $output['status'] = $this->buildStatusSection($failoverService);
        }

        if ($showConfig) {
            $output['configuration'] = $failoverService->getConfiguration();
            $output['providers'] = $failoverService->allProviders();
        }

        if ($showAudit) {
            $output['audit_trail'] = $failoverService->getAuditTrail();
        }

        if ($showSummary) {
            $output['summary'] = $failoverService->getFailoverSummary();
        }

        if ($showHealth) {
            $output['health'] = $this->buildHealthSection($failoverService);
        }

        if ($this->option('json')) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        // Pretty-print table output
        if ($showStatus) {
            $this->printStatusTable($failoverService);
        }

        if ($showConfig) {
            $this->newLine();
            $this->section('Configuration');
            $config = $failoverService->getConfiguration();
            $this->table(
                ['Setting', 'Value'],
                [
                    ['Enabled', $config['enabled'] ? '✅ Yes' : '❌ No'],
                    ['Strategy', $config['strategy']],
                    ['Max Cascade Depth', (string) $config['max_cascade_depth']],
                    ['Recovery Ramp-Up', $config['recovery_ramp_up_percent'] . '% per minute'],
                    ['Providers Configured', (string) $config['provider_count']],
                ],
            );

            $this->newLine();
            $this->section('Fallback Chains');
            $rows = [];
            foreach ($config['providers'] as $provider => $fallbacks) {
                $rows[] = [$provider, implode(' → ', $fallbacks)];
            }
            $this->table(['Provider', 'Fallback Chain'], $rows);
        }

        if ($showAudit) {
            $this->newLine();
            $this->section('Audit Trail (Today)');
            $trail = $failoverService->getAuditTrail();
            if ($trail === []) {
                $this->comment('No failover events recorded today.');
            } else {
                $rows = [];
                foreach ($trail as $entry) {
                    $rows[] = [
                        $entry['timestamp'],
                        $entry['from'],
                        '→',
                        $entry['to'],
                        $entry['reason'],
                    ];
                }
                $this->table(['Time', 'From', '', 'To', 'Reason'], $rows);
            }
        }

        if ($showSummary) {
            $this->newLine();
            $this->section('Failover Summary');
            $summary = $failoverService->getFailoverSummary();
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Failovers Today', (string) $summary['total_failovers']],
                    ['Unique Providers Affected', (string) count($summary['by_provider'])],
                    ['Unique Reasons', (string) count($summary['by_reason'])],
                ],
            );

            if ($summary['by_provider'] !== []) {
                $this->newLine();
                $this->section('By Provider');
                $rows = [];
                foreach ($summary['by_provider'] as $provider => $count) {
                    $rows[] = [$provider, (string) $count];
                }
                $this->table(['Provider', 'Failover Count'], $rows);
            }
        }

        if ($showHealth) {
            $this->newLine();
            $this->section('Provider Health Scores');
            $this->comment('Health scores require real-time circuit state data.');
            $this->comment('Run with --json for programmatic access.');
        }

        return self::SUCCESS;
    }

    /**
     * Build the status section data.
     *
     * @return array{enabled: bool, strategy: string, providers: int}
     */
    private function buildStatusSection(ProviderFailoverService $service): array
    {
        return [
            'enabled' => $service->isEnabled(),
            'strategy' => $service->getStrategy(),
            'providers' => count($service->allProviders()),
        ];
    }

    /**
     * Build the health section data.
     *
     * @return array{providers: list<string>, note: string}
     */
    private function buildHealthSection(ProviderFailoverService $service): array
    {
        return [
            'providers' => $service->allProviders(),
            'note' => 'Health scores require real-time circuit state data from ProviderCircuitBreaker',
        ];
    }

    /**
     * Print the status table in human-readable format.
     */
    private function printStatusTable(ProviderFailoverService $service): void
    {
        $this->table(
            ['Property', 'Value'],
            [
                ['Failover Enabled', $service->isEnabled() ? '✅ Yes' : '❌ No'],
                ['Strategy', $service->getStrategy()],
                ['Known Providers', (string) count($service->allProviders())],
            ],
        );
    }
}
