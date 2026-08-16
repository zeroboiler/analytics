<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsCompositeHealthIndex;
use ZeroBoiler\Analytics\Services\MultiTouchAttributionService;
use ZeroBoiler\Analytics\Services\RealTimeEventCorrelationEngine;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Analytics Health Intelligence Command — composite health + attribution + correlation overview.
 *
 * Provides a unified admin command for SaaS analytics health monitoring:
 *
 * - `health`: Composite health index score with dimension breakdown
 * - `trend`: Health trend over time (improving/stable/declining)
 * - `attribution`: Multi-touch attribution model comparison
 * - `correlation`: Event correlation analysis overview
 * - `candidates`: List correlation-candidate events
 *
 * Usage:
 *   php artisan analytics:health-intelligence
 *   php artisan analytics:health-intelligence health
 *   php artisan analytics:health-intelligence trend --json
 *   php artisan analytics:health-intelligence attribution --model=position_based
 *   php artisan analytics:health-intelligence correlation --limit=10
 *   php artisan analytics:health-intelligence candidates
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsCompositeHealthIndex
 * @see \ZeroBoiler\Analytics\Services\MultiTouchAttributionService
 * @see \ZeroBoiler\Analytics\Services\RealTimeEventCorrelationEngine
 *
 * @since 204.0.0
 */
final class AnalyticsHealthIntelligenceCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:health-intelligence
        {action? : Action to perform (health, trend, attribution, correlation, candidates)}
        {--model=position_based : Attribution model (first_touch, last_touch, linear, position_based, time_decay, w_shaped)}
        {--json : Output as JSON}
        {--limit=20 : Max results to display}
        {--force : Bypass cache and compute fresh}';

    /** @var string */
    protected $description = 'Analytics health intelligence — composite health, attribution, and event correlation';

    /**
     * Execute the console command.
     */
    public function handle(
        AnalyticsCompositeHealthIndex $healthIndex,
        MultiTouchAttributionService $attributionService,
        RealTimeEventCorrelationEngine $correlationEngine,
    ): int {
        $action = $this->argument('action') ?? 'health';
        $asJson = (bool) $this->option('json');
        $forceFresh = (bool) $this->option('force');

        return match ($action) {
            'health' => $this->actionHealth($healthIndex, $asJson, $forceFresh),
            'trend' => $this->actionTrend($healthIndex, $asJson),
            'attribution' => $this->actionAttribution($attributionService, $asJson),
            'correlation' => $this->actionCorrelation($correlationEngine, $asJson),
            'candidates' => $this->actionCandidates($correlationEngine, $asJson),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Display the composite health index.
     */
    private function actionHealth(AnalyticsCompositeHealthIndex $healthIndex, bool $asJson, bool $forceFresh): int
    {
        $report = $forceFresh ? $healthIndex->computeFresh() : $healthIndex->compute();

        if ($asJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('Analytics Composite Health Index');
        $this->newLine();

        // Overall score
        $score = $report['score'];
        $grade = $report['grade'];
        $color = $score >= 80 ? 'green' : ($score >= 55 ? 'yellow' : 'red');

        $this->components->twoColumnDetail('Overall Score', "({$color}){$score}/100 — Grade: {$grade}");
        $this->components->twoColumnDetail('Computed At', $report['computed_at']);
        $this->newLine();

        // Dimension breakdown
        $this->components->info('Dimension Breakdown');
        $this->newLine();

        foreach ($report['dimensions'] as $key => $dimension) {
            $dimScore = $dimension['score'];
            $dimGrade = $dimension['grade'];
            $dimStatus = $dimension['status'];
            $statusIcon = $dimStatus === 'healthy' ? '✓' : ($dimStatus === 'warning' ? '⚠' : '✗');

            $bar = $this->renderBar($dimScore);
            $this->line("  {$statusIcon} <fg=cyan>{$dimension['name']}</>  {$bar}  <fg=white>{$dimScore}/100</> (<fg=gray>{$dimGrade}</>)");
            $this->line("      <fg=gray>{$dimension['details']}</>");
        }

        $this->newLine();

        // Recommendations
        if (! empty($report['recommendations'])) {
            $this->components->info('Recommendations');
            foreach ($report['recommendations'] as $rec) {
                $this->line("  • {$rec}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Display health trend over time.
     */
    private function actionTrend(AnalyticsCompositeHealthIndex $healthIndex, bool $asJson): int
    {
        $trend = $healthIndex->trend();

        if ($asJson) {
            $this->line(json_encode($trend, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $direction = $trend['direction'];
        $icon = match ($direction) {
            'improving' => '📈',
            'declining' => '📉',
            default => '➡️',
        };

        $this->components->info("Health Trend {$icon}");
        $this->newLine();
        $this->components->twoColumnDetail('Current Score', (string) $trend['current']);
        $this->components->twoColumnDetail('Previous Score', $trend['previous'] !== null ? (string) $trend['previous'] : 'N/A (first measurement)');
        $this->components->twoColumnDetail('Delta', ($trend['delta'] >= 0 ? '+' : '') . $trend['delta']);
        $this->components->twoColumnDetail('Direction', $direction);

        return self::SUCCESS;
    }

    /**
     * Display multi-touch attribution model info.
     */
    private function actionAttribution(MultiTouchAttributionService $attributionService, bool $asJson): int
    {
        $models = $attributionService->supportedModels();
        $conversionEvents = $attributionService->conversionEvents();

        $info = [
            'supported_models' => $models,
            'conversion_events' => $conversionEvents,
            'default_model' => $this->option('model'),
        ];

        if ($asJson) {
            $this->line(json_encode($info, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('Multi-Touch Attribution Models');
        $this->newLine();

        foreach ($models as $model) {
            $description = match ($model) {
                'first_touch' => '100% credit to first touchpoint',
                'last_touch' => '100% credit to last touchpoint',
                'linear' => 'Equal credit across all touchpoints',
                'position_based' => '40% first, 40% last, 20% middle',
                'time_decay' => 'More credit to recent touchpoints',
                'w_shaped' => '30% first, 30% lead, 30% last, 10% middle',
                default => 'Unknown model',
            };
            $this->components->twoColumnDetail($model, $description);
        }

        $this->newLine();
        $this->components->info('Conversion Events');
        $this->newLine();

        foreach ($conversionEvents as $event) {
            $this->line("  • {$event}");
        }

        return self::SUCCESS;
    }

    /**
     * Display event correlation analysis overview.
     */
    private function actionCorrelation(RealTimeEventCorrelationEngine $correlationEngine, bool $asJson): int
    {
        $candidates = $correlationEngine->candidateEvents();
        $limit = (int) $this->option('limit');

        if ($asJson) {
            $this->line(json_encode([
                'candidate_events' => $candidates,
                'total_candidates' => count($candidates),
                'excluded_events' => RealTimeEventCorrelationEngine::class,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('Event Correlation Engine — Candidate Events');
        $this->newLine();
        $this->components->twoColumnDetail('Total Candidates', (string) count($candidates));
        $this->components->twoColumnDetail('Showing (limit)', (string) $limit);
        $this->newLine();

        $display = array_slice($candidates, 0, $limit);
        foreach ($display as $i => $event) {
            $category = EventCatalog::getCategory($event) ?? 'unknown';
            $this->line(sprintf('  %3d. <fg=cyan>%-35s</> <fg=gray>[%s]</>', $i + 1, $event, $category));
        }

        return self::SUCCESS;
    }

    /**
     * List correlation candidate events.
     */
    private function actionCandidates(RealTimeEventCorrelationEngine $correlationEngine, bool $asJson): int
    {
        $candidates = $correlationEngine->candidateEvents();

        if ($asJson) {
            $this->line(json_encode($candidates, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('Correlation-Candidate Events');
        $this->newLine();

        foreach ($candidates as $event) {
            $category = EventCatalog::getCategory($event) ?? 'unknown';
            $this->components->twoColumnDetail($event, $category);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Total', (string) count($candidates));

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     */
    private function invalidAction(string $action): int
    {
        $validActions = ['health', 'trend', 'attribution', 'correlation', 'candidates'];
        $this->components->error("Invalid action: {$action}");
        $this->line('Valid actions: ' . implode(', ', $validActions));

        return self::FAILURE;
    }

    /**
     * Render a simple ASCII progress bar.
     */
    private function renderBar(float $score): string
    {
        $width = 20;
        $filled = (int) round($score / 100 * $width);
        $empty = $width - $filled;

        return str_repeat('█', $filled) . str_repeat('░', $empty);
    }
}
