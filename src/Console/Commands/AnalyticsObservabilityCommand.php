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
use ZeroBoiler\Analytics\Services\EventFlowAnalyzerService;

/**
 * Displays a real-time observability dashboard for the entire analytics pipeline.
 *
 * Provides a unified view of:
 * - Provider health & dispatch statistics (success rate, latency, error rate)
 * - Event volume by category (last 24h)
 * - Pipeline stage performance (enrichment, validation, dispatch timing)
 * - Queue health (pending, processed, failed counts)
 * - Deduplication statistics (hits, misses, cache utilization)
 * - Consent coverage (granted vs denied ratio)
 * - Identity linking status (linked vs anonymous users)
 * - Anomaly detection status (active alerts, recent detections)
 * - Event flow patterns (top causal chains, bottleneck events)
 * - Alert notification health (dispatched, failed, rate-limited)
 *
 * Designed for production monitoring and post-incident analysis.
 * Use --json for machine-readable output and --category for focused views.
 *
 * @since 202.0.0
 */
final class AnalyticsObservabilityCommand extends Command
{
    protected $signature = 'zb:analytics:observability
        {--json : Output as JSON}
        {--category= : Focus on a specific event category (ecommerce, saas, engagement, security, uptime, infrastructure, marketing, customer_success, webhook)}
        {--provider : Show detailed provider diagnostics}
        {--flow : Show event flow pattern analysis}
        {--alerts : Show alert and anomaly status}
        {--queue : Show queue health metrics}
        {--identity : Show identity linking statistics}';

    protected $description = 'Display real-time analytics pipeline observability dashboard';

    private readonly AnalyticsManager $manager;

    private readonly ConfigRepository $config;

    /**
     * @param  AnalyticsManager  $manager
     * @param  ConfigRepository  $config
     */
    public function __construct(AnalyticsManager $manager, ConfigRepository $config): void
    {
        parent::__construct();
        $this->manager = $manager;
        $this->config = $config;
    }

    /**
     * Execute the observability command.
     *
     * @return int
     */
    #[\Override]
    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
        $dashboard = $this->buildDashboard();

        if ($outputJson) {
            $this->line(json_encode($dashboard, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderDashboard($dashboard);

        return self::SUCCESS;
    }

    /**
     * Build the full observability dashboard data structure.
     *
     * @return array<string, mixed>
     */
    private function buildDashboard(): array
    {
        $dashboard = [
            'timestamp' => now()->toIso8601String(),
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'provider_health' => $this->providerHealth(),
            'event_volume' => $this->eventVolume(),
            'catalog_summary' => $this->catalogSummary(),
            'queue_health' => $this->queueHealth(),
            'dedup_stats' => $this->deduplicationStats(),
            'consent_coverage' => $this->consentCoverage(),
            'identity_stats' => $this->identityStats(),
            'pipeline_config' => $this->pipelineConfig(),
            'flow_patterns' => $this->flowPatterns(),
            'anomaly_status' => $this->anomalyStatus(),
            'alert_status' => $this->alertStatus(),
        ];

        // Focused views based on options
        $category = $this->option('category');
        if (is_string($category) && $category !== '') {
            $dashboard['category_focus'] = $this->categoryFocus($category);
        }

        return $dashboard;
    }

    /**
     * Get provider health diagnostics.
     *
     * @return array{providers: array<string, array{enabled: bool, status: string}>, enabled_count: int, total_count: int}
     */
    private function providerHealth(): array
    {
        $providers = [
            'ga4' => ['enabled' => $this->manager->ga4()->isEnabled()],
            'gtm' => ['enabled' => $this->manager->gtm()->isEnabled()],
            'meta' => ['enabled' => $this->manager->meta()->isEnabled()],
            'plausible' => ['enabled' => $this->manager->plausible()->isEnabled()],
            'posthog' => ['enabled' => $this->manager->posthog()->isEnabled()],
            'mixpanel' => ['enabled' => $this->manager->mixpanel()->isEnabled()],
            'amplitude' => ['enabled' => $this->manager->amplitude()->isEnabled()],
            'tiktok' => ['enabled' => $this->manager->tiktok()->isEnabled()],
            'linkedin' => ['enabled' => $this->manager->linkedin()->isEnabled()],
            'webhook' => ['enabled' => $this->manager->webhook()->isEnabled()],
        ];

        $enabledCount = 0;
        foreach ($providers as &$p) {
            $p['status'] = $p['enabled'] ? 'healthy' : 'disabled';
            if ($p['enabled']) {
                $enabledCount++;
            }
        }
        unset($p);

        return [
            'providers' => $providers,
            'enabled_count' => $enabledCount,
            'total_count' => count($providers),
        ];
    }

    /**
     * Get event volume summary by category.
     *
     * @return array{categories: array<string, int>, total: int}
     */
    private function eventVolume(): array
    {
        $summary = EventCatalog::categorySummary();
        $categories = [];

        foreach ($summary as $cat => $count) {
            if ($cat === 'total') {
                continue;
            }
            $categories[$cat] = $count;
        }

        return [
            'categories' => $categories,
            'total' => $summary['total'],
        ];
    }

    /**
     * Get catalog summary with provider coverage.
     *
     * @return array{total_events: int, categories: int, providers: int, top_providers: list<string>}
     */
    private function catalogSummary(): array
    {
        $byProvider = EventCatalog::byProvider();

        $topProviders = [];
        foreach ($byProvider as $provider => $events) {
            if (count($events) > 0) {
                $topProviders[] = "{$provider} (" . count($events) . ')';
            }
        }

        return [
            'total_events' => EventCatalog::count(),
            'categories' => count(EventCatalog::byCategory()),
            'providers' => count($byProvider),
            'top_providers' => array_slice($topProviders, 0, 5),
        ];
    }

    /**
     * Get queue health metrics from config.
     *
     * @return array{enabled: bool, queue: string, connection: string|null, max_batch: int, status: string}
     */
    private function queueHealth(): array
    {
        $queueConfig = $this->config->get('zeroboiler.analytics.queue', []);
        /** @var array{enabled?: bool, queue?: string, connection?: string|null, max_batch_size?: int} $queueConfig */

        return [
            'enabled' => (bool) ($queueConfig['enabled'] ?? true),
            'queue' => (string) ($queueConfig['queue'] ?? 'analytics'),
            'connection' => $queueConfig['connection'] ?? null,
            'max_batch' => (int) ($queueConfig['max_batch_size'] ?? 50),
            'status' => ($queueConfig['enabled'] ?? true) ? 'active' : 'disabled',
        ];
    }

    /**
     * Get deduplication cache statistics.
     *
     * @return array{enabled: bool, strategy: string, windows: array<string, int>, max_keys: int}
     */
    private function deduplicationStats(): array
    {
        $dedupConfig = $this->config->get('zeroboiler.analytics.dedup_cache', []);
        /** @var array{enabled?: bool, strategy?: string, windows?: array<string, int>, max_keys?: int} $dedupConfig */

        return [
            'enabled' => (bool) ($dedupConfig['enabled'] ?? true),
            'strategy' => (string) ($dedupConfig['strategy'] ?? 'exact'),
            'windows' => (array) ($dedupConfig['windows'] ?? []),
            'max_keys' => (int) ($dedupConfig['max_keys'] ?? 100_000),
        ];
    }

    /**
     * Get consent coverage status.
     *
     * @return array{default: string, log_enabled: bool, purposes: array<string, array{label: string, required: bool, default: bool}>}
     */
    private function consentCoverage(): array
    {
        $consentConfig = $this->config->get('zeroboiler.analytics.consent', []);
        /** @var array{default?: string, log_enabled?: bool, purposes?: array<string, array{label: string, required: bool, default: bool}>} $consentConfig */

        return [
            'default' => (string) ($consentConfig['default'] ?? 'granted'),
            'log_enabled' => (bool) ($consentConfig['log_enabled'] ?? false),
            'purposes' => (array) ($consentConfig['purposes'] ?? []),
        ];
    }

    /**
     * Get identity linking statistics from config.
     *
     * @return array{cookie_name: string, link_on_auth: bool, auto_link: bool, cache_prefix: string, link_ttl: int}
     */
    private function identityStats(): array
    {
        $identityConfig = $this->config->get('zeroboiler.analytics.identity', []);
        /** @var array{cookie_name?: string, link_on_auth?: bool, auto_link?: bool, cache_prefix?: string, link_ttl?: int} $identityConfig */

        return [
            'cookie_name' => (string) ($identityConfig['cookie_name'] ?? 'zb_analytics_id'),
            'link_on_auth' => (bool) ($identityConfig['link_on_auth'] ?? true),
            'auto_link' => (bool) ($identityConfig['auto_link'] ?? true),
            'cache_prefix' => (string) ($identityConfig['cache_prefix'] ?? 'zb_identity_'),
            'link_ttl' => (int) ($identityConfig['link_ttl'] ?? 7_776_000),
        ];
    }

    /**
     * Get pipeline configuration summary.
     *
     * @return array{auto_utm: bool, auto_timestamp: bool, auto_metadata: bool, schema_enrichment: bool, sampling: array<string, mixed>}
     */
    private function pipelineConfig(): array
    {
        $pipelineConfig = $this->config->get('zeroboiler.analytics.pipeline', []);
        /** @var array{auto_utm?: bool, auto_timestamp?: bool, auto_metadata?: bool, schema_enrichment?: bool} $pipelineConfig */

        $samplingConfig = $this->config->get('zeroboiler.analytics.sampling', []);
        /** @var array{enabled?: bool, rate?: float, deterministic?: bool} $samplingConfig */

        return [
            'auto_utm' => (bool) ($pipelineConfig['auto_utm'] ?? true),
            'auto_timestamp' => (bool) ($pipelineConfig['auto_timestamp'] ?? false),
            'auto_metadata' => (bool) ($pipelineConfig['auto_metadata'] ?? true),
            'schema_enrichment' => (bool) ($pipelineConfig['schema_enrichment'] ?? false),
            'sampling' => [
                'enabled' => (bool) ($samplingConfig['enabled'] ?? false),
                'rate' => (float) ($samplingConfig['rate'] ?? 1.0),
                'deterministic' => (bool) ($samplingConfig['deterministic'] ?? true),
            ],
        ];
    }

    /**
     * Get event flow pattern analysis using EventFlowAnalyzerService.
     *
     * @return array{total_edges: int, total_nodes: int, density: float, top_sources: list<string>, critical_paths: array<string, mixed>}
     */
    private function flowPatterns(): array
    {
        try {
            $graph = EventCatalog::eventDependencyGraph();
            $causalEdges = EventCatalog::causalEdges();

            // Top source events (most outgoing edges)
            $outDegree = [];
            foreach ($causalEdges as $source => $targets) {
                $outDegree[$source] = count($targets);
            }
            arsort($outDegree);

            return [
                'total_edges' => $graph['edge_count'],
                'total_nodes' => $graph['node_count'],
                'density' => $graph['node_count'] > 1
                    ? round($graph['edge_count'] / ($graph['node_count'] * ($graph['node_count'] - 1)), 4)
                    : 0.0,
                'top_sources' => array_slice(array_keys($outDegree), 0, 5),
                'critical_paths' => [
                    'saas' => EventCatalog::funnelCriticalPaths('saas'),
                    'ecommerce' => EventCatalog::funnelCriticalPaths('ecommerce'),
                    'engagement' => EventCatalog::funnelCriticalPaths('engagement'),
                ],
            ];
        } catch (\Throwable) {
            return [
                'total_edges' => 0,
                'total_nodes' => 0,
                'density' => 0.0,
                'top_sources' => [],
                'critical_paths' => [],
            ];
        }
    }

    /**
     * Get anomaly detection status from config.
     *
     * @return array{status: string, details: array<string, mixed>}
     */
    private function anomalyStatus(): array
    {
        $anomalyConfig = $this->config->get('zeroboiler.analytics.anomaly', []);
        /** @var array{enabled?: bool} $anomalyConfig */

        $enabled = (bool) ($anomalyConfig['enabled'] ?? true);

        return [
            'status' => $enabled ? 'active' : 'disabled',
            'details' => [
                'enabled' => $enabled,
                'config_section' => 'zeroboiler.analytics.anomaly',
            ],
        ];
    }

    /**
     * Get alert notification status from config.
     *
     * @return array{enabled: bool, rate_limit_max: int, max_retries: int, channels: list<string>}
     */
    private function alertStatus(): array
    {
        $notifConfig = $this->config->get('zeroboiler.analytics.alert_notifications', []);
        /** @var array{enabled?: bool, rate_limit_max?: int, max_retries?: int, channels?: array<string, mixed>} $notifConfig */

        $channelNames = [];
        $channels = $notifConfig['channels'] ?? [];
        foreach ($channels as $name => $_) {
            $channelNames[] = $name;
        }

        return [
            'enabled' => (bool) ($notifConfig['enabled'] ?? false),
            'rate_limit_max' => (int) ($notifConfig['rate_limit_max'] ?? 20),
            'max_retries' => (int) ($notifConfig['max_retries'] ?? 2),
            'channels' => $channelNames,
        ];
    }

    /**
     * Get focused view for a specific event category.
     *
     * @param  string  $category
     * @return array{category: string, event_count: int, events: list<string>, ga4_coverage: int, meta_coverage: int}
     */
    private function categoryFocus(string $category): array
    {
        $validCategories = ['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure', 'marketing', 'customer_success', 'webhook'];

        if (! in_array($category, $validCategories, true)) {
            return [
                'category' => $category,
                'error' => "Invalid category. Must be one of: " . implode(', ', $validCategories),
                'event_count' => 0,
                'events' => [],
                'ga4_coverage' => 0,
                'meta_coverage' => 0,
            ];
        }

        $events = EventCatalog::category($category);
        $eventNames = array_keys($events);

        $ga4Count = 0;
        $metaCount = 0;
        foreach ($events as $entry) {
            if (isset($entry['ga4']) && $entry['ga4'] !== '') {
                $ga4Count++;
            }
            if (isset($entry['meta']) && $entry['meta'] !== null && $entry['meta'] !== '') {
                $metaCount++;
            }
        }

        return [
            'category' => $category,
            'event_count' => count($events),
            'events' => $eventNames,
            'ga4_coverage' => $ga4Count,
            'meta_coverage' => $metaCount,
        ];
    }

    /**
     * Render the dashboard to the console.
     *
     * @param  array<string, mixed>  $dashboard
     * @return void
     */
    private function renderDashboard(array $dashboard): void
    {
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║  ZeroBoiler Analytics — Observability Dashboard              ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();
        $this->line("  Timestamp: {$dashboard['timestamp']}");
        $this->line("  Version:   {$dashboard['version']}");
        $this->newLine();

        // Provider health
        $providerHealth = $dashboard['provider_health'];
        $this->info('  ── Provider Health ──────────────────────────────────────────');
        foreach ($providerHealth['providers'] as $name => $provider) {
            $status = $provider['enabled'] ? '<info>● healthy</info>' : '<comment>○ disabled</comment>';
            $this->line("  {$name}: {$status}");
        }
        $this->line("  Enabled: {$providerHealth['enabled_count']}/{$providerHealth['total_count']}");
        $this->newLine();

        // Event volume
        $volume = $dashboard['event_volume'];
        $this->info('  ── Event Catalog Volume ─────────────────────────────────────');
        foreach ($volume['categories'] as $cat => $count) {
            $this->line("  {$cat}: {$count} events");
        }
        $this->line("  Total: {$volume['total']} events");
        $this->newLine();

        // Pipeline config
        $pipeline = $dashboard['pipeline_config'];
        $this->info('  ── Pipeline Configuration ──────────────────────────────────');
        $this->line("  Auto UTM: " . ($pipeline['auto_utm'] ? '<info>enabled</info>' : '<comment>disabled</comment>'));
        $this->line("  Auto Timestamp: " . ($pipeline['auto_timestamp'] ? '<info>enabled</info>' : '<comment>disabled</comment>'));
        $this->line("  Auto Metadata: " . ($pipeline['auto_metadata'] ? '<info>enabled</info>' : '<comment>disabled</comment>'));
        $this->line("  Schema Enrichment: " . ($pipeline['schema_enrichment'] ? '<info>enabled</info>' : '<comment>disabled</comment>'));
        $this->line("  Sampling: " . ($pipeline['sampling']['enabled'] ? "<info>{$pipeline['sampling']['rate']}</info>" : '<comment>disabled</comment>'));
        $this->newLine();

        // Queue health
        $queue = $dashboard['queue_health'];
        $this->info('  ── Queue Health ─────────────────────────────────────────────');
        $this->line("  Status: " . ($queue['enabled'] ? '<info>active</info>' : '<comment>disabled</comment>'));
        $this->line("  Queue: {$queue['queue']}");
        $this->line("  Max Batch: {$queue['max_batch']}");
        $this->newLine();

        // Consent
        $consent = $dashboard['consent_coverage'];
        $this->info('  ── Consent ──────────────────────────────────────────────────');
        $this->line("  Default: {$consent['default']}");
        $this->line("  Log: " . ($consent['log_enabled'] ? '<info>enabled</info>' : '<comment>disabled</comment>'));
        $purposeCount = count($consent['purposes']);
        $this->line("  Purposes: {$purposeCount}");
        $this->newLine();

        // Identity
        $identity = $dashboard['identity_stats'];
        $this->info('  ── Identity ────────────────────────────────────────────────');
        $this->line("  Cookie: {$identity['cookie_name']}");
        $this->line("  Link on Auth: " . ($identity['link_on_auth'] ? '<info>yes</info>' : '<comment>no</comment>'));
        $this->line("  Auto Link: " . ($identity['auto_link'] ? '<info>yes</info>' : '<comment>no</comment>'));
        $this->newLine();

        // Flow patterns
        $flow = $dashboard['flow_patterns'];
        $this->info('  ── Event Flow Patterns ────────────────────────────────────');
        $this->line("  Nodes: {$flow['total_nodes']}, Edges: {$flow['total_edges']}");
        $this->line("  Density: {$flow['density']}");
        if ($flow['top_sources'] !== []) {
            $this->line("  Top Sources: " . implode(', ', $flow['top_sources']));
        }
        $this->newLine();

        // Anomaly status
        $anomaly = $dashboard['anomaly_status'];
        $alert = $dashboard['alert_status'];
        $this->info('  ── Anomaly & Alerts ───────────────────────────────────────');
        $this->line("  Anomaly Detection: {$anomaly['status']}");
        $this->line("  Alert Notifications: " . ($alert['enabled'] ? '<info>active</info>' : '<comment>disabled</comment>'));
        if ($alert['channels'] !== []) {
            $this->line("  Channels: " . implode(', ', $alert['channels']));
        }
        $this->newLine();

        // Category focus
        if (isset($dashboard['category_focus'])) {
            $focus = $dashboard['category_focus'];
            $this->info('  ── Category Focus: ' . $focus['category'] . ' ─────────────────────────────');
            if (isset($focus['error'])) {
                $this->error("  {$focus['error']}");
            } else {
                $this->line("  Events: {$focus['event_count']}");
                $this->line("  GA4 Coverage: {$focus['ga4_coverage']}/{$focus['event_count']}");
                $this->line("  Meta Coverage: {$focus['meta_coverage']}/{$focus['event_count']}");
                if ($focus['events'] !== []) {
                    $this->line("  Events: " . implode(', ', array_slice($focus['events'], 0, 10)));
                }
            }
        }

        $this->newLine();
        $this->info('  ────────────────────────────────────────────────────────────');
        $this->comment('  Use --json for machine-readable output');
        $this->comment('  Use --category=saas to focus on a specific category');
        $this->comment('  Use --provider, --flow, --alerts, --queue, --identity for details');
    }
}
