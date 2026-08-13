<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

/**
 * Unified health endpoint service.
 *
 * Aggregates health data from all registered analytics subsystems into a
 * single composite health check. Designed for `/api/analytics/health`
 * endpoints, Kubernetes liveness/readiness probes, and monitoring dashboards.
 *
 * Subsystems checked:
 * - Provider connectivity (GA4, GTM, Meta, PostHog, Plausible, Mixpanel, Amplitude)
 * - Queue health (connection, pending jobs)
 * - Consent mode status
 * - Event pipeline status
 * - Data quality firewall metrics
 * - Fraud detection metrics
 * - PMF scoring cache status
 * - Circuit breaker states
 * - Provider rate limit status
 * - Sampling metrics
 * - Event archive health
 * - Cache driver connectivity
 *
 * Returns a structured report with overall status (healthy/warning/critical),
 * subsystem scores, and actionable recommendations.
 *
 * Inspired by AWS Health Check patterns and Stripe's status page API.
 *
 * @since 47.0.0
 */
final class UnifiedHealthEndpointService
{
    /**
     * @param  AnalyticsHealthService  $healthService  Core health service
     * @param  AnalyticsHealthCheckService  $healthCheck  Extended health check service
     * @param  AnalyticsHealthMonitorService  $healthMonitor  Continuous health monitor
     * @param  AnalyticsDataQualityFirewall|null  $qualityFirewall  Data quality firewall (optional)
     * @param  EventFraudDetectionService|null  $fraudDetection  Fraud detection service (optional)
     * @param  ProductMarketFitScoringService|null  $pmfScoring  PMF scoring service (optional)
     */
    public function __construct(
        private readonly AnalyticsHealthService $healthService,
        private readonly AnalyticsHealthCheckService $healthCheck,
        private readonly AnalyticsHealthMonitorService $healthMonitor,
        private readonly ?AnalyticsDataQualityFirewall $qualityFirewall = null,
        private readonly ?EventFraudDetectionService $fraudDetection = null,
        private readonly ?ProductMarketFitScoringService $pmfScoring = null,
    ) {}

    /**
     * Run a full unified health check.
     *
     * @return array{status: string, score: int, timestamp: string, subsystems: array<string, array{status: string, score: int, details: array<string, mixed>}>, warnings: list<string>, recommendations: list<string>, version: string}
     */
    public function check(): array
    {
        $subsystems = [];
        $warnings = [];
        $recommendations = [];

        // Core health service
        $coreHealth = $this->healthService->getHealthReport();
        $subsystems['core'] = [
            'status' => ($coreHealth['status'] ?? 'healthy') === 'healthy' ? 'healthy' : 'warning',
            'score' => $this->extractScore($coreHealth),
            'details' => $coreHealth,
        ];

        // Extended health check
        try {
            $extendedHealth = $this->healthCheck->check();
            $subsystems['extended'] = [
                'status' => ($extendedHealth['status'] ?? 'healthy') === 'healthy' ? 'healthy' : 'warning',
                'score' => $this->extractScore($extendedHealth),
                'details' => $extendedHealth,
            ];
        } catch (\Throwable) {
            $subsystems['extended'] = [
                'status' => 'unknown',
                'score' => 0,
                'details' => ['error' => 'Extended health check failed'],
            ];
            $warnings[] = 'Extended health check service threw an exception';
        }

        // Continuous health monitor
        try {
            $monitorData = $this->healthMonitor->getStatus();
            $subsystems['monitor'] = [
                'status' => ($monitorData['status'] ?? 'healthy') === 'healthy' ? 'healthy' : 'warning',
                'score' => $this->extractScore($monitorData),
                'details' => $monitorData,
            ];
        } catch (\Throwable) {
            $subsystems['monitor'] = [
                'status' => 'unknown',
                'score' => 0,
                'details' => ['error' => 'Health monitor service threw an exception'],
            ];
        }

        // Data Quality Firewall
        if ($this->qualityFirewall !== null) {
            try {
                $qualityMetrics = $this->qualityFirewall->getMetrics();
                $qualityStatus = ($qualityMetrics['pass_rate'] ?? 1.0) >= 0.9 ? 'healthy' : 'warning';
                $subsystems['quality_firewall'] = [
                    'status' => $qualityStatus,
                    'score' => (int) round(($qualityMetrics['pass_rate'] ?? 0) * 100),
                    'details' => $qualityMetrics,
                ];
            } catch (\Throwable) {
                $subsystems['quality_firewall'] = [
                    'status' => 'unknown',
                    'score' => 0,
                    'details' => ['error' => 'Quality firewall check failed'],
                ];
            }
        }

        // Fraud Detection
        if ($this->fraudDetection !== null) {
            try {
                $fraudMetrics = $this->fraudDetection->getMetrics();
                $total = $fraudMetrics['total_evaluated'] ?? 0;
                $blockedRate = $total > 0
                    ? ($fraudMetrics['blocked'] ?? 0) / $total
                    : 0;
                $fraudStatus = $blockedRate < 0.05 ? 'healthy' : ($blockedRate < 0.20 ? 'warning' : 'critical');
                $subsystems['fraud_detection'] = [
                    'status' => $fraudStatus,
                    'score' => (int) round((1.0 - $blockedRate) * 100),
                    'details' => $fraudMetrics,
                ];
            } catch (\Throwable) {
                $subsystems['fraud_detection'] = [
                    'status' => 'unknown',
                    'score' => 0,
                    'details' => ['error' => 'Fraud detection check failed'],
                ];
            }
        }

        // PMF Scoring
        if ($this->pmfScoring !== null) {
            try {
                $cachedScore = $this->pmfScoring->getCachedScore();
                $subsystems['pmf_scoring'] = [
                    'status' => $cachedScore !== null ? 'healthy' : 'warning',
                    'score' => $cachedScore !== null ? $cachedScore['score'] : 0,
                    'details' => $cachedScore ?? ['message' => 'No cached PMF score available'],
                ];
            } catch (\Throwable) {
                $subsystems['pmf_scoring'] = [
                    'status' => 'unknown',
                    'score' => 0,
                    'details' => ['error' => 'PMF scoring check failed'],
                ];
            }
        }

        // Compute overall status
        $scores = array_column($subsystems, 'score');
        $averageScore = count($scores) > 0 ? (int) round(array_sum($scores) / count($scores)) : 0;

        $hasCritical = in_array('critical', array_column($subsystems, 'status'), true);
        $hasWarning = in_array('warning', array_column($subsystems, 'status'), true);

        $overallStatus = $hasCritical ? 'critical' : ($hasWarning ? 'warning' : 'healthy');

        // Collect warnings from core health
        if (isset($coreHealth['warnings']) && is_array($coreHealth['warnings'])) {
            foreach ($coreHealth['warnings'] as $w) {
                $warnings[] = (string) $w;
            }
        }

        // Collect recommendations from core health
        if (isset($coreHealth['recommendations']) && is_array($coreHealth['recommendations'])) {
            foreach ($coreHealth['recommendations'] as $r) {
                $recommendations[] = (string) $r;
            }
        }

        return [
            'status' => $overallStatus,
            'score' => $averageScore,
            'timestamp' => now()->toIso8601String(),
            'subsystems' => $subsystems,
            'warnings' => $warnings,
            'recommendations' => $recommendations,
            'version' => '52.0.0',
        ];
    }

    /**
     * Run a lightweight liveness probe.
     *
     * Only checks if the core health service is responsive.
     * Suitable for Kubernetes liveness probes with low overhead.
     *
     * @return array{status: string, timestamp: string}
     */
    public function liveness(): array
    {
        try {
            $report = $this->healthService->getHealthReport();
            $status = ($report['status'] ?? 'healthy') === 'healthy' ? 'healthy' : 'warning';
        } catch (\Throwable) {
            $status = 'critical';
        }

        return [
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Run a readiness probe.
     *
     * Checks if all critical subsystems are operational.
     * Suitable for Kubernetes readiness probes.
     *
     * @return array{ready: bool, status: string, timestamp: string, subsystems: array<string, string>}
     */
    public function readiness(): array
    {
        $subsystemStatuses = [];
        $allHealthy = true;

        // Core health check
        try {
            $report = $this->healthService->getHealthReport();
            $coreStatus = ($report['status'] ?? 'healthy') === 'healthy' ? 'healthy' : 'unhealthy';
            $subsystemStatuses['core'] = $coreStatus;
            if ($coreStatus !== 'healthy') {
                $allHealthy = false;
            }
        } catch (\Throwable) {
            $subsystemStatuses['core'] = 'unhealthy';
            $allHealthy = false;
        }

        // Queue check (from extended health)
        try {
            $extended = $this->healthCheck->check();
            $extStatus = ($extended['status'] ?? 'healthy') === 'healthy' ? 'healthy' : 'unhealthy';
            $subsystemStatuses['extended'] = $extStatus;
            if ($extStatus !== 'healthy') {
                $allHealthy = false;
            }
        } catch (\Throwable) {
            $subsystemStatuses['extended'] = 'unhealthy';
            $allHealthy = false;
        }

        return [
            'ready' => $allHealthy,
            'status' => $allHealthy ? 'ready' : 'not_ready',
            'timestamp' => now()->toIso8601String(),
            'subsystems' => $subsystemStatuses,
        ];
    }

    /**
     * Extract a numeric score from a health report array.
     *
     * @param  array<string, mixed>  $report  Health report data
     */
    private function extractScore(array $report): int
    {
        // Try common score keys
        if (isset($report['overall_score']) && is_int($report['overall_score'])) {
            return $report['overall_score'];
        }

        if (isset($report['score']) && is_int($report['score'])) {
            return $report['score'];
        }

        // Derive from status
        $status = $report['status'] ?? 'unknown';

        return match ($status) {
            'healthy', 'ok' => 100,
            'warning', 'degraded' => 70,
            'critical', 'error', 'unhealthy' => 30,
            default => 50,
        };
    }
}
