<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;

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
});
