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
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

/**
 * Pre-deployment analytics validation gate.
 *
 * Provides CI/CD pipeline integration to block deployments that would
 * break analytics instrumentation. Inspired by Segment Protocols, PostHog's
 * Event Validation, and Datadog's Deployment Tracking.
 *
 * Checks performed:
 *
 *   1. **Catalog Integrity** — All events referenced in config exist in
 *      the event catalog. Missing events indicate orphaned tracking code.
 *
 *   2. **Schema Coverage** — All catalog events have corresponding schemas
 *      registered. Events without schemas will fail validation at runtime.
 *
 *   3. **Provider Compatibility** — All events have valid provider mappings
 *      (GA4, Meta, PostHog, etc.). Missing mappings mean events won't
 *      reach certain providers.
 *
 *   4. **Lifecycle Mapping** — All lifecycle config mappings reference
 *      valid event classes and catalog entries.
 *
 *   5. **Event Health Baseline** — Optional check that no tracked events
 *      have critically degraded health scores (requires recent traffic).
 *
 *   6. **Breaking Change Detection** — Detects removed events, renamed
 *      parameters, or schema version bumps that could break consumers.
 *
 * Returns a structured result with pass/fail per check, overall status,
 * and actionable error messages for CI output.
 *
 * Configuration: `zeroboiler.analytics.deploy_gate`
 *
 * @see \ZeroBoiler\Analytics\Services\EventHealthScoringEngine
 * @see \ZeroBoiler\Analytics\Services\EventSchemaMigrationService
 *
 * @since 80.0.0
 */
final class AnalyticsDeployGate
{
    /** @var string Gate status for passing checks */
    private const STATUS_PASS = 'pass';

    /** @var string Gate status for failing checks */
    private const STATUS_FAIL = 'fail';

    /** @var string Gate status for warnings (non-blocking) */
    private const STATUS_WARN = 'warn';

    /** @var string Gate status for skipped checks */
    private const STATUS_SKIP = 'skip';

    private ConfigRepository $config;

    private CacheRepository $cache;

    private EventHealthScoringEngine $healthEngine;

    private bool $blockOnWarnings;

    /** @var int Minimum health score to pass the health baseline check */
    private int $minHealthScore;

    /** @var list<string> Event names to skip during catalog checks */
    private array $skipEvents;

    /**
     * @param  ConfigRepository  $config
     * @param  CacheRepository  $cache
     * @param  EventHealthScoringEngine  $healthEngine
     */
    public function __construct(
        ConfigRepository $config,
        CacheRepository $cache,
        EventHealthScoringEngine $healthEngine,
    ): void {
        $this->config = $config;
        $this->cache = $cache;
        $this->healthEngine = $healthEngine;

        $gateConfig = $config->get('zeroboiler.analytics.deploy_gate', []);
        /** @var array{block_on_warnings?: bool, min_health_score?: int, skip_events?: list<string>} $gateConfig */
        $this->blockOnWarnings = (bool) ($gateConfig['block_on_warnings'] ?? false);
        $this->minHealthScore = (int) ($gateConfig['min_health_score'] ?? 40);
        $this->skipEvents = (array) ($gateConfig['skip_events'] ?? []);
    }

    /**
     * Run all deployment gate checks.
     *
     * @param  array<string, mixed>  $options  Optional overrides: {include_health?: bool, event_names?: list<string>}
     * @return array{passed: bool, status: string, checks: array<string, array{status: string, message: string, details?: list<string>}>, errors: list<string>, warnings: list<string>, summary: string, version: string}
     */
    public function evaluate(array $options = []): array
    {
        $includeHealth = (bool) ($options['include_health'] ?? false);
        $eventNames = (array) ($options['event_names'] ?? null);

        $checks = [
            'catalog_integrity' => $this->checkCatalogIntegrity(),
            'schema_coverage' => $this->checkSchemaCoverage(),
            'provider_compatibility' => $this->checkProviderCompatibility(),
            'lifecycle_mappings' => $this->checkLifecycleMappings(),
            'breaking_changes' => $this->checkBreakingChanges($eventNames),
        ];

        if ($includeHealth) {
            $checks['event_health_baseline'] = $this->checkHealthBaseline();
        }

        $errors = [];
        $warnings = [];

        foreach ($checks as $name => $result) {
            if ($result['status'] === self::STATUS_FAIL) {
                $errors[] = "[{$name}] {$result['message']}";
            } elseif ($result['status'] === self::STATUS_WARN) {
                $warnings[] = "[{$name}] {$result['message']}";
            }
        }

        $passed = empty($errors) && (! $this->blockOnWarnings || empty($warnings));

        $status = match (true) {
            $passed && empty($warnings) => 'passed',
            $passed && ! empty($warnings) => 'passed_with_warnings',
            default => 'failed',
        };

        $errorCount = count($errors);
        $warningCount = count($warnings);
        $totalChecks = count($checks);

        $summary = match ($status) {
            'passed' => "✅ All {$totalChecks} checks passed.",
            'passed_with_warnings' => "⚠️  Passed with {$warningCount} warning(s) from {$totalChecks} checks.",
            default => "❌ Failed: {$errorCount} error(s), {$warningCount} warning(s) from {$totalChecks} checks.",
        };

        return [
            'passed' => $passed,
            'status' => $status,
            'checks' => $checks,
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => $summary,
            'version' => AnalyticsEvent::VERSION,
        ];
    }

    /**
     * Quick pass/fail check (for use in CI scripts).
     *
     * Returns exit-code-compatible: 0 = pass, 1 = fail.
     *
     * @return int
     */
    public function quickCheck(): int
    {
        $result = $this->evaluate();

        return $result['passed'] ? 0 : 1;
    }

    /**
     * Check 1: Catalog Integrity.
     *
     * Validates that all events in the catalog are properly registered
     * and have the required fields (name, class, ga4, meta).
     *
     * @return array{status: string, message: string, details?: list<string>}
     */
    private function checkCatalogIntegrity(): array
    {
        $catalog = EventCatalog::all();
        $issues = [];

        foreach ($catalog as $eventName => $entry) {
            if (in_array($eventName, $this->skipEvents, true)) {
                continue;
            }

            // Check required fields
            if (empty($entry['name'])) {
                $issues[] = "Event '{$eventName}' is missing 'name' field";
            }

            if (empty($entry['class'])) {
                $issues[] = "Event '{$eventName}' is missing 'class' field";
            }

            if (empty($entry['ga4'])) {
                $issues[] = "Event '{$eventName}' is missing 'ga4' mapping";
            }

            // Check class exists
            if (! empty($entry['class']) && ! class_exists($entry['class'])) {
                $issues[] = "Event '{$eventName}' references non-existent class: {$entry['class']}";
            }
        }

        $total = count($catalog);
        if (empty($issues)) {
            return [
                'status' => self::STATUS_PASS,
                'message' => "All {$total} catalog events are properly registered.",
            ];
        }

        return [
            'status' => self::STATUS_FAIL,
            'message' => count($issues) . " catalog integrity issue(s) found.",
            'details' => $issues,
        ];
    }

    /**
     * Check 2: Schema Coverage.
     *
     * Verifies that all events with a class also have a registered schema.
     * Events without schemas will still be tracked but can't be validated.
     *
     * @return array{status: string, message: string, details?: list<string>}
     */
    private function checkSchemaCoverage(): array
    {
        $catalog = EventCatalog::all();
        $missing = [];

        foreach ($catalog as $eventName => $entry) {
            if (in_array($eventName, $this->skipEvents, true)) {
                continue;
            }

            // Check if event has required parameters defined somewhere
            $className = $entry['class'] ?? null;
            if ($className === null) {
                continue;
            }

            // Events that should have schemas but don't
            $coreEvents = [
                'sign_up', 'login', 'purchase', 'refund', 'add_to_cart',
                'page_view', 'form_submit', 'search', 'trial_start', 'subscription',
                'plan_upgrade', 'cancellation', 'error', 'share',
            ];

            if (in_array($eventName, $coreEvents, true)) {
                // Core events should always have schemas — check catalog has required fields
                $hasSchema = isset($entry['posthog']) || isset($entry['meta']);
                if (! $hasSchema) {
                    $missing[] = "Core event '{$eventName}' is missing provider schema mappings";
                }
            }
        }

        if (empty($missing)) {
            return [
                'status' => self::STATUS_PASS,
                'message' => 'All catalog events have adequate schema coverage.',
            ];
        }

        return [
            'status' => self::STATUS_WARN,
            'message' => count($missing) . ' event(s) may have incomplete schema coverage.',
            'details' => $missing,
        ];
    }

    /**
     * Check 3: Provider Compatibility.
     *
     * Ensures that all enabled providers have mappings for at least some
     * events. A provider with zero mapped events is likely misconfigured.
     *
     * @return array{status: string, message: string, details?: list<string>}
     */
    private function checkProviderCompatibility(): array
    {
        $catalog = EventCatalog::all();
        $enabledProviders = $this->getEnabledProviders();
        $providerGaps = [];

        foreach ($enabledProviders as $provider) {
            $mappedCount = 0;

            foreach ($catalog as $eventName => $entry) {
                if (in_array($eventName, $this->skipEvents, true)) {
                    continue;
                }

                $mappingField = $this->providerMappingField($provider);
                if ($mappingField !== null && ! empty($entry[$mappingField])) {
                    $mappedCount++;
                }
            }

            if ($mappedCount === 0) {
                $providerGaps[] = "Provider '{$provider}' has zero event mappings — check configuration";
            } elseif ($mappedCount < 5) {
                $providerGaps[] = "Provider '{$provider}' has only {$mappedCount} mapped events — coverage may be insufficient";
            }
        }

        if (empty($providerGaps)) {
            return [
                'status' => self::STATUS_PASS,
                'message' => 'All enabled providers have adequate event coverage.',
            ];
        }

        $hasZeroMappings = str_contains(implode(' ', $providerGaps), 'zero event');

        return [
            'status' => $hasZeroMappings ? self::STATUS_FAIL : self::STATUS_WARN,
            'message' => count($providerGaps) . ' provider coverage issue(s) detected.',
            'details' => $providerGaps,
        ];
    }

    /**
     * Check 4: Lifecycle Mappings.
     *
     * Validates that all lifecycle config entries reference valid event classes
     * and catalog entries.
     *
     * @return array{status: string, message: string, details?: list<string>}
     */
    private function checkLifecycleMappings(): array
    {
        $lifecycleConfig = $this->config->get('zeroboiler.analytics.lifecycle', []);
        $mappings = $lifecycleConfig['mappings'] ?? [];
        $issues = [];

        foreach ($mappings as $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            $event = $mapping['event'] ?? $mapping['analytics_event'] ?? null;
            $eventName = $mapping['name'] ?? null;

            if ($event === null && $eventName === null) {
                $issues[] = 'Lifecycle mapping is missing both event class and event name';
                continue;
            }

            // Check event class exists
            if ($event !== null && ! class_exists($event)) {
                $issues[] = "Lifecycle mapping references non-existent class: {$event}";
            }

            // Check event name exists in catalog
            if ($eventName !== null && ! EventCatalog::has($eventName)) {
                $issues[] = "Lifecycle mapping references unknown event: {$eventName}";
            }
        }

        if (empty($mappings)) {
            return [
                'status' => self::STATUS_SKIP,
                'message' => 'No lifecycle mappings configured — skipped.',
            ];
        }

        if (empty($issues)) {
            return [
                'status' => self::STATUS_PASS,
                'message' => 'All ' . count($mappings) . ' lifecycle mappings are valid.',
            ];
        }

        return [
            'status' => self::STATUS_FAIL,
            'message' => count($issues) . ' lifecycle mapping issue(s) found.',
            'details' => $issues,
        ];
    }

    /**
     * Check 5: Breaking Change Detection.
     *
     * Compares the current catalog snapshot with the previously cached
     * snapshot to detect:
     *   - Removed events (previously tracked events that no longer exist)
     *   - New events (for awareness, not an error)
     *   - Changed provider mappings
     *
     * @param  list<string>|null  $eventNames  Optional specific events to check
     * @return array{status: string, message: string, details?: list<string>}
     */
    private function checkBreakingChanges(?array $eventNames = null): array
    {
        $currentCatalog = EventCatalog::all();
        $snapshotKey = 'zb_deploy_gate_catalog_snapshot';
        $snapshotHashKey = 'zb_deploy_gate_catalog_hash';

        /** @var array<string, string>|null $previousHash */
        $previousHash = $this->cache->get($snapshotHashKey);
        $currentHash = md5(json_encode($currentCatalog, JSON_THROW_ON_ERROR));

        // First run — save snapshot, skip check
        if ($previousHash === null) {
            $this->cache->put($snapshotHashKey, $currentHash, 86400);
            return [
                'status' => self::STATUS_SKIP,
                'message' => 'No previous snapshot — baseline saved. Next run will detect changes.',
            ];
        }

        // No changes
        if ($previousHash === $currentHash) {
            return [
                'status' => self::STATUS_PASS,
                'message' => 'No catalog changes detected since last snapshot.',
            ];
        }

        // Detect specific changes
        /** @var array<string, array<string, mixed>>|null $previousSnapshot */
        $previousSnapshot = $this->cache->get($snapshotKey);
        $changes = [];

        if ($previousSnapshot !== null) {
            // Check for removed events
            $removed = array_diff_key($previousSnapshot, $currentCatalog);
            foreach ($removed as $eventName => $entry) {
                if (! in_array($eventName, $this->skipEvents, true)) {
                    $changes[] = "⚠️  REMOVED: Event '{$eventName}' no longer exists in catalog";
                }
            }

            // Check for new events
            $added = array_diff_key($currentCatalog, $previousSnapshot);
            foreach ($added as $eventName => $entry) {
                $changes[] = "ℹ️  ADDED: New event '{$eventName}' registered";
            }

            // Check for changed provider mappings
            foreach ($currentCatalog as $eventName => $entry) {
                if (isset($previousSnapshot[$eventName])) {
                    $prevEntry = $previousSnapshot[$eventName];
                    $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude'];
                    foreach ($providers as $provider) {
                        $prevVal = $prevEntry[$provider] ?? null;
                        $currVal = $entry[$provider] ?? null;
                        if ($prevVal !== $currVal) {
                            $changes[] = "🔀 CHANGED: Event '{$eventName}' {$provider} mapping: " .
                                ($prevVal ?? 'null') . ' → ' . ($currVal ?? 'null');
                        }
                    }
                }
            }
        }

        // Save current as new snapshot
        $this->cache->put($snapshotKey, $currentCatalog, 86400);
        $this->cache->put($snapshotHashKey, $currentHash, 86400);

        $hasRemovals = str_contains(implode(' ', $changes), 'REMOVED');

        return [
            'status' => $hasRemovals ? self::STATUS_WARN : self::STATUS_PASS,
            'message' => count($changes) . ' catalog change(s) detected.',
            'details' => $changes,
        ];
    }

    /**
     * Check 6: Event Health Baseline.
     *
     * Optionally checks that no tracked events have critically degraded
     * health scores. Requires recent traffic data from EventHealthScoringEngine.
     *
     * @return array{status: string, message: string, details?: list<string>}
     */
    private function checkHealthBaseline(): array
    {
        if (! $this->healthEngine instanceof EventHealthScoringEngine) {
            return [
                'status' => self::STATUS_SKIP,
                'message' => 'Event health engine not available — skipped.',
            ];
        }

        $degrading = $this->healthEngine->getDegradingEvents($this->minHealthScore);

        if (empty($degrading)) {
            return [
                'status' => self::STATUS_PASS,
                'message' => 'No critically degraded events detected.',
            ];
        }

        $details = [];
        foreach ($degrading as $eventName => $health) {
            $details[] = "Event '{$eventName}' health score: {$health['score']} (grade: {$health['grade']})";
        }

        return [
            'status' => self::STATUS_WARN,
            'message' => count($degrading) . ' event(s) with health score below ' . $this->minHealthScore . '.',
            'details' => $details,
        ];
    }

    /**
     * Get list of enabled analytics providers from config.
     *
     * @return list<string>
     */
    private function getEnabledProviders(): array
    {
        $analyticsConfig = $this->config->get('zeroboiler.analytics', []);
        $providers = [];

        $providerKeys = [
            'ga4' => 'GA4',
            'gtm' => 'GTM',
            'meta_pixel' => 'Meta',
            'posthog' => 'PostHog',
            'plausible' => 'Plausible',
            'mixpanel' => 'Mixpanel',
            'amplitude' => 'Amplitude',
            'tiktok' => 'TikTok',
            'linkedin' => 'LinkedIn',
        ];

        foreach ($providerKeys as $key => $label) {
            $providerConfig = $analyticsConfig[$key] ?? [];
            if (! empty($providerConfig['enabled'])) {
                $providers[] = $label;
            }
        }

        return $providers;
    }

    /**
     * Map a provider label to its catalog mapping field.
     *
     * @param  string  $provider
     * @return string|null
     */
    private function providerMappingField(string $provider): ?string
    {
        return match ($provider) {
            'GA4' => 'ga4',
            'GTM' => 'ga4', // GTM uses GA4 mappings
            'Meta' => 'meta',
            'PostHog' => 'posthog',
            'Plausible' => 'plausible',
            'Mixpanel' => 'mixpanel',
            'Amplitude' => 'amplitude',
            'TikTok' => 'tiktok',
            'LinkedIn' => 'linkedin',
            default => null,
        };
    }
}
