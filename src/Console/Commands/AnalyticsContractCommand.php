<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventContractTestService;

/**
 * Analytics contract testing command — validates events against
 * provider-specific contracts.
 *
 * Actions:
 *   validate     Validate a specific event against all providers
 *   catalog      Validate the entire event catalog
 *   coverage     Show per-provider contract coverage
 *   list         List all registered contracts
 *   test         Run a test event through contract validation
 *
 * @since 76.0.0
 */
final class AnalyticsContractCommand extends Command
{
    protected $signature = 'zb:analytics:contract
        {action : validate|catalog|coverage|list|test}
        {--event= : Event name to validate (for validate action)}
        {--provider= : Specific provider to check (default: all)}
        {--json : Output as JSON}
        {--severity= : Override severity level (reject|warn|off)}';

    protected $description = 'Validate events against provider-specific contracts';

    private EventContractTestService $service;

    public function __construct(EventContractTestService $service): void
    {
        parent::__construct();
        $this->service = $service;
    }

    #[\Override]
    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $outputJson = (bool) $this->option('json');

        return match ($action) {
            'validate' => $this->validateEvent($outputJson),
            'catalog' => $this->validateCatalog($outputJson),
            'coverage' => $this->showCoverage($outputJson),
            'list' => $this->listContracts($outputJson),
            'test' => $this->runTest($outputJson),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Validate a specific event against all provider contracts.
     *
     * @param  bool  $outputJson  Whether to output as JSON
     * @return int  Exit code
     */
    private function validateEvent(bool $outputJson): int
    {
        $eventName = (string) $this->option('event');

        if ($eventName === '') {
            $eventName = $this->ask('Enter event name to validate');

            if ($eventName === null || $eventName === '') {
                $this->error('Event name is required.');

                return self::FAILURE;
            }
        }

        $event = new AnalyticsEvent(name: $eventName, params: []);
        $result = $this->service->validateEvent($event);

        if ($outputJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("═══ Contract Validation: {$eventName} ═══");

        foreach ($result['providers'] as $provider => $check) {
            $status = $check['passed'] ? '✓ PASS' : '✗ FAIL';
            $color = $check['passed'] ? 'info' : 'error';
            $this->$color("  {$provider}: {$status}");

            foreach ($check['violations'] as $violation) {
                $this->warn("    → [{$violation['rule']}] {$violation['message']}");
            }
        }

        $overallStatus = $result['overall_passed'] ? '✓ PASSED' : '✗ FAILED';
        $overallColor = $result['overall_passed'] ? 'info' : 'error';
        $this->newLine();
        $this->$overallColor("Overall: {$overallStatus} (severity: {$result['severity']})");

        return $result['overall_passed'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Validate the entire event catalog.
     *
     * @param  bool  $outputJson  Whether to output as JSON
     * @return int  Exit code
     */
    private function validateCatalog(bool $outputJson): int
    {
        $this->info('Validating entire event catalog against all provider contracts...');

        $result = $this->service->validateCatalog();

        if ($outputJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("═══ Catalog Contract Validation ═══");
        $this->line("  Total Events:     {$result['total_events']}");
        $this->line("  Total Contracts:   {$result['total_contracts']}");
        $this->line("  Overall Coverage:  {$result['coverage']}% (Grade: {$result['grade']})");
        $this->newLine();

        $this->info("─── Per-Provider Results ───");
        foreach ($result['results'] as $provider => $stats) {
            $coverage = $result['provider_coverage'][$provider] ?? 0.0;
            $total = $stats['passed'] + $stats['failed'];
            $pct = $total > 0 ? round(($stats['passed'] / $total) * 100, 1) : 100.0;
            $this->line("  {$provider}: {$stats['passed']}/{$total} passed ({$pct}%) — {$stats['violations']} violations");
        }

        return self::SUCCESS;
    }

    /**
     * Show per-provider contract coverage.
     *
     * @param  bool  $outputJson  Whether to output as JSON
     * @return int  Exit code
     */
    private function showCoverage(bool $outputJson): int
    {
        $provider = (string) $this->option('provider');

        if ($provider !== '') {
            $result = $this->service->providerCoverage($provider);

            if ($outputJson) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                $this->info("═══ Coverage: {$provider} ═══");
                $this->line("  Total Events: {$result['total_events']}");
                $this->line("  Passed:       {$result['passed']}");
                $this->line("  Failed:       {$result['failed']}");
                $this->line("  Coverage:     {$result['coverage']}%");

                if (! empty($result['top_violations'])) {
                    $this->newLine();
                    $this->warn("─── Top Violations ───");
                    foreach ($result['top_violations'] as $violation) {
                        $this->line("  {$violation['event']}:");
                        foreach ($violation['violations'] as $msg) {
                            $this->warn("    → {$msg}");
                        }
                    }
                }
            }

            return self::SUCCESS;
        }

        // Show all providers
        $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $rows = [];

        foreach ($providers as $p) {
            $cov = $this->service->providerCoverage($p);
            $rows[] = [$p, $cov['total_events'], $cov['passed'], $cov['failed'], $cov['coverage'] . '%'];
        }

        if ($outputJson) {
            $allResults = [];
            foreach ($providers as $p) {
                $allResults[$p] = $this->service->providerCoverage($p);
            }
            $this->line(json_encode($allResults, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info('═══ Provider Coverage Summary ═══');
            $this->table(['Provider', 'Total', 'Passed', 'Failed', 'Coverage'], $rows);
        }

        return self::SUCCESS;
    }

    /**
     * List all registered contracts.
     *
     * @param  bool  $outputJson  Whether to output as JSON
     * @return int  Exit code
     */
    private function listContracts(bool $outputJson): int
    {
        $contracts = $this->service->getContracts();

        if ($outputJson) {
            $this->line(json_encode($contracts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info("═══ Registered Contracts ({$this->service->contractCount()}) ═══");

            foreach ($contracts as $provider => $providerContracts) {
                $this->newLine();
                $this->comment("[{$provider}]");
                foreach ($providerContracts as $event => $contract) {
                    $required = isset($contract['required']) ? 'required: ' . implode(', ', $contract['required']) : 'no required params';
                    $this->line("  {$event}: {$required}");
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Run a test event through contract validation.
     *
     * @param  bool  $outputJson  Whether to output as JSON
     * @return int  Exit code
     */
    private function runTest(bool $outputJson): int
    {
        $testEvents = [
            ['name' => 'purchase', 'params' => ['transaction_id' => 'txn_001', 'value' => 99.99, 'currency' => 'USD', 'items' => [['item_id' => 'p1', 'price' => 99.99, 'quantity' => 1]]]],
            ['name' => 'purchase', 'params' => ['value' => 99.99]],  // Missing transaction_id
            ['name' => 'page_view', 'params' => ['page_title' => 'Test Page']],
            ['name' => 'sign_up', 'params' => ['$distinct_id' => 'user_001']],
        ];

        $allResults = [];
        $allPassed = true;

        foreach ($testEvents as $testCase) {
            $event = new AnalyticsEvent(name: $testCase['name'], params: $testCase['params']);
            $result = $this->service->validateEvent($event);
            $allResults[] = $result;

            if (! $result['overall_passed']) {
                $allPassed = false;
            }
        }

        if ($outputJson) {
            $this->line(json_encode($allResults, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info('═══ Contract Test Suite ═══');

            foreach ($allResults as $i => $result) {
                $num = $i + 1;
                $status = $result['overall_passed'] ? '✓ PASS' : '✗ FAIL';
                $color = $result['overall_passed'] ? 'info' : 'error';
                $this->$color("  Test {$num}: {$result['event']} → {$status}");

                foreach ($result['providers'] as $provider => $check) {
                    if (! $check['passed']) {
                        foreach ($check['violations'] as $violation) {
                            $this->warn("    [{$provider}] {$violation['message']}");
                        }
                    }
                }
            }

            $this->newLine();
            $total = count($allResults);
            $passed = count(array_filter($allResults, fn (array $r): bool => $r['overall_passed']));
            $this->line("Results: {$passed}/{$total} passed");
        }

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Handle invalid action.
     *
     * @param  string  $action  Invalid action name
     * @return int  Exit code
     */
    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->line('Available actions: validate, catalog, coverage, list, test');

        return self::FAILURE;
    }
}
