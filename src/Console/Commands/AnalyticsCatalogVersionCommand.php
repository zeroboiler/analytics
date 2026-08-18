<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\CatalogVersionRecommendation;
use ZeroBoiler\Analytics\Services\CatalogSnapshotService;
use ZeroBoiler\Analytics\Services\EventCatalogVersioningEngine;
use ZeroBoiler\Analytics\Services\ReleaseChangelogGeneratorService;

/**
 * Analyzes event catalog changes and generates version recommendations + changelogs.
 *
 * Provides CI/CD integration for automated catalog versioning decisions.
 * Compares current catalog against a baseline snapshot and outputs
 * a SemVer recommendation with optional changelog in multiple formats.
 *
 * @see \ZeroBoiler\Analytics\Services\EventCatalogVersioningEngine
 * @see \ZeroBoiler\Analytics\Services\ReleaseChangelogGeneratorService
 *
 * @since 216.0.0
 */
final class AnalyticsCatalogVersionCommand extends Command
{
    protected $signature = 'zb:analytics:catalog-version
        {--baseline= : Baseline snapshot label to compare against}
        {--capture : Capture current catalog as new baseline}
        {--format=markdown : Changelog output format (markdown|json|compact|conventional)}
        {--json : Output recommendation as JSON}
        {--severity : Show only severity summary (no changelog)}
        {--stats : Show catalog statistics}
        {--history : Show versioning history}';

    protected $description = 'Analyze catalog changes and generate version recommendations';

    private EventCatalogVersioningEngine $versioningEngine;

    private ReleaseChangelogGeneratorService $changelogGenerator;

    private CatalogSnapshotService $snapshotService;

    /**
     * @param  EventCatalogVersioningEngine  $versioningEngine
     * @param  ReleaseChangelogGeneratorService  $changelogGenerator
     * @param  CatalogSnapshotService  $snapshotService
     */
    public function __construct(
        EventCatalogVersioningEngine $versioningEngine,
        ReleaseChangelogGeneratorService $changelogGenerator,
        CatalogSnapshotService $snapshotService,
    ): void {
        parent::__construct();
        $this->versioningEngine = $versioningEngine;
        $this->changelogGenerator = $changelogGenerator;
        $this->snapshotService = $snapshotService;
    }

    #[\Override]
    public function handle(): int
    {
        // --stats — show catalog statistics
        if ($this->option('stats')) {
            $this->showStats();

            return self::SUCCESS;
        }

        // --history — show versioning history
        if ($this->option('history')) {
            $this->showHistory();

            return self::SUCCESS;
        }

        // --capture — capture current catalog as baseline
        if ($this->option('capture')) {
            $this->captureBaseline();

            return self::SUCCESS;
        }

        // --severity — quick severity summary
        if ($this->option('severity')) {
            $this->showSeveritySummary();

            return self::SUCCESS;
        }

        // Default: full analysis
        $this->showFullAnalysis();

        return self::SUCCESS;
    }

    /**
     * Display full analysis with version recommendation and changelog.
     */
    private function showFullAnalysis(): void
    {
        $baselineLabel = $this->option('baseline');
        $format = (string) $this->option('format');
        $outputJson = (bool) $this->option('json');

        $recommendation = $this->versioningEngine->analyzeAgainstBaseline(
            is_string($baselineLabel) && $baselineLabel !== '' ? $baselineLabel : null,
        );

        if ($outputJson) {
            $this->line(json_encode($recommendation->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return;
        }

        // Version recommendation summary
        $this->components->info("Catalog Version Analysis: {$recommendation->currentVersion} → {$recommendation->nextVersion}");
        $this->newLine();

        $this->line("  Recommended bump: <info>{$recommendation->recommended}</info>");
        $this->line("  Breaking changes:  {$this->formatBool($recommendation->hasBreaking)}");
        $this->line("  Major changes:     {$recommendation->summary['major']}");
        $this->line("  Minor changes:     {$recommendation->summary['minor']}");
        $this->line("  Patch changes:     {$recommendation->summary['patch']}");
        $this->line("  Total changes:     " . array_sum($recommendation->summary));
        $this->newLine();

        // Rationale
        $this->components->twoColumnDetail('Rationale', $recommendation->rationale);
        $this->newLine();

        // Changelog
        $changelog = $this->changelogGenerator->generate($recommendation, $format);
        $this->components->task('Generated changelog', true);

        if ($format === 'compact') {
            $this->line($changelog);
        } else {
            $this->newLine();
            $this->line($changelog);
        }
    }

    /**
     * Display quick severity summary.
     */
    private function showSeveritySummary(): void
    {
        $baselineLabel = $this->option('baseline');
        $baseline = $this->snapshotService->getSnapshot(
            is_string($baselineLabel) && $baselineLabel !== '' ? $baselineLabel : 'baseline',
        );

        if ($baseline === null) {
            $this->components->warn('No baseline snapshot found. Run with --capture first.');

            return;
        }

        $current = $this->snapshotService->capture(null);
        $diff = $this->snapshotService->diff($baseline, $current);
        $summary = $this->versioningEngine->quickSeveritySummary($diff);

        $this->components->info('Catalog Change Severity Summary');
        $this->newLine();

        $this->line("  Major (breaking):  {$summary['major']}");
        $this->line("  Minor (feature):   {$summary['minor']}");
        $this->line("  Patch (non-break): {$summary['patch']}");
        $this->line("  Total:             {$summary['total']}");
        $this->newLine();

        $status = $summary['has_breaking'] ? '<error>BREAKING</error>' : '<info>CLEAN</info>';
        $this->line("  Status: {$status}");

        if ((bool) $this->option('json')) {
            $this->newLine();
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Capture current catalog as a new baseline snapshot.
     */
    private function captureBaseline(): void
    {
        $label = 'baseline_' . AnalyticsEvent::VERSION;
        $snapshot = $this->snapshotService->capture($label);

        $this->components->info("Captured baseline snapshot: {$label}");
        $this->line("  Version:   {$snapshot['version']}");
        $this->line("  Events:    {$snapshot['total_events']}");
        $this->line("  Timestamp: {$snapshot['timestamp']}");
        $this->line("  Categories: " . count($snapshot['categories']));
    }

    /**
     * Show catalog statistics.
     */
    private function showStats(): void
    {
        $stats = $this->changelogGenerator->catalogStats();

        $this->components->info('Event Catalog Statistics');
        $this->newLine();

        $this->line("  Version:      <info>{$stats['version']}</info>");
        $this->line("  Total Events: {$stats['total_events']}");
        $this->newLine();

        // Category breakdown
        $this->line('  Categories:');
        foreach ($stats['categories'] as $cat => $count) {
            $this->line("    {$cat}: {$count}");
        }
        $this->newLine();

        // Provider coverage
        $this->line('  Provider Coverage:');
        foreach ($stats['provider_coverage'] as $provider => $count) {
            $pct = $stats['total_events'] > 0
                ? round(($count / $stats['total_events']) * 100, 1)
                : 0.0;
            $this->line("    {$provider}: {$count} events ({$pct}%)");
        }

        if ((bool) $this->option('json')) {
            $this->newLine();
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Show versioning history.
     */
    private function showHistory(): void
    {
        $history = $this->versioningEngine->getHistory();

        if ($history === []) {
            $this->components->warn('No versioning history found.');

            return;
        }

        $this->components->info('Versioning History');
        $this->newLine();

        foreach ($history as $rec) {
            $breaking = $rec->hasBreaking ? ' ⚠' : '';
            $this->line("  {$rec->currentVersion} → {$rec->nextVersion} ({$rec->recommended}){$breaking}");
            $this->line("    " . array_sum($rec->summary) . ' changes: '
                . "{$rec->summary['major']} major, {$rec->summary['minor']} minor, {$rec->summary['patch']} patch");
        }

        if ((bool) $this->option('json')) {
            $this->newLine();
            $this->line(json_encode(
                array_map(fn(CatalogVersionRecommendation $r) => $r->toArray(), $history),
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));
        }
    }

    /**
     * Format a boolean for CLI output.
     */
    private function formatBool(bool $value): string
    {
        return $value ? '<error>YES</error>' : '<info>no</info>';
    }
}
