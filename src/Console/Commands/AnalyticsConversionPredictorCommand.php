<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\SaaSConversionPredictorService;

/**
 * Analytics Conversion Predictor — evaluate conversion probability for users.
 *
 * Provides insights into which users are most likely to convert
 * (trial → paid, free → paid) based on behavioral signal scoring.
 *
 * @since 193.0.0
 */
final class AnalyticsConversionPredictorCommand extends Command
{
    protected $signature = 'analytics:predict
        {--user= : Predict for a specific user ID}
        {--top=10 : Show top N prospects when using demo mode}
        {--demo : Run with demo data to illustrate predictor output}
        {--signals= : JSON object of signals for a specific user (e.g. \'{"page_views":15,"onboarding_completed":true}\')}';

    protected $description = 'Predict user conversion probability using behavioral signal scoring';

    /**
     * Execute the console command.
     */
    public function handle(SaaSConversionPredictorService $predictor): int
    {
        if (! $predictor->isEnabled()) {
            $this->warn('Conversion predictor is disabled. Enable it via ANALYTICS_CONVERSION_PREDICTOR_ENABLED=true.');

            return self::SUCCESS;
        }

        // Demo mode
        if ($this->option('demo')) {
            return $this->runDemo($predictor);
        }

        // Specific user prediction
        $userId = $this->option('user');

        if ($userId !== null) {
            return $this->predictUser($predictor, (string) $userId);
        }

        // No user specified — show stats
        $this->showStats($predictor);

        return self::SUCCESS;
    }

    /**
     * Show predictor statistics.
     */
    private function showStats(SaaSConversionPredictorService $predictor): int
    {
        $stats = $predictor->stats();

        $this->info('=== SaaS Conversion Predictor ===');
        $this->newLine();
        $this->line("Status: " . ($stats['enabled'] ? '<info>Enabled</info>' : '<comment>Disabled</comment>'));
        $this->line("Cache TTL: {$stats['cache_ttl']}s");
        $this->line("Positive Signals: {$stats['positive_signal_count']}");
        $this->line("Negative Signals: {$stats['negative_signal_count']}");
        $this->line("Total Signals: {$stats['total_signal_count']}");
        $this->line("Custom Weight Overrides: {$stats['custom_weight_overrides']}");

        $this->newLine();
        $this->info('Available Signals:');

        $this->table(
            ['Signal', 'Weight', 'Category', 'Type'],
            collect($predictor->positiveSignals())
                ->map(fn (array $def, string $name): array => [
                    $name,
                    $def['weight'],
                    $def['category'],
                    '<info>Positive</info>',
                ])
                ->merge(
                    collect($predictor->negativeSignals())
                        ->map(fn (array $def, string $name): array => [
                            $name,
                            $def['weight'],
                            $def['category'],
                            '<comment>Negative</comment>',
                        ]),
                )
                ->values()
                ->toArray(),
        );

        return self::SUCCESS;
    }

    /**
     * Predict conversion for a specific user.
     */
    private function predictUser(SaaSConversionPredictorService $predictor, string $userId): int
    {
        $signalsJson = $this->option('signals');

        if ($signalsJson !== null) {
            $signals = json_decode((string) $signalsJson, true);

            if (! is_array($signals)) {
                $this->error('Invalid JSON for --signals option.');

                return self::FAILURE;
            }
        } else {
            $this->warn('No --signals provided. Predicting with no matched signals (baseline).');
            $signals = [];
        }

        $prediction = $predictor->predict($userId, $signals);

        $this->info("=== Conversion Prediction: {$userId} ===");
        $this->newLine();

        $gradeColor = match ($prediction['grade']) {
            'A+', 'A' => 'info',
            'B+', 'B' => 'comment',
            default => 'error',
        };
        $categoryColor = match ($prediction['category']) {
            'high_intent' => 'info',
            'medium_intent' => 'comment',
            default => 'error',
        };

        $this->line("Score: <{$gradeColor}>{$prediction['score']}</{$gradeColor}> (probability: {$prediction['probability']}%)");
        $this->line("Grade: <{$gradeColor}>{$prediction['grade']}</{$gradeColor}>");
        $this->line("Category: <{$categoryColor}>{$prediction['category']}</{$categoryColor}>");
        $this->line("Matched Positive: " . (count($prediction['matched_positive']) > 0 ? implode(', ', $prediction['matched_positive']) : '<comment>none</comment>'));
        $this->line("Matched Negative: " . (count($prediction['matched_negative']) > 0 ? implode(', ', $prediction['matched_negative']) : '<comment>none</comment>'));

        if (count($prediction['recommendations']) > 0) {
            $this->newLine();
            $this->info('Recommendations:');
            foreach ($prediction['recommendations'] as $rec) {
                $this->line("  • {$rec}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Run demo mode with sample data.
     */
    private function runDemo(SaaSConversionPredictorService $predictor): int
    {
        $top = (int) $this->option('top');

        $demoUsers = [
            'user_001_hot_lead' => [
                'page_views' => 25,
                'feature_used_count' => 7,
                'onboarding_completed' => true,
                'first_value_moment' => true,
                'session_count_7d' => 8,
                'last_session_hours_ago' => 2,
                'team_invited' => true,
                'referral_shared' => false,
                'form_submitted' => true,
                'search_count' => 3,
                'error_count_7d' => 0,
                'support_tickets' => 0,
                'pages_per_session' => 4.2,
            ],
            'user_002_warm_lead' => [
                'page_views' => 12,
                'feature_used_count' => 4,
                'onboarding_completed' => true,
                'first_value_moment' => false,
                'session_count_7d' => 5,
                'last_session_hours_ago' => 12,
                'team_invited' => false,
                'referral_shared' => false,
                'form_submitted' => true,
                'search_count' => 1,
                'error_count_7d' => 1,
                'support_tickets' => 0,
                'pages_per_session' => 2.5,
            ],
            'user_003_cold_lead' => [
                'page_views' => 3,
                'feature_used_count' => 1,
                'onboarding_completed' => false,
                'first_value_moment' => false,
                'session_count_7d' => 1,
                'last_session_hours_ago' => 96,
                'team_invited' => false,
                'referral_shared' => false,
                'form_submitted' => false,
                'search_count' => 0,
                'error_count_7d' => 4,
                'support_tickets' => 1,
                'pages_per_session' => 1.0,
            ],
            'user_004_browsing' => [
                'page_views' => 18,
                'feature_used_count' => 2,
                'onboarding_completed' => false,
                'first_value_moment' => false,
                'session_count_7d' => 6,
                'last_session_hours_ago' => 4,
                'team_invited' => false,
                'referral_shared' => false,
                'form_submitted' => false,
                'search_count' => 5,
                'error_count_7d' => 0,
                'support_tickets' => 0,
                'pages_per_session' => 3.0,
            ],
            'user_005_new_signups' => [
                'page_views' => 1,
                'feature_used_count' => 0,
                'onboarding_completed' => false,
                'first_value_moment' => false,
                'session_count_7d' => 1,
                'last_session_hours_ago' => 0.5,
                'team_invited' => false,
                'referral_shared' => false,
                'form_submitted' => false,
                'search_count' => 0,
                'error_count_7d' => 0,
                'support_tickets' => 0,
                'pages_per_session' => 1.0,
            ],
        ];

        // Convert event summaries to signal maps
        $userSignals = [];
        foreach ($demoUsers as $id => $summary) {
            $userSignals[$id] = $predictor->buildSignalMap($summary);
        }

        $prospects = $predictor->topProspects($userSignals, $top);

        $this->info('=== Conversion Predictor Demo (Top ' . $top . ' Prospects) ===');
        $this->newLine();

        $rows = [];
        foreach ($prospects as $p) {
            $gradeTag = match ($p['grade']) {
                'A+', 'A' => '<info>' . $p['grade'] . '</info>',
                'B+', 'B' => '<comment>' . $p['grade'] . '</comment>',
                default => $p['grade'],
            };
            $rows[] = [
                $p['user_id'],
                (string) $p['probability'] . '%',
                $gradeTag,
                $p['category'],
                (string) count($p['matched_positive']) . '+' . (string) count($p['matched_negative']) . '-',
            ];
        }

        $this->table(
            ['User', 'Probability', 'Grade', 'Category', 'Signals (+/-)'],
            $rows,
        );

        // Show detailed breakdown for top prospect
        if (count($prospects) > 0) {
            $topUser = $prospects[0];
            $prediction = $predictor->predict($topUser['user_id'], $userSignals[$topUser['user_id']]);

            $this->newLine();
            $this->info("--- Detailed: {$topUser['user_id']} ---");

            $signalRows = [];
            foreach ($prediction['signal_breakdown'] as $name => $detail) {
                $matched = $detail['matched'] ? '<info>✓</info>' : '<comment>✗</comment>';
                $signalRows[] = [
                    $matched,
                    $name,
                    (string) $detail['weight'],
                    $detail['label'],
                    $detail['category'],
                ];
            }

            $this->table(
                ['Match', 'Signal', 'Weight', 'Description', 'Category'],
                $signalRows,
            );

            if (count($prediction['recommendations']) > 0) {
                $this->newLine();
                $this->info('Recommendations:');
                foreach ($prediction['recommendations'] as $rec) {
                    $this->line("  • {$rec}");
                }
            }
        }

        return self::SUCCESS;
    }
}
