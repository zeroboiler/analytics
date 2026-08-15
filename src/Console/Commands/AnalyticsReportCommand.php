<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService;
use ZeroBoiler\Analytics\Services\EventSessionizer;
use ZeroBoiler\Analytics\Services\EventFunnelAggregator;
use ZeroBoiler\Analytics\Services\ProviderHealthMonitor;
use ZeroBoiler\Analytics\Services\SaaSHealthScoreService;

/**
 * Scheduled analytics report generator command.
 *
 * Generates a comprehensive analytics health and performance report
 * suitable for cron-based scheduled delivery (email, Slack, dashboard).
 * Covers provider health, event catalog coverage, funnel conversion,
 * session engagement, and SaaS health score.
 *
 * @since 8.0.0
 */
final class AnalyticsReportCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:report
                            {--format=table : Output format (table, json)}
                            {--section=all : Report section (all, health, catalog, funnels, sessions, saas)}';

    /** @var string */
    protected $description = 'Generate a comprehensive analytics health & performance report';

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(
        ConfigRepository $config,
        CacheRepository $cache,
        AnalyticsHealthCheckService $healthCheck,
        ProviderHealthMonitor $providerHealth,
        SaaSHealthScoreService $healthScore,
    ): int
    {
        $section = (string) $this->option('section');
        $format = (string) $this->option('format');

        $report = [];

        if ($section === 'all' || $section === 'health') {
            $report['health'] = $this->buildHealthSection($healthCheck, $providerHealth, $config);
        }

        if ($section === 'all' || $section === 'catalog') {
            $report['catalog'] = $this->buildCatalogSection();
        }

        if ($section === 'all' || $section === 'funnels') {
            $funnelConfig = $config->get('zeroboiler.analytics.funnels', []);
            /** @var array{cache_ttl?: int} $funnelConfig */
            $aggregator = new EventFunnelAggregator($cache, [
                'cache_ttl' => $funnelConfig['cache_ttl'] ?? 300,
            ]);
            $report['funnels'] = $this->buildFunnelsSection($aggregator);
        }

        if ($section === 'all' || $section === 'sessions') {
            $sessionizer = new EventSessionizer($cache);
            $report['sessions'] = $this->buildSessionsSection($sessionizer, $config);
        }

        if ($section === 'all' || $section === 'saas') {
            $report['saas'] = $this->buildSaaSSection($healthScore);
        }

        if ($format === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderTable($report, $section);

        return self::SUCCESS;
    }

    /**
     * Build the provider health report section.
     *
     * @return array{providers: array<string, array{enabled: bool, status: string, success_rate: float, total_dispatched: int, last_dispatch: string|null}>, overall_status: string}
     */
    private function buildHealthSection(
        AnalyticsHealthCheckService $healthCheck,
        ProviderHealthMonitor $providerHealth,
        ConfigRepository $config,
    ): array
    {
        $health = $healthCheck->run();
        $providers = [];

        $providerMap = [
            'ga4' => ['label' => 'GA4', 'key' => 'ga4'],
            'gtm' => ['label' => 'GTM', 'key' => 'gtm'],
            'meta' => ['label' => 'Meta Pixel', 'key' => 'meta_pixel'],
            'plausible' => ['label' => 'Plausible', 'key' => 'plausible'],
            'posthog' => ['label' => 'PostHog', 'key' => 'posthog'],
            'webhook' => ['label' => 'Webhook', 'key' => 'webhook'],
        ];

        foreach ($providerMap as $key => $provider) {
            $cfg = $config->get('zeroboiler.analytics.' . $provider['key'], []);
            $enabled = (bool) ($cfg['enabled'] ?? false);

            $providerStatus = $providerHealth->getProviderStatus($key);
            $providers[$key] = [
                'enabled' => $enabled,
                'status' => $enabled ? ($providerStatus['status'] ?? 'unknown') : 'disabled',
                'success_rate' => $enabled ? round(($providerStatus['success_rate'] ?? 0) * 100, 2) : 0.0,
                'total_dispatched' => (int) ($providerStatus['total_dispatched'] ?? 0),
                'last_dispatch' => $providerStatus['last_dispatch'] ?? null,
            ];
        }

        return [
            'providers' => $providers,
            'overall_status' => $health['overall_status'] ?? 'healthy',
        ];
    }

    /**
     * Build the event catalog coverage section.
     *
     * @return array{total_events: int, categories: array<string, int>, provider_coverage: array<string, int>, recent_events: list<string>}
     */
    private function buildCatalogSection(): array
    {
        $categories = EventCatalog::byCategory();

        return [
            'total_events' => EventCatalog::count(),
            'categories' => array_map(static fn (array $cat): int => count($cat), $categories),
            'provider_coverage' => [
                'ga4' => count(EventCatalog::allGa4Names()),
                'meta' => count(EventCatalog::allMetaNames()),
                'posthog' => count(EventCatalog::allPosthogNames()),
                'plausible' => count(EventCatalog::allPlausibleNames()),
            ],
            'recent_events' => array_slice(EventCatalog::names(), 0, 10),
        ];
    }

    /**
     * Build the funnel conversion report section.
     *
     * @return array<string, array{funnel: string, overall_conversion_rate: float, total_entered: int, total_completed: int, steps: int}>
     */
    private function buildFunnelsSection(EventFunnelAggregator $aggregator): array
    {
        $reports = $aggregator->getAllFunnelReports();
        $result = [];

        foreach ($reports as $name => $report) {
            $result[$name] = [
                'funnel' => $report['funnel'],
                'overall_conversion_rate' => $report['overall_conversion_rate'],
                'total_entered' => $report['total_entered'],
                'total_completed' => $report['total_completed'],
                'steps' => count($aggregator->getDefinedFunnels()[$name]['steps'] ?? []),
            ];
        }

        return $result;
    }

    /**
     * Build the session analytics section.
     *
     * @return array{note: string, config: array<string, mixed>}
     */
    private function buildSessionsSection(EventSessionizer $sessionizer, ConfigRepository $config): array
    {
        return [
            'note' => 'Session analytics require Redis cache driver for production scale. Results shown are per-client aggregate.',
            'config' => [
                'session_ttl' => $config->get('zeroboiler.analytics.sessionizer.session_ttl', 1800),
                'max_sessions' => $config->get('zeroboiler.analytics.sessionizer.max_sessions_per_client', 50),
            ],
        ];
    }

    /**
     * Build the SaaS health score section.
     *
     * @return array{score: int, grade: string, dimensions: array<string, mixed>}
     */
    private function buildSaaSSection(SaaSHealthScoreService $healthScore): array
    {
        $score = $healthScore->calculate();

        return [
            'score' => $score['score'],
            'grade' => $score['grade'],
            'dimensions' => $score['dimensions'] ?? [],
        ];
    }

    /**
     * Render the report as a formatted table.
     *
     * @param  array<string, mixed>  $report
     */
    private function renderTable(array $report, string $section): void
    {
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║          ZeroBoiler Analytics Report — v8.2.0          ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Health section
        if (isset($report['health'])) {
            $health = $report['health'];
            $this->info('┌─ Provider Health ──────────────────────────────────────');
            $this->table(
                ['Provider', 'Enabled', 'Status', 'Success Rate', 'Dispatched'],
                array_map(
                    static fn (string $key, array $p): array => [
                        $key,
                        $p['enabled'] ? '✓' : '✗',
                        $p['status'],
                        $p['success_rate'] . '%',
                        (string) $p['total_dispatched'],
                    ],
                    array_keys($health['providers']),
                    array_values($health['providers']),
                ),
            );
            $this->info('  Overall: ' . ($health['overall_status'] ?? 'unknown'));
            $this->newLine();
        }

        // Catalog section
        if (isset($report['catalog'])) {
            $catalog = $report['catalog'];
            $this->info('┌─ Event Catalog ────────────────────────────────────────');
            $this->table(
                ['Category', 'Events'],
                array_map(
                    static fn (string $cat, int $count): array => [$cat, (string) $count],
                    array_keys($catalog['categories']),
                    array_values($catalog['categories']),
                ),
            );
            $this->info('  Total: ' . $catalog['total_events'] . ' events');
            $this->info('  Provider Coverage: GA4=' . $catalog['provider_coverage']['ga4'] . ', Meta=' . $catalog['provider_coverage']['meta'] . ', PostHog=' . $catalog['provider_coverage']['posthog'] . ', Plausible=' . $catalog['provider_coverage']['plausible']);
            $this->newLine();
        }

        // Funnels section
        if (isset($report['funnels'])) {
            $this->info('┌─ Funnel Conversion ─────────────────────────────────────');
            $funnelData = $report['funnels'];
            if (count($funnelData) > 0) {
                $this->table(
                    ['Funnel', 'Conv. Rate', 'Entered', 'Completed', 'Steps'],
                    array_map(
                        static fn (string $name, array $f): array => [
                            $name,
                            $f['overall_conversion_rate'] . '%',
                            (string) $f['total_entered'],
                            (string) $f['total_completed'],
                            (string) $f['steps'],
                        ],
                        array_keys($funnelData),
                        array_values($funnelData),
                    ),
                );
            } else {
                $this->warn('  No funnel data available yet.');
            }
            $this->newLine();
        }

        // Sessions section
        if (isset($report['sessions'])) {
            $this->info('┌─ Session Analytics ────────────────────────────────────');
            foreach ($report['sessions']['config'] as $key => $value) {
                $this->info('  ' . $key . ': ' . (is_array($value) ? json_encode($value) : (string) $value));
            }
            $this->newLine();
        }

        // SaaS section
        if (isset($report['saas'])) {
            $saas = $report['saas'];
            $this->info('┌─ SaaS Health Score ────────────────────────────────────');
            $this->info('  Score: ' . $saas['score'] . '/100');
            $this->info('  Grade: ' . $saas['grade']);
            $this->newLine();
        }

        $this->info('── End of Report ──────────────────────────────────────────');
    }
}
