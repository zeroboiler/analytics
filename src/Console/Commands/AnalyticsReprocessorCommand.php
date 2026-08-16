<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventReprocessorService;

/**
 * Analytics reprocessor CLI command.
 *
 * Provides 5 actions for managing event reprocessing:
 * - reprocess: Reprocess archived events with optional schema migration
 * - audit: Validate archived events against current schemas
 * - status: Show reprocessor configuration and last result
 * - metrics: Show reprocessing run history
 * - clear: Clear audit history and cached metrics
 *
 * Actions:
 *   php artisan analytics:reprocessor reprocess [--event=] [--category=] [--client=] [--user=] [--dry-run] [--no-migrate] [--no-validate] [--json]
 *   php artisan analytics:reprocessor audit [--event=] [--category=] [--json]
 *   php artisan analytics:reprocessor status [--json]
 *   php artisan analytics:reprocessor metrics [--json]
 *   php artisan analytics:reprocessor clear
 *
 * @see \ZeroBoiler\Analytics\Services\EventReprocessorService
 *
 * @since 209.0.0
 */
final class AnalyticsReprocessorCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'analytics:reprocessor
        {action : Action to perform (reprocess|audit|status|metrics|clear)}
        {--event= : Filter by event name (comma-separated)}
        {--category= : Filter by category (comma-separated)}
        {--client= : Filter by client ID}
        {--user= : Filter by user ID}
        {--dry-run : Simulate without dispatching}
        {--no-migrate : Skip schema migrations}
        {--no-validate : Skip validation}
        {--json : Output as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Reprocess archived analytics events with schema migration and validation';

    /**
     * Action constants.
     */
    private const ACTION_REPROCESS = 'reprocess';
    private const ACTION_AUDIT = 'audit';
    private const ACTION_STATUS = 'status';
    private const ACTION_METRICS = 'metrics';
    private const ACTION_CLEAR = 'clear';

    private const VALID_ACTIONS = [
        self::ACTION_REPROCESS,
        self::ACTION_AUDIT,
        self::ACTION_STATUS,
        self::ACTION_METRICS,
        self::ACTION_CLEAR,
    ];

    /**
     * Execute the console command.
     */
    public function handle(EventReprocessorService $reprocessor): int
    {
        $action = $this->argument('action');

        if (! is_string($action) || ! in_array($action, self::VALID_ACTIONS, true)) {
            $this->error("Invalid action: {$action}");
            $this->line('Valid actions: ' . implode(', ', self::VALID_ACTIONS));

            return self::FAILURE;
        }

        return match ($action) {
            self::ACTION_REPROCESS => $this->actionReprocess($reprocessor),
            self::ACTION_AUDIT => $this->actionAudit($reprocessor),
            self::ACTION_STATUS => $this->actionStatus($reprocessor),
            self::ACTION_METRICS => $this->actionMetrics($reprocessor),
            self::ACTION_CLEAR => $this->actionClear($reprocessor),
        };
    }

    /**
     * Execute reprocess action.
     */
    private function actionReprocess(EventReprocessorService $reprocessor): int
    {
        $filters = $this->buildFilters(dryRun: true);

        $this->info('Starting event reprocessing...');
        $this->newLine();

        $result = $reprocessor->reprocess($filters);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Processed', $result['processed']],
                ['Dispatched', $result['dispatched']],
                ['Failed', $result['failed']],
                ['Skipped', $result['skipped']],
                ['Validation Errors', $result['validation_errors']],
                ['Migration Errors', $result['migration_errors']],
                ['Dispatch Rate', $result['metrics']['dispatch_rate'] . '%'],
                ['Validation Rate', $result['metrics']['validation_rate'] . '%'],
            ],
        );

        // Show error details if any
        $errors = array_filter(
            $result['results'],
            fn (array $r): bool => $r['status'] === 'failed' || $r['status'] === 'validation_error' || $r['status'] === 'migration_error',
        );

        if (! empty($errors)) {
            $this->newLine();
            $this->warn('Errors and issues:');
            $this->table(
                ['Event', 'Status', 'Reason'],
                array_map(fn (array $r): array => [$r['event'], $r['status'], $r['reason'] ?? '—'], array_values($errors)),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Execute audit action.
     */
    private function actionAudit(EventReprocessorService $reprocessor): int
    {
        $filters = $this->buildFilters(dryRun: false);

        $this->info('Auditing archived events against current schemas...');
        $this->newLine();

        $result = $reprocessor->audit($filters);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Events', $result['total']],
                ['Valid', $result['valid']],
                ['Invalid', $result['invalid']],
                ['Missing Schema', $result['missing_schema']],
            ],
        );

        if (! empty($result['details'])) {
            $invalidDetails = array_filter($result['details'], fn (array $d): bool => ! $d['valid']);

            if (! empty($invalidDetails)) {
                $this->newLine();
                $this->warn('Invalid events:');
                $this->table(
                    ['Event', 'Issues'],
                    array_map(fn (array $d): array => [$d['event'], implode('; ', $d['issues'])], array_values($invalidDetails)),
                );
            }
        }

        return self::SUCCESS;
    }

    /**
     * Execute status action.
     */
    private function actionStatus(EventReprocessorService $reprocessor): int
    {
        $config = $reprocessor->configSummary();
        $lastResult = $reprocessor->lastResult();

        if ($this->option('json')) {
            $this->line(json_encode([
                'config' => $config,
                'last_result' => $lastResult,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Event Reprocessor Status');
        $this->newLine();

        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', $config['enabled'] ? 'Yes' : 'No'],
                ['Dry Run', $config['dry_run'] ? 'Yes' : 'No'],
                ['Batch Size', (string) $config['batch_size']],
                ['Max Events', (string) $config['max_events']],
                ['Apply Migrations', $config['apply_migrations'] ? 'Yes' : 'No'],
                ['Validate Before Dispatch', $config['validate_before_dispatch'] ? 'Yes' : 'No'],
                ['Audit Results', $config['audit_results'] ? 'Yes' : 'No'],
                ['Audit TTL', $config['audit_ttl'] . 's'],
            ],
        );

        if ($lastResult !== null) {
            $this->newLine();
            $this->info('Last Reprocess Result:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Processed', (string) $lastResult['processed']],
                    ['Dispatched', (string) $lastResult['dispatched']],
                    ['Failed', (string) $lastResult['failed']],
                    ['Dispatch Rate', ($lastResult['metrics']['dispatch_rate'] * 100) . '%'],
                ],
            );
        }

        return self::SUCCESS;
    }

    /**
     * Execute metrics action.
     */
    private function actionMetrics(EventReprocessorService $reprocessor): int
    {
        $metrics = $reprocessor->metrics();

        if ($this->option('json')) {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Event Reprocessor Metrics');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Runs', (string) $metrics['total_runs']],
                ['Last Run', $metrics['last_run']['timestamp'] ?? 'Never'],
            ],
        );

        if ($metrics['recent_summary'] !== null) {
            $summary = $metrics['recent_summary'];
            $this->newLine();
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Processed', (string) $summary['total_processed']],
                    ['Total Dispatched', (string) $summary['total_dispatched']],
                    ['Total Failed', (string) $summary['total_failed']],
                    ['Avg Dispatch Rate', ($summary['avg_dispatch_rate'] * 100) . '%'],
                ],
            );
        }

        return self::SUCCESS;
    }

    /**
     * Execute clear action.
     */
    private function actionClear(EventReprocessorService $reprocessor): int
    {
        $reprocessor->clearMetrics();
        $this->info('Reprocessor audit history and metrics cleared.');

        return self::SUCCESS;
    }

    /**
     * Build filter array from command options.
     *
     * @return array{event_names?: list<string>, categories?: list<string>, client_id?: string|null, user_id?: string|null, dry_run?: bool, apply_migrations?: bool, validate?: bool}
     */
    private function buildFilters(bool $dryRun): array
    {
        $filters = [];

        $eventOption = $this->option('event');
        if (is_string($eventOption) && $eventOption !== '') {
            $filters['event_names'] = array_map('trim', explode(',', $eventOption));
        }

        $categoryOption = $this->option('category');
        if (is_string($categoryOption) && $categoryOption !== '') {
            $filters['categories'] = array_map('trim', explode(',', $categoryOption));
        }

        $clientOption = $this->option('client');
        if (is_string($clientOption) && $clientOption !== '') {
            $filters['client_id'] = $clientOption;
        }

        $userOption = $this->option('user');
        if (is_string($userOption) && $userOption !== '') {
            $filters['user_id'] = $userOption;
        }

        if ($dryRun && $this->option('dry-run')) {
            $filters['dry_run'] = true;
        }

        if ($this->option('no-migrate')) {
            $filters['apply_migrations'] = false;
        }

        if ($this->option('no-validate')) {
            $filters['validate'] = false;
        }

        return $filters;
    }
}
