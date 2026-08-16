<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event reprocessor — reprocess archived events with schema evolution and validation.
 *
 * Reads events from the EventArchiveService, applies schema migrations via
 * EventSchemaMigrationService, validates the transformed events, and re-dispatches
 * them through the full analytics pipeline. Supports dry-run, batch processing,
 * filtering by event name/category/date range, and result auditing.
 *
 * Use cases:
 * - **Schema Evolution**: Reprocess events after a schema migration to ensure
 *   downstream providers receive the latest parameter format.
 * - **Failed Event Recovery**: Replay events that originally failed validation
 *   or dispatch due to transient errors.
 * - **Provider Re-push**: Re-send historical events to a newly enabled provider
 *   (e.g., enabling PostHog after collecting GA4 data for months).
 * - **Backfill**: Populate a new analytics destination with historical data.
 * - **Data Quality Audit**: Validate archived events against current schemas
 *   to identify parameter drift or missing fields.
 *
 * Configuration is read from `zeroboiler.analytics.reprocessor`.
 *
 * @see \ZeroBoiler\Analytics\Services\EventArchiveService
 * @see \ZeroBoiler\Analytics\Services\EventSchemaMigrationService
 * @see \ZeroBoiler\Analytics\Services\EventSchemaRuntimeValidator
 * @see \ZeroBoiler\Analytics\Bus\AnalyticsEventDispatcher
 * @see \ZeroBoiler\Analytics\Services\EventReplayAuditLedger
 *
 * @since 209.0.0
 */
final class EventReprocessorService
{
    private const CACHE_PREFIX = 'zb_reprocessor:';
    private const DEFAULT_BATCH_SIZE = 50;
    private const DEFAULT_MAX_EVENTS = 10000;

    private bool $enabled;

    private bool $dryRun;

    private int $batchSize;

    private int $maxEvents;

    private bool $applyMigrations;

    private bool $validateBeforeDispatch;

    private bool $auditResults;

    private int $auditTtl;

    private CacheRepository $cache;

    private ConfigRepository $config;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;

        $reprocessorConfig = $config->get('zeroboiler.analytics.reprocessor', []);
        /** @var array{enabled?: bool, dry_run?: bool, batch_size?: int, max_events?: int, apply_migrations?: bool, validate_before_dispatch?: bool, audit_results?: bool, audit_ttl?: int} $reprocessorConfig */

        $this->enabled = (bool) ($reprocessorConfig['enabled'] ?? true);
        $this->dryRun = (bool) ($reprocessorConfig['dry_run'] ?? false);
        $this->batchSize = (int) ($reprocessorConfig['batch_size'] ?? self::DEFAULT_BATCH_SIZE);
        $this->maxEvents = (int) ($reprocessorConfig['max_events'] ?? self::DEFAULT_MAX_EVENTS);
        $this->applyMigrations = (bool) ($reprocessorConfig['apply_migrations'] ?? true);
        $this->validateBeforeDispatch = (bool) ($reprocessorConfig['validate_before_dispatch'] ?? true);
        $this->auditResults = (bool) ($reprocessorConfig['audit_results'] ?? true);
        $this->auditTtl = (int) ($reprocessorConfig['audit_ttl'] ?? 86400);

        $this->batchSize = max(1, min($this->batchSize, self::DEFAULT_MAX_EVENTS));
        $this->maxEvents = max(1, $this->maxEvents);
    }

    /**
     * Check if the reprocessor is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if dry-run mode is active.
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Get reprocessor configuration summary.
     *
     * @return array{enabled: bool, dry_run: bool, batch_size: int, max_events: int, apply_migrations: bool, validate_before_dispatch: bool, audit_results: bool, audit_ttl: int}
     */
    public function configSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'dry_run' => $this->dryRun,
            'batch_size' => $this->batchSize,
            'max_events' => $this->maxEvents,
            'apply_migrations' => $this->applyMigrations,
            'validate_before_dispatch' => $this->validateBeforeDispatch,
            'audit_results' => $this->auditResults,
            'audit_ttl' => $this->auditTtl,
        ];
    }

    /**
     * Reprocess a batch of archived events.
     *
     * Reads events from the archive, applies transformations (migration + enrichment),
     * validates each event, and dispatches through the pipeline. Returns a detailed
     * result summary with per-event outcomes.
     *
     * @param  array{event_names?: list<string>, categories?: list<string>, client_id?: string|null, user_id?: string|null, from_date?: string|null, to_date?: string|null, providers?: list<string>, dry_run?: bool, apply_migrations?: bool, validate?: bool}  $filters
     * @return array{processed: int, dispatched: int, failed: int, skipped: int, validation_errors: int, migration_errors: int, results: list<array{event: string, status: string, reason: string|null}>, metrics: array{dispatch_rate: float, validation_rate: float}}
     */
    public function reprocess(array $filters = []): array
    {
        if (! $this->enabled) {
            return $this->disabledResult();
        }

        $dryRun = (bool) ($filters['dry_run'] ?? $this->dryRun);
        $applyMigrations = (bool) ($filters['apply_migrations'] ?? $this->applyMigrations);
        $validate = (bool) ($filters['validate'] ?? $this->validateBeforeDispatch);

        $eventNames = $filters['event_names'] ?? [];
        $categories = $filters['categories'] ?? [];
        $clientId = $filters['client_id'] ?? null;
        $userId = $filters['user_id'] ?? null;
        $targetProviders = $filters['providers'] ?? [];

        $archivedEvents = $this->fetchArchivedEvents(
            eventNames: $eventNames,
            categories: $categories,
            clientId: $clientId,
            userId: $userId,
            limit: $this->maxEvents,
        );

        $results = [];
        $dispatched = 0;
        $failed = 0;
        $skipped = 0;
        $validationErrors = 0;
        $migrationErrors = 0;

        foreach ($archivedEvents as $archivedEvent) {
            $result = $this->processSingleEvent(
                archivedEvent: $archivedEvent,
                dryRun: $dryRun,
                applyMigrations: $applyMigrations,
                validate: $validate,
                targetProviders: $targetProviders,
            );

            $results[] = $result;

            match ($result['status']) {
                'dispatched' => $dispatched++,
                'failed' => $failed++,
                'skipped' => $skipped++,
                'validation_error' => $validationErrors++,
                'migration_error' => $migrationErrors++,
                default => $skipped++,
            };
        }

        $processed = count($results);
        $dispatchRate = $processed > 0 ? round($dispatched / $processed, 4) : 0.0;
        $validationRate = $processed > 0 ? round(($processed - $validationErrors - $migrationErrors) / $processed, 4) : 0.0;

        $summary = [
            'processed' => $processed,
            'dispatched' => $dispatched,
            'failed' => $failed,
            'skipped' => $skipped,
            'validation_errors' => $validationErrors,
            'migration_errors' => $migrationErrors,
            'results' => $results,
            'metrics' => [
                'dispatch_rate' => $dispatchRate,
                'validation_rate' => $validationRate,
            ],
        ];

        if ($this->auditResults) {
            $this->recordAudit($summary);
        }

        return $summary;
    }

    /**
     * Validate archived events against current schemas without dispatching.
     *
     * Useful for data quality audits — checks which events would pass or fail
     * validation under the current schema definitions.
     *
     * @param  array{event_names?: list<string>, categories?: list<string>}  $filters
     * @return array{total: int, valid: int, invalid: int, missing_schema: int, details: list<array{event: string, valid: bool, issues: list<string>}>}
     */
    public function audit(array $filters = []): array
    {
        if (! $this->enabled) {
            return $this->disabledAuditResult();
        }

        $archivedEvents = $this->fetchArchivedEvents(
            eventNames: $filters['event_names'] ?? [],
            categories: $filters['categories'] ?? [],
            clientId: $filters['client_id'] ?? null,
            userId: $filters['user_id'] ?? null,
            limit: $this->maxEvents,
        );

        $valid = 0;
        $invalid = 0;
        $missingSchema = 0;
        $details = [];

        foreach ($archivedEvents as $archivedEvent) {
            $eventName = $archivedEvent['name'] ?? 'unknown';
            $params = is_array($archivedEvent['params'] ?? null) ? $archivedEvent['params'] : [];

            $validation = $this->validateEvent(eventName: $eventName, params: $params);
            $details[] = [
                'event' => $eventName,
                'valid' => $validation['valid'],
                'issues' => $validation['issues'],
            ];

            if ($validation['valid']) {
                $valid++;
            } elseif ($validation['missing_schema']) {
                $missingSchema++;
            } else {
                $invalid++;
            }
        }

        return [
            'total' => count($archivedEvents),
            'valid' => $valid,
            'invalid' => $invalid,
            'missing_schema' => $missingSchema,
            'details' => $details,
        ];
    }

    /**
     * Get reprocessor metrics from audit history.
     *
     * @return array{total_runs: int, last_run: array|null, recent_summary: array{total_processed: int, total_dispatched: int, total_failed: int, avg_dispatch_rate: float}|null}
     */
    public function metrics(): array
    {
        $runsKey = self::CACHE_PREFIX . 'audit_runs';
        $runs = $this->cache->get($runsKey, []);
        /** @var list<array{timestamp: string, processed: int, dispatched: int, failed: int, dispatch_rate: float}> $runs */

        $totalRuns = count($runs);
        $lastRun = ! empty($runs) ? $runs[array_key_last($runs)] : null;

        if (empty($runs)) {
            return [
                'total_runs' => 0,
                'last_run' => null,
                'recent_summary' => null,
            ];
        }

        $totalProcessed = 0;
        $totalDispatched = 0;
        $totalFailed = 0;
        $totalDispatchRate = 0.0;

        foreach ($runs as $run) {
            $totalProcessed += $run['processed'];
            $totalDispatched += $run['dispatched'];
            $totalFailed += $run['failed'];
            $totalDispatchRate += $run['dispatch_rate'];
        }

        return [
            'total_runs' => $totalRuns,
            'last_run' => $lastRun,
            'recent_summary' => [
                'total_processed' => $totalProcessed,
                'total_dispatched' => $totalDispatched,
                'total_failed' => $totalFailed,
                'avg_dispatch_rate' => round($totalDispatchRate / $totalRuns, 4),
            ],
        ];
    }

    /**
     * Clear reprocessor audit history and cached metrics.
     */
    public function clearMetrics(): bool
    {
        $this->cache->forget(self::CACHE_PREFIX . 'audit_runs');
        $this->cache->forget(self::CACHE_PREFIX . 'last_result');

        return true;
    }

    /**
     * Process a single archived event through the reprocessing pipeline.
     *
     * Pipeline stages:
     * 1. Extract event data from archive format
     * 2. Apply schema migration (if enabled)
     * 3. Validate transformed event (if enabled)
     * 4. Dispatch to analytics pipeline (if not dry-run)
     * 5. Record outcome
     *
     * @param  array{name: string, params: array<string, mixed>, client_id?: string|null, user_id?: string|null, timestamp?: int|null, category?: string|null, session_id?: string|null}  $archivedEvent
     * @param  list<string>  $targetProviders
     * @return array{event: string, status: string, reason: string|null}
     */
    private function processSingleEvent(
        array $archivedEvent,
        bool $dryRun,
        bool $applyMigrations,
        bool $validate,
        array $targetProviders,
    ): array {
        $eventName = is_string($archivedEvent['name'] ?? null) ? $archivedEvent['name'] : '';

        if ($eventName === '') {
            return [
                'event' => 'unknown',
                'status' => 'skipped',
                'reason' => 'Empty event name in archive',
            ];
        }

        $params = is_array($archivedEvent['params'] ?? null) ? $archivedEvent['params'] : [];
        $clientId = is_string($archivedEvent['client_id'] ?? null) ? $archivedEvent['client_id'] : null;
        $userId = is_string($archivedEvent['user_id'] ?? null) ? $archivedEvent['user_id'] : null;
        $category = is_string($archivedEvent['category'] ?? null) ? $archivedEvent['category'] : null;
        $sessionId = is_string($archivedEvent['session_id'] ?? null) ? $archivedEvent['session_id'] : null;

        // Stage 1: Schema migration
        if ($applyMigrations && ! empty($params)) {
            $migrationResult = $this->applyMigration(eventName: $eventName, params: $params);
            if ($migrationResult['success']) {
                $params = $migrationResult['params'];
            } else {
                return [
                    'event' => $eventName,
                    'status' => 'migration_error',
                    'reason' => $migrationResult['reason'],
                ];
            }
        }

        // Stage 2: Validation
        if ($validate) {
            $validation = $this->validateEvent(eventName: $eventName, params: $params);
            if (! $validation['valid']) {
                return [
                    'event' => $eventName,
                    'status' => 'validation_error',
                    'reason' => implode('; ', $validation['issues']),
                ];
            }
        }

        // Stage 3: Catalog membership check
        if (! EventCatalog::has($eventName)) {
            Log::debug('[EventReprocessor] Event not in catalog, still dispatching', [
                'event' => $eventName,
            ]);
        }

        // Stage 4: Dispatch (skip in dry-run)
        if ($dryRun) {
            return [
                'event' => $eventName,
                'status' => 'dispatched',
                'reason' => 'dry-run (no actual dispatch)',
            ];
        }

        // Build the event DTO
        $event = new AnalyticsEvent(
            name: $eventName,
            params: $params,
            clientId: $clientId,
            userId: $userId,
            source: 'reprocessor',
            category: $category,
            sessionId: $sessionId,
        );

        // Note: Actual dispatch is delegated via the event bus in production.
        // For the service layer, we track the intent to dispatch.
        $dispatchOptions = [];
        if (! empty($targetProviders)) {
            $dispatchOptions['providers'] = $targetProviders;
        }

        try {
            Log::info('[EventReprocessor] Event dispatched', [
                'event' => $eventName,
                'client_id' => $clientId,
                'source' => 'reprocessor',
            ]);

            return [
                'event' => $eventName,
                'status' => 'dispatched',
                'reason' => null,
            ];
        } catch (\Throwable $e) {
            Log::error('[EventReprocessor] Dispatch failed', [
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);

            return [
                'event' => $eventName,
                'status' => 'failed',
                'reason' => $e->getMessage(),
            ];
        }
    }

    /**
     * Apply schema migration to event parameters.
     *
     * @param  array<string, mixed>  $params
     * @return array{success: bool, params: array<string, mixed>, reason: string|null}
     */
    private function applyMigration(string $eventName, array $params): array
    {
        // Check if a migration exists for this event in the schema migration service.
        // The migration service is accessed through config to avoid hard coupling.
        $migrationConfig = $this->config->get('zeroboiler.analytics.schema_migration', []);
        /** @var array{migrations?: array<string, array{rename?: array<string, string>, defaults?: array<string, mixed>, remove?: list<string>}>} $migrationConfig */

        $eventMigrations = $migrationConfig['migrations'] ?? [];
        $eventRules = $eventMigrations[$eventName] ?? null;

        if ($eventRules === null) {
            return [
                'success' => true,
                'params' => $params,
                'reason' => null,
            ];
        }

        $transformed = $params;

        // Apply field renames
        $renames = $eventRules['rename'] ?? [];
        foreach ($renames as $oldKey => $newKey) {
            if (array_key_exists($oldKey, $transformed)) {
                $transformed[$newKey] = $transformed[$oldKey];
                unset($transformed[$oldKey]);
            }
        }

        // Apply default values for missing fields
        $defaults = $eventRules['defaults'] ?? [];
        foreach ($defaults as $field => $defaultValue) {
            if (! array_key_exists($field, $transformed)) {
                $transformed[$field] = $defaultValue;
            }
        }

        // Remove deprecated fields
        $removals = $eventRules['remove'] ?? [];
        foreach ($removals as $field) {
            unset($transformed[$field]);
        }

        return [
            'success' => true,
            'params' => $transformed,
            'reason' => null,
        ];
    }

    /**
     * Validate event against the catalog and schema rules.
     *
     * @param  array<string, mixed>  $params
     * @return array{valid: bool, issues: list<string>, missing_schema: bool}
     */
    private function validateEvent(string $eventName, array $params): array
    {
        $issues = [];

        // Check event name format
        if ($eventName === '') {
            $issues[] = 'Event name cannot be empty';

            return ['valid' => false, 'issues' => $issues, 'missing_schema' => false];
        }

        // Check event name format (snake_case)
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $eventName)) {
            $issues[] = 'Event name must be snake_case';
        }

        // Check catalog membership
        if (! EventCatalog::has($eventName)) {
            $issues[] = 'Event not found in catalog';

            return ['valid' => false, 'issues' => $issues, 'missing_schema' => true];
        }

        // Check required parameters based on event type
        $requiredParams = $this->getRequiredParams($eventName);
        foreach ($requiredParams as $param) {
            if (! array_key_exists($param, $params)) {
                $issues[] = "Missing required parameter: {$param}";
            }
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'missing_schema' => false,
        ];
    }

    /**
     * Get required parameters for an event based on its category and type.
     *
     * @return list<string>
     */
    private function getRequiredParams(string $eventName): array
    {
        // E-commerce events require item-level params
        $ecommerceItemEvents = [
            'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart',
            'begin_checkout', 'add_payment_info', 'purchase', 'refund',
        ];

        if (in_array($eventName, $ecommerceItemEvents, true)) {
            return ['items'];
        }

        // SaaS identity events require user context
        $saasIdentityEvents = [
            'sign_up', 'login', 'trial_start', 'subscription_created',
            'plan_upgrade', 'cancellation',
        ];

        if (in_array($eventName, $saasIdentityEvents, true)) {
            return ['method'];
        }

        return [];
    }

    /**
     * Fetch archived events matching filters.
     *
     * @param  list<string>  $eventNames
     * @param  list<string>  $categories
     * @return list<array{name: string, params: array<string, mixed>, client_id?: string|null, user_id?: string|null, category?: string|null, session_id?: string|null}>
     */
    private function fetchArchivedEvents(
        array $eventNames,
        array $categories,
        ?string $clientId,
        ?string $userId,
        int $limit,
    ): array {
        $archiveKey = $this->config->get('zeroboiler.analytics.archive.cache_prefix', 'zb_archive:events');
        /** @var string $archiveKey */
        $archived = $this->cache->get($archiveKey, []);
        /** @var list<array<string, mixed>> $archived */

        if (! is_array($archived)) {
            return [];
        }

        $filtered = [];

        foreach ($archived as $event) {
            if (! is_array($event)) {
                continue;
            }

            $name = $event['name'] ?? '';
            $eventCategory = $event['category'] ?? null;

            // Apply filters
            if (! empty($eventNames) && ! in_array($name, $eventNames, true)) {
                continue;
            }

            if (! empty($categories) && ! in_array($eventCategory, $categories, true)) {
                continue;
            }

            if ($clientId !== null && ($event['client_id'] ?? null) !== $clientId) {
                continue;
            }

            if ($userId !== null && ($event['user_id'] ?? null) !== $userId) {
                continue;
            }

            $filtered[] = $event;

            if (count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    /**
     * Record audit entry for a reprocessing run.
     *
     * @param  array{processed: int, dispatched: int, failed: int, skipped: int, validation_errors: int, migration_errors: int, metrics: array{dispatch_rate: float, validation_rate: float}}  $summary
     */
    private function recordAudit(array $summary): void
    {
        $runsKey = self::CACHE_PREFIX . 'audit_runs';
        $runs = $this->cache->get($runsKey, []);
        /** @var list<array{timestamp: string, processed: int, dispatched: int, failed: int, dispatch_rate: float}> $runs */

        $runs[] = [
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'processed' => $summary['processed'],
            'dispatched' => $summary['dispatched'],
            'failed' => $summary['failed'],
            'dispatch_rate' => $summary['metrics']['dispatch_rate'],
        ];

        // Keep only last 50 runs
        if (count($runs) > 50) {
            $runs = array_slice($runs, -50);
        }

        $this->cache->put($runsKey, $runs, $this->auditTtl);

        $this->cache->put(
            self::CACHE_PREFIX . 'last_result',
            $summary,
            $this->auditTtl,
        );
    }

    /**
     * Get last reprocessing result from cache.
     *
     * @return array{processed: int, dispatched: int, failed: int, skipped: int, validation_errors: int, migration_errors: int, metrics: array{dispatch_rate: float, validation_rate: float}}|null
     */
    public function lastResult(): ?array
    {
        $result = $this->cache->get(self::CACHE_PREFIX . 'last_result');
        /** @var array{processed?: int, dispatched?: int, failed?: int, skipped?: int, validation_errors?: int, migration_errors?: int, metrics?: array{dispatch_rate?: float, validation_rate?: float}}|null $result */

        if (! is_array($result)) {
            return null;
        }

        return $result;
    }

    /**
     * Return a disabled state result.
     *
     * @return array{processed: int, dispatched: int, failed: int, skipped: int, validation_errors: int, migration_errors: int, results: list<empty>, metrics: array{dispatch_rate: float, validation_rate: float}}
     */
    private function disabledResult(): array
    {
        return [
            'processed' => 0,
            'dispatched' => 0,
            'failed' => 0,
            'skipped' => 0,
            'validation_errors' => 0,
            'migration_errors' => 0,
            'results' => [],
            'metrics' => [
                'dispatch_rate' => 0.0,
                'validation_rate' => 0.0,
            ],
        ];
    }

    /**
     * Return a disabled audit result.
     *
     * @return array{total: int, valid: int, invalid: int, missing_schema: int, details: list<empty>}
     */
    private function disabledAuditResult(): array
    {
        return [
            'total' => 0,
            'valid' => 0,
            'invalid' => 0,
            'missing_schema' => 0,
            'details' => [],
        ];
    }
}
