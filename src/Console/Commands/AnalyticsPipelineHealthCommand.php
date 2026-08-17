<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsPipelineHealthService;

/**
 * Analytics Pipeline Health Command.
 *
 * Displays the composite pipeline health score with detailed
 * per-dimension breakdown. Supports multiple output formats.
 *
 * Actions:
 *   (default)  Full health report with dimension breakdown
 *   --score     Quick score and grade only
 *   --history   Health trend history
 *   --attention Only show dimensions needing attention (< 70)
 *   --json      Machine-readable JSON output
 *   --invalidate Force recomputation by clearing cache
 *
 * @since 213.0.0
 */
final class AnalyticsPipelineHealthCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:pipeline-health
        {--score : Show score and grade only}
        {--history : Show health trend history}
        {--attention : Show only degraded/critical dimensions}
        {--json : Output as JSON}
        {--invalidate : Clear cache and recompute}';

    /** @var string */
    protected $description = 'Analytics pipeline infrastructure health score';

    private AnalyticsPipelineHealthService $service;

    public function __construct(AnalyticsPipelineHealthService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('invalidate')) {
            $this->service->invalidate();
            $this->info('Pipeline health cache invalidated.');

            return self::SUCCESS;
        }

        if (! $this->service->isEnabled()) {
            $this->warn('Pipeline health service is disabled in configuration.');

            return self::SUCCESS;
        }

        if ($this->option('history')) {
            return $this->showHistory();
        }

        if ($this->option('score')) {
            return $this->showScore();
        }

        if ($this->option('attention')) {
            return $this->showAttention();
        }

        return $this->showFullReport();
    }

    /**
     * Display the full health report with dimension breakdown.
     */
    private function showFullReport(): int
    {
        $result = $this->service->compute();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $score = $result['score'];
        $grade = $result['grade'];
        $status = $result['status'];
        $computedAt = $result['computed_at'];

        $statusColor = match ($status) {
            'healthy' => 'info',
            'degraded' => 'comment',
            'critical' => 'warn',
            default => 'error',
        };

        $this->components->info("Analytics Pipeline Health — {$grade} ({$score}/100)");
        $this->newLine();

        // Status badge
        $this->components->twoColumnDetail('Status', "<{$statusColor}>{$status}</{$statusColor}>");
        $this->components->twoColumnDetail('Grade', $grade);
        $this->components->twoColumnDetail('Computed At', $computedAt);
        $this->components->twoColumnDetail('Cached', $result['cached'] ? 'Yes' : 'No (fresh)');
        $this->newLine();

        // Trend
        $trend = $this->service->trend();
        $trendIcon = match ($trend['direction']) {
            'improving' => '↑',
            'stable' => '→',
            'degrading' => '↓',
            default => '?',
        };
        $this->components->twoColumnDetail('Trend', "{$trendIcon} {$trend['direction']}");
        if ($trend['delta'] !== null) {
            $this->components->twoColumnDetail('Delta', (string) $trend['delta']);
        }
        $this->newLine();

        // Dimensions
        $this->components->info('Dimensions:');
        $this->newLine();

        foreach ($result['dimensions'] as $name => $dimension) {
            $dimScore = (float) $dimension['score'];
            $dimStatus = (string) $dimension['status'];
            $dimWeight = (float) $dimension['weight'];
            $dimDetails = (string) $dimension['details'];

            $color = match (true) {
                $dimScore >= 90.0 => 'info',
                $dimScore >= 70.0 => 'comment',
                default => 'error',
            };

            $label = str_replace('_', ' ', ucfirst($name));
            $this->components->twoColumnDetail($label, "<{$color}>{$dimScore}/100</{$color}> ({$dimStatus})");
            $this->components->twoColumnDetail('  Weight', (string) round($dimWeight * 100) . '%');
            $this->components->twoColumnDetail('  Details', $dimDetails);
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Display score and grade only.
     */
    private function showScore(): int
    {
        $result = $this->service->compute();
        $score = $result['score'];
        $grade = $result['grade'];
        $status = $result['status'];

        if ($this->option('json')) {
            $this->line(json_encode([
                'score' => $score,
                'grade' => $grade,
                'status' => $status,
                'computed_at' => $result['computed_at'],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info("Pipeline Health: {$grade} ({$score}/100) — {$status}");

        return self::SUCCESS;
    }

    /**
     * Display health history.
     */
    private function showHistory(): int
    {
        $history = $this->service->history();
        $trend = $this->service->trend();

        if ($this->option('json')) {
            $this->line(json_encode([
                'history' => $history,
                'trend' => $trend,
                'count' => count($history),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Health History — %d snapshots', count($history)));

        if ($history === []) {
            $this->comment('No history available yet.');

            return self::SUCCESS;
        }

        // Show last 10 entries
        $recent = array_slice($history, -10);

        $this->table(
            ['Time', 'Score', 'Grade', 'Status'],
            array_map(fn (array $entry): array => [
                $entry['computed_at'],
                (string) $entry['score'],
                $entry['grade'],
                $entry['status'],
            ], $recent),
        );

        $this->newLine();
        $this->components->twoColumnDetail('Trend', $trend['direction']);

        if ($trend['delta'] !== null) {
            $this->components->twoColumnDetail('Delta', (string) $trend['delta']);
        }

        return self::SUCCESS;
    }

    /**
     * Display only dimensions needing attention.
     */
    private function showAttention(): int
    {
        $attention = $this->service->attention();

        if ($this->option('json')) {
            $this->line(json_encode([
                'attention_count' => count($attention),
                'issues' => $attention,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($attention === []) {
            $this->components->info('No dimensions need attention — all healthy!');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf('%d dimension(s) need attention:', count($attention)));
        $this->newLine();

        foreach ($attention as $item) {
            $color = match ($item['status']) {
                'degraded' => 'comment',
                'critical' => 'error',
                default => 'warn',
            };

            $this->components->twoColumnDetail(
                $item['name'],
                "<{$color}>{$item['score']}/100 ({$item['status']})</{$color}>",
            );
            $this->components->twoColumnDetail('  Details', $item['details']);
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
