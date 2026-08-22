<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event governance service for SaaS analytics.
 *
 * Manages the lifecycle of analytics events: registration, approval,
 * deprecation, and retirement. Enforces naming conventions, required
 * parameter policies, and ownership tracking. Provides governance
 * dashboards for compliance and data quality monitoring.
 *
 * Inspired by Segment's Tracking Plan and Amplitude's Event Taxonomy.
 *
 * Configuration is read from `zeroboiler.analytics.governance`.
 *
 * @phpstan-type EventRegistration array{name: string, category: string, owner: string, description: string, status: 'draft'|'active'|'deprecated'|'retired', required_params: list<string>, optional_params: list<string>, created_at: string|null, deprecated_at: string|null, retired_at: string|null, version: int, schema_hash: string|null}
 * @phpstan-type GovernanceReport array{total_events: int, active: int, draft: int, deprecated: int, retired: int, naming_score: float, schema_coverage: float, duplicate_risk: int, governance_score: float}
 *
 * @since 1.0.0
 */
final class EventGovernanceService
{
    private const CACHE_PREFIX = 'zb_governance_';

    private bool $enabled;

    private bool $enforceOnDispatch;

    private int $cacheTtl;

    /** @var array<string, EventRegistration> Registered event governance records */
    private array $registrations = [];

    /** @var list<string> Reserved event name prefixes (e.g., '$' for PostHog reserved) */
    private array $reservedPrefixes;

    private EventNamingConventionService $namingService;

    private EventDeprecationService $deprecationService;

    private DataQualityScorer $qualityScorer;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ){
        $governanceConfig = $config->get('zeroboiler.analytics.governance', []);
        /** @var array{enabled?: bool, enforce_on_dispatch?: bool, cache_ttl?: int, reserved_prefixes?: list<string>} $governanceConfig */

        $this->enabled = (bool) ($governanceConfig['enabled'] ?? false);
        $this->enforceOnDispatch = (bool) ($governanceConfig['enforce_on_dispatch'] ?? false);
        $this->cacheTtl = (int) ($governanceConfig['cache_ttl'] ?? 3600);
        $this->reservedPrefixes = $governanceConfig['reserved_prefixes'] ?? ['$', 'zb_', 'amp_'];

        $this->namingService = new EventNamingConventionService($config);
        $this->deprecationService = new EventDeprecationService($cache, $config);
        $this->qualityScorer = new DataQualityScorer($cache, $config);

        $this->loadRegistrations();
    }

    /**
     * Register a new event for governance tracking.
     *
     * @param  string  $name  Event name (must pass naming convention check)
     * @param  string  $category  Event category (ecommerce|saas|engagement|custom)
     * @param  string  $owner  Team or person responsible for this event
     * @param  string  $description  Human-readable description of the event
     * @param  list<string>  $requiredParams  Required parameter keys
     * @param  list<string>  $optionalParams  Optional parameter keys
     * @return array{success: bool, event: string, errors: list<string>, warnings: list<string>}
     */
    public function register(
        string $name,
        string $category,
        string $owner,
        string $description,
        array $requiredParams = [],
        array $optionalParams = [],
    ): array {
        $errors = [];
        $warnings = [];

        $namingResult = $this->namingService->validate($name);
        if (! $namingResult['valid']) {
            $errors = array_merge($errors, $namingResult['errors']);
        }

        foreach ($this->reservedPrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $errors[] = "Event name '{$name}' uses reserved prefix '{$prefix}'";
            }
        }

        if (isset($this->registrations[$name])) {
            $errors[] = "Event '{$name}' is already registered";
        }

        $validCategories = ['ecommerce', 'saas', 'engagement', 'custom'];
        if (! in_array($category, $validCategories, true)) {
            $errors[] = "Invalid category '{$category}'. Must be one of: " . implode(', ', $validCategories);
        }

        if (! EventCatalog::has($name)) {
            $warnings[] = "Event '{$name}' is not in the standard catalog — it will be tracked as custom";
        }

        if (! empty($errors)) {
            return [
                'success' => false,
                'event' => $name,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        $registration = [
            'name' => $name,
            'category' => $category,
            'owner' => $owner,
            'description' => $description,
            'status' => 'draft',
            'required_params' => $requiredParams,
            'optional_params' => $optionalParams,
            'created_at' => date('c'),
            'deprecated_at' => null,
            'retired_at' => null,
            'version' => 1,
            'schema_hash' => $this->computeSchemaHash($requiredParams, $optionalParams),
        ];

        $this->registrations[$name] = $registration;
        $this->persistRegistrations();

        return [
            'success' => true,
            'event' => $name,
            'errors' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * Activate a draft event.
     *
     * @return array{success: bool, event: string, error: string|null}
     */
    public function activate(string $name): array
    {
        if (! isset($this->registrations[$name])) {
            return ['success' => false, 'event' => $name, 'error' => "Event '{$name}' is not registered"];
        }

        $registration = $this->registrations[$name];

        if ($registration['status'] === 'retired') {
            return ['success' => false, 'event' => $name, 'error' => "Cannot activate retired event '{$name}'"];
        }

        $this->registrations[$name]['status'] = 'active';
        $this->persistRegistrations();

        return ['success' => true, 'event' => $name, 'error' => null];
    }

    /**
     * Deprecate an active event.
     *
     * @param  string  $name  Event name
     * @param  string|null  $replacement  Suggested replacement event name
     * @return array{success: bool, event: string, error: string|null}
     */
    public function deprecate(string $name, ?string $replacement = null): array
    {
        if (! isset($this->registrations[$name])) {
            return ['success' => false, 'event' => $name, 'error' => "Event '{$name}' is not registered"];
        }

        $registration = $this->registrations[$name];

        if ($registration['status'] !== 'active') {
            return ['success' => false, 'event' => $name, 'error' => "Only active events can be deprecated, current status: {$registration['status']}"];
        }

        $this->registrations[$name]['status'] = 'deprecated';
        $this->registrations[$name]['deprecated_at'] = date('c');

        if ($replacement !== null) {
            $this->deprecationService->setReplacement($name, $replacement);
        }

        $this->persistRegistrations();

        return ['success' => true, 'event' => $name, 'error' => null];
    }

    /**
     * Retire a deprecated event (permanent removal).
     *
     * @return array{success: bool, event: string, error: string|null}
     */
    public function retire(string $name): array
    {
        if (! isset($this->registrations[$name])) {
            return ['success' => false, 'event' => $name, 'error' => "Event '{$name}' is not registered"];
        }

        $registration = $this->registrations[$name];

        if ($registration['status'] !== 'deprecated') {
            return ['success' => false, 'event' => $name, 'error' => "Only deprecated events can be retired, current status: {$registration['status']}"];
        }

        $this->registrations[$name]['status'] = 'retired';
        $this->registrations[$name]['retired_at'] = date('c');
        $this->persistRegistrations();

        return ['success' => true, 'event' => $name, 'error' => null];
    }

    /**
     * Validate an event against governance rules before dispatch.
     *
     * Called by the event pipeline when governance enforcement is enabled.
     * Returns validation result without throwing — the caller decides
     * whether to block or warn.
     *
     * @param  string  $name  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{allowed: bool, errors: list<string>, warnings: list<string>, governance_status: string|null}
     */
    public function validateDispatch(string $name, array $params): array
    {
        if (! $this->enabled) {
            return ['allowed' => true, 'errors' => [], 'warnings' => [], 'governance_status' => null];
        }

        $errors = [];
        $warnings = [];
        $status = null;

        $namingResult = $this->namingService->validate($name);
        if (! $namingResult['valid']) {
            $errors = array_merge($errors, $namingResult['errors']);
        }

        if (isset($this->registrations[$name])) {
            $registration = $this->registrations[$name];
            $status = $registration['status'];

            // Block retired events
            if ($registration['status'] === 'retired') {
                $errors[] = "Event '{$name}' has been retired and cannot be dispatched";
            }

            // Warn on deprecated events
            if ($registration['status'] === 'deprecated') {
                $replacement = $this->deprecationService->getReplacement($name);
                $msg = "Event '{$name}' is deprecated";
                if ($replacement !== null) {
                    $msg .= " — use '{$replacement}' instead";
                }
                $warnings[] = $msg;
            }

            // Warn on draft events
            if ($registration['status'] === 'draft') {
                $warnings[] = "Event '{$name}' is still in draft status";
            }

            foreach ($registration['required_params'] as $param) {
                if (! array_key_exists($param, $params)) {
                    $errors[] = "Missing required parameter '{$param}' for event '{$name}'";
                }
            }
        }

        return [
            'allowed' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'governance_status' => $status,
        ];
    }

    /**
     * Get a governance report for dashboards.
     *
     * @return GovernanceReport
     */
    public function report(): array
    {
        $total = count($this->registrations);
        $active = 0;
        $draft = 0;
        $deprecated = 0;
        $retired = 0;

        foreach ($this->registrations as $reg) {
            match ($reg['status']) {
                'active' => $active++,
                'draft' => $draft++,
                'deprecated' => $deprecated++,
                'retired' => $retired++,
                default => null,
            };
        }

        // Catalog coverage
        $catalogEvents = EventCatalog::all();
        $governedCount = 0;
        foreach (array_keys($catalogEvents) as $catalogName) {
            if (isset($this->registrations[$catalogName])) {
                $governedCount++;
            }
        }
        $catalogCount = count($catalogEvents);
        $catalogCoverage = $catalogCount > 0 ? round(($governedCount / $catalogCount) * 100, 2) : 100.0;

        // Naming compliance score
        $namingScore = $this->namingService->catalogComplianceScore();

        // Data quality score
        $qualityScore = $this->qualityScorer->overallScore();

        // Duplicate risk (GA4 name collisions)
        $ga4Names = [];
        foreach ($this->registrations as $reg) {
            $catalogEntry = EventCatalog::get($reg['name']);
            if ($catalogEntry !== null) {
                $ga4Name = $catalogEntry['ga4'] ?? $reg['name'];
                $ga4Names[$ga4Name][] = $reg['name'];
            }
        }
        $duplicateRisk = 0;
        foreach ($ga4Names as $ga4Name => $events) {
            if (count($events) > 1) {
                $duplicateRisk += count($events) - 1;
            }
        }

        // Composite governance score
        $governanceScore = $total > 0
            ? round(($catalogCoverage * 0.3) + ($namingScore * 0.2) + ($qualityScore * 0.3) + (($duplicateRisk === 0 ? 100 : max(0, 100 - $duplicateRisk * 20)) * 0.2), 2)
            : 0.0;

        return [
            'total_events' => $total,
            'active' => $active,
            'draft' => $draft,
            'deprecated' => $deprecated,
            'retired' => $retired,
            'catalog_coverage' => $catalogCoverage,
            'naming_score' => $namingScore,
            'quality_score' => $qualityScore,
            'duplicate_risk' => $duplicateRisk,
            'governance_score' => $governanceScore,
        ];
    }

    /**
     * Get all registrations filtered by status.
     *
     * @param  string|null  $status  Filter by status (null = all)
     * @return list<EventRegistration>
     */
    public function registrations(?string $status = null): array
    {
        if ($status === null) {
            return array_values($this->registrations);
        }

        return array_values(array_filter(
            $this->registrations,
            fn (array $reg): bool => $reg['status'] === $status,
        ));
    }

    /**
     * Get a single event registration by name.
     *
     * @return EventRegistration|null
     */
    public function getRegistration(string $name): ?array
    {
        return $this->registrations[$name] ?? null;
    }

    /**
     * Get events that need attention (draft or deprecated).
     *
     * @return list<array{event: string, status: string, owner: string, action: string}>
     */
    public function attentionRequired(): array
    {
        $items = [];

        foreach ($this->registrations as $reg) {
            if ($reg['status'] === 'draft') {
                $items[] = [
                    'event' => $reg['name'],
                    'status' => 'draft',
                    'owner' => $reg['owner'],
                    'action' => 'activate',
                ];
            }

            if ($reg['status'] === 'deprecated') {
                $items[] = [
                    'event' => $reg['name'],
                    'status' => 'deprecated',
                    'owner' => $reg['owner'],
                    'action' => 'retire',
                ];
            }
        }

        return $items;
    }

    /**
     * Get deprecation warnings for events dispatched in the last N days.
     *
     * @return list<array{event: string, deprecated_at: string|null, replacement: string|null, dispatch_count: int}>
     */
    public function deprecationWarnings(int $days = 30): array
    {
        return $this->deprecationService->warnings($days);
    }

    /**
     * Check if governance is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if governance enforcement on dispatch is enabled.
     */
    public function isEnforced(): bool
    {
        return $this->enabled && $this->enforceOnDispatch;
    }

    /**
     * Get the naming convention service.
     */
    public function naming(): EventNamingConventionService
    {
        return $this->namingService;
    }

    /**
     * Get the deprecation service.
     */
    public function deprecation(): EventDeprecationService
    {
        return $this->deprecationService;
    }

    /**
     * Get the data quality scorer.
     */
    public function quality(): DataQualityScorer
    {
        return $this->qualityScorer;
    }

    /**
     * Compute a schema hash for change detection.
     *
     * @param  list<string>  $requiredParams
     * @param  list<string>  $optionalParams
     */
    private function computeSchemaHash(array $requiredParams, array $optionalParams): string
    {
        $payload = json_encode([
            'required' => $requiredParams,
            'optional' => $optionalParams,
        ], JSON_THROW_ON_ERROR);

        return hash('xxh128', $payload);
    }

    /**
     * Load registrations from cache.
     */
    private function loadRegistrations(): void
    {
        try {
            $cached = $this->cache->get(self::CACHE_PREFIX . 'registrations');

            if (is_array($cached)) {
                $this->registrations = $cached;

                return;
            }
        } catch (\Throwable $e) {
            // Cache unavailable
        }

        $this->registrations = [];
    }

    /**
     * Persist registrations to cache.
     */
    private function persistRegistrations(): void
    {
        try {
            $this->cache->put(
                self::CACHE_PREFIX . 'registrations',
                $this->registrations,
                $this->cacheTtl,
            );
        } catch (\Throwable $e) {
            // Cache unavailable
        }
    }
}
