<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsIncidentService;
use ZeroBoiler\Analytics\Services\AnalyticsOnCallRouter;

/**
 * Manage analytics pipeline incidents.
 *
 * Provides a single command for incident lifecycle management:
 * - Run detection cycle (default)
 * - List active incidents
 * - View incident dashboard
 * - Show incident history
 * - Manually resolve an incident
 * - Suppress alerts
 * - View on-call routing configuration
 *
 * Usage:
 *   php artisan zb:analytics:incidents                  # Run detection cycle
 *   php artisan zb:analytics:incidents --dashboard       # Show dashboard
 *   php artisan zb:analytics:incidents --list             # List active incidents
 *   php artisan zb:analytics:incidents --history          # Show history
 *   php artisan zb:analytics:incidents --resolve=INC-xxx  # Resolve an incident
 *   php artisan zb:analytics:incidents --suppress=type:provider --for=3600
 *   php artisan zb:analytics:incidents --on-call          # Show on-call routing
 *   php artisan zb:analytics:incidents --json             # JSON output
 *
 * @since 262.0.0
 */
final class AnalyticsIncidentsCommand extends Command
{
    protected $signature = 'zb:analytics:incidents
        {--dashboard : Show incident dashboard}
        {--list : List active incidents}
        {--history : Show incident history}
        {--resolve= : Resolve an incident by ID}
        {--suppress= : Suppress alerts (format: type:provider)}
        {--for=3600 : Suppression duration in seconds}
        {--reason= : Reason for suppression}
        {--on-call : Show on-call routing config}
        {--json : Output as JSON}
        {--dry-run : Show what would be detected without acting}';

    protected $description = 'Manage analytics pipeline incidents — detect, classify, and resolve';

    private AnalyticsIncidentService $incidentService;

    private ?AnalyticsOnCallRouter $onCallRouter;

    public function handle(AnalyticsIncidentService $incidentService, ?AnalyticsOnCallRouter $onCallRouter = null): int
    {
        $this->incidentService = $incidentService;
        $this->onCallRouter = $onCallRouter;
        $outputJson = (bool) $this->option('json');

        // --resolve
        $resolveId = $this->option('resolve');
        if (is_string($resolveId) && $resolveId !== '') {
            return $this->resolveIncident($resolveId, $outputJson);
        }

        // --suppress
        $suppressSpec = $this->option('suppress');
        if (is_string($suppressSpec) && $suppressSpec !== '') {
            return $this->suppressAlerts($suppressSpec, $outputJson);
        }

        // --on-call
        if ((bool) $this->option('on-call')) {
            return $this->showOnCallConfig($outputJson);
        }

        // --dashboard
        if ((bool) $this->option('dashboard')) {
            return $this->showDashboard($outputJson);
        }

        // --list
        if ((bool) $this->option('list')) {
            return $this->listIncidents($outputJson);
        }

        // --history
        if ((bool) $this->option('history')) {
            return $this->showHistory($outputJson);
        }

        // Default: run detection cycle
        return $this->runDetection($outputJson);
    }

    /**
     * Run a detection cycle.
     */
    private function runDetection(bool $outputJson): int
    {
        if ((bool) $this->option('dry-run')) {
            $this->line('[DRY RUN] Would run detection cycle across ' . $this->incidentService->getMonitoredProviderCount() . ' providers');

            return self::SUCCESS;
        }

        $result = $this->incidentService->runDetectionCycle();

        if ($outputJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('🔍 Incident Detection Cycle');
        $this->line('   Providers checked: <info>' . $result['checked_providers'] . '</info>');
        $this->line('   New incidents: <info>' . $result['new_incidents'] . '</info>');
        $this->line('   Updated incidents: <info>' . $result['updated_incidents'] . '</info>');
        $this->line('   Auto-resolved: <info>' . $result['auto_resolved'] . '</info>');

        if (! empty($result['actions_taken'])) {
            $this->newLine();
            $this->info('Actions taken:');
            foreach ($result['actions_taken'] as $action) {
                $isError = str_contains($action, 'OPENED') || str_contains($action, 'P1');
                $this->line($isError ? "  <fg=red>▶ {$action}</>" : "  ✓ {$action}");
            }
        }

        $dashboard = $this->incidentService->getDashboard();
        $this->newLine();
        $this->line('Active incidents: <info>' . $dashboard['active_count'] . '</info>');
        $this->line('MTTR: <info>' . $this->formatDuration($dashboard['mttr_seconds']) . '</info>');
        $this->line('MTBF: <info>' . $this->formatDuration($dashboard['mtbf_seconds']) . '</info>');

        return self::SUCCESS;
    }

    /**
     * Show the incident dashboard.
     */
    private function showDashboard(bool $outputJson): int
    {
        $dashboard = $this->incidentService->getDashboard();
        $config = $this->incidentService->getConfig();

        if ($outputJson) {
            $this->line(json_encode(['dashboard' => $dashboard, 'config' => $config], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('📊 Incident Response Dashboard');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Active Incidents', (string) $dashboard['active_count']],
                ['P1 Critical', (string) ($dashboard['by_severity']['P1'] ?? 0)],
                ['P2 High', (string) ($dashboard['by_severity']['P2'] ?? 0)],
                ['P3 Medium', (string) ($dashboard['by_severity']['P3'] ?? 0)],
                ['P4 Low', (string) ($dashboard['by_severity']['P4'] ?? 0)],
                ['MTTR', $this->formatDuration($dashboard['mttr_seconds'])],
                ['MTBF', $this->formatDuration($dashboard['mtbf_seconds'])],
                ['Suppressions', (string) $dashboard['suppression_count']],
                ['Monitored Providers', (string) $this->incidentService->getMonitoredProviderCount()],
                ['Detection Interval', $config['detection_interval'] . 's'],
                ['Auto-Remediation', $config['auto_remediation'] ? 'ON' : 'OFF'],
                ['Auto-Resolve After', $this->formatDuration((float) $config['auto_resolve_after'])],
            ],
        );

        if (! empty($dashboard['by_type'])) {
            $this->newLine();
            $this->info('By Type:');
            foreach ($dashboard['by_type'] as $type => $count) {
                $this->line("  {$type}: <info>{$count}</info>");
            }
        }

        if (! empty($dashboard['by_provider'])) {
            $this->newLine();
            $this->info('By Provider:');
            foreach ($dashboard['by_provider'] as $provider => $count) {
                $this->line("  {$provider}: <info>{$count}</info>");
            }
        }

        return self::SUCCESS;
    }

    /**
     * List active incidents.
     */
    private function listIncidents(bool $outputJson): int
    {
        $incidents = $this->incidentService->getActiveIncidents();

        if ($outputJson) {
            $this->line(json_encode($incidents, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($incidents === []) {
            $this->info('✅ No active incidents');

            return self::SUCCESS;
        }

        $this->info('🚨 Active Incidents (' . count($incidents) . ')');
        $this->newLine();

        $this->table(
            ['ID', 'Severity', 'Type', 'Provider', 'Status', 'Signals', 'Age', 'Description'],
            array_map(fn (array $i): array => [
                $i['id'],
                $i['severity'],
                $i['type'],
                $i['provider'],
                $i['status'],
                (string) ($i['signal_count'] ?? 1),
                $this->formatDuration((float) (time() - $i['created_at'])),
                $this->truncate($i['description'], 50),
            ], $incidents),
        );

        return self::SUCCESS;
    }

    /**
     * Show incident history.
     */
    private function showHistory(bool $outputJson): int
    {
        $history = $this->incidentService->getHistory(20);

        if ($outputJson) {
            $this->line(json_encode($history, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($history === []) {
            $this->info('No incident history');

            return self::SUCCESS;
        }

        $this->info('📋 Incident History (last 20)');
        $this->newLine();

        $this->table(
            ['ID', 'Severity', 'Type', 'Provider', 'Duration', 'Resolved'],
            array_map(fn (array $h): array => [
                $h['id'],
                $h['severity'],
                $h['type'],
                $h['provider'],
                $this->formatDuration((float) ($h['duration_seconds'] ?? 0)),
                ($h['resolved_at'] ?? 0) > 0
                    ? date('Y-m-d H:i:s', $h['resolved_at'])
                    : 'N/A',
            ], $history),
        );

        $mttr = $this->incidentService->getMttr();
 $this->newLine();
        $this->line('Mean Time To Resolution: <info>' . $this->formatDuration($mttr) . '</info>');

        return self::SUCCESS;
    }

    /**
     * Resolve an incident.
     */
    private function resolveIncident(string $incidentId, bool $outputJson): int
    {
        $resolved = $this->incidentService->resolveIncident($incidentId);

        if ($outputJson) {
            $this->line(json_encode(['incident_id' => $incidentId, 'resolved' => $resolved], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $resolved ? self::SUCCESS : self::FAILURE;
        }

        if ($resolved) {
            $this->info("✅ Incident {$incidentId} resolved");

            return self::SUCCESS;
        }

        $this->error("Incident {$incidentId} not found");

        return self::FAILURE;
    }

    /**
     * Suppress alerts.
     */
    private function suppressAlerts(string $spec, bool $outputJson): int
    {
        $parts = explode(':', $spec, 2);
        $type = $parts[0] ?? '';
        $provider = $parts[1] ?? '';

        if ($type === '' || $provider === '') {
            $this->error('Invalid suppress format. Use: type:provider (e.g. error_budget_breach:ga4)');

            return self::FAILURE;
        }

        $duration = (int) $this->option('for');
        $reason = $this->option('reason');

        $this->incidentService->suppressAlerts($type, $provider, $duration, is_string($reason) ? $reason : null);

        if ($outputJson) {
            $this->line(json_encode([
                'suppressed' => true,
                'type' => $type,
                'provider' => $provider,
                'duration_seconds' => $duration,
                'reason' => $reason,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("🔇 Alerts suppressed: {$type} on {$provider} for {$duration}s");

        return self::SUCCESS;
    }

    /**
     * Show on-call routing configuration.
     */
    private function showOnCallConfig(bool $outputJson): int
    {
        if ($this->onCallRouter === null) {
            $this->warn('On-call router is not configured. Set ANALYTICS_ON_CALL_ENABLED=true to enable.');

            return self::FAILURE;
        }

        $schedule = $this->onCallRouter->getSchedule();

        if ($outputJson) {
            $this->line(json_encode($schedule, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('📞 On-Call Routing Configuration');
        $this->newLine();

        $this->table(
            ['Severity', 'Channels'],
            array_map(fn (string $sev, array $ch): array => [$sev, implode(', ', $ch)], array_keys($schedule['routing']), array_values($schedule['routing'])),
        );

        $this->newLine();
        $this->line('Level 1 timeout: <info>' . $schedule['level_1_timeout'] . 's</info>');
        $this->line('Level 2 timeout: <info>' . $schedule['level_2_timeout'] . 's</info>');
        $this->line('Rotation: <info>' . ($schedule['enabled'] ? 'EVERY ' . $schedule['rotation_minutes'] . ' min' : 'DISABLED') . '</>');

        return self::SUCCESS;
    }

    /**
     * Format seconds into human-readable duration.
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds <= 0) {
            return 'N/A';
        }

        if ($seconds < 60) {
            return round($seconds) . 's';
        }

        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }

        if ($seconds < 86400) {
            return round($seconds / 3600, 1) . 'h';
        }

        return round($seconds / 86400, 1) . 'd';
    }

    /**
     * Truncate a string to a maximum length.
     */
    private function truncate(string $text, int $max): string
    {
        return strlen($text) > $max ? substr($text, 0, $max - 3) . '...' : $text;
    }
}
