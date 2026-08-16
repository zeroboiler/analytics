<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\DataQualityScoringEngine;
use ZeroBoiler\Analytics\Services\EventCorrelationMatrixService;
use ZeroBoiler\Analytics\Services\EventReplayAuditTrailService;
use ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsBridge;

/**
 * Analytics Intelligence Command.
 *
 * Comprehensive diagnostic and intelligence command that reports on:
 * - Event correlation matrix (top correlated pairs)
 * - Data quality scores across categories
 * - Feature flag bridge status
 * - Replay audit trail statistics
 * - Event catalog coverage summary
 *
 * Provides a unified view of analytics intelligence for admins.
 *
 * @since 203.0.0
 */
final class AnalyticsIntelligenceCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:intelligence
                            {--action=overview : Action to perform (overview|quality|correlation|replay-audit|feature-flags)}
                            {--event= : Specific event name for focused analysis}
                            {--limit=10 : Max results to display}
                            {--format=text : Output format (text|json)}';

    /** @var string */
    protected $description = 'Analytics intelligence: correlation, quality scoring, replay audit, and feature flag analysis';

    /**
     * Execute the console command.
     */
    public function handle(
        DataQualityScoringEngine $qualityEngine,
        EventCorrelationMatrixService $correlationService,
        EventReplayAuditTrailService $auditTrail,
        FeatureFlagAnalyticsBridge $flagBridge,
    ): int {
        $action = $this->option('action');
        $format = $this->option('format');

        return match ($action) {
            'quality' => $this->reportQuality($qualityEngine, $format),
            'correlation' => $this->reportCorrelation($correlationService, $format),
            'replay-audit' => $this->reportReplayAudit($auditTrail, $format),
            'feature-flags' => $this->reportFeatureFlags($flagBridge, $format),
            default => $this->reportOverview($qualityEngine, $correlationService, $auditTrail, $flagBridge, $format),
        };
    }

    /**
     * Report full intelligence overview.
     */
    private function reportOverview(
        DataQualityScoringEngine $qualityEngine,
        EventCorrelationMatrixService $correlationService,
        EventReplayAuditTrailService $auditTrail,
        FeatureFlagAnalyticsBridge $flagBridge,
        string $format,
    ): int {
        $this->printHeader('ZeroBoiler Analytics Intelligence v' . AnalyticsEvent::VERSION);

        // Data Quality Summary
        $qualitySummary = $qualityEngine->diagnosticSummary();
        $this->section('Data Quality Engine');
        $this->info("  Weights: " . json_encode($qualitySummary['weights']));
        $this->info("  Freshness: {$qualitySummary['freshness_threshold']}s");
        $this->info("  Categories: " . implode(', ', $qualitySummary['categories']));

        // Sample quality score
        $sampleEvent = new AnalyticsEvent(
            name: 'purchase',
            params: ['transaction_id' => 'TXN-001', 'value' => 99.99, 'currency' => 'USD', 'item_id' => 'SKU-123'],
            clientId: 'test-client',
            userId: '1',
            category: 'ecommerce',
        );
        $score = $qualityEngine->scoreEvent($sampleEvent);
        $this->info("  Sample 'purchase' score: {$score['score']}/100 (Grade {$score['grade']})");

        // Correlation Matrix Summary
        $correlationSummary = $correlationService->diagnosticSummary();
        $this->section('Event Correlation Matrix');
        $this->info("  Window: {$correlationSummary['default_window']}s");
        $this->info("  Min Sample: {$correlationSummary['min_sample_size']}");
        $this->info("  Cache TTL: {$correlationSummary['cache_ttl']}s");

        // Replay Audit Summary
        $auditStats = $auditTrail->statistics();
        $this->section('Replay Audit Trail');
        $this->info("  Total Entries: {$auditStats['total_entries']}");
        $this->info("  Success Rate: {$auditStats['success_rate']}%");
        $this->info("  Events Replayed: {$auditStats['total_events_replayed']}");
        if ($auditStats['avg_duration_ms'] !== null) {
            $this->info("  Avg Duration: {$auditStats['avg_duration_ms']}ms");
        }

        // Feature Flag Bridge Summary
        $flagSummary = $flagBridge->diagnosticSummary();
        $this->section('Feature Flag Analytics Bridge');
        $this->info("  Registered Mappings: {$flagSummary['registered_mappings']}");
        $this->info("  Dedup TTL: {$flagSummary['dedup_ttl']}s");

        // Event Catalog Summary
        $this->section('Event Catalog Coverage');
        $catalog = EventCatalog::summary();
        $this->info("  Total Events: {$catalog['total_events']}");
        $this->info("  Categories: " . count($catalog['categories']));
        foreach ($catalog['categories'] as $cat => $events) {
            $count = count($events);
            $this->line("    {$cat}: {$count} events");
        }

        $this->printFooter();

        return self::SUCCESS;
    }

    /**
     * Report data quality scores.
     */
    private function reportQuality(DataQualityScoringEngine $qualityEngine, string $format): int
    {
        $this->printHeader('Data Quality Analysis');

        $eventName = $this->option('event');
        $eventNames = $eventName !== null ? [$eventName] : ['purchase', 'sign_up', 'page_view', 'scroll_depth', 'error'];

        foreach ($eventNames as $name) {
            $category = match (true) {
                in_array($name, ['purchase', 'add_to_cart', 'view_item', 'refund'], true) => 'ecommerce',
                in_array($name, ['sign_up', 'login', 'trial_start', 'cancellation'], true) => 'saas',
                default => 'engagement',
            };

            $params = match ($name) {
                'purchase' => ['transaction_id' => 'TXN-001', 'value' => 99.99, 'currency' => 'USD', 'item_id' => 'SKU-123'],
                'sign_up' => ['plan' => 'pro', 'period' => 'monthly'],
                default => [],
            };

            $event = new AnalyticsEvent(
                name: $name,
                params: $params,
                clientId: 'quality-test-client',
                userId: '1',
                category: $category,
            );

            $score = $qualityEngine->scoreEvent($event);

            $this->info("  {$name}");
            $this->line("    Score: {$score['score']}/100 ({$score['grade']})");

            foreach ($score['dimensions'] as $dim => $data) {
                $bar = $this->scoreBar($data['score']);
                $this->line("    {$dim}: {$bar} {$data['score']} - {$data['details']}");
            }

            foreach ($score['issues'] as $issue) {
                $this->warn("    [{$issue['severity']}] {$issue['dimension']}: {$issue['message']}");
            }

            $this->newLine();
        }

        $this->printFooter();

        return self::SUCCESS;
    }

    /**
     * Report event correlation analysis.
     */
    private function reportCorrelation(EventCorrelationMatrixService $correlationService, string $format): int
    {
        $this->printHeader('Event Correlation Analysis');

        $event = $this->option('event');
        $limit = (int) $this->option('limit');

        $topPairs = $correlationService->topCorrelations(
            limit: $limit,
            event: $event !== null ? $event : null,
        );

        if ($topPairs === []) {
            $this->warn('  No correlated pairs found. Ensure events have been tracked.');
        } else {
            $this->info("  Top {$limit} Correlated Event Pairs:");
            $this->newLine();

            foreach ($topPairs as $pair) {
                $correlation = $pair['correlation'];
                $significance = $pair['significance'];
                $label = $correlation > 0 ? 'positive' : 'negative';
                $indicator = $correlation > 0.5 ? '🔗' : ($correlation > 0.2 ? '↗️' : '→');

                $this->line("  {$indicator} {$pair['event_a']} ↔ {$pair['event_b']}");
                $this->line("    r={$correlation} ({$significance}, {$label})");
            }
        }

        // Conversion correlation example
        $this->newLine();
        $this->section('Conversion Correlation Sample');
        $conversion = $correlationService->conversionCorrelation('page_view', 'purchase');
        $this->line("  page_view → purchase");
        $this->line("    Lift: {$conversion['lift']}x");
        $this->line("    Confidence: " . ($conversion['confidence'] * 100) . '%');
        $this->line("    Interpretation: {$conversion['interpretation']}");

        $this->printFooter();

        return self::SUCCESS;
    }

    /**
     * Report replay audit trail.
     */
    private function reportReplayAudit(EventReplayAuditTrailService $auditTrail, string $format): int
    {
        $this->printHeader('Replay Audit Trail');

        $stats = $auditTrail->statistics();

        $this->info("  Total Replays: {$stats['total_entries']}");
        $this->info("  Success Rate: {$stats['success_rate']}%");

        if ($stats['avg_duration_ms'] !== null) {
            $this->info("  Avg Duration: {$stats['avg_duration_ms']}ms");
        }

        $this->newLine();
        $this->section('By Type');
        foreach ($stats['by_type'] as $type => $count) {
            $this->line("  {$type}: {$count}");
        }

        $this->section('By Status');
        foreach ($stats['by_status'] as $status => $count) {
            $label = $status === 'success' ? '<info>' . $status . '</info>' : $status;
            $this->line("  {$label}: {$count}");
        }

        $this->section('By Source');
        foreach ($stats['by_source'] as $source => $count) {
            $this->line("  {$source}: {$count}");
        }

        // Recent entries
        $this->newLine();
        $this->section('Recent Entries');
        $recent = $auditTrail->listEntries(['limit' => 5]);

        foreach ($recent['entries'] as $entry) {
            $id = $entry['id'] ?? 'unknown';
            $timestamp = $entry['timestamp'] ?? 'unknown';
            $status = $entry['status'] ?? 'unknown';
            $type = $entry['type'] ?? 'unknown';
            $eventCount = $entry['event_count'] ?? 0;

            $this->line("  [{$timestamp}] {$id}");
            $this->line("    Type: {$type}, Events: {$eventCount}, Status: {$status}");
        }

        $this->printFooter();

        return self::SUCCESS;
    }

    /**
     * Report feature flag bridge status.
     */
    private function reportFeatureFlags(FeatureFlagAnalyticsBridge $flagBridge, string $format): int
    {
        $this->printHeader('Feature Flag Analytics Bridge');

        $summary = $flagBridge->diagnosticSummary();
        $mappings = $flagBridge->getMappings();

        $this->info("  Registered Mappings: {$summary['registered_mappings']}");
        $this->info("  Max Mappings: {$summary['max_mappings']}");
        $this->info("  Dedup TTL: {$summary['dedup_ttl']}s");

        if ($mappings !== []) {
            $this->newLine();
            $this->section('Active Mappings');

            foreach ($mappings as $flagKey => $mapping) {
                $eventName = $mapping['event_name'];
                $paramCount = count($mapping['params']);
                $this->line("  {$flagKey} → {$eventName}");
                if ($paramCount > 0) {
                    $this->line("    Default params: {$paramCount}");
                }
            }
        } else {
            $this->warn('  No flag mappings registered.');
            $this->line('  Use $bridge->registerMapping("flag_key", "event_name") to add mappings.');
        }

        // Test evaluation tracking
        $this->newLine();
        $this->section('Sample Evaluation');
        $evaluated = $flagBridge->trackEvaluation(
            flagKey: 'new_dashboard',
            variant: true,
            userId: 'test_user_1',
            clientId: 'test_client',
        );

        if ($evaluated !== null) {
            $this->info("  Tracked: {$evaluated->name}");
            $this->line("  Flag: {$evaluated->params['flag_key']}");
            $this->line("  Variant: " . var_export($evaluated->params['variant'], true));
        } else {
            $this->warn('  Evaluation deduplicated (already tracked).');
        }

        $this->printFooter();

        return self::SUCCESS;
    }

    /**
     * Print command header.
     */
    private function printHeader(string $title): void
    {
        $this->newLine();
        $this->info("╔══════════════════════════════════════════════════════════╗");
        $this->info("║  {$this->padCenter($title, 56)}║");
        $this->info("╚══════════════════════════════════════════════════════════╝");
        $this->newLine();
    }

    /**
     * Print section header.
     */
    private function section(string $title): void
    {
        $this->newLine();
        $this->comment("  ── {$title} ──");
    }

    /**
     * Print command footer.
     */
    private function printFooter(): void
    {
        $this->newLine();
        $this->comment('  ZeroBoiler Analytics Intelligence — Industry-Standard SaaS');
    }

    /**
     * Center text within a fixed width.
     */
    private function padCenter(string $text, int $width): string
    {
        $len = mb_strlen($text);
        $padL = (int) floor(($width - $len) / 2);
        $padR = $width - $len - $padL;

        return str_repeat(' ', max(0, $padL)) . $text . str_repeat(' ', max(0, $padR));
    }

    /**
     * Generate a visual score bar.
     */
    private function scoreBar(float $score): string
    {
        $filled = (int) round($score / 5);
        $empty = 20 - $filled;

        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }
}
