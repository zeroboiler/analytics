<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\DeclarativeFunnelService;
use ZeroBoiler\Analytics\Services\PrivacyCollectionService;

/**
 * Admin command for declarative funnel and privacy collection diagnostics.
 *
 * Displays defined funnels, their step progress, and privacy collection
 * configuration status. Supports JSON output for CI/CD integration.
 *
 * @since 58.0.0
 */
final class AnalyticsFunnelPrivacyCommand extends Command
{
    protected $signature = 'zb:analytics:funnel-privacy
        {--json : Output as JSON}
        {--funnel= : Show specific funnel definition}
        {--privacy : Show privacy collection status}';

    protected $description = 'Display declarative funnel definitions and privacy collection diagnostics';

    private ?DeclarativeFunnelService $funnelService;

    private ?PrivacyCollectionService $privacyService;

    public function __construct(
        ?DeclarativeFunnelService $funnelService = null,
        ?PrivacyCollectionService $privacyService = null,
    ){
        parent::__construct();
        $this->funnelService = $funnelService;
        $this->privacyService = $privacyService;
    }

    #[Override]
    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
        $result = $this->buildReport();

        if ($outputJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderReport($result);

        return self::SUCCESS;
    }

    /**
     * Build the diagnostic report data.
     *
     * @return array<string, mixed>
     */
    private function buildReport(): array
    {
        $report = [
            'funnels' => $this->buildFunnelReport(),
            'privacy_collection' => $this->buildPrivacyReport(),
        ];

        return $report;
    }

    /**
     * @return array{enabled: bool, definitions: array<string, mixed>}
     */
    private function buildFunnelReport(): array
    {
        if ($this->funnelService === null) {
            return ['enabled' => false, 'definitions' => [], 'error' => 'Service not available'];
        }

        $specificFunnel = $this->option('funnel');

        if ($specificFunnel !== null && is_string($specificFunnel)) {
            $definition = $this->funnelService->getDefinition($specificFunnel);

            return [
                'enabled' => $this->funnelService->isEnabled(),
                'definitions' => $definition !== null
                    ? [$specificFunnel => $definition]
                    : [],
            ];
        }

        $definitions = [];
        foreach ($this->funnelService->getFunnelNames() as $name) {
            $definitions[$name] = $this->funnelService->getDefinition($name);
        }

        return [
            'enabled' => $this->funnelService->isEnabled(),
            'definitions' => $definitions,
        ];
    }

    /**
     * @return array{enabled: bool, hash_algorithm: string, ip_anonymization: bool, cache_ttl: int}
     */
    private function buildPrivacyReport(): array
    {
        if ($this->privacyService === null) {
            return ['enabled' => false, 'error' => 'Service not available'];
        }

        return [
            'enabled' => $this->privacyService->isEnabled(),
        ];
    }

    /**
     * Render the report to the console.
     *
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        // Funnels section
        $this->info('── Declarative Funnels ──');
        $funnelReport = $report['funnels'];

        if (($funnelReport['error'] ?? null) !== null) {
            $this->warn('  ' . $funnelReport['error']);

            return;
        }

        $this->line('  Enabled: ' . ($funnelReport['enabled'] ? '<info>Yes</info>' : '<comment>No</info>'));
        $this->newLine();

        foreach ($funnelReport['definitions'] as $name => $definition) {
            $steps = $definition['steps'] ?? [];
            $stepCount = count($steps);

            $this->line("  <comment>{$name}</comment> ({$stepCount} steps):");

            foreach ($steps as $i => $step) {
                $stepName = $step['name'] ?? $step['event'] ?? '?';
                $stepEvent = $step['event'] ?? '?';
                $timeout = $step['timeout'] ?? null;

                $label = "[{$i}] {$stepName}";
                if ($timeout !== null) {
                    $label .= " (timeout: {$timeout}s)";
                }

                $this->line("    {$label}  →  <dim>{$stepEvent}</dim>");
            }

            if (($definition['completion_event'] ?? null) !== null) {
                $this->line("    ✓ completion_event: {$definition['completion_event']}");
            }

            if (($definition['abandonment_timeout'] ?? null) !== null) {
                $this->line("    ⏱ abandonment_timeout: {$definition['abandonment_timeout']}s");
            }

            $this->newLine();
        }

        // Privacy section
        if ($this->option('privacy')) {
            $this->info('── Privacy Collection ──');
            $privacyReport = $report['privacy_collection'];

            if (($privacyReport['error'] ?? null) !== null) {
                $this->warn('  ' . $privacyReport['error']);
            } else {
                $this->line('  Enabled: ' . ($privacyReport['enabled'] ? '<info>Yes</info>' : '<comment>No</comment>'));
            }
        }
    }
}
