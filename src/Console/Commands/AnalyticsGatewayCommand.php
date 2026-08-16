<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsEventGateway;
use ZeroBoiler\Analytics\Services\EventContractTestingService;

/**
 * Analytics gateway and contract testing command.
 *
 * Provides diagnostic and operational actions for the event gateway
 * and provider contract testing system:
 *
 * - `gateway:status` — Show gateway configuration and live metrics
 * - `gateway:reset` — Reset gateway metrics counters
 * - `contracts:coverage` — Show per-provider contract coverage analysis
 * - `contracts:validate` — Validate a specific event against all providers
 * - `contracts:list` — List all registered contracts
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsEventGateway
 * @see \ZeroBoiler\Analytics\Services\EventContractTestingService
 *
 * @since 208.0.0
 */
final class AnalyticsGatewayCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:gateway
                            {action : Action to perform (gateway:status|gateway:reset|contracts:coverage|contracts:validate|contracts:list)}
                            {--event= : Event name (for contracts:validate)}
                            {--json : Output as JSON}';

    /** @var string */
    protected $description = 'Analytics event gateway and contract testing diagnostics';

    /**
     * Execute the console command.
     */
    public function handle(AnalyticsEventGateway $gateway, EventContractTestingService $contracts): int
    {
        $action = $this->argument('action');
        $asJson = $this->option('json');

        return match ($action) {
            'gateway:status' => $this->gatewayStatus($gateway, $asJson),
            'gateway:reset' => $this->gatewayReset($gateway, $asJson),
            'contracts:coverage' => $this->contractsCoverage($contracts, $asJson),
            'contracts:validate' => $this->contractsValidate($contracts, $asJson),
            'contracts:list' => $this->contractsList($contracts, $asJson),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Show gateway status.
     *
     * @param  AnalyticsEventGateway  $gateway  Gateway service
     * @param  bool  $asJson  Output as JSON
     */
    private function gatewayStatus(AnalyticsEventGateway $gateway, bool $asJson): int
    {
        $data = [
            'enabled' => $gateway->isEnabled(),
            'config' => $gateway->configSummary(),
            'metrics' => $gateway->metrics(),
        ];

        if ($asJson) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info('Analytics Event Gateway Status');
        $this->newLine();

        $this->table(
            ['Setting', 'Value'],
            collect($gateway->configSummary())
                ->map(fn (mixed $value, string $key): array => [$key, is_bool($value) ? ($value ? '<fg=green>true</>' : '<fg=red>false</>') : (string) $value])
                ->values()
                ->toArray(),
        );

        $this->newLine();
        $this->components->info('Live Metrics');
        $this->newLine();

        $metrics = $gateway->metrics();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Inbound', (string) $metrics['total_inbound']],
                ['Total Dispatched', (string) $metrics['total_dispatched']],
                ['Total Rejected', (string) $metrics['total_rejected']],
                ['Total Deduplicated', (string) $metrics['total_deduplicated']],
                ['Total Rate Limited', (string) $metrics['total_rate_limited']],
                ['Total Capacity Rejected', (string) $metrics['total_capacity_rejected']],
                ['Dispatch Rate', ($metrics['dispatch_rate'] * 100).'%'],
                ['Rejection Rate', ($metrics['rejection_rate'] * 100).'%'],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Reset gateway metrics.
     *
     * @param  AnalyticsEventGateway  $gateway  Gateway service
     * @param  bool  $asJson  Output as JSON
     */
    private function gatewayReset(AnalyticsEventGateway $gateway, bool $asJson): int
    {
        $gateway->resetMetrics();

        if ($asJson) {
            $this->line(json_encode(['reset' => true, 'metrics' => $gateway->metrics()], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->info('Gateway metrics have been reset.');

        return self::SUCCESS;
    }

    /**
     * Show contract coverage analysis.
     *
     * @param  EventContractTestingService  $contracts  Contract testing service
     * @param  bool  $asJson  Output as JSON
     */
    private function contractsCoverage(EventContractTestingService $contracts, bool $asJson): int
    {
        $analysis = $contracts->coverageAnalysis();

        if ($asJson) {
            $this->line(json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info('Event Contract Coverage Analysis');
        $this->newLine();

        $rows = [];
        foreach ($analysis['providers'] as $provider => $stats) {
            $coveragePercent = ($stats['coverage'] * 100).'%';
            $color = $stats['coverage'] >= 0.8 ? 'green' : ($stats['coverage'] >= 0.5 ? 'yellow' : 'red');

            $rows[] = [
                $provider,
                (string) $stats['defined'],
                (string) $stats['total'],
                "<fg={$color}>{$coveragePercent}</>",
            ];
        }

        $this->table(['Provider', 'Contracts Defined', 'Total Events', 'Coverage'], $rows);

        $this->newLine();
        $overallPercent = ($analysis['overall'] * 100).'%';
        $this->info("Overall contract coverage: {$overallPercent}");
        $this->info("Total registered contracts: {$contracts->contractCount()}");

        return self::SUCCESS;
    }

    /**
     * Validate an event against all provider contracts.
     *
     * @param  EventContractTestingService  $contracts  Contract testing service
     * @param  bool  $asJson  Output as JSON
     */
    private function contractsValidate(EventContractTestingService $contracts, bool $asJson): int
    {
        $eventName = $this->option('event');

        if ($eventName === null || $eventName === '') {
            $this->components->error('Event name is required. Use --event=EVENT_NAME.');

            return self::FAILURE;
        }

        $event = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::fromArray(['name' => $eventName]);
        $results = $contracts->validateAllProviders($event);

        if ($asJson) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info("Contract Validation: {$eventName}");
        $this->newLine();

        $rows = [];
        foreach ($results as $provider => $result) {
            $status = $result['valid'] ? '<fg=green>✓ PASS</>' : '<fg=red>✗ FAIL</>';
            $errors = implode('; ', $result['errors']);
            $warnings = implode('; ', $result['warnings']);

            $rows[] = [
                $provider,
                $status,
                ($result['coverage'] * 100).'%',
                $errors ?: '—',
                $warnings ?: '—',
            ];
        }

        $this->table(['Provider', 'Status', 'Coverage', 'Errors', 'Warnings'], $rows);

        return self::SUCCESS;
    }

    /**
     * List all registered contracts.
     *
     * @param  EventContractTestingService  $contracts  Contract testing service
     * @param  bool  $asJson  Output as JSON
     */
    private function contractsList(EventContractTestingService $contracts, bool $asJson): int
    {
        $data = [
            'total_contracts' => $contracts->contractCount(),
            'enabled' => $contracts->isEnabled(),
            'providers' => $contracts->getSupportedProviders(),
        ];

        if ($asJson) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->info('Registered Event Contracts');
        $this->newLine();
        $this->info("Total contracts: {$data['total_contracts']}");
        $this->info("Service enabled: " . ($data['enabled'] ? 'yes' : 'no'));
        $this->info("Supported providers: " . implode(', ', $data['providers']));

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     *
     * @param  string  $action  Invalid action name
     */
    private function invalidAction(string $action): int
    {
        $this->components->error("Invalid action: {$action}");
        $this->newLine();
        $this->line('Available actions:');
        $this->line('  gateway:status      — Show gateway configuration and live metrics');
        $this->line('  gateway:reset       — Reset gateway metrics counters');
        $this->line('  contracts:coverage  — Show per-provider contract coverage');
        $this->line('  contracts:validate  — Validate event against all providers (--event=NAME)');
        $this->line('  contracts:list       — List registered contracts');

        return self::FAILURE;
    }
}
