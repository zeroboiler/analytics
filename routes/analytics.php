<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsSSEController;

/*
|--------------------------------------------------------------------------
| Analytics API Routes
|--------------------------------------------------------------------------
|
| Server-side endpoints for frontend event tracking.
| Track/batch/identify/consent require authentication and are rate-limited.
| Health endpoint is public for monitoring.
|
*/

Route::prefix('analytics')->group(function () {
    // Public health check (no auth required)
    Route::get('health', [AnalyticsEventController::class, 'health']);

    // Public catalog endpoint
    Route::get('catalog', [AnalyticsEventController::class, 'catalog']);

    // Event stream (public, for dashboards — event data is non-sensitive)
    Route::get('stream', [AnalyticsEventController::class, 'stream']);
    Route::get('stream/stats', [AnalyticsEventController::class, 'streamStats']);

    // Export endpoint
    Route::get('export', [AnalyticsEventController::class, 'export']);
    Route::get('export/mappings', [AnalyticsEventController::class, 'exportMappings']);
    Route::get('export/catalog.csv', [AnalyticsEventController::class, 'exportCatalogCsv']);

    // Stats endpoint (public, for admin dashboards)
    Route::get('stats', [AnalyticsEventController::class, 'stats']);

    // Inbound webhook (public, signature-verified)
    Route::post('webhook/inbound', [AnalyticsEventController::class, 'inboundWebhook']);

    // Alert rules (public, for admin dashboards)
    Route::get('alerts', [AnalyticsEventController::class, 'alerts']);
    Route::post('alerts/evaluate', [AnalyticsEventController::class, 'evaluateAlerts']);

    // Funnel visualization (public, for admin dashboards)
    Route::get('funnels', [AnalyticsEventController::class, 'funnelData']);
    Route::post('funnels/compare', [AnalyticsEventController::class, 'funnelCompare']);
    Route::get('funnels/drop-off', [AnalyticsEventController::class, 'funnelDropOff']);
    Route::get('funnels/chart', [AnalyticsEventController::class, 'funnelChart']);

    // Dead letter queue (public, for admin dashboards)
    Route::get('dlq', [AnalyticsEventController::class, 'dlqList']);
    Route::get('dlq/summary', [AnalyticsEventController::class, 'dlqSummary']);
    Route::delete('dlq', [AnalyticsEventController::class, 'dlqClear']);
    Route::delete('dlq/{offset}', [AnalyticsEventController::class, 'dlqRemove']);
    Route::post('dlq/replay', [AnalyticsEventController::class, 'dlqReplayAll']);
    Route::post('dlq/replay/{offset}', [AnalyticsEventController::class, 'dlqReplaySingle']);

    // Real-time aggregation
    Route::get('realtime', [AnalyticsEventController::class, 'realtimeSnapshot']);
    Route::get('realtime/top-events', [AnalyticsEventController::class, 'realtimeTopEvents']);

    // A/B test analytics
    Route::get('ab-tests/{experimentId}', [AnalyticsEventController::class, 'abTestResults']);
    Route::post('ab-tests/{experimentId}/exposure', [AnalyticsEventController::class, 'abTestRecordExposure']);
    Route::post('ab-tests/{experimentId}/conversion', [AnalyticsEventController::class, 'abTestRecordConversion']);
    Route::delete('ab-tests/{experimentId}', [AnalyticsEventController::class, 'abTestDelete']);

    // Analytics snapshots
    Route::get('snapshots/daily', [AnalyticsEventController::class, 'dailySnapshot']);
    Route::get('snapshots/hourly', [AnalyticsEventController::class, 'hourlySnapshot']);
    Route::get('snapshots/comparison', [AnalyticsEventController::class, 'dailyComparison']);

    // SaaS KPI
    Route::get('kpi', [AnalyticsEventController::class, 'saasKpiSummary']);
    Route::get('kpi/mrr-history', [AnalyticsEventController::class, 'saasKpiMrrHistory']);

    // UTM aggregation
    Route::get('utm/sources', [AnalyticsEventController::class, 'utmTopSources']);
    Route::get('utm/campaigns', [AnalyticsEventController::class, 'utmTopCampaigns']);
    Route::get('utm/breakdown', [AnalyticsEventController::class, 'utmBreakdown']);

    // Reporting (public, for admin dashboards)
    Route::get('report', [AnalyticsEventController::class, 'report']);
    Route::get('report/summary', [AnalyticsEventController::class, 'reportSummary']);
    Route::get('report/top-events', [AnalyticsEventController::class, 'reportTopEvents']);
    Route::get('report/trending', [AnalyticsEventController::class, 'reportTrending']);
    Route::get('report/provider-stats', [AnalyticsEventController::class, 'reportProviderStats']);

    // Event taxonomy (public, for admin dashboards)
    Route::get('taxonomy', [AnalyticsEventController::class, 'taxonomySummary']);
    Route::get('taxonomy/definitions', [AnalyticsEventController::class, 'taxonomyDefinitions']);
    Route::get('taxonomy/grouped', [AnalyticsEventController::class, 'taxonomyGrouped']);
    Route::get('taxonomy/{tag}', [AnalyticsEventController::class, 'taxonomyTag']);

    // Event Schema Validation API
    Route::get('schemas', [AnalyticsEventController::class, 'schemaList']);
    Route::get('schemas/summary', [AnalyticsEventController::class, 'schemaSummary']);
    Route::post('schemas/validate', [AnalyticsEventController::class, 'schemaValidate']);
    Route::get('schemas/{eventName}', [AnalyticsEventController::class, 'schemaDetail']);

    // Tracking preference endpoints (authenticated)
    Route::get('preference', [AnalyticsEventController::class, 'preference']);
    Route::post('opt-out', [AnalyticsEventController::class, 'optOut']);
    Route::post('opt-in', [AnalyticsEventController::class, 'optIn']);

    // User profile endpoint (authenticated)
    Route::get('profile', [AnalyticsEventController::class, 'profile']);

    // GDPR data erasure (authenticated)
    Route::delete('data', [AnalyticsEventController::class, 'eraseData']);

    // GDPR data export / DSAR (authenticated)
    Route::get('gdpr/export', [AnalyticsEventController::class, 'gdprExport']);

    // Authenticated endpoints (require auth:sanctum middleware from route registration)
    Route::post('events', [AnalyticsEventController::class, 'track']);
    Route::post('batch', [AnalyticsEventController::class, 'batch']);
    Route::post('identify', [AnalyticsEventController::class, 'identify']);
    Route::post('pageview', [AnalyticsEventController::class, 'pageview']);
    Route::post('consent', [AnalyticsEventController::class, 'updateConsent']);

    // Event Buckets (time-binned aggregation)
    Route::get('buckets', [AnalyticsEventController::class, 'eventBucketList']);
    Route::get('buckets/{series}', [AnalyticsEventController::class, 'eventBuckets']);
    Route::get('buckets/{series}/summary', [AnalyticsEventController::class, 'eventBucketSummary']);
    Route::get('buckets/{seriesA}/compare/{seriesB}', [AnalyticsEventController::class, 'eventBucketCompare']);

    // SaaS Health Score
    Route::get('health-score', [AnalyticsEventController::class, 'healthScore']);
    Route::post('health-score/calculate', [AnalyticsEventController::class, 'healthScoreCalculate']);
    Route::get('health-score/history', [AnalyticsEventController::class, 'healthScoreHistory']);

    // User Journey Timeline
    Route::get('journeys/stats', [AnalyticsEventController::class, 'journeyStats']);
    Route::get('journeys/patterns', [AnalyticsEventController::class, 'journeyPatterns']);
    Route::get('journeys/drop-offs', [AnalyticsEventController::class, 'journeyDropOffs']);
    Route::get('journeys/search', [AnalyticsEventController::class, 'journeySearch']);
    Route::post('journeys/funnel', [AnalyticsEventController::class, 'journeyFunnel']);
    Route::get('journeys/{journeyId}', [AnalyticsEventController::class, 'journeyTimeline']);

    // SaaS Journey Milestones (v2.62.0)
    Route::post('journeys/milestones', [AnalyticsEventController::class, 'journeyRecordMilestone']);
    Route::get('journeys/milestones/{journey}', [AnalyticsEventController::class, 'journeyGetProgress']);
    Route::get('journeys/list', [AnalyticsEventController::class, 'journeyListAll']);
    Route::delete('journeys/{journey}', [AnalyticsEventController::class, 'journeyResetProgress']);

    // Provider Telemetry (v2.62.0)
    Route::get('telemetry', [AnalyticsEventController::class, 'telemetry']);
    Route::post('telemetry/probe', [AnalyticsEventController::class, 'telemetryProbe']);

    // Campaign ROI (v2.62.0)
    Route::get('campaigns/roi', [AnalyticsEventController::class, 'campaignRoiSummary']);
    Route::get('campaigns/{campaign}/roi', [AnalyticsEventController::class, 'campaignRoi']);
    Route::post('campaigns/spend', [AnalyticsEventController::class, 'campaignRegisterSpend']);

    // Data Minimization / Privacy (v2.62.0)
    Route::get('privacy/minimization', [AnalyticsEventController::class, 'dataMinimizationStatus']);
    Route::post('privacy/minimization/preview', [AnalyticsEventController::class, 'dataMinimizationPreview']);

    // SaaS Conversion Analytics (v2.66.0)
    Route::get('conversion/summary', [AnalyticsEventController::class, 'conversionSummary']);
    Route::get('conversion/funnel', [AnalyticsEventController::class, 'conversionFunnel']);
    Route::get('conversion/activation/{userId}', [AnalyticsEventController::class, 'conversionActivationScore']);
    Route::get('conversion/time-to-convert', [AnalyticsEventController::class, 'conversionTimeToConvert']);

    // Data Warehouse Export (v2.67.0)
    Route::post('export/warehouse', [AnalyticsEventController::class, 'exportWarehouse']);

    // Dashboard Overview (v2.67.0)
    Route::get('dashboard', [AnalyticsEventController::class, 'dashboardOverview']);

    // Event Deconfliction (v2.69.0)
    Route::get('deconfliction', [AnalyticsEventController::class, 'deconfliction']);

    // Event Schema Inference (v2.69.0)
    Route::get('schemas/infer', [AnalyticsEventController::class, 'schemaInfer']);

    // Click Heatmap (v2.69.0)
    Route::post('heatmap/click', [AnalyticsEventController::class, 'heatmapClick']);
    Route::get('heatmap/data', [AnalyticsEventController::class, 'heatmapData']);
    Route::get('heatmap/urls', [AnalyticsEventController::class, 'heatmapUrls']);
    Route::delete('heatmap/data', [AnalyticsEventController::class, 'heatmapClear']);

    // Rate Limit Dashboard (v2.69.0)
    Route::get('rate-limits', [AnalyticsEventController::class, 'rateLimitDashboard']);
    Route::get('rate-limits/{clientId}', [AnalyticsEventController::class, 'rateLimitClientStatus']);
    Route::delete('rate-limits/{clientId}', [AnalyticsEventController::class, 'rateLimitResetClient']);

    // Circuit Breaker Dashboard (v2.70.0)
    Route::get('circuit-breaker', [AnalyticsEventController::class, 'circuitBreakerDashboard']);
    Route::get('circuit-breaker/summary', [AnalyticsEventController::class, 'circuitBreakerSummary']);

    // Compliance Audit Report (v2.70.0)
    Route::get('compliance', [AnalyticsEventController::class, 'complianceReport']);
    Route::get('compliance/score', [AnalyticsEventController::class, 'complianceScore']);
    Route::post('compliance/invalidate', [AnalyticsEventController::class, 'complianceInvalidateCache']);

    // Recovery Service (v2.70.0)
    Route::get('recovery/budget', [AnalyticsEventController::class, 'recoveryBudget']);
    Route::get('recovery/health', [AnalyticsEventController::class, 'recoveryHealth']);
    Route::get('recovery/history', [AnalyticsEventController::class, 'recoveryHistory']);
    Route::post('recovery/batch', [AnalyticsEventController::class, 'recoveryBatch']);

    // Circuit Breaker Control (v2.70.0)
    Route::post('circuit-breaker/{provider}/reset', [AnalyticsEventController::class, 'circuitBreakerReset']);
    Route::post('circuit-breaker/{provider}/trip', [AnalyticsEventController::class, 'circuitBreakerTrip']);

    // Analytics Sandbox (v2.71.0)
    Route::get('sandbox', [AnalyticsEventController::class, 'sandboxStatus']);
    Route::get('sandbox/events', [AnalyticsEventController::class, 'sandboxEvents']);
    Route::get('sandbox/replay-log', [AnalyticsEventController::class, 'sandboxReplayLog']);
    Route::delete('sandbox/events', [AnalyticsEventController::class, 'sandboxClear']);

    // Per-Provider Rate Limits (v2.71.0)
    Route::get('provider-rate-limits', [AnalyticsEventController::class, 'providerRateLimits']);
    Route::post('provider-rate-limits/reset', [AnalyticsEventController::class, 'providerRateLimitsReset']);

    // Schema Versioning (v2.71.0)
    Route::get('schema-versions', [AnalyticsEventController::class, 'schemaVersions']);

    // SaaS Starter Readiness (v2.71.0)
    Route::get('readiness', [AnalyticsEventController::class, 'readiness']);

    // SaaS Analytics Maturity & AARRR (v2.77.0)
    Route::get('maturity', [AnalyticsEventController::class, 'maturity']);
    Route::get('onboarding', [AnalyticsEventController::class, 'onboardingChecklist']);
    Route::get('funnel-readiness', [AnalyticsEventController::class, 'funnelReadiness']);
    Route::get('industry-standard', [AnalyticsEventController::class, 'industryStandard']);

    // Revenue Forecasting (v2.81.0)
    Route::get('forecast', [AnalyticsEventController::class, 'revenueForecast']);
    Route::get('forecast/summary', [AnalyticsEventController::class, 'revenueForecastSummary']);
    Route::get('forecast/project', [AnalyticsEventController::class, 'revenueForecastProject']);
    Route::get('forecast/ltv', [AnalyticsEventController::class, 'ltvCalculation']);
    Route::get('forecast/ltv-cac', [AnalyticsEventController::class, 'ltvCacRatio']);
    Route::get('forecast/payback', [AnalyticsEventController::class, 'paybackPeriod']);
    Route::get('forecast/runway', [AnalyticsEventController::class, 'runwayEstimate']);
    Route::get('forecast/cohort-retention', [AnalyticsEventController::class, 'cohortRetentionCurve']);
    Route::get('forecast/mrr-movement', [AnalyticsEventController::class, 'mrrMovementBreakdown']);

    // Churn Prediction (v2.81.0)
    Route::post('churn/score', [AnalyticsEventController::class, 'churnScoreUser']);
    Route::post('churn/score-batch', [AnalyticsEventController::class, 'churnScoreBatch']);
    Route::post('churn/cohort-summary', [AnalyticsEventController::class, 'churnCohortSummary']);
    Route::get('churn/weights', [AnalyticsEventController::class, 'churnSignalWeights']);
    Route::get('churn/thresholds', [AnalyticsEventController::class, 'churnThresholds']);

    // SaaS Metrics Benchmarks (v2.87.0)
    Route::get('benchmarks', [AnalyticsEventController::class, 'benchmarksList']);
    Route::get('benchmarks/{metric}', [AnalyticsEventController::class, 'benchmarksGet']);
    Route::get('benchmarks/compare', [AnalyticsEventController::class, 'benchmarksCompare']);
    Route::get('benchmarks/report-card', [AnalyticsEventController::class, 'benchmarksReportCard']);
    Route::get('benchmarks/quick-start', [AnalyticsEventController::class, 'benchmarksQuickStart']);

    // Comprehensive Health Check (v2.98.0)
    Route::get('health-check', [AnalyticsEventController::class, 'healthCheck']);
    Route::get('ping', [AnalyticsEventController::class, 'ping']);

    // SaaS Coverage Report (v67.0.0)
    Route::get('coverage', [AnalyticsEventController::class, 'coverageReport']);
    Route::get('coverage/summary', [AnalyticsEventController::class, 'coverageSummary']);

    // Event Rules Engine (v3.1.0)
    Route::get('rules', [AnalyticsEventController::class, 'rulesList']);
    Route::post('rules/evaluate', [AnalyticsEventController::class, 'rulesEvaluate']);
    Route::get('rules/absence', [AnalyticsEventController::class, 'rulesEvaluateAbsence']);
    Route::get('rules/counts', [AnalyticsEventController::class, 'rulesTriggerCounts']);

    // User Properties (v3.1.0)
    Route::get('user-properties/{identity}', [AnalyticsEventController::class, 'userPropertiesGet']);
    Route::post('user-properties/{identity}', [AnalyticsEventController::class, 'userPropertiesSet']);
    Route::post('user-properties/{identity}/merge', [AnalyticsEventController::class, 'userPropertiesMerge']);
    Route::post('user-properties/{identity}/increment', [AnalyticsEventController::class, 'userPropertiesIncrement']);
    Route::post('user-properties/link', [AnalyticsEventController::class, 'userPropertiesLink']);
    Route::delete('user-properties/{identity}', [AnalyticsEventController::class, 'userPropertiesDelete']);

    // Retention & Stickiness (v3.1.0)
    Route::get('retention', [AnalyticsEventController::class, 'retentionOverview']);
    Route::get('retention/{date}', [AnalyticsEventController::class, 'retentionForCohort']);
    Route::get('retention/{date}/rolling/{days}', [AnalyticsEventController::class, 'rollingRetention']);
    Route::get('retention/{date}/curve', [AnalyticsEventController::class, 'retentionCurve']);
    Route::get('retention/cohorts/{days}', [AnalyticsEventController::class, 'retentionCohortComparison']);
    Route::get('stickiness', [AnalyticsEventController::class, 'stickiness']);

    // Identity Resolution (v3.2.0)
    Route::get('identity/{clientId}', [AnalyticsEventController::class, 'identityLookup']);
    Route::get('identity/user/{userId}', [AnalyticsEventController::class, 'identityUserLookup']);
    Route::post('identity/resolve', [AnalyticsEventController::class, 'identityResolve']);
    Route::delete('identity/{clientId}', [AnalyticsEventController::class, 'identityForgetClient']);
    Route::delete('identity/user/{userId}', [AnalyticsEventController::class, 'identityForgetUser']);

    // Behavioral Cohorts (v3.1.0)
    Route::get('cohorts', [AnalyticsEventController::class, 'behavioralCohorts']);
    Route::get('cohorts/{identity}', [AnalyticsEventController::class, 'behavioralCohortForUser']);
    Route::get('cohorts/summary/{days}', [AnalyticsEventController::class, 'behavioralCohortSummary']);
    Route::get('cohorts/transitions/{daysAgo}', [AnalyticsEventController::class, 'behavioralCohortTransitions']);

    // Growth Engine (v3.6.0)
    Route::get('growth/dashboard', [AnalyticsEventController::class, 'growthDashboard']);
    Route::get('growth/activation', [AnalyticsEventController::class, 'growthActivation']);
    Route::get('growth/stickiness', [AnalyticsEventController::class, 'growthStickiness']);
    Route::get('growth/velocity', [AnalyticsEventController::class, 'growthVelocity']);
    Route::get('growth/cohort-health', [AnalyticsEventController::class, 'growthCohortHealth']);

    // Onboarding Wizard (v3.6.0)
    Route::get('onboarding/wizard', [AnalyticsEventController::class, 'onboardingWizardState']);
    Route::get('onboarding/wizard/steps', [AnalyticsEventController::class, 'onboardingWizardSteps']);
    Route::get('onboarding/wizard/progress', [AnalyticsEventController::class, 'onboardingWizardProgress']);
    Route::get('onboarding/wizard/recommendations', [AnalyticsEventController::class, 'onboardingWizardRecommendations']);
    Route::get('onboarding/wizard/config-checklist', [AnalyticsEventController::class, 'onboardingWizardConfigChecklist']);
    Route::get('onboarding/wizard/readiness', [AnalyticsEventController::class, 'onboardingWizardReadiness']);
    Route::get('onboarding/wizard/quick-start', [AnalyticsEventController::class, 'onboardingWizardQuickStart']);

    // Weekly Digest (v3.6.0)
    Route::get('digest', [AnalyticsEventController::class, 'weeklyDigest']);
    Route::get('digest/latest', [AnalyticsEventController::class, 'weeklyDigestLatest']);

    // Revenue Intelligence (v3.7.0)
    Route::get('revenue/intelligence', [AnalyticsEventController::class, 'revenueIntelligence']);
    Route::get('revenue/quick-summary', [AnalyticsEventController::class, 'revenueQuickSummary']);
    Route::get('revenue/signals', [AnalyticsEventController::class, 'revenueSignals']);

    // Event Enrichment (v3.7.0)
    Route::get('enrichment/diagnostics', [AnalyticsEventController::class, 'enrichmentDiagnostics']);

    // Subscription Lifecycle (v3.7.0)
    Route::post('subscription/trial-started', [AnalyticsEventController::class, 'subscriptionTrialStarted']);
    Route::post('subscription/trial-converted', [AnalyticsEventController::class, 'subscriptionTrialConverted']);
    Route::post('subscription/trial-expired', [AnalyticsEventController::class, 'subscriptionTrialExpired']);
    Route::post('subscription/created', [AnalyticsEventController::class, 'subscriptionCreated']);
    Route::post('subscription/renewed', [AnalyticsEventController::class, 'subscriptionRenewed']);
    Route::post('subscription/plan-upgraded', [AnalyticsEventController::class, 'subscriptionPlanUpgraded']);
    Route::post('subscription/plan-downgraded', [AnalyticsEventController::class, 'subscriptionPlanDowngraded']);
    Route::post('subscription/cancelled', [AnalyticsEventController::class, 'subscriptionCancelled']);
    Route::post('subscription/paused', [AnalyticsEventController::class, 'subscriptionPaused']);
    Route::post('subscription/resumed', [AnalyticsEventController::class, 'subscriptionResumed']);
    Route::post('subscription/payment-succeeded', [AnalyticsEventController::class, 'subscriptionPaymentSucceeded']);
    Route::post('subscription/payment-failed', [AnalyticsEventController::class, 'subscriptionPaymentFailed']);
    Route::post('subscription/billing-retry', [AnalyticsEventController::class, 'subscriptionBillingRetry']);

    // Event Archetypes (v3.9.0)
    Route::get('archetypes', [AnalyticsEventController::class, 'archetypeList']);
    Route::get('archetypes/{key}', [AnalyticsEventController::class, 'archetypeDetail']);
    Route::get('archetypes/gaps', [AnalyticsEventController::class, 'archetypeGaps']);
    Route::post('archetypes/{key}/score', [AnalyticsEventController::class, 'archetypeScore']);

    // Anonymized Aggregation (v3.9.0)
    Route::get('anonymized/summary', [AnalyticsEventController::class, 'anonymizedSummary']);
    Route::get('anonymized/by-event', [AnalyticsEventController::class, 'anonymizedByEvent']);
    Route::get('anonymized/by-category', [AnalyticsEventController::class, 'anonymizedByCategory']);
    Route::get('anonymized/by-time', [AnalyticsEventController::class, 'anonymizedByTime']);

    // Config Drift (v3.9.0)
    Route::get('config-drift', [AnalyticsEventController::class, 'configDriftDetect']);
    Route::get('config-drift/baseline', [AnalyticsEventController::class, 'configDriftBaselineInfo']);
    Route::post('config-drift/capture', [AnalyticsEventController::class, 'configDriftCapture']);
    Route::delete('config-drift/baseline', [AnalyticsEventController::class, 'configDriftClear']);

    // Event Archive (v4.0.0)
    Route::get('archive', [AnalyticsEventController::class, 'archiveSearch']);
    Route::get('archive/stats', [AnalyticsEventController::class, 'archiveStats']);
    Route::get('archive/{id}', [AnalyticsEventController::class, 'archiveGet']);
    Route::post('archive/{id}/replay', [AnalyticsEventController::class, 'archiveReplay']);
    Route::delete('archive', [AnalyticsEventController::class, 'archiveClear']);

    // Event Governance (v4.1.0)
    Route::get('governance', [AnalyticsEventController::class, 'governanceReport']);
    Route::get('governance/events', [AnalyticsEventController::class, 'governanceRegistrations']);
    Route::get('governance/attention', [AnalyticsEventController::class, 'governanceAttention']);
    Route::get('governance/naming', [AnalyticsEventController::class, 'governanceNaming']);
    Route::get('governance/quality', [AnalyticsEventController::class, 'governanceQuality']);
    Route::get('governance/deprecations', [AnalyticsEventController::class, 'governanceDeprecations']);
    Route::post('governance/register', [AnalyticsEventController::class, 'governanceRegister']);
    Route::post('governance/activate', [AnalyticsEventController::class, 'governanceActivate']);
    Route::post('governance/deprecate', [AnalyticsEventController::class, 'governanceDeprecate']);
    Route::post('governance/retire', [AnalyticsEventController::class, 'governanceRetire']);

    // Event Impact Analytics (v4.2.0)
    Route::post('impact/calculate', [AnalyticsEventController::class, 'eventImpactCalculate']);
    Route::post('impact/conversion-drivers', [AnalyticsEventController::class, 'eventImpactConversionDrivers']);
    Route::post('impact/retention-drivers', [AnalyticsEventController::class, 'eventImpactRetentionDrivers']);

    // Feature Adoption Analytics (v4.2.0)
    Route::get('adoption/profile/{userId}', [AnalyticsEventController::class, 'featureAdoptionProfile']);
    Route::post('adoption/record', [AnalyticsEventController::class, 'featureAdoptionRecord']);
    Route::post('adoption/funnel', [AnalyticsEventController::class, 'featureAdoptionFunnel']);
    Route::get('adoption/recent/{userId}', [AnalyticsEventController::class, 'featureAdoptionRecent']);
    Route::get('adoption/streak/{userId}/{featureName}', [AnalyticsEventController::class, 'featureAdoptionStreak']);
    Route::delete('adoption/profile/{userId}', [AnalyticsEventController::class, 'featureAdoptionClear']);

    // Event Sequencing Analysis (v4.3.0)
    Route::post('correlation/matrix', [AnalyticsEventController::class, 'correlationMatrix']);
    Route::post('correlation/conversion-rate', [AnalyticsEventController::class, 'correlationConversionRate']);

    // Event Budget & Throttling (v4.3.0)
    Route::get('budget', [AnalyticsEventController::class, 'budgetStats']);
    Route::get('budget/client/{clientId}', [AnalyticsEventController::class, 'budgetClientStatus']);
    Route::get('budget/user/{userId}', [AnalyticsEventController::class, 'budgetUserStatus']);
    Route::get('budget/top-clients', [AnalyticsEventController::class, 'budgetTopClients']);
    Route::delete('budget', [AnalyticsEventController::class, 'budgetClear']);
    Route::delete('budget/client/{clientId}', [AnalyticsEventController::class, 'budgetResetClient']);
    Route::delete('budget/user/{userId}', [AnalyticsEventController::class, 'budgetResetUser']);

    // Event Cost Tracking (v4.4.0)
    Route::get('cost', [AnalyticsEventController::class, 'costReport']);
    Route::get('cost/{provider}', [AnalyticsEventController::class, 'costProvider']);

    // Notification Webhooks (v4.4.0)
    Route::get('notifications/webhooks', [AnalyticsEventController::class, 'notificationWebhooks']);
    Route::get('notifications/stats', [AnalyticsEventController::class, 'notificationStats']);
    Route::post('notifications/test/{webhookName}', [AnalyticsEventController::class, 'notificationTest']);
    Route::post('notifications/send', [AnalyticsEventController::class, 'notificationSend']);

    // Config Audit API (v4.5.0)
    Route::get('config/audit', [AnalyticsEventController::class, 'configAudit']);
    Route::get('config/summary', [AnalyticsEventController::class, 'configSummary']);
    Route::post('config/snapshot', [AnalyticsEventController::class, 'configSnapshotSave']);
    Route::get('config/snapshot/{label}', [AnalyticsEventController::class, 'configSnapshotLoad']);
    Route::post('config/diff', [AnalyticsEventController::class, 'configDiff']);

    // Event Catalog Validation API (v4.5.0)
    Route::post('catalog/validate', [AnalyticsEventController::class, 'catalogValidate']);
    Route::get('catalog/stats', [AnalyticsEventController::class, 'catalogStats']);
    Route::get('catalog/suggest', [AnalyticsEventController::class, 'catalogSuggest']);

    // Analytics Data Service — Dashboard Queries (v5.0.0)
    Route::get('dashboard', [AnalyticsEventController::class, 'dashboardSummary']);
    Route::get('dashboard/dau', [AnalyticsEventController::class, 'dashboardDAU']);
    Route::get('dashboard/mau', [AnalyticsEventController::class, 'dashboardMAU']);
    Route::get('dashboard/stickiness', [AnalyticsEventController::class, 'dashboardStickiness']);
    Route::get('dashboard/revenue', [AnalyticsEventController::class, 'dashboardRevenue']);
    Route::get('dashboard/top-events', [AnalyticsEventController::class, 'dashboardTopEvents']);
    Route::get('dashboard/providers', [AnalyticsEventController::class, 'dashboardProviders']);
    Route::get('dashboard/funnel/{funnelName}', [AnalyticsEventController::class, 'dashboardFunnel']);

    // Event Taxonomy API (v5.0.0)
    Route::get('taxonomy/tags', [AnalyticsEventController::class, 'taxonomyTags']);
    Route::get('taxonomy/groups', [AnalyticsEventController::class, 'taxonomyGroups']);
    Route::get('taxonomy/summary', [AnalyticsEventController::class, 'taxonomySummary']);
    Route::post('taxonomy/classify', [AnalyticsEventController::class, 'taxonomyAutoClassify']);
    Route::get('taxonomy/event/{eventName}', [AnalyticsEventController::class, 'taxonomyEventTags']);
    Route::get('taxonomy/tag/{tagName}/events', [AnalyticsEventController::class, 'taxonomyTagEvents']);

    // Multi-Tenant Analytics (v5.0.0)
    Route::get('tenant/{tenantId}/stats', [AnalyticsEventController::class, 'tenantStats']);
    Route::get('tenant/{tenantId}/revenue', [AnalyticsEventController::class, 'tenantRevenue']);

    // Event Routing (v5.9.0)
    Route::get('routing', [AnalyticsEventController::class, 'routingSummary']);
    Route::get('routing/rules', [AnalyticsEventController::class, 'routingRules']);
    Route::post('routing/rules', [AnalyticsEventController::class, 'routingAddRule']);
    Route::delete('routing/rules/{pattern}', [AnalyticsEventController::class, 'routingRemoveRule']);
    Route::post('routing/match', [AnalyticsEventController::class, 'routingMatch']);
    Route::post('routing/test', [AnalyticsEventController::class, 'routingTest']);

    // Provider Health Monitor (v5.9.0)
    Route::get('provider-health', [AnalyticsEventController::class, 'providerHealth']);
    Route::get('provider-health/{provider}', [AnalyticsEventController::class, 'providerHealthDetail']);
    Route::post('provider-health/reset', [AnalyticsEventController::class, 'providerHealthReset']);

    // Config Export (v6.5.0)
    Route::get('config/export', [AnalyticsEventController::class, 'configExport']);
    Route::get('config/status', [AnalyticsEventController::class, 'configStatus']);
    Route::get('config/section/{section}', [AnalyticsEventController::class, 'configSection']);

    // Event Data Mart (v7.0.0)
    Route::get('data-mart/summary', [AnalyticsEventController::class, 'dataMartSummary']);
    Route::get('data-mart/top/{dimension}', [AnalyticsEventController::class, 'dataMartTop']);
    Route::get('data-mart/by-category', [AnalyticsEventController::class, 'dataMartByCategory']);
    Route::get('data-mart/by-event', [AnalyticsEventController::class, 'dataMartByEvent']);
    Route::get('data-mart/by-provider', [AnalyticsEventController::class, 'dataMartByProvider']);
    Route::get('data-mart/export', [AnalyticsEventController::class, 'dataMartExport']);
    Route::get('data-mart/compare', [AnalyticsEventController::class, 'dataMartCompare']);
    Route::delete('data-mart', [AnalyticsEventController::class, 'dataMartClear']);

    // Insight Engine (v7.0.0)
    Route::get('insights', [AnalyticsEventController::class, 'insightReport']);
    Route::get('insights/latest', [AnalyticsEventController::class, 'insightLatest']);
    Route::get('insights/health', [AnalyticsEventController::class, 'insightHealth']);
    Route::get('insights/severity/{severity}', [AnalyticsEventController::class, 'insightBySeverity']);

    // Event Recommendations & Provider Gap Analysis (v7.1.0)
    Route::get('recommendations', [AnalyticsEventController::class, 'eventRecommendations']);
    Route::get('recommendations/top', [AnalyticsEventController::class, 'topEventRecommendations']);
    Route::get('recommendations/aarrr', [AnalyticsEventController::class, 'aarrrBreakdown']);
    Route::get('recommendations/tiers', [AnalyticsEventController::class, 'recommendationTiers']);
    Route::get('provider-gaps', [AnalyticsEventController::class, 'providerGapAnalysis']);
    Route::get('provider-gaps/{provider}', [AnalyticsEventController::class, 'providerGapDetail']);

    // Event Sparkline (v7.2.0)
    Route::get('sparkline/{eventName}', [AnalyticsEventController::class, 'eventSparkline']);
    Route::get('sparklines', [AnalyticsEventController::class, 'eventSparklines']);
    Route::get('sparkline/dashboard', [AnalyticsEventController::class, 'sparklineDashboard']);
    Route::get('sparkline/categories', [AnalyticsEventController::class, 'sparklineCategories']);

    // Event Co-occurrence Matrix (v7.2.0)
    Route::get('cooccurrence/matrix', [AnalyticsEventController::class, 'cooccurrenceMatrix']);
    Route::get('cooccurrence/top', [AnalyticsEventController::class, 'cooccurrenceTopPairs']);
    Route::get('cooccurrence/{eventName}', [AnalyticsEventController::class, 'cooccurrenceWith']);
    Route::get('cooccurrence/dashboard', [AnalyticsEventController::class, 'cooccurrenceDashboard']);

    // Cohort Waterfall Analysis (v7.5.0)
    Route::post('cohort-waterfall', [AnalyticsEventController::class, 'cohortWaterfall']);
    Route::post('cohort-waterfall/summary', [AnalyticsEventController::class, 'cohortWaterfallSummary']);
    Route::post('cohort-waterfall/compare', [AnalyticsEventController::class, 'cohortWaterfallCompare']);
    Route::get('cohort-waterfall/stages', [AnalyticsEventController::class, 'cohortWaterfallStages']);

    // Funnel Drop-off Intelligence (v7.5.0)
    Route::post('funnel-intelligence', [AnalyticsEventController::class, 'funnelIntelligence']);
    Route::post('funnel-intelligence/compare', [AnalyticsEventController::class, 'funnelIntelligenceCompare']);

    // Event Signal Intelligence (v7.7.0)
    Route::get('signal', [AnalyticsEventController::class, 'signalIntelligenceReport']);
    Route::get('signal/score', [AnalyticsEventController::class, 'signalIntelligenceScore']);
    Route::get('signal/anomalies', [AnalyticsEventController::class, 'signalIntelligenceAnomalies']);
    Route::get('signal/providers', [AnalyticsEventController::class, 'signalIntelligenceProviders']);
    Route::get('signal/categories', [AnalyticsEventController::class, 'signalIntelligenceCategories']);
    Route::get('signal/staleness', [AnalyticsEventController::class, 'signalIntelligenceStaleness']);
    Route::get('signal/signal-to-noise', [AnalyticsEventController::class, 'signalIntelligenceSignalToNoise']);
    Route::get('signal/dispatch-balance', [AnalyticsEventController::class, 'signalIntelligenceDispatchBalance']);

    // Attribution Modeling (v7.9.0)
    Route::get('attribution/models', [AnalyticsEventController::class, 'attributionModels']);
    Route::post('attribution/attribute', [AnalyticsEventController::class, 'attributionAttribute']);
    Route::post('attribution/compare', [AnalyticsEventController::class, 'attributionCompare']);
    Route::post('attribution/by-channel', [AnalyticsEventController::class, 'attributionByChannel']);
    Route::post('attribution/by-campaign', [AnalyticsEventController::class, 'attributionByCampaign']);
    Route::post('attribution/efficiency', [AnalyticsEventController::class, 'attributionEfficiency']);

    // SaaS Feature Matrix (v7.9.0)
    Route::get('feature-matrix', [AnalyticsEventController::class, 'featureMatrix']);
    Route::get('feature-matrix/summary', [AnalyticsEventController::class, 'featureMatrixSummary']);
    Route::get('feature-matrix/gaps', [AnalyticsEventController::class, 'featureMatrixGaps']);
    Route::get('feature-matrix/compare/{competitor}', [AnalyticsEventController::class, 'featureMatrixCompare']);

    // Event Sessionizer (v8.0.0)
    Route::get('sessions/{clientId}', [AnalyticsEventController::class, 'sessionizerClientSessions']);
    Route::get('sessions/{clientId}/{sessionId}', [AnalyticsEventController::class, 'sessionizerGetSession']);
    Route::get('sessions/{clientId}/stats', [AnalyticsEventController::class, 'sessionizerAggregateStats']);
    Route::post('sessions/end/{clientId}/{sessionId}', [AnalyticsEventController::class, 'sessionizerEndSession']);

    // Funnel Aggregation (v8.0.0)
    Route::get('funnels/aggregated/{funnelName}', [AnalyticsEventController::class, 'funnelAggregatedReport']);
    Route::get('funnels/aggregated', [AnalyticsEventController::class, 'funnelAllAggregatedReports']);
    Route::get('funnels/definitions', [AnalyticsEventController::class, 'funnelDefinitions']);

    // Cohort Intelligence (v8.1.0)
    Route::post('cohort-intelligence/profile', [AnalyticsEventController::class, 'cohortIntelligenceProfile']);
    Route::post('cohort-intelligence/profile/batch', [AnalyticsEventController::class, 'cohortIntelligenceProfileBatch']);
    Route::post('cohort-intelligence/distribution', [AnalyticsEventController::class, 'cohortIntelligenceDistribution']);
    Route::post('cohort-intelligence/transitions', [AnalyticsEventController::class, 'cohortIntelligenceTransitions']);
    Route::post('cohort-intelligence/predict', [AnalyticsEventController::class, 'cohortIntelligencePredict']);
    Route::post('cohort-intelligence/score', [AnalyticsEventController::class, 'cohortIntelligenceScore']);
    Route::post('cohort-intelligence/score/batch', [AnalyticsEventController::class, 'cohortIntelligenceScoreBatch']);
    Route::post('cohort-intelligence/summary', [AnalyticsEventController::class, 'cohortIntelligenceSummary']);
    Route::post('cohort-intelligence/churn-top', [AnalyticsEventController::class, 'cohortIntelligenceChurnTop']);
    Route::post('cohort-intelligence/expansion-top', [AnalyticsEventController::class, 'cohortIntelligenceExpansionTop']);
    Route::get('cohort-intelligence/insights/{cohort}', [AnalyticsEventController::class, 'cohortIntelligenceInsights']);

    // Dashboard Widgets (v8.3.0)
    Route::get('dashboard/widgets', [AnalyticsEventController::class, 'dashboardWidgets']);
    Route::get('dashboard/widgets/{widgetName}', [AnalyticsEventController::class, 'dashboardWidgets']);
    Route::post('dashboard/widgets/invalidate', [AnalyticsEventController::class, 'dashboardWidgetsInvalidate']);

    // Identity Graph — Cross-Device Identity Resolution (v8.7.0)
    Route::get('identity-graph/user/{userId}', [AnalyticsEventController::class, 'identityGraphGet']);
    Route::post('identity-graph/link', [AnalyticsEventController::class, 'identityGraphLink']);
    Route::post('identity-graph/infer', [AnalyticsEventController::class, 'identityGraphInfer']);
    Route::post('identity-graph/merge', [AnalyticsEventController::class, 'identityGraphMerge']);
    Route::post('identity-graph/same-user', [AnalyticsEventController::class, 'identityGraphSameUser']);
    Route::get('identity-graph/fingerprint', [AnalyticsEventController::class, 'identityGraphFingerprint']);

    // Event Delivery Confirmation (v9.0.0)
    Route::get('delivery', [AnalyticsEventController::class, 'deliveryDashboard']);
    Route::get('delivery/score', [AnalyticsEventController::class, 'deliveryReliabilityScore']);
    Route::get('delivery/receipt/{eventId}', [AnalyticsEventController::class, 'deliveryCheckReceipt']);
    Route::get('delivery/{provider}/response-times', [AnalyticsEventController::class, 'deliveryResponseTimes']);
    Route::get('delivery/{provider}/recent', [AnalyticsEventController::class, 'deliveryRecentDeliveries']);
    Route::get('delivery/{provider}/outage', [AnalyticsEventController::class, 'deliveryOutageStatus']);
    Route::delete('delivery', [AnalyticsEventController::class, 'deliveryClearStats']);

    // Tracking Guard Rails (v9.1.0)
    Route::post('guard-rails/check', [AnalyticsEventController::class, 'guardRailsCheck']);
    Route::get('guard-rails/score', [AnalyticsEventController::class, 'guardRailsScore']);
    Route::get('guard-rails/violations', [AnalyticsEventController::class, 'guardRailsViolations']);
    Route::get('guard-rails/coverage', [AnalyticsEventController::class, 'guardRailsCoverage']);
    Route::post('guard-rails/validate-name', [AnalyticsEventController::class, 'guardRailsValidateName']);

    // Server-Sent Events (v9.1.0)
    Route::get('sse', [AnalyticsSSEController::class, 'stream']);
    Route::get('sse/info', [AnalyticsSSEController::class, 'info']);
    Route::get('sse/health', [AnalyticsSSEController::class, 'health']);

    // Event Idempotency (v9.3.0)
    Route::get('idempotency', [AnalyticsEventController::class, 'idempotencyStats']);
    Route::post('idempotency/invalidate', [AnalyticsEventController::class, 'idempotencyInvalidate']);
    Route::post('idempotency/reset-stats', [AnalyticsEventController::class, 'idempotencyResetStats']);

    // Privacy Manifest — GDPR Article 30 (v9.3.0)
    Route::get('privacy-manifest', [AnalyticsEventController::class, 'privacyManifest']);
    Route::get('privacy-manifest/summary', [AnalyticsEventController::class, 'privacyManifestSummary']);
    Route::get('privacy-manifest/classify/{eventName}', [AnalyticsEventController::class, 'privacyManifestClassify']);
    Route::post('privacy-manifest/invalidate', [AnalyticsEventController::class, 'privacyManifestInvalidate']);

    // Event Annotations (v9.3.0)
    Route::get('annotations/stats', [AnalyticsEventController::class, 'annotationStats']);
    Route::post('annotations', [AnalyticsEventController::class, 'annotateEvent']);
    Route::post('annotations/auto-attach', [AnalyticsEventController::class, 'autoAttachAnnotations']);
    Route::get('annotations/{eventId}', [AnalyticsEventController::class, 'getEventAnnotations']);
    Route::delete('annotations/{eventId}', [AnalyticsEventController::class, 'clearEventAnnotations']);
    Route::delete('annotations/{eventId}/{key}', [AnalyticsEventController::class, 'removeEventAnnotation']);

    // Provider Fallback Strategy (v9.4.0)
    Route::get('fallback', [AnalyticsEventController::class, 'fallbackStats']);
    Route::get('fallback/chains', [AnalyticsEventController::class, 'fallbackChains']);
    Route::get('fallback/validate', [AnalyticsEventController::class, 'fallbackValidate']);
    Route::get('fallback/health', [AnalyticsEventController::class, 'fallbackHealth']);
    Route::post('fallback/reset-counts', [AnalyticsEventController::class, 'fallbackResetCounts']);

    // B2B Group/Account Analytics (v9.5.0)
    Route::post('group/identify', [AnalyticsEventController::class, 'groupIdentify']);
    Route::post('group/members/add', [AnalyticsEventController::class, 'groupAddMember']);
    Route::delete('group/members/remove', [AnalyticsEventController::class, 'groupRemoveMember']);
    Route::get('group/{groupId}', [AnalyticsEventController::class, 'groupGet']);
    Route::get('group/{groupId}/members', [AnalyticsEventController::class, 'groupMembers']);
    Route::post('group/{groupId}/traits', [AnalyticsEventController::class, 'groupUpdateTraits']);
    Route::delete('group/{groupId}', [AnalyticsEventController::class, 'groupForget']);

    // Cross-Domain Tracking (v9.8.0)
    Route::get('cross-domain', [AnalyticsEventController::class, 'crossDomainStatus']);
    Route::post('cross-domain/link', [AnalyticsEventController::class, 'crossDomainLink']);
    Route::get('cross-domain/links/{clientId}', [AnalyticsEventController::class, 'crossDomainLinks']);
    Route::get('cross-domain/resolve/{clientId}', [AnalyticsEventController::class, 'crossDomainResolve']);
    Route::delete('cross-domain/{clientId}', [AnalyticsEventController::class, 'crossDomainClear']);

    // Session Recording Bridge (v9.8.0)
    Route::get('session-recording', [AnalyticsEventController::class, 'sessionRecordingStatus']);
    Route::get('session-recording/config', [AnalyticsEventController::class, 'sessionRecordingConfig']);

    // Schema Export (v9.8.0)
    Route::get('schemas/export/json', [AnalyticsEventController::class, 'schemaExportJson']);
    Route::get('schemas/export/typescript', [AnalyticsEventController::class, 'schemaExportTypeScript']);
    Route::get('schemas/export/openapi', [AnalyticsEventController::class, 'schemaExportOpenApi']);

    // API Rate Limiter (v9.8.0)
    Route::get('rate-limits/advanced', [AnalyticsEventController::class, 'advancedRateLimitStatus']);
    Route::get('rate-limits/advanced/{clientId}', [AnalyticsEventController::class, 'advancedRateLimitClient']);

    // Observability (v18.0.0)
    Route::get('observability/filters', [AnalyticsEventController::class, 'observabilityFilterMetrics']);
    Route::get('observability/{provider}/events', [AnalyticsEventController::class, 'observabilityEventMetrics']);
    Route::get('observability/{provider}/timeline', [AnalyticsEventController::class, 'observabilityDispatchTimeline']);
    Route::get('observability/{provider}', [AnalyticsEventController::class, 'observabilityProviderMetrics']);
    Route::get('observability', [AnalyticsEventController::class, 'observabilityDashboard']);
    Route::delete('observability/{provider}', [AnalyticsEventController::class, 'observabilityResetProvider']);
    Route::delete('observability', [AnalyticsEventController::class, 'observabilityResetAll']);

    // Event Store — Persistent Event Storage (v30.0.0)
    Route::get('store/health', [AnalyticsEventController::class, 'eventStoreHealth']);
    Route::get('store/stats', [AnalyticsEventController::class, 'eventStoreStats']);
    Route::get('store/events', [AnalyticsEventController::class, 'eventStoreQuery']);
    Route::get('store/events/{id}', [AnalyticsEventController::class, 'eventStoreRetrieve']);
    Route::get('store/count', [AnalyticsEventController::class, 'eventStoreCount']);
    Route::get('store/aggregate/{groupBy}', [AnalyticsEventController::class, 'eventStoreAggregate']);
    Route::delete('store/events', [AnalyticsEventController::class, 'eventStoreDelete']);
    Route::delete('store/events/{id}', [AnalyticsEventController::class, 'eventStoreDeleteById']);
    Route::delete('store', [AnalyticsEventController::class, 'eventStorePurge']);

    // Event Ingestion Pipeline (v36.0.0)
    Route::get('ingestion/metrics', [AnalyticsEventController::class, 'ingestionMetrics']);
    Route::get('ingestion/stats', [AnalyticsEventController::class, 'ingestionAggregatedStats']);
    Route::get('ingestion/health', [AnalyticsEventController::class, 'ingestionHealth']);

    // Event Cost Allocation (v36.0.0)
    Route::get('cost-allocation/daily', [AnalyticsEventController::class, 'costAllocationDaily']);
    Route::get('cost-allocation/monthly', [AnalyticsEventController::class, 'costAllocationMonthly']);
    Route::get('cost-allocation/events', [AnalyticsEventController::class, 'costAllocationTopEvents']);
    Route::get('cost-allocation/tenant/{tenantId}', [AnalyticsEventController::class, 'costAllocationTenant']);
    Route::get('cost-allocation/budget', [AnalyticsEventController::class, 'costAllocationBudget']);

    // Analytics Command Scheduler (v36.0.0)
    Route::get('scheduler/status', [AnalyticsEventController::class, 'schedulerStatus']);
    Route::get('scheduler/tasks', [AnalyticsEventController::class, 'schedulerTasks']);
    Route::get('scheduler/due', [AnalyticsEventController::class, 'schedulerDueTasks']);
    Route::get('scheduler/log', [AnalyticsEventController::class, 'schedulerExecutionLog']);
    Route::post('scheduler/execute', [AnalyticsEventController::class, 'schedulerExecuteDue']);
    Route::post('scheduler/execute/{taskName}', [AnalyticsEventController::class, 'schedulerExecuteTask']);
    Route::post('scheduler/toggle/{taskName}', [AnalyticsEventController::class, 'schedulerToggleTask']);
    Route::post('scheduler/register', [AnalyticsEventController::class, 'schedulerRegisterTask']);
    Route::delete('scheduler/{taskName}', [AnalyticsEventController::class, 'schedulerRemoveTask']);

    // Event TTL & Auto-Expiry (v43.0.0)
    Route::get('ttl/metrics', [AnalyticsEventController::class, 'ttlMetrics']);
    Route::get('ttl/config', [AnalyticsEventController::class, 'ttlConfig']);
    Route::delete('ttl/metrics', [AnalyticsEventController::class, 'ttlResetMetrics']);
    Route::post('ttl/check', [AnalyticsEventController::class, 'ttlCheckEvent']);

    // Referral & Viral Loop Tracking (v43.0.0)
    Route::post('referral/generate-code', [AnalyticsEventController::class, 'referralGenerateCode']);
    Route::get('referral/resolve/{code}', [AnalyticsEventController::class, 'referralResolveCode']);
    Route::post('referral/click', [AnalyticsEventController::class, 'referralTrackClick']);
    Route::post('referral/convert', [AnalyticsEventController::class, 'referralTrackConversion']);
    Route::get('referral/health', [AnalyticsEventController::class, 'referralHealth']);
    Route::get('referral/viral', [AnalyticsEventController::class, 'referralViralCoefficient']);
    Route::get('referral/funnel', [AnalyticsEventController::class, 'referralFunnel']);
    Route::get('referral/top-referrers', [AnalyticsEventController::class, 'referralTopReferrers']);

    // Traffic Spike Shield (v43.0.0)
    Route::get('spike-shield/status', [AnalyticsEventController::class, 'spikeShieldStatus']);
    Route::get('spike-shield/config', [AnalyticsEventController::class, 'spikeShieldConfig']);
    Route::post('spike-shield/cooldown', [AnalyticsEventController::class, 'spikeShieldTriggerCooldown']);
    Route::delete('spike-shield/cooldown', [AnalyticsEventController::class, 'spikeShieldClearCooldown']);
    Route::delete('spike-shield/metrics', [AnalyticsEventController::class, 'spikeShieldResetMetrics']);

    // Event Replay Simulator (v43.0.0)
    Route::get('simulator/config', [AnalyticsEventController::class, 'simulatorConfig']);
    Route::get('simulator/mix', [AnalyticsEventController::class, 'simulatorMix']);
    Route::post('simulator/generate', [AnalyticsEventController::class, 'simulatorGenerate']);
    Route::post('simulator/ecommerce', [AnalyticsEventController::class, 'simulatorEcommerce']);
    Route::post('simulator/saas', [AnalyticsEventController::class, 'simulatorSaaSLifecycle']);

    // Event Lineage Tracker (v49.0.0)
    Route::get('lineage/status', [AnalyticsEventController::class, 'lineageStatus']);
    Route::get('lineage/stats', [AnalyticsEventController::class, 'lineageStats']);
    Route::get('lineage/{lineageId}', [AnalyticsEventController::class, 'lineageShow']);
    Route::get('lineage', [AnalyticsEventController::class, 'lineageList']);
    Route::get('lineage/failures', [AnalyticsEventController::class, 'lineageFailures']);
    Route::get('lineage/stages/performance', [AnalyticsEventController::class, 'lineageStagePerformance']);
    Route::get('lineage/providers/reliability', [AnalyticsEventController::class, 'lineageProviderReliability']);
    Route::get('lineage/export', [AnalyticsEventController::class, 'lineageExportCompliance']);
    Route::delete('lineage', [AnalyticsEventController::class, 'lineagePurge']);

    // Anomaly Detection & Alerting (v54.0.0)
    Route::get('anomaly/status', [AnalyticsEventController::class, 'anomalyStatus']);
    Route::get('anomaly/metrics', [AnalyticsEventController::class, 'anomalyMetrics']);
    Route::get('anomaly/check', [AnalyticsEventController::class, 'anomalyCheck']);
    Route::get('anomaly/alerts', [AnalyticsEventController::class, 'anomalyAlerts']);
    Route::delete('anomaly', [AnalyticsEventController::class, 'anomalyClear']);

    // Multi-Provider Event Relay (v54.0.0)
    Route::get('relay/status', [AnalyticsEventController::class, 'relayStatus']);
    Route::get('relay/metrics', [AnalyticsEventController::class, 'relayMetrics']);

    // Export Formatting (v54.0.0)
    Route::get('export/formats', [AnalyticsEventController::class, 'exportFormats']);
    Route::post('export/transform', [AnalyticsEventController::class, 'exportTransform']);

    // Analytics Data Explorer (v60.0.0)
    Route::get('explorer/health', [AnalyticsEventController::class, 'explorerHealth']);
    Route::get('explorer/explore', [AnalyticsEventController::class, 'explorerExplore']);
    Route::get('explorer/top-events', [AnalyticsEventController::class, 'explorerTopEvents']);
    Route::get('explorer/drill-down/{eventName}', [AnalyticsEventController::class, 'explorerDrillDown']);
    Route::get('explorer/compare', [AnalyticsEventController::class, 'explorerCompare']);
    Route::get('explorer/funnel', [AnalyticsEventController::class, 'explorerFunnel']);

    // Event Correlation Analyzer — Time-Lagged (v60.0.0)
    Route::get('correlation-analyzer/health', [AnalyticsEventController::class, 'correlationAnalyzerHealth']);
    Route::get('correlation-analyzer/cross-correlation', [AnalyticsEventController::class, 'correlationAnalyzerCrossCorrelation']);
    Route::get('correlation-analyzer/transition', [AnalyticsEventController::class, 'correlationAnalyzerTransition']);
    Route::get('correlation-analyzer/matrix', [AnalyticsEventController::class, 'correlationAnalyzerMatrix']);

    // Product-Market Fit Scoring (v61.0.0)
    Route::post('pmf/score', [AnalyticsEventController::class, 'pmfScore']);
    Route::get('pmf/summary', [AnalyticsEventController::class, 'pmfSummary']);

    // First-Value Detection (v61.0.0)
    Route::get('first-value/score/{userId}', [AnalyticsEventController::class, 'firstValueScore']);
    Route::post('first-value/reset/{userId}', [AnalyticsEventController::class, 'firstValueReset']);

    // Event Session Context (v63.0.0)
    Route::get('session-context/stats', [AnalyticsEventController::class, 'sessionContextStats']);
    Route::post('session-context/build', [AnalyticsEventController::class, 'sessionContextBuild']);

    // Provider Dispatch Deduplication (v63.0.0)
    Route::get('dispatch-dedup/stats', [AnalyticsEventController::class, 'dispatchDedupStats']);
    Route::post('dispatch-dedup/check', [AnalyticsEventController::class, 'dispatchDedupCheck']);
    Route::post('dispatch-dedup/clear', [AnalyticsEventController::class, 'dispatchDedupClear']);

    // Event Validation Pipeline (v69.0.0)
    Route::get('pipeline/validate/status', [AnalyticsEventController::class, 'pipelineValidateStatus']);
    Route::get('pipeline/validate/stages', [AnalyticsEventController::class, 'pipelineValidateStages']);
    Route::post('pipeline/validate/event', [AnalyticsEventController::class, 'pipelineValidateEvent']);
    Route::post('pipeline/validate/batch', [AnalyticsEventController::class, 'pipelineValidateBatch']);

    // Event Payload Transformation Engine (v70.0.0)
    Route::get('transform/mappings', [AnalyticsEventController::class, 'transformMappings']);
    Route::get('transform/mappings/event/{eventName}', [AnalyticsEventController::class, 'transformMappingsByEvent']);
    Route::get('transform/mappings/provider/{provider}', [AnalyticsEventController::class, 'transformMappingsByProvider']);
    Route::post('transform/preview', [AnalyticsEventController::class, 'transformPreview']);
    Route::post('transform/validate', [AnalyticsEventController::class, 'transformValidate']);

    // Event Audit Trail (v72.0.0)
    Route::get('audit-trail', [AnalyticsEventController::class, 'auditTrailRecent']);
    Route::get('audit-trail/{auditId}', [AnalyticsEventController::class, 'auditTrailGet']);
    Route::get('audit-trail/search/{eventName}', [AnalyticsEventController::class, 'auditTrailSearch']);
    Route::get('audit-trail/stats/{period}', [AnalyticsEventController::class, 'auditTrailStats']);
    Route::get('audit-trail/summary', [AnalyticsEventController::class, 'auditTrailSummary']);
    Route::delete('audit-trail', [AnalyticsEventController::class, 'auditTrailClear']);
    Route::delete('audit-trail/client/{clientId}', [AnalyticsEventController::class, 'auditTrailEraseClient']);
    Route::delete('audit-trail/user/{userId}', [AnalyticsEventController::class, 'auditTrailEraseUser']);

    // Event Attribution Trail (v72.0.0)
    Route::get('attribution-trail/{clientId}', [AnalyticsEventController::class, 'attributionTrailGet']);
    Route::get('attribution-trail/{clientId}/first-touch', [AnalyticsEventController::class, 'attributionTrailFirstTouch']);
    Route::get('attribution-trail/{clientId}/last-touch', [AnalyticsEventController::class, 'attributionTrailLastTouch']);
    Route::get('attribution-trail/{clientId}/attribute', [AnalyticsEventController::class, 'attributionTrailAttribute']);
    Route::get('attribution-trail/stats', [AnalyticsEventController::class, 'attributionTrailStats']);
    Route::delete('attribution-trail/{clientId}', [AnalyticsEventController::class, 'attributionTrailErase']);

    // Experiment Analysis Engine (v75.0.0)
    Route::post('experiments/analyze', [AnalyticsEventController::class, 'experimentAnalyze']);
    Route::post('experiments/quick-significance', [AnalyticsEventController::class, 'experimentQuickSignificance']);
    Route::post('experiments/sample-size', [AnalyticsEventController::class, 'experimentSampleSize']);
    Route::post('experiments/mde', [AnalyticsEventController::class, 'experimentMDE']);
    Route::post('experiments/sequential', [AnalyticsEventController::class, 'experimentSequential']);
    Route::post('experiments/health', [AnalyticsEventController::class, 'experimentHealth']);
    Route::get('experiments/{experimentId}', [AnalyticsEventController::class, 'experimentGetAnalysis']);
    Route::delete('experiments/{experimentId}', [AnalyticsEventController::class, 'experimentClearAnalysis']);
});
