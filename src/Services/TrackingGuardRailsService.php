<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Analytics Guard Rails — tracking quality monitoring engine.
 *
 * Monitors the health of your analytics instrumentation in real-time,
 * inspired by Amplitude Compass, Mixpanel Data Governance, and Segment
 * Protocols. Computes a composite quality score (0-100) across multiple
 * dimensions and generates actionable recommendations.
 *
 * Dimensions:
 * - **Schema Compliance** (25%) — % of events with valid schema registrations
 * - **Naming Convention** (20%) — % of events following snake_case convention
 * - **Coverage Completeness** (20%) — % of core SaaS lifecycle events tracked
 * - **Provider Coverage** (15%) — % of providers receiving events
 * - **Identity Linking** (10%) — client ID ↔ user ID link rate
 * - **Consent Compliance** (10%) — GDPR consent mode defaults and logging
 *
 * Configuration: `zeroboiler.analytics.guard_rails`
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 *
 * @since 8.9.0
 */
final class TrackingGuardRailsService
{
    private const CACHE_PREFIX = 'zb_guard_rails_';

    private const DEFAULT_CACHE_TTL = 300; // 5 minutes

    private CacheRepository $cache;

    private ConfigRepository $config;

    private AnalyticsManager $manager;

    private bool $enabled;

    private int $cacheTtl;

    /**
     * Minimum events threshold before coverage scoring is activated.
     * Prevents noisy scores when tracking is first set up.
     */
    private int $minimumEvents;

    /**
     * Core SaaS events that every deployment should track.
     *
     * @var list<string>
     */
    private const CORE_EVENTS = [
        'sign_up',
        'login',
        'logout',
        'start_trial',
        'trial_converted',
        'subscribe',
        'plan_upgrade',
        'plan_downgrade',
        'cancellation',
        'page_view',
        'purchase',
    ];

    /**
     * Critical SaaS events required for industry-standard readiness.
     *
     * @var list<string>
     */
    private const CRITICAL_EVENTS = [
        'sign_up',
        'login',
        'page_view',
        'purchase',
        'start_trial',
        'subscribe',
    ];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     * @param  AnalyticsManager  $manager
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        AnalyticsManager $manager,
    ){
        $this->cache = $cache;
        $this->config = $config;
        $this->manager = $manager;

        $guardConfig = $config->get('zeroboiler.analytics.guard_rails', []);
        /** @var array{enabled?: bool, cache_ttl?: int, minimum_events?: int} $guardConfig */
        $this->enabled = (bool) ($guardConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($guardConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->minimumEvents = (int) ($guardConfig['minimum_events'] ?? 100);
    }

    /**
     * Run a full guard rails check and return the composite report.
     *
     * @param  array{total_events?: int, tracked_event_names?: list<string>, identity_linked_count?: int, total_clients?: int, consent_log_enabled?: bool, consent_default?: string, schema_registered_count?: int}  $metrics  Optional pre-computed metrics (allows decoupling from real-time data)
     * @return array{score: int, grade: string, dimensions: array<string, array{score: int, weight: int, label: string, status: string, details: array<string, mixed>}>, violations: list<array{severity: string, dimension: string, message: string, recommendation: string}>, recommendations: list<string>, generated_at: string, coverage: array{total_events: int, core_tracked: list<string>, core_missing: list<string>, completeness: float}, naming: array{total: int, compliant: int, rate: float, violations: list<string>}}
     */
    public function check(array $metrics = []): array
    {
        if (! $this->enabled) {
            return $this->disabledCheck();
        }

        $cacheKey = self::CACHE_PREFIX . 'check_' . md5(json_encode($metrics, JSON_THROW_ON_ERROR));
        $generation = (int) $this->cache->get(self::CACHE_PREFIX . 'generation', 0);
        $cacheKey .= '_g' . $generation;

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            /** @var array{score: int, grade: string, dimensions: array<string, array{score: int, weight: int, label: string, status: string, details: array<string, mixed>}>, violations: list<array{severity: string, dimension: string, message: string, recommendation: string}>, recommendations: list<string>, generated_at: string, coverage: array{total_events: int, core_tracked: list<string>, core_missing: list<string>, completeness: float}, naming: array{total: int, compliant: int, rate: float, violations: list<string>}} $cached */
            return $cached;
        }

        $report = $this->buildCheck($metrics);

        $this->cache->put($cacheKey, $report, $this->cacheTtl);

        return $report;
    }

    /**
     * Get the quick quality score without the full report.
     *
     * Useful for badge widgets and notification indicators.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{score: int, grade: string, label: string, generated_at: string}
     */
    public function quickScore(array $metrics = []): array
    {
        $report = $this->check($metrics);

        $score = $report['score'];
        $grade = $report['grade'];

        $label = match (true) {
            $grade === 'A' => 'Excellent — production-grade instrumentation',
            $grade === 'B' => 'Good — minor gaps to address',
            $grade === 'C' => 'Fair — notable instrumentation gaps',
            $grade === 'D' => 'Poor — significant issues detected',
            $grade === 'F' => 'Critical — analytics instrumentation is unreliable',
            default => 'Unknown',
        };

        return [
            'score' => $score,
            'grade' => $grade,
            'label' => $label,
            'generated_at' => $report['generated_at'],
        ];
    }

    /**
     * Get violations only — useful for alerting integrations.
     *
     * Returns only violations above the given severity threshold.
     *
     * @param  array<string, mixed>  $metrics
     * @param  string  $minSeverity  'critical'|'warning'|'info'
     * @return list<array{severity: string, dimension: string, message: string, recommendation: string}>
     */
    public function violations(array $metrics = [], string $minSeverity = 'info'): array
    {
        $report = $this->check($metrics);
        $severityLevels = ['critical' => 3, 'warning' => 2, 'info' => 1];
        $threshold = $severityLevels[$minSeverity] ?? 1;

        return array_values(array_filter(
            $report['violations'],
            fn (array $v): bool => ($severityLevels[$v['severity']] ?? 0) >= $threshold,
        ));
    }

    /**
     * Validate a single event name against naming conventions.
     *
     * @param  string  $eventName
     * @return array{valid: bool, issues: list<string>, suggestion?: string}
     */
    public function validateEventName(string $eventName): array
    {
        $issues = [];

        // Must be lowercase snake_case
        if ($eventName !== strtolower($eventName)) {
            $issues[] = 'Event name must be lowercase';
        }

        if (! preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $eventName)) {
            $issues[] = 'Event name must use snake_case (e.g., user_signed_up)';
        }

        // Check for reserved prefixes
        if (str_starts_with($eventName, 'zb_') || str_starts_with($eventName, '_')) {
            $issues[] = 'Event name must not use reserved prefixes (zb_, _)';
        }

        // Check length
        if (strlen($eventName) > 50) {
            $issues[] = 'Event name exceeds 50 character limit';
        }

        if (strlen($eventName) < 2) {
            $issues[] = 'Event name must be at least 2 characters';
        }

        // Generate suggestion from issues
        $suggestion = null;
        if ($issues !== []) {
            $suggestion = $this->suggestEventName($eventName);
        }

        return [
            'valid' => $issues === [],
            'issues' => $issues,
            'suggestion' => $suggestion,
        ];
    }

    /**
     * Get the list of core SaaS events and whether they're tracked.
     *
     * @param  list<string>  $trackedEventNames  Event names currently being tracked
     * @return array{required: list<string>, tracked: list<string>, missing: list<string>, completeness: float}
     */
    public function coreEventCoverage(array $trackedEventNames = []): array
    {
        $tracked = array_values(array_intersect(self::CORE_EVENTS, $trackedEventNames));
        $missing = array_values(array_diff(self::CORE_EVENTS, $trackedEventNames));
        $completeness = count(self::CORE_EVENTS) > 0
            ? round(count($tracked) / count(self::CORE_EVENTS) * 100, 1)
            : 0.0;

        return [
            'required' => self::CORE_EVENTS,
            'tracked' => $tracked,
            'missing' => $missing,
            'completeness' => $completeness,
        ];
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Clear the guard rails cache.
     *
     * Since check() uses content-hashed cache keys, we flush by
     * incrementing a generation counter. All subsequent check() calls
     * will use a new generation suffix, effectively invalidating old entries.
     */
    public function clearCache(): void
    {
        $generation = (int) $this->cache->get(self::CACHE_PREFIX . 'generation', 0);
        $this->cache->put(self::CACHE_PREFIX . 'generation', $generation + 1, $this->cacheTtl * 2);
    }

    /**
     * Build the complete guard rails check.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{score: int, grade: string, dimensions: array<string, array{score: int, weight: int, label: string, status: string, details: array<string, mixed>}>, violations: list<array{severity: string, dimension: string, message: string, recommendation: string}>, recommendations: list<string>, generated_at: string, coverage: array{total_events: int, core_tracked: list<string>, core_missing: list<string>, completeness: float}, naming: array{total: int, compliant: int, rate: float, violations: list<string>}}
     */
    private function buildCheck(array $metrics): array
    {
        $dimensions = [];
        $allViolations = [];
        $allRecommendations = [];

        // ── Dimension 1: Schema Compliance (25%) ──────────────────
        $schemaResult = $this->assessSchemaCompliance($metrics);
        $dimensions['schema_compliance'] = [
            'score' => $schemaResult['score'],
            'weight' => 25,
            'label' => 'Schema Compliance',
            'status' => $this->statusFromScore($schemaResult['score']),
            'details' => $schemaResult['details'],
        ];
        $allViolations = array_merge($allViolations, $schemaResult['violations']);
        $allRecommendations = array_merge($allRecommendations, $schemaResult['recommendations']);

        // ── Dimension 2: Naming Convention (20%) ──────────────────
        $namingResult = $this->assessNamingConvention($metrics);
        $dimensions['naming_convention'] = [
            'score' => $namingResult['score'],
            'weight' => 20,
            'label' => 'Naming Convention',
            'status' => $this->statusFromScore($namingResult['score']),
            'details' => $namingResult['details'],
        ];
        $allViolations = array_merge($allViolations, $namingResult['violations']);
        $allRecommendations = array_merge($allRecommendations, $namingResult['recommendations']);

        // ── Dimension 3: Coverage Completeness (20%) ──────────────
        $coverageResult = $this->assessCoverageCompleteness($metrics);
        $dimensions['coverage_completeness'] = [
            'score' => $coverageResult['score'],
            'weight' => 20,
            'label' => 'Coverage Completeness',
            'status' => $this->statusFromScore($coverageResult['score']),
            'details' => $coverageResult['details'],
        ];
        $allViolations = array_merge($allViolations, $coverageResult['violations']);
        $allRecommendations = array_merge($allRecommendations, $coverageResult['recommendations']);

        // ── Dimension 4: Provider Coverage (15%) ──────────────────
        $providerResult = $this->assessProviderCoverage();
        $dimensions['provider_coverage'] = [
            'score' => $providerResult['score'],
            'weight' => 15,
            'label' => 'Provider Coverage',
            'status' => $this->statusFromScore($providerResult['score']),
            'details' => $providerResult['details'],
        ];
        $allViolations = array_merge($allViolations, $providerResult['violations']);
        $allRecommendations = array_merge($allRecommendations, $providerResult['recommendations']);

        // ── Dimension 5: Identity Linking (10%) ───────────────────
        $identityResult = $this->assessIdentityLinking($metrics);
        $dimensions['identity_linking'] = [
            'score' => $identityResult['score'],
            'weight' => 10,
            'label' => 'Identity Linking',
            'status' => $this->statusFromScore($identityResult['score']),
            'details' => $identityResult['details'],
        ];
        $allViolations = array_merge($allViolations, $identityResult['violations']);
        $allRecommendations = array_merge($allRecommendations, $identityResult['recommendations']);

        // ── Dimension 6: Consent Compliance (10%) ────────────────
        $consentResult = $this->assessConsentCompliance();
        $dimensions['consent_compliance'] = [
            'score' => $consentResult['score'],
            'weight' => 10,
            'label' => 'Consent Compliance',
            'status' => $this->statusFromScore($consentResult['score']),
            'details' => $consentResult['details'],
        ];
        $allViolations = array_merge($allViolations, $consentResult['violations']);
        $allRecommendations = array_merge($allRecommendations, $consentResult['recommendations']);

        // Compute composite score
        $compositeScore = 0;
        $totalWeight = 0;
        foreach ($dimensions as $dim) {
            $compositeScore += $dim['score'] * $dim['weight'];
            $totalWeight += $dim['weight'];
        }
        $compositeScore = $totalWeight > 0 ? (int) round($compositeScore / $totalWeight) : 0;
        $compositeScore = max(0, min(100, $compositeScore));

        $grade = $this->gradeFromScore($compositeScore);

        // Deduplicate recommendations
        $uniqueRecommendations = array_values(array_unique($allRecommendations));

        // Build coverage summary
        $trackedNames = $metrics['tracked_event_names'] ?? [];
        $coreCoverage = $this->coreEventCoverage($trackedNames);

        // Build naming summary
        $namingSummary = $namingResult['details'];

        return [
            'score' => $compositeScore,
            'grade' => $grade,
            'dimensions' => $dimensions,
            'violations' => array_values($allViolations),
            'recommendations' => $uniqueRecommendations,
            'generated_at' => now()->toIso8601String(),
            'coverage' => [
                'total_events' => (int) ($metrics['total_events'] ?? 0),
                'core_tracked' => $coreCoverage['tracked'],
                'core_missing' => $coreCoverage['missing'],
                'completeness' => $coreCoverage['completeness'],
            ],
            'naming' => [
                'total' => (int) ($namingSummary['total'] ?? 0),
                'compliant' => (int) ($namingSummary['compliant'] ?? 0),
                'rate' => (float) ($namingSummary['rate'] ?? 0.0),
                'violations' => (array) ($namingSummary['violations'] ?? []),
            ],
        ];
    }

    /**
     * Assess schema compliance dimension.
     *
     * Checks the ratio of tracked events that have corresponding
     * schema registrations in the EventSchemaRegistry.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{score: int, details: array<string, mixed>, violations: list<array{severity: string, dimension: string, message: string, recommendation: string}>, recommendations: list<string>}
     */
    private function assessSchemaCompliance(array $metrics): array
    {
        $violations = [];
        $recommendations = [];

        $trackedNames = $metrics['tracked_event_names'] ?? [];
        $schemaRegistered = (int) ($metrics['schema_registered_count'] ?? 0);
        $totalTracked = count($trackedNames);

        if ($totalTracked === 0) {
            return [
                'score' => 0,
                'details' => [
                    'total_tracked' => 0,
                    'schema_registered' => 0,
                    'rate' => 0.0,
                    'message' => 'No events tracked — instrumentation not started',
                ],
                'violations' => [[
                    'severity' => 'critical',
                    'dimension' => 'schema_compliance',
                    'message' => 'No events are being tracked',
                    'recommendation' => 'Implement initial event tracking for core SaaS lifecycle events',
                ]],
                'recommendations' => ['Start tracking core SaaS lifecycle events (sign_up, login, page_view)'],
            ];
        }

        // Calculate: how many tracked events exist in the catalog
        $catalogMatched = 0;
        foreach ($trackedNames as $name) {
            if (EventCatalog::has((string) $name)) {
                $catalogMatched++;
            }
        }

        // Schema compliance = % of tracked events that are catalog-registered
        // Higher weight to catalog matching over raw schema count
        $rate = $totalTracked > 0 ? round($catalogMatched / $totalTracked, 2) : 0.0;
        $score = (int) round($rate * 100);

        if ($rate < 0.5) {
            $violations[] = [
                'severity' => 'warning',
                'dimension' => 'schema_compliance',
                'message' => "Only {$rate}% of tracked events are in the catalog",
                'recommendation' => 'Register custom events in the catalog or use standard event names',
            ];
            $recommendations[] = 'Use standard catalog event names instead of custom names';
        }

        if ($score >= 90) {
            $score = 100; // Perfect score for high compliance
        }

        return [
            'score' => $score,
            'details' => [
                'total_tracked' => $totalTracked,
                'catalog_matched' => $catalogMatched,
                'schema_registered' => $schemaRegistered,
                'rate' => $rate,
            ],
            'violations' => $violations,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess naming convention compliance.
     *
     * Validates tracked event names against the snake_case convention.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{score: int, details: array<string, mixed>, violations: list<array{severity: string, dimension: string, message: string, recommendation: string}>, recommendations: list<string>}
     */
    private function assessNamingConvention(array $metrics): array
    {
        $violations = [];
        $recommendations = [];
        $namingViolations = [];

        $trackedNames = $metrics['tracked_event_names'] ?? [];
        $total = count($trackedNames);

        if ($total === 0) {
            return [
                'score' => 0,
                'details' => [
                    'total' => 0,
                    'compliant' => 0,
                    'rate' => 0.0,
                    'violations' => [],
                ],
                'violations' => [],
                'recommendations' => [],
            ];
        }

        $compliant = 0;
        $pattern = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

        foreach ($trackedNames as $name) {
            $name = (string) $name;
            if (preg_match($pattern, $name) && strlen($name) >= 2 && strlen($name) <= 50 && ! str_starts_with($name, 'zb_')) {
                $compliant++;
            } else {
                $namingViolations[] = $name;
            }
        }

        $rate = round($compliant / $total, 2);
        $score = (int) round($rate * 100);

        if ($rate < 0.8 && $total > 5) {
            $violations[] = [
                'severity' => 'warning',
                'dimension' => 'naming_convention',
                'message' => sprintf('%d of %d events violate naming conventions', $total - $compliant, $total),
                'recommendation' => 'Rename events to snake_case (e.g., userSignedUp → user_signed_up)',
            ];
            $recommendations[] = 'Adopt snake_case naming convention for all custom events';
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $total,
                'compliant' => $compliant,
                'rate' => $rate,
                'violations' => array_slice($namingViolations, 0, 20), // Limit output
            ],
            'violations' => $violations,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess coverage completeness — are core SaaS lifecycle events tracked?
     *
     * @param  array<string, mixed>  $metrics
     * @return array{score: int, details: array<string, mixed>, violations: list<array{severity: string, dimension: string, message: string, recommendation: string}>, recommendations: list<string>}
     */
    private function assessCoverageCompleteness(array $metrics): array
    {
        $violations = [];
        $recommendations = [];

        $trackedNames = $metrics['tracked_event_names'] ?? [];
        $totalEvents = (int) ($metrics['total_events'] ?? 0);

        $coreCoverage = $this->coreEventCoverage($trackedNames);
        $completeness = $coreCoverage['completeness'] / 100.0;

        // Check if we have enough data to assess
        if ($totalEvents < $this->minimumEvents && $totalEvents > 0) {
            return [
                'score' => 50, // Neutral score during ramp-up
                'details' => [
                    'completeness' => $coreCoverage['completeness'],
                    'core_tracked' => $coreCoverage['tracked'],
                    'core_missing' => $coreCoverage['missing'],
                    'total_events' => $totalEvents,
                    'minimum_threshold' => $this->minimumEvents,
                    'message' => "Below minimum event threshold ({$this->minimumEvents}) — score deferred",
                ],
                'violations' => [],
                'recommendations' => [],
            ];
        }

        if ($totalEvents === 0) {
            return [
                'score' => 0,
                'details' => [
                    'completeness' => 0.0,
                    'core_tracked' => [],
                    'core_missing' => self::CORE_EVENTS,
                    'total_events' => 0,
                    'message' => 'No events tracked — coverage cannot be assessed',
                ],
                'violations' => [[
                    'severity' => 'critical',
                    'dimension' => 'coverage_completeness',
                    'message' => 'No events are being tracked',
                    'recommendation' => 'Implement initial event tracking for at least the critical SaaS events',
                ]],
                'recommendations' => ['Track at minimum: ' . implode(', ', self::CRITICAL_EVENTS)],
            ];
        }

        // Check for critical missing events
        $criticalMissing = array_values(array_diff(self::CRITICAL_EVENTS, $trackedNames));
        if ($criticalMissing !== []) {
            $violations[] = [
                'severity' => 'critical',
                'dimension' => 'coverage_completeness',
                'message' => sprintf('Critical events not tracked: %s', implode(', ', $criticalMissing)),
                'recommendation' => 'Implement tracking for all critical SaaS lifecycle events',
            ];
            $recommendations[] = 'Track critical events: ' . implode(', ', $criticalMissing);
        }

        $score = (int) round($completeness * 100);

        return [
            'score' => $score,
            'details' => [
                'completeness' => $coreCoverage['completeness'],
                'core_tracked' => $coreCoverage['tracked'],
                'core_missing' => $coreCoverage['missing'],
                'total_events' => $totalEvents,
            ],
            'violations' => $violations,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess provider coverage — are events being sent to multiple providers?
     *
     * @return array{score: int, details: array<string, mixed>, violations: list<array{severity: string, dimension: string, message: string, recommendation: string}>, recommendations: list<string>}
     */
    private function assessProviderCoverage(): array
    {
        $violations = [];
        $recommendations = [];

        $providers = [
            'ga4' => $this->manager->ga4()->isEnabled(),
            'gtm' => $this->manager->gtm()->isEnabled(),
            'meta' => $this->manager->meta()->isEnabled(),
            'plausible' => $this->manager->plausible()->isEnabled(),
            'posthog' => $this->manager->posthog()->isEnabled(),
        ];

        $enabledCount = count(array_filter($providers));
        $totalProviders = count($providers);

        // Scoring: 1 provider = 60, 2 = 80, 3+ = 100
        $score = match (true) {
            $enabledCount === 0 => 0,
            $enabledCount === 1 => 60,
            $enabledCount === 2 => 80,
            default => 100,
        };

        if ($enabledCount === 0) {
            $violations[] = [
                'severity' => 'critical',
                'dimension' => 'provider_coverage',
                'message' => 'No analytics providers are configured',
                'recommendation' => 'Enable at least one analytics provider (GA4, GTM, Meta Pixel, Plausible, PostHog)',
            ];
            $recommendations[] = 'Configure at least GA4 or Meta Pixel to start tracking';
        } elseif ($enabledCount === 1) {
            $recommendations[] = 'Consider enabling a second provider for data redundancy and cross-validation';
        }

        return [
            'score' => $score,
            'details' => [
                'enabled_providers' => array_keys(array_filter($providers)),
                'disabled_providers' => array_keys(array_filter($providers, fn (bool $v): bool => ! $v)),
                'enabled_count' => $enabledCount,
                'total_count' => $totalProviders,
                'rate' => $totalProviders > 0 ? round($enabledCount / $totalProviders, 2) : 0.0,
            ],
            'violations' => $violations,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess identity linking — are anonymous users being linked to authenticated users?
     *
     * @param  array<string, mixed>  $metrics
     * @return array{score: int, details: array<string, mixed>, violations: list<array{severity: string, dimension: string, message: string, recommendation: string}>, recommendations: list<string>}
     */
    private function assessIdentityLinking(array $metrics): array
    {
        $violations = [];
        $recommendations = [];

        $linkedCount = (int) ($metrics['identity_linked_count'] ?? 0);
        $totalClients = (int) ($metrics['total_clients'] ?? 0);

        // Check config-based identity linking
        $linkOnAuth = $this->config->get('zeroboiler.analytics.identity.link_on_auth', true);

        if (! $linkOnAuth) {
            $violations[] = [
                'severity' => 'warning',
                'dimension' => 'identity_linking',
                'message' => 'Identity auto-linking is disabled',
                'recommendation' => 'Enable identity.link_on_auth for automatic client ID ↔ user ID linking',
            ];
            $recommendations[] = 'Enable identity auto-linking for cross-device attribution';
        }

        // Score based on link rate
        if ($totalClients > 0) {
            $linkRate = round($linkedCount / $totalClients, 2);
            $score = (int) round($linkRate * 100);

            if ($linkRate < 0.1 && $totalClients > 50) {
                $violations[] = [
                    'severity' => 'warning',
                    'dimension' => 'identity_linking',
                    'message' => sprintf('Low identity link rate: %.1f%% (%d/%d clients)', $linkRate * 100, $linkedCount, $totalClients),
                    'recommendation' => 'Ensure identify() is called on user authentication',
                ];
            }
        } else {
            // No client data — score based on config alone
            $score = $linkOnAuth ? 70 : 30;
            $linkRate = 0.0;
        }

        return [
            'score' => $score,
            'details' => [
                'linked_count' => $linkedCount,
                'total_clients' => $totalClients,
                'link_rate' => $totalClients > 0 ? round($linkedCount / $totalClients, 2) : 0.0,
                'auto_link_enabled' => $linkOnAuth,
            ],
            'violations' => $violations,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess consent compliance — GDPR-ready defaults.
     *
     * @return array{score: int, details: array<string, mixed>, violations: list<array{severity: string, dimension: string, message: string, recommendation: string}>, recommendations: list<string>}
     */
    private function assessConsentCompliance(): array
    {
        $violations = [];
        $recommendations = [];

        $consentConfig = $this->config->get('zeroboiler.analytics.consent', []);
        /** @var array{default?: string, log_enabled?: bool, purposes?: array<string, mixed>} $consentConfig */
        $defaultConsent = (string) ($consentConfig['default'] ?? 'granted');
        $logEnabled = (bool) ($consentConfig['log_enabled'] ?? false);
        $purposes = (array) ($consentConfig['purposes'] ?? []);

        $score = 50; // Base score

        // GDPR-safe defaults (+30)
        if ($defaultConsent === 'denied') {
            $score += 30;
        } else {
            $violations[] = [
                'severity' => 'warning',
                'dimension' => 'consent_compliance',
                'message' => 'Consent default is "granted" — not GDPR-safe',
                'recommendation' => 'Set consent.default to "denied" for GDPR compliance',
            ];
            $recommendations[] = 'Set ANALYTICS_CONSENT_DEFAULT=denied for GDPR compliance';
            $score += 10;
        }

        // Consent logging (+20)
        if ($logEnabled) {
            $score += 20;
        } else {
            $recommendations[] = 'Enable consent logging for Article 7 compliance records';
        }

        // Consent purposes defined (+0-10)
        if ($purposes !== []) {
            $score += 5;
        }

        $score = min(100, $score);

        return [
            'score' => $score,
            'details' => [
                'default' => $defaultConsent,
                'log_enabled' => $logEnabled,
                'purposes_count' => count($purposes),
                'gdpr_safe' => $defaultConsent === 'denied',
            ],
            'violations' => $violations,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Derive a status label from a dimension score.
     *
     * @return 'excellent'|'good'|'fair'|'poor'|'critical'
     */
    private function statusFromScore(int $score): string
    {
        return match (true) {
            $score >= 90 => 'excellent',
            $score >= 70 => 'good',
            $score >= 50 => 'fair',
            $score >= 25 => 'poor',
            default => 'critical',
        };
    }

    /**
     * Derive a grade from a composite score.
     *
     * @return 'A'|'B'|'C'|'D'|'F'
     */
    private function gradeFromScore(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };
    }

    /**
     * Generate a snake_case suggestion from a non-compliant event name.
     *
     * @param  string  $name
     * @return string
     */
    private function suggestEventName(string $name): string
    {
        // Convert camelCase to snake_case
        $suggested = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name) ?? $name);

        // Replace spaces and hyphens with underscores
        $suggested = str_replace([' ', '-'], '_', $suggested);

        // Remove double underscores
        $suggested = preg_replace('/_+/', '_', $suggested) ?? $suggested;

        // Remove leading/trailing underscores and reserved prefixes
        $suggested = trim($suggested, '_');
        $suggested = ltrim($suggested, 'zb_');

        return $suggested ?: 'custom_event';
    }

    /**
     * Return a disabled check response.
     *
     * @return array{score: int, grade: string, dimensions: array<empty, empty>, violations: list<empty>, recommendations: list<empty>, generated_at: string, coverage: array{total_events: int, core_tracked: list<empty>, core_missing: list<string>, completeness: float}, naming: array{total: int, compliant: int, rate: float, violations: list<empty>}}
     */
    private function disabledCheck(): array
    {
        return [
            'score' => 0,
            'grade' => 'N/A',
            'dimensions' => [],
            'violations' => [],
            'recommendations' => [],
            'generated_at' => now()->toIso8601String(),
            'coverage' => [
                'total_events' => 0,
                'core_tracked' => [],
                'core_missing' => self::CORE_EVENTS,
                'completeness' => 0.0,
            ],
            'naming' => [
                'total' => 0,
                'compliant' => 0,
                'rate' => 0.0,
                'violations' => [],
            ],
        ];
    }
}
