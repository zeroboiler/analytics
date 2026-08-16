<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Runtime event governance validator.
 *
 * Validates analytics events at dispatch time against the EventCatalog
 * and configurable governance rules. Provides early detection of:
 *
 * - Unknown/misspelled event names
 * - Missing catalog entries
 * - Category mismatches
 * - Empty required parameters
 * - Provider mapping gaps
 * - Deprecated events
 *
 * Designed as a lightweight, non-blocking validator that logs warnings
 * rather than throwing exceptions (governance should never break tracking).
 *
 * Can be integrated into the AnalyticsManager dispatch pipeline or used
 * standalone for batch validation.
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 * @see \ZeroBoiler\Analytics\Services\CatalogSnapshotService
 *
 * @since 160.0.0
 */
final class EventGovernanceRuntimeValidator
{
    /** @var list<string> Events that have been deprecated and should trigger warnings */
    private array $deprecatedEvents = [];

    /** @var list<string> Required parameter keys that must be non-empty */
    private array $requiredGlobalParams = [];

    /** @var bool Whether to check provider mapping gaps */
    private bool $checkProviderGaps;

    /** @var bool Whether to auto-resolve event names via EventCatalog::resolve() */
    private bool $autoResolve;

    /** @var list<array{severity: string, event: string, message: string}> */
    private array $validationLog = [];

    /** @var int Maximum log entries to keep */
    private int $maxLogSize;

    /**
     * @param  bool  $checkProviderGaps  Check for provider mapping gaps
     * @param  bool  $autoResolve  Auto-resolve event names via catalog
     * @param  int  $maxLogSize  Maximum validation log entries
     */
    public function __construct(
        bool $checkProviderGaps = true,
        bool $autoResolve = true,
        int $maxLogSize = 1000,
    ): void {
        $this->checkProviderGaps = $checkProviderGaps;
        $this->autoResolve = $autoResolve;
        $this->maxLogSize = $maxLogSize;
    }

    /**
     * Validate a single analytics event against governance rules.
     *
     * Non-blocking: returns validation result without throwing.
     * Warnings are accumulated in the internal validation log.
     *
     * @return array{valid: bool, event: string, warnings: list<string>, provider_gaps: list<string>, resolved_name: string|null, category: string|null, catalog_entry: array|null}
     */
    public function validate(AnalyticsEvent $event): array
    {
        $warnings = [];
        $providerGaps = [];
        $resolvedName = null;
        $catalogEntry = null;

        // 1. Check if event exists in catalog
        if (EventCatalog::has($event->name)) {
            $catalogEntry = EventCatalog::get($event->name);
            $resolvedName = $event->name;
        } elseif ($this->autoResolve) {
            // 2. Try to resolve via fuzzy matching
            $resolved = EventCatalog::resolve($event->name);
            if ($resolved !== null) {
                $resolvedName = $resolved;
                $catalogEntry = EventCatalog::get($resolved);
                $warnings[] = "Event name '{$event->name}' resolved to '{$resolved}'";
            } else {
                $warnings[] = "Unknown event '{$event->name}' — not found in catalog";
            }
        } else {
            $warnings[] = "Unknown event '{$event->name}' — not found in catalog";
        }

        // 3. Category mismatch detection
        if ($catalogEntry !== null && $event->category !== null) {
            $catalogCategory = $catalogEntry['category'] ?? null;
            if ($catalogCategory !== null && $event->category !== $catalogCategory) {
                $warnings[] = "Category mismatch: event '{$event->name}' dispatched as '{$event->category}' but catalog says '{$catalogCategory}'";
            }
        }

        // 4. Required global parameter check
        foreach ($this->requiredGlobalParams as $key) {
            if (! array_key_exists($key, $event->params)) {
                $warnings[] = "Missing required parameter '{$key}' in event '{$event->name}'";
            } elseif ($event->params[$key] === null || $event->params[$key] === '') {
                $warnings[] = "Empty required parameter '{$key}' in event '{$event->name}'";
            }
        }

        // 5. Deprecated event check
        if (in_array($event->name, $this->deprecatedEvents, true)) {
            $warnings[] = "Deprecated event '{$event->name}' — should be migrated to a current event name";
        }

        // 6. Provider mapping gap analysis
        if ($this->checkProviderGaps && $catalogEntry !== null) {
            $enabledProviders = ['ga4', 'meta', 'posthog'];
            foreach ($enabledProviders as $provider) {
                $mapping = $catalogEntry[$provider] ?? null;
                if ($mapping === null || $mapping === '') {
                    $providerGaps[] = $provider;
                }
            }
        }

        // 7. Empty event name check
        if (trim($event->name) === '') {
            $warnings[] = 'Empty event name — event will be dropped by most providers';
        }

        // Build result
        $valid = $warnings === [] && $providerGaps === [];

        // Log the validation result
        $this->logValidation($event->name, $valid, $warnings);

        return [
            'valid' => $valid,
            'event' => $event->name,
            'warnings' => $warnings,
            'provider_gaps' => $providerGaps,
            'resolved_name' => $resolvedName,
            'category' => $event->category,
            'catalog_entry' => $catalogEntry,
        ];
    }

    /**
     * Validate a batch of events and return aggregated results.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return array{total: int, valid: int, invalid: int, warnings_total: int, provider_gap_total: int, unknown_events: list<string>, deprecated_events: list<string>, results: list<array{valid: bool, event: string, warnings: list<string>, provider_gaps: list<string>}>}
     */
    public function validateBatch(array $events): array
    {
        $results = [];
        $validCount = 0;
        $invalidCount = 0;
        $warningsTotal = 0;
        $providerGapTotal = 0;
        $unknownEvents = [];
        $deprecatedHits = [];

        foreach ($events as $event) {
            $result = $this->validate($event);
            $results[] = $result;

            if ($result['valid']) {
                $validCount++;
            } else {
                $invalidCount++;
            }

            $warningsTotal += count($result['warnings']);
            $providerGapTotal += count($result['provider_gaps']);

            if ($result['resolved_name'] === null && $result['catalog_entry'] === null) {
                $unknownEvents[] = $event->name;
            }

            if (in_array($event->name, $this->deprecatedEvents, true)) {
                $deprecatedHits[] = $event->name;
            }
        }

        return [
            'total' => count($events),
            'valid' => $validCount,
            'invalid' => $invalidCount,
            'warnings_total' => $warningsTotal,
            'provider_gap_total' => $providerGapTotal,
            'unknown_events' => array_values(array_unique($unknownEvents)),
            'deprecated_events' => array_values(array_unique($deprecatedHits)),
            'results' => $results,
        ];
    }

    /**
     * Mark events as deprecated.
     *
     * @param  list<string>  $eventNames
     * @return self
     */
    public function setDeprecatedEvents(array $eventNames): self
    {
        $this->deprecatedEvents = $eventNames;

        return $this;
    }

    /**
     * Set required global parameter keys.
     *
     * @param  list<string>  $keys
     * @return self
     */
    public function setRequiredGlobalParams(array $keys): self
    {
        $this->requiredGlobalParams = $keys;

        return $this;
    }

    /**
     * Get the accumulated validation log.
     *
     * @return list<array{severity: string, event: string, message: string}>
     */
    public function getValidationLog(): array
    {
        return $this->validationLog;
    }

    /**
     * Clear the validation log.
     */
    public function clearLog(): void
    {
        $this->validationLog = [];
    }

    /**
     * Get a summary of the validation log.
     *
     * @return array{total_entries: int, warnings: int, errors: int, unique_events: int, top_offenders: list<array{event: string, count: int}>}
     */
    public function logSummary(): array
    {
        $warnings = 0;
        $errors = 0;
        $eventCounts = [];

        foreach ($this->validationLog as $entry) {
            if ($entry['severity'] === 'warning') {
                $warnings++;
            } else {
                $errors++;
            }
            $eventCounts[$entry['event']] = ($eventCounts[$entry['event']] ?? 0) + 1;
        }

        arsort($eventCounts);
        $topOffenders = [];
        $count = 0;

        foreach ($eventCounts as $event => $cnt) {
            if ($count >= 10) {
                break;
            }
            $topOffenders[] = ['event' => $event, 'count' => $cnt];
            $count++;
        }

        return [
            'total_entries' => count($this->validationLog),
            'warnings' => $warnings,
            'errors' => $errors,
            'unique_events' => count($eventCounts),
            'top_offenders' => $topOffenders,
        ];
    }

    /**
     * Check governance health for the entire catalog.
     *
     * Validates all catalog entries for structural completeness.
     *
     * @return array{total: int, valid: int, incomplete: int, issues: list<array{event: string, issue: string}>}
     */
    public function catalogGovernanceHealth(): array
    {
        $catalog = EventCatalog::all();
        $valid = 0;
        $incomplete = 0;
        $issues = [];

        foreach ($catalog as $name => $entry) {
            $eventIssues = [];

            // Check required fields
            if (empty($entry['name'])) {
                $eventIssues[] = 'missing name';
            }
            if (empty($entry['class'])) {
                $eventIssues[] = 'missing class';
            }
            if (empty($entry['ga4'])) {
                $eventIssues[] = 'missing GA4 mapping';
            }
            if (empty($entry['category'])) {
                $eventIssues[] = 'missing category';
            }

            if ($eventIssues === []) {
                $valid++;
            } else {
                $incomplete++;
                foreach ($eventIssues as $issue) {
                    $issues[] = ['event' => $name, 'issue' => $issue];
                }
            }
        }

        return [
            'total' => count($catalog),
            'valid' => $valid,
            'incomplete' => $incomplete,
            'issues' => $issues,
        ];
    }

    /**
     * Log a validation result internally.
     */
    private function logValidation(string $eventName, bool $valid, array $warnings): void
    {
        if ($valid && $warnings === []) {
            return;
        }

        foreach ($warnings as $message) {
            $this->validationLog[] = [
                'severity' => $valid ? 'warning' : 'error',
                'event' => $eventName,
                'message' => $message,
                'timestamp' => date('c'),
            ];
        }

        // Enforce max log size
        if (count($this->validationLog) > $this->maxLogSize) {
            $this->validationLog = array_slice($this->validationLog, -$this->maxLogSize);
        }
    }
}
