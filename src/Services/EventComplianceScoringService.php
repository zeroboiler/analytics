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
 * Automated compliance scoring service for analytics events.
 *
 * Evaluates each analytics event and the overall system against
 * GDPR, CCPA, and SOC2 compliance requirements. Produces per-event
 * compliance scores and an overall system compliance grade.
 *
 * Scoring dimensions:
 * - **Data Minimization** — Does the event collect only necessary data?
 * - **Purpose Limitation** — Is the event's purpose clearly defined?
 * - **Consent Readiness** — Can the event be suppressed by consent state?
 * - **PII Risk** — Does the event contain or risk exposing PII?
 * - **Retention Compliance** — Is a retention policy defined for the event?
 * - **Data Subject Rights** — Can this event's data be exported/erased?
 * - **Audit Trail** — Is the event traceable in the audit log?
 *
 * Each dimension scores 0-100. The overall event compliance score
 * is the weighted average. System compliance is the average across
 * all catalog events plus infrastructure compliance checks.
 *
 * Configuration: `zeroboiler.analytics.compliance_scoring`
 *
 * @phpstan-type EventComplianceScore array{event: string, category: string, dimensions: array<string, int>, overall: int, grade: string, violations: list<string>, recommendations: list<string>}
 * @phpstan-type SystemComplianceReport array{overall_score: int, grade: string, events_scored: int, events_compliant: int, events_needing_attention: int, critical_violations: int, gdpr_score: int, ccpa_score: int, soc2_score: int, dimensions_summary: array<string, float>, top_violations: list<array{event: string, dimension: string, severity: string, description: string}>}
 *
 * @since 171.0.0
 */
final class EventComplianceScoringService
{
    private const CACHE_PREFIX = 'zb_compliance_score_';

    /** Compliance grade thresholds */
    private const GRADES = [
        'A+' => 97,
        'A' => 93,
        'A-' => 90,
        'B+' => 87,
        'B' => 83,
        'B-' => 80,
        'C+' => 77,
        'C' => 70,
        'C-' => 65,
        'D' => 50,
        'F' => 0,
    ];

    /** Dimension weights for overall event score */
    private const DIMENSION_WEIGHTS = [
        'data_minimization' => 0.20,
        'purpose_limitation' => 0.15,
        'consent_readiness' => 0.20,
        'pii_risk' => 0.15,
        'retention_compliance' => 0.10,
        'data_subject_rights' => 0.10,
        'audit_trail' => 0.10,
    ];

    /** Events known to contain PII (higher risk) */
    private const HIGH_PII_EVENTS = [
        'sign_up', 'login', 'logout', 'password_changed', 'password_reset',
        'password_reset_requested', 'email_verified', 'profile_updated',
        'account_activated', 'account_deactivated', 'account_deleted',
        'data_subject_access_request', 'data_erasure_completed',
    ];

    /** Events involving financial data (CCPA sensitive) */
    private const FINANCIAL_EVENTS = [
        'purchase', 'refund', 'subscription_created', 'subscription_cancelled',
        'plan_upgrade', 'plan_downgrade', 'payment_failed', 'payment_succeeded',
        'payment_method_added', 'payment_method_removed', 'invoice_generated',
        'credit_applied', 'revenue_tracked', 'mrr_movement', 'billing_retry',
    ];

    /** Required fields per category for data minimization check */
    private const CATEGORY_REQUIRED_FIELDS = [
        'ecommerce' => ['transaction_id', 'value', 'currency'],
        'saas' => ['plan_name'],
        'engagement' => [],
    ];

    private bool $enabled;

    private int $cacheTtl;

    /** @var array<string, array{ pii_fields?: list<string>, retention_days?: int, legal_basis?: string, sensitive?: bool }> Custom event compliance overrides */
    private array $eventOverrides;

    /** @var list<string> Fields considered PII */
    private readonly array $piiFields;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ): void {
        $csConfig = $config->get('zeroboiler.analytics.compliance_scoring', []);
        /** @var array{enabled?: bool, cache_ttl?: int, event_overrides?: array<string, array{pii_fields?: list<string>, retention_days?: int, legal_basis?: string, sensitive?: bool}>, pii_fields?: list<string>} $csConfig */

        $this->enabled = (bool) ($csConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($csConfig['cache_ttl'] ?? 7200);
        $this->eventOverrides = $csConfig['event_overrides'] ?? [];
        $this->piiFields = $csConfig['pii_fields'] ?? [
            'email', 'name', 'first_name', 'last_name', 'phone',
            'address', 'ip_address', 'user_agent', 'ssn',
            'credit_card', 'date_of_birth', 'gender',
        ];
    }

    // ── Event-Level Scoring ───────────────────────────────────────────

    /**
     * Score a single event's compliance.
     *
     * @param  string  $eventName  Event name from the catalog
     * @return EventComplianceScore
     */
    public function scoreEvent(string $eventName): array
    {
        $category = EventCatalog::getCategory($eventName) ?? 'unknown';

        $dimensions = [
            'data_minimization' => $this->scoreDataMinimization($eventName, $category),
            'purpose_limitation' => $this->scorePurposeLimitation($eventName, $category),
            'consent_readiness' => $this->scoreConsentReadiness($eventName),
            'pii_risk' => $this->scorePiiRisk($eventName),
            'retention_compliance' => $this->scoreRetentionCompliance($eventName),
            'data_subject_rights' => $this->scoreDataSubjectRights($eventName),
            'audit_trail' => $this->scoreAuditTrail($eventName),
        ];

        // Weighted average
        $overall = 0;
        foreach ($dimensions as $dim => $score) {
            $overall += $score * self::DIMENSION_WEIGHTS[$dim];
        }
        $overall = (int) round($overall);

        $violations = $this->identifyViolations($eventName, $dimensions);
        $recommendations = $this->generateRecommendations($eventName, $dimensions, $category);

        return [
            'event' => $eventName,
            'category' => $category,
            'dimensions' => $dimensions,
            'overall' => $overall,
            'grade' => $this->toGrade($overall),
            'violations' => $violations,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Score multiple events at once.
     *
     * @param  list<string>  $eventNames
     * @return array<string, EventComplianceScore>
     */
    public function scoreEvents(array $eventNames): array
    {
        $results = [];
        foreach ($eventNames as $name) {
            $results[$name] = $this->scoreEvent($name);
        }

        return $results;
    }

    // ── System-Level Scoring ─────────────────────────────────────────

    /**
     * Generate a comprehensive system compliance report.
     *
     * Scores all catalog events and aggregates into system-level
     * GDPR, CCPA, and SOC2 compliance scores.
     *
     * @return SystemComplianceReport
     */
    public function systemReport(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'system_report';

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $allEvents = EventCatalog::names();
        $eventScores = [];
        $dimensionSums = array_fill_keys(array_keys(self::DIMENSION_WEIGHTS), 0.0);
        $gdprSum = 0.0;
        $ccpaSum = 0.0;
        $soc2Sum = 0.0;
        $compliantCount = 0;
        $attentionCount = 0;
        $criticalViolations = 0;
        $topViolations = [];

        foreach ($allEvents as $eventName) {
            $score = $this->scoreEvent($eventName);
            $eventScores[$eventName] = $score;

            foreach ($score['dimensions'] as $dim => $val) {
                $dimensionSums[$dim] += $val;
            }

            // Framework-specific scoring
            $gdprSum += $this->gdprEventScore($eventName, $score);
            $ccpaSum += $this->ccpaEventScore($eventName, $score);
            $soc2Sum += $this->soc2EventScore($eventName, $score);

            if ($score['overall'] >= 80) {
                $compliantCount++;
            } elseif ($score['overall'] >= 50) {
                $attentionCount++;
            }

            // Collect critical violations
            foreach ($score['violations'] as $violation) {
                if (str_starts_with($violation, '[CRITICAL]')) {
                    $criticalViolations++;
                    $topViolations[] = [
                        'event' => $eventName,
                        'dimension' => 'unknown',
                        'severity' => 'critical',
                        'description' => $violation,
                    ];
                }
            }
        }

        $totalEvents = count($allEvents);
        $avgDimensions = [];
        foreach ($dimensionSums as $dim => $sum) {
            $avgDimensions[$dim] = $totalEvents > 0 ? round($sum / $totalEvents, 1) : 0.0;
        }

        $overallScore = $totalEvents > 0
            ? (int) round(array_sum(array_column($eventScores, 'overall')) / $totalEvents)
            : 0;

        $report = [
            'overall_score' => $overallScore,
            'grade' => $this->toGrade($overallScore),
            'events_scored' => $totalEvents,
            'events_compliant' => $compliantCount,
            'events_needing_attention' => $attentionCount,
            'critical_violations' => $criticalViolations,
            'gdpr_score' => $totalEvents > 0 ? (int) round($gdprSum / $totalEvents) : 0,
            'ccpa_score' => $totalEvents > 0 ? (int) round($ccpaSum / $totalEvents) : 0,
            'soc2_score' => $totalEvents > 0 ? (int) round($soc2Sum / $totalEvents) : 0,
            'dimensions_summary' => $avgDimensions,
            'top_violations' => array_slice($topViolations, 0, 20),
        ];

        $this->cache->put($cacheKey, $report, $this->cacheTtl);

        return $report;
    }

    /**
     * Invalidate the cached system report.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'system_report');
    }

    // ── Quick Health Check ──────────────────────────────────────────

    /**
     * Quick compliance health check (single score, cached).
     *
     * @return array{score: int, grade: string, compliant: bool, events_scored: int}
     */
    public function quickHealth(): array
    {
        $report = $this->systemReport();

        return [
            'score' => $report['overall_score'],
            'grade' => $report['grade'],
            'compliant' => $report['overall_score'] >= 80,
            'events_scored' => $report['events_scored'],
        ];
    }

    /**
     * Check if a specific event is compliant (score >= 80).
     */
    public function isEventCompliant(string $eventName): bool
    {
        $score = $this->scoreEvent($eventName);

        return $score['overall'] >= 80;
    }

    // ── Status ───────────────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get all configured PII fields.
     *
     * @return list<string>
     */
    public function getPiiFields(): array
    {
        return $this->piiFields;
    }

    // ── Dimension Scorers (private) ──────────────────────────────────

    /**
     * Score data minimization (0-100).
     *
     * Higher score = event collects only necessary data.
     */
    private function scoreDataMinimization(string $eventName, string $category): int
    {
        $override = $this->eventOverrides[$eventName] ?? [];

        // Sensitive events that should minimize data collection
        if (isset($override['sensitive']) && $override['sensitive']) {
            // If marked sensitive, check if PII fields are explicitly listed
            return isset($override['pii_fields']) ? 80 : 60;
        }

        // Ecommerce events need financial fields but not PII
        if ($category === 'ecommerce') {
            return 90; // Ecommerce events typically well-structured
        }

        // Engagement events should be low-data
        if ($category === 'engagement') {
            return 95; // Page views, clicks, etc. — inherently minimal
        }

        // SaaS lifecycle events need some user context
        if ($category === 'saas') {
            return in_array($eventName, self::HIGH_PII_EVENTS, true) ? 70 : 85;
        }

        return 85;
    }

    /**
     * Score purpose limitation (0-100).
     *
     * Higher score = event's purpose is clearly defined.
     */
    private function scorePurposeLimitation(string $eventName, string $category): int
    {
        // Events in the catalog have defined purposes
        $catalogEntry = EventCatalog::get($eventName);
        if ($catalogEntry !== null) {
            return 90;
        }

        return 60;
    }

    /**
     * Score consent readiness (0-100).
     *
     * Higher score = event can be suppressed by consent state.
     */
    private function scoreConsentReadiness(string $eventName): int
    {
        // Core engagement events should respect consent
        $consentAwareEvents = [
            'page_view', 'scroll_depth', 'click', 'form_start', 'form_submit',
            'search', 'share', 'error', 'session_start', 'session_end',
        ];

        if (in_array($eventName, $consentAwareEvents, true)) {
            return 95; // These events go through the consent filter pipeline
        }

        // SaaS lifecycle events (server-side) are consent-independent
        if (str_starts_with($eventName, 'cohort_') || str_starts_with($eventName, 'subscription_')) {
            return 80; // Server-side events, consent handled at data access level
        }

        // Financial events should respect consent
        if (in_array($eventName, self::FINANCIAL_EVENTS, true)) {
            return 75;
        }

        return 85;
    }

    /**
     * Score PII risk (inverse: higher = safer).
     *
     * 100 = no PII risk, 0 = critical PII exposure.
     */
    private function scorePiiRisk(string $eventName): int
    {
        if (in_array($eventName, self::HIGH_PII_EVENTS, true)) {
            // High PII events — check if override defines handling
            $override = $this->eventOverrides[$eventName] ?? [];
            if (isset($override['pii_fields'])) {
                return 65; // PII acknowledged, but still risky
            }

            return 45; // High PII with no explicit handling
        }

        if (in_array($eventName, self::FINANCIAL_EVENTS, true)) {
            return 60; // Financial data is quasi-PII under CCPA
        }

        // Engagement events typically don't contain PII
        return 95;
    }

    /**
     * Score retention compliance (0-100).
     *
     * Higher score = retention policy is defined and reasonable.
     */
    private function scoreRetentionCompliance(string $eventName): int
    {
        $override = $this->eventOverrides[$eventName] ?? [];

        if (isset($override['retention_days'])) {
            $days = $override['retention_days'];
            if ($days <= 0) {
                return 30; // Invalid retention
            }
            if ($days <= 90) {
                return 95; // Short retention = good
            }
            if ($days <= 365) {
                return 85; // Reasonable
            }
            if ($days <= 1095) {
                return 70; // 3 years — borderline
            }

            return 50; // Very long retention
        }

        // Default: system-wide retention applies
        $defaultTtl = $this->config->get('zeroboiler.analytics.consent.log_ttl', 7776000);
        $defaultDays = (int) ($defaultTtl / 86400);

        if ($defaultDays <= 365) {
            return 80;
        }

        return 60;
    }

    /**
     * Score data subject rights readiness (0-100).
     *
     * Higher score = event data can be exported/erased per GDPR Art. 15-17.
     */
    private function scoreDataSubjectRights(string $eventName): int
    {
        // Events with user context can be exported/erased
        $userBoundEvents = array_merge(self::HIGH_PII_EVENTS, self::FINANCIAL_EVENTS, [
            'start_trial', 'feature_used', 'onboarding_started',
            'team_created', 'team_member_joined', 'role_changed',
        ]);

        if (in_array($eventName, $userBoundEvents, true)) {
            return 85; // User-bound events support DSAR operations
        }

        // Anonymous engagement events
        return 70;
    }

    /**
     * Score audit trail readiness (0-100).
     *
     * Higher score = event is traceable in the audit log.
     */
    private function scoreAuditTrail(string $eventName): int
    {
        // All events dispatched through the pipeline are audit-logged
        // if audit trail is enabled
        $auditConfig = $this->config->get('zeroboiler.analytics.audit_trail', []);
        /** @var array{enabled?: bool} $auditConfig */

        if (isset($auditConfig['enabled']) && $auditConfig['enabled']) {
            return 95; // Full audit trail coverage
        }

        return 70; // Audit trail not explicitly enabled
    }

    // ── Framework-Specific Scoring ────────────────────────────────────

    /**
     * GDPR-specific event score (focus: consent, PII, data minimization, retention, DSAR).
     */
    private function gdprEventScore(string $eventName, array $score): float
    {
        return (
            $score['dimensions']['consent_readiness'] * 0.30 +
            $score['dimensions']['pii_risk'] * 0.25 +
            $score['dimensions']['data_minimization'] * 0.20 +
            $score['dimensions']['retention_compliance'] * 0.15 +
            $score['dimensions']['data_subject_rights'] * 0.10
        );
    }

    /**
     * CCPA-specific event score (focus: financial data, PII, opt-out, data sale).
     */
    private function ccpaEventScore(string $eventName, array $score): float
    {
        return (
            $score['dimensions']['pii_risk'] * 0.30 +
            $score['dimensions']['consent_readiness'] * 0.25 +
            $score['dimensions']['data_minimization'] * 0.20 +
            $score['dimensions']['data_subject_rights'] * 0.15 +
            $score['dimensions']['retention_compliance'] * 0.10
        );
    }

    /**
     * SOC2-specific event score (focus: audit trail, access control, data integrity).
     */
    private function soc2EventScore(string $eventName, array $score): float
    {
        return (
            $score['dimensions']['audit_trail'] * 0.30 +
            $score['dimensions']['data_minimization'] * 0.20 +
            $score['dimensions']['purpose_limitation'] * 0.20 +
            $score['dimensions']['retention_compliance'] * 0.15 +
            $score['dimensions']['consent_readiness'] * 0.15
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Identify compliance violations for an event.
     *
     * @param  string  $eventName
     * @param  array<string, int>  $dimensions
     * @return list<string>
     */
    private function identifyViolations(string $eventName, array $dimensions): array
    {
        $violations = [];

        if ($dimensions['pii_risk'] < 50) {
            $violations[] = '[CRITICAL] High PII exposure risk — no explicit handling defined';
        }

        if ($dimensions['consent_readiness'] < 60) {
            $violations[] = '[HIGH] Event may fire without proper consent check';
        }

        if ($dimensions['retention_compliance'] < 50) {
            $violations[] = '[HIGH] No defined retention policy — potential GDPR Art. 5(1)(e) violation';
        }

        if ($dimensions['data_minimization'] < 50) {
            $violations[] = '[MEDIUM] Event may collect more data than necessary';
        }

        if ($dimensions['audit_trail'] < 60) {
            $violations[] = '[MEDIUM] Audit trail not fully configured — SOC2 concern';
        }

        if ($dimensions['purpose_limitation'] < 50) {
            $violations[] = '[LOW] Event purpose not clearly documented';
        }

        return $violations;
    }

    /**
     * Generate compliance improvement recommendations.
     *
     * @param  string  $eventName
     * @param  array<string, int>  $dimensions
     * @param  string  $category
     * @return list<string>
     */
    private function generateRecommendations(string $eventName, array $dimensions, string $category): array
    {
        $recommendations = [];

        if ($dimensions['pii_risk'] < 70) {
            $recommendations[] = "Define explicit PII field list in compliance_scoring.event_overrides.{$eventName}.pii_fields";
        }

        if ($dimensions['consent_readiness'] < 70) {
            $recommendations[] = "Ensure this event passes through the ConsentFilter pipeline stage";
        }

        if ($dimensions['retention_compliance'] < 70) {
            $recommendations[] = "Set retention_days in compliance_scoring.event_overrides.{$eventName}";
        }

        if ($dimensions['audit_trail'] < 70) {
            $recommendations[] = "Enable audit_trail in config for SOC2 compliance";
        }

        if ($dimensions['data_minimization'] < 70 && $category === 'saas') {
            $recommendations[] = "Review event parameters to ensure only necessary data is collected";
        }

        return $recommendations;
    }

    /**
     * Convert a numeric score to a letter grade.
     */
    private function toGrade(int $score): string
    {
        foreach (self::GRADES as $grade => $threshold) {
            if ($score >= $threshold) {
                return $grade;
            }
        }

        return 'F';
    }
}
