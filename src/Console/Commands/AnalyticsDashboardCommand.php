<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;

/**
 * Console command to export analytics dashboard data as structured JSON.
 *
 * Produces a comprehensive JSON payload containing provider status,
 * event catalog, config summary, metrics, and health information.
 * Ideal for external dashboards, monitoring systems, and CI pipelines.
 *
 * @since 1.0.0
 */
final class AnalyticsDashboardCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'zb:analytics:dashboard
        {--format=json : Output format (json or table)}
        {--include-catalog : Include full event catalog in output}
        {--include-metrics : Include dispatch metrics}
        {--include-health : Include health check data}
        {--pretty : Pretty-print JSON output}';

    /**
     * The console command description.
     */
    protected $description = 'Export analytics dashboard data as structured JSON';

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(AnalyticsManager $manager, ConfigRepository $config): int
    {
        $analyticsConfig = new AnalyticsConfig($config);

        $dashboard = $this->buildDashboard($manager, $analyticsConfig);

        if ($this->option('format') === 'table') {
            $this->displayAsTable($dashboard);

            return self::SUCCESS;
        }

        $flags = $this->option('pretty') ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : 0;

        $this->line((string) json_encode($dashboard, $flags));

        return self::SUCCESS;
    }

    /**
     * Build the dashboard data structure.
     *
     * @return array<string, mixed>
     */
    private function buildDashboard(AnalyticsManager $manager, AnalyticsConfig $config): array
    {
        $dashboard = [
            'version' => $manager->version(),
            'timestamp' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'providers' => $manager->providerSummary(),
            'config' => $config->summary(),
            'catalog' => [
                'total' => EventCatalog::count(),
                'ecommerce' => EventCatalog::category('ecommerce'),
                'saas' => EventCatalog::category('saas'),
                'engagement' => EventCatalog::category('engagement'),
            ],
            'consent' => $manager->getConsent()->toArray(),
        ];

        if ($this->option('include-metrics')) {
            $dashboard['metrics'] = $manager->metrics()->summary();
            $dashboard['metrics']['total_dispatched'] = $manager->metrics()->totalDispatched();
            $dashboard['metrics']['total_failed'] = $manager->metrics()->totalFailed();
        }

        if ($this->option('include-health')) {
            $dashboard['health'] = $this->getHealthData($manager);
        }

        if (! $this->option('include-catalog')) {
            $dashboard['catalog'] = [
                'total' => EventCatalog::count(),
                'by_category' => [
                    'ecommerce' => \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count(),
                    'saas' => \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::count(),
                    'engagement' => \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::count(),
                ],
                'by_provider' => EventCatalog::byProvider(),
            ];
        }

        return $dashboard;
    }

    /**
     * Get health check data.
     *
     * @return array<string, mixed>
     */
    private function getHealthData(AnalyticsManager $manager): array
    {
        $health = [
            'providers_enabled' => 0,
            'providers_total' => 0,
        ];

        foreach ($manager->providerSummary() as $provider => $info) {
            $health['providers_total']++;
            if ($info['enabled']) {
                $health['providers_enabled']++;
            }
        }

        try {
            $replay = app(\ZeroBoiler\Analytics\Queue\EventReplayQueue::class);
            $health['replay'] = $replay->summary();
        } catch (\Throwable $e) {
            $health['replay'] = ['status' => 'unavailable'];
        }

        return $health;
    }

    /**
     * Display dashboard data as a table.
     *
     * @param  array<string, mixed>  $dashboard
     */
    private function displayAsTable(array $dashboard): void
    {
        $this->table(['Section', 'Key', 'Value'], $this->flattenForTable($dashboard));
    }

    /**
     * Flatten dashboard data for table display.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function flattenForTable(array $data, string $prefix = ''): array
    {
        $rows = [];

        foreach ($data as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix.'.'.$key : (string) $key;

            if (is_array($value) && ! $this->isNumericArray($value)) {
                $rows = array_merge($rows, $this->flattenForTable($value, $fullKey));
            } elseif (is_bool($value)) {
                $rows[] = ['Dashboard', $fullKey, $value ? '✓' : '✗'];
            } elseif (is_scalar($value)) {
                $rows[] = ['Dashboard', $fullKey, (string) $value];
            } elseif (is_array($value)) {
                $rows[] = ['Dashboard', $fullKey, json_encode($value)];
            }
        }

        return $rows;
    }

    /**
     * Check if an array is a numeric (sequential) array.
     */
    private function isNumericArray(array $array): bool
    {
        return array_is_list($array);
    }
}
