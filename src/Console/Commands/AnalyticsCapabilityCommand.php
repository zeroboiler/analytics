<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\ProviderCapabilityMatrixService;

/**
 * Analytics Provider Capability Matrix command.
 *
 * Displays the feature capability matrix for all analytics providers.
 * Supports multiple output modes: table, JSON, comparison, and gap analysis.
 *
 * @since 215.0.0
 * @see \ZeroBoiler\Analytics\Services\ProviderCapabilityMatrixService
 */
final class AnalyticsCapabilityCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:capability
        {action? : Action to perform (default: ranking)}
        {--provider= : Show capability profile for a specific provider}
        {--compare= : Compare two providers (comma-separated, e.g. ga4,meta_pixel)}
        {--capability= : Check which providers support a capability}
        {--missing : Find providers missing a capability (use with --capability)}
        {--json : Output as JSON}
        {--rank : Show coverage ranking (default action)}';

    /** @var string */
    protected $description = 'Display analytics provider capability matrix and feature comparison';

    /**
     * Execute the command.
     */
    public function handle(ProviderCapabilityMatrixService $service): int
    {
        $action = $this->argument('action');
        $asJson = $this->option('json');

        return match ($action) {
            'compare' => $this->actionCompare($service, $asJson),
            'check' => $this->actionCheck($service, $asJson),
            'profile' => $this->actionProfile($service, $asJson),
            'summary' => $this->actionSummary($service, $asJson),
            'matrix' => $this->actionMatrix($service, $asJson),
            default => $this->actionRanking($service, $asJson),
        };
    }

    /**
     * Coverage ranking action.
     */
    private function actionRanking(ProviderCapabilityMatrixService $service, bool $asJson): int
    {
        $rankings = $service->coverageRanking();

        if ($asJson) {
            $this->output->write(json_encode($rankings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Provider Capability Coverage Ranking');
        $this->line('');

        $headers = ['Provider', 'Display Name', 'Supported', 'Total', 'Coverage', 'Grade'];
        $rows = array_map(
            static fn (array $r): array => [
                $r['provider'],
                $r['display_name'],
                $r['supported'],
                $r['total'],
                $r['coverage'] . '%',
                $r['grade'],
            ],
            $rankings,
        );

        $this->table($headers, $rows);

        $summary = $service->coverageSummary();
        $this->newLine();
        $this->info("Average coverage: {$summary['avg_coverage']}%");
        $this->info("Best: {$summary['best_provider']} ({$summary['best_coverage']}%)");
        $this->info("Worst: {$summary['worst_provider']} ({$summary['worst_coverage']}%)");
        $this->info("Capabilities tracked: {$summary['capabilities']}");

        return self::SUCCESS;
    }

    /**
     * Provider comparison action.
     */
    private function actionCompare(ProviderCapabilityMatrixService $service, bool $asJson): int
    {
        $compareOption = $this->option('compare');

        if ($compareOption !== null) {
            $providers = explode(',', $compareOption);
        } else {
            $providers = [];
        }

        if (count($providers) !== 2) {
            $this->error('Comparison requires exactly 2 providers. Use --compare=ga4,meta_pixel');

            return self::FAILURE;
        }

        $comparison = $service->compare($providers[0], $providers[1]);

        if (empty($comparison)) {
            $this->error("Unknown provider(s): {$providers[0]}, {$providers[1]}");

            return self::FAILURE;
        }

        if ($asJson) {
            $this->output->write(json_encode($comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Capability Comparison: {$providers[0]} vs {$providers[1]}");
        $this->line('');

        $headers = ['Capability', $providers[0], $providers[1], 'Match'];
        $rows = [];

        foreach ($comparison as $capName => $data) {
            $rows[] = [
                $capName,
                $data['provider_a'] ? '✓' : '✗',
                $data['provider_b'] ? '✓' : '✗',
                $data['match'] ? '=' : '≠',
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Check single capability support.
     */
    private function actionCheck(ProviderCapabilityMatrixService $service, bool $asJson): int
    {
        $capability = $this->option('capability');

        if ($capability === null) {
            $this->error('Use --capability=<name> to check capability support');

            return self::FAILURE;
        }

        $providers = $service->getProviders();
        $results = [];

        foreach ($providers as $provider) {
            $results[$provider] = $service->supports($provider, $capability);
        }

        if ($this->option('missing')) {
            $missing = $service->findProvidersMissing($capability);

            if ($asJson) {
                $this->output->write(json_encode(['capability' => $capability, 'missing' => $missing], JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            if (empty($missing)) {
                $this->info("All providers support '{$capability}'");
            } else {
                $this->warn("Providers missing '{$capability}': " . implode(', ', $missing));
            }

            return self::SUCCESS;
        }

        if ($asJson) {
            $this->output->write(json_encode(['capability' => $capability, 'support' => $results], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $headers = ['Provider', 'Supports'];
        $rows = array_map(
            static fn (string $p): array => [$p, $results[$p] ? '✓' : '✗'],
            $providers,
        );

        $this->info("Capability Support: {$capability}");
        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Single provider profile action.
     */
    private function actionProfile(ProviderCapabilityMatrixService $service, bool $asJson): int
    {
        $provider = $this->option('provider');

        if ($provider === null) {
            $this->error('Use --provider=<id> to show a provider profile');

            return self::FAILURE;
        }

        $profile = $service->getProfile($provider);

        if ($profile === null) {
            $this->error("Unknown provider: {$provider}");

            return self::FAILURE;
        }

        if ($asJson) {
            $this->output->write(json_encode($profile->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Provider Profile: {$profile->displayName}");
        $this->line("Type: {$profile->providerType}");
        $this->line("Coverage: {$profile->coveragePercent}% ({$profile->supportedCount}/{$profile->totalCapabilities})");
        $this->newLine();

        $headers = ['Capability', 'Type', 'Supported', 'Value', 'Description'];
        $rows = [];

        foreach ($profile->capabilities as $cap) {
            $rows[] = [
                $cap->name,
                $cap->type,
                $cap->supported ? '✓' : '✗',
                $cap->value !== null ? (string) $cap->value : '-',
                $cap->description,
            ];
        }

        $this->table($headers, $rows);

        if (! empty($profile->limitations)) {
            $this->newLine();
            $this->info('Limitations:');
            foreach ($profile->limitations as $cap => $limit) {
                $this->line("  {$cap}: {$limit}");
            }
        }

        if (! empty($profile->missingCapabilities)) {
            $this->newLine();
            $this->comment('Missing capabilities: ' . implode(', ', $profile->missingCapabilities));
        }

        return self::SUCCESS;
    }

    /**
     * Coverage summary action.
     */
    private function actionSummary(ProviderCapabilityMatrixService $service, bool $asJson): int
    {
        $summary = $service->coverageSummary();

        if ($asJson) {
            $this->output->write(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Provider Capability Summary');
        $this->line('');
        $this->line("Total providers: {$summary['providers']}");
        $this->line("Total capabilities: {$summary['capabilities']}");
        $this->line("Average coverage: {$summary['avg_coverage']}%");
        $this->line("Best provider: {$summary['best_provider']} ({$summary['best_coverage']}%)");
        $this->line("Worst provider: {$summary['worst_provider']} ({$summary['worst_coverage']}%)");

        return self::SUCCESS;
    }

    /**
     * Full matrix table action.
     */
    private function actionMatrix(ProviderCapabilityMatrixService $service, bool $asJson): int
    {
        $matrix = $service->matrixTable();

        if ($asJson) {
            $this->output->write(json_encode($matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Full Provider × Capability Matrix');
        $this->table($matrix['headers'], $matrix['rows']);

        return self::SUCCESS;
    }
}
