<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * GDPR Article 35 Data Protection Impact Assessment (DPIA) service.
 *
 * Performs automated privacy impact assessments for analytics processing
 * activities. Evaluates risk levels, identifies mitigation measures, and
 * generates structured DPIA reports suitable for regulatory submission.
 *
 * A DPIA is required under GDPR Article 35 when processing is "likely to
 * result in a high risk to the rights and freedoms of natural persons."
 * This service helps SaaS applications continuously monitor their analytics
 * processing for triggering conditions.
 *
 * Configuration: `zeroboiler.analytics.privacy_impact_assessment`
 *
 * @see \ZeroBoiler\Analytics\Services\PrivacyManifestService
 * @see \ZeroBoiler\Analytics\Services\AnalyticsConsentComplianceService
 *
 * @since 62.0.0
 */
final class PrivacyImpactAssessmentService
{
    private const CACHE_PREFIX = 'zb_pia_';

    private const RISK_LEVELS = ['none', 'low', 'medium', 'high', 'critical'];

    private const DEFAULT_RISK_THRESHOLDS = [
        'high' => 70,
        'medium' => 40,
        'low' => 15,
    ];

    /** @var array{enabled: bool, cache_ttl: int, auto_assess: bool, required_for_new_events: bool, reviewer_email: string|null, dpo_email: string|null, assessment_frequency_days: int, high_risk_categories: list<string>, processing_purposes: list<string>, retention_review_days: int, cross_border_transfers: list<string>} */
    private array $config;

    private bool $enabled;

    private int $cacheTtl;

    private bool $autoAssess;

    private bool $requiredForNewEvents;

    private ?string $reviewerEmail;

    private ?string $dpoEmail;

    private int $assessmentFrequencyDays;

    /** @var list<string> */
    private array $highRiskCategories;

    /** @var list<string> */
    private array $processingPurposes;

    private int $retentionReviewDays;

    /** @var list<string> */
    private array $crossBorderTransfers;

    /**
     * Built-in data sensitivity mapping for event categories.
     *
     * Each category maps to a sensitivity score (0-100) used in risk calculation.
     *
     * @var array<string, int>
     */
    private const CATEGORY_SENSITIVITY = [
        'saas' => 65,
        'ecommerce' => 80,
        'engagement' => 30,
        'security' => 90,
        'uptime' => 10,
        'infrastructure' => 15,
    ];

    /**
     * Built-in processing operation risk weights.
     *
     * @var array<string, int>
     */
    private const OPERATION_RISKS = [
        'collect' => 30,
        'store' => 25,
        'process' => 35,
        'share' => 50,
        'export' => 45,
        'delete' => 5,
        'anonymize' => 10,
        'profile' => 60,
        'automated_decision' => 70,
    ];

    /**
     * Data subject vulnerability multipliers.
     *
     * @var array<string, float>
     */
    private const VULNERABILITY_MULTIPLIERS = [
        'standard' => 1.0,
        'employee' => 1.2,
        'child' => 1.8,
        'health_data' => 1.5,
        'financial_data' => 1.4,
    ];

    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $piaConfig = $config->get('zeroboiler.analytics.privacy_impact_assessment', []);

        $this->enabled = (bool) ($piaConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($piaConfig['cache_ttl'] ?? 86400);
        $this->autoAssess = (bool) ($piaConfig['auto_assess'] ?? true);
        $this->requiredForNewEvents = (bool) ($piaConfig['required_for_new_events'] ?? false);
        $this->reviewerEmail = isset($piaConfig['reviewer_email']) && is_string($piaConfig['reviewer_email']) && $piaConfig['reviewer_email'] !== '' ? $piaConfig['reviewer_email'] : null;
        $this->dpoEmail = isset($piaConfig['dpo_email']) && is_string($piaConfig['dpo_email']) && $piaConfig['dpo_email'] !== '' ? $piaConfig['dpo_email'] : null;
        $this->assessmentFrequencyDays = (int) ($piaConfig['assessment_frequency_days'] ?? 365);
        $this->highRiskCategories = (array) ($piaConfig['high_risk_categories'] ?? ['security', 'ecommerce']);
        $this->processingPurposes = (array) ($piaConfig['processing_purposes'] ?? ['analytics', 'improvement', 'security']);
        $this->retentionReviewDays = (int) ($piaConfig['retention_review_days'] ?? 90);
        $this->crossBorderTransfers = (array) ($piaConfig['cross_border_transfers'] ?? ['US', 'EU']);

        $this->config = [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'auto_assess' => $this->autoAssess,
            'required_for_new_events' => $this->requiredForNewEvents,
            'reviewer_email' => $this->reviewerEmail,
            'dpo_email' => $this->dpoEmail,
            'assessment_frequency_days' => $this->assessmentFrequencyDays,
            'high_risk_categories' => $this->highRiskCategories,
            'processing_purposes' => $this->processingPurposes,
            'retention_review_days' => $this->retentionReviewDays,
            'cross_border_transfers' => $this->crossBorderTransfers,
        ];
    }

    /**
     * Check if the PIA service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Perform a full privacy impact assessment for a specific event.
     *
     * Evaluates the event against GDPR Article 35 criteria including:
     * - Data sensitivity level
     * - Processing operations involved
     * - Volume and scale of processing
     * - Data subject categories affected
     * - Cross-border transfer requirements
     * - Automated decision-making involvement
     * - Likelihood and severity of risk
     * - Available mitigation measures
     *
     * @param  string  $eventName  The analytics event name to assess
     * @param  array{volume?: string, subjects?: string, operations?: list<string>, context?: string}  $context  Processing context
     * @return array{id: string, event: string, timestamp: string, overall_risk: string, overall_score: int, triggers_dpia: bool, sections: array<string, mixed>, recommendations: list<string>, mitigations: list<string>, review_due: string}
     */
    public function assessEvent(string $eventName, array $context = []): array
    {
        $assessmentId = 'pia_' . Str::uuid()->toString();
        $timestamp = date('c');

        $category = EventCatalog::getCategory($eventName);
        $catalogEntry = EventCatalog::get($eventName);

        // Section 1: Data Sensitivity Analysis
        $sensitivitySection = $this->assessSensitivity($eventName, $category, $catalogEntry);

        // Section 2: Processing Operations Risk
        $operationsSection = $this->assessOperations($eventName, $context['operations'] ?? []);

        // Section 3: Scale & Volume Assessment
        $scaleSection = $this->assessScale($eventName, $context['volume'] ?? 'standard', $context['subjects'] ?? 'all_users');

        // Section 4: Cross-Border Transfer Assessment
        $transferSection = $this->assessCrossBorderTransfers($eventName);

        // Section 5: Automated Decision-Making Check
        $automatedSection = $this->assessAutomatedDecisionMaking($eventName, $catalogEntry);

        // Section 6: Data Subject Rights Assessment
        $rightsSection = $this->assessDataSubjectRights($eventName, $catalogEntry);

        // Section 7: Legal Basis Validation
        $legalSection = $this->assessLegalBasis($eventName, $category);

        // Calculate overall risk score
        $weights = [
            'sensitivity' => 0.25,
            'operations' => 0.20,
            'scale' => 0.15,
            'transfers' => 0.10,
            'automated' => 0.15,
            'rights' => 0.10,
            'legal' => 0.05,
        ];

        $overallScore = (int) round(
            $sensitivitySection['score'] * $weights['sensitivity']
            + $operationsSection['score'] * $weights['operations']
            + $scaleSection['score'] * $weights['scale']
            + $transferSection['score'] * $weights['transfers']
            + $automatedSection['score'] * $weights['automated']
            + $rightsSection['score'] * $weights['rights']
            + $legalSection['score'] * $weights['legal']
        );

        $overallRisk = $this->scoreToRiskLevel($overallScore);
        $triggersDpia = $overallScore >= self::DEFAULT_RISK_THRESHOLDS['high']
            || $this->categoryTriggersDpia($category);

        $recommendations = $this->generateRecommendations($overallScore, $category, $eventName);

        $mitigations = $this->generateMitigations($overallScore, $sensitivitySection, $operationsSection, $transferSection);

        // Review due date
        $reviewDue = date('c', strtotime("+{$this->assessmentFrequencyDays} days"));

        $assessment = [
            'id' => $assessmentId,
            'event' => $eventName,
            'timestamp' => $timestamp,
            'overall_risk' => $overallRisk,
            'overall_score' => $overallScore,
            'triggers_dpia' => $triggersDpia,
            'sections' => [
                'sensitivity' => $sensitivitySection,
                'operations' => $operationsSection,
                'scale' => $scaleSection,
                'transfers' => $transferSection,
                'automated_decision_making' => $automatedSection,
                'data_subject_rights' => $rightsSection,
                'legal_basis' => $legalSection,
            ],
            'recommendations' => $recommendations,
            'mitigations' => $mitigations,
            'review_due' => $reviewDue,
            'reviewer_email' => $this->reviewerEmail,
            'dpo_email' => $this->dpoEmail,
        ];

        // Cache the assessment
        $this->cache->put(
            self::CACHE_PREFIX . $eventName,
            $assessment,
            $this->cacheTtl,
        );

        return $assessment;
    }

    /**
     * Perform a system-wide PIA across all tracked events.
     *
     * Generates a comprehensive privacy impact assessment report covering
     * the entire analytics event catalog.
     *
     * @return array{assessed_at: string, total_events: int, by_risk: array<string, int>, high_risk_events: list<string>, overall_compliance_score: float, recommendations: list<string>, requires_dpa_review: bool, events: array<string, mixed>}
     */
    public function assessSystemWide(): array
    {
        $allEvents = EventCatalog::all();
        $results = [];
        $riskCounts = ['none' => 0, 'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
        $highRiskEvents = [];
        $totalRiskScore = 0;

        foreach ($allEvents as $name => $entry) {
            $assessment = $this->assessEvent($name);
            $results[$name] = $assessment;

            $risk = $assessment['overall_risk'];
            $riskCounts[$risk] = ($riskCounts[$risk] ?? 0) + 1;
            $totalRiskScore += $assessment['overall_score'];

            if ($assessment['overall_score'] >= self::DEFAULT_RISK_THRESHOLDS['high']) {
                $highRiskEvents[] = $name;
            }
        }

        $totalEvents = count($allEvents);
        $avgRisk = $totalEvents > 0 ? $totalRiskScore / $totalEvents : 0.0;
        $complianceScore = max(0.0, 100.0 - $avgRisk);

        return [
            'assessed_at' => date('c'),
            'total_events' => $totalEvents,
            'by_risk' => $riskCounts,
            'high_risk_events' => $highRiskEvents,
            'overall_compliance_score' => round($complianceScore, 2),
            'recommendations' => $this->generateSystemWideRecommendations($riskCounts, $highRiskEvents),
            'requires_dpa_review' => count($highRiskEvents) > 0,
            'events' => $results,
        ];
    }

    /**
     * Get the cached assessment for an event, or null if not cached.
     *
     * @param  string  $eventName
     * @return array<string, mixed>|null
     */
    public function getCachedAssessment(string $eventName): ?array
    {
        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get(self::CACHE_PREFIX . $eventName);

        return is_array($cached) ? $cached : null;
    }

    /**
     * Check if an event requires DPIA before processing.
     *
     * Returns true if the event category is in the high-risk list
     * or if a cached assessment scores above the high threshold.
     *
     * @param  string  $eventName
     */
    public function requiresDpia(string $eventName): bool
    {
        $category = EventCatalog::getCategory($eventName);

        if ($category !== null && $this->categoryTriggersDpia($category)) {
            return true;
        }

        $cached = $this->getCachedAssessment($eventName);
        if ($cached !== null && ($cached['triggers_dpia'] ?? false)) {
            return true;
        }

        return false;
    }

    /**
     * Get the DPIA triggering criteria summary.
     *
     * @return array{event_categories: list<string>, score_threshold: int, criteria: list<array{description: string, applies: bool}>}
     */
    public function getTriggerCriteria(): array
    {
        return [
            'event_categories' => $this->highRiskCategories,
            'score_threshold' => self::DEFAULT_RISK_THRESHOLDS['high'],
            'criteria' => [
                [
                    'description' => 'Systematic and extensive profiling with significant effects',
                    'applies' => true,
                ],
                [
                    'description' => 'Large-scale processing of special category data',
                    'applies' => in_array('security', $this->highRiskCategories, true),
                ],
                [
                    'description' => 'Systematic monitoring of publicly accessible areas',
                    'applies' => in_array('engagement', $this->highRiskCategories, true),
                ],
                [
                    'description' => 'Cross-border data transfers outside adequate jurisdictions',
                    'applies' => count($this->crossBorderTransfers) > 1,
                ],
                [
                    'description' => 'Processing of financial or transactional data at scale',
                    'applies' => in_array('ecommerce', $this->highRiskCategories, true),
                ],
            ],
        ];
    }

    /**
     * Generate a summary report for compliance dashboards.
     *
     * @return array{enabled: bool, total_assessments: int, high_risk_count: int, compliance_score: float, last_assessed: string|null, review_due: string|null, dpo_contact: string|null}
     */
    public function summaryReport(): array
    {
        $highRiskCount = 0;
        $totalScore = 0;
        $count = 0;
        $lastAssessed = null;

        foreach (EventCatalog::names() as $eventName) {
            $cached = $this->getCachedAssessment($eventName);
            if ($cached !== null) {
                $totalScore += $cached['overall_score'];
                $count++;
                if ($cached['triggers_dpia']) {
                    $highRiskCount++;
                }
                $cachedTime = $cached['timestamp'] ?? null;
                if ($cachedTime !== null && ($lastAssessed === null || $cachedTime > $lastAssessed)) {
                    $lastAssessed = $cachedTime;
                }
            }
        }

        $avgScore = $count > 0 ? $totalScore / $count : 0.0;

        return [
            'enabled' => $this->enabled,
            'total_assessments' => $count,
            'high_risk_count' => $highRiskCount,
            'compliance_score' => round(max(0.0, 100.0 - $avgScore), 2),
            'last_assessed' => $lastAssessed,
            'review_due' => $lastAssessed !== null
                ? date('c', strtotime($lastAssessed) + ($this->assessmentFrequencyDays * 86400))
                : null,
            'dpo_contact' => $this->dpoEmail,
        ];
    }

    /**
     * Assess data sensitivity for an event.
     *
     * @param  string  $eventName
     * @param  string|null  $category
     * @param  array<string, mixed>|null  $catalogEntry
     * @return array{score: int, level: string, category_sensitivity: int|null, pii_risk: string, data_categories: list<string>}
     */
    private function assessSensitivity(string $eventName, ?string $category, ?array $catalogEntry): array
    {
        $categorySensitivity = $category !== null
            ? (self::CATEGORY_SENSITIVITY[$category] ?? 50)
            : 50;

        // Detect PII risk from event name patterns
        $piiRisk = 'low';
        $dataCategories = ['behavioral', 'technical'];

        $highPiiPatterns = ['password', 'email', 'profile', 'account', 'payment', 'billing', 'login', 'signup', 'identify'];
        $mediumPiiPatterns = ['team', 'role', 'invite', 'workspace', 'feature'];

        foreach ($highPiiPatterns as $pattern) {
            if (str_contains($eventName, $pattern)) {
                $piiRisk = 'high';
                $dataCategories[] = 'identifier';
                $dataCategories[] = 'personal';
                $categorySensitivity = max($categorySensitivity, 80);
                break;
            }
        }

        if ($piiRisk === 'low') {
            foreach ($mediumPiiPatterns as $pattern) {
                if (str_contains($eventName, $pattern)) {
                    $piiRisk = 'medium';
                    $dataCategories[] = 'organizational';
                    $categorySensitivity = max($categorySensitivity, 55);
                    break;
                }
            }
        }

        $financialPatterns = ['purchase', 'refund', 'revenue', 'payment', 'billing', 'invoice', 'credit', 'subscription'];
        foreach ($financialPatterns as $pattern) {
            if (str_contains($eventName, $pattern)) {
                $dataCategories[] = 'financial';
                $categorySensitivity = max($categorySensitivity, 75);
                break;
            }
        }

        return [
            'score' => $categorySensitivity,
            'level' => $this->scoreToRiskLevel($categorySensitivity),
            'category_sensitivity' => $category !== null ? (self::CATEGORY_SENSITIVITY[$category] ?? null) : null,
            'pii_risk' => $piiRisk,
            'data_categories' => array_values(array_unique($dataCategories)),
        ];
    }

    /**
     * Assess processing operations risk.
     *
     * @param  string  $eventName
     * @param  list<string>  $customOperations
     * @return array{score: int, level: string, operations: list<array{operation: string, risk: int}>}
     */
    private function assessOperations(string $eventName, array $customOperations): array
    {
        // Default operations for any analytics event
        $defaultOps = ['collect', 'store', 'process'];
        $operations = $defaultOps;

        if (str_contains($eventName, 'export') || str_contains($eventName, 'report')) {
            $operations[] = 'export';
        }

        if (str_contains($eventName, 'profile') || str_contains($eventName, 'cohort') || str_contains($eventName, 'recommendation')) {
            $operations[] = 'profile';
            $operations[] = 'automated_decision';
        }

        foreach ($customOperations as $op) {
            if (is_string($op) && $op !== '' && ! in_array($op, $operations, true)) {
                $operations[] = $op;
            }
        }

        $opDetails = [];
        $totalRisk = 0;

        foreach ($operations as $op) {
            $risk = self::OPERATION_RISKS[$op] ?? 30;
            $opDetails[] = [
                'operation' => $op,
                'risk' => $risk,
            ];
            $totalRisk += $risk;
        }

        $avgRisk = count($operations) > 0 ? (int) round($totalRisk / count($operations)) : 0;

        return [
            'score' => $avgRisk,
            'level' => $this->scoreToRiskLevel($avgRisk),
            'operations' => $opDetails,
        ];
    }

    /**
     * Assess scale and volume risk.
     *
     * @param  string  $eventName
     * @param  string  $volume
     * @param  string  $subjects
     * @return array{score: int, level: string, volume_level: string, subject_scope: string, factor: float}
     */
    private function assessScale(string $eventName, string $volume, string $subjects): array
    {
        $volumeLevels = [
            'minimal' => 5,
            'low' => 15,
            'standard' => 35,
            'high' => 55,
            'massive' => 80,
        ];

        $subjectMultipliers = [
            'employees_only' => 0.8,
            'authenticated' => 1.0,
            'all_users' => 1.2,
            'public' => 1.5,
            'children' => 2.0,
        ];

        $baseVolume = $volumeLevels[$volume] ?? $volumeLevels['standard'];
        $subjectMultiplier = $subjectMultipliers[$subjects] ?? $subjectMultipliers['all_users'];

        $score = (int) min(100, round($baseVolume * $subjectMultiplier));

        return [
            'score' => $score,
            'level' => $this->scoreToRiskLevel($score),
            'volume_level' => $volume,
            'subject_scope' => $subjects,
            'factor' => $subjectMultiplier,
        ];
    }

    /**
     * Assess cross-border data transfer risk.
     *
     * @param  string  $eventName
     * @return array{score: int, level: string, transfers: list<string>, adequate_jurisdictions: list<string>, safeguards: list<string>}
     */
    private function assessCrossBorderTransfers(string $eventName): array
    {
        $transfers = $this->crossBorderTransfers;
        $adequateJurisdictions = ['EU', 'EEA', 'UK', 'CH', 'NO', 'IS', 'LI'];
        $inadequateTransfers = array_values(array_diff($transfers, $adequateJurisdictions));

        $safeguards = [];
        if (count($inadequateTransfers) > 0) {
            $safeguards[] = 'standard_contractual_clauses';
            $safeguards[] = 'transfer_impact_assessment';
            $safeguards[] = 'supplementary_measures';
        }
        if (count($transfers) > 1) {
            $safeguards[] = 'data_localization_preference';
        }

        $score = count($inadequateTransfers) > 0
            ? (int) min(100, 40 + (count($inadequateTransfers) * 20))
            : 10;

        return [
            'score' => $score,
            'level' => $this->scoreToRiskLevel($score),
            'transfers' => $transfers,
            'adequate_jurisdictions' => $adequateJurisdictions,
            'safeguards' => $safeguards,
        ];
    }

    /**
     * Assess automated decision-making involvement.
     *
     * @param  string  $eventName
     * @param  array<string, mixed>|null  $catalogEntry
     * @return array{score: int, level: string, involves_profiling: bool, involves_automated_decisions: bool, user_impact: string}
     */
    private function assessAutomatedDecisionMaking(string $eventName, ?array $catalogEntry): array
    {
        $profilingEvents = ['cohort', 'profile', 'recommend', 'score', 'predict', 'churn', 'retention', 'funnel', 'benchmark'];
        $decisionEvents = ['guard_rails', 'rate_limit', 'fraud', 'budget', 'rules', 'anomaly'];

        $involvesProfiling = false;
        $involvesAutomatedDecisions = false;

        foreach ($profilingEvents as $pattern) {
            if (str_contains($eventName, $pattern)) {
                $involvesProfiling = true;
                break;
            }
        }

        foreach ($decisionEvents as $pattern) {
            if (str_contains($eventName, $pattern)) {
                $involvesAutomatedDecisions = true;
                break;
            }
        }

        $score = 5; // baseline
        if ($involvesProfiling) {
            $score += 40;
        }
        if ($involvesAutomatedDecisions) {
            $score += 45;
        }

        $userImpact = 'minimal';
        if ($involvesAutomatedDecisions && $involvesProfiling) {
            $userImpact = 'significant';
        } elseif ($involvesAutomatedDecisions || $involvesProfiling) {
            $userImpact = 'moderate';
        }

        return [
            'score' => min(100, $score),
            'level' => $this->scoreToRiskLevel($score),
            'involves_profiling' => $involvesProfiling,
            'involves_automated_decisions' => $involvesAutomatedDecisions,
            'user_impact' => $userImpact,
        ];
    }

    /**
     * Assess data subject rights compliance.
     *
     * @param  string  $eventName
     * @param  array<string, mixed>|null  $catalogEntry
     * @return array{score: int, level: string, rights_covered: list<string>, gaps: list<string>}
     */
    private function assessDataSubjectRights(string $eventName, ?array $catalogEntry): array
    {
        $allRights = ['access', 'rectification', 'erasure', 'portability', 'objection', 'restriction'];

        $rightsCovered = ['access', 'erasure']; // Analytics always supports access and erasure via GDPR endpoints

        // Financial events have additional portability rights
        $financialPatterns = ['purchase', 'refund', 'subscription', 'payment', 'invoice'];
        foreach ($financialPatterns as $pattern) {
            if (str_contains($eventName, $pattern)) {
                $rightsCovered[] = 'portability';
                $rightsCovered[] = 'rectification';
                break;
            }
        }

        // Profile events have objection rights
        if (str_contains($eventName, 'profile') || str_contains($eventName, 'cohort')) {
            $rightsCovered[] = 'objection';
            $rightsCovered[] = 'restriction';
        }

        $rightsCovered = array_values(array_unique($rightsCovered));
        $gaps = array_values(array_diff($allRights, $rightsCovered));

        $score = (int) round((count($rightsCovered) / count($allRights)) * 100);

        return [
            'score' => $score,
            'level' => $this->scoreToRiskLevel(100 - $score), // inverted: fewer gaps = lower risk
            'rights_covered' => $rightsCovered,
            'gaps' => $gaps,
        ];
    }

    /**
     * Assess legal basis adequacy.
     *
     * @param  string  $eventName
     * @param  string|null  $category
     * @return array{score: int, level: string, primary_basis: string, adequacy: string, notes: list<string>}
     */
    private function assessLegalBasis(string $eventName, ?string $category): array
    {
        $basisMap = [
            'ecommerce' => ['contractual', 'Analytics events are necessary for order processing'],
            'saas' => ['contractual', 'Required for service delivery and account management'],
            'engagement' => ['consent', 'Behavioral analytics require user consent under GDPR Article 6(1)(a)'],
            'security' => ['legitimate_interest', 'Security monitoring is a legitimate interest under Article 6(1)(f)'],
            'uptime' => ['legitimate_interest', 'Service monitoring is a legitimate interest'],
            'infrastructure' => ['legitimate_interest', 'Infrastructure monitoring supports service delivery'],
        ];

        [$primaryBasis, $note] = $category !== null && isset($basisMap[$category])
            ? $basisMap[$category]
            : ['consent', 'Default legal basis is consent'];

        // Score: lower is better (more risk if legal basis is weak)
        $basisScores = [
            'contractual' => 10,
            'legitimate_interest' => 25,
            'consent' => 15,
            'legal_obligation' => 5,
            'vital_interest' => 20,
        ];

        $score = $basisScores[$primaryBasis] ?? 30;

        $adequacy = $score <= 15 ? 'strong' : ($score <= 25 ? 'adequate' : 'review_needed');
        $notes = [$note];

        if ($primaryBasis === 'legitimate_interest') {
            $notes[] = 'LIA (Legitimate Interest Assessment) should be documented';
        }
        if ($primaryBasis === 'consent') {
            $notes[] = 'Consent must be freely given, specific, informed, and unambiguous';
        }

        return [
            'score' => $score,
            'level' => $this->scoreToRiskLevel($score),
            'primary_basis' => $primaryBasis,
            'adequacy' => $adequacy,
            'notes' => $notes,
        ];
    }

    /**
     * Check if a category is in the high-risk list.
     *
     * @param  string|null  $category
     */
    private function categoryTriggersDpia(?string $category): bool
    {
        return $category !== null && in_array($category, $this->highRiskCategories, true);
    }

    /**
     * Convert a numeric score (0-100) to a risk level string.
     */
    private function scoreToRiskLevel(int $score): string
    {
        if ($score >= self::DEFAULT_RISK_THRESHOLDS['high']) {
            return 'high';
        }

        if ($score >= self::DEFAULT_RISK_THRESHOLDS['medium']) {
            return 'medium';
        }

        if ($score >= self::DEFAULT_RISK_THRESHOLDS['low']) {
            return 'low';
        }

        return 'none';
    }

    /**
     * Generate recommendations based on assessment results.
     *
     * @param  int  $score
     * @param  string|null  $category
     * @param  string  $eventName
     * @return list<string>
     */
    private function generateRecommendations(int $score, ?string $category, string $eventName): array
    {
        $recommendations = [];

        if ($score >= 70) {
            $recommendations[] = 'CRITICAL: This event triggers GDPR Article 35 DPIA requirements. A formal Data Protection Impact Assessment must be completed before processing.';
            $recommendations[] = 'Consult with your DPO and document the necessity and proportionality of this processing activity.';
            $recommendations[] = 'Implement additional safeguards: data minimization, purpose limitation, and storage limitation.';
        } elseif ($score >= 40) {
            $recommendations[] = 'Review data minimization practices — collect only data strictly necessary for the stated purpose.';
            $recommendations[] = 'Ensure explicit consent mechanisms are in place for this event category.';
        }

        if ($category === 'security') {
            $recommendations[] = 'Security events may contain sensitive system information. Ensure log anonymization is applied.';
        }

        if ($category === 'ecommerce') {
            $recommendations[] = 'Financial data processing requires enhanced safeguards. Ensure PCI-DSS compliance where applicable.';
        }

        if (count($recommendations) === 0) {
            $recommendations[] = 'No immediate actions required. Standard GDPR compliance practices apply.';
        }

        return $recommendations;
    }

    /**
     * Generate mitigation measures based on assessment sections.
     *
     * @param  int  $score
     * @param  array{score: int, pii_risk: string, data_categories: list<string>}  $sensitivity
     * @param  array{score: int}  $operations
     * @param  array{score: int, safeguards: list<string>}  $transfers
     * @return list<string>
     */
    private function generateMitigations(int $score, array $sensitivity, array $operations, array $transfers): array
    {
        $mitigations = [];

        if ($score >= 40) {
            $mitigations[] = 'Enable event sanitization (config.analytics.sanitization.enabled)';
        }

        if ($sensitivity['pii_risk'] === 'high') {
            $mitigations[] = 'Apply PII detection and anonymization pipeline before dispatch';
            $mitigations[] = 'Implement data minimization: exclude unnecessary fields from event payloads';
        }

        if ($operations['score'] >= 50) {
            $mitigations[] = 'Restrict processing operations to the minimum necessary';
            $mitigations[] = 'Implement purpose limitation controls';
        }

        if ($transfers['score'] >= 40) {
            foreach ($transfers['safeguards'] as $safeguard) {
                $mitigations[] = "Implement cross-border transfer safeguard: {$safeguard}";
            }
        }

        if ($score >= 70) {
            $mitigations[] = 'Schedule formal DPIA review with DPO within 30 days';
            $mitigations[] = 'Document residual risk acceptance with executive sign-off';
        }

        return $mitigations;
    }

    /**
     * Generate system-wide recommendations.
     *
     * @param  array<string, int>  $riskCounts
     * @param  list<string>  $highRiskEvents
     * @return list<string>
     */
    private function generateSystemWideRecommendations(array $riskCounts, array $highRiskEvents): array
    {
        $recommendations = [];

        if ($riskCounts['high'] > 0 || $riskCounts['critical'] > 0) {
            $recommendations[] = sprintf(
                '%d event(s) require formal DPIA review: %s',
                count($highRiskEvents),
                implode(', ', array_slice($highRiskEvents, 0, 5)),
            );
        }

        if ($riskCounts['medium'] > 10) {
            $recommendations[] = sprintf(
                '%d events have medium risk — review data minimization practices across these events.',
                $riskCounts['medium'],
            );
        }

        $totalEvents = array_sum($riskCounts);
        if ($totalEvents > 0) {
            $highPct = round((($riskCounts['high'] ?? 0) + ($riskCounts['critical'] ?? 0)) / $totalEvents * 100, 1);
            if ($highPct > 10) {
                $recommendations[] = "High-risk events represent {$highPct}% of total catalog — consider reducing data collection scope.";
            }
        }

        if (count($recommendations) === 0) {
            $recommendations[] = 'System-wide privacy posture is healthy. Continue regular assessment schedule.';
        }

        return $recommendations;
    }
}
