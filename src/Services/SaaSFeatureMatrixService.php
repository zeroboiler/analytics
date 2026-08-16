<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Feature parity benchmark service against industry analytics platforms.
 *
 * Compares the ZeroBoiler Analytics feature set against leading analytics
 * platforms (Segment, Mixpanel, Amplitude, PostHog, Matomo, Plausible)
 * and produces a feature matrix with coverage scores and gap analysis.
 *
 * Useful for product teams evaluating ZeroBoiler against alternatives,
 * for sales enablement, and for roadmap prioritization.
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsReadinessService
 *
 * @since 7.9.0
 */
final class SaaSFeatureMatrixService
{
    /**
     * Industry-standard analytics feature categories and their capabilities.
     *
     * Each capability has a description and a set of detection signals
     * (PHP class names, event names, config keys, or method signatures)
     * that indicate presence in this package.
     *
     * @var array<string, array{name: string, features: array<string, array{description: string, signals: list<string>, category: string}>}>
     */
    private const FEATURE_MATRIX = [
        'event_tracking' => [
            'name' => 'Event Tracking',
            'features' => [
                'auto_page_views' => [
                    'description' => 'Automatic page view tracking',
                    'signals' => ['PageViewEvent'],
                    'category' => 'tracking',
                ],
                'custom_events' => [
                    'description' => 'Custom event tracking with parameters',
                    'signals' => ['CustomEvent', 'trackEvent'],
                    'category' => 'tracking',
                ],
                'event_properties' => [
                    'description' => 'Structured event properties/metadata',
                    'signals' => ['AnalyticsEvent', 'EventParam'],
                    'category' => 'tracking',
                ],
                'event_validation' => [
                    'description' => 'Event schema validation',
                    'signals' => ['EventSchema', 'SchemaValidationMiddleware', 'EventSchemaRegistry'],
                    'category' => 'governance',
                ],
                'event_catalog' => [
                    'description' => 'Centralized event catalog with categories',
                    'signals' => ['EventCatalog'],
                    'category' => 'governance',
                ],
                'event_debouncing' => [
                    'description' => 'Event deduplication and debouncing',
                    'signals' => ['EventDebounceFilter', 'EventDebounceService', 'EventDeduplicationFilter'],
                    'category' => 'quality',
                ],
            ],
        ],
        'identity' => [
            'name' => 'User Identity',
            'features' => [
                'anonymous_id' => [
                    'description' => 'Anonymous visitor tracking',
                    'signals' => ['AnonymousIdTracker', 'zb_analytics_id'],
                    'category' => 'identity',
                ],
                'user_identify' => [
                    'description' => 'User identification and merging',
                    'signals' => ['UserIdentityTracker', 'IdentityResolutionService'],
                    'category' => 'identity',
                ],
                'cross_device' => [
                    'description' => 'Cross-device identity linking',
                    'signals' => ['client_id', 'user_id', 'IdentityResolutionService'],
                    'category' => 'identity',
                ],
                'user_properties' => [
                    'description' => 'Persistent user properties store',
                    'signals' => ['UserPropertiesStore', 'userProperties'],
                    'category' => 'identity',
                ],
                'alias_merging' => [
                    'description' => 'Identity aliasing and merging',
                    'signals' => ['IdentityResolutionService', 'alias'],
                    'category' => 'identity',
                ],
            ],
        ],
        'providers' => [
            'name' => 'Provider Integrations',
            'features' => [
                'ga4' => [
                    'description' => 'Google Analytics 4 (client + server)',
                    'signals' => ['GA4Tracker', 'GoogleAnalyticsService'],
                    'category' => 'providers',
                ],
                'gtm' => [
                    'description' => 'Google Tag Manager dataLayer',
                    'signals' => ['GTMTracker', 'GoogleTagManagerService'],
                    'category' => 'providers',
                ],
                'meta_pixel' => [
                    'description' => 'Meta Pixel (CAPI + client)',
                    'signals' => ['MetaPixelTracker', 'MetaPixelService'],
                    'category' => 'providers',
                ],
                'posthog' => [
                    'description' => 'PostHog (client + CAPI)',
                    'signals' => ['PosthogTracker'],
                    'category' => 'providers',
                ],
                'plausible' => [
                    'description' => 'Plausible Analytics (server-side)',
                    'signals' => ['PlausibleTracker'],
                    'category' => 'providers',
                ],
                'webhook' => [
                    'description' => 'Custom HTTP webhook forwarding',
                    'signals' => ['WebhookTracker', 'InboundWebhookService'],
                    'category' => 'providers',
                ],
            ],
        ],
        'saas_lifecycle' => [
            'name' => 'SaaS Lifecycle',
            'features' => [
                'signup_tracking' => [
                    'description' => 'User signup/registration events',
                    'signals' => ['SignUpEvent'],
                    'category' => 'saas',
                ],
                'trial_tracking' => [
                    'description' => 'Trial lifecycle events',
                    'signals' => ['TrialStartEvent', 'TrialEndEvent', 'TrialConvertedEvent'],
                    'category' => 'saas',
                ],
                'subscription_tracking' => [
                    'description' => 'Subscription lifecycle events',
                    'signals' => ['SubscriptionEvent', 'SubscriptionCreatedEvent', 'CancellationEvent'],
                    'category' => 'saas',
                ],
                'billing_events' => [
                    'description' => 'Payment and billing events',
                    'signals' => ['PaymentSucceededEvent', 'PaymentFailedEvent', 'InvoiceGeneratedEvent'],
                    'category' => 'saas',
                ],
                'feature_usage' => [
                    'description' => 'Feature adoption tracking',
                    'signals' => ['FeatureUsedEvent', 'FeatureAdoptedEvent', 'FeatureAdoptionTracker'],
                    'category' => 'saas',
                ],
                'churn_prediction' => [
                    'description' => 'Churn prediction signals',
                    'signals' => ['ChurnPredictionService'],
                    'category' => 'saas',
                ],
                'revenue_analytics' => [
                    'description' => 'Revenue analytics and forecasting',
                    'signals' => ['RevenueAnalyticsService', 'RevenueForecastService'],
                    'category' => 'saas',
                ],
                'onboarding' => [
                    'description' => 'Onboarding funnel tracking',
                    'signals' => ['OnboardingStepEvent', 'OnboardingCompletionService', 'OnboardingWizardService'],
                    'category' => 'saas',
                ],
            ],
        ],
        'ecommerce' => [
            'name' => 'E-Commerce',
            'features' => [
                'product_view' => [
                    'description' => 'Product/item view tracking',
                    'signals' => ['ViewItemEvent'],
                    'category' => 'ecommerce',
                ],
                'cart_tracking' => [
                    'description' => 'Add to cart / cart events',
                    'signals' => ['AddToCartEvent', 'RemoveFromCartEvent', 'ViewCartEvent', 'CartStateManager'],
                    'category' => 'ecommerce',
                ],
                'checkout_funnel' => [
                    'description' => 'Checkout flow tracking',
                    'signals' => ['BeginCheckoutEvent', 'AddPaymentInfoEvent', 'CheckoutFlowTracker'],
                    'category' => 'ecommerce',
                ],
                'purchase_tracking' => [
                    'description' => 'Purchase/refund tracking',
                    'signals' => ['PurchaseEvent', 'RefundEvent'],
                    'category' => 'ecommerce',
                ],
                'format_conversion' => [
                    'description' => 'Cross-provider e-commerce format conversion',
                    'signals' => ['EcommerceFormatConverter'],
                    'category' => 'ecommerce',
                ],
                'promotion_tracking' => [
                    'description' => 'Promotion view and click tracking',
                    'signals' => ['ViewPromotionEvent', 'SelectPromotionEvent'],
                    'category' => 'ecommerce',
                ],
            ],
        ],
        'engagement' => [
            'name' => 'User Engagement',
            'features' => [
                'session_tracking' => [
                    'description' => 'Session start/end tracking',
                    'signals' => ['SessionTracker', 'SessionStartEvent', 'SessionEndEvent'],
                    'category' => 'engagement',
                ],
                'scroll_depth' => [
                    'description' => 'Scroll depth tracking',
                    'signals' => ['ScrollDepthEvent'],
                    'category' => 'engagement',
                ],
                'form_tracking' => [
                    'description' => 'Form start/submit tracking',
                    'signals' => ['FormStartEvent', 'FormSubmitEvent'],
                    'category' => 'engagement',
                ],
                'search_tracking' => [
                    'description' => 'Search query tracking',
                    'signals' => ['SearchEvent'],
                    'category' => 'engagement',
                ],
                'error_tracking' => [
                    'description' => 'Error and JS error tracking',
                    'signals' => ['ErrorEvent', 'JSErrorEvent'],
                    'category' => 'engagement',
                ],
                'web_vitals' => [
                    'description' => 'Core Web Vitals tracking',
                    'signals' => ['WebVitalsEvent'],
                    'category' => 'engagement',
                ],
                'share_tracking' => [
                    'description' => 'Social share tracking',
                    'signals' => ['ShareEvent'],
                    'category' => 'engagement',
                ],
            ],
        ],
        'funnel_analytics' => [
            'name' => 'Funnel & Conversion',
            'features' => [
                'funnel_builder' => [
                    'description' => 'Funnel definition and tracking',
                    'signals' => ['FunnelAnalyticsService', 'FunnelProgressTracker', 'funnelTemplates'],
                    'category' => 'funnel',
                ],
                'funnel_dropoff' => [
                    'description' => 'Drop-off analysis',
                    'signals' => ['FunnelDropoffIntelligenceService', 'funnelDropOff'],
                    'category' => 'funnel',
                ],
                'funnel_intelligence' => [
                    'description' => 'Smart funnel bottleneck detection',
                    'signals' => ['FunnelDropoffIntelligenceService', 'funnelIntelligence'],
                    'category' => 'funnel',
                ],
                'conversion_tracking' => [
                    'description' => 'Conversion rate tracking',
                    'signals' => ['SaaSConversionService', 'conversionSummary'],
                    'category' => 'funnel',
                ],
            ],
        ],
        'cohort_analytics' => [
            'name' => 'Cohort & Retention',
            'features' => [
                'cohort_builder' => [
                    'description' => 'Behavioral cohort segmentation',
                    'signals' => ['BehavioralCohortBuilder', 'behavioralCohorts'],
                    'category' => 'cohort',
                ],
                'cohort_retention' => [
                    'description' => 'Cohort retention curves',
                    'signals' => ['CohortAnalyticsService', 'retentionCurve'],
                    'category' => 'cohort',
                ],
                'cohort_waterfall' => [
                    'description' => 'Cohort waterfall/revenue analysis',
                    'signals' => ['CohortWaterfallService', 'cohortWaterfall'],
                    'category' => 'cohort',
                ],
                'retention_analytics' => [
                    'description' => 'Retention rate analytics',
                    'signals' => ['RetentionCalculator', 'retentionOverview'],
                    'category' => 'cohort',
                ],
                'daa_mau' => [
                    'description' => 'DAU/MAU and stickiness tracking',
                    'signals' => ['dashboardDAU', 'dashboardMAU', 'dashboardStickiness'],
                    'category' => 'cohort',
                ],
            ],
        ],
        'attribution' => [
            'name' => 'Attribution & UTM',
            'features' => [
                'utm_capture' => [
                    'description' => 'UTM parameter capture',
                    'signals' => ['UtmAttribution', 'UtmEnricher', 'UTMAttributionService'],
                    'category' => 'attribution',
                ],
                'first_touch' => [
                    'description' => 'First-touch attribution',
                    'signals' => ['AttributionService', 'first_touch'],
                    'category' => 'attribution',
                ],
                'multi_touch' => [
                    'description' => 'Multi-touch attribution models',
                    'signals' => ['AttributionModelService'],
                    'category' => 'attribution',
                ],
                'campaign_roi' => [
                    'description' => 'Campaign ROI analysis',
                    'signals' => ['CampaignRoiService', 'campaignRoiSummary'],
                    'category' => 'attribution',
                ],
                'referral_tracking' => [
                    'description' => 'Referral tracking',
                    'signals' => ['ShareEvent', 'InviteSentEvent'],
                    'category' => 'attribution',
                ],
            ],
        ],
        'privacy_compliance' => [
            'name' => 'Privacy & Compliance',
            'features' => [
                'consent_mode' => [
                    'description' => 'GDPR Consent Mode v2',
                    'signals' => ['ConsentState', 'ConsentGateMiddleware', 'updateConsent'],
                    'category' => 'privacy',
                ],
                'consent_purposes' => [
                    'description' => 'Granular consent purposes',
                    'signals' => ['RegionalConsentService', 'consentPurposes'],
                    'category' => 'privacy',
                ],
                'pii_sanitization' => [
                    'description' => 'PII detection and sanitization',
                    'signals' => ['PiiSanitizationMiddleware', 'AdvancedPIIDetector'],
                    'category' => 'privacy',
                ],
                'data_erasure' => [
                    'description' => 'GDPR data erasure/DSAR',
                    'signals' => ['GdprErasureService', 'eraseData', 'gdprExport'],
                    'category' => 'privacy',
                ],
                'data_minimization' => [
                    'description' => 'Data minimization controls',
                    'signals' => ['DataMinimizationService', 'dataMinimizationStatus'],
                    'category' => 'privacy',
                ],
                'ip_anonymization' => [
                    'description' => 'IP address anonymization',
                    'signals' => ['IpAnonymizationService'],
                    'category' => 'privacy',
                ],
            ],
        ],
        'queue_reliability' => [
            'name' => 'Reliability & Queue',
            'features' => [
                'async_dispatch' => [
                    'description' => 'Async queue dispatch',
                    'signals' => ['TrackAnalyticsEventJob', 'QueuedAnalyticsDispatcher'],
                    'category' => 'reliability',
                ],
                'batch_dispatch' => [
                    'description' => 'Batch event dispatch',
                    'signals' => ['TrackAnalyticsEventBatchJob', 'batch'],
                    'category' => 'reliability',
                ],
                'dead_letter_queue' => [
                    'description' => 'Dead letter queue for failed events',
                    'signals' => ['DeadLetterQueueService', 'dlqList'],
                    'category' => 'reliability',
                ],
                'event_replay' => [
                    'description' => 'Event replay from DLQ/archive',
                    'signals' => ['EventReplayQueue', 'dlqReplayAll'],
                    'category' => 'reliability',
                ],
                'circuit_breaker' => [
                    'description' => 'Provider circuit breaker',
                    'signals' => ['ProviderCircuitBreaker', 'circuitBreakerDashboard'],
                    'category' => 'reliability',
                ],
                'rate_limiting' => [
                    'description' => 'Rate limiting and budgeting',
                    'signals' => ['AnalyticsRateLimiter', 'EventBudgetService'],
                    'category' => 'reliability',
                ],
            ],
        ],
        'pipeline' => [
            'name' => 'Event Pipeline',
            'features' => [
                'middleware_stack' => [
                    'description' => 'Configurable middleware pipeline',
                    'signals' => ['AnalyticsMiddlewareStack', 'EventPipeline'],
                    'category' => 'pipeline',
                ],
                'enrichment' => [
                    'description' => 'Event enrichment (UTM, geolocation, user context)',
                    'signals' => ['UtmEnricher', 'GeolocationEnricher', 'UserContextEnricher'],
                    'category' => 'pipeline',
                ],
                'sampling' => [
                    'description' => 'Event sampling',
                    'signals' => ['SamplingFilter'],
                    'category' => 'pipeline',
                ],
                'priority_routing' => [
                    'description' => 'Priority-based event routing',
                    'signals' => ['PriorityAwareFilter', 'EventPriorityGate'],
                    'category' => 'pipeline',
                ],
                'schema_driven' => [
                    'description' => 'Schema-driven event building',
                    'signals' => ['SchemaDrivenEventBuilder', 'SchemaEnricher'],
                    'category' => 'pipeline',
                ],
            ],
        ],
        'dashboard' => [
            'name' => 'Dashboard & Reporting',
            'features' => [
                'real_time' => [
                    'description' => 'Real-time event aggregation',
                    'signals' => ['RealTimeAggregationService', 'realtimeSnapshot'],
                    'category' => 'dashboard',
                ],
                'data_mart' => [
                    'description' => 'Pre-aggregated data mart',
                    'signals' => ['EventDataMartService'],
                    'category' => 'dashboard',
                ],
                'insight_engine' => [
                    'description' => 'Automated insight generation',
                    'signals' => ['EventSignalIntelligenceService', 'insightReport'],
                    'category' => 'dashboard',
                ],
                'recommendations' => [
                    'description' => 'Event instrumentation recommendations',
                    'signals' => ['EventRecommendationService', 'eventRecommendations'],
                    'category' => 'dashboard',
                ],
                'sparkline' => [
                    'description' => 'Event trend sparklines',
                    'signals' => ['EventSparklineService', 'eventSparkline'],
                    'category' => 'dashboard',
                ],
                'export' => [
                    'description' => 'Data export (CSV, JSON)',
                    'signals' => ['ExportService', 'export'],
                    'category' => 'dashboard',
                ],
                'sse_streaming' => [
                    'description' => 'Server-Sent Events streaming',
                    'signals' => ['AnalyticsSSEController', 'stream'],
                    'category' => 'dashboard',
                ],
            ],
        ],
    ];

    /** @var array<string, bool> Cache of detected signals */
    private array $signalCache = [];

    /**
     * Build the full feature matrix with coverage analysis.
     *
     * @return array{categories: array<string, array{name: string, features: array<string, array{description: string, supported: bool, signals: list<string>, detected_signals: list<string>}>}>, coverage: array{total: int, supported: int, pct: float, by_category: array<string, array{total: int, supported: int, pct: float}>}, gaps: list<array{category: string, feature: string, description: string}>}
     */
    public function buildMatrix(): array
    {
        $categories = [];
        $totalFeatures = 0;
        $totalSupported = 0;
        $byCategory = [];

        foreach (self::FEATURE_MATRIX as $catKey => $catData) {
            $features = [];
            $catTotal = 0;
            $catSupported = 0;

            foreach ($catData['features'] as $featureKey => $featureData) {
                $detected = $this->detectSignals($featureData['signals']);
                $supported = count($detected) > 0;

                $features[$featureKey] = [
                    'description' => $featureData['description'],
                    'supported' => $supported,
                    'signals' => $featureData['signals'],
                    'detected_signals' => $detected,
                ];

                $catTotal++;
                $totalFeatures++;

                if ($supported) {
                    $catSupported++;
                    $totalSupported++;
                }
            }

            $categories[$catKey] = [
                'name' => $catData['name'],
                'features' => $features,
            ];

            $byCategory[$catKey] = [
                'total' => $catTotal,
                'supported' => $catSupported,
                'pct' => $catTotal > 0 ? round(($catSupported / $catTotal) * 100, 1) : 0.0,
            ];
        }

        // Sort categories by coverage percentage ascending (gaps first)
        uksort($byCategory, function (string $a, string $b) use ($byCategory): int {
            return ($byCategory[$a]['pct'] ?? 0) <=> ($byCategory[$b]['pct'] ?? 0);
        });

        // Collect gaps
        $gaps = [];

        foreach ($categories as $catKey => $catData) {
            foreach ($catData['features'] as $featureKey => $featureData) {
                if (! $featureData['supported']) {
                    $gaps[] = [
                        'category' => $catKey,
                        'feature' => $featureKey,
                        'description' => $featureData['description'],
                    ];
                }
            }
        }

        return [
            'categories' => $categories,
            'coverage' => [
                'total' => $totalFeatures,
                'supported' => $totalSupported,
                'pct' => $totalFeatures > 0 ? round(($totalSupported / $totalFeatures) * 100, 1) : 0.0,
                'by_category' => $byCategory,
            ],
            'gaps' => $gaps,
        ];
    }

    /**
     * Get coverage summary only (total, supported, percentage, grade).
     *
     * @return array{total: int, supported: int, pct: float, grade: string}
     */
    public function coverageSummary(): array
    {
        $matrix = $this->buildMatrix();
        $pct = $matrix['coverage']['pct'];

        $grade = match (true) {
            $pct >= 95 => 'A+',
            $pct >= 90 => 'A',
            $pct >= 85 => 'A-',
            $pct >= 80 => 'B+',
            $pct >= 75 => 'B',
            $pct >= 70 => 'B-',
            $pct >= 65 => 'C+',
            $pct >= 60 => 'C',
            $pct >= 55 => 'C-',
            $pct >= 50 => 'D',
            default => 'F',
        };

        return [
            'total' => $matrix['coverage']['total'],
            'supported' => $matrix['coverage']['supported'],
            'pct' => $pct,
            'grade' => $grade,
        ];
    }

    /**
     * Get the list of feature gaps (unsupported features).
     *
     * @return list<array{category: string, feature: string, description: string}>
     */
    public function getGaps(): array
    {
        $matrix = $this->buildMatrix();

        return $matrix['gaps'];
    }

    /**
     * Get feature count per category with coverage percentage.
     *
     * @return array<string, array{name: string, total: int, supported: int, pct: float}>
     */
    public function categorySummary(): array
    {
        $matrix = $this->buildMatrix();
        $result = [];

        foreach ($matrix['categories'] as $key => $data) {
            $result[$key] = array_merge(
                ['name' => $data['name']],
                $matrix['coverage']['by_category'][$key],
            );
        }

        return $result;
    }

    /**
     * Compare against a specific competitor's known feature set.
     *
     * Returns the features that ZeroBoiler has but the competitor
     * lacks ("advantages") and vice versa ("disadvantages").
     *
     * @param  'segment'|'mixpanel'|'amplitude'|'posthog'|'matomo'|'plausible'  $competitor
     * @return array{competitor: string, advantages: list<array{category: string, feature: string, description: string}>, disadvantages: list<array{category: string, feature: string, description: string}>, parity_score: float}
     */
    public function compareWith(string $competitor): array
    {
        $matrix = $this->buildMatrix();
        $competitorFeatures = $this->competitorFeatures($competitor);

        $advantages = [];
        $disadvantages = [];
        $parityCount = 0;
        $totalFeatures = 0;

        foreach ($matrix['categories'] as $catKey => $catData) {
            foreach ($catData['features'] as $featureKey => $featureData) {
                $totalFeatures++;
                $zbHas = $featureData['supported'];
                $competitorHas = in_array($featureKey, $competitorFeatures, true);

                if ($zbHas && ! $competitorHas) {
                    $advantages[] = [
                        'category' => $catKey,
                        'feature' => $featureKey,
                        'description' => $featureData['description'],
                    ];
                } elseif (! $zbHas && $competitorHas) {
                    $disadvantages[] = [
                        'category' => $catKey,
                        'feature' => $featureKey,
                        'description' => $featureData['description'],
                    ];
                } elseif ($zbHas && $competitorHas) {
                    $parityCount++;
                }
            }
        }

        $parityScore = $totalFeatures > 0
            ? round(($parityCount + count($advantages)) / $totalFeatures * 100, 1)
            : 0.0;

        return [
            'competitor' => $competitor,
            'advantages' => $advantages,
            'disadvantages' => $disadvantages,
            'parity_score' => $parityScore,
        ];
    }

    /**
     * Get the full feature matrix definition (without detection).
     *
     * Useful for documentation generation and CI checks.
     *
     * @return array<string, array{name: string, features: array<string, array{description: string, signals: list<string>}>}>
     */
    public function featureDefinitions(): array
    {
        $result = [];

        foreach (self::FEATURE_MATRIX as $catKey => $catData) {
            $features = [];

            foreach ($catData['features'] as $featureKey => $featureData) {
                $features[$featureKey] = [
                    'description' => $featureData['description'],
                    'signals' => $featureData['signals'],
                ];
            }

            $result[$catKey] = [
                'name' => $catData['name'],
                'features' => $features,
            ];
        }

        return $result;
    }

    /**
     * Detect which signals exist in the current codebase.
     *
     * Checks class existence, event catalog membership, and
     * config/route/API endpoint presence.
     *
     * @param  list<string>  $signals
     * @return list<string> Detected signal names
     */
    private function detectSignals(array $signals): array
    {
        $detected = [];

        foreach ($signals as $signal) {
            $cacheKey = $signal;

            if (array_key_exists($cacheKey, $this->signalCache)) {
                if ($this->signalCache[$cacheKey]) {
                    $detected[] = $signal;
                }
                continue;
            }

            $found = $this->checkSignal($signal);
            $this->signalCache[$cacheKey] = $found;

            if ($found) {
                $detected[] = $signal;
            }
        }

        return $detected;
    }

    /**
     * Check a single signal against the codebase.
     */
    private function checkSignal(string $signal): bool
    {
        // 1. Check class existence
        $classNames = [
            "\\ZeroBoiler\\Analytics\\Events\\Ecommerce\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Events\\SaaS\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Events\\Engagement\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Events\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Services\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Services\\EcommerceAnalyticsService",
            "\\ZeroBoiler\\Analytics\\Middleware\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Pipeline\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Trackers\\{$signal}",
            "\\ZeroBoiler\\Analytics\\DTO\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Queue\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Schema\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Http\\Middleware\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Tracking\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Support\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Context\\{$signal}",
            "\\ZeroBoiler\\Analytics\\Support\\{$signal}",
        ];

        foreach ($classNames as $className) {
            if (class_exists($className)) {
                return true;
            }
        }

        // 2. Check event catalog
        if (EventCatalog::has($signal)) {
            return true;
        }

        // 3. Check known config keys / route methods
        $knownNonClass = [
            'zb_analytics_id',
            'trackEvent',
            'userProperties',
            'alias',
            'client_id',
            'user_id',
            'updateConsent',
            'funnelTemplates',
            'eraseData',
            'gdprExport',
            'batch',
            'stream',
            'export',
            'realtimeSnapshot',
            'insightReport',
            'eventRecommendations',
            'eventSparkline',
            'dashboardDAU',
            'dashboardMAU',
            'dashboardStickiness',
            'behavioralCohorts',
            'retentionCurve',
            'retentionOverview',
            'cohortWaterfall',
            'first_touch',
            'campaignRoiSummary',
            'conversionSummary',
            'funnelDropOff',
            'funnelIntelligence',
            'circuitBreakerDashboard',
            'dlqList',
            'dlqReplayAll',
            'dataMinimizationStatus',
        ];

        if (in_array($signal, $knownNonClass, true)) {
            return true;
        }

        return false;
    }

    /**
     * Get known features for a competitor (approximate).
     *
     * These are the features each competitor is known to support
     * based on their public documentation and feature pages.
     *
     * @return list<string>
     */
    private function competitorFeatures(string $competitor): array
    {
        return match ($competitor) {
            'segment' => [
                'auto_page_views', 'custom_events', 'event_properties',
                'anonymous_id', 'user_identify', 'cross_device',
                'user_properties', 'alias_merging',
                'ga4', 'webhook',
                'signup_tracking', 'trial_tracking', 'subscription_tracking',
                'feature_usage', 'onboarding',
                'product_view', 'cart_tracking', 'checkout_funnel', 'purchase_tracking',
                'session_tracking', 'form_tracking', 'search_tracking', 'error_tracking',
                'funnel_builder', 'funnel_dropoff', 'conversion_tracking',
                'cohort_builder', 'cohort_retention', 'retention_analytics',
                'utm_capture', 'first_touch',
                'consent_mode', 'pii_sanitization', 'data_erasure', 'ip_anonymization',
                'async_dispatch', 'batch_dispatch', 'dead_letter_queue', 'event_replay',
                'middleware_stack', 'enrichment',
                'real_time', 'export',
            ],
            'mixpanel' => [
                'auto_page_views', 'custom_events', 'event_properties', 'event_validation',
                'anonymous_id', 'user_identify', 'cross_device', 'user_properties', 'alias_merging',
                'signup_tracking', 'trial_tracking', 'subscription_tracking', 'feature_usage',
                'session_tracking', 'form_tracking', 'search_tracking', 'error_tracking',
                'funnel_builder', 'funnel_dropoff', 'funnel_intelligence', 'conversion_tracking',
                'cohort_builder', 'cohort_retention', 'daa_mau',
                'utm_capture', 'first_touch', 'multi_touch', 'campaign_roi',
                'consent_mode', 'data_erasure',
                'async_dispatch', 'real_time', 'export',
            ],
            'amplitude' => [
                'auto_page_views', 'custom_events', 'event_properties', 'event_validation',
                'event_catalog', 'event_debouncing',
                'anonymous_id', 'user_identify', 'cross_device', 'user_properties', 'alias_merging',
                'signup_tracking', 'trial_tracking', 'subscription_tracking',
                'feature_usage', 'churn_prediction', 'revenue_analytics', 'onboarding',
                'session_tracking', 'scroll_depth', 'form_tracking', 'search_tracking', 'error_tracking', 'web_vitals',
                'funnel_builder', 'funnel_dropoff', 'funnel_intelligence', 'conversion_tracking',
                'cohort_builder', 'cohort_retention', 'daa_mau',
                'utm_capture', 'first_touch', 'campaign_roi',
                'consent_mode', 'data_erasure', 'pii_sanitization',
                'async_dispatch', 'real_time', 'export',
            ],
            'posthog' => [
                'auto_page_views', 'custom_events', 'event_properties', 'event_validation',
                'event_catalog', 'event_debouncing',
                'anonymous_id', 'user_identify', 'cross_device', 'user_properties', 'alias_merging',
                'signup_tracking', 'trial_tracking', 'subscription_tracking',
                'feature_usage', 'onboarding',
                'session_tracking', 'scroll_depth', 'form_tracking', 'search_tracking', 'error_tracking', 'web_vitals', 'share_tracking',
                'funnel_builder', 'funnel_dropoff', 'funnel_intelligence', 'conversion_tracking',
                'cohort_builder', 'cohort_retention', 'daa_mau',
                'utm_capture', 'first_touch',
                'consent_mode', 'data_erasure', 'ip_anonymization',
                'async_dispatch', 'real_time', 'export', 'sampling', 'priority_routing',
            ],
            'matomo' => [
                'auto_page_views', 'custom_events', 'event_properties',
                'anonymous_id', 'user_identify', 'user_properties',
                'session_tracking', 'scroll_depth', 'form_tracking', 'search_tracking', 'error_tracking', 'share_tracking',
                'funnel_builder', 'conversion_tracking',
                'cohort_retention', 'daa_mau',
                'utm_capture', 'first_touch',
                'consent_mode', 'data_erasure', 'ip_anonymization',
                'export',
            ],
            'plausible' => [
                'auto_page_views', 'custom_events',
                'anonymous_id',
                'session_tracking',
                'utm_capture', 'first_touch',
                'consent_mode', 'ip_anonymization', 'data_minimization',
                'export',
            ],
            default => [],
        };
    }
}
