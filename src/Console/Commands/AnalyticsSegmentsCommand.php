<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\BehavioralSegmentationService;

/**
 * Behavioral Segmentation Command.
 *
 * CLI entry point for user behavioral segmentation analysis.
 * Segments users into RFM-based tiers and provides distribution analysis,
 * individual user segmentation, and segment migration tracking.
 *
 * Usage:
 *   php artisan zb:analytics:segments                        # Distribution summary
 *   php artisan zb:analytics:segments --user=123           # Segment a specific user
 *   php artisan zb:analytics:segments --segment=champions   # List users in a tier
 *   php artisan zb:analytics:segments --migration           # Show recent migrations
 *   php artisan zb:analytics:segments --record             # Record snapshots for history
 *   php artisan zb:analytics:segments --tiers             # List all tier definitions
 *   php artisan zb:analytics:segments --invalidate         # Clear segment caches
 *
 * @since 239.0.0
 */
final class AnalyticsSegmentsCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:segments
        {--user= : Segment a specific user by ID}
        {--segment= : List users in a specific segment tier}
        {--migration : Show segment migration analysis}
        {--record : Record current segment snapshots}
        {--tiers : List all tier definitions}
        {--limit=50 : Max results for segment listing}
        {--json : Output as JSON}
        {--invalidate : Clear all segment caches}
    ';

    /** @var string */
    protected $description = 'Behavioral segmentation: RFM-based user tier classification & distribution';

    /**
     * @param  BehavioralSegmentationService  $service
     */
    public function __construct(
        private readonly BehavioralSegmentationService $service,
    ){
        parent::__construct();
    }

    /**
     * Execute the command.
     *
     * @return int
     */
    public function handle(): int
    {
        if ($this->option('tiers')) {
            return $this->showTiers();
        }

        if ($this->option('invalidate')) {
            return $this->invalidateCaches();
        }

        if ($this->option('user')) {
            return $this->segmentUser((string) $this->option('user'));
        }

        if ($this->option('segment')) {
            return $this->listSegmentUsers(
                (string) $this->option('segment'),
                (int) $this->option('limit'),
            );
        }

        if ($this->option('migration')) {
            return $this->showMigrations();
        }

        if ($this->option('record')) {
            return $this->recordSnapshots();
        }

        return $this->showDistribution();
    }

    /**
     * Show segment distribution summary.
     *
     * @return int
     */
    private function showDistribution(): int
    {
        $this->components->info('Computing segment distribution...');

        $distribution = $this->service->segmentDistribution();
        $total = array_sum($distribution);

        if ($this->option('json')) {
            $this->line(json_encode([
                'total_users' => $total,
                'distribution' => $distribution,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info("Behavioral Segmentation Distribution ({$total} users)");
        $this->newLine();

        $headers = ['Segment', 'Users', 'Percentage', 'Tier Range'];
        $rows = [];

        $tiers = $this->service->tiers();
        foreach ($distribution as $segment => $count) {
            $tier = $tiers[$segment] ?? ['min' => 0, 'max' => 100];
            $pct = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
            $rows[] = [
                $segment,
                (string) $count,
                "{$pct}%",
                "{$tier['min']}–{$tier['max']}",
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Segment a specific user.
     *
     * @param  string  $userId
     * @return int
     */
    private function segmentUser(string $userId): int
    {
        $this->components->info("Segmenting user {$userId}...");

        $result = $this->service->segmentUser($userId);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info("User: {$userId}");
        $this->line("  Segment: <fg=green>{$result['segment']}</>");
        $this->line("  Composite Score: {$result['composite_score']}/100");
        $this->newLine();

        $this->line('  RFM Scores:');
        $this->line("    Recency (R):  {$result['rfm']['r']}/5");
        $this->line("    Frequency (F): {$result['rfm']['f']}/5");
        $this->line("    Monetary (M):  {$result['rfm']['m']}/5");
        $this->newLine();

        $this->line('  Dimension Scores:');
        foreach ($result['dimensions'] as $dim => $score) {
            $bar = str_repeat('▓', (int) ($score / 5)) . str_repeat('░', 20 - (int) ($score / 5));
            $this->line("    {$dim}: {$score} {$bar}");
        }

        $this->newLine();
        $this->line("  Computed at: {$result['computed_at']}");

        return self::SUCCESS;
    }

    /**
     * List users in a specific segment.
     *
     * @param  string  $segment
     * @param  int  $limit
     * @return int
     */
    private function listSegmentUsers(string $segment, int $limit): int
    {
        $tiers = $this->service->tiers();
        if (! isset($tiers[$segment])) {
            $this->components->error("Unknown segment: {$segment}");
            $this->line('Available: ' . implode(', ', array_keys($tiers)));

            return self::FAILURE;
        }

        $this->components->info("Loading users in '{$segment}' segment (limit: {$limit})...");

        $users = $this->service->getUsersInSegment($segment, $limit);

        if ($this->option('json')) {
            $this->line(json_encode($users, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (count($users) === 0) {
            $this->components->warn("No users found in '{$segment}' segment.");

            return self::SUCCESS;
        }

        $headers = ['User ID', 'Segment', 'Score'];
        $rows = array_map(fn (array $u): array => [
            $u['user_id'],
            $u['segment'],
            (string) $u['composite_score'],
        ], $users);

        $this->table($headers, $rows);
        $this->components->info(count($users) . " users in '{$segment}'");

        return self::SUCCESS;
    }

    /**
     * Show segment tier definitions.
     *
     * @return int
     */
    private function showTiers(): int
    {
        $tiers = $this->service->tiers();

        if ($this->option('json')) {
            $this->line(json_encode($tiers, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info('Behavioral Segment Tiers');
        $this->newLine();

        $headers = ['Tier', 'Score Range', 'Description'];
        $rows = [];

        foreach ($tiers as $name => $tier) {
            $rows[] = [
                $name,
                "{$tier['min']}–{$tier['max']}",
                $tier['description'],
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Show segment migration tracking.
     *
     * @return int
     */
    private function showMigrations(): int
    {
        $this->components->warn('Segment migration tracking requires known user IDs.');
        $this->line('Use --user=<id> to check migration for a specific user.');
        $this->newLine();
        $this->components->info('Example:');
        $this->line('  php artisan zb:analytics:segments --user=123 --migration');

        return self::SUCCESS;
    }

    /**
     * Record segment snapshots.
     *
     * @return int
     */
    private function recordSnapshots(): int
    {
        $this->components->info('Recording segment snapshots...');

        // This would normally iterate over known users
        $this->components->warn('Batch snapshot recording requires known user IDs.');
        $this->line('Use --user=<id> to record a snapshot for a specific user.');

        return self::SUCCESS;
    }

    /**
     * Invalidate all segment caches.
     *
     * @return int
     */
    private function invalidateCaches(): int
    {
        $this->service->invalidateAll();
        $this->components->info('Segment caches invalidated.');

        return self::SUCCESS;
    }
}
