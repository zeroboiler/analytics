<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * GDPR Article 30 Privacy Manifest — automated processing records generator.
 *
 * Generates GDPR-compliant Records of Processing Activities (RoPA) for
 * all analytics events, providers, and data flows. Produces a structured
 * privacy manifest suitable for DPA (Data Processing Agreement) documentation,
 * regulatory audits, and transparency reports.
 *
 * Covers:
 * - Data categories per event type (PII, behavioral, financial, technical)
 * - Legal basis mapping (consent, legitimate interest, contractual necessity)
 * - Data retention periods per category
 * - Third-party data sharing (providers: GA4, Meta, PostHog, Plausible)
 * - Data subject rights implementation status
 * - Cross-border data transfer assessment
 * - Automated decision-making disclosure
 *
 * Configuration: `zeroboiler.analytics.privacy_manifest`
 *
 * @since 9.3.0
 */
final class PrivacyManifestService
{
    /** @var array{enabled: bool, cache_ttl: int, controller_email: string, dpo_email: string|null, legal_basis_defaults: array<string, string>, retention_defaults: array<string, int>} */
    private array $config;

    private bool $enabled;

    private int $cacheTtl;

    private string $controllerEmail;

    private ?string $dpoEmail;

    /** @var array<string, string> */
    private array $legalBasisDefaults;

    /** @var array<string, int> */
    private array $retentionDefaults;

    /**
     * Built-in data category classification for event types.
     *
     * Maps event name patterns to GDPR data categories.
     *
     * @var array<string, list<string>>
     */
    private const DATA_CATEGORIES = [
        'authentication' => ['identifier', 'behavioral'],
        'subscription' => ['financial', 'contractual'],
        'billing' => ['financial', 'contractual'],
        'trial' => ['contractual', 'behavioral'],
        'feature' => ['behavioral', 'technical'],
        'purchase' => ['financial', 'contractual', 'transactional'],
        'refund' => ['financial', 'transactional'],
        'page_view' => ['technical', 'behavioral'],
        'click' => ['behavioral', 'technical'],
        'scroll' => ['behavioral', 'technical'],
        'search' => ['behavioral'],
        'form' => ['behavioral', 'identifier'],
        'consent' => ['identifier', 'legal'],
        'account' => ['identifier', 'contractual'],
        'team' => ['identifier', 'contractual'],
        'error' => ['technical'],
        'cohort' => ['behavioral', 'statistical'],
        'integration' => ['technical', 'behavioral'],
        'gdpr' => ['legal', 'identifier'],
        'workspace' => ['identifier', 'contractual'],
        'invitation' => ['identifier'],
        'onboarding' => ['behavioral'],
        'revenue' => ['financial'],
        'milestone' => ['behavioral'],
        'notification' => ['behavioral', 'technical'],
    ];

    /**
     * Legal basis options per GDPR Article 6.
     *
     * @var array<string, string>
     */
    private const LEGAL_BASIS_OPTIONS = [
        'consent' => 'Consent (Article 6(1)(a))',
        'contract' => 'Contractual necessity (Article 6(1)(b))',
        'legitimate_interest' => 'Legitimate interest (Article 6(1)(f))',
        'legal_obligation' => 'Legal obligation (Article 6(1)(c))',
    ];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $manifestConfig = $config->get('zeroboiler.analytics.privacy_manifest', []);
        /** @var array{enabled?: bool, cache_ttl?: int, controller_email?: string, dpo_email?: string|null, legal_basis_defaults?: array<string, string>, retention_defaults?: array<string, int>} $manifestConfig */

        $this->config = $manifestConfig;
        $this->enabled = (bool) ($manifestConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($manifestConfig['cache_ttl'] ?? 3600); // 1 hour
        $this->controllerEmail = (string) ($manifestConfig['controller_email'] ?? 'privacy@example.com');
        $this->dpoEmail = $manifestConfig['dpo_email'] ?? null;
        $this->legalBasisDefaults = (array) ($manifestConfig['legal_basis_defaults'] ?? []);
        $this->retentionDefaults = (array) ($manifestConfig['retention_defaults'] ?? []);
    }

    /**
     * Generate the full privacy manifest.
     *
     * Produces a comprehensive GDPR Article 30 RoPA document covering
     * all registered analytics events, data categories, legal bases,
     * retention periods, provider data flows, and DSA compliance.
     *
     * @return array{controller: array<string, mixed>, summary: array<string, mixed>, processing_activities: list<array<string, mixed>>, data_flows: list<array<string, mixed>>, data_subject_rights: array<string, mixed>, cross_border: array<string, mixed>, generated_at: string, version: string}
     */
    public function generate(): array
    {
        $cacheKey = 'zb_privacy_manifest';

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $manifest = [
            'controller' => $this->controllerInfo(),
            'summary' => $this->manifestSummary(),
            'processing_activities' => $this->processingActivities(),
            'data_flows' => $this->dataFlows(),
            'data_subject_rights' => $this->dataSubjectRights(),
            'cross_border' => $this->crossBorderAssessment(),
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
        ];

        try {
            $this->cache->put($cacheKey, $manifest, $this->cacheTtl);
        } catch (\Throwable $e) {
            Log::warning('PrivacyManifestService: failed to cache manifest', [
                'error' => $e->getMessage(),
            ]);
        }

        return $manifest;
    }

    /**
     * Get a summary of the privacy manifest for dashboard display.
     *
     * @return array{total_events: int, data_categories: list<string>, providers: list<string>, pii_events_count: int, consent_required: bool, retention_max_days: int, legal_bases_used: list<string>}
     */
    public function summary(): array
    {
        $manifest = $this->generate();

        $legalBases = array_unique(array_map(
            fn (array $activity): string => $activity['legal_basis'],
            $manifest['processing_activities'],
        ));

        $allCategories = [];
        foreach ($manifest['processing_activities'] as $activity) {
            foreach ($activity['data_categories'] as $cat) {
                $allCategories[$cat] = true;
            }
        }

        return [
            'total_events' => count($manifest['processing_activities']),
            'data_categories' => array_keys($allCategories),
            'providers' => array_column($manifest['data_flows'], 'recipient'),
            'pii_events_count' => count(array_filter(
                $manifest['processing_activities'],
                fn (array $a): bool => in_array('identifier', $a['data_categories'], true),
            )),
            'consent_required' => in_array('consent', $legalBases, true),
            'retention_max_days' => max(
                array_column($manifest['processing_activities'], 'retention_days'),
            ),
            'legal_bases_used' => array_values($legalBases),
        ];
    }

    /**
     * Classify an event into data categories.
     *
     * @param  string  $eventName
     * @return list<string>
     */
    public function classifyEvent(string $eventName): array
    {
        $categories = ['technical']; // All analytics events are technical by default

        foreach (self::DATA_CATEGORIES as $pattern => $cats) {
            if (str_contains($eventName, $pattern)) {
                foreach ($cats as $cat) {
                    if (! in_array($cat, $categories, true)) {
                        $categories[] = $cat;
                    }
                }
            }
        }

        return $categories;
    }

    /**
     * Get the legal basis for an event category.
     *
     * Uses config defaults, then falls back to sensible defaults:
     * - Financial/transactional events → contractual necessity
     * - Behavioral events → legitimate interest
     * - PII events → consent
     * - Technical events → legitimate interest
     *
     * @param  list<string>  $categories
     * @return string
     */
    public function legalBasisFor(array $categories): string
    {
        foreach ($categories as $cat) {
            if (isset($this->legalBasisDefaults[$cat])) {
                return $this->legalBasisDefaults[$cat];
            }
        }

        // Default legal basis by category
        if (in_array('financial', $categories, true) || in_array('transactional', $categories, true)) {
            return 'contract';
        }

        if (in_array('identifier', $categories, true) || in_array('legal', $categories, true)) {
            return 'consent';
        }

        return 'legitimate_interest';
    }

    /**
     * Get retention period in days for an event category.
     *
     * @param  list<string>  $categories
     * @return int Days
     */
    public function retentionFor(array $categories): int
    {
        foreach ($categories as $cat) {
            if (isset($this->retentionDefaults[$cat])) {
                return $this->retentionDefaults[$cat];
            }
        }

        // Default retention by category
        if (in_array('financial', $categories, true) || in_array('transactional', $categories, true)) {
            return 2555; // 7 years (tax/legal requirement)
        }

        if (in_array('identifier', $categories, true)) {
            return 1095; // 3 years
        }

        return 90; // 90 days for behavioral/technical
    }

    /**
     * Check if the privacy manifest service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Invalidate the cached manifest.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget('zb_privacy_manifest');
    }

    /**
     * Build the data controller information section.
     *
     * @return array<string, mixed>
     */
    private function controllerInfo(): array
    {
        return [
            'name' => config('app.name', 'Application'),
            'email' => $this->controllerEmail,
            'dpo_email' => $this->dpoEmail,
            'country' => config('app.timezone', 'UTC'),
            'purposes' => [
                'Analytics and product improvement',
                'User behavior analysis',
                'Conversion funnel optimization',
                'Revenue tracking and reporting',
                'Feature adoption measurement',
            ],
        ];
    }

    /**
     * Build the manifest summary section.
     *
     * @return array<string, mixed>
     */
    private function manifestSummary(): array
    {
        $allEvents = EventCatalog::all();
        $categories = EventCatalog::byCategory();

        return [
            'total_events_registered' => count($allEvents),
            'categories' => array_map(fn (array $cat): int => count($cat), $categories),
            'manifest_scope' => 'ZeroBoiler Analytics — all registered event catalog events',
            'regulation' => 'GDPR (EU) 2016/679 — Article 30 Records of Processing',
            'frameworks' => ['GDPR', 'ePrivacy Directive', 'CCPA (limited support)'],
        ];
    }

    /**
     * Build processing activities for all registered events.
     *
     * @return list<array<string, mixed>>
     */
    private function processingActivities(): array
    {
        $activities = [];
        $allEvents = EventCatalog::all();

        foreach ($allEvents as $name => $entry) {
            $categories = $this->classifyEvent($name);
            $legalBasis = $this->legalBasisFor($categories);
            $retention = $this->retentionFor($categories);

            $activities[] = [
                'event_name' => $name,
                'event_class' => $entry['class'] ?? null,
                'category' => $entry['category'] ?? 'unknown',
                'data_categories' => $categories,
                'legal_basis' => $legalBasis,
                'legal_basis_description' => self::LEGAL_BASIS_OPTIONS[$legalBasis] ?? $legalBasis,
                'retention_days' => $retention,
                'contains_pii' => in_array('identifier', $categories, true),
                'contains_financial' => in_array('financial', $categories, true),
                'automated_decision' => false,
                'profiling' => in_array('behavioral', $categories, true) && in_array('statistical', $categories, true),
            ];
        }

        // Sort by category, then name
        usort($activities, fn (array $a, array $b): int =>
            [$a['category'], $a['event_name']] <=> [$b['category'], $b['event_name']]
        );

        return $activities;
    }

    /**
     * Build data flows to third-party providers.
     *
     * @return list<array<string, mixed>>
     */
    private function dataFlows(): array
    {
        return [
            [
                'recipient' => 'Google Analytics 4 (GA4)',
                'purpose' => 'Web analytics and conversion tracking',
                'data_shared' => ['event_name', 'event_params', 'client_id', 'user_id', 'page_url', 'user_agent'],
                'data_location' => 'United States (with EU data centers available)',
                'safeguards' => ['data_retention_controls', 'ip_anonymization', 'consent_mode_v2'],
                'dpa_required' => true,
                'standard_contractual_clauses' => true,
            ],
            [
                'recipient' => 'Google Tag Manager (GTM)',
                'purpose' => 'Tag management and data layer orchestration',
                'data_shared' => ['event_name', 'event_params', 'consent_state'],
                'data_location' => 'United States',
                'safeguards' => ['consent_mode_v2', 'server_container'],
                'dpa_required' => true,
                'standard_contractual_clauses' => true,
            ],
            [
                'recipient' => 'Meta Platforms (Meta Pixel)',
                'purpose' => 'Advertising conversion tracking and remarketing',
                'data_shared' => ['event_name', 'event_params', 'user_agent', 'ip_address', 'cookie_data'],
                'data_location' => 'United States',
                'safeguards' => ['consent_api', 'first_party_mode', 'capi_server_side'],
                'dpa_required' => true,
                'standard_contractual_clauses' => true,
            ],
            [
                'recipient' => 'PostHog',
                'purpose' => 'Product analytics and feature flag management',
                'data_shared' => ['event_name', 'event_params', 'user_properties', 'device_info'],
                'data_location' => 'EU (Ireland) or US (configurable)',
                'safeguards' => ['data_retention_controls', 'pii_anonymization', 'export capabilities'],
                'dpa_required' => true,
                'standard_contractual_clauses' => true,
            ],
            [
                'recipient' => 'Plausible Analytics',
                'purpose' => 'Privacy-focused web analytics',
                'data_shared' => ['event_name', 'page_url', 'referrer', 'user_agent'],
                'data_location' => 'EU (EU-only hosting available)',
                'safeguards' => ['no_cookies_by_default', 'ip_anonymization', 'gdpr_compliant_by_design'],
                'dpa_required' => true,
                'standard_contractual_clauses' => true,
            ],
        ];
    }

    /**
     * Build data subject rights implementation status.
     *
     * @return array<string, mixed>
     */
    private function dataSubjectRights(): array
    {
        return [
            'right_of_access' => [
                'implemented' => true,
                'endpoint' => 'GET /api/analytics/gdpr/export',
                'description' => 'Users can export all analytics data associated with their identity',
            ],
            'right_to_erasure' => [
                'implemented' => true,
                'endpoint' => 'DELETE /api/analytics/data',
                'description' => 'Users can request deletion of all analytics data (GDPR right to be forgotten)',
            ],
            'right_to_rectification' => [
                'implemented' => false,
                'description' => 'Analytics data is observational — no user-supplied data to correct',
            ],
            'right_to_portability' => [
                'implemented' => true,
                'endpoint' => 'GET /api/analytics/gdpr/export',
                'description' => 'Export available in JSON format for data portability',
            ],
            'right_to_object' => [
                'implemented' => true,
                'endpoint' => 'POST /api/analytics/opt-out',
                'description' => 'Users can opt out of all analytics tracking',
            ],
            'right_to_restrict' => [
                'implemented' => true,
                'endpoint' => 'POST /api/analytics/consent',
                'description' => 'Granular consent purposes allow restricting processing categories',
            ],
        ];
    }

    /**
     * Build cross-border data transfer assessment.
     *
     * @return array<string, mixed>
     */
    private function crossBorderAssessment(): array
    {
        return [
            'transfers_outside_eea' => true,
            'transfer_mechanism' => 'Standard Contractual Clauses (SCCs) — EU Commission 2021/914',
            'supplemental_measures' => [
                'IP anonymization (server-side, configurable)',
                'Consent Mode v2 (GA4/Meta)',
                'Server-side event dispatch (bypasses ad blockers)',
                'Data minimization controls',
                'Configurable retention policies',
            ],
            'transfer_impact_assessment' => 'Provider-specific — refer to individual DPA agreements',
            'adequacy_countries' => ['EU/EEA', 'United Kingdom (post-Brexit adequacy decision)'],
        ];
    }
}
