<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsCleanRoomService;

/**
 * Analytics Clean Room management command.
 *
 * CLI for managing privacy data clean rooms — creating agreements,
 * submitting sketches, executing queries, and viewing audit trails.
 *
 * @since 198.0.0
 */
final class AnalyticsCleanRoomCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:clean-room
        {action : Action to perform (create|list|revoke|submit|query|audit|stats|validate|flush)}
        {--id= : Agreement ID (for create/revoke/submit/query)}
        {--participants= : Comma-separated participant IDs (for create)}
        {--scope= : Comma-separated scope items (for create)}
        {--dimensions= : Comma-separated dimension names (for create)}
        {--participant= : Single participant ID (for submit)}
        {--sketch= : JSON sketch data (for submit)}
        {--query-type= : Query type (for query)}
        {--query-params= : JSON query parameters (for query)}
        {--limit=100 : Audit trail limit}
        {--json : Output as JSON}';

    /** @var string */
    protected $description = 'Privacy data clean room management — agreements, sketches, queries, audit';

    /**
     * Execute the console command.
     */
    public function handle(AnalyticsCleanRoomService $service): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'create' => $this->createAgreement($service),
            'list' => $this->listAgreements($service),
            'revoke' => $this->revokeAgreement($service),
            'submit' => $this->submitSketch($service),
            'query' => $this->executeQuery($service),
            'audit' => $this->showAudit($service),
            'stats' => $this->showStats($service),
            'validate' => $this->validateConfig($service),
            'flush' => $this->flushData($service),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Create a new clean room agreement.
     */
    private function createAgreement(AnalyticsCleanRoomService $service): int
    {
        $id = $this->option('id');
        if (! is_string($id) || $id === '') {
            $this->error('Agreement ID is required (--id=).');

            return self::FAILURE;
        }

        $participantsRaw = $this->option('participants');
        if (! is_string($participantsRaw) || $participantsRaw === '') {
            $this->error('Participants are required (--participants=participant_a,participant_b).');

            return self::FAILURE;
        }

        $participants = array_map('trim', explode(',', $participantsRaw));

        $scopeRaw = $this->option('scope');
        $scope = is_string($scopeRaw) && $scopeRaw !== ''
            ? array_map('trim', explode(',', $scopeRaw))
            : ['event_counts'];

        $dimensionsRaw = $this->option('dimensions');
        $dimensions = is_string($dimensionsRaw) && $dimensionsRaw !== ''
            ? array_map('trim', explode(',', $dimensionsRaw))
            : [];

        try {
            $agreement = $service->createAgreement($id, $participants, [
                'scope' => $scope,
                'dimensions' => $dimensions,
                'allowed_aggregations' => ['count', 'sum', 'avg', 'cohort_overlap', 'frequency', 'funnel', 'histogram'],
            ]);

            if ($this->option('json')) {
                $this->line(json_encode($agreement, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                $this->info("Clean room agreement created: {$id}");
                $this->table(
                    ['Property', 'Value'],
                    [
                        ['Agreement ID', $agreement['agreement_id']],
                        ['Participants', implode(', ', $agreement['participants'])],
                        ['Scope', implode(', ', $agreement['scope'])],
                        ['Dimensions', implode(', ', $agreement['dimensions'])],
                        ['K-Anonymity', (string) $agreement['k_anonymity']],
                        ['Status', $agreement['status']],
                        ['Created At', $agreement['created_at']],
                        ['Expires At', $agreement['expires_at']],
                    ],
                );
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to create agreement: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * List active clean room agreements.
     */
    private function listAgreements(AnalyticsCleanRoomService $service): int
    {
        $agreements = $service->listAgreements();

        if ($this->option('json')) {
            $this->line(json_encode($agreements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } elseif ($agreements === []) {
            $this->info('No active clean room agreements.');
        } else {
            $this->info(count($agreements) . ' active agreement(s):');
            $this->table(
                ['ID', 'Participants', 'Dimensions', 'Status', 'Created', 'Expires'],
                array_map(static fn (array $a): array => [
                    $a['agreement_id'],
                    implode(', ', $a['participants']),
                    implode(', ', $a['dimensions']),
                    $a['status'],
                    $a['created_at'],
                    $a['expires_at'],
                ], $agreements),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Revoke a clean room agreement.
     */
    private function revokeAgreement(AnalyticsCleanRoomService $service): int
    {
        $id = $this->option('id');
        if (! is_string($id) || $id === '') {
            $this->error('Agreement ID is required (--id=).');

            return self::FAILURE;
        }

        $revoked = $service->revokeAgreement($id);

        if ($revoked) {
            $this->info("Agreement '{$id}' revoked.");
        } else {
            $this->error("Agreement '{$id}' not found.");
        }

        return $revoked ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Submit a sketch for a participant.
     */
    private function submitSketch(AnalyticsCleanRoomService $service): int
    {
        $id = $this->option('id');
        if (! is_string($id) || $id === '') {
            $this->error('Agreement ID is required (--id=).');

            return self::FAILURE;
        }

        $participant = $this->option('participant');
        if (! is_string($participant) || $participant === '') {
            $this->error('Participant ID is required (--participant=).');

            return self::FAILURE;
        }

        $sketchJson = $this->option('sketch');
        if (! is_string($sketchJson) || $sketchJson === '') {
            $this->error('Sketch data is required (--sketch=\'{"event_counts": {...}}\').');

            return self::FAILURE;
        }

        try {
            /** @var array<string, mixed> $sketch */
            $sketch = json_decode($sketchJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->error("Invalid sketch JSON: {$e->getMessage()}");

            return self::FAILURE;
        }

        try {
            $result = $service->submitSketch($id, $participant, $sketch);

            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                $this->info("Sketch submitted for participant '{$participant}'.");
                $this->table(
                    ['Property', 'Value'],
                    [
                        ['Status', $result['status']],
                        ['Participant', $result['participant']],
                        ['Accepted Dimensions', (string) $result['accepted_dimensions']],
                        ['Rejected Dimensions', implode(', ', $result['rejected_dimensions'])],
                    ],
                );
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to submit sketch: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Execute a clean room query.
     */
    private function executeQuery(AnalyticsCleanRoomService $service): int
    {
        $id = $this->option('id');
        if (! is_string($id) || $id === '') {
            $this->error('Agreement ID is required (--id=).');

            return self::FAILURE;
        }

        $queryType = $this->option('query-type');
        if (! is_string($queryType) || $queryType === '') {
            $this->error('Query type is required (--query-type=count).');

            return self::FAILURE;
        }

        $paramsJson = $this->option('query-params');
        $params = [];
        if (is_string($paramsJson) && $paramsJson !== '') {
            /** @var array<string, mixed> $params */
            $params = json_decode($paramsJson, true, 512, JSON_THROW_ON_ERROR) ?? [];
        }

        try {
            $result = $service->executeQuery($id, $queryType, $params);

            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                $this->info("Query executed: {$queryType}");
                $this->table(
                    ['Property', 'Value'],
                    [
                        ['Status', $result['status']],
                        ['Query Type', $result['query_type']],
                        ['K-Anonymity Applied', $result['k_anonymity_applied'] ? 'Yes' : 'No'],
                        ['Privacy Noise Applied', $result['privacy_noise_applied'] ? 'Yes' : 'No'],
                        ['Computed At', $result['computed_at']],
                    ],
                );
                $this->line("\nResult:");
                $this->line(json_encode($result['result'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Query failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Show clean room audit trail.
     */
    private function showAudit(AnalyticsCleanRoomService $service): int
    {
        $limit = (int) $this->option('limit');
        $auditTrail = $service->getAuditTrail($limit);

        if ($this->option('json')) {
            $this->line(json_encode($auditTrail, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } elseif ($auditTrail === []) {
            $this->info('No audit entries found.');
        } else {
            $this->info(count($auditTrail) . ' audit entries:');
            $this->table(
                ['Timestamp', 'Action', 'Agreement', 'Participant', 'Details'],
                array_map(static fn (array $entry): array => [
                    $entry['timestamp'],
                    $entry['action'],
                    $entry['agreement_id'],
                    $entry['participant_id'] ?? '-',
                    json_encode($entry['metadata'], JSON_UNESCAPED_SLASHES),
                ], $auditTrail),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Show clean room statistics.
     */
    private function showStats(AnalyticsCleanRoomService $service): int
    {
        $stats = $service->stats();

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['Property', 'Value'],
                [
                    ['Enabled', $stats['enabled'] ? 'Yes' : 'No'],
                    ['K-Anonymity', (string) $stats['k_anonymity']],
                    ['Active Agreements', "{$stats['active_agreements']} / {$stats['max_agreements']}"],
                    ['Query Rate Limit', "{$stats['query_rate_limit']}/hour"],
                    ['Differential Privacy', $stats['differential_privacy'] ? 'Enabled' : 'Disabled'],
                    ['Privacy Budget (ε)', (string) $stats['privacy_budget']],
                    ['Agreement TTL', $stats['agreement_ttl'] . ' seconds'],
                    ['Audit Entries', (string) $stats['audit_entries']],
                ],
            );
        }

        return self::SUCCESS;
    }

    /**
     * Validate clean room configuration.
     */
    private function validateConfig(AnalyticsCleanRoomService $service): int
    {
        $validation = $service->validateConfig();

        if ($this->option('json')) {
            $this->line(json_encode($validation, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            if ($validation['valid']) {
                $this->info('✓ Clean room configuration is valid.');
            } else {
                $this->error('✗ Clean room configuration has errors:');
                foreach ($validation['errors'] as $error) {
                    $this->line("  • {$error}");
                }
            }

            if ($validation['warnings'] !== []) {
                $this->warn('Warnings:');
                foreach ($validation['warnings'] as $warning) {
                    $this->line("  ⚠ {$warning}");
                }
            }
        }

        return $validation['valid'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Flush all clean room data.
     */
    private function flushData(AnalyticsCleanRoomService $service): int
    {
        if (! $this->confirm('This will remove all clean room agreements, sketches, and audit data. Continue?')) {
            return self::SUCCESS;
        }

        $service->flush();
        $this->info('All clean room data flushed.');

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     */
    private function invalidAction(string $action): int
    {
        $this->error("Invalid action '{$action}'. Use: create, list, revoke, submit, query, audit, stats, validate, flush.");

        return self::FAILURE;
    }
}
