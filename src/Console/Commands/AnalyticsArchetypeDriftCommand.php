<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\ConfigDriftDetectionService;
use ZeroBoiler\Analytics\Services\EventArchetypeService;

/**
 * Analytics config drift detection and archetype management command.
 *
 * @see \ZeroBoiler\Analytics\Services\ConfigDriftDetectionService
 * @see \ZeroBoiler\Analytics\Services\EventArchetypeService
 *
 * @since 1.0.0
 */
final class AnalyticsArchetypeDriftCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:archetype-drift
        {action : baseline|drift|clear|archetypes|gaps|score}
        {--archetype= : Archetype key for score action}
        {--events=* : Completed event names for score action}';

    /** @var string */
    protected $description = 'Manage analytics config drift detection and event archetypes';

    #[Override]
    #[Override]
    public function handle(
        ConfigDriftDetectionService $driftService,
        EventArchetypeService $archetypeService,
    ): int {
        $action = $this->argument('action');

        return match ($action) {
            'baseline' => $this->captureBaseline($driftService),
            'drift' => $this->detectDrift($driftService),
            'clear' => $this->clearBaseline($driftService),
            'archetypes' => $this->listArchetypes($archetypeService),
            'gaps' => $this->detectGaps($archetypeService),
            'score' => $this->calculateScore($archetypeService),
            default => $this->invalidAction($action),
        };
    }

    private function captureBaseline(ConfigDriftDetectionService $service): int
    {
        $meta = $service->captureBaseline();

        $this->info('✓ Config baseline captured successfully.');
        $this->table(
            ['Key', 'Value'],
            [
                ['Version', $meta['version']],
                ['Captured At', $meta['captured_at']],
                ['Sections', (string) $meta['sections']],
                ['Keys', (string) $meta['keys']],
            ],
        );

        return self::SUCCESS;
    }

    private function detectDrift(ConfigDriftDetectionService $service): int
    {
        if (! $service->hasBaseline()) {
            $this->warn('No baseline found. Run `zb:analytics:archetype-drift baseline` first.');

            return self::FAILURE;
        }

        $result = $service->detectDrift();

        if ($result['drift_detected']) {
            $this->warn('⚠ Config drift detected!');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Baseline Version', $result['baseline_version'] ?? 'unknown'],
                    ['Current Version', $result['current_version']],
                    ['Added Keys', (string) count($result['added'])],
                    ['Removed Keys', (string) count($result['removed'])],
                    ['Changed Keys', (string) count($result['changed'])],
                    ['Unchanged Keys', (string) count($result['unchanged'])],
                ],
            );

            if ($result['added'] !== []) {
                $this->newLine();
                $this->info('Added keys:');
                foreach ($result['added'] as $key) {
                    $this->line("  + {$key}");
                }
            }

            if ($result['removed'] !== []) {
                $this->newLine();
                $this->info('Removed keys:');
                foreach ($result['removed'] as $key) {
                    $this->line("  - {$key}");
                }
            }

            if ($result['changed'] !== []) {
                $this->newLine();
                $this->info('Changed keys:');
                foreach ($result['changed'] as $change) {
                    $old = is_scalar($change['baseline']) ? (string) $change['baseline'] : json_encode($change['baseline']);
                    $new = is_scalar($change['current']) ? (string) $change['current'] : json_encode($change['current']);
                    $this->line("  ~ {$change['key']}: {$old} → {$new}");
                }
            }
        } else {
            $this->info('✓ No config drift detected. Current config matches baseline.');
        }

        return self::SUCCESS;
    }

    private function clearBaseline(ConfigDriftDetectionService $service): int
    {
        $cleared = $service->clearBaseline();

        if ($cleared) {
            $this->info('✓ Config baseline cleared.');

            return self::SUCCESS;
        }

        $this->warn('No baseline to clear.');

        return self::FAILURE;
    }

    private function listArchetypes(EventArchetypeService $service): int
    {
        $summary = $service->summary();

        if ($summary === []) {
            $this->warn('No archetypes available.');

            return self::FAILURE;
        }

        $this->info('Event Archetypes (v' . AnalyticsEvent::VERSION . ')');
        $this->table(
            ['Key', 'Name', 'Category', 'Steps', 'Required', 'Events'],
            array_map(
                fn (array $a): array => [
                    $a['key'],
                    $a['name'],
                    $a['category'],
                    (string) $a['steps'],
                    (string) $a['required_steps'],
                    implode(', ', array_slice($a['event_names'], 0, 5)) . (count($a['event_names']) > 5 ? ' …' : ''),
                ],
                $summary,
            ),
        );

        return self::SUCCESS;
    }

    private function detectGaps(EventArchetypeService $service): int
    {
        $gaps = $service->detectGaps();

        $this->info('Archetype Instrumentation Gap Analysis');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Steps', (string) $gaps['total_steps']],
                ['Missing Steps', (string) $gaps['missing_steps']],
                ['Coverage', $gaps['coverage_pct'] . '%'],
            ],
        );

        if ($gaps['gaps'] !== []) {
            $this->newLine();
            $this->warn('Missing events (' . count($gaps['gaps']) . '):');
            $this->table(
                ['Archetype', 'Step', 'Event'],
                array_map(
                    fn (array $g): array => [$g['archetype'], $g['step'], $g['event']],
                    $gaps['gaps'],
                ),
            );
        } else {
            $this->info('✓ All archetype events are in the EventCatalog. No gaps detected.');
        }

        return self::SUCCESS;
    }

    private function calculateScore(EventArchetypeService $service): int
    {
        $archetypeKey = $this->option('archetype');
        $events = $this->option('events');

        if ($archetypeKey === null || $archetypeKey === '') {
            $this->error('--archetype is required for score action.');

            return self::FAILURE;
        }

        $archetype = $service->get((string) $archetypeKey);
        if ($archetype === null) {
            $this->error("Archetype '{$archetypeKey}' not found. Available: " . implode(', ', $service->keys()));

            return self::FAILURE;
        }

        $score = $service->completionScore((string) $archetypeKey, $events);

        $this->info("Completion Score: {$archetype['name']} ({$archetypeKey})");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Score', "{$score['score']} / {$score['max_score']}"],
                ['Percentage', $score['pct'] . '%'],
                ['Completed Steps', implode(', ', $score['completed_steps']) ?: 'none'],
                ['Missing Steps', implode(', ', $score['missing_steps']) ?: 'none'],
            ],
        );

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->line('Available actions: baseline, drift, clear, archetypes, gaps, score');

        return self::FAILURE;
    }
}
