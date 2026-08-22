<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\CatalogSnapshotService;
use ZeroBoiler\Analytics\Services\EventGovernanceRuntimeValidator;

/**
 * Event governance runtime validation command.
 *
 * Validates the event catalog against governance rules, optionally
 * captures a snapshot, and computes diffs against baseline snapshots.
 *
 * Usage:
 *   php artisan zb:analytics:governance-validate
 *   php artisan zb:analytics:governance-validate --snapshot=v160
 *   php artisan zb:analytics:governance-validate --diff=v159 --baseline=v158
 *   php artisan zb:analytics:governance-validate --health
 *
 * @see \ZeroBoiler\Analytics\Services\EventGovernanceRuntimeValidator
 * @see \ZeroBoiler\Analytics\Services\CatalogSnapshotService
 *
 * @since 160.0.0
 */
final class AnalyticsGovernanceValidateCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:governance-validate
        {--snapshot= : Capture a catalog snapshot with this label}
        {--diff= : Compute diff from this snapshot label to current state}
        {--baseline= : Baseline snapshot label for diff (used with --diff)}
        {--health : Run catalog governance health check}
        {--sample : Validate sample events from each category}
        {--provider-gaps : List events with provider mapping gaps}';

    /** @var string */
    protected $description = 'Validate event catalog governance rules and compute snapshot diffs';

    public function handle(
        EventGovernanceRuntimeValidator $validator,
        ?CatalogSnapshotService $snapshotService = null,
    ): int {
        $this->info('╔══════════════════════════════════════════╗');
        $this->info('║  Event Governance Runtime Validator v160  ║');
        $this->info('╚══════════════════════════════════════════╝');

        // 1. Snapshot capture
        if ($this->option('snapshot') !== null) {
            return $this->captureSnapshot($snapshotService);
        }

        // 2. Snapshot diff
        if ($this->option('diff') !== null) {
            return $this->computeDiff($snapshotService);
        }

        // 3. Catalog health check
        if ($this->option('health')) {
            return $this->healthCheck($validator);
        }

        // 4. Provider gaps report
        if ($this->option('provider-gaps')) {
            return $this->providerGapsReport();
        }

        // 5. Default: run validation with sample events
        if ($this->option('sample')) {
            return $this->validateSamples($validator);
        }

        // 6. Default: show catalog summary
        return $this->showSummary();
    }

    /**
     * Capture a catalog snapshot.
     */
    private function captureSnapshot(?CatalogSnapshotService $service): int
    {
        if ($service === null) {
            $this->error('CatalogSnapshotService requires a cache repository. Skipping snapshot.');
            $this->warn('Tip: Ensure cache is configured in your Laravel application.');

            return self::FAILURE;
        }

        $label = (string) $this->option('snapshot');
        $snapshot = $service->capture($label);

        $this->info("✅ Snapshot captured: {$snapshot['label']}");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Version', $snapshot['version']],
                ['Timestamp', $snapshot['timestamp']],
                ['Total Events', (string) $snapshot['total_events']],
                ['Categories', $this->formatAssocList($snapshot['categories'])],
                ['Provider Coverage', $this->formatAssocList($snapshot['provider_coverage'])],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Compute a diff between two snapshots.
     */
    private function computeDiff(?CatalogSnapshotService $service): int
    {
        if ($service === null) {
            $this->error('CatalogSnapshotService requires a cache repository.');
            return self::FAILURE;
        }

        $currentLabel = (string) $this->option('diff');
        $baselineLabel = $this->option('baseline') !== null
            ? (string) $this->option('baseline')
            : null;

        $current = $service->getSnapshot($currentLabel);
        if ($current === null) {
            $this->error("Snapshot '{$currentLabel}' not found in cache.");
            $this->warn('Run --snapshot={$currentLabel} first to capture it.');

            return self::FAILURE;
        }

        if ($baselineLabel !== null) {
            $baseline = $service->getSnapshot($baselineLabel);
            if ($baseline === null) {
                $this->error("Baseline snapshot '{$baselineLabel}' not found in cache.");
                return self::FAILURE;
            }
        } else {
            // Use current live catalog as baseline
            $baseline = $service->capture('live_baseline_' . time());
        }

        $diff = $service->diff($baseline, $current);

        $this->info('📊 Catalog Diff: ' . (baselineLabel ?? 'live') . ' → {$currentLabel}');
        $this->newLine();

        // Summary
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Changes', (string) $diff['summary']['total_changes']],
                ['Breaking Changes', (string) $diff['summary']['breaking']],
                ['Non-Breaking', (string) $diff['summary']['non_breaking']],
                ['Stability Score', (string) $diff['summary']['score']],
            ],
        );

        // Added events
        if ($diff['added'] !== []) {
            $this->warn('Added Events (' . count($diff['added']) . '):');
            foreach (array_slice($diff['added'], 0, 20) as $name) {
                $this->line("  + {$name}");
            }
        }

        // Removed events (breaking!)
        if ($diff['removed'] !== []) {
            $this->error('Removed Events — BREAKING (' . count($diff['removed']) . '):');
            foreach (array_slice($diff['removed'], 0, 20) as $name) {
                $this->line("  - {$name}");
            }
        }

        // Category changes
        if ($diff['category_changed'] !== []) {
            $this->warn('Category Changes (' . count($diff['category_changed']) . '):');
            foreach ($diff['category_changed'] as $change) {
                $this->line("  ~ {$change['event']}: {$change['from']} → {$change['to']}");
            }
        }

        return $diff['summary']['breaking'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Run catalog governance health check.
     */
    private function healthCheck(EventGovernanceRuntimeValidator $validator): int
    {
        $health = $validator->catalogGovernanceHealth();

        $this->info('🏥 Catalog Governance Health Check');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Events', (string) $health['total']],
                ['Valid', (string) $health['valid']],
                ['Incomplete', (string) $health['incomplete']],
                ['Health Score', $health['total'] > 0
                    ? round(($health['valid'] / $health['total']) * 100, 1) . '%'
                    : 'N/A'],
            ],
        );

        if ($health['issues'] !== []) {
            $this->warn("Issues found: {$health['incomplete']}");
            foreach (array_slice($health['issues'], 0, 20) as $issue) {
                $this->line("  ⚠ {$issue['event']}: {$issue['issue']}");
            }
        } else {
            $this->info('✅ All catalog entries pass governance validation.');
        }

        return $health['incomplete'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Show provider mapping gaps.
     */
    private function providerGapsReport(): int
    {
        $catalog = EventCatalog::all();
        $gaps = [];
        $providers = ['ga4', 'meta', 'posthog'];

        foreach ($catalog as $name => $entry) {
            $missing = [];
            foreach ($providers as $p) {
                $val = $entry[$p] ?? null;
                if ($val === null || $val === '') {
                    $missing[] = $p;
                }
            }
            if ($missing !== []) {
                $gaps[$name] = ['category' => $entry['category'] ?? 'unknown', 'missing' => $missing];
            }
        }

        $this->info("🔍 Provider Gap Report (checking: {$providers[0]}, {$providers[1]}, {$providers[2]})");
        $this->table(
            ['Event', 'Category', 'Missing Providers'],
            array_map(
                fn (string $name, array $data): array => [$name, $data['category'], implode(', ', $data['missing'])],
                array_keys($gaps),
                array_values($gaps),
            ),
        );

        $this->info("Total events with gaps: " . count($gaps) . ' / ' . count($catalog));

        return self::SUCCESS;
    }

    /**
     * Validate sample events from each category.
     */
    private function validateSamples(EventGovernanceRuntimeValidator $validator): int
    {
        $validator->setRequiredGlobalParams([]);

        $sampleEvents = [
            new AnalyticsEvent(name: 'page_view', params: ['title' => 'Test'], category: 'engagement'),
            new AnalyticsEvent(name: 'purchase', params: ['transaction_id' => 'tx_123', 'value' => 99.99], category: 'ecommerce'),
            new AnalyticsEvent(name: 'sign_up', params: ['method' => 'email'], category: 'saas'),
            new AnalyticsEvent(name: 'login', params: ['user_id' => 'u_1'], category: 'saas'),
            new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 75], category: 'engagement'),
            new AnalyticsEvent(name: 'add_to_cart', params: ['item_id' => 'p_1', 'price' => 29.99, 'quantity' => 1], category: 'ecommerce'),
            new AnalyticsEvent(name: 'plan_upgrade', params: ['from_plan' => 'starter', 'to_plan' => 'pro'], category: 'saas'),
            new AnalyticsEvent(name: 'search', params: ['search_term' => 'analytics'], category: 'engagement'),
            new AnalyticsEvent(name: 'form_submit', params: ['form_name' => 'contact'], category: 'engagement'),
            new AnalyticsEvent(name: 'refund', params: ['transaction_id' => 'tx_123', 'value' => 49.99], category: 'ecommerce'),
        ];

        $results = $validator->validateBatch($sampleEvents);

        $this->info('🧪 Sample Event Validation');
        $this->table(
            ['Event', 'Valid', 'Warnings', 'Provider Gaps'],
            array_map(
                fn (array $r): array => [
                    $r['event'],
                    $r['valid'] ? '✅' : '❌',
                    $r['warnings'] === [] ? '—' : implode('; ', array_slice($r['warnings'], 0, 2)),
                    $r['provider_gaps'] === [] ? '—' : implode(', ', $r['provider_gaps']),
                ],
                $results['results'],
            ),
        );

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total', (string) $results['total']],
                ['Valid', (string) $results['valid']],
                ['Invalid', (string) $results['invalid']],
                ['Total Warnings', (string) $results['warnings_total']],
                ['Provider Gap Total', (string) $results['provider_gap_total']],
            ],
        );

        return $results['invalid'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Format an associative array as "key: val, key: val" string.
     *
     * @param  array<string, mixed>  $items
     */
    private function formatAssocList(array $items): string
    {
        $parts = [];

        foreach ($items as $key => $value) {
            $parts[] = "{$key}: {$value}";
        }

        return implode(', ', $parts);
    }

    /**
     * Show catalog summary.
     */
    private function showSummary(): int
    {
        $byCategory = EventCatalog::byCategory();

        $this->info('📋 Event Catalog Summary');
        $this->table(
            ['Category', 'Events'],
            array_map(
                fn (string $cat, array $events): array => [$cat, (string) count($events)],
                array_keys($byCategory),
                array_values($byCategory),
            ),
        );

        $this->newLine();
        $this->info("Total events: " . EventCatalog::count());
        $this->info("Total categories: " . count($byCategory));

        return self::SUCCESS;
    }
}
