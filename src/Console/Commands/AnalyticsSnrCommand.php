<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventSNRCalculatorService;
use ZeroBoiler\Analytics\DTO\EventSNRResult;

/**
 * Analytics SNR Calculator — inspect event signal-to-noise ratios.
 *
 * @since 220.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\EventSNRCalculatorService
 */
final class AnalyticsSnrCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'zb:analytics:snr
        {--event= : Calculate SNR for a specific event}
        {--signal : Show only signal events (SNR ≥ 70)}
        {--noise : Show only noise events (SNR < 20)}
        {--categories : Show category-level summary}
        {--grades : Show grade distribution}
        {--top=10 : Limit output to top N events}
        {--json : Output as JSON}
        {--fresh : Force fresh calculation (bypass cache)}
        {--invalidate : Clear SNR cache and exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate event signal-to-noise ratios (SNR)';

    /**
     * Execute the console command.
     */
    public function handle(EventSNRCalculatorService $service): int
    {
        if ($this->option('invalidate')) {
            $service->invalidateCache();
            $this->info('SNR cache invalidated.');

            return self::SUCCESS;
        }

        $fresh = (bool) $this->option('fresh');

        // Single event mode
        if ($this->option('event')) {
            return $this->showSingleEvent($service, (string) $this->option('event'));
        }

        // Signal-only mode
        if ($this->option('signal')) {
            return $this->showSignalEvents($service);
        }

        // Noise-only mode
        if ($this->option('noise')) {
            return $this->showNoiseEvents($service);
        }

        // Category summary mode
        if ($this->option('categories')) {
            return $this->showCategorySummary($service);
        }

        // Grade distribution mode
        if ($this->option('grades')) {
            return $this->showGrades($service);
        }

        // Default: full report
        return $this->showFullReport($service, $fresh);
    }

    /**
     * Show SNR for a single event.
     */
    private function showSingleEvent(EventSNRCalculatorService $service, string $eventName): int
    {
        $result = $service->calculate($eventName);

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->displayEventTable([$result]);

        return self::SUCCESS;
    }

    /**
     * Show signal events.
     */
    private function showSignalEvents(EventSNRCalculatorService $service): int
    {
        $limit = (int) $this->option('top');
        $events = $service->topSignalEvents(70.0, $limit);

        if ($this->option('json')) {
            $this->line(json_encode(array_map(fn (EventSNRResult $r): array => $r->toArray(), $events), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (empty($events)) {
            $this->warn('No signal events found (SNR ≥ 70).');

            return self::SUCCESS;
        }

        $this->info("Top {$limit} Signal Events (SNR ≥ 70):");
        $this->displayEventTable($events);

        return self::SUCCESS;
    }

    /**
     * Show noise events.
     */
    private function showNoiseEvents(EventSNRCalculatorService $service): int
    {
        $limit = (int) $this->option('top');
        $events = $service->noiseEvents(20.0, $limit);

        if ($this->option('json')) {
            $this->line(json_encode(array_map(fn (EventSNRResult $r): array => $r->toArray(), $events), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (empty($events)) {
            $this->info('No noise events found (SNR < 20). Your event catalog is clean!');

            return self::SUCCESS;
        }

        $this->warn("Bottom {$limit} Noise Events (SNR < 20):");
        $this->displayEventTable($events);

        return self::SUCCESS;
    }

    /**
     * Show category-level summary.
     */
    private function showCategorySummary(EventSNRCalculatorService $service): int
    {
        $summary = $service->categorySummary();

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Category SNR Summary:');
        $this->table(
            ['Category', 'Events', 'Avg SNR', 'Signal', 'Noise', 'Total Cost'],
            array_map(
                fn (string $cat, array $data): array => [
                    $cat,
                    $data['count'],
                    $data['avg_snr'],
                    $data['signal_count'],
                    $data['noise_count'],
                    '$' . number_format($data['total_cost'], 4),
                ],
                array_keys($summary),
                array_values($summary),
            ),
        );

        return self::SUCCESS;
    }

    /**
     * Show grade distribution.
     */
    private function showGrades(EventSNRCalculatorService $service): int
    {
        $report = $service->report();
        $grades = $report['grades'];

        if ($this->option('json')) {
            $this->line(json_encode($grades, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Event SNR Grade Distribution:');
        $this->table(
            ['Grade', 'Count', 'Percentage'],
            array_map(
                fn (string $grade, int $count): array => [
                    $grade,
                    $count,
                    $report['total_events'] > 0
                        ? round(($count / $report['total_events']) * 100, 1) . '%'
                        : '0%',
                ],
                array_keys($grades),
                array_values($grades),
            ),
        );

        return self::SUCCESS;
    }

    /**
     * Show full SNR report.
     */
    private function showFullReport(EventSNRCalculatorService $service, bool $fresh): int
    {
        $report = $service->report($fresh);

        if ($this->option('json')) {
            $jsonReport = $report;
            $jsonReport['events'] = array_map(
                fn (EventSNRResult $r): array => $r->toArray(),
                $report['events'],
            );
            $this->line(json_encode($jsonReport, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        // Summary header
        $this->info('📊 Event Signal-to-Noise Ratio (SNR) Report');
        $this->newLine();
        $this->line("  Total Events:     {$report['total_events']}");
        $this->line("  Signal (≥70):     {$report['signal_count']} events");
        $this->line("  Moderate (40-69): {$report['moderate_count']} events");
        $this->line("  Noise Cand (20-39): {$report['noise_candidate_count']} events");
        $this->line("  Noise (<20):      {$report['noise_count']} events");
        $this->newLine();
        $this->line("  Average SNR:      {$report['average_snr']}");
        $this->line("  Median SNR:       {$report['median_snr']}");
        $this->line("  Weighted SNR:     {$report['weighted_snr']}");
        $this->line("  Monthly Cost:     \${$report['total_monthly_cost']}");
        $this->newLine();

        // Top signal events
        $this->info('  🟢 Top Signal Events:');
        foreach ($report['top_signal_events'] as $i => $name) {
            $event = $report['events'][$name] ?? null;
            $snr = $event?->snr ?? '—';
            $grade = $event?->grade ?? '—';
            $this->line("    " . ($i + 1) . ". {$name} (SNR: {$snr}, Grade: {$grade})");
        }

        $this->newLine();

        // Bottom noise events
        if (! empty($report['top_noise_events'])) {
            $this->warn('  🔴 Top Noise Events:');
            foreach ($report['top_noise_events'] as $i => $name) {
                $event = $report['events'][$name] ?? null;
                $snr = $event?->snr ?? '—';
                $grade = $event?->grade ?? '—';
                $this->line("    " . ($i + 1) . ". {$name} (SNR: {$snr}, Grade: {$grade})");
            }
        }

        $this->newLine();
        $this->comment("  Run 'zb:analytics:snr --prune' for removal recommendations.");
        $this->comment("  Computed at: {$report['computed_at']}");

        return self::SUCCESS;
    }

    /**
     * Display events as a table.
     *
     * @param  list<EventSNRResult>  $events
     */
    private function displayEventTable(array $events): void
    {
        $this->table(
            ['Event', 'Category', 'SNR', 'Grade', 'Verdict', 'Dispatches', 'Cost'],
            array_map(
                fn (EventSNRResult $r): array => [
                    $r->eventName,
                    $r->category,
                    $r->snr,
                    $r->grade,
                    $r->verdict,
                    number_format($r->dispatchCount),
                    '$' . number_format($r->totalCost, 4),
                ],
                $events,
            ),
        );
    }
}
