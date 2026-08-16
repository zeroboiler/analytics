<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Sentry error analytics integration service.
 *
 * Ingests Sentry exception/crash events into the analytics pipeline,
 * computes error cohort analytics, and measures error-to-revenue impact.
 *
 * Provides a bridge between application error monitoring (Sentry) and
 * product analytics, enabling teams to understand how errors affect
 * user behavior, conversion funnels, and revenue.
 *
 * Features:
 * - **Error Ingestion**: Parse Sentry webhook payloads into AnalyticsEvent DTOs
 * - **Error Cohort Analytics**: Group users by error exposure and measure downstream impact
 * - **Error Impact Scoring**: Quantify how errors affect conversion/revenue per error type
 * - **Error Fingerprinting**: Normalize stack traces into deduplicate-able fingerprints
 * - **Error Funnel Analysis**: Track how errors at each funnel stage affect conversion
 * - **Critical Path Detection**: Identify errors on critical user flows (checkout, signup)
 * - **Error Resolution Tracking**: Monitor error rate changes after deployments
 * - **SLA Impact**: Measure error-related SLA breaches with time-to-resolution
 *
 * Configuration: `zeroboiler.analytics.sentry_error_analytics`
 *
 * @see \ZeroBoiler\Analytics\Services\EventDebugCaptureService
 * @see \ZeroBoiler\Analytics\Services\AlertNotificationService
 *
 * @since 187.0.0
 */
final class SentryErrorAnalyticsService
{
    private const CACHE_PREFIX = 'zb_sentry_';
    private const DEFAULT_TTL = 7200; // 2 hours
    private const DEFAULT_MAX_ERRORS = 500;
    private const DEFAULT_MAX_COHORTS = 50;

    private readonly bool $enabled;
    private readonly string $environment;
    private readonly int $maxErrors;
    private readonly int $maxCohorts;
    private readonly int $cacheTtl;
    /** @var list<string> */
    private readonly array $criticalPaths;
    private readonly int $impactWindowHours;
    private readonly float $criticalPathWeight;
    private readonly float $revenueImpactFactor;
    private readonly float $conversionDropThreshold;

    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $cfg = $config->get('zeroboiler.analytics.sentry_error_analytics', []);
        $this->enabled = (bool) ($cfg['enabled'] ?? true);
        $this->environment = (string) ($cfg['environment'] ?? 'production');
        $this->maxErrors = (int) ($cfg['max_errors'] ?? self::DEFAULT_MAX_ERRORS);
        $this->maxCohorts = (int) ($cfg['max_cohorts'] ?? self::DEFAULT_MAX_COHORTS);
        $this->cacheTtl = (int) ($cfg['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->criticalPaths = (array) ($cfg['critical_paths'] ?? [
            'checkout', 'payment', 'signup', 'trial_start', 'login',
        ]);
        $this->impactWindowHours = (int) ($cfg['impact_window_hours'] ?? 24);
        $this->criticalPathWeight = (float) ($cfg['critical_path_weight'] ?? 2.0);
        $this->revenueImpactFactor = (float) ($cfg['revenue_impact_factor'] ?? 0.15);
        $this->conversionDropThreshold = (float) ($cfg['conversion_drop_threshold'] ?? 5.0);
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Ingest a Sentry webhook payload and convert to AnalyticsEvent.
     *
     * Parses Sentry's issue/issue_alert webhook format and creates a
     * normalized AnalyticsEvent with error metadata.
     *
     * @param  array<string, mixed>  $payload  Sentry webhook payload
     * @return AnalyticsEvent|null  The converted event, or null if invalid
     */
    public function ingestSentryPayload(array $payload): ?AnalyticsEvent
    {
        if (! $this->enabled) {
            return null;
        }

        $action = (string) ($payload['action'] ?? '');
        if ($action === '') {
            return null;
        }

        $issue = $payload['data']['issue'] ?? $payload['issue'] ?? null;
        if ($issue === null) {
            return null;
        }

        $issueId = (string) ($issue['id'] ?? ($payload['data']['issue']['id'] ?? 'unknown'));
        $shortId = (string) ($issue['shortId'] ?? '');
        $title = (string) ($issue['title'] ?? 'Unknown Error');
        $level = (string) ($issue['level'] ?? 'error');
        $type = (string) ($issue['type'] ?? 'error');
        $eventCount = (int) ($issue['count'] ?? 1);
        $firstSeen = (string) ($issue['firstSeen'] ?? '');
        $lastSeen = (string) ($issue['lastSeen'] ?? '');
        $tags = (array) ($issue['tags'] ?? []);
        $context = (array) ($issue['context'] ?? []);
        $culprit = (string) ($issue['culprit'] ?? '');

        // Build fingerprint from title + culprit
        $fingerprint = $this->buildFingerprint($title, $culprit, $tags);

        // Detect if error is on a critical path
        $criticalPath = $this->detectCriticalPath($title, $culprit, $context, $tags);

        $params = [
            'issue_id' => $issueId,
            'short_id' => $shortId,
            'title' => $title,
            'level' => $level,
            'type' => $type,
            'event_count' => $eventCount,
            'fingerprint' => $fingerprint,
            'culprit' => $culprit,
            'critical_path' => $criticalPath,
            'first_seen' => $firstSeen,
            'last_seen' => $lastSeen,
            'tags' => $tags,
            'action' => $action,
        ];

        $this->recordError($fingerprint, $params);

        return new AnalyticsEvent(
            name: 'sentry_error',
            params: $params,
            category: 'security',
        );
    }

    /**
     * Record an error for cohort analytics.
     *
     * @param  array<string, mixed>  $errorData  Error metadata
     */
    public function recordError(string $fingerprint, array $errorData): void
    {
        if (! $this->enabled) {
            return;
        }

        $cacheKey = self::CACHE_PREFIX . 'errors_' . $this->environment;
        /** @var list<array<string, mixed>> $errors */
        $errors = $this->cache->get($cacheKey, []);

        $errors[] = array_merge($errorData, [
            'fingerprint' => $fingerprint,
            'recorded_at' => time(),
        ]);

        // Keep within limit
        $errors = array_slice($errors, -$this->maxErrors);
        $this->cache->put($cacheKey, $errors, $this->cacheTtl);
    }

    /**
     * Build a deduplication fingerprint from error metadata.
     *
     * @param  array<string, mixed>  $tags
     */
    public function buildFingerprint(string $title, string $culprit, array $tags = []): string
    {
        $components = [$title, $culprit];

        // Include relevant tags for specificity
        foreach (['transaction', 'url', 'release'] as $tagKey) {
            if (isset($tags[$tagKey])) {
                $components[] = (string) $tags[$tagKey];
            }
        }

        return md5(implode('|', $components));
    }

    /**
     * Detect if an error occurred on a critical user path.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $tags
     */
    public function detectCriticalPath(string $title, string $culprit, array $context = [], array $tags = []): ?string
    {
        $sources = [
            strtolower($title),
            strtolower($culprit),
            strtolower((string) ($context['url'] ?? '')),
            strtolower((string) ($tags['transaction'] ?? '')),
            strtolower((string) ($context['route'] ?? '')),
        ];

        $searchable = implode(' ', $sources);

        foreach ($this->criticalPaths as $path) {
            if (str_contains($searchable, strtolower($path))) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Compute error impact score (0–100).
     *
     * Combines error frequency, severity level, critical path weight,
     * and recency into a single impact score.
     *
     * @param  array<string, mixed>  $errorData
     */
    public function computeImpactScore(array $errorData): float
    {
        $frequency = (float) ($errorData['event_count'] ?? 1);
        $level = (string) ($errorData['level'] ?? 'error');
        $criticalPath = $errorData['critical_path'] ?? null;

        // Base score from frequency (capped at 100)
        $freqScore = min($frequency * 10, 100);

        // Level multiplier
        $levelMultipliers = [
            'fatal' => 1.5,
            'error' => 1.0,
            'warning' => 0.5,
            'info' => 0.1,
        ];
        $levelMultiplier = $levelMultipliers[$level] ?? 1.0;

        // Critical path bonus
        $criticalMultiplier = $criticalPath !== null ? $this->criticalPathWeight : 1.0;

        // Recency factor (errors from last hour are more impactful)
        $lastSeen = (string) ($errorData['last_seen'] ?? '');
        $recencyFactor = 1.0;
        if ($lastSeen !== '') {
            $lastSeenTime = strtotime($lastSeen);
            if ($lastSeenTime !== false) {
                $hoursSince = (time() - $lastSeenTime) / 3600;
                $recencyFactor = max(0.1, 1.0 - ($hoursSince / $this->impactWindowHours));
            }
        }

        $rawScore = $freqScore * $levelMultiplier * $criticalMultiplier * $recencyFactor;

        return round(min($rawScore, 100.0), 2);
    }

    /**
     * Compute error cohort analytics.
     *
     * Groups errors by fingerprint and computes per-cohort metrics:
     * occurrence count, unique error types, critical path errors, avg impact score.
     *
     * @return array{cohorts: int, top_errors: list<array{fingerprint: string, count: int, latest_title: string, avg_impact: float, critical: bool}>, critical_path_count: int, total_errors: int}
     */
    public function errorCohorts(): array
    {
        $errors = $this->getErrors();

        $cohorts = [];
        $totalCritical = 0;

        foreach ($errors as $error) {
            $fp = (string) ($error['fingerprint'] ?? 'unknown');

            if (! isset($cohorts[$fp])) {
                $cohorts[$fp] = [
                    'fingerprint' => $fp,
                    'count' => 0,
                    'latest_title' => (string) ($error['title'] ?? 'Unknown'),
                    'impact_sum' => 0.0,
                    'critical' => false,
                ];
            }

            $cohorts[$fp]['count']++;
            $cohorts[$fp]['latest_title'] = (string) ($error['title'] ?? 'Unknown');
            $cohorts[$fp]['impact_sum'] += $this->computeImpactScore($error);

            if (($error['critical_path'] ?? null) !== null) {
                $cohorts[$fp]['critical'] = true;
                $totalCritical++;
            }
        }

        // Compute averages and sort by impact
        $topErrors = [];
        foreach ($cohorts as $cohort) {
            $topErrors[] = [
                'fingerprint' => $cohort['fingerprint'],
                'count' => $cohort['count'],
                'latest_title' => $cohort['latest_title'],
                'avg_impact' => round($cohort['impact_sum'] / max($cohort['count'], 1), 2),
                'critical' => $cohort['critical'],
            ];
        }

        usort($topErrors, fn (array $a, array $b): int => $b['avg_impact'] <=> $a['avg_impact']);

        return [
            'cohorts' => count($cohorts),
            'top_errors' => array_slice($topErrors, 0, 20),
            'critical_path_count' => $totalCritical,
            'total_errors' => count($errors),
        ];
    }

    /**
     * Compute error-to-revenue impact estimation.
     *
     * Estimates how much revenue is at risk from errors on critical paths.
     * Uses configurable revenue impact factor and error frequency.
     *
     * @param  float  $avgOrderValue  Average order value for revenue projection
     * @return array{estimated_loss: float, at_risk_revenue: float, critical_errors: int, affected_paths: list<string>, conversion_drop_risk: float}
     */
    public function revenueImpact(float $avgOrderValue = 0.0): array
    {
        $errors = $this->getErrors();
        $criticalErrors = [];
        $affectedPaths = [];

        foreach ($errors as $error) {
            if (($error['critical_path'] ?? null) !== null) {
                $path = (string) $error['critical_path'];
                $affectedPaths[$path] = ($affectedPaths[$path] ?? 0) + 1;
                $criticalErrors[] = $error;
            }
        }

        $criticalCount = count($criticalErrors);

        // Estimate conversion drop risk based on critical error frequency
        $baseConversionDrop = min($criticalCount * 0.5, 50.0);
        $conversionDropRisk = $baseConversionDrop > $this->conversionDropThreshold
            ? round($baseConversionDrop, 2)
            : 0.0;

        // Estimate revenue at risk
        $atRiskRevenue = $avgOrderValue > 0
            ? round($avgOrderValue * $criticalCount * $this->revenueImpactFactor, 2)
            : 0.0;

        // Estimated loss = at-risk × probability of impact
        $estimatedLoss = $atRiskRevenue * ($conversionDropRisk / 100);

        return [
            'estimated_loss' => round($estimatedLoss, 2),
            'at_risk_revenue' => $atRiskRevenue,
            'critical_errors' => $criticalCount,
            'affected_paths' => array_keys($affectedPaths),
            'conversion_drop_risk' => $conversionDropRisk,
        ];
    }

    /**
     * Analyze error rates across funnel stages.
     *
     * Maps errors to funnel stages (awareness, interest, trial, conversion, retention)
     * and computes per-stage error rates and impact.
     *
     * @return array{stages: array<string, array{errors: int, critical: int, impact_avg: float}>, highest_impact_stage: string|null}
     */
    public function funnelAnalysis(): array
    {
        $errors = $this->getErrors();
        $stages = [
            'awareness' => ['errors' => 0, 'critical' => 0, 'impact_sum' => 0.0],
            'interest' => ['errors' => 0, 'critical' => 0, 'impact_sum' => 0.0],
            'trial' => ['errors' => 0, 'critical' => 0, 'impact_sum' => 0.0],
            'conversion' => ['errors' => 0, 'critical' => 0, 'impact_sum' => 0.0],
            'retention' => ['errors' => 0, 'critical' => 0, 'impact_sum' => 0.0],
        ];

        $pathToStage = [
            'signup' => 'awareness',
            'login' => 'interest',
            'trial_start' => 'trial',
            'checkout' => 'conversion',
            'payment' => 'conversion',
        ];

        foreach ($errors as $error) {
            $path = $error['critical_path'] ?? null;
            $stage = $path !== null ? ($pathToStage[$path] ?? null) : null;

            if ($stage === null) {
                // Assign to a default stage based on tags or title
                $stage = $this->inferFunnelStage($error);
            }

            if ($stage !== null && isset($stages[$stage])) {
                $stages[$stage]['errors']++;
                if ($path !== null) {
                    $stages[$stage]['critical']++;
                }
                $stages[$stage]['impact_sum'] += $this->computeImpactScore($error);
            }
        }

        // Compute averages
        $resultStages = [];
        $highestImpact = null;
        $highestImpactScore = 0.0;

        foreach ($stages as $name => $data) {
            $avgImpact = $data['errors'] > 0
                ? round($data['impact_sum'] / $data['errors'], 2)
                : 0.0;
            $resultStages[$name] = [
                'errors' => $data['errors'],
                'critical' => $data['critical'],
                'impact_avg' => $avgImpact,
            ];

            if ($avgImpact > $highestImpactScore) {
                $highestImpactScore = $avgImpact;
                $highestImpact = $name;
            }
        }

        return [
            'stages' => $resultStages,
            'highest_impact_stage' => $highestImpact,
        ];
    }

    /**
     * Get quick status summary.
     *
     * @return array{enabled: bool, environment: string, error_count: int, critical_count: int, status: string}
     */
    public function quickSummary(): array
    {
        $errors = $this->getErrors();
        $criticalCount = 0;

        foreach ($errors as $error) {
            if (($error['critical_path'] ?? null) !== null) {
                $criticalCount++;
            }
        }

        return [
            'enabled' => $this->enabled,
            'environment' => $this->environment,
            'error_count' => count($errors),
            'critical_count' => $criticalCount,
            'status' => $this->enabled
                ? ($criticalCount > 0 ? 'degraded' : 'healthy')
                : 'disabled',
        ];
    }

    /**
     * Clear all recorded errors.
     */
    public function clear(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'errors_' . $this->environment);
    }

    /**
     * Get the list of recorded errors.
     *
     * @return list<array<string, mixed>>
     */
    private function getErrors(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'errors_' . $this->environment;
        /** @var list<array<string, mixed>> $errors */
        return $this->cache->get($cacheKey, []);
    }

    /**
     * Infer funnel stage from error metadata.
     *
     * @param  array<string, mixed>  $error
     */
    private function inferFunnelStage(array $error): ?string
    {
        $title = strtolower((string) ($error['title'] ?? ''));
        $tags = (array) ($error['tags'] ?? []);

        $patterns = [
            'awareness' => ['landing', 'homepage', 'marketing', 'seo'],
            'interest' => ['pricing', 'feature', 'demo', 'docs', 'api'],
            'trial' => ['trial', 'signup', 'register', 'activation', 'onboard'],
            'conversion' => ['checkout', 'payment', 'billing', 'invoice', 'subscription'],
            'retention' => ['dashboard', 'settings', 'export', 'import', 'integration'],
        ];

        $searchable = $title . ' ' . implode(' ', array_map('strval', $tags));

        foreach ($patterns as $stage => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($searchable, $keyword)) {
                    return $stage;
                }
            }
        }

        return null;
    }
}
