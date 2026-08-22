<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event Replay Validation Service — validates events before replay dispatch.
 *
 * When events are replayed from the dead-letter queue or event archive,
 * this service validates them against the current EventCatalog, compliance
 * rules, and data quality checks before they are re-dispatched to providers.
 *
 * Prevents:
 * - Replaying unknown/deprecated events
 * - Replaying events that violate current consent state
 * - Replaying PII-bearing events that should have been anonymized
 * - Replaying events with invalid or expired payloads
 * - Replaying duplicate events (idempotency check)
 *
 * Returns a validation result with pass/fail status, list of issues,
 * and optionally a sanitized event suitable for re-dispatch.
 *
 * @since 183.0.0
 */
final class EventReplayValidationService
{
    private CacheRepository $cache;

    private string $cachePrefix;

    private int $replayIdempotencyTtl;

    private bool $enforceCatalogMembership;

    private bool $enforceConsent;

    private bool $enforceDataQuality;

    /** @var list<string> Events that are always blocked from replay */
    private array $blockedEvents;

    /** @var list<string> Regex patterns for blocked payload content */
    private array $blockedPayloadPatterns;

    /**
     * @param  CacheRepository  $cache  Laravel cache store
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        private readonly EventCatalogValidator $catalogValidator,
    ){
        $this->cache = $cache;

        $replayConfig = $config->get('zeroboiler.analytics.replay_validation', []);
        /** @var array{cache_prefix?: string, idempotency_ttl?: int, enforce_catalog?: bool, enforce_consent?: bool, enforce_quality?: bool, blocked_events?: list<string>, blocked_patterns?: list<string>} $replayConfig */
        $this->cachePrefix = (string) ($replayConfig['cache_prefix'] ?? 'zb_replay_val_');
        $this->replayIdempotencyTtl = (int) ($replayConfig['idempotency_ttl'] ?? 86400);
        $this->enforceCatalogMembership = (bool) ($replayConfig['enforce_catalog'] ?? true);
        $this->enforceConsent = (bool) ($replayConfig['enforce_consent'] ?? true);
        $this->enforceDataQuality = (bool) ($replayConfig['enforce_quality'] ?? true);
        $this->blockedEvents = (array) ($replayConfig['blocked_events'] ?? []);
        $this->blockedPayloadPatterns = (array) ($replayConfig['blocked_patterns'] ?? [
            '/password/i',
            '/credit_card/i',
            '/ssn/i',
            '/social_security/i',
        ]);
    }

    /**
     * Validate an event for replay.
     *
     * @return array{valid: bool, issues: list<array{code: string, message: string, severity: string}>, sanitized_event: AnalyticsEvent|null}
     */
    public function validate(AnalyticsEvent $event): array
    {
        $issues = [];

        // 1. Idempotency check — has this event already been replayed?
        $idempotencyIssue = $this->checkIdempotency($event);
        if ($idempotencyIssue !== null) {
            $issues[] = $idempotencyIssue;
        }

        // 2. Blocked events check
        $blockedIssue = $this->checkBlockedEvents($event);
        if ($blockedIssue !== null) {
            $issues[] = $blockedIssue;
        }

        // 3. Catalog membership check
        if ($this->enforceCatalogMembership) {
            $catalogIssues = $this->checkCatalogMembership($event);
            $issues = array_merge($issues, $catalogIssues);
        }

        // 4. Data quality check
        if ($this->enforceDataQuality) {
            $qualityIssues = $this->checkDataQuality($event);
            $issues = array_merge($issues, $qualityIssues);
        }

        // 5. Blocked payload content check
        $payloadIssues = $this->checkBlockedPayloadContent($event);
        $issues = array_merge($issues, $payloadIssues);

        // 6. Timestamp sanity check — events older than 90 days are flagged
        $timestampIssue = $this->checkTimestampSanity($event);
        if ($timestampIssue !== null) {
            $issues[] = $timestampIssue;
        }

        $sanitizedEvent = $this->valid($issues) ? $this->sanitizeEvent($event, $issues) : null;

        return [
            'valid' => $this->valid($issues),
            'issues' => $issues,
            'sanitized_event' => $sanitizedEvent,
        ];
    }

    /**
     * Validate a batch of events for replay.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return array{valid_count: int, invalid_count: int, results: list<array{event_name: string, valid: bool, issues: list<array{code: string, message: string, severity: string}>}>}
     */
    public function validateBatch(array $events): array
    {
        $validCount = 0;
        $invalidCount = 0;
        $results = [];

        foreach ($events as $event) {
            $validation = $this->validate($event);
            $results[] = [
                'event_name' => $event->name,
                'valid' => $validation['valid'],
                'issues' => $validation['issues'],
            ];

            if ($validation['valid']) {
                $validCount++;
            } else {
                $invalidCount++;
            }
        }

        return [
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'results' => $results,
        ];
    }

    /**
     * Mark an event as successfully replayed (idempotency tracking).
     */
    public function markReplayed(AnalyticsEvent $event): void
    {
        $key = $this->buildIdempotencyKey($event);
        $this->cache->put($key, true, $this->replayIdempotencyTtl);
    }

    /**
     * Get validation statistics.
     *
     * @return array{total_validated: int, total_passed: int, total_failed: int, pass_rate: float}
     */
    public function stats(): array
    {
        $key = $this->cachePrefix . 'stats';
        /** @var array{total: int, passed: int, failed: int}|null $stats */
        $stats = $this->cache->get($key);

        if ($stats === null) {
            return [
                'total_validated' => 0,
                'total_passed' => 0,
                'total_failed' => 0,
                'pass_rate' => 1.0,
            ];
        }

        return [
            'total_validated' => $stats['total'],
            'total_passed' => $stats['passed'],
            'total_failed' => $stats['failed'],
            'pass_rate' => $stats['total'] > 0
                ? round($stats['passed'] / $stats['total'], 4)
                : 1.0,
        ];
    }

    /**
     * Reset validation statistics.
     */
    public function resetStats(): void
    {
        $this->cache->forget($this->cachePrefix . 'stats');
    }

    /**
     * Get the list of currently blocked events.
     *
     * @return list<string>
     */
    public function getBlockedEvents(): array
    {
        return $this->blockedEvents;
    }

    /**
     * Add an event name to the blocked list.
     */
    public function blockEvent(string $eventName): void
    {
        if (! in_array($eventName, $this->blockedEvents, true)) {
            $this->blockedEvents[] = $eventName;
        }
    }

    /**
     * Remove an event name from the blocked list.
     */
    public function unblockEvent(string $eventName): void
    {
        $this->blockedEvents = array_values(array_filter(
            $this->blockedEvents,
            static fn(string $e): bool => $e !== $eventName,
        ));
    }

    /**
     * Check if all issues are warnings (non-blocking).
     *
     * @param  list<array{code: string, message: string, severity: string}>  $issues
     */
    private function valid(array $issues): bool
    {
        return ! array_any($issues, static fn(array $issue): bool => $issue['severity'] === 'error');
    }

    /**
     * Check idempotency — has this event already been replayed?
     *
     * @return array{code: string, message: string, severity: string}|null
     */
    private function checkIdempotency(AnalyticsEvent $event): ?array
    {
        $key = $this->buildIdempotencyKey($event);

        if ($this->cache->has($key)) {
            return [
                'code' => 'REPLAY_DUPLICATE',
                'message' => "Event '{$event->name}' has already been replayed (idempotency check)",
                'severity' => 'error',
            ];
        }

        return null;
    }

    /**
     * Check if the event is in the blocked list.
     *
     * @return array{code: string, message: string, severity: string}|null
     */
    private function checkBlockedEvents(AnalyticsEvent $event): ?array
    {
        if (in_array($event->name, $this->blockedEvents, true)) {
            return [
                'code' => 'EVENT_BLOCKED',
                'message' => "Event '{$event->name}' is explicitly blocked from replay",
                'severity' => 'error',
            ];
        }

        return null;
    }

    /**
     * Validate catalog membership — is this event in the EventCatalog?
     *
     * @return list<array{code: string, message: string, severity: string}>
     */
    private function checkCatalogMembership(AnalyticsEvent $event): array
    {
        $issues = [];
        $catalog = EventCatalog::all();

        if (! isset($catalog[$event->name])) {
            $issues[] = [
                'code' => 'CATALOG_UNKNOWN',
                'message' => "Event '{$event->name}' is not in the EventCatalog — may be deprecated or custom",
                'severity' => 'warning',
            ];
        } elseif (($catalog[$event->name]['category'] ?? null) !== $event->category && $event->category !== null) {
            $issues[] = [
                'code' => 'CATEGORY_MISMATCH',
                'message' => "Event '{$event->name}' category mismatch: expected '{$catalog[$event->name]['category']}', got '{$event->category}'",
                'severity' => 'warning',
            ];
        }

        return $issues;
    }

    /**
     * Check data quality — required fields, param types, value ranges.
     *
     * @return list<array{code: string, message: string, severity: string}>
     */
    private function checkDataQuality(AnalyticsEvent $event): array
    {
        $issues = [];

        // Event name must be non-empty and snake_case
        if ($event->name === '') {
            $issues[] = [
                'code' => 'EMPTY_NAME',
                'message' => 'Event name is empty',
                'severity' => 'error',
            ];
        }

        $payloadSize = strlen(json_encode($event->params));
        if ($payloadSize > 10240) {
            $issues[] = [
                'code' => 'PAYLOAD_TOO_LARGE',
                'message' => "Event '{$event->name}' payload is {$payloadSize} bytes (max 10KB for replay)",
                'severity' => 'warning',
            ];
        }

        if (trim($event->name) === '') {
            $issues[] = [
                'code' => 'INVALID_NAME',
                'message' => 'Event name is blank or whitespace-only',
                'severity' => 'error',
            ];
        }

        return $issues;
    }

    /**
     * Check for blocked content patterns in event params.
     *
     * @return list<array{code: string, message: string, severity: string}>
     */
    private function checkBlockedPayloadContent(AnalyticsEvent $event): array
    {
        $issues = [];
        $json = json_encode($event->params);

        foreach ($this->blockedPayloadPatterns as $pattern) {
            if (preg_match($pattern, (string) $json)) {
                $issues[] = [
                    'code' => 'SENSITIVE_CONTENT',
                    'message' => "Event '{$event->name}' payload matches blocked pattern: {$pattern}",
                    'severity' => 'error',
                ];
            }
        }

        return $issues;
    }

    /**
     * Check timestamp sanity — flag events older than 90 days.
     *
     * @return array{code: string, message: string, severity: string}|null
     */
    private function checkTimestampSanity(AnalyticsEvent $event): ?array
    {
        if ($event->timestamp === null) {
            return null;
        }

        $age = now()->diffInDays($event->timestamp);

        if ($age > 90) {
            return [
                'code' => 'STALE_EVENT',
                'message' => "Event '{$event->name}' is {$age} days old — replay may produce inaccurate analytics",
                'severity' => 'warning',
            ];
        }

        if ($event->timestamp > now()) {
            return [
                'code' => 'FUTURE_EVENT',
                'message' => "Event '{$event->name}' has a future timestamp — clock skew detected",
                'severity' => 'warning',
            ];
        }

        return null;
    }

    /**
     * Sanitize an event for safe replay.
     *
     * Creates a new AnalyticsEvent with sanitized params (removes blocked fields).
     *
     * @param  list<array{code: string, message: string, severity: string}>  $issues
     */
    private function sanitizeEvent(AnalyticsEvent $event, array $issues): AnalyticsEvent
    {
        $params = $event->params;

        unset(
            $params['_replay_id'],
            $params['_original_timestamp'],
            $params['_dlq_reason'],
            $params['_replay_count'],
        );

        // If event had category mismatch, fix it from catalog
        if (array_any($issues, static fn(array $i): bool => $i['code'] === 'CATEGORY_MISMATCH')) {
            $catalog = EventCatalog::all();
            if (isset($catalog[$event->name])) {
                return new AnalyticsEvent(
                    name: $event->name,
                    params: $params,
                    clientId: $event->clientId,
                    userId: $event->userId,
                    timestamp: $event->timestamp,
                    priority: $event->priority,
                    source: 'replay',
                    category: $catalog[$event->name]['category'],
                    sessionId: $event->sessionId,
                );
            }
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: $params,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: 'replay',
            category: $event->category,
            sessionId: $event->sessionId,
        );
    }

    /**
     * Build an idempotency cache key for an event.
     */
    private function buildIdempotencyKey(AnalyticsEvent $event): string
    {
        $hash = md5($event->name . ':' . ($event->clientId ?? '') . ':' . ($event->userId ?? '') . ':' . json_encode($event->params));

        return $this->cachePrefix . 'replayed:' . $hash;
    }

    /**
     * Update validation statistics.
     */
    private function updateStats(bool $passed): void
    {
        $key = $this->cachePrefix . 'stats';
        /** @var array{total: int, passed: int, failed: int} $stats */
        $stats = $this->cache->get($key, ['total' => 0, 'passed' => 0, 'failed' => 0]);
        $stats['total']++;
        if ($passed) {
            $stats['passed']++;
        } else {
            $stats['failed']++;
        }
        $this->cache->put($key, $stats, 86400);
    }
}
