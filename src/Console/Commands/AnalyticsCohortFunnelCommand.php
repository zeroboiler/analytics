<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\CohortFunnelMatrixService;

/**
 * Admin CLI for the Cohort × Funnel Matrix Engine.
 *
 * Provides interactive commands for building, comparing, and analyzing
 * cohort-funnel matrices across predefined and custom funnels.
 *
 * @since 56.0.0
 */
final class AnalyticsCohortFunnelCommand extends Command
{
    /** @var string Command signature */
    protected $signature = 'zb:analytics:cohort-funnel
        {action : Command action (config|templates|build|compare|heatmap|velocity|analysis|dropoff|clear-cache)}
        {--template=onboarding : Funnel template name (onboarding, purchase, saas_conversion, engagement)}
        {--cohorts= : Comma-separated cohort labels (e.g., "2026-W01,2026-W02,2026-W03")}
        {--steps= : Comma-separated custom funnel step names (overrides --template)}
        {--json : Output as JSON}
    ';

    /** @var string Command description */
    protected $description = 'Cohort × Funnel Matrix Engine — cross-dimensional conversion analytics';

    private const SAMPLE_COHORT_DATA = [
        '2026-W28' => [
            'sign_up' => ['count' => 500, 'users' => ['u1', 'u2', 'u3'], 'timestamps' => [1709100000, 1709101000, 1709102000]],
            'email_verified' => ['count' => 420, 'users' => ['u1', 'u2'], 'timestamps' => [1709103600, 1709104600]],
            'profile_completed' => ['count' => 310, 'users' => ['u1'], 'timestamps' => [1709110800]],
            'first_feature_used' => ['count' => 230, 'users' => ['u1'], 'timestamps' => [1709118000]],
            'trial_started' => ['count' => 180, 'users' => ['u1'], 'timestamps' => [1709125200]],
        ],
        '2026-W29' => [
            'sign_up' => ['count' => 550, 'users' => ['u4', 'u5'], 'timestamps' => [1709700000, 1709701000]],
            'email_verified' => ['count' => 480, 'users' => ['u4'], 'timestamps' => [1709703600]],
            'profile_completed' => ['count' => 370, 'users' => ['u4'], 'timestamps' => [1709710800]],
            'first_feature_used' => ['count' => 280, 'users' => ['u4'], 'timestamps' => [1709718000]],
            'trial_started' => ['count' => 220, 'users' => ['u4'], 'timestamps' => [1709725200]],
        ],
        '2026-W30' => [
            'sign_up' => ['count' => 620, 'users' => ['u6', 'u7'], 'timestamps' => [1710300000, 1710301000]],
            'email_verified' => ['count' => 540, 'users' => ['u6'], 'timestamps' => [1710303600]],
            'profile_completed' => ['count' => 410, 'users' => ['u6'], 'timestamps' => [1710310800]],
            'first_feature_used' => ['count' => 310, 'users' => ['u6'], 'timestamps' => [1710318000]],
            'trial_started' => ['count' => 260, 'users' => ['u6'], 'timestamps' => [1710325200]],
        ],
        '2026-W31' => [
            'sign_up' => ['count' => 480, 'users' => ['u8', 'u9'], 'timestamps' => [1710900000, 1710901000]],
            'email_verified' => ['count' => 390, 'users' => ['u8'], 'timestamps' => [1710903600]],
            'profile_completed' => ['count' => 280, 'users' => ['u8'], 'timestamps' => [1710910800]],
            'first_feature_used' => ['count' => 200, 'users' => ['u8'], 'timestamps' => [1710918000]],
            'trial_started' => ['count' => 150, 'users' => ['u8'], 'timestamps' => [1710925200]],
        ],
    ];

    private const PURCHASE_SAMPLE_DATA = [
        '2026-W28' => [
            'view_item' => ['count' => 1200, 'users' => [], 'timestamps' => [1709100000]],
            'add_to_cart' => ['count' => 380, 'users' => [], 'timestamps' => [1709103600]],
            'begin_checkout' => ['count' => 220, 'users' => [], 'timestamps' => [1709107200]],
            'add_payment_info' => ['count' => 180, 'users' => [], 'timestamps' => [1709110800]],
            'purchase' => ['count' => 150, 'users' => [], 'timestamps' => [1709114400]],
        ],
        '2026-W29' => [
            'view_item' => ['count' => 1350, 'users' => [], 'timestamps' => [1709700000]],
            'add_to_cart' => ['count' => 420, 'users' => [], 'timestamps' => [1709703600]],
            'begin_checkout' => ['count' => 260, 'users' => [], 'timestamps' => [1709707200]],
            'add_payment_info' => ['count' => 210, 'users' => [], 'timestamps' => [1709710800]],
            'purchase' => ['count' => 185, 'users' => [], 'timestamps' => [1709714400]],
        ],
        '2026-W30' => [
            'view_item' => ['count' => 1500, 'users' => [], 'timestamps' => [1710300000]],
            'add_to_cart' => ['count' => 460, 'users' => [], 'timestamps' => [1710303600]],
            'begin_checkout' => ['count' => 290, 'users' => [], 'timestamps' => [1710307200]],
            'add_payment_info' => ['count' => 240, 'users' => [], 'timestamps' => [1710310800]],
            'purchase' => ['count' => 210, 'users' => [], 'timestamps' => [1710314400]],
        ],
    ];

    /**
     * Execute the console command.
     */
    public function handle(CohortFunnelMatrixService $service): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'config' => $this->actionConfig($service),
            'templates' => $this->actionTemplates($service),
            'build' => $this->actionBuild($service),
            'compare' => $this->actionCompare($service),
            'heatmap' => $this->actionHeatmap($service),
            'velocity' => $this->actionVelocity($service),
            'analysis' => $this->actionAnalysis($service),
            'dropoff' => $this->actionDropoff($service),
            'clear-cache' => $this->actionClearCache($service),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Show service configuration.
     */
    private function actionConfig(CohortFunnelMatrixService $service): int
    {
        $summary = $service->configSummary();

        $this->output($summary, 'Cohort × Funnel Matrix Configuration');

        return self::SUCCESS;
    }

    /**
     * List all funnel templates.
     */
    private function actionTemplates(CohortFunnelMatrixService $service): int
    {
        $templates = $service->funnelTemplates();

        $rows = [];
        foreach ($templates as $name => $steps) {
            $rows[] = [
                $name,
                count($steps),
                implode(' → ', $steps),
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode($templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Template', 'Steps', 'Step Sequence'],
            $rows
        );

        return self::SUCCESS;
    }

    /**
     * Build a full cohort × funnel matrix.
     */
    private function actionBuild(CohortFunnelMatrixService $service): int
    {
        $template = $this->option('template');
        $customSteps = $this->option('steps');
        $cohortInput = $this->option('cohorts');

        $steps = $customSteps !== null
            ? explode(',', $customSteps)
            : ($service->funnelTemplate($template) ?? []);

        if ($steps === []) {
            $this->error("Unknown funnel template '{$template}'. Use --steps to define custom steps.");

            return self::FAILURE;
        }

        $cohorts = $cohortInput !== null
            ? explode(',', $cohortInput)
            : array_keys(self::SAMPLE_COHORT_DATA);

        $data = $this->resolveSampleData($template);

        $matrix = $service->buildMatrix($cohorts, $steps, $data);

        $this->output($matrix, 'Cohort × Funnel Matrix');

        return self::SUCCESS;
    }

    /**
     * Compare two cohorts side by side.
     */
    private function actionCompare(CohortFunnelMatrixService $service): int
    {
        $cohortInput = $this->option('cohorts');

        if ($cohortInput === null) {
            $this->error('The --cohorts option is required for compare. Provide exactly 2 cohort labels (comma-separated).');

            return self::FAILURE;
        }

        $cohorts = explode(',', $cohortInput);

        if (count($cohorts) !== 2) {
            $this->error('Compare requires exactly 2 cohort labels. Got: ' . count($cohorts));

            return self::FAILURE;
        }

        $template = $this->option('template');
        $steps = $service->funnelTemplate($template) ?? [];

        if ($steps === []) {
            $this->error("Unknown funnel template '{$template}'.");

            return self::FAILURE;
        }

        $data = $this->resolveSampleData($template);

        $result = $service->compareCohorts($cohorts[0], $cohorts[1], $steps, $data);

        $this->output($result, "Cohort Comparison: {$cohorts[0]} vs {$cohorts[1]}");

        return self::SUCCESS;
    }

    /**
     * Generate a heatmap matrix.
     */
    private function actionHeatmap(CohortFunnelMatrixService $service): int
    {
        $template = $this->option('template');
        $steps = $service->funnelTemplate($template) ?? [];

        if ($steps === []) {
            $this->error("Unknown funnel template '{$template}'.");

            return self::FAILURE;
        }

        $cohortInput = $this->option('cohorts');
        $cohorts = $cohortInput !== null
            ? explode(',', $cohortInput)
            : array_keys(self::SAMPLE_COHORT_DATA);

        $data = $this->resolveSampleData($template);

        $heatmap = $service->heatmap($cohorts, $steps, $data);

        $this->output($heatmap, 'Heatmap Matrix');

        return self::SUCCESS;
    }

    /**
     * Compute velocity index for a cohort.
     */
    private function actionVelocity(CohortFunnelMatrixService $service): int
    {
        $cohortInput = $this->option('cohorts');

        if ($cohortInput === null) {
            $this->error('The --cohorts option is required for velocity. Provide a cohort label.');

            return self::FAILURE;
        }

        $cohortLabel = explode(',', $cohortInput)[0];
        $template = $this->option('template');
        $steps = $service->funnelTemplate($template) ?? [];

        if ($steps === []) {
            $this->error("Unknown funnel template '{$template}'.");

            return self::FAILURE;
        }

        $data = $this->resolveSampleData($template);
        $cohortStepData = $data[$cohortLabel] ?? [];

        $velocity = $service->velocityIndex($cohortLabel, $steps, $cohortStepData);

        $this->output($velocity, "Velocity Index: {$cohortLabel}");

        return self::SUCCESS;
    }

    /**
     * Run step performance analysis.
     */
    private function actionAnalysis(CohortFunnelMatrixService $service): int
    {
        $template = $this->option('template');
        $steps = $service->funnelTemplate($template) ?? [];

        if ($steps === []) {
            $this->error("Unknown funnel template '{$template}'.");

            return self::FAILURE;
        }

        $cohortInput = $this->option('cohorts');
        $cohorts = $cohortInput !== null
            ? explode(',', $cohortInput)
            : array_keys(self::SAMPLE_COHORT_DATA);

        $data = $this->resolveSampleData($template);

        $analysis = $service->stepPerformanceAnalysis($cohorts, $steps, $data);

        $this->output($analysis, 'Step Performance Analysis');

        return self::SUCCESS;
    }

    /**
     * Generate drop-off ranking.
     */
    private function actionDropoff(CohortFunnelMatrixService $service): int
    {
        $template = $this->option('template');
        $steps = $service->funnelTemplate($template) ?? [];

        if ($steps === []) {
            $this->error("Unknown funnel template '{$template}'.");

            return self::FAILURE;
        }

        $cohortInput = $this->option('cohorts');
        $cohorts = $cohortInput !== null
            ? explode(',', $cohortInput)
            : array_keys(self::SAMPLE_COHORT_DATA);

        $data = $this->resolveSampleData($template);

        $ranking = $service->dropoffRanking($cohorts, $steps, $data);

        $this->output($ranking, 'Drop-off Ranking');

        return self::SUCCESS;
    }

    /**
     * Clear cached matrix data.
     */
    private function actionClearCache(CohortFunnelMatrixService $service): int
    {
        $service->clearCache();

        $this->info('Cohort × Funnel Matrix cache cleared.');

        return self::SUCCESS;
    }

    /**
     * Show error for invalid action.
     */
    private function invalidAction(string $action): int
    {
        $valid = 'config, templates, build, compare, heatmap, velocity, analysis, dropoff, clear-cache';
        $this->error("Invalid action: {$action}. Valid actions: {$valid}");

        return self::FAILURE;
    }

    /**
     * Resolve sample data for the given template.
     *
     * @return array<string, array<string, array{count: int, users: list<string>, timestamps: list<int>}>
     */
    private function resolveSampleData(string $template): array
    {
        return match ($template) {
            'purchase' => self::PURCHASE_SAMPLE_DATA,
            default => self::SAMPLE_COHORT_DATA,
        };
    }

    /**
     * Output data — JSON or table format.
     *
     * @param  array<string, mixed>  $data  Data to output
     * @param  string  $title  Output title
     */
    private function output(array $data, string $title): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info("━━━ {$title} ━━━");
        $this->newLine();

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->line("  {$key}:");
                foreach ($value as $k => $v) {
                    if (is_array($v)) {
                        $this->line("    {$k}: " . json_encode($v, JSON_UNESCAPED_SLASHES));
                    } else {
                        $this->line("    {$k}: {$v}");
                    }
                }
            } else {
                $this->line("  {$key}: {$value}");
            }
        }
    }
}
