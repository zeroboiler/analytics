<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventFraudDetectionService;

/**
 * Analytics fraud detection command.
 *
 * Provides fraud analysis, metrics reporting, and testing utilities
 * for the EventFraudDetectionService.
 *
 * @since 47.0.0
 */
final class AnalyticsFraudCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:fraud
        {--mode=status : Output mode (status, metrics, evaluate, test-burst, reset)}
        {--event= : Event name for evaluate mode}
        {--client= : Client ID for evaluate mode}
        {--json : Output as JSON}
    ';

    /** @var string */
    protected $description = 'Analyze analytics event fraud detection metrics';

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(EventFraudDetectionService $fraud): int
    {
        $mode = (string) $this->option('mode');
        $asJson = (bool) $this->option('json');

        return match ($mode) {
            'metrics' => $this->showMetrics($fraud, $asJson),
            'evaluate' => $this->evaluateEvent($fraud, $asJson),
            'test-burst' => $this->testBurstScenario($fraud, $asJson),
            'reset' => $this->resetMetrics($fraud, $asJson),
            default => $this->showStatus($fraud, $asJson),
        };
    }

    /**
     * Show fraud detection status.
     *
     * @return int Exit code
     */
    private function showStatus(EventFraudDetectionService $fraud, bool $asJson): int
    {
        $metrics = $fraud->getMetrics();
        $config = [
            'quarantine_threshold' => $fraud->getQuarantineThreshold(),
            'block_threshold' => $fraud->getBlockThreshold(),
        ];

        $data = array_merge($metrics, $config);

        if ($asJson) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('Analytics Fraud Detection Status');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Evaluated', (string) $data['total_evaluated']],
                ['Passed', (string) $data['passed']],
                ['Quarantined', (string) $data['quarantined']],
                ['Blocked', (string) $data['blocked']],
                ['Average Score', (string) $data['average_score']],
                ['Quarantine Threshold', (string) $data['quarantine_threshold']],
                ['Block Threshold', (string) $data['block_threshold']],
            ]
        );

        if (! empty($data['top_flagged_events'])) {
            $this->warn('Top Flagged Events: ' . implode(', ', $data['top_flagged_events']));
        }

        return self::SUCCESS;
    }

    /**
     * Show fraud metrics.
     *
     * @return int Exit code
     */
    private function showMetrics(EventFraudDetectionService $fraud, bool $asJson): int
    {
        $metrics = $fraud->getMetrics();

        if ($asJson) {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('Fraud Detection Metrics');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Evaluated', (string) $metrics['total_evaluated']],
                ['Passed', (string) $metrics['passed']],
                ['Quarantined', (string) $metrics['quarantined']],
                ['Blocked', (string) $metrics['blocked']],
                ['Top Flagged', implode(', ', $metrics['top_flagged_events'])],
                ['Avg Score', (string) $metrics['average_score']],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Evaluate a single event for fraud.
     *
     * @return int Exit code
     */
    private function evaluateEvent(EventFraudDetectionService $fraud, bool $asJson): int
    {
        $eventName = (string) $this->option('event');
        $clientId = $this->option('client');

        if ($eventName === '') {
            $this->error('--event is required for evaluate mode');

            return self::FAILURE;
        }

        $event = new AnalyticsEvent(name: $eventName, params: ['test' => true]);
        $result = $fraud->evaluate($event, $clientId !== null ? (string) $clientId : null);

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info("Fraud Evaluation: {$eventName}");
        $this->table(
            ['Field', 'Value'],
            [
                ['Score', (string) $result['score']],
                ['Action', $result['action']],
                ['Reason', $result['reason'] ?? 'N/A'],
            ]
        );

        foreach ($result['signals'] as $signal) {
            $status = $signal['triggered'] ? '<fg=red>TRIGGERED</>' : '<fg=green>OK</>';
            $this->line("  {$signal['name']}: {$status} (score: {$signal['score']})");
        }

        return self::SUCCESS;
    }

    /**
     * Run a simulated burst test scenario.
     *
     * @return int Exit code
     */
    private function testBurstScenario(EventFraudDetectionService $fraud, bool $asJson): int
    {
        $this->components->info('Running burst test scenario (100 rapid events)...');

        $results = [];
        $blocked = 0;
        $quarantined = 0;
        $passed = 0;

        $clientId = 'test_burst_client_' . time();

        for ($i = 0; $i < 100; $i++) {
            $event = new AnalyticsEvent(name: 'test_event', params: ['iteration' => $i]);
            $result = $fraud->evaluate($event, $clientId);

            $results[] = $result;

            match ($result['action']) {
                'block' => $blocked++,
                'quarantine' => $quarantined++,
                default => $passed++,
            };
        }

        $data = [
            'total_events' => 100,
            'passed' => $passed,
            'quarantined' => $quarantined,
            'blocked' => $blocked,
            'pass_rate' => round($passed / 100, 2),
            'block_rate' => round($blocked / 100, 2),
        ];

        if ($asJson) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Events', (string) $data['total_events']],
                ['Passed', (string) $data['passed']],
                ['Quarantined', (string) $data['quarantined']],
                ['Blocked', (string) $data['blocked']],
                ['Pass Rate', (string) ($data['pass_rate'] * 100) . '%'],
                ['Block Rate', (string) ($data['block_rate'] * 100) . '%'],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Reset fraud metrics.
     *
     * @return int Exit code
     */
    private function resetMetrics(EventFraudDetectionService $fraud, bool $asJson): int
    {
        $fraud->resetMetrics();

        if ($asJson) {
            $this->line(json_encode(['status' => 'reset', 'timestamp' => now()->toIso8601String()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('Fraud detection metrics have been reset.');

        return self::SUCCESS;
    }
}
