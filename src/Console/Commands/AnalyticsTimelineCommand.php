<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventTimelineService;

/**
 * Analytics Timeline Command — inspect event timelines for users and clients.
 *
 * Displays chronological event timelines, session groups, and gap analysis
 * for debugging user journeys and funnel progression.
 *
 * @since 75.0.0
 */
final class AnalyticsTimelineCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:timeline
        {clientId : Client ID or user ID to inspect}
        {--user : Treat argument as user ID (merge all linked client timelines)}
        {--gaps : Show gap detection results}
        {--sessions : Show session-grouped timeline}
        {--json : Output as JSON}
        {--limit=50 : Maximum events to show}';

    /** @var string */
    protected $description = 'Inspect event timeline for a client or user';

    /**
     * Execute the console command.
     */
    public function handle(EventTimelineService $timeline): int
    {
        $identifier = $this->argument('clientId');
        $asUser = $this->option('user');
        $showGaps = $this->option('gaps');
        $showSessions = $this->option('sessions');
        $asJson = $this->option('json');
        $limit = (int) $this->option('limit');

        if (! $timeline->isEnabled()) {
            $this->components->warn('Event Timeline Service is disabled in config.');

            return self::SUCCESS;
        }

        if ($asUser) {
            $events = $timeline->getUserTimeline($identifier, $limit);
            $this->info("User timeline: {$identifier} (" . count($events) . ' events)');
        } else {
            $events = $timeline->getTimeline($identifier, $limit);
            $this->info("Client timeline: {$identifier} (" . count($events) . ' events)');
        }

        if ($asJson) {
            $output = [
                'identifier' => $identifier,
                'type' => $asUser ? 'user' : 'client',
                'count' => count($events),
                'events' => $events,
            ];

            if ($showGaps && ! $asUser) {
                $output['gaps'] = $timeline->detectGaps($identifier);
            }

            if ($showSessions && ! $asUser) {
                $output['sessions'] = $timeline->getSessionGroups($identifier);
            }

            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        // Table output
        if ($events !== []) {
            $rows = array_map(fn (array $e): array => [
                date('Y-m-d H:i:s', $e['timestamp'] ?? 0),
                $e['event_name'] ?? '-',
                $e['category'] ?? '-',
                $e['user_id'] ?? '-',
                implode(', ', $e['providers'] ?? []),
            ], $events);

            $this->table(
                ['Timestamp', 'Event', 'Category', 'User ID', 'Providers'],
                $rows,
            );
        } else {
            $this->components->info('No events found in timeline.');
        }

        // Gap detection
        if ($showGaps && ! $asUser) {
            $gaps = $timeline->detectGaps($identifier);

            $this->newLine();
            $this->info('Gap Detection');

            if ($gaps !== []) {
                $gapRows = array_map(fn (array $g): array => [
                    $g['type'],
                    $g['from'] . ' → ' . $g['to'],
                    $this->formatDuration($g['elapsed_seconds']),
                    $this->formatDuration($g['threshold_seconds']),
                    date('Y-m-d H:i:s', $g['timestamp']),
                ], $gaps);

                $this->table(
                    ['Gap Type', 'Events', 'Elapsed', 'Threshold', 'Since'],
                    $gapRows,
                );
            } else {
                $this->components->info('No gaps detected.');
            }
        }

        // Session groups
        if ($showSessions && ! $asUser) {
            $sessions = $timeline->getSessionGroups($identifier);

            $this->newLine();
            $this->info('Session Groups (' . count($sessions) . ' sessions)');

            if ($sessions !== []) {
                $sessionRows = array_map(fn (array $s): array => [
                    $s['session_id'],
                    date('H:i:s', $s['start_time']),
                    date('H:i:s', $s['end_time']),
                    $s['event_count'],
                    implode(', ', array_column($s['events'], 'event_name')),
                ], $sessions);

                $this->table(
                    ['Session', 'Start', 'End', 'Events', 'Event Names'],
                    $sessionRows,
                );
            }
        }

        return self::SUCCESS;
    }

    /**
     * Format seconds into human-readable duration.
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }

        if ($seconds < 86400) {
            return round($seconds / 3600, 1) . 'h';
        }

        return round($seconds / 86400, 1) . 'd';
    }
}
