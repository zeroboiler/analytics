<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;

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
        {--health : Show system health indicators}';

    protected $description = 'Display comprehensive analytics pipeline overview';

    private AnalyticsManager $manager;

    public function __construct(AnalyticsManager $manager): void
    {
        parent::__construct();
        $this->manager = $manager;
    }

    #[\Override]
    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
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
        return [
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'providers' => $this->getProviderStatus(),
            'catalog' => $this->getCatalogStats(),
            'consent' => $this->manager->getConsent()->toArray(),
            'enabled_count' => $this->countEnabledProviders(),
            'total_providers' => 10,
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
            ],
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
            $this->line("   {$icon} {$signal}: <{$state === 'granted' ? 'info' : 'comment'}>{$state}</>");
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
}
