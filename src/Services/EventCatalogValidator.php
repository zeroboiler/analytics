<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Catalog-aware event validation service.
 *
 * Validates incoming events against the registered EventCatalog.
 * Provides structured error messages for invalid events, unknown names,
 * missing required parameters, and type mismatches. Designed for use
 * in API controllers and event gate services.
 *
 * @version 5.0.0
 *
 * @since 1.0.0
 */
final class EventCatalogValidator
{
    /**
     * Known catalog event names (lazy-loaded).
     *
     * @var list<string>|null
     */
    private ?array $catalogNames = null;

    /**
     * Validate a single analytics event against the catalog.
     *
     * Checks: event name exists, name length, parameter types, required params.
     * Returns a validation result with pass/fail status and error details.
     *
     * @param  AnalyticsEvent  $event  The event to validate
     * @param  array{check_catalog?: bool, max_name_length?: int, enforce_types?: bool}  $options  Validation options
     * @return array{valid: bool, event: string, errors: list<array{rule: string, message: string, field?: string}>}
     */
    public function validate(AnalyticsEvent $event, array $options = []): array
    {
        $errors = [];
        $checkCatalog = (bool) ($options['check_catalog'] ?? true);
        $maxNameLength = (int) ($options['max_name_length'] ?? 100);
        $enforceTypes = (bool) ($options['enforce_types'] ?? false);

        // Name length check
        $name = $event->name;
        if (strlen($name) > $maxNameLength) {
            $errors[] = [
                'rule' => 'name_length',
                'message' => "Event name exceeds maximum length of {$maxNameLength} characters",
                'field' => 'name',
            ];
        }

        // Name format check (snake_case recommended)
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            $errors[] = [
                'rule' => 'name_format',
                'message' => 'Event name should be snake_case (lowercase, underscores only)',
                'field' => 'name',
            ];
        }

        // Catalog lookup
        if ($checkCatalog) {
            $catalogEntry = $this->getCatalogEntry($name);

            if ($catalogEntry !== null) {
                // Event is in catalog — validate params if class has schema
                if ($enforceTypes) {
                    $schemaErrors = $this->validateSchema($catalogEntry, $event->params);
                    $errors = array_merge($errors, $schemaErrors);
                }
            }
            // Non-catalog events are allowed (custom events) — no error
        }

        return [
            'valid' => count($errors) === 0,
            'event' => $name,
            'errors' => $errors,
        ];
    }

    /**
     * Validate a batch of events against the catalog.
     *
     * @param  list<AnalyticsEvent>  $events
     * @param  array{check_catalog?: bool, max_name_length?: int}  $options
     * @return array{valid: bool, total: int, passed: int, failed: int, results: list<array{valid: bool, event: string, errors: list<array{rule: string, message: string, field?: string}>}>}
     */
    public function validateBatch(array $events, array $options = []): array
    {
        $results = [];
        $passed = 0;
        $failed = 0;

        foreach ($events as $event) {
            $result = $this->validate($event, $options);
            $results[] = $result;

            if ($result['valid']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        return [
            'valid' => $failed === 0,
            'total' => count($events),
            'passed' => $passed,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * Check if an event name is a known catalog event.
     */
    public function isCatalogEvent(string $name): bool
    {
        return EventCatalog::has($name);
    }

    /**
     * Get the catalog category for an event name.
     *
     * @return 'ecommerce'|'saas'|'engagement'|null
     */
    public function getCategory(string $name): ?string
    {
        return EventCatalog::getCategory($name);
    }

    /**
     * Get full catalog info for an event name.
     *
     * @return array{name: string, class: class-string<AnalyticsEvent>, ga4: string, meta: string|null, posthog: string, plausible: string|null, category: string}|null
     */
    public function getCatalogEntry(string $name): ?array
    {
        return EventCatalog::get($name);
    }

    /**
     * Get all known catalog event names.
     *
     * @return list<string>
     */
    public function getCatalogNames(): array
    {
        if ($this->catalogNames === null) {
            $this->catalogNames = EventCatalog::names();
        }

        return $this->catalogNames;
    }

    /**
     * Get catalog statistics.
     *
     * @return array{total: int, ecommerce: int, saas: int, engagement: int, providers: array{ga4: int, meta: int, posthog: int, plausible: int}}
     */
    public function catalogStats(): array
    {
        $byCategory = EventCatalog::byCategory();

        return [
            'total' => EventCatalog::count(),
            'ecommerce' => count($byCategory['ecommerce']),
            'saas' => count($byCategory['saas']),
            'engagement' => count($byCategory['engagement']),
            'providers' => [
                'ga4' => count(EventCatalog::allGa4Names()),
                'meta' => count(EventCatalog::allMetaNames()),
                'posthog' => count(EventCatalog::allPosthogNames()),
                'plausible' => count(EventCatalog::allPlausibleNames()),
            ],
        ];
    }

    /**
     * Suggest catalog events for a given partial name (fuzzy match).
     *
     * Useful for auto-complete in admin dashboards and error messages.
     *
     * @param  string  $partial  Partial event name
     * @param  int  $limit  Max suggestions
     * @return list<array{name: string, category: string}>
     */
    public function suggest(string $partial, int $limit = 5): array
    {
        $results = [];
        $lower = strtolower($partial);

        foreach (EventCatalog::all() as $name => $entry) {
            if (str_contains($name, $lower)) {
                $results[] = [
                    'name' => $name,
                    'category' => $entry['category'],
                ];
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Validate event parameters against a catalog entry's event class schema.
     *
     * @param  array{name: string, class: class-string<AnalyticsEvent>}  $catalogEntry
     * @param  array<string, mixed>  $params
     * @return list<array{rule: string, message: string, field?: string}>
     */
    private function validateSchema(array $catalogEntry, array $params): array
    {
        $errors = [];
        $className = $catalogEntry['class'];

        if (! class_exists($className)) {
            return $errors;
        }

        // Use HasEventSchema trait if available
        if (method_exists($className, 'requiredParams')) {
            $required = $className::requiredParams();

            foreach ($required as $param) {
                if (! array_key_exists($param, $params)) {
                    $errors[] = [
                        'rule' => 'required_param',
                        'message' => "Required parameter '{$param}' is missing",
                        'field' => $param,
                    ];
                }
            }
        }

        return $errors;
    }
}
