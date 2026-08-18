<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\IdentityResolutionService;
use ZeroBoiler\Analytics\Services\EventCorrelationService;

/**
 * Admin command: analytics:snapshot
 *
 * Generates an instant analytics context snapshot and prints a summary
 * of the current analytics configuration, event catalog coverage,
 * identity resolution state, and journey reconstruction stats.
 *
 * Useful for debugging, health checks, and operational dashboards.
 *
 * @since 8.5.0
 */
final class AnalyticsSnapshotCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:snapshot
        {--identity= : Specific user/client ID to snapshot}
        {--format=table : Output format (table, json)}
        {--include-catalog : Include full event catalog in output}';

    /** @var string */
    protected $description = 'Generate an instant analytics context snapshot and system summary';

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(
        ConfigRepository $config,
        EventCatalog $catalog,
        IdentityResolutionService $identity,
        EventCorrelationService $correlation,
    ): int {
        $this->info('╔══════════════════════════════════════════════════╗');
        $this->info('║  ZeroBoiler Analytics — Context Snapshot Report    ║');
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->newLine();

        // ── 1. Configuration Overview ────────────────────────────
        $this->sectionHeader('1. Configuration Overview');
        $this->printConfigOverview($config);

        // ── 2. Event Catalog Coverage ────────────────────────────
        $this->sectionHeader('2. Event Catalog Coverage');
        $this->printCatalogCoverage($catalog);

        // ── 3. Provider Status ──────────────────────────────────
        $this->sectionHeader('3. Provider Status');
        $this->printProviderStatus($config);

        // ── 4. Identity Resolution ──────────────────────────────
        $this->sectionHeader('4. Identity Resolution');
        $this->printIdentityStatus($identity);

        // ── 5. Correlation & Pattern Detection ──────────────────
        $this->sectionHeader('5. Correlation & Pattern Detection');
        $this->printCorrelationStatus($correlation);

        // ── 6. Context Snapshot (if specific identity provided) ─
        $identityId = $this->option('identity');
        if ($identityId !== null) {
            $this->sectionHeader('6. Identity Snapshot: ' . $identityId);
            $this->printIdentitySnapshot($identity, $identityId);
        }

        // ── 7. Optional: Full Catalog ────────────────────────────
        if ($this->option('include-catalog')) {
            $this->sectionHeader('7. Full Event Catalog');
            $this->printFullCatalog($catalog);
        }

        $this->newLine();
        $this->info('Snapshot complete.');

        return self::SUCCESS;
    }

    /**
     * Print a section header.
     *
     * @param  string  $title
     */
    private function sectionHeader(string $title): void
    {
        $this->newLine();
        $this->info("── {$title} " . str_repeat('─', max(1, 60 - strlen($title))));
    }

    /**
     * Print configuration overview.
     *
     * @param  ConfigRepository  $config
     */
    private function printConfigOverview(ConfigRepository $config): void
    {
        $analytics = $config->get('zeroboiler.analytics', []);

        $rows = [
            ['GA4 Enabled', $this->boolStr($analytics['ga4']['enabled'] ?? false)],
            ['GA4 Measurement ID', $this->masked($analytics['ga4']['measurement_id'] ?? '')],
            ['GTM Enabled', $this->boolStr($analytics['gtm']['enabled'] ?? false)],
            ['GTM Container ID', $this->masked($analytics['gtm']['container_id'] ?? '')],
            ['Meta Pixel Enabled', $this->boolStr($analytics['meta_pixel']['enabled'] ?? false)],
            ['Meta Pixel ID', $this->masked($analytics['meta_pixel']['id'] ?? '')],
            ['Plausible Enabled', $this->boolStr($analytics['plausible']['enabled'] ?? false)],
            ['PostHog Enabled', $this->boolStr($analytics['posthog']['enabled'] ?? false)],
            ['Queue Enabled', $this->boolStr($analytics['queue']['enabled'] ?? true)],
            ['Queue Name', $analytics['queue']['queue'] ?? 'analytics'],
            ['Consent Default', $analytics['consent']['default'] ?? 'granted'],
            ['Debug Mode', $this->boolStr($analytics['debug']['enabled'] ?? false)],
            ['Validation Strict', $this->boolStr($analytics['validation']['strict'] ?? false)],
            ['GDPR IP Anonymize', $this->boolStr($analytics['gdpr']['anonymize_ip'] ?? false)],
            ['Lifecycle Mapper', $this->boolStr($analytics['lifecycle']['enabled'] ?? true)],
            ['Sessionizer', $this->boolStr($analytics['sessionizer']['enabled'] ?? true)],
            ['Fingerprint', $this->boolStr($analytics['fingerprint']['enabled'] ?? true)],
            ['Data Mart', $this->boolStr($analytics['data_mart']['enabled'] ?? true)],
            ['Insight Engine', $this->boolStr($analytics['insight_engine']['enabled'] ?? true)],
        ];

        $this->table(['Setting', 'Value'], $rows);
    }

    /**
     * Print event catalog coverage.
     *
     * @param  EventCatalog  $catalog
     */
    private function printCatalogCoverage(EventCatalog $catalog): void
    {
        $all = $catalog->all();
        $byCategory = $catalog->byCategory();

        $rows = [
            ['Total Events', (string) $catalog->count()],
            ['Ecommerce Events', (string) count($byCategory['ecommerce'])],
            ['SaaS Events', (string) count($byCategory['saas'])],
            ['Engagement Events', (string) count($byCategory['engagement'])],
            ['GA4 Mappings', (string) count($catalog->allGa4Names())],
            ['Meta Mappings', (string) count($catalog->allMetaNames())],
            ['PostHog Mappings', (string) count($catalog->allPosthogNames())],
            ['Plausible Mappings', (string) count($catalog->allPlausibleNames())],
        ];

        $this->table(['Metric', 'Count'], $rows);

        // Validation check
        $validation = $catalog->validate();
        if ($validation['valid']) {
            $this->info('✓ Catalog validation passed (no errors)');
        } else {
            $this->error('✗ Catalog validation failed:');
            foreach ($validation['errors'] as $error) {
                $this->line("  - {$error}");
            }
        }
    }

    /**
     * Print provider status.
     *
     * @param  ConfigRepository  $config
     */
    private function printProviderStatus(ConfigRepository $config): void
    {
        $analytics = $config->get('zeroboiler.analytics', []);

        $providers = [];

        if ($analytics['ga4']['enabled'] ?? false) {
            $providers[] = ['GA4', 'active', $analytics['ga4']['measurement_id'] ? 'configured' : 'missing ID'];
        } else {
            $providers[] = ['GA4', 'disabled', '—'];
        }

        if ($analytics['gtm']['enabled'] ?? false) {
            $providers[] = ['GTM', 'active', $analytics['gtm']['container_id'] ? 'configured' : 'missing ID'];
        } else {
            $providers[] = ['GTM', 'disabled', '—'];
        }

        if ($analytics['meta_pixel']['enabled'] ?? false) {
            $providers[] = ['Meta Pixel', 'active', $analytics['meta_pixel']['id'] ? 'configured' : 'missing ID'];
        } else {
            $providers[] = ['Meta Pixel', 'disabled', '—'];
        }

        if ($analytics['plausible']['enabled'] ?? false) {
            $providers[] = ['Plausible', 'active', $analytics['plausible']['domain'] ? 'configured' : 'missing domain'];
        } else {
            $providers[] = ['Plausible', 'disabled', '—'];
        }

        if ($analytics['posthog']['enabled'] ?? false) {
            $providers[] = ['PostHog', 'active', $analytics['posthog']['api_key'] ? 'configured' : 'missing API key'];
        } else {
            $providers[] = ['PostHog', 'disabled', '—'];
        }

        $this->table(['Provider', 'Status', 'Notes'], $providers);

        $activeCount = count(array_filter($providers, fn (array $p): bool => $p[1] === 'active'));
        $this->line("Active providers: {$activeCount}/" . count($providers));
    }

    /**
     * Print identity resolution status.
     *
     * @param  IdentityResolutionService  $identity
     */
    private function printIdentityStatus(IdentityResolutionService $identity): void
    {
        $stats = $identity->stats();

        $rows = [
            ['Resolved Users', (string) $stats['total_resolved_users']],
            ['Linked Clients', (string) $stats['total_linked_clients']],
        ];

        $this->table(['Metric', 'Value'], $rows);
    }

    /**
     * Print correlation status.
     *
     * @param  EventCorrelationService  $correlation
     */
    private function printCorrelationStatus(EventCorrelationService $correlation): void
    {
        $summary = $correlation->summary();

        $rows = [
            ['Total Events Tracked', (string) $summary['total_events']],
            ['Unique Events', (string) $summary['unique_events']],
            ['Total Transitions', (string) $summary['total_transitions']],
            ['Unique Users', (string) $summary['unique_users']],
            ['Avg Journey Length', (string) $summary['avg_journey_length']],
            ['Detected Patterns', (string) $summary['detected_patterns']],
        ];

        $this->table(['Metric', 'Value'], $rows);

        // Top transitions
        $topTransitions = $summary['top_transitions'] ?? [];
        if ($topTransitions !== []) {
            $this->line('Top Transitions:');
            foreach (array_slice($topTransitions, 0, 5) as $t) {
                $this->line("  {$t['from']} → {$t['to']} ({$t['count']}x, {$t['probability']}%)");
            }
        }
    }

    /**
     * Print identity-specific snapshot.
     *
     * @param  IdentityResolutionService  $identity
     * @param  string  $identityId
     */
    private function printIdentitySnapshot(IdentityResolutionService $identity, string $identityId): void
    {
        $summary = $identity->identitySummary($identityId);
        $clientIds = $identity->getClientIdsForUser($identityId);

        $rows = [
            ['User ID', $summary['user_id']],
            ['Linked Clients', (string) $summary['linked_clients']],
            ['Primary Client', $summary['primary_client_id'] ?? '—'],
            ['First Linked', $summary['first_linked'] ?? '—'],
        ];

        $this->table(['Field', 'Value'], $rows);

        if ($clientIds !== []) {
            $this->line('All linked client IDs: ' . implode(', ', $clientIds));
        }
    }

    /**
     * Print full event catalog.
     *
     * @param  EventCatalog  $catalog
     */
    private function printFullCatalog(EventCatalog $catalog): void
    {
        $rows = [];

        foreach ($catalog->byCategory() as $category => $events) {
            foreach ($events as $name => $entry) {
                $rows[] = [
                    $category,
                    $name,
                    $entry['ga4'] ?? '',
                    $entry['meta'] ?? '—',
                    $entry['posthog'] ?? '—',
                    $entry['plausible'] ?? '—',
                ];
            }
        }

        if ($rows !== []) {
            $this->table(['Category', 'Event', 'GA4', 'Meta', 'PostHog', 'Plausible'], $rows);
        } else {
            $this->warn('No events in catalog.');
        }
    }

    /**
     * Format boolean as styled string.
     *
     * @param  bool  $value
     * @return string
     */
    private function boolStr(bool $value): string
    {
        return $value ? '<info>enabled</info>' : '<comment>disabled</comment>';
    }

    /**
     * Mask a sensitive value for display.
     *
     * @param  string  $value
     * @return string
     */
    private function masked(string $value): string
    {
        if ($value === '' || $value === '0') {
            return '<comment>not set</comment>';
        }

        if (strlen($value) <= 8) {
            return '••••••••';
        }

        return substr($value, 0, 4) . '••••' . substr($value, -4);
    }
}
