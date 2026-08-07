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

    // Reporting (public, for admin dashboards)
    Route::get('report', [AnalyticsEventController::class, 'report']);
    Route::get('report/summary', [AnalyticsEventController::class, 'reportSummary']);
    Route::get('report/top-events', [AnalyticsEventController::class, 'reportTopEvents']);
    Route::get('report/trending', [AnalyticsEventController::class, 'reportTrending']);
    Route::get('report/provider-stats', [AnalyticsEventController::class, 'reportProviderStats']);

    // Authenticated endpoints (require auth:sanctum middleware from route registration)
    Route::post('events', [AnalyticsEventController::class, 'track']);
    Route::post('batch', [AnalyticsEventController::class, 'batch']);
    Route::post('identify', [AnalyticsEventController::class, 'identify']);
    Route::post('pageview', [AnalyticsEventController::class, 'pageview']);
    Route::post('consent', [AnalyticsEventController::class, 'updateConsent']);
});
