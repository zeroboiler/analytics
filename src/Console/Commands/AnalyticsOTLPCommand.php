<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\OTLPExportService;

/**
 * Admin CLI for OpenTelemetry (OTLP) export diagnostics and management.
 *
 * Provides commands for:
 * - Viewing OTLP export statistics (success/failure counts, latency)
 * - Validating OTLP configuration
 * - Sending a test event to the collector
 * - Resetting export statistics
 * - Enabling/disabling OTLP export at runtime
 *
 * @since 38.0.0
 */
final class AnalyticsOTLPCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'zb:analytics:otel
        {--stats : Show OTLP export statistics}
        {--validate : Validate OTLP configuration}
        {--test : Send a test event to the collector}
        {--reset : Reset export statistics}
        {--enable : Enable OTLP export}
        {--disable : Disable OTLP export}
        {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'OpenTelemetry (OTLP) export diagnostics and management';

    /**
     * Execute the console command.
     */
    public function handle(OTLPExportService $otelService): int
    {
        $asJson = $this->option('json');
        $executed = false;

        // Validate
        if ($this->option('validate')) {
            $executed = true;
            $validation = $otelService->validate();

            if ($asJson) {
                $this->line(json_encode($validation, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return $validation['valid'] ? self::SUCCESS : self::FAILURE;
            }

            if ($validation['valid']) {
                $this->info('✅ OTLP configuration is valid');
            } else {
                $this->error('❌ OTLP configuration has errors:');
                foreach ($validation['errors'] as $error) {
                    $this->line("  - {$error}");
                }
            }

            foreach ($validation['warnings'] as $warning) {
                $this->warn("⚠️  {$warning}");
            }

            return $validation['valid'] ? self::SUCCESS : self::FAILURE;
        }

        // Enable
        if ($this->option('enable')) {
            $executed = true;
            $otelService->enable();
            $this->info('✅ OTLP export enabled');

            return self::SUCCESS;
        }

        // Disable
        if ($this->option('disable')) {
            $executed = true;
            $otelService->disable();
            $this->info('✅ OTLP export disabled');

            return self::SUCCESS;
        }

        // Reset
        if ($this->option('reset')) {
            $executed = true;
            $otelService->resetStats();
            $this->info('✅ OTLP export statistics reset');

            return self::SUCCESS;
        }

        // Test
        if ($this->option('test')) {
            $executed = true;
            $testEvent = new AnalyticsEvent(
                name: 'zb_otel_test',
                params: [
                    'test' => true,
                    'timestamp' => date('c'),
                    'command' => 'zb:analytics:otel',
                ],
                clientId: 'cli-test',
                source: 'cli',
            );

            if (! $otelService->isEnabled()) {
                $this->warn('⚠️  OTLP export is currently disabled. Use --enable first.');
                $this->line('');
            }

            $this->info('Sending test event to OTLP collector...');
            $this->line("  Endpoint: {$otelService->getEndpoint()}");

            $result = $otelService->export($testEvent);

            if ($result) {
                $this->info('✅ Test event exported successfully');
            } else {
                $this->error('❌ Test event export failed');
                $this->line('  Check that your OTLP collector is running and the endpoint is correct.');
            }

            return $result ? self::SUCCESS : self::FAILURE;
        }

        // Stats (default or explicit)
        if ($this->option('stats') || ! $executed) {
            $executed = true;
            $stats = $otelService->stats();

            if ($asJson) {
                $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            $this->info('📊 OTLP Export Statistics');
            $this->line('');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Status', $stats['enabled'] ? '✅ Enabled' : '⚠️  Disabled'],
                    ['Endpoint', $stats['endpoint']],
                    ['Events Exported', (string) $stats['exported']],
                    ['Successful', (string) $stats['success']],
                    ['Failed', (string) $stats['failure']],
                    ['Success Rate', "{$stats['success_rate']}%"],
                    ['Avg Latency', "{$stats['avg_latency_ms']}ms"],
                    ['Last Error', $stats['last_error'] ?? 'None'],
                ],
            );
        }

        return self::SUCCESS;
    }
}
