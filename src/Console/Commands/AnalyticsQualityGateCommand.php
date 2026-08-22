<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Services\EventCatalogDiffService;
use ZeroBoiler\Analytics\Services\EventSchemaRuntimeValidator;
use ZeroBoiler\Analytics\Services\EventComplianceScoringService;

/**
 * CI/CD quality gate command for analytics event catalog.
 *
 * Runs a comprehensive production readiness check:
 * 1. Schema validation coverage (catalog events with/without schemas)
 * 2. Catalog snapshot comparison (added/removed/renamed since last deploy)
 * 3. Compliance scoring (GDPR consent, PII handling, data retention)
 * 4. Provider coverage (events mapped to at least one provider)
 * 5. Deduplication checks (duplicate GA4/Meta event names)
 *
 * Designed to run in CI/CD pipelines with `--fail-level` exit codes.
 *
 * @since 243.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\EventCatalogDiffService
 * @see \ZeroBoiler\Analytics\Services\EventSchemaRuntimeValidator
 * @see \ZeroBoiler\Analytics\Services\EventComplianceScoringService
 */
final class AnalyticsQualityGateCommand extends Command
{
    protected $signature = 'zb:analytics:quality-gate
        {--json : Output as JSON}
        {--fail-level=warning : Fail on error|warning|none}
        {--snapshot : Take a snapshot before running checks}
        {--check=schema : Run specific check (schema|diff|compliance|coverage|dedup|all)}
        {--min-coverage=80 : Minimum schema coverage % (0-100)}
        {--min-compliance=70 : Minimum compliance score (0-100)}';

    protected $description = 'Run CI/CD quality gate checks on the analytics event catalog';

    private EventCatalogDiffService $diffService;

    private ?EventSchemaRuntimeValidator $schemaValidator;

    private ?EventSchemaRegistry $schemaRegistry;

    private ?EventComplianceScoringService $complianceService;

    /**
     * @param  EventCatalogDiffService  $diffService
     * @param  EventSchemaRuntimeValidator|null  $schemaValidator  Nullable for headless environments
     * @param  EventSchemaRegistry|null  $schemaRegistry  Nullable for headless environments
     * @param  EventComplianceScoringService|null  $complianceService  Nullable for headless environments
     */
    public function __construct(
        EventCatalogDiffService $diffService,
        ?EventSchemaRuntimeValidator $schemaValidator = null,
        ?EventSchemaRegistry $schemaRegistry = null,
        ?EventComplianceScoringService $complianceService = null,
    ){
        parent::__construct();
        $this->diffService = $diffService;
        $this->schemaValidator = $schemaValidator;
        $this->schemaRegistry = $schemaRegistry;
        $this->complianceService = $complianceService;
    }

    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
        $failLevel = (string) $this->option('fail-level');
        $minCoverage = (int) $this->option('min-coverage');
        $minCompliance = (int) $this->option('min-compliance');
        $check = (string) $this->option('check');

        // --snapshot: take baseline before running checks
        if ((bool) $this->option('snapshot')) {
            $result = $this->diffService->takeSnapshot();
            if ($outputJson) {
                $this->line(json_encode(['snapshot' => $result], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                $this->info("Snapshot saved: {$result['event_count']} events across {$result['categories']} categories.");
            }
        }

        $results = $this->runChecks($check);
        $results['metadata'] = [
            'total_events' => count(EventCatalog::all()),
            'min_coverage' => $minCoverage,
            'min_compliance' => $minCompliance,
            'fail_level' => $failLevel,
        ];

        $results['passed'] = $this->evaluatePassFail($results, $failLevel, $minCoverage, $minCompliance);

        if ($outputJson) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->renderResults($results);
        }

        return $results['passed'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Run selected checks (or all).
     *
     * @return array<string, mixed>
     */
    private function runChecks(string $check): array
    {
        $results = [];

        if ($check === 'all' || $check === 'schema') {
            $results['schema'] = $this->checkSchemaCoverage();
        }

        if ($check === 'all' || $check === 'diff') {
            $results['diff'] = $this->checkCatalogDiff();
        }

        if ($check === 'all' || $check === 'compliance') {
            $results['compliance'] = $this->checkCompliance();
        }

        if ($check === 'all' || $check === 'coverage') {
            $results['coverage'] = $this->checkProviderCoverage();
        }

        if ($check === 'all' || $check === 'dedup') {
            $results['dedup'] = $this->checkDeduplication();
        }

        return $results;
    }

    /**
     * Check schema coverage — what % of catalog events have schemas.
     *
     * @return array{coverage_percent: float, with_schema: int, without_schema: int, missing_events: list<string>, status: string}
     */
    private function checkSchemaCoverage(): array
    {
        if ($this->schemaRegistry === null) {
            return [
                'coverage_percent' => -1,
                'with_schema' => 0,
                'without_schema' => 0,
                'missing_events' => [],
                'status' => 'skipped',
                'reason' => 'EventSchemaRegistry not available',
            ];
        }

        $allEvents = EventCatalog::all();
        $total = count($allEvents);
        $missing = [];

        foreach ($allEvents as $name => $entry) {
            $schema = $this->schemaRegistry->get($name);
            if ($schema === null) {
                $missing[] = $name;
            }
        }

        $withSchema = $total - count($missing);
        $percent = $total > 0 ? round(($withSchema / $total) * 100, 1) : 100.0;

        return [
            'coverage_percent' => $percent,
            'with_schema' => $withSchema,
            'without_schema' => count($missing),
            'missing_events' => array_slice($missing, 0, 50), // Limit output
            'status' => $percent >= 50.0 ? 'pass' : 'warning',
        ];
    }

    /**
     * Check catalog diff against last snapshot.
     *
     * @return array{has_baseline: bool, added_count: int, removed_count: int, renamed_count: int, category_changes_count: int, added: list<string>, removed: list<string>, renamed: list<array{from: string, to: string}>, status: string}
     */
    private function checkCatalogDiff(): array
    {
        $diff = $this->diffService->diff();

        $status = 'pass';

        if (!$diff['has_baseline']) {
            $status = 'warning';
        } elseif (count($diff['removed']) > 0) {
            $status = 'error'; // Removed events = potential breaking change
        } elseif (count($diff['added']) > 10) {
            $status = 'warning'; // Large additions = review needed
        }

        return [
            'has_baseline' => $diff['has_baseline'],
            'added_count' => count($diff['added']),
            'removed_count' => count($diff['removed']),
            'renamed_count' => count($diff['renamed']),
            'category_changes_count' => count($diff['category_changes']),
            'added' => $diff['added'],
            'removed' => $diff['removed'],
            'renamed' => $diff['renamed'],
            'status' => $status,
        ];
    }

    /**
     * Check compliance score.
     *
     * @return array{score: float, status: string, reason?: string}
     */
    private function checkCompliance(): array
    {
        if ($this->complianceService === null) {
            return [
                'score' => -1,
                'status' => 'skipped',
                'reason' => 'AnalyticsComplianceScoringService not available',
            ];
        }

        $health = $this->complianceService->quickHealth();
        $score = (float) ($health['score'] ?? 0.0);

        return [
            'score' => $score,
            'status' => $score >= 70.0 ? 'pass' : 'warning',
        ];
    }

    /**
     * Check provider coverage — events mapped to at least one provider.
     *
     * @return array{coverage_percent: float, unmapped_count: int, unmapped_events: list<string>, status: string}
     */
    private function checkProviderCoverage(): array
    {
        $allEvents = EventCatalog::all();
        $total = count($allEvents);
        $unmapped = [];

        foreach ($allEvents as $name => $entry) {
            $hasGa4 = isset($entry['ga4']) && $entry['ga4'] !== '';
            $hasMeta = isset($entry['meta']) && $entry['meta'] !== '';

            if (!$hasGa4 && !$hasMeta) {
                $unmapped[] = $name;
            }
        }

        $mapped = $total - count($unmapped);
        $percent = $total > 0 ? round(($mapped / $total) * 100, 1) : 100.0;

        return [
            'coverage_percent' => $percent,
            'unmapped_count' => count($unmapped),
            'unmapped_events' => array_slice($unmapped, 0, 50),
            'status' => $percent >= 70.0 ? 'pass' : 'warning',
        ];
    }

    /**
     * Check for duplicate event definitions.
     *
     * @return array{ga4_duplicates: list<string>, meta_duplicates: list<string>, status: string}
     */
    private function checkDeduplication(): array
    {
        $allEvents = EventCatalog::all();
        $ga4Map = [];
        $metaMap = [];

        foreach ($allEvents as $name => $entry) {
            $ga4 = $entry['ga4'] ?? '';
            $meta = $entry['meta'] ?? '';

            if ($ga4 !== '') {
                $ga4Map[$ga4][] = $name;
            }

            if ($meta !== '' && $meta !== null) {
                $metaMap[$meta][] = $name;
            }
        }

        $ga4Dups = array_filter($ga4Map, static fn (array $v): bool => count($v) > 1);
        $metaDups = array_filter($metaMap, static fn (array $v): bool => count($v) > 1);

        $status = (count($ga4Dups) === 0 && count($metaDups) === 0) ? 'pass' : 'warning';

        return [
            'ga4_duplicates' => array_keys($ga4Dups),
            'meta_duplicates' => array_keys($metaDups),
            'status' => $status,
        ];
    }

    /**
     * Evaluate overall pass/fail based on results and fail level.
     *
     * @param  array<string, mixed>  $results
     * @return bool
     */
    private function evaluatePassFail(array $results, string $failLevel, int $minCoverage, int $minCompliance): bool
    {
        $errors = 0;
        $warnings = 0;

        foreach ($results as $key => $check) {
            if ($key === 'metadata' || $key === 'passed') {
                continue;
            }

            if (!is_array($check)) {
                continue;
            }

            $status = $check['status'] ?? 'pass';

            if ($status === 'error') {
                $errors++;
            } elseif ($status === 'warning') {
                $warnings++;
            }
        }

        // Schema coverage below minimum = warning
        if (isset($results['schema']) && is_array($results['schema'])) {
            $coverage = $results['schema']['coverage_percent'] ?? 100.0;
            if ($coverage >= 0 && $coverage < $minCoverage) {
                $warnings++;
            }
        }

        // Compliance below minimum = warning
        if (isset($results['compliance']) && is_array($results['compliance'])) {
            $score = $results['compliance']['score'] ?? 100.0;
            if ($score >= 0 && $score < $minCompliance) {
                $warnings++;
            }
        }

        return match ($failLevel) {
            'error' => $errors === 0,
            'warning' => $errors === 0 && $warnings === 0,
            'none' => true,
            default => true,
        };
    }

    /**
     * Render results as a human-readable table.
     *
     * @param  array<string, mixed>  $results
     */
    private function renderResults(array $results): void
    {
        $this->line('');
        $this->info('╔══════════════════════════════════════════════════╗');
        $this->info('║  ZeroBoiler Analytics — Quality Gate Report        ║');
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->line('');

        $metadata = $results['metadata'] ?? [];
        $this->line("  Total catalog events: {$metadata['total_events']}");
        $this->line('');

        foreach (['schema', 'diff', 'compliance', 'coverage', 'dedup'] as $check) {
            if (!isset($results[$check]) || !is_array($results[$check])) {
                continue;
            }

            $data = $results[$check];
            $status = $data['status'] ?? 'pass';

            $icon = match ($status) {
                'pass' => '<fg=green>✓</>',
                'warning' => '<fg=yellow>⚠</>',
                'error' => '<fg=red>✗</>',
                'skipped' => '<fg=gray>○</>',
                default => '?',
            };

            $this->line("  {$icon} <bold>{$check}</bold>");

            if ($check === 'schema') {
                $cov = $data['coverage_percent'] ?? -1;
                if ($cov >= 0) {
                    $this->line("      Coverage: {$cov}% ({$data['with_schema']} with / {$data['without_schema']} without)");
                } else {
                    $this->line("      Skipped: {$data['reason']}");
                }
            } elseif ($check === 'diff') {
                $this->line("      Added: {$data['added_count']}, Removed: {$data['removed_count']}, Renamed: {$data['renamed_count']}");
                if (!$data['has_baseline']) {
                    $this->line('      <comment>No baseline snapshot found. Run with --snapshot to create one.</comment>');
                }
                if (!empty($data['removed'])) {
                    $this->line('      <fg=red>Removed events: ' . implode(', ', $data['removed']) . '</>');
                }
            } elseif ($check === 'compliance') {
                $score = $data['score'] ?? -1;
                if ($score >= 0) {
                    $this->line("      Score: {$score}/100");
                } else {
                    $this->line("      Skipped: {$data['reason']}");
                }
            } elseif ($check === 'coverage') {
                $cov = $data['coverage_percent'] ?? 100;
                $this->line("      Provider coverage: {$cov}% ({$data['unmapped_count']} unmapped)");
            } elseif ($check === 'dedup') {
                $ga4 = count($data['ga4_duplicates'] ?? []);
                $meta = count($data['meta_duplicates'] ?? []);
                $this->line("      GA4 duplicates: {$ga4}, Meta duplicates: {$meta}");
            }

            $this->line('');
        }

        $passed = $results['passed'] ?? true;
        if ($passed) {
            $this->info('  <fg=green>Quality gate: PASSED</>');
        } else {
            $this->error('  <fg=red>Quality gate: FAILED</>');
        }

        $this->line('');
    }
}
