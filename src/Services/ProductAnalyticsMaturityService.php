<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Product analytics maturity model assessment service.
 *
 * Evaluates a SaaS product's analytics instrumentation against
 * industry-standard maturity levels, inspired by the Amplitude
 * Product Analytics Maturity Model and Gartner Analytics Maturity Model.
 *
 * **Maturity Levels:**
 * 1. **Level 1 — Ad Hoc**: Basic page views and events, no funnel tracking
 * 2. **Level 2 — Basic**: Core lifecycle events, basic funnels, single provider
 * 3. **Level 3 — Standard**: Full AARRR coverage, multi-provider, identity resolution
 * 4. **Level 4 — Advanced**: Predictive analytics, cohort analysis, attribution models
 * 5. **Level 5 — Leading**: Data-driven culture, full automation, real-time dashboards
 *
 * **Assessment Dimensions:**
 * - Event Coverage: Breadth and depth of tracked events
 * - Provider Coverage: Number and quality of analytics providers
 * - Funnel Instrumentation: Conversion funnel completeness
 * - Identity Resolution: User tracking sophistication
 * - Real-time Capabilities: Live analytics and alerting
 * - Privacy & Compliance: GDPR, CCPA, consent management
 * - Operational Excellence: Testing, monitoring, CI integration
 * - Data Quality: Validation, deduplication, enrichment
 *
 * @since 87.0.0
 */
final class ProductAnalyticsMaturityService
{
    /**
     * Maturity level constants.
     */
    public const LEVEL_AD_HOC = 1;
    public const LEVEL_BASIC = 2;
    public const LEVEL_STANDARD = 3;
    public const LEVEL_ADVANCED = 4;
    public const LEVEL_LEADING = 5;

    /**
     * Dimension weight constants (must sum to 100).
     */
    private const WEIGHT_EVENT_COVERAGE = 20;
    private const WEIGHT_PROVIDER_COVERAGE = 10;
    private const WEIGHT_FUNNEL_INSTRUMENTATION = 15;
    private const WEIGHT_IDENTITY_RESOLUTION = 10;
    private const WEIGHT_REAL_TIME = 10;
    private const WEIGHT_PRIVACY_COMPLIANCE = 15;
    private const WEIGHT_OPERATIONAL_EXCELLENCE = 10;
    private const WEIGHT_DATA_QUALITY = 10;

    /**
     * @var array<string, int> Dimension → weight
     */
    private const DIMENSION_WEIGHTS = [
        'event_coverage' => self::WEIGHT_EVENT_COVERAGE,
        'provider_coverage' => self::WEIGHT_PROVIDER_COVERAGE,
        'funnel_instrumentation' => self::WEIGHT_FUNNEL_INSTRUMENTATION,
        'identity_resolution' => self::WEIGHT_IDENTITY_RESOLUTION,
        'real_time' => self::WEIGHT_REAL_TIME,
        'privacy_compliance' => self::WEIGHT_PRIVACY_COMPLIANCE,
        'operational_excellence' => self::WEIGHT_OPERATIONAL_EXCELLENCE,
        'data_quality' => self::WEIGHT_DATA_QUALITY,
    ];

    /**
     * Required events for "basic" level.
     *
     * @var list<string>
     */
    private const BASIC_EVENTS = [
        'page_view',
        'sign_up',
        'login',
        'purchase',
    ];

    /**
     * Required events for "standard" level (includes basic).
     *
     * @var list<string>
     */
    private const STANDARD_EVENTS = [
        ...self::BASIC_EVENTS,
        'start_trial',
        'subscribe',
        'plan_upgrade',
        'cancellation',
        'add_to_cart',
        'form_submit',
        'search',
        'error',
    ];

    /**
     * Required events for "advanced" level (includes standard).
     *
     * @var list<string>
     */
    private const ADVANCED_EVENTS = [
        ...self::STANDARD_EVENTS,
        'scroll_depth',
        'session_start',
        'session_end',
        'share',
        'feedback',
        'goal_conversion',
        'web_vitals',
        'consent_granted',
        'consent_withdrawn',
        'onboarding_completed',
        'team_created',
        'invite_sent',
        'payment_failed',
        'payment_succeeded',
    ];

    /**
     * Key conversion funnels to evaluate.
     *
     * @var array<string, list<string>>
     */
    private const KEY_FUNNELS = [
        'signup' => ['page_view', 'sign_up', 'email_verified', 'login', 'onboarding_step', 'feature_used'],
        'purchase' => ['view_item', 'add_to_cart', 'view_cart', 'begin_checkout', 'add_payment_info', 'purchase'],
        'subscription' => ['sign_up', 'start_trial', 'subscribe', 'plan_upgrade'],
        'engagement' => ['session_start', 'page_view', 'search', 'feature_used', 'form_submit'],
        'retention' => ['login', 'feature_used', 'goal_conversion', 'share', 'feedback'],
    ];

    /**
     * Assess the full analytics maturity level.
     *
     * @param  array{providers?: array<string, bool>, identity_resolution?: bool, real_time?: bool, consent_mode?: bool, gdpr?: bool, ccpa?: bool, event_validation?: bool, dedup?: bool, enrichment?: bool, testing?: bool, ci_integration?: bool, monitoring?: bool, auto_tracking?: bool}  $capabilities  Optional capability flags
     * @return array{level: int, level_name: string, score: int, max_score: int, grade: string, dimensions: array<string, array{name: string, score: int, max: int, weight: int, status: string, findings: list<string>, recommendations: list<string>}>, strengths: list<string>, weaknesses: list<string>, roadmap: list<string>}
     */
    public function assess(array $capabilities = []): array
    {
        $dimensions = $this->assessDimensions($capabilities);

        $totalScore = 0;
        $totalMax = 0;

        foreach ($dimensions as $dim) {
            $totalScore += $dim['score'] * $dim['weight'];
            $totalMax += $dim['max'] * $dim['weight'];
        }

        $percentage = $totalMax > 0 ? ($totalScore / $totalMax) * 100 : 0;
        $normalizedScore = (int) round($percentage);

        $level = match (true) {
            $normalizedScore >= 85 => self::LEVEL_LEADING,
            $normalizedScore >= 65 => self::LEVEL_ADVANCED,
            $normalizedScore >= 45 => self::LEVEL_STANDARD,
            $normalizedScore >= 25 => self::LEVEL_BASIC,
            default => self::LEVEL_AD_HOC,
        };

        $levelName = match ($level) {
            self::LEVEL_AD_HOC => 'Ad Hoc',
            self::LEVEL_BASIC => 'Basic',
            self::LEVEL_STANDARD => 'Standard',
            self::LEVEL_ADVANCED => 'Advanced',
            self::LEVEL_LEADING => 'Leading',
            default => 'Unknown',
        };

        $grade = match (true) {
            $normalizedScore >= 90 => 'A+ (Industry Leading)',
            $normalizedScore >= 80 => 'A (Excellent)',
            $normalizedScore >= 70 => 'B+ (Strong)',
            $normalizedScore >= 60 => 'B (Good)',
            $normalizedScore >= 50 => 'C+ (Developing)',
            $normalizedScore >= 40 => 'C (Basic)',
            default => 'D (Needs Improvement)',
        };

        // Identify strengths and weaknesses
        $strengths = [];
        $weaknesses = [];

        foreach ($dimensions as $key => $dim) {
            $dimPct = $dim['max'] > 0 ? ($dim['score'] / $dim['max']) * 100 : 0;
            if ($dimPct >= 80) {
                $strengths[] = $dim['name'];
            } elseif ($dimPct < 50) {
                $weaknesses[] = $dim['name'];
            }
        }

        $roadmap = $this->generateRoadmap($level, $dimensions);

        return [
            'level' => $level,
            'level_name' => $levelName,
            'score' => $normalizedScore,
            'max_score' => 100,
            'grade' => $grade,
            'dimensions' => $dimensions,
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
            'roadmap' => $roadmap,
        ];
    }

    /**
     * Get quick maturity summary (score + level only).
     *
     * @param  array<string, bool>  $capabilities
     * @return array{level: int, level_name: string, score: int, grade: string}
     */
    public function quickAssess(array $capabilities = []): array
    {
        $full = $this->assess($capabilities);

        return [
            'level' => $full['level'],
            'level_name' => $full['level_name'],
            'score' => $full['score'],
            'grade' => $full['grade'],
        ];
    }

    /**
     * Compare maturity between two capability snapshots.
     *
     * @param  array<string, bool>  $current
     * @param  array<string, bool>  $previous
     * @return array{current: array<string, mixed>, previous: array<string, mixed>, delta: int, improved: list<string>, declined: list<string>, unchanged: list<string>}
     */
    public function compare(array $current, array $previous): array
    {
        $currentAssessment = $this->assess($current);
        $previousAssessment = $this->assess($previous);

        $improved = [];
        $declined = [];
        $unchanged = [];

        foreach ($currentAssessment['dimensions'] as $key => $dim) {
            $prevDim = $previousAssessment['dimensions'][$key] ?? null;
            if ($prevDim === null) {
                $improved[] = $dim['name'];
                continue;
            }

            $prevPct = $prevDim['max'] > 0 ? ($prevDim['score'] / $prevDim['max']) * 100 : 0;
            $currPct = $dim['max'] > 0 ? ($dim['score'] / $dim['max']) * 100 : 0;

            if ($currPct > $prevPct + 5) {
                $improved[] = $dim['name'];
            } elseif ($currPct < $prevPct - 5) {
                $declined[] = $dim['name'];
            } else {
                $unchanged[] = $dim['name'];
            }
        }

        return [
            'current' => $currentAssessment,
            'previous' => $previousAssessment,
            'delta' => $currentAssessment['score'] - $previousAssessment['score'],
            'improved' => $improved,
            'declined' => $declined,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * Assess all individual dimensions.
     *
     * @param  array<string, bool>  $capabilities
     * @return array<string, array{name: string, score: int, max: int, weight: int, status: string, findings: list<string>, recommendations: list<string>}>
     */
    private function assessDimensions(array $capabilities): array
    {
        return [
            'event_coverage' => $this->assessEventCoverage(),
            'provider_coverage' => $this->assessProviderCoverage($capabilities),
            'funnel_instrumentation' => $this->assessFunnelInstrumentation(),
            'identity_resolution' => $this->assessIdentityResolution($capabilities),
            'real_time' => $this->assessRealTime($capabilities),
            'privacy_compliance' => $this->assessPrivacyCompliance($capabilities),
            'operational_excellence' => $this->assessOperationalExcellence($capabilities),
            'data_quality' => $this->assessDataQuality($capabilities),
        ];
    }

    /**
     * Assess event coverage dimension.
     *
     * @return array{name: string, score: int, max: int, weight: int, status: string, findings: list<string>, recommendations: list<string>}
     */
    private function assessEventCoverage(): array
    {
        $catalogCount = EventCatalog::count();
        $basicCovered = $this->countCovered(self::BASIC_EVENTS);
        $standardCovered = $this->countCovered(self::STANDARD_EVENTS);
        $advancedCovered = $this->countCovered(self::ADVANCED_EVENTS);

        $score = 0;
        $findings = [];
        $recommendations = [];

        // Basic coverage (0-3 points)
        if ($basicCovered === count(self::BASIC_EVENTS)) {
            $score += 3;
        } elseif ($basicCovered >= 3) {
            $score += 2;
        } elseif ($basicCovered >= 2) {
            $score += 1;
        } else {
            $recommendations[] = 'Implement core events: page_view, sign_up, login, purchase';
        }
        $findings[] = "Basic events: {$basicCovered}/" . count(self::BASIC_EVENTS);

        // Standard coverage (0-4 points)
        if ($standardCovered === count(self::STANDARD_EVENTS)) {
            $score += 4;
        } elseif ($standardCovered >= count(self::STANDARD_EVENTS) * 0.7) {
            $score += 3;
        } elseif ($standardCovered >= count(self::STANDARD_EVENTS) * 0.5) {
            $score += 2;
        } else {
            $recommendations[] = 'Add standard SaaS lifecycle events (trial, subscription, churn)';
        }
        $findings[] = "Standard events: {$standardCovered}/" . count(self::STANDARD_EVENTS);

        // Advanced coverage (0-3 points)
        if ($advancedCovered === count(self::ADVANCED_EVENTS)) {
            $score += 3;
        } elseif ($advancedCovered >= count(self::ADVANCED_EVENTS) * 0.7) {
            $score += 2;
        } elseif ($advancedCovered >= count(self::ADVANCED_EVENTS) * 0.4) {
            $score += 1;
        } else {
            $recommendations[] = 'Add engagement and consent events for advanced tracking';
        }
        $findings[] = "Advanced events: {$advancedCovered}/" . count(self::ADVANCED_EVENTS);

        // Catalog breadth bonus (0-3 points, ~10 events per point)
        $breadthBonus = min(3, (int) floor($catalogCount / 40));
        $score += $breadthBonus;
        $findings[] = "Total catalog size: {$catalogCount} events";

        return [
            'name' => 'Event Coverage',
            'score' => $score,
            'max' => 13,
            'weight' => self::WEIGHT_EVENT_COVERAGE,
            'status' => $this->statusLabel($score, 13),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess provider coverage dimension.
     *
     * @param  array<string, bool>  $capabilities
     * @return array{name: string, score: int, max: int, weight: int, status: string, findings: list<string>, recommendations: list<string>}
     */
    private function assessProviderCoverage(array $capabilities): array
    {
        $providers = $capabilities['providers'] ?? [];
        $enabledCount = count(array_filter($providers, fn (bool $v): bool => $v));

        // Primary providers
        $hasGA4 = $providers['ga4'] ?? false;
        $hasMeta = $providers['meta_pixel'] ?? false;
        $hasGTM = $providers['gtm'] ?? false;

        $score = 0;
        $findings = [];
        $recommendations = [];

        // Primary provider (0-2 points)
        if ($hasGA4) {
            $score += 2;
            $findings[] = 'GA4: configured';
        } else {
            $recommendations[] = 'Configure Google Analytics 4 as primary provider';
        }

        // Secondary provider (0-2 points)
        if ($hasMeta) {
            $score += 1;
            $findings[] = 'Meta Pixel: configured';
        }
        if ($hasGTM) {
            $score += 1;
            $findings[] = 'GTM: configured';
        }

        // Multi-provider bonus (0-3 points)
        if ($enabledCount >= 5) {
            $score += 3;
        } elseif ($enabledCount >= 4) {
            $score += 2;
        } elseif ($enabledCount >= 3) {
            $score += 1;
        } else {
            $recommendations[] = 'Add Plausible or PostHog for privacy-focused analytics';
        }
        $findings[] = "Active providers: {$enabledCount}";

        // Provider parity (0-3 points)
        $catalog = EventCatalog::all();
        $ga4Coverage = $this->providerCoverageCount($catalog, 'ga4');
        $totalEvents = count($catalog);
        $parity = $totalEvents > 0 ? $ga4Coverage / $totalEvents : 0;

        if ($parity >= 0.95) {
            $score += 3;
        } elseif ($parity >= 0.8) {
            $score += 2;
        } elseif ($parity >= 0.6) {
            $score += 1;
        }
        $findings[] = "GA4 coverage: " . round($parity * 100, 0) . '%';

        return [
            'name' => 'Provider Coverage',
            'score' => $score,
            'max' => 10,
            'weight' => self::WEIGHT_PROVIDER_COVERAGE,
            'status' => $this->statusLabel($score, 10),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess funnel instrumentation dimension.
     *
     * @return array{name: string, score: int, max: int, weight: int, status: string, findings: list<string>, recommendations: list<string>}
     */
    private function assessFunnelInstrumentation(): array
    {
        $score = 0;
        $findings = [];
        $recommendations = [];
        $totalSteps = 0;
        $totalPresent = 0;

        foreach (self::KEY_FUNNELS as $funnelName => $steps) {
            $present = 0;
            foreach ($steps as $step) {
                $totalSteps++;
                if (EventCatalog::has($step)) {
                    $present++;
                    $totalPresent++;
                }
            }

            $pct = count($steps) > 0 ? ($present / count($steps)) * 100 : 0;
            $findings[] = "{$funnelName} funnel: {$present}/" . count($steps) . ' (' . round($pct, 0) . '%)';

            if ($pct === 100.0) {
                $score += 3;
            } elseif ($pct >= 80.0) {
                $score += 2;
            } elseif ($pct >= 50.0) {
                $score += 1;
            } else {
                $recommendations[] = "Instrument {$funnelName} funnel steps";
            }
        }

        return [
            'name' => 'Funnel Instrumentation',
            'score' => $score,
            'max' => 15,
            'weight' => self::WEIGHT_FUNNEL_INSTRUMENTATION,
            'status' => $this->statusLabel($score, 15),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess identity resolution dimension.
     *
     * @param  array<string, bool>  $capabilities
     * @return array{name: string, score: int, max: int, weight: int, status: string, findings: list<string>, recommendations: list<string>}
     */
    private function assessIdentityResolution(array $capabilities): array
    {
        $score = 0;
        $findings = [];
        $recommendations = [];

        $hasIdentity = $capabilities['identity_resolution'] ?? true;
        $hasAutoTracking = $capabilities['auto_tracking'] ?? true;

        if ($hasIdentity) {
            $score += 3;
            $findings[] = 'Client ↔ User ID linking: enabled';
        } else {
            $recommendations[] = 'Enable client ID ↔ user ID identity linking';
        }

        if ($hasAutoTracking) {
            $score += 2;
            $findings[] = 'Server-side auto-tracking: enabled';
        } else {
            $recommendations[] = 'Enable server-side lifecycle auto-tracking';
        }

        // Cross-device / anonymous tracking
        $score += 2;
        $findings[] = 'Anonymous ID tracking: available';
        $score += 1;
        $findings[] = 'Session tracking: available';

        $score += 2;
        $findings[] = 'Identity graph service: available';

        return [
            'name' => 'Identity Resolution',
            'score' => $score,
            'max' => 10,
            'weight' => self::WEIGHT_IDENTITY_RESOLUTION,
            'status' => $this->statusLabel($score, 10),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess real-time capabilities dimension.
     *
     * @param  array<string, bool>  $capabilities
     * @return array{name: string, score: int, max: int, weight: int, status: string, findings: list<string>, recommendations: list<string>}
     */
    private function assessRealTime(array $capabilities): array
    {
        $score = 0;
        $findings = [];
        $recommendations = [];

        $hasRealTime = $capabilities['real_time'] ?? true;

        if ($hasRealTime) {
            $score += 3;
            $findings[] = 'Real-time event streaming: available';
        } else {
            $recommendations[] = 'Enable real-time event streaming';
        }

        $score += 2;
        $findings[] = 'SSE endpoint: available';
        $score += 2;
        $findings[] = 'Queue-based dispatch: available';
        $score += 2;
        $findings[] = 'Alert rules engine: available';

        $hasMonitoring = $capabilities['monitoring'] ?? true;
        if ($hasMonitoring) {
            $score += 1;
            $findings[] = 'Health monitoring: enabled';
        }

        return [
            'name' => 'Real-time Capabilities',
            'score' => $score,
            'max' => 10,
            'weight' => self::WEIGHT_REAL_TIME,
            'status' => $this->statusLabel($score, 10),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess privacy & compliance dimension.
     *
     * @param  array<string, bool>  $capabilities
     * @return array{name: string, score: int, max: int, weight: int, status: string, findings: list<string>, recommendations: list<string>}
     */
    private function assessPrivacyCompliance(array $capabilities): array
    {
        $score = 0;
        $findings = [];
        $recommendations = [];

        $hasConsentMode = $capabilities['consent_mode'] ?? true;
        $hasGdpr = $capabilities['gdpr'] ?? true;
        $hasCcpa = $capabilities['ccpa'] ?? true;

        if ($hasConsentMode) {
            $score += 4;
            $findings[] = 'Consent Mode v2: implemented';
        } else {
            $recommendations[] = 'Implement Consent Mode v2 for GDPR compliance';
        }

        if ($hasGdpr) {
            $score += 3;
            $findings[] = 'GDPR compliance: enabled';
        } else {
            $recommendations[] = 'Enable GDPR compliance features';
        }

        if ($hasCcpa) {
            $score += 2;
            $findings[] = 'CCPA compliance: enabled';
        } else {
            $recommendations[] = 'Enable CCPA compliance features';
        }

        // Consent purposes
        $score += 2;
        $findings[] = 'Granular consent purposes: available';

        // Data erasure
        $score += 2;
        $findings[] = 'Data erasure (right to be forgotten): available';

        // Privacy impact assessment
        $score += 2;
        $findings[] = 'Privacy impact assessment: available';

        return [
            'name' => 'Privacy & Compliance',
            'score' => $score,
            'max' => 13,
            'weight' => self::WEIGHT_PRIVACY_COMPLIANCE,
            'status' => $this->statusLabel($score, 13),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess operational excellence dimension.
     *
     * @param  array<string, bool>  $capabilities
     * @return array{name: string, score: int, max: int, weight: int, status: string, findings: list<string>, recommendations: list<string>}
     */
    private function assessOperationalExcellence(array $capabilities): array
    {
        $score = 0;
        $findings = [];
        $recommendations = [];

        $hasTesting = $capabilities['testing'] ?? true;
        $hasCI = $capabilities['ci_integration'] ?? true;

        if ($hasTesting) {
            $score += 2;
            $findings[] = 'Automated testing: available';
        } else {
            $recommendations[] = 'Set up automated testing for analytics events';
        }

        if ($hasCI) {
            $score += 2;
            $findings[] = 'CI integration: available';
        } else {
            $recommendations[] = 'Integrate analytics checks into CI pipeline';
        }

        // CLI commands
        $score += 2;
        $findings[] = 'Admin CLI commands: available';
        $score += 2;
        $findings[] = 'Overview + Test commands: available';
        $score += 1;
        $findings[] = 'Health + Readiness checks: available';

        return [
            'name' => 'Operational Excellence',
            'score' => $score,
            'max' => 9,
            'weight' => self::WEIGHT_OPERATIONAL_EXCELLENCE,
            'status' => $this->statusLabel($score, 9),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess data quality dimension.
     *
     * @param  array<string, bool>  $capabilities
     * @return array{name: string, score: int, max: int, weight: int, status: string, findings: list<string>, recommendations: list<string>}
     */
    private function assessDataQuality(array $capabilities): array
    {
        $score = 0;
        $findings = [];
        $recommendations = [];

        $hasValidation = $capabilities['event_validation'] ?? true;
        $hasDedup = $capabilities['dedup'] ?? true;
        $hasEnrichment = $capabilities['enrichment'] ?? true;

        if ($hasValidation) {
            $score += 3;
            $findings[] = 'Event schema validation: enabled';
        } else {
            $recommendations[] = 'Enable event schema validation';
        }

        if ($hasDedup) {
            $score += 2;
            $findings[] = 'Event deduplication: enabled';
        } else {
            $recommendations[] = 'Enable event deduplication';
        }

        if ($hasEnrichment) {
            $score += 2;
            $findings[] = 'Event enrichment pipeline: enabled';
        }

        $score += 1;
        $findings[] = 'UTM enrichment: available';
        $score += 1;
        $findings[] = 'PII sanitization: available';

        return [
            'name' => 'Data Quality',
            'score' => $score,
            'max' => 9,
            'weight' => self::WEIGHT_DATA_QUALITY,
            'status' => $this->statusLabel($score, 9),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Generate a prioritized roadmap for reaching the next maturity level.
     *
     * @param  int  $currentLevel
     * @param  array<string, array{name: string, score: int, max: int, recommendations: list<string>}>  $dimensions
     * @return list<string>
     */
    private function generateRoadmap(int $currentLevel, array $dimensions): array
    {
        $roadmap = [];

        if ($currentLevel < self::LEVEL_BASIC) {
            $roadmap[] = 'P1: Instrument core events (page_view, sign_up, login, purchase)';
            $roadmap[] = 'P1: Configure GA4 as primary analytics provider';
        }

        if ($currentLevel < self::LEVEL_STANDARD) {
            $roadmap[] = 'P2: Add SaaS lifecycle events (trial, subscription, churn)';
            $roadmap[] = 'P2: Implement consent mode for GDPR compliance';
            $roadmap[] = 'P2: Enable identity resolution (client ↔ user ID linking)';
        }

        if ($currentLevel < self::LEVEL_ADVANCED) {
            $roadmap[] = 'P3: Add engagement events (scroll, session, consent)';
            $roadmap[] = 'P3: Implement multi-touch attribution modeling';
            $roadmap[] = 'P3: Enable real-time event streaming';
            $roadmap[] = 'P3: Add automated testing and CI integration';
        }

        if ($currentLevel < self::LEVEL_LEADING) {
            $roadmap[] = 'P4: Implement predictive analytics (churn, LTV forecasts)';
            $roadmap[] = 'P4: Add data-driven attribution (Shapley values)';
            $roadmap[] = 'P4: Build custom dashboards and alerting';
        }

        foreach ($dimensions as $dim) {
            foreach ($dim['recommendations'] as $rec) {
                $roadmap[] = "→ {$rec}";
            }
        }

        return array_slice(array_unique($roadmap), 0, 15);
    }

    /**
     * Count how many events in a list are present in the catalog.
     *
     * @param  list<string>  $events
     */
    private function countCovered(array $events): int
    {
        $count = 0;
        foreach ($events as $event) {
            if (EventCatalog::has($event)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count how many events have a non-null value for a given provider field.
     *
     * @param  array<string, array<string, mixed>>  $catalog
     */
    private function providerCoverageCount(array $catalog, string $provider): int
    {
        $count = 0;
        foreach ($catalog as $entry) {
            if (($entry[$provider] ?? null) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Generate a status label from score/max.
     */
    private function statusLabel(int $score, int $max): string
    {
        $pct = $max > 0 ? ($score / $max) * 100 : 0;

        return match (true) {
            $pct >= 90 => 'excellent',
            $pct >= 70 => 'good',
            $pct >= 50 => 'developing',
            $pct >= 25 => 'needs_attention',
            default => 'critical',
        };
    }
}
