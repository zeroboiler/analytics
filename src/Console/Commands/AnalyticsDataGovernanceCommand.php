<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsDataResidencyService;
use ZeroBoiler\Analytics\Services\EventConsistencyValidatorService;

/**
 * Displays data residency compliance and event consistency diagnostics.
 *
 * Provides a comprehensive view of:
 * - Configured geographic zones and their compliance rules
 * - Cross-provider event coverage and consistency scores
 * - Priority gaps that should be addressed
 * - Audit log summary for data residency decisions
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsDataResidencyService
 * @see \ZeroBoiler\Analytics\Services\EventConsistencyValidatorService
 *
 * @since 134.0.0
 */
final class AnalyticsDataGovernanceCommand extends Command
{
    protected $signature = 'zb:analytics:data-governance
        {--json : Output as JSON}
        {--residency : Show data residency details}
        {--consistency : Show event consistency details}
        {--gaps : Show priority gaps only}
        {--audit : Show audit log}
        {--clear-cache : Clear consistency validation cache}
        {--clear-audit : Clear residency audit log}';

    protected $description = 'Display data residency compliance and event consistency diagnostics';

    private AnalyticsDataResidencyService $residencyService;

    private EventConsistencyValidatorService $consistencyService;

    /**
     * @param  AnalyticsDataResidencyService  $residencyService
     * @param  EventConsistencyValidatorService  $consistencyService
     */
    public function __construct(
        AnalyticsDataResidencyService $residencyService,
        EventConsistencyValidatorService $consistencyService,
    ){
        parent::__construct();
        $this->residencyService = $residencyService;
        $this->consistencyService = $consistencyService;
    }

    #[Override]
    public function handle(): int
    {
        // Handle cache clearing
        if ($this->option('clear-cache')) {
            $this->consistencyService->clearCache();
            $this->info('Event consistency cache cleared.');

            return self::SUCCESS;
        }

        if ($this->option('clear-audit')) {
            $this->residencyService->clearAuditLog();
            $this->info('Data residency audit log cleared.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($this->buildJsonOutput(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        // Render sections
        $this->renderHeader();

        if ($this->option('gaps')) {
            $this->renderPriorityGaps();
        } elseif ($this->option('audit')) {
            $this->renderAuditLog();
        } elseif ($this->option('residency')) {
            $this->renderResidencyDetails();
        } elseif ($this->option('consistency')) {
            $this->renderConsistencyDetails();
        } else {
            // Default: render both summaries
            $this->renderResidencySummary();
            $this->newLine();
            $this->renderConsistencySummary();
        }

        return self::SUCCESS;
    }

    /**
     * Build JSON output for all governance data.
     *
     * @return array{data_residency: array, event_consistency: array, gaps: list<array>}
     */
    private function buildJsonOutput(): array
    {
        return [
            'data_residency' => [
                'enabled' => $this->residencyService->isEnabled(),
                'default_zone' => $this->residencyService->getDefaultZone(),
                'zones' => $this->residencyService->getZones(),
                'compliance_summary' => $this->residencyService->getComplianceSummary(),
                'audit_entries' => count($this->residencyService->getAuditLog()),
            ],
            'event_consistency' => [
                'enabled' => $this->consistencyService->isEnabled(),
                'consistency_score' => $this->consistencyService->getConsistencyScore(),
                'full_results' => $this->consistencyService->validateAllEvents(),
            ],
            'gaps' => $this->consistencyService->getPriorityGaps(),
        ];
    }

    /**
     * Render the command header.
     */
    private function renderHeader(): void
    {
        $this->components->info('ZeroBoiler Analytics — Data Governance Report v134.0.0');
    }

    /**
     * Render data residency summary.
     */
    private function renderResidencySummary(): void
    {
        $this->components->section('Data Residency');
        $this->line('  Status: ' . ($this->residencyService->isEnabled() ? '<fg=green>ENABLED</>' : '<fg=yellow>DISABLED</>'));
        $this->line('  Default Zone: ' . $this->residencyService->getDefaultZone());

        $zones = $this->residencyService->getZones();
        $this->line('  Configured Zones: ' . count($zones));

        foreach ($zones as $code => $zone) {
            $providerCount = count($zone['allowed_providers']);
            $consentRequired = $zone['requires_consent'] ? ' [consent required]' : '';
            $this->line("    • {$code}: {$zone['label']} ({$providerCount} providers){$consentRequired}");
        }

        $summary = $this->residencyService->getComplianceSummary();
        $this->line("  Compliance Score: {$summary['compliance_score']}%");
        $this->line("  Total Audit Entries: {$summary['total_audit_entries']}");
    }

    /**
     * Render detailed data residency information.
     */
    private function renderResidencyDetails(): void
    {
        $this->renderResidencySummary();
        $this->newLine();

        $zones = $this->residencyService->getZones();

        foreach ($zones as $code => $zone) {
            $this->components->twoColumnDetail("Zone: {$code}", $zone['label']);
            $this->line('    Providers: ' . implode(', ', $zone['allowed_providers']));
            $this->line('    Blocked Fields: ' . (count($zone['blocked_fields']) > 0 ? implode(', ', $zone['blocked_fields']) : '(none)'));

            $validation = $this->residencyService->validateZoneConfig($code);
            if (! $validation['valid']) {
                foreach ($validation['errors'] as $error) {
                    $this->components->error("  ⚠ {$error}");
                }
            }
            $this->newLine();
        }
    }

    /**
     * Render event consistency summary.
     */
    private function renderConsistencySummary(): void
    {
        $this->components->section('Event Consistency');

        if (! $this->consistencyService->isEnabled()) {
            $this->line('  Status: <fg=yellow>DISABLED</>');

            return;
        }

        $this->line('  Status: <fg=green>ENABLED</>');

        $score = $this->consistencyService->getConsistencyScore();

        $gradeColor = match (true) {
            str_starts_with($score['grade'], 'A') => 'green',
            str_starts_with($score['grade'], 'B') => 'cyan',
            str_starts_with($score['grade'], 'C') => 'yellow',
            default => 'red',
        };

        $this->line("  Consistency Score: <fg={$gradeColor}>{$score['score']}% (Grade: {$score['grade']})</>");
        $this->line("  Total Events: {$score['total_events']}");
        $this->line("  Fully Covered: {$score['fully_covered']}");
        $this->line("  Gap Events: {$score['gap_events']}");
        $this->line("  Weakest Provider: {$score['weakest_provider']} ({$score['weakest_provider_coverage']}%)");

        $this->newLine();
        $this->renderTopGaps(5);
    }

    /**
     * Render detailed event consistency information.
     */
    private function renderConsistencyDetails(): void
    {
        $this->renderConsistencySummary();
        $this->newLine();

        $allResults = $this->consistencyService->validateAllEvents();
        $this->line('  Provider Coverage:');

        foreach ($allResults['provider_coverage'] as $provider => $count) {
            $percentage = $allResults['total_events'] > 0
                ? round(($count / $allResults['total_events']) * 100, 1)
                : 100;
            $barLength = (int) round($percentage / 5);
            $bar = str_repeat('█', $barLength) . str_repeat('░', 20 - $barLength);
            $color = $percentage >= 90 ? 'green' : ($percentage >= 70 ? 'yellow' : 'red');
            $this->line("    <fg={$color}>{$provider}</>: {$bar} {$count}/{$allResults['total_events']} ({$percentage}%)");
        }

        $this->newLine();
        $this->renderTopGaps(10);
    }

    /**
     * Render priority gaps.
     */
    private function renderPriorityGaps(): void
    {
        $this->components->section('Priority Gaps');
        $gaps = $this->consistencyService->getPriorityGaps();

        if (empty($gaps)) {
            $this->line('  <fg=green>No coverage gaps detected!</>');

            return;
        }

        $this->line("  Found " . count($gaps) . ' events with provider coverage gaps:');
        $this->newLine();

        foreach (array_slice($gaps, 0, 20) as $gap) {
            $priorityColor = match ($gap['priority']) {
                'critical' => 'red',
                'high' => 'yellow',
                'medium' => 'cyan',
                default => 'white',
            };
            $this->line("  [<fg={$priorityColor}>{$gap['priority']}</>] {$gap['event']} ({$gap['category']})");
            $this->line('    Missing: ' . implode(', ', $gap['missing_providers']));
        }

        if (count($gaps) > 20) {
            $this->line("  ... and " . (count($gaps) - 20) . ' more gaps');
        }
    }

    /**
     * Render the audit log.
     */
    private function renderAuditLog(): void
    {
        $this->components->section('Residency Audit Log');

        $entries = $this->residencyService->getAuditLog(50);

        if (empty($entries)) {
            $this->line('  No audit entries found.');
            $this->line('  Audit entries are recorded when events are routed through the data residency service.');

            return;
        }

        $this->line('  Last ' . count($entries) . ' entries:');
        $this->newLine();

        foreach (array_reverse($entries) as $entry) {
            $decisionColor = ($entry['decision'] ?? '') === 'blocked' ? 'red' : 'green';
            $this->line("  [<fg={$decisionColor}>{$entry['decision']}</>] {$entry['timestamp']}");
            $this->line("    Event: {$entry['event']} → {$entry['provider']} ({$entry['zone']})");
            if (! empty($entry['blocked_fields'])) {
                $this->line('    Blocked: ' . implode(', ', $entry['blocked_fields']));
            }
        }
    }

    /**
     * Render top N gaps in a compact format.
     *
     * @param  int  $limit  Number of gaps to show
     */
    private function renderTopGaps(int $limit): void
    {
        $gaps = $this->consistencyService->getPriorityGaps();

        if (empty($gaps)) {
            return;
        }

        $this->line('  Top Priority Gaps:');
        foreach (array_slice($gaps, 0, $limit) as $gap) {
            $this->line("    • [{$gap['priority']}] {$gap['event']} → missing: " . implode(', ', $gap['missing_providers']));
        }

        if (count($gaps) > $limit) {
            $this->line('    ... and ' . (count($gaps) - $limit) . ' more');
        }
    }
}
