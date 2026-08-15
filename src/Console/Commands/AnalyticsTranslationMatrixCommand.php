<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventFieldRegistry;
use ZeroBoiler\Analytics\Services\CrossProviderTranslationMatrix;
use ZeroBoiler\Analytics\Services\RevenueHealthScoreService;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

/**
 * Analytics Translation Matrix Command.
 *
 * Displays the full cross-provider event translation matrix,
 * identifies mapping gaps, and reports provider coverage statistics.
 *
 * Useful for auditing event catalog completeness and ensuring
 * consistent event naming across all analytics providers.
 *
 * @since 133.0.0
 */
final class AnalyticsTranslationMatrixCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:translation-matrix
        {--event= : Show translation for a specific event}
        {--provider= : Show gaps for a specific provider}
        {--gaps : Show only events with missing mappings}
        {--json : Output as JSON}
    ';

    /** @var string */
    protected $description = 'Display cross-provider event translation matrix and coverage';

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(
        CrossProviderTranslationMatrix $matrix,
        RevenueHealthScoreService $revenueHealth,
    ): int
    {
        $eventName = $this->option('event');
        $provider = $this->option('provider');
        $showGapsOnly = $this->option('gaps');
        $asJson = $this->option('json');

        if ($eventName !== null) {
            return $this->showSingleEventTranslation($matrix, (string) $eventName, $asJson);
        }

        if ($provider !== null) {
            return $this->showProviderGaps($matrix, (string) $provider, $asJson);
        }

        if ($showGapsOnly) {
            return $this->showAllGaps($matrix, $asJson);
        }

        return $this->showFullMatrix($matrix, $revenueHealth, $asJson);
    }

    /**
     * Show translation map for a single event.
     */
    private function showSingleEventTranslation(CrossProviderTranslationMatrix $matrix, string $eventName, bool $asJson): int
    {
        $entry = EventCatalog::resolveAndGet($eventName);

        if ($entry === null) {
            $this->error("Event '{$eventName}' not found in catalog.");

            return self::FAILURE;
        }

        $map = $matrix->fullTranslationMap($eventName);
        $coverage = $matrix->coverageFor($eventName);

        if ($asJson) {
            $this->line(json_encode([
                'event' => $eventName,
                'category' => $entry['category'],
                'translations' => $map,
                'coverage' => $coverage,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("Event: {$eventName} ({$entry['category']})");
        $this->newLine();

        $this->table(
            ['Provider', 'Event Name', 'Status'],
            array_map(
                fn (string $provider): array => [
                    $provider,
                    $map[$provider] ?? '—',
                    ($map[$provider] !== null) ? '<info>✓ Mapped</info>' : '<comment>— Unmapped</comment>',
                ],
                $matrix->providers(),
            ),
        );

        $this->newLine();
        $this->info("Coverage: {$coverage['mapped']}/{$coverage['total']} providers ({$coverage['coverage']}%)");

        return self::SUCCESS;
    }

    /**
     * Show gaps for a specific provider.
     */
    private function showProviderGaps(CrossProviderTranslationMatrix $matrix, string $provider, bool $asJson): int
    {
        if (! $matrix->isProviderSupported($provider)) {
            $this->error("Provider '{$provider}' is not supported.");
            $this->line('Supported providers: ' . implode(', ', $matrix->providers()));

            return self::FAILURE;
        }

        $gaps = $matrix->mappingGaps();
        $unmapped = $gaps[$provider] ?? [];

        if ($asJson) {
            $this->line(json_encode([
                'provider' => $provider,
                'unmapped_count' => count($unmapped),
                'unmapped_events' => $unmapped,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $total = EventCatalog::count();
        $mapped = $total - count($unmapped);
        $pct = $total > 0 ? round(($mapped / $total) * 100, 1) : 0.0;

        $this->info("Provider: {$provider}");
        $this->info("Coverage: {$mapped}/{$total} events ({$pct}%)");
        $this->newLine();

        if (empty($unmapped)) {
            $this->info('<info>All events are mapped! No gaps found.</info>');

            return self::SUCCESS;
        }

        $this->warn(count($unmapped) . ' events missing mapping:');
        $this->table(
            ['#', 'Event Name', 'Category'],
            array_map(
                fn (string $name, int $i): array => [
                    $i + 1,
                    $name,
                    EventCatalog::getCategory($name) ?? 'unknown',
                ],
                $unmapped,
                array_keys($unmapped),
            ),
        );

        return self::SUCCESS;
    }

    /**
     * Show all mapping gaps across providers.
     */
    private function showAllGaps(CrossProviderTranslationMatrix $matrix, bool $asJson): int
    {
        $gaps = $matrix->mappingGaps();
        $collisions = $matrix->providerCollisions();

        if ($asJson) {
            $this->line(json_encode([
                'gaps' => $gaps,
                'collisions' => $collisions,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Cross-Provider Mapping Gaps');
        $this->newLine();

        foreach ($gaps as $provider => $events) {
            $count = count($events);

            if ($count === 0) {
                $this->line("  {$provider}: <info>All mapped ✓</info>");
            } else {
                $this->line("  {$provider}: <comment>{$count} unmapped</comment>");
            }
        }

        if (! empty($collisions)) {
            $this->newLine();
            $this->warn('Provider event name collisions (ambiguous reverse lookup):');

            foreach ($collisions as $collision) {
                $this->line("  {$collision['provider']}: '{$collision['event_name']}' → [" .
                    implode(', ', $collision['canonical_names']) . ']');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Show the full translation matrix summary.
     */
    private function showFullMatrix(
        CrossProviderTranslationMatrix $matrix,
        RevenueHealthScoreService $revenueHealth,
        bool $asJson,
    ): int
    {
        $health = $revenueHealth->computeFresh();

        if ($asJson) {
            $this->line(json_encode([
                'catalog_summary' => EventCatalog::summary(),
                'translation_matrix' => $matrix->matrixTable(),
                'revenue_health' => $health,
                'provider_collisions' => $matrix->providerCollisions(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        // Revenue health score
        $this->info('Revenue Health Score: ' . $health['score'] . '/100 (Grade: ' . $health['grade'] . ')');
        $this->newLine();

        foreach ($health['dimensions'] as $dim => $data) {
            $label = str_replace('_', ' ', $dim);
            $status = $data['status'] === 'healthy'
                ? '<info>✓</info>'
                : ($data['status'] === 'warning' ? '<comment>⚠</comment>' : '<error>✗</error>');

            $this->line("  {$status} {$label}: {$data['score']}% — {$data['details']}");
        }

        if (! empty($health['gaps'])) {
            $this->newLine();
            $this->warn('Gaps:');
            foreach ($health['gaps'] as $gap) {
                $this->line("  - {$gap}");
            }
        }

        if (! empty($health['recommendations'])) {
            $this->newLine();
            $this->info('Recommendations:');
            foreach ($health['recommendations'] as $rec) {
                $this->line("  → {$rec}");
            }
        }

        // Catalog summary
        $summary = EventCatalog::summary();
        $this->newLine();
        $this->info('Catalog Summary: ' . $summary['total'] . ' events across 8 categories');

        $this->table(
            ['Category', 'Events', 'GA4', 'Meta', 'PostHog', 'Plausible'],
            [
                ['E-commerce', $summary['ecommerce'], $summary['ecommerce'], '', '', ''],
                ['SaaS', $summary['saas'], '', '', '', ''],
                ['Engagement', $summary['engagement'], '', '', '', ''],
                ['Security', $summary['security'] ?? 0, '', '', '', ''],
                ['Uptime', $summary['uptime'] ?? 0, '', '', '', ''],
                ['Infrastructure', $summary['infrastructure'] ?? 0, '', '', '', ''],
                ['Marketing', $summary['marketing'] ?? 0, '', '', '', ''],
            ],
        );

        // Provider coverage
        $this->newLine();
        $this->info('Provider Coverage:');
        $coverage = EventCatalog::providerCoverage()['counts'];

        foreach ($coverage as $provider => $count) {
            $pct = $summary['total'] > 0 ? round(($count / $summary['total']) * 100, 1) : 0.0;
            $this->line("  {$provider}: {$count}/{$summary['total']} ({$pct}%)");
        }

        // Event field schema stats
        $this->newLine();
        $fieldEvents = EventFieldRegistry::eventNames();
        $totalFields = 0;
        foreach ($fieldEvents as $fe) {
            $totalFields += count(EventFieldRegistry::forEvent($fe));
        }
        $this->info("Event Field Schemas: {$totalFields} field definitions across " . count($fieldEvents) . ' events');

        return self::SUCCESS;
    }
}
