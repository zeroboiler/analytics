<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Unified analytics dashboard data provider.
 *
 * Aggregates data from multiple analytics services into a single
 * structured response for dashboard rendering. Combines health scores,
 * KPI metrics, real-time stats, funnel data, and provider connectivity
 * into a single API call.
 *
 * Designed as the backend for admin dashboard widgets.
 *
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController
 *
 * @since 1.0.0
 */
final class AnalyticsDashboardDataProvider
{
    private AnalyticsManager $manager;

    private ?SaasKpiTracker $kpiTracker;

    private ?RealTimeAggregationService $realtimeService;

    private ?SaaSHealthScoreService $healthScoreService;

    private ?EventReportingService $reportingService;

    private ?AnalyticsStatsService $statsService;

    private ?AnalyticsHealthService $healthService;

    private ?EventAlertRulesService $alertRulesService;

    private ?FunnelDataBuilderService $funnelDataBuilder;

    private ?SaasFunnelService $funnelService;

    private ?AnalyticsSnapshotService $snapshotService;

    /**
     * @param  AnalyticsManager  $manager
     * @param  SaasKpiTracker|null  $kpiTracker
     * @param  RealTimeAggregationService|null  $realtimeService
     * @param  SaaSHealthScoreService|null  $healthScoreService
     * @param  EventReportingService|null  $reportingService
     * @param  AnalyticsStatsService|null  $statsService
     * @param  AnalyticsHealthService|null  $healthService
     * @param  EventAlertRulesService|null  $alertRulesService
     * @param  FunnelDataBuilderService|null  $funnelDataBuilder
     * @param  SaasFunnelService|null  $funnelService
     * @param  AnalyticsSnapshotService|null  $snapshotService
     */
    public function __construct(
        AnalyticsManager $manager,
        ?SaasKpiTracker $kpiTracker = null,
        ?RealTimeAggregationService $realtimeService = null,
        ?SaaSHealthScoreService $healthScoreService = null,
        ?EventReportingService $reportingService = null,
        ?AnalyticsStatsService $statsService = null,
        ?AnalyticsHealthService $healthService = null,
        ?EventAlertRulesService $alertRulesService = null,
        ?FunnelDataBuilderService $funnelDataBuilder = null,
        ?SaasFunnelService $funnelService = null,
        ?AnalyticsSnapshotService $snapshotService = null,
    ): void {
        $this->manager = $manager;
        $this->kpiTracker = $kpiTracker;
        $this->realtimeService = $realtimeService;
        $this->healthScoreService = $healthScoreService;
        $this->reportingService = $reportingService;
        $this->statsService = $statsService;
        $this->healthService = $healthService;
        $this->alertRulesService = $alertRulesService;
        $this->funnelDataBuilder = $funnelDataBuilder;
        $this->funnelService = $funnelService;
        $this->snapshotService = $snapshotService;
    }

    /**
     * Get the full dashboard overview.
     *
     * Returns a structured array suitable for JSON API response.
     * Includes version, provider status, event catalog summary,
     * KPI metrics, health score, real-time stats, and alerts.
     *
     * @return array{version: string, providers: array<string, bool>, catalog: array{total: int, ecommerce: int, saas: int, engagement: int}, kpi: array<string, mixed>|null, health_score: array<string, mixed>|null, realtime: array<string, mixed>|null, alerts: list<string>, metrics: array<string, mixed>}
     */
    public function overview(): array
    {
        $overview = [
            'version' => $this->manager->version(),
            'providers' => [
                'ga4' => $this->manager->ga4()->isEnabled(),
                'gtm' => $this->manager->gtm()->isEnabled(),
                'meta' => $this->manager->meta()->isEnabled(),
                'plausible' => $this->manager->plausible()->isEnabled(),
                'posthog' => $this->manager->posthog()->isEnabled(),
                'webhook' => $this->manager->webhook()->isEnabled(),
            ],
            'catalog' => EventCatalog::summary(),
            'kpi' => $this->kpiTracker?->getSummary(),
            'health_score' => $this->healthScoreService?->currentScore(),
            'realtime' => $this->realtimeService?->snapshot(),
            'alerts' => $this->alertRulesService?->getActiveAlerts() ?? [],
            'metrics' => [
                'dispatched' => $this->manager->metrics()->getDispatchedCount(),
                'failed' => $this->manager->metrics()->getFailedCount(),
                'filtered' => $this->manager->metrics()->getFilteredCount(),
                'providers' => $this->manager->metrics()->getProviderStats(),
            ],
        ];

        return $overview;
    }

    /**
     * Get a lightweight version of the dashboard for public display.
     *
     * Excludes sensitive KPI and health data.
     *
     * @return array{version: string, catalog: array{total: int, ecommerce: int, saas: int, engagement: int}, providers: array<string, bool>}
     */
    public function publicOverview(): array
    {
        return [
            'version' => $this->manager->version(),
            'catalog' => EventCatalog::summary(),
            'providers' => [
                'ga4' => $this->manager->ga4()->isEnabled(),
                'gtm' => $this->manager->gtm()->isEnabled(),
                'meta' => $this->manager->meta()->isEnabled(),
                'plausible' => $this->manager->plausible()->isEnabled(),
                'posthog' => $this->manager->posthog()->isEnabled(),
            ],
        ];
    }

    /**
     * Get the provider connectivity status section.
     *
     * @return array{enabled: array<string, bool>, counts: array<string, int>}
     */
    public function providerStatus(): array
    {
        return [
            'enabled' => [
                'ga4' => $this->manager->ga4()->isEnabled(),
                'gtm' => $this->manager->gtm()->isEnabled(),
                'meta' => $this->manager->meta()->isEnabled(),
                'plausible' => $this->manager->plausible()->isEnabled(),
                'posthog' => $this->manager->posthog()->isEnabled(),
                'webhook' => $this->manager->webhook()->isEnabled(),
            ],
            'counts' => [
                'total_enabled' => $this->countEnabledProviders(),
                'total_available' => 6,
            ],
        ];
    }

    /**
     * Get the SaaS KPI section only.
     *
     * @return array<string, mixed>|null
     */
    public function kpiSection(): ?array
    {
        return $this->kpiTracker?->getSummary();
    }

    /**
     * Get the health score section only.
     *
     * @return array<string, mixed>|null
     */
    public function healthSection(): ?array
    {
        return $this->healthScoreService?->currentScore();
    }

    /**
     * Get the real-time section only.
     *
     * @return array<string, mixed>|null
     */
    public function realtimeSection(): ?array
    {
        return $this->realtimeService?->snapshot();
    }

    /**
     * Count the number of enabled providers.
     */
    private function countEnabledProviders(): int
    {
        $count = 0;
        if ($this->manager->ga4()->isEnabled()) $count++;
        if ($this->manager->gtm()->isEnabled()) $count++;
        if ($this->manager->meta()->isEnabled()) $count++;
        if ($this->manager->plausible()->isEnabled()) $count++;
        if ($this->manager->posthog()->isEnabled()) $count++;
        if ($this->manager->webhook()->isEnabled()) $count++;

        return $count;
    }
}
