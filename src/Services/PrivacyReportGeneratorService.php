<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * GDPR/CCPA privacy compliance report generator.
 *
 * Generates audit-ready reports for regulatory compliance:
 *
 * - **GDPR Article 30** (Records of Processing Activities): Documents
 *   all analytics data processing activities, purposes, data categories,
 *   retention periods, and technical measures.
 *
 * - **CCPA Data Inventory**: Lists all personal data fields collected,
 *   their sources, purposes, and whether they are sold/shared.
 *
 * - **Consent Audit**: Generates a summary of consent states across
 *   purposes with timestamps and user counts.
 *
 * - **Data Subject Access Report**: Produces a complete export of all
 *   analytics data held for a specific user identity (for DSAR requests).
 *
 * Reports are cacheable and can be exported as structured arrays
 * suitable for JSON, CSV, or PDF rendering.
 *
 * Configuration: `zeroboiler.analytics.privacy_report`
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsConsentComplianceService
 * @see \ZeroBoiler\Analytics\Services\ConsentLogService
 * @see \ZeroBoiler\Analytics\Services\GdprErasureService
 *
 * @since 29.0.0
 */
final class PrivacyReportGeneratorService
{
    private const CACHE_PREFIX = 'zb_privacy_report_';
    private const DEFAULT_REPORT_TTL = 3600; // 1 hour

    private CacheRepository $cache;

    private ConfigRepository $config;

    private UserPropertiesStore $propertiesStore;

    private IdentityResolutionService $identityResolution;

    private CustomerProfileUnificationService $cdp;

    private string $cachePrefix;

    private int $reportTtl;

    private bool $enabled;

    private string $organizationName;

    private string $dpoContact;

    private string $jurisdiction;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     * @param  UserPropertiesStore  $propertiesStore
     * @param  IdentityResolutionService  $identityResolution
     * @param  CustomerProfileUnificationService  $cdp
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        UserPropertiesStore $propertiesStore,
        IdentityResolutionService $identityResolution,
        CustomerProfileUnificationService $cdp,
    ){
        $this->cache = $cache;
        $this->config = $config;
        $this->propertiesStore = $propertiesStore;
        $this->identityResolution = $identityResolution;
        $this->cdp = $cdp;

        $privacyConfig = $config->get('zeroboiler.analytics.privacy_report', []);
        /** @var array{enabled?: bool, cache_prefix?: string, report_ttl?: int, organization_name?: string, dpo_contact?: string, jurisdiction?: string} $privacyConfig */

        $this->cachePrefix = (string) ($privacyConfig['cache_prefix'] ?? self::CACHE_PREFIX);
        $this->reportTtl = (int) ($privacyConfig['report_ttl'] ?? self::DEFAULT_REPORT_TTL);
        $this->enabled = (bool) ($privacyConfig['enabled'] ?? true);
        $this->organizationName = (string) ($privacyConfig['organization_name'] ?? $config->get('app.name', 'Application'));
        $this->dpoContact = (string) ($privacyConfig['dpo_contact'] ?? '');
        $this->jurisdiction = (string) ($privacyConfig['jurisdiction'] ?? 'GDPR');
    }

    /**
     * Generate GDPR Article 30 — Records of Processing Activities (ROPA).
     *
     * Documents all analytics processing activities as required by
     * GDPR Article 30(1). Output is a structured array suitable for
     * rendering as PDF or exporting to regulators.
     *
     * @param  bool  $fresh  Force rebuild (ignore cache)
     * @return array{
     *     report_type: string,
     *     jurisdiction: string,
     *     organization: string,
     *     generated_at: string,
     *     controller: array{name: string, dpo_contact: string, role: string},
     *     processing_activities: list<array{name: string, purpose: string, legal_basis: string, data_categories: list<string>, data_subjects: list<string>, retention: string, technical_measures: list<string>, recipients: list<string>}>
     * }
     */
    public function generateArticle30Report(bool $fresh = false): array
    {
        if (! $this->enabled) {
            return $this->disabledReport('article_30');
        }

        $cacheKey = $this->cachePrefix . 'article_30';

        if (! $fresh) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $activities = $this->buildProcessingActivities();
        $report = [
            'report_type' => 'GDPR Article 30 — Records of Processing Activities',
            'jurisdiction' => $this->jurisdiction,
            'organization' => $this->organizationName,
            'generated_at' => date('c'),
            'controller' => [
                'name' => $this->organizationName,
                'dpo_contact' => $this->dpoContact,
                'role' => 'Data Controller',
            ],
            'processing_activities' => $activities,
        ];

        $this->cache->put($cacheKey, $report, $this->reportTtl);

        Log::info('PrivacyReport: GDPR Article 30 report generated', [
            'activities' => count($activities),
        ]);

        return $report;
    }

    /**
     * Generate a CCPA data inventory report.
     *
     * Lists all personal data fields collected by the analytics system,
     * their sources, collection purposes, and whether they are shared.
     *
     * @param  bool  $fresh  Force rebuild
     * @return array{
     *     report_type: string,
     *     jurisdiction: string,
     *     organization: string,
     *     generated_at: string,
     *     data_inventory: list<array{field: string, category: string, source: string, purpose: string, collected_from: string, shared: bool, retention: string, sensitive: bool}>
     * }
     */
    public function generateCcpaInventory(bool $fresh = false): array
    {
        if (! $this->enabled) {
            return $this->disabledReport('ccpa_inventory');
        }

        $cacheKey = $this->cachePrefix . 'ccpa_inventory';

        if (! $fresh) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $inventory = $this->buildDataInventory();
        $report = [
            'report_type' => 'CCPA Data Inventory',
            'jurisdiction' => 'CCPA (California)',
            'organization' => $this->organizationName,
            'generated_at' => date('c'),
            'data_inventory' => $inventory,
        ];

        $this->cache->put($cacheKey, $report, $this->reportTtl);

        Log::info('PrivacyReport: CCPA data inventory generated', [
            'fields' => count($inventory),
        ]);

        return $report;
    }

    /**
     * Generate a Data Subject Access Report (DSAR).
     *
     * Exports all analytics data held for a specific user, including
     * profile traits, identity links, event history, consent records,
     * and external IDs. Used to fulfill GDPR Article 15 / CCPA §1798.100
     * data access requests.
     *
     * @param  string  $identity  user_id or client_id
     * @return array{
     *     report_type: string,
     *     jurisdiction: string,
     *     organization: string,
     *     generated_at: string,
     *     request_type: string,
     *     subject: array{identity: string, user_id: string|null, client_ids: list<string>},
     *     data: array{profile: array<string, mixed>, traits: array<string, mixed>, external_ids: array<string, string>, identity_links: array<string, mixed>},
     *     processing_activities: list<array{activity: string, timestamp: string, purpose: string}>,
     *     retention_info: array{profile_ttl: string, consent_log_ttl: string, identity_link_ttl: string}
     * }
     */
    public function generateDataSubjectReport(string $identity): array
    {
        if (! $this->enabled) {
            return $this->disabledReport('data_subject_access');
        }

        $profile = $this->cdp->getProfile($identity);
        $export = $this->cdp->exportProfile($identity);
        $userId = $profile['identity']['user_id'];

        $identityLinks = $userId !== null
            ? $this->identityResolution->identitySummary($userId)
            : ['user_id' => null, 'linked_clients' => 0, 'primary_client_id' => null];

        $analyticsConfig = $this->config->get('zeroboiler.analytics', []);
        $consentConfig = $analyticsConfig['consent'] ?? [];

        $report = [
            'report_type' => 'Data Subject Access Report',
            'jurisdiction' => $this->jurisdiction,
            'organization' => $this->organizationName,
            'generated_at' => date('c'),
            'request_type' => 'Personal Data Access',
            'subject' => [
                'identity' => $identity,
                'user_id' => $userId,
                'client_ids' => $profile['identity']['client_ids'],
            ],
            'data' => [
                'profile' => $profile,
                'traits' => $export['traits'],
                'external_ids' => $export['external_ids'],
                'identity_links' => $identityLinks,
            ],
            'processing_activities' => [
                [
                    'activity' => 'Event tracking and analytics',
                    'timestamp' => date('c'),
                    'purpose' => 'Product analytics, user behavior analysis, and service improvement',
                ],
                [
                    'activity' => 'Identity resolution',
                    'timestamp' => date('c'),
                    'purpose' => 'Cross-device user stitching for accurate analytics',
                ],
                [
                    'activity' => 'Consent management',
                    'timestamp' => date('c'),
                    'purpose' => 'GDPR/CCPA consent state tracking and compliance',
                ],
            ],
            'retention_info' => [
                'profile_ttl' => '30 days (cache-based)',
                'consent_log_ttl' => ((int) ($consentConfig['log_ttl'] ?? 7776000) / 86400) . ' days',
                'identity_link_ttl' => '90 days (cache-based)',
            ],
        ];

        Log::info('PrivacyReport: Data Subject Access report generated', [
            'identity' => $identity,
            'user_id' => $userId,
        ]);

        return $report;
    }

    /**
     * Generate a consent compliance audit report.
     *
     * Summarizes consent configuration, purposes, and compliance status.
     *
     * @param  bool  $fresh  Force rebuild
     * @return array{
     *     report_type: string,
     *     jurisdiction: string,
     *     organization: string,
     *     generated_at: string,
     *     consent_config: array{default_state: string, purposes: list<array{name: string, required: bool, default: bool}>, logging_enabled: bool, log_ttl: int},
     *     compliance_status: array{consent_default_safe: bool, all_purposes_configured: bool, logging_active: bool, gdpr_ready: bool},
     *     recommendations: list<string>
     * }
     */
    public function generateConsentAudit(bool $fresh = false): array
    {
        if (! $this->enabled) {
            return $this->disabledReport('consent_audit');
        }

        $cacheKey = $this->cachePrefix . 'consent_audit';

        if (! $fresh) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $analyticsConfig = $this->config->get('zeroboiler.analytics', []);
        $consentConfig = $analyticsConfig['consent'] ?? [];
        $defaultState = (string) ($consentConfig['default'] ?? 'granted');
        $purposes = $consentConfig['purposes'] ?? [];
        $logEnabled = (bool) ($consentConfig['log_enabled'] ?? false);
        $logTtl = (int) ($consentConfig['log_ttl'] ?? 7776000);

        $purposeList = [];
        foreach ($purposes as $key => $purpose) {
            $purposeList[] = [
                'name' => is_array($purpose) ? ($purpose['label'] ?? $key) : (string) $purpose,
                'required' => is_array($purpose) ? (bool) ($purpose['required'] ?? false) : false,
                'default' => is_array($purpose) ? (bool) ($purpose['default'] ?? false) : true,
            ];
        }

        $consentDefaultSafe = $defaultState === 'denied';
        $gdprReady = $consentDefaultSafe && $logEnabled && count($purposeList) >= 2;

        $recommendations = [];
        if (! $consentDefaultSafe) {
            $recommendations[] = 'Set consent default to "denied" for GDPR compliance. Users must explicitly opt-in.';
        }
        if (! $logEnabled) {
            $recommendations[] = 'Enable consent logging (consent.log_enabled) for audit trail.';
        }
        if (count($purposeList) < 2) {
            $recommendations[] = 'Configure at least 2 consent purposes for granular consent.';
        }
        if ($this->dpoContact === '') {
            $recommendations[] = 'Set DPO contact information in privacy_report.dpo_contact.';
        }

        $report = [
            'report_type' => 'Consent Compliance Audit',
            'jurisdiction' => $this->jurisdiction,
            'organization' => $this->organizationName,
            'generated_at' => date('c'),
            'consent_config' => [
                'default_state' => $defaultState,
                'purposes' => $purposeList,
                'logging_enabled' => $logEnabled,
                'log_ttl' => $logTtl,
            ],
            'compliance_status' => [
                'consent_default_safe' => $consentDefaultSafe,
                'all_purposes_configured' => count($purposeList) >= 2,
                'logging_active' => $logEnabled,
                'gdpr_ready' => $gdprReady,
            ],
            'recommendations' => $recommendations,
        ];

        $this->cache->put($cacheKey, $report, $this->reportTtl);

        Log::info('PrivacyReport: Consent audit generated', [
            'gdpr_ready' => $gdprReady,
            'recommendations' => count($recommendations),
        ]);

        return $report;
    }

    /**
     * Generate a comprehensive privacy overview combining all reports.
     *
     * @param  bool  $fresh  Force rebuild
     * @return array{article_30: array<string, mixed>, ccpa_inventory: array<string, mixed>, consent_audit: array<string, mixed>, generated_at: string}
     */
    public function generateFullReport(bool $fresh = false): array
    {
        return [
            'article_30' => $this->generateArticle30Report($fresh),
            'ccpa_inventory' => $this->generateCcpaInventory($fresh),
            'consent_audit' => $this->generateConsentAudit($fresh),
            'generated_at' => date('c'),
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
     * Build GDPR Article 30 processing activities list.
     *
     * @return list<array{name: string, purpose: string, legal_basis: string, data_categories: list<string>, data_subjects: list<string>, retention: string, technical_measures: list<string>, recipients: list<string>}>
     */
    private function buildProcessingActivities(): array
    {
        $analyticsConfig = $this->config->get('zeroboiler.analytics', []);

        $enabledProviders = [];
        if (($analyticsConfig['ga4']['enabled'] ?? false)) {
            $enabledProviders[] = 'Google Analytics 4';
        }
        if (($analyticsConfig['gtm']['enabled'] ?? false)) {
            $enabledProviders[] = 'Google Tag Manager';
        }
        if (($analyticsConfig['meta_pixel']['enabled'] ?? false)) {
            $enabledProviders[] = 'Meta Pixel (Facebook)';
        }
        if (($analyticsConfig['plausible']['enabled'] ?? false)) {
            $enabledProviders[] = 'Plausible Analytics';
        }
        if (($analyticsConfig['posthog']['enabled'] ?? false)) {
            $enabledProviders[] = 'PostHog';
        }
        if (($analyticsConfig['mixpanel']['enabled'] ?? false)) {
            $enabledProviders[] = 'Mixpanel';
        }
        if (($analyticsConfig['amplitude']['enabled'] ?? false)) {
            $enabledProviders[] = 'Amplitude';
        }
        if (($analyticsConfig['webhook']['enabled'] ?? false)) {
            $enabledProviders[] = 'Custom Webhook';
        }

        if (empty($enabledProviders)) {
            $enabledProviders[] = 'No providers currently enabled';
        }

        $consentTtl = (int) ($analyticsConfig['consent']['log_ttl'] ?? 7776000);
        $identityTtl = (int) ($analyticsConfig['identity']['link_ttl'] ?? 7776000);

        return [
            [
                'name' => 'Event Collection and Tracking',
                'purpose' => 'Collect user interaction data (page views, clicks, transactions) for product analytics and service improvement',
                'legal_basis' => 'Legitimate interest (Art. 6(1)(f)) or Consent (Art. 6(1)(a)) for marketing analytics',
                'data_categories' => ['Identifier (client_id)', 'Online identifiers', 'Technical data (user agent, IP)', 'Behavioral data (events, clicks)', 'Transaction data (purchases)'],
                'data_subjects' => ['Website visitors', 'Authenticated users', 'Trial users', 'Subscribers'],
                'retention' => 'Session-based (not persisted by analytics package; governed by provider retention settings)',
                'technical_measures' => ['HTTPS encryption', 'Consent Mode v2 integration', 'PII sanitization pipeline', 'IP anonymization'],
                'recipients' => $enabledProviders,
            ],
            [
                'name' => 'User Identity Resolution',
                'purpose' => 'Link anonymous browsing sessions to authenticated user profiles for cross-session analytics',
                'legal_basis' => 'Legitimate interest (Art. 6(1)(f))',
                'data_categories' => ['Client ID', 'User ID', 'Device fingerprint (hashed)', 'Session ID'],
                'data_subjects' => ['Authenticated users'],
                'retention' => ($identityTtl / 86400) . ' days (cache-based, auto-expiring)',
                'technical_measures' => ['Cache-based storage', 'Automatic expiration', 'Support for GDPR erasure'],
                'recipients' => ['Internal analytics system only'],
            ],
            [
                'name' => 'Consent Management',
                'purpose' => 'Track and enforce user consent preferences for analytics data processing',
                'legal_basis' => 'Legal obligation (Art. 6(1)(c))',
                'data_categories' => ['Consent state per purpose', 'Consent timestamp', 'Consent source (cookie/banner/API)'],
                'data_subjects' => ['All website visitors'],
                'retention' => ($consentTtl / 86400) . ' days (audit log)',
                'technical_measures' => ['Immutable log entries', 'Configurable TTL', 'Purpose-level granularity'],
                'recipients' => ['Internal compliance system only'],
            ],
            [
                'name' => 'CDP Profile Unification',
                'purpose' => 'Build unified customer profiles from multiple analytics sources for personalization and analytics',
                'legal_basis' => 'Consent (Art. 6(1)(a))',
                'data_categories' => ['User traits (plan, company, role)', 'Event history summary', 'Attribution data', 'Lifetime metrics', 'External IDs (Stripe, CRM)'],
                'data_subjects' => ['Authenticated users'],
                'retention' => '30 days (cache-based, auto-expiring)',
                'technical_measures' => ['Cache-based storage', 'Profile deletion support', 'Trait filtering'],
                'recipients' => ['Internal analytics system only'],
            ],
            [
                'name' => 'E-commerce Analytics',
                'purpose' => 'Track purchase transactions, cart behavior, and revenue attribution',
                'legal_basis' => 'Legitimate interest (Art. 6(1)(f))',
                'data_categories' => ['Transaction value', 'Currency', 'Product data (items, categories)', 'Cart contents'],
                'data_subjects' => ['Customers making purchases'],
                'retention' => 'Governed by e-commerce provider settings',
                'technical_measures' => ['Multi-provider format conversion', 'Revenue aggregation', 'Currency normalization'],
                'recipients' => $enabledProviders,
            ],
        ];
    }

    /**
     * Build CCPA data inventory.
     *
     * @return list<array{field: string, category: string, source: string, purpose: string, collected_from: string, shared: bool, retention: string, sensitive: bool}>
     */
    private function buildDataInventory(): array
    {
        return [
            [
                'field' => 'client_id',
                'category' => 'Online Identifier',
                'source' => 'Cookie (zb_analytics_id)',
                'purpose' => 'Session tracking and identity resolution',
                'collected_from' => 'First website visit',
                'shared' => false,
                'retention' => '1 year (cookie) / 90 days (identity link)',
                'sensitive' => false,
            ],
            [
                'field' => 'user_id',
                'category' => 'Direct Identifier',
                'source' => 'Authentication system',
                'purpose' => 'Authenticated user analytics and profile unification',
                'collected_from' => 'Login / registration',
                'shared' => true,
                'retention' => 'As long as account is active',
                'sensitive' => false,
            ],
            [
                'field' => 'event_name',
                'category' => 'Behavioral Data',
                'source' => 'Client-side / Server-side',
                'purpose' => 'User behavior analysis',
                'collected_from' => 'User interactions',
                'shared' => true,
                'retention' => 'Provider-dependent',
                'sensitive' => false,
            ],
            [
                'field' => 'event_parameters',
                'category' => 'Behavioral Data',
                'source' => 'Client-side / Server-side',
                'purpose' => 'Event context enrichment',
                'collected_from' => 'User interactions',
                'shared' => true,
                'retention' => 'Provider-dependent',
                'sensitive' => false,
            ],
            [
                'field' => 'consent_state',
                'category' => 'Consent Data',
                'source' => 'Cookie / Banner / API',
                'purpose' => 'GDPR/CCPA compliance',
                'collected_from' => 'Consent banner interaction',
                'shared' => false,
                'retention' => '90 days (audit log)',
                'sensitive' => false,
            ],
            [
                'field' => 'utm_source / utm_medium / utm_campaign',
                'category' => 'Online Identifier',
                'source' => 'URL parameters',
                'purpose' => 'Marketing attribution',
                'collected_from' => 'Landing page URL',
                'shared' => true,
                'retention' => '30 days (attribution cache)',
                'sensitive' => false,
            ],
            [
                'field' => 'user_traits (plan, company, role)',
                'category' => 'Professional Information',
                'source' => 'Application / CRM integration',
                'purpose' => 'CDP profile unification and segmentation',
                'collected_from' => 'User profile settings',
                'shared' => false,
                'retention' => '30 days (cache)',
                'sensitive' => false,
            ],
            [
                'field' => 'device_fingerprint',
                'category' => 'Online Identifier (Hashed)',
                'source' => 'Browser signals (JS)',
                'purpose' => 'Cross-device identity resolution',
                'collected_from' => 'Client-side fingerprinting',
                'shared' => false,
                'retention' => '90 days (identity graph cache)',
                'sensitive' => false,
            ],
        ];
    }

    /**
     * Return a disabled placeholder report.
     *
     * @param  string  $type
     * @return array<string, mixed>
     */
    private function disabledReport(string $type): array
    {
        return [
            'report_type' => $type,
            'status' => 'disabled',
            'message' => 'Privacy report generation is disabled in configuration.',
            'generated_at' => date('c'),
        ];
    }
}
