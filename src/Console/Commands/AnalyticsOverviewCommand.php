<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaSStarterEvents;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Services\SaaSStarterInstrumentationService;
/**
 * Displays a comprehensive overview of the analytics configuration,
 * enabled providers, event catalog statistics, and system health.
 *
 * Provides a single-command health check for operators to quickly
 * verify analytics pipeline status, catalog coverage, and provider readiness.
 *
 * @since 1.0.0
 */
final class AnalyticsOverviewCommand extends Command
{
    protected $signature = 'zb:analytics:overview
        {--json : Output as JSON}
        {--providers : Show detailed provider status}
        {--catalog : Show event catalog summary}
        {--health : Show system health indicators}
        {--starter : Show SaaS Starter Events instrumentation coverage}
        {--snippets= : Show instrumentation snippet for a specific event}';

    protected $description = 'Display comprehensive analytics pipeline overview';

    private AnalyticsManager $manager;

    public function __construct(AnalyticsManager $manager)
    {
        parent::__construct();
        $this->manager = $manager;
    }

    #[Override]
    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
        $snippetEvent = $this->option('snippets');

        // --snippets=<event> — show code snippets for a specific event
        if (is_string($snippetEvent) && $snippetEvent !== '') {
            $this->showSnippets($snippetEvent, $outputJson);

            return self::SUCCESS;
        }

        // --starter — show SaaS Starter Events instrumentation coverage
        if ((bool) $this->option('starter')) {
            $this->showStarterCoverage($outputJson);

            return self::SUCCESS;
        }

        $overview = $this->buildOverview();

        if ($outputJson) {
            $this->line(json_encode($overview, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderOverview($overview);

        return self::SUCCESS;
    }

    /**
     * Build the full overview data structure.
     *
     * @return array<string, mixed>
     */
    private function buildOverview(): array
    {
        /** @var ConfigRepository $config */
        $config = app(ConfigRepository::class);

        return [
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'providers' => $this->getProviderStatus(),
            'catalog' => $this->getCatalogStats(),
            'consent' => $this->manager->getConsent()->toArray(),
            'enabled_count' => $this->countEnabledProviders(),
            'total_providers' => 10,
            'lifecycle' => $this->getLifecycleStats($config),
            'queue' => $this->getQueueStats($config),
            'api' => $this->getApiStats($config),
            'auto_track' => $this->getAutoTrackStats($config),
            'saas_kpi' => $this->getSaasKpiStats($config),
            'identity' => $this->getIdentityStats($config),
            'event_costs' => $this->getEventCostStats($config),
        ];
    }

    /**
     * Get status information for all configured providers.
     *
     * @return array<string, array{enabled: bool, configured: bool, id?: string}>
     */
    private function getProviderStatus(): array
    {
        return [
            'ga4' => [
                'enabled' => $this->manager->ga4()->isEnabled(),
                'configured' => $this->manager->ga4()->getMeasurementId() !== '',
                'id' => $this->manager->ga4()->getMeasurementId() ?: null,
            ],
            'gtm' => [
                'enabled' => $this->manager->gtm()->isEnabled(),
                'configured' => $this->manager->gtm()->getContainerId() !== '',
                'id' => $this->manager->gtm()->getContainerId() ?: null,
            ],
            'meta_pixel' => [
                'enabled' => $this->manager->meta()->isEnabled(),
                'configured' => $this->manager->meta()->getPixelId() !== '',
                'id' => $this->manager->meta()->getPixelId() ?: null,
            ],
            'plausible' => [
                'enabled' => $this->manager->plausible()->isEnabled(),
                'configured' => $this->manager->plausible()->getDomain() !== '',
                'id' => $this->manager->plausible()->getDomain() ?: null,
            ],
            'posthog' => [
                'enabled' => $this->manager->posthog()->isEnabled(),
                'configured' => $this->manager->posthog()->getHost() !== '',
                'id' => $this->manager->posthog()->getHost() ?: null,
            ],
            'mixpanel' => [
                'enabled' => $this->manager->mixpanel()->isEnabled(),
                'configured' => $this->manager->mixpanel()->getToken() !== '',
            ],
            'amplitude' => [
                'enabled' => $this->manager->amplitude()->isEnabled(),
                'configured' => $this->manager->amplitude()->getApiKey() !== '',
            ],
            'webhook' => [
                'enabled' => $this->manager->webhook()->isEnabled(),
                'configured' => true,
            ],
            'tiktok' => [
                'enabled' => $this->manager->tiktok()->isEnabled(),
                'configured' => $this->manager->tiktok()->getPixelId() !== '',
                'id' => $this->manager->tiktok()->getPixelId() ?: null,
            ],
            'linkedin' => [
                'enabled' => $this->manager->linkedin()->isEnabled(),
                'configured' => $this->manager->linkedin()->getPartnerId() !== '',
                'id' => $this->manager->linkedin()->getPartnerId() ?: null,
            ],
        ];
    }

    /**
     * Get event catalog statistics.
     *
     * @return array{total: int, by_category: array<string, int>, providers: array<string, int>}
     */
    private function getCatalogStats(): array
    {
        $byCategory = EventCatalog::byCategory();

        $categoryCounts = [];
        foreach ($byCategory as $category => $events) {
            $categoryCounts[$category] = count($events);
        }

        return [
            'total' => EventCatalog::count(),
            'by_category' => $categoryCounts,
            'providers' => [
                'ga4' => count(EventCatalog::allGa4Names()),
                'meta' => count(EventCatalog::allMetaNames()),
                'posthog' => count(EventCatalog::allPosthogNames()),
                'plausible' => count(EventCatalog::allPlausibleNames()),
                'mixpanel' => count(EventCatalog::allMixpanelNames()),
                'amplitude' => count(EventCatalog::allAmplitudeNames()),
                'tiktok' => count(EventCatalog::allTikTokNames()),
                'linkedin' => count(EventCatalog::allLinkedInNames()),
            ],
        ];
    }

    /**
     * Get lifecycle event mapping statistics.
     *
     * @return array{enabled: bool, built_in_count: int, custom_count: int, queue_events: bool}
     */
    private function getLifecycleStats(ConfigRepository $config): array
    {
        $lifecycleConfig = $config->get('zeroboiler.analytics.lifecycle', []);
        /** @var array{enabled?: bool, queue_events?: bool, custom_mappings?: array<string, string>} $lifecycleConfig */
        $customMappings = $lifecycleConfig['custom_mappings'] ?? [];

        return [
            'enabled' => $lifecycleConfig['enabled'] ?? true,
            'built_in_count' => LifecycleEventMapper::DEFAULT_MAPPING_COUNT,
            'custom_count' => count($customMappings),
            'queue_events' => $lifecycleConfig['queue_events'] ?? false,
        ];
    }

    /**
     * Get queue configuration statistics.
     *
     * @return array{enabled: bool, queue: string, connection: string|null, max_batch_size: int}
     */
    private function getQueueStats(ConfigRepository $config): array
    {
        $queueConfig = $config->get('zeroboiler.analytics.queue', []);
        /** @var array{enabled?: bool, queue?: string, connection?: string|null, max_batch_size?: int} $queueConfig */

        return [
            'enabled' => $queueConfig['enabled'] ?? true,
            'queue' => $queueConfig['queue'] ?? 'analytics',
            'connection' => $queueConfig['connection'] ?? null,
            'max_batch_size' => $queueConfig['max_batch_size'] ?? 50,
        ];
    }

    /**
     * Get API configuration statistics.
     *
     * @return array{enabled: bool, base_url: string, rate_limit: int, sdk_token_configured: bool, batch_max_size: int}
     */
    private function getApiStats(ConfigRepository $config): array
    {
        $apiConfig = $config->get('zeroboiler.analytics.api', []);
        /** @var array{enabled?: bool, base_url?: string, rate_limit?: int, sdk_token?: string|null, batch_max_size?: int} $apiConfig */
        $sdkToken = $apiConfig['sdk_token'] ?? null;

        return [
            'enabled' => $apiConfig['enabled'] ?? true,
            'base_url' => $apiConfig['base_url'] ?? '/api/analytics',
            'rate_limit' => $apiConfig['rate_limit'] ?? 120,
            'sdk_token_configured' => is_string($sdkToken) && $sdkToken !== '',
            'batch_max_size' => $apiConfig['batch_max_size'] ?? 25,
        ];
    }

    /**
     * Get client-side auto-tracking configuration.
     *
     * @return array{page_views: bool, scroll_depth: bool, form_tracking: bool, error_tracking: bool, session_tracking: bool}
     */
    private function getAutoTrackStats(ConfigRepository $config): array
    {
        $autoTrackConfig = $config->get('zeroboiler.analytics.client_auto_track', []);

        return [
            'page_views' => $autoTrackConfig['page_views'] ?? true,
            'scroll_depth' => $autoTrackConfig['scroll_depth'] ?? true,
            'form_tracking' => $autoTrackConfig['form_tracking'] ?? true,
            'error_tracking' => $autoTrackConfig['error_tracking'] ?? true,
            'session_tracking' => $autoTrackConfig['session_tracking'] ?? true,
        ];
    }

    /**
     * Count the number of enabled analytics providers.
     */
    private function countEnabledProviders(): int
    {
        $count = 0;
        $providers = $this->getProviderStatus();

        foreach ($providers as $provider) {
            if ($provider['enabled']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get SaaS KPI configuration statistics.
     *
     * @return array{enabled: bool, cache_ttl: int, mrr_goal: float, churn_warning: float, ltv_cac_target: float, quick_ratio_target: float, rule_of_40_target: float, tiers_count: int}
     */
    private function getSaasKpiStats(ConfigRepository $config): array
    {
        $kpiConfig = $config->get('zeroboiler.analytics.saas_kpi_calc', []);
        /** @var array{enabled?: bool, cache_ttl?: int, mrr_goal?: float, churn_warning?: float, ltv_cac_target?: float, quick_ratio_target?: float, rule_of_40_target?: float} $kpiConfig */
        $revenueConfig = $config->get('zeroboiler.analytics.revenue', []);
        /** @var array{subscription_tiers?: array<string, mixed>} $revenueConfig */
        $tiers = $revenueConfig['subscription_tiers'] ?? [];

        return [
            'enabled' => (bool) ($kpiConfig['enabled'] ?? true),
            'cache_ttl' => (int) ($kpiConfig['cache_ttl'] ?? 300),
            'mrr_goal' => (float) ($kpiConfig['mrr_goal'] ?? 10000),
            'churn_warning' => (float) ($kpiConfig['churn_warning'] ?? 0.05),
            'ltv_cac_target' => (float) ($kpiConfig['ltv_cac_target'] ?? 3.0),
            'quick_ratio_target' => (float) ($kpiConfig['quick_ratio_target'] ?? 4.0),
            'rule_of_40_target' => (float) ($kpiConfig['rule_of_40_target'] ?? 40.0),
            'tiers_count' => count($tiers),
        ];
    }

    /**
     * Get identity tracking configuration statistics.
     *
     * @return array{cookie_name: string, cookie_ttl: int, link_on_auth: bool, auto_link: bool, cache_prefix: string, link_ttl: int}
     */
    private function getIdentityStats(ConfigRepository $config): array
    {
        $identityConfig = $config->get('zeroboiler.analytics.identity', []);
        /** @var array{cookie_name?: string, cookie_ttl?: int, link_on_auth?: bool, auto_link?: bool, cache_prefix?: string, link_ttl?: int} $identityConfig */

        return [
            'cookie_name' => (string) ($identityConfig['cookie_name'] ?? 'zb_analytics_id'),
            'cookie_ttl' => (int) ($identityConfig['cookie_ttl'] ?? 525600),
            'link_on_auth' => (bool) ($identityConfig['link_on_auth'] ?? true),
            'auto_link' => (bool) ($identityConfig['auto_link'] ?? true),
            'cache_prefix' => (string) ($identityConfig['cache_prefix'] ?? 'zb_identity_'),
            'link_ttl' => (int) ($identityConfig['link_ttl'] ?? 7776000),
        ];
    }

    /**
     * Get event cost estimation configuration.
     *
     * @return array{budget_threshold?: float, currency: string}
     */
    private function getEventCostStats(ConfigRepository $config): array
    {
        $costConfig = $config->get('zeroboiler.analytics.event_costs', []);
        /** @var array{budget_threshold?: float, currency?: string} $costConfig */

        return [
            'budget_threshold' => (float) ($costConfig['budget_threshold'] ?? 0.0),
            'currency' => (string) ($costConfig['currency'] ?? 'USD'),
        ];
    }

    /**
     * Render the overview to the console.
     *
     * @param  array<string, mixed>  $overview
     */
    private function renderOverview(array $overview): void
    {
        $this->info('📊 ZeroBoiler Analytics Overview');
        $this->line('   Version: <info>'.$overview['version'].'</info>');
        $this->line('   Providers: <info>'.$overview['enabled_count'].'</info>/<fg=cyan>'.$overview['total_providers'].'</> enabled');
        $this->line('   Events: <info>'.$overview['catalog']['total'].'</info> in catalog');
        $this->newLine();

        // Provider status table
        $this->table(
            ['Provider', 'Status', 'ID'],
            [
                ['GA4', $this->formatStatus($overview['providers']['ga4']['enabled']), $overview['providers']['ga4']['id'] ?? '—'],
                ['GTM', $this->formatStatus($overview['providers']['gtm']['enabled']), $overview['providers']['gtm']['id'] ?? '—'],
                ['Meta Pixel', $this->formatStatus($overview['providers']['meta_pixel']['enabled']), $overview['providers']['meta_pixel']['id'] ?? '—'],
                ['Plausible', $this->formatStatus($overview['providers']['plausible']['enabled']), $overview['providers']['plausible']['id'] ?? '—'],
                ['PostHog', $this->formatStatus($overview['providers']['posthog']['enabled']), $overview['providers']['posthog']['id'] ?? '—'],
                ['Mixpanel', $this->formatStatus($overview['providers']['mixpanel']['enabled']), '—'],
                ['Amplitude', $this->formatStatus($overview['providers']['amplitude']['enabled']), '—'],
                ['Webhook', $this->formatStatus($overview['providers']['webhook']['enabled']), '—'],
                ['TikTok', $this->formatStatus($overview['providers']['tiktok']['enabled']), $overview['providers']['tiktok']['id'] ?? '—'],
                ['LinkedIn', $this->formatStatus($overview['providers']['linkedin']['enabled']), $overview['providers']['linkedin']['id'] ?? '—'],
            ],
        );

        // Catalog breakdown
        if ((bool) $this->option('catalog')) {
            $this->newLine();
            $this->info('📋 Event Catalog');
            foreach ($overview['catalog']['by_category'] as $category => $count) {
                $this->line("   {$category}: <info>{$count}</info> events");
            }
            $this->newLine();
            $this->line('   Provider Coverage:');
            foreach ($overview['catalog']['providers'] as $provider => $count) {
                $this->line("   {$provider}: <info>{$count}</info> mappings");
            }
        }

        // Consent state
        $this->newLine();
        $this->info('🔒 Consent State');
        foreach ($overview['consent'] as $signal => $state) {
            $icon = $state === 'granted' ? '✅' : '🚫';
            $this->line('   ' . $icon . ' ' . $signal . ': <' . ($state === 'granted' ? 'info' : 'comment') . '>' . $state . '</>');
        }

        // Lifecycle & Infrastructure summary
        $this->newLine();
        $this->info('🔄 Lifecycle & Infrastructure');
        $lifecycle = $overview['lifecycle'];
        $this->line('   Lifecycle tracking: <info>'.($lifecycle['enabled'] ? 'ON' : 'OFF').'</info>');
        $this->line('   Built-in mappings: <info>'.$lifecycle['built_in_count'].'</info>');
        $this->line('   Custom mappings: <info>'.$lifecycle['custom_count'].'</info>');
        $this->line('   Lifecycle queue: <info>'.($lifecycle['queue_events'] ? 'ASYNC' : 'SYNC').'</info>');
        $queue = $overview['queue'];
        $this->line('   Event queue: <info>'.($queue['enabled'] ? 'ON' : 'OFF').'</info> ('.$queue['queue'].($queue['connection'] ? ', '.$queue['connection'].' connection' : '').', max '.$queue['max_batch_size'].')');
        $api = $overview['api'];
        $this->line('   API endpoint: <info>'.($api['enabled'] ? 'ENABLED' : 'DISABLED').'</info> ('.$api['base_url'].', rate limit '.$api['rate_limit'].'/min)');
        $this->line('   SDK token: <info>'.($api['sdk_token_configured'] ? 'CONFIGURED' : 'NOT SET').'</info>');
        $autoTrack = $overview['auto_track'];
        $this->line('   Auto-track: <info>'.implode(', ', array_filter(array_keys($autoTrack), fn (string $k): bool => $autoTrack[$k])).'</info>');

        // SaaS KPI configuration
        $this->newLine();
        $this->info('📈 SaaS KPI Configuration');
        $saasKpi = $overview['saas_kpi'];
        $this->line('   SaaS KPI calc: <info>'.($saasKpi['enabled'] ? 'ON' : 'OFF').'</info> (cache TTL: '.$saasKpi['cache_ttl'].'s)');
        $this->line('   MRR goal: <info>$'.number_format($saasKpi['mrr_goal'], 0).'</info>');
        $this->line('   Churn warning: <info>'.($saasKpi['churn_warning'] * 100).'%</info>');
        $this->line('   LTV/CAC target: <info>'.$saasKpi['ltv_cac_target'].'x</info>');
        $this->line('   Quick Ratio target: <info>'.$saasKpi['quick_ratio_target'].'</info>');
        $this->line('   Rule of 40 target: <info>'.$saasKpi['rule_of_40_target'].'%</info>');
        $this->line('   Subscription tiers: <info>'.$saasKpi['tiers_count'].'</info>');

        // Identity configuration
        $this->newLine();
        $this->info('🔗 Identity Configuration');
        $identity = $overview['identity'];
        $this->line('   Cookie: <info>'.$identity['cookie_name'].'</info> (TTL: '.$identity['cookie_ttl'].' min)');
        $this->line('   Link on auth: <info>'.($identity['link_on_auth'] ? 'YES' : 'NO').'</info>');
        $this->line('   Auto-link: <info>'.($identity['auto_link'] ? 'YES' : 'NO').'</info>');
        $this->line('   Cache prefix: <info>'.$identity['cache_prefix'].'</info> (link TTL: '.$identity['link_ttl'].'s)');

        // Event cost configuration
        $costs = $overview['event_costs'];
        if ($costs['budget_threshold'] > 0) {
            $this->newLine();
            $this->info('💰 Event Cost Budget');
            $this->line('   Budget threshold: <info>'.$costs['currency'].' '.number_format($costs['budget_threshold'], 2).'</info>');
        }

        $this->newLine();
        $this->comment('Use --providers, --catalog, or --health for detailed output.');
        $this->comment('Use --json for machine-readable output.');
    }

    /**
     * Format a boolean enabled status for console display.
     */
    private function formatStatus(bool $enabled): string
    {
        return $enabled
            ? '<fg=green>ENABLED</>'
            : '<fg=yellow>DISABLED</>';
    }

    /**
     * Show instrumentation code snippets for a specific starter event.
     *
     * @param  string  $eventName  Event name (e.g. 'sign_up', 'purchase')
     * @param  bool  $asJson  Output as JSON
     */
    private function showSnippets(string $eventName, bool $asJson): void
    {
        // Try resolving from the starter set
        $resolved = SaaSStarterEvents::isStarterEvent($eventName)
            ? $eventName
            : (SaaSStarterEvents::isStarterEvent(EventCatalog::resolve($eventName) ?? '')
                ? (EventCatalog::resolve($eventName) ?? '')
                : null);

        if ($resolved === null) {
            $this->error("Event '{$eventName}' is not in the SaaS Starter Events set.");

            $this->line('');
            $this->info('Available events:');
            foreach (SaaSStarterEvents::priorityOrder() as $name) {
                $entry = SaaSStarterEvents::all()[$name];
                $this->line("   <info>{$name}</info> — {$entry['label']}");
            }

            return;
        }

        $snippets = SaaSStarterInstrumentationService::snippetsFor($resolved);
        $entry = SaaSStarterEvents::all()[$resolved];
        $catalogEntry = EventCatalog::get($resolved);

        if ($asJson) {
            $this->line(json_encode([
                'event' => $resolved,
                'label' => $entry['label'],
                'category' => $entry['category'],
                'hint' => $entry['hint'],
                'ga4' => $catalogEntry['ga4'] ?? $resolved,
                'meta' => $catalogEntry['meta'] ?? null,
                'params' => $snippets['params'] ?? [],
                'snippets' => [
                    'php' => $snippets['php'] ?? '',
                    'js' => $snippets['js'] ?? '',
                    'blade' => $snippets['blade'] ?? '',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return;
        }

        $this->info("📝 {$entry['label']} ({$resolved})");
        $this->line("   Category: <comment>{$entry['category']}</comment>");
        $this->line("   Hint: {$entry['hint']}");
        $this->line("   GA4: <info>" . ($catalogEntry['ga4'] ?? $resolved) . '</info>');
        if (($catalogEntry['meta'] ?? null) !== null) {
            $this->line("   Meta: <info>{$catalogEntry['meta']}</info>");
        }
        $this->newLine();

        // Parameters
        $this->info('📦 Parameters:');
        foreach ($snippets['params'] as $param) {
            $req = $param['required'] ? '<fg=red>required</>' : '<comment>optional</>';
            $this->line("   {$req} \${$param['name']} ({$param['type']}) — {$param['description']}");
        }
        $this->newLine();

        // PHP snippet
        $this->info('🐍 PHP (Server-side):');
        $this->line('<comment>' . trim($snippets['php']) . '</comment>');
        $this->newLine();

        // JS snippet
        $this->info('⚡ JavaScript (Client-side):');
        $this->line('<comment>' . trim($snippets['js']) . '</comment>');
        $this->newLine();

        // Blade snippet
        $this->info('📦 Blade Template:');
        $this->line('<comment>' . trim($snippets['blade']) . '</comment>');
    }

    /**
     * Show SaaS Starter Events instrumentation coverage report.
     *
     * @param  bool  $asJson  Output as JSON
     */
    private function showStarterCoverage(bool $asJson): void
    {
        $coverage = SaaSStarterInstrumentationService::coverageAnalysis();
        $completeness = SaaSStarterInstrumentationService::completenessScore();
        $byCategory = SaaSStarterEvents::byCategory();
        $catalogPresence = SaaSStarterEvents::catalogPresence();

        if ($asJson) {
            $this->line(json_encode([
                'starter_events' => SaaSStarterEvents::count(),
                'catalog_coverage' => SaaSStarterEvents::coveragePercent(),
                'auto_tracking_coverage' => $coverage['coverage'],
                'auto_tracked_events' => $coverage['auto_tracked'],
                'manual_events' => $coverage['manual'],
                'completeness' => $completeness,
                'by_category' => array_map(fn (array $events): int => count($events), $byCategory),
                'catalog_presence' => $catalogPresence,
                'priority_order' => SaaSStarterEvents::priorityOrder(),
                'client_guide' => SaaSStarterInstrumentationService::clientGuide(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return;
        }

        $this->info('🎯 SaaS Starter Events — Instrumentation Coverage');
        $this->line('   Total events: <info>' . SaaSStarterEvents::count() . '</info>');
        $this->line('   Catalog coverage: <info>' . SaaSStarterEvents::coveragePercent() . '%</info>');
        $this->line('   Auto-tracking: <info>' . $coverage['coverage'] . '%</info> (' . count($coverage['auto_tracked']) . '/' . SaaSStarterEvents::count() . ')');
        $this->line('   Completeness: <info>' . $completeness['score'] . '/' . $completeness['max'] . '</info>');

        // Category breakdown
        $this->newLine();
        $this->info('📂 By Category:');
        foreach ($byCategory as $category => $events) {
            $count = count($events);
            $icon = match ($category) {
                'saas' => '🔄',
                'ecommerce' => '🛒',
                'engagement' => '📊',
                default => '📋',
            };
            $this->line("   {$icon} {$category}: <info>{$count}</info> events");
        }

        // Auto-tracked events
        $this->newLine();
        $this->info('🤖 Auto-Tracked (no manual code needed):');
        foreach ($coverage['auto_tracked'] as $name) {
            $entry = SaaSStarterEvents::all()[$name];
            $this->line("   ✅ {$entry['label']} (<comment>{$name}</comment>)");
        }

        // Manual events in priority order
        $this->newLine();
        $this->info('✏️  Manual Instrumentation (priority order):');
        foreach ($coverage['manual'] as $name) {
            $entry = SaaSStarterEvents::all()[$name];
            $inCatalog = $catalogPresence[$name] ?? false;
            $status = $inCatalog ? '✅' : '⚠️';
            $this->line("   {$status} {$entry['label']} (<comment>{$name}</comment>) — {$entry['hint']}");
        }

        // Completeness details
        $this->newLine();
        $incomplete = array_filter($completeness['details'], fn (bool $v): bool => ! $v);
        if (count($incomplete) > 0) {
            $this->warn('⚠️  Incomplete instrumentation entries:');
            foreach ($incomplete as $name => $_) {
                $this->line("   - {$name}");
            }
        } else {
            $this->info('✅ All 20 starter events have complete instrumentation entries.');
        }

        $this->newLine();
        $this->comment('Use --snippets=<event_name> to see code snippets for a specific event.');
        $this->comment('Use --json for machine-readable output.');
    }
}
