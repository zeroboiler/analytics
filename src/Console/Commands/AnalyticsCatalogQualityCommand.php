<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventNamingConventionLinter;
use ZeroBoiler\Analytics\Services\EventSemanticClassifierService;

/**
 * Event Catalog Quality & Naming Convention Linter.
 *
 * Analyzes the analytics event catalog for:
 * - Semantic classification quality (how well events are categorized)
 * - Naming convention violations (format, pattern, reserved words)
 * - Uncategorized or misnamed events
 * - Quality score and grade
 *
 * Options:
 *   --classify     Show semantic classification for all events
 *   --lint         Show naming convention violations
 *   --event=NAME  Classify/lint a single specific event
 *   --suggest      Show improvement suggestions
 *   --json         Output as JSON
 *
 * @since 222.0.0
 */
final class AnalyticsCatalogQualityCommand extends Command
{
    protected $signature = 'zb:analytics:catalog-quality
        {--classify : Show semantic classification for all catalog events}
        {--lint : Show naming convention violations}
        {--event= : Classify or lint a specific event}
        {--suggest : Show improvement suggestions for problematic events}
        {--json : Output as machine-readable JSON}';

    protected $description = 'Analyze event catalog quality — classification, naming conventions, and suggestions';

    /**
     * Execute the catalog quality command.
     */
    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
        $singleEvent = $this->option('event');
        $showClassify = (bool) $this->option('classify');
        $showLint = (bool) $this->option('lint');
        $showSuggestions = (bool) $this->option('suggest');

        // Default: show everything if no specific option is selected
        $showAll = ! $showClassify && ! $showLint && ! $showSuggestions;

        $classifier = new EventSemanticClassifierService(
            cache(),
            $this->config(),
        );

        $linter = new EventNamingConventionLinter(
            cache(),
            $this->config(),
        );

        // Single event mode
        if (is_string($singleEvent) && $singleEvent !== '') {
            return $this->handleSingleEvent($singleEvent, $classifier, $linter, $showClassify, $showLint, $showSuggestions, $outputJson);
        }

        if ($outputJson) {
            $this->line(json_encode($this->buildJsonReport($classifier, $linter, $showClassify, $showLint, $showSuggestions, $showAll), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('🔍 ZeroBoiler Analytics — Catalog Quality Report');
        $this->line('   Version: ' . AnalyticsEvent::VERSION);
        $this->newLine();

        // Classification report
        if ($showClassify || $showAll) {
            $this->showClassificationReport($classifier);
        }

        // Lint report
        if ($showLint || $showAll) {
            $this->showLintReport($linter);
        }

        // Suggestions
        if ($showSuggestions || $showAll) {
            $this->showSuggestions($classifier, $linter);
        }

        if (! $outputJson) {
            $this->newLine();
            $this->comment('Use --classify, --lint, --suggest for specific sections, or --event=NAME for a single event.');
        }

        return self::SUCCESS;
    }

    /**
     * Handle a single event classification/lint.
     *
     * @param  EventSemanticClassifierService  $classifier
     * @param  EventNamingConventionLinter  $linter
     * @param  bool  $showClassify
     * @param  bool  $showLint
     * @param  bool  $showSuggestions
     * @param  bool  $outputJson
     * @return int
     */
    private function handleSingleEvent(
        string $eventName,
        EventSemanticClassifierService $classifier,
        EventNamingConventionLinter $linter,
        bool $showClassify,
        bool $showLint,
        bool $showSuggestions,
        bool $outputJson,
    ): int {
        $result = [
            'event' => $eventName,
        ];

        if ($showClassify || (! $showLint && ! $showSuggestions)) {
            $classification = $classifier->classify($eventName);
            $result['classification'] = $classification;
            $suggestions = $classifier->suggestCategory($eventName);
            $result['category_suggestions'] = $suggestions;
            $alias = $classifier->resolveAlias($eventName);
            $result['alias'] = $alias;
        }

        if ($showLint || (! $showClassify && ! $showSuggestions)) {
            $violations = $linter->lint($eventName);
            $result['lint_violations'] = $violations;
            $nameSuggestions = $linter->suggestName($eventName);
            $result['name_suggestions'] = $nameSuggestions;
        }

        if ($showSuggestions) {
            $classification = $classifier->classify($eventName);
            $categorySuggestions = $classifier->suggestCategory($eventName);
            $nameSuggestions = $linter->suggestName($eventName);
            $result['suggestions'] = [
                'category' => $categorySuggestions,
                'name' => $nameSuggestions,
                'alias' => $classifier->resolveAlias($eventName),
            ];
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Show classification report in console.
     *
     * @param  EventSemanticClassifierService  $classifier
     */
    private function showClassificationReport(EventSemanticClassifierService $classifier): void
    {
        $report = $classifier->classificationReport();

        $this->info('📊 Semantic Classification');
        $this->line('   Total events: <info>' . $report['total_events'] . '</info>');
        $this->line('   Classified: <info>' . $report['classified'] . '</info>');
        $this->line('   Uncategorized: ' . ($report['uncategorized'] > 0 ? '<fg=red>' . $report['uncategorized'] . '</>' : '<info>0</>'));
        $this->line('   Average confidence: <info>' . ($report['average_confidence'] * 100) . '%</info>');
        $this->line('   Quality score: <info>' . ($report['quality_score'] * 100) . '%</info>');
        $this->newLine();

        // Category breakdown
        $this->line('   By category:');
        foreach ($report['by_category'] as $category => $count) {
            $pct = $report['total_events'] > 0 ? round(($count / $report['total_events']) * 100, 1) : 0;
            $this->line("     {$category}: <info>{$count}</info> ({$pct}%)");
        }

        $this->newLine();

        // Method breakdown
        $this->line('   By classification method:');
        foreach ($report['by_method'] as $method => $count) {
            $this->line("     {$method}: <info>{$count}</info>");
        }

        // Low confidence events
        if ($report['low_confidence'] !== []) {
            $this->newLine();
            $this->warn('   ⚠️  Low confidence events (' . count($report['low_confidence']) . '):');
            foreach (array_slice($report['low_confidence'], 0, 10) as $event) {
                $this->line("      • {$event}");
            }

            if (count($report['low_confidence']) > 10) {
                $this->line('      ... and ' . (count($report['low_confidence']) - 10) . ' more');
            }
        }

        // Overlap events
        if ($report['overlap_events'] !== []) {
            $this->newLine();
            $this->line('   🔄 Overlap events (competing categories):');
            foreach (array_slice($report['overlap_events'], 0, 10) as $event) {
                $this->line("      • {$event}");
            }
        }

        $this->newLine();
    }

    /**
     * Show lint report in console.
     *
     * @param  EventNamingConventionLinter  $linter
     */
    private function showLintReport(EventNamingConventionLinter $linter): void
    {
        $report = $linter->lintReport();

        $this->info('✏️  Naming Convention Lint');
        $this->line('   Total violations: ' . ($report['total_violations'] > 0 ? '<fg=red>' . $report['total_violations'] . '</>' : '<info>0</>'));
        $this->line('   Quality grade: <info>' . $report['quality_grade'] . '</info>');
        $this->line('   Quality score: <info>' . ($report['quality_score'] * 100) . '%</info>');
        $this->newLine();

        // Severity breakdown
        $sev = $report['violations_by_severity'];
        $this->line('   By severity:');
        $this->line('     Errors: ' . ($sev['error'] > 0 ? '<fg=red>' . $sev['error'] . '</>' : '<info>0</>'));
        $this->line('     Warnings: ' . ($sev['warning'] > 0 ? '<fg=yellow>' . $sev['warning'] . '</>' : '<info>0</>'));
        $this->line('     Info: <info>' . $sev['info'] . '</>');
        $this->newLine();

        // Rule breakdown
        if ($report['violations_by_rule'] !== []) {
            $this->line('   By rule:');
            foreach ($report['violations_by_rule'] as $rule => $count) {
                $this->line("     {$rule}: <info>{$count}</info>");
            }
            $this->newLine();
        }

        // Error events
        if ($report['error_events'] !== []) {
            $this->warn('   ❌ Events with errors:');
            foreach (array_slice($report['error_events'], 0, 10) as $event) {
                $this->line("      • {$event}");
            }

            if (count($report['error_events']) > 10) {
                $this->line('      ... and ' . (count($report['error_events']) - 10) . ' more');
            }
            $this->newLine();
        }

        // Top violations
        if ($report['violations'] !== []) {
            $this->line('   Top violations:');
            $count = 0;

            foreach ($report['violations'] as $event => $violations) {
                if ($count >= 15) {
                    break;
                }

                foreach ($violations as $v) {
                    if ($count >= 15) {
                        break;
                    }

                    $icon = match ($v['severity']) {
                        'error' => '❌',
                        'warning' => '⚠️ ',
                        default => 'ℹ️ ',
                    };

                    $this->line("      {$icon} <comment>{$event}</comment>: {$v['message']}");

                    if ($v['suggestion'] !== null) {
                        $this->line('         → Suggestion: <info>' . $v['suggestion'] . '</>');
                    }

                    $count++;
                }
            }

            $this->newLine();
        }
    }

    /**
     * Show suggestions for problematic events.
     *
     * @param  EventSemanticClassifierService  $classifier
     * @param  EventNamingConventionLinter  $linter
     */
    private function showSuggestions(EventSemanticClassifierService $classifier, EventNamingConventionLinter $linter): void
    {
        $report = $classifier->classificationReport();

        $this->info('💡 Suggestions');
        $suggestionCount = 0;

        // Suggest aliases for misnamed events
        if ($report['misnamed'] !== []) {
            $this->line('   Potential misnamed events (alias detected):');
            foreach (array_slice($report['misnamed'], 0, 10) as $misnamed) {
                $this->line("      • <comment>{$misnamed['event']}</comment> → suggest <info>{$misnamed['suggested']}</info>");
                $suggestionCount++;
            }
        }

        // Suggest categories for uncategorized events
        if ($report['uncategorized'] !== []) {
            $this->newLine();
            $this->line('   Uncategorized events:');
            foreach (array_slice($report['uncategorized'], 0, 10) as $event) {
                $categorySuggestions = $classifier->suggestCategory($event);
                $topSuggestion = $categorySuggestions[0] ?? null;

                if ($topSuggestion !== null) {
                    $cat = $topSuggestion['category'];
                    $conf = $topSuggestion['confidence'] * 100;
                    $this->line("      • <comment>{$event}</comment> → might be <info>{$cat}</info> ({$conf}%)");
                    $this->line('        Reason: ' . $topSuggestion['reason']);
                } else {
                    $this->line("      • <comment>{$event}</comment> → no category suggestion available");
                }

                $suggestionCount++;
            }
        }

        if ($suggestionCount === 0) {
            $this->line('   No suggestions — catalog looks clean! ✨');
        }

        $this->newLine();
    }

    /**
     * Build the full JSON report.
     *
     * @param  EventSemanticClassifierService  $classifier
     * @param  EventNamingConventionLinter  $linter
     * @param  bool  $showClassify
     * @param  bool  $showLint
     * @param  bool  $showSuggestions
     * @param  bool  $showAll
     * @return array<string, mixed>
     */
    private function buildJsonReport(
        EventSemanticClassifierService $classifier,
        EventNamingConventionLinter $linter,
        bool $showClassify,
        bool $showLint,
        bool $showSuggestions,
        bool $showAll,
    ): array {
        $report = [
            'version' => AnalyticsEvent::VERSION,
        ];

        if ($showClassify || $showAll) {
            $report['classification'] = $classifier->classificationReport();
        }

        if ($showLint || $showAll) {
            $lintReport = $linter->lintReport();
            $report['lint'] = $lintReport;
            // Remove full violations for JSON (too verbose)
            unset($report['lint']['violations']);
        }

        if ($showSuggestions || $showAll) {
            $classReport = $classifier->classificationReport();
            $report['suggestions'] = [
                'misnamed' => $classReport['misnamed'],
                'uncategorized_count' => count($classReport['uncategorized']),
            ];
        }

        return $report;
    }
}
