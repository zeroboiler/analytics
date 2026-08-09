<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Weekly Digest Service for SaaS analytics.
 *
 * Generates structured weekly analytics digests with key metrics,
 * trends, highlights, and actionable recommendations. Designed for
 * scheduled commands, email reports, and admin dashboard digests.
 *
 * Digests are cache-backed for performance and include:
 * - Executive summary (key numbers)
 * - Top events (most tracked)
 * - Provider health (dispatch success rates)
 * - Retention & churn signals
 * - Growth metrics overview
 * - Recommended actions
 *
 * @phpstan-type DigestSection array{title: string, data: array<string, mixed>, grade?: string}
 * @phpstan-type WeeklyDigest array{period: string, generated_at: string, version: string, sections: list<DigestSection>, summary: array{total_events: int, active_providers: int, overall_grade: string, highlights: list<string>, alerts: list<string>}}
 *
 * @since 1.0.0
 */
final class WeeklyDigestService
{
    private const CACHE_PREFIX = 'zb_digest_';

    private const CACHE_TTL = 604800; // 7 days

    private ConfigRepository $config;

    public function __construct(ConfigRepository $config): void
    {
        $this->config = $config;
    }

    /**
     * Generate a weekly digest for the given period.
     *
     * @param  string|null  $period  ISO week date (e.g., '2026-W32'). Defaults to current week.
     * @return WeeklyDigest
     */
    public function generate(?string $period = null): array
    {
        $period = $period ?? $this->currentIsoWeek();

        // Check cache
        $cacheKey = self::CACHE_PREFIX . $period;
        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $digest = $this->buildDigest($period);
        $this->writeCache($cacheKey, $digest);

        return $digest;
    }

    /**
     * Get the latest available digest (current week).
     *
     * @return WeeklyDigest
     */
    public function latest(): array
    {
        return $this->generate();
    }

    /**
     * Generate a digest summary for CLI output.
     *
     * Returns a human-readable summary suitable for Artisan commands
     * and scheduled notifications.
     *
     * @return array{lines: list<string>, grade: string, has_alerts: bool}
     */
    public function cliSummary(): array
    {
        $digest = $this->generate();
        $lines = [];
        $hasAlerts = false;

        // Header
        $lines[] = '╔══════════════════════════════════════════════════╗';
        $lines[] = '║  ZeroBoiler Analytics — Weekly Digest            ║';
        $lines[] = '║  Period: ' . str_pad($digest['period'], 36) . '║';
        $lines[] = '╚══════════════════════════════════════════════════╝';
        $lines[] = '';

        // Summary
        $summary = $digest['summary'];
        $lines[] = "Total Events:        " . number_format($summary['total_events']);
        $lines[] = "Active Providers:   " . (string) $summary['active_providers'];
        $lines[] = "Overall Grade:      " . $summary['overall_grade'];
        $lines[] = '';

        // Highlights
        if (! empty($summary['highlights'])) {
            $lines[] = '── Highlights ──────────────────────────────────';
            foreach ($summary['highlights'] as $highlight) {
                $lines[] = '  ✓ ' . $highlight;
            }
            $lines[] = '';
        }

        // Alerts
        if (! empty($summary['alerts'])) {
            $hasAlerts = true;
            $lines[] = '── Alerts ─────────────────────────────────────';
            foreach ($summary['alerts'] as $alert) {
                $lines[] = '  ⚠ ' . $alert;
            }
            $lines[] = '';
        }

        // Sections
        foreach ($digest['sections'] as $section) {
            $lines[] = '── ' . $section['title'] . ' ─────────────────────────────────';
            foreach ($section['data'] as $key => $value) {
                if (is_array($value)) {
                    $lines[] = '  ' . $key . ': ' . json_encode($value);
                } else {
                    $lines[] = '  ' . $key . ': ' . (string) $value;
                }
            }
            $lines[] = '';
        }

        return [
            'lines' => $lines,
            'grade' => $summary['overall_grade'],
            'has_alerts' => $hasAlerts,
        ];
    }

    /**
     * Get the current ISO week string (e.g., '2026-W32').
     */
    public function currentIsoWeek(): string
    {
        $now = now();
        $year = $now->format('Y');
        $week = $now->format('W');

        return "{$year}-W{$week}";
    }

    /**
     * Get available digest periods from cache.
     *
     * @return list<string>
     */
    public function availablePeriods(): array
    {
        try {
            // This would scan the cache for digest keys
            // For now, return the current period
            return [$this->currentIsoWeek()];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Build the full weekly digest structure.
     *
     * @param  string  $period  ISO week identifier
     * @return WeeklyDigest
     */
    private function buildDigest(string $period): array
    {
        $sections = [];

        // Section 1: Event Overview
        $sections[] = $this->buildEventOverviewSection();

        // Section 2: Provider Health
        $sections[] = $this->buildProviderHealthSection();

        // Section 3: SaaS Metrics
        $sections[] = $this->buildSaaSMetricsSection();

        // Section 4: Retention & Engagement
        $sections[] = $this->buildRetentionSection();

        // Section 5: E-commerce (if applicable)
        $ecomSection = $this->buildEcommerceSection();
        if ($ecomSection !== null) {
            $sections[] = $ecomSection;
        }

        // Section 6: Growth Insights
        $sections[] = $this->buildGrowthInsightsSection();

        // Build summary
        $summary = $this->buildSummary($sections);

        return [
            'period' => $period,
            'generated_at' => now()->toIso8601String(),
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'sections' => $sections,
            'summary' => $summary,
        ];
    }

    /**
     * Build the event overview section.
     *
     * @return DigestSection
     */
    private function buildEventOverviewSection(): array
    {
        $totalEvents = 0;
        $topEvents = [];
        $categoryBreakdown = ['ecommerce' => 0, 'saas' => 0, 'engagement' => 0];

        try {
            $streamService = app(EventStreamService::class);
            $totalEvents = $streamService->getTotalCount();

            // Get event counts from stream
            $allEvents = $streamService->getRecentEvents(100);
            $eventCounts = [];
            foreach ($allEvents as $event) {
                $name = $event['name'] ?? 'unknown';
                $eventCounts[$name] = ($eventCounts[$name] ?? 0) + 1;
            }
            arsort($eventCounts);
            $topEvents = array_slice($eventCounts, 0, 10, true);
        } catch (\Throwable) {
            // Stream service not available
        }

        return [
            'title' => 'Event Overview',
            'data' => [
                'total_events' => $totalEvents,
                'top_events' => $topEvents,
                'catalog_size' => EventCatalog::count(),
                'categories' => $categoryBreakdown,
            ],
        ];
    }

    /**
     * Build the provider health section.
     *
     * @return DigestSection
     */
    private function buildProviderHealthSection(): array
    {
        $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog'];
        $activeProviders = 0;
        $providerStatus = [];

        foreach ($providers as $provider) {
            $enabled = (bool) $this->config->get("zeroboiler.analytics.{$provider}.enabled");
            $providerStatus[$provider] = [
                'enabled' => $enabled,
                'status' => $enabled ? 'active' : 'disabled',
            ];
            $activeProviders += (int) $enabled;
        }

        // Check for issues
        $issues = [];
        if ($activeProviders === 0) {
            $issues[] = 'No analytics providers are enabled';
        }
        if ($activeProviders === 1) {
            $issues[] = 'Only one provider enabled — consider adding a backup';
        }

        $grade = match (true) {
            $activeProviders >= 3 => 'A',
            $activeProviders >= 2 => 'B',
            $activeProviders >= 1 => 'C',
            default => 'F',
        };

        return [
            'title' => 'Provider Health',
            'grade' => $grade,
            'data' => [
                'active_providers' => $activeProviders,
                'providers' => $providerStatus,
                'issues' => $issues,
            ],
        ];
    }

    /**
     * Build the SaaS metrics section.
     *
     * @return DigestSection
     */
    private function buildSaaSMetricsSection(): array
    {
        $saasEvents = EventCatalog::saasFunnelEvents();
        $saasEventNames = array_column($saasEvents, 'name');

        $counts = [];
        $total = 0;

        try {
            $streamService = app(EventStreamService::class);
            foreach ($saasEventNames as $name) {
                $count = $streamService->getEventCount($name);
                $counts[$name] = $count;
                $total += $count;
            }
        } catch (\Throwable) {
            // Stream service not available
        }

        // Key SaaS ratios
        $signupCount = $counts['sign_up'] ?? 0;
        $trialCount = $counts['start_trial'] ?? 0;
        $subscribeCount = $counts['subscribe'] ?? 0;
        $cancellationCount = $counts['cancellation'] ?? 0;

        $trialConversionRate = $trialCount > 0
            ? round(min($subscribeCount / $trialCount, 1.0), 4)
            : 0.0;
        $signupToTrialRate = $signupCount > 0
            ? round(min($trialCount / $signupCount, 1.0), 4)
            : 0.0;
        $churnRate = $subscribeCount > 0
            ? round(min($cancellationCount / $subscribeCount, 1.0), 4)
            : 0.0;

        return [
            'title' => 'SaaS Metrics',
            'data' => [
                'total_saas_events' => $total,
                'signups' => $signupCount,
                'trial_starts' => $trialCount,
                'subscriptions' => $subscribeCount,
                'cancellations' => $cancellationCount,
                'signup_to_trial_rate' => $signupToTrialRate,
                'trial_conversion_rate' => $trialConversionRate,
                'churn_rate' => $churnRate,
            ],
        ];
    }

    /**
     * Build the retention & engagement section.
     *
     * @return DigestSection
     */
    private function buildRetentionSection(): array
    {
        $engagementEvents = EventCatalog::engagementEvents();
        $engagementNames = array_column($engagementEvents, 'name');

        $counts = [];
        $total = 0;

        try {
            $streamService = app(EventStreamService::class);
            foreach ($engagementNames as $name) {
                $count = $streamService->getEventCount($name);
                $counts[$name] = $count;
                $total += $count;
            }
        } catch (\Throwable) {
            // Stream service not available
        }

        return [
            'title' => 'Retention & Engagement',
            'data' => [
                'total_engagement_events' => $total,
                'page_views' => $counts['page_view'] ?? 0,
                'search_events' => $counts['search'] ?? 0,
                'form_submits' => $counts['form_submit'] ?? 0,
                'error_events' => $counts['error'] ?? 0,
                'session_starts' => $counts['session_start'] ?? 0,
                'scroll_depth_events' => $counts['scroll_depth'] ?? 0,
            ],
        ];
    }

    /**
     * Build the e-commerce section (only if e-commerce events are tracked).
     *
     * @return DigestSection|null
     */
    private function buildEcommerceSection(): ?array
    {
        $ecommerceEvents = EventCatalog::checkoutFunnel();
        $ecommerceNames = array_column($ecommerceEvents, 'name');

        $counts = [];
        $total = 0;

        try {
            $streamService = app(EventStreamService::class);
            foreach ($ecommerceNames as $name) {
                $count = $streamService->getEventCount($name);
                $counts[$name] = $count;
                $total += $count;
            }
        } catch (\Throwable) {
            return null;
        }

        if ($total === 0) {
            return null;
        }

        $viewItemCount = $counts['view_item'] ?? 0;
        $purchaseCount = $counts['purchase'] ?? 0;
        $conversionRate = $viewItemCount > 0
            ? round(min($purchaseCount / $viewItemCount, 1.0), 4)
            : 0.0;

        return [
            'title' => 'E-commerce',
            'data' => [
                'total_ecommerce_events' => $total,
                'purchases' => $purchaseCount,
                'refunds' => $counts['refund'] ?? 0,
                'conversion_rate' => $conversionRate,
                'cart_adds' => $counts['add_to_cart'] ?? 0,
                'checkouts_started' => $counts['begin_checkout'] ?? 0,
            ],
        ];
    }

    /**
     * Build the growth insights section.
     *
     * @return DigestSection
     */
    private function buildGrowthInsightsSection(): array
    {
        $insights = [];
        $alerts = [];

        // Check provider coverage
        $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog'];
        $enabledCount = count(array_filter($providers, fn (string $p): bool => (bool) $this->config->get("zeroboiler.analytics.{$p}.enabled")));

        if ($enabledCount === 0) {
            $alerts[] = 'No analytics providers configured — events are being lost';
        } elseif ($enabledCount === 1) {
            $insights[] = 'Single provider — consider adding a backup for resilience';
        }

        // Check queue status
        $queueEnabled = (bool) $this->config->get('zeroboiler.analytics.queue.enabled');
        if (! $queueEnabled) {
            $alerts[] = 'Queue dispatch is disabled — analytics events block HTTP responses';
        }

        // Check consent
        $consentDefault = $this->config->get('zeroboiler.analytics.consent.default', 'granted');
        if ($consentDefault === 'granted') {
            $insights[] = 'Consent defaults to "granted" — consider GDPR review if targeting EU users';
        }

        // Check catalog size
        $catalogSize = EventCatalog::count();
        if ($catalogSize >= 90) {
            $insights[] = "Comprehensive catalog ({$catalogSize} events) — well-instrumented for production";
        }

        // Check lifecycle tracking
        $lifecycleEnabled = (bool) $this->config->get('zeroboiler.analytics.lifecycle.enabled');
        if ($lifecycleEnabled) {
            $insights[] = 'Lifecycle auto-tracking enabled — server events are captured automatically';
        }

        // Check onboarding wizard grade
        try {
            $wizard = app(OnboardingWizardService::class);
            $grade = $wizard->getReadinessGrade();
            $scorePercent = (int) round(($grade['score'] ?? 0) * 100);
            $insights[] = "Onboarding readiness: {$grade['grade']} ({$scorePercent}% complete)";
        } catch (\Throwable) {
            // OnboardingWizardService not available
        }

        return [
            'title' => 'Growth Insights',
            'data' => [
                'insights' => $insights,
                'alerts' => $alerts,
                'enabled_providers' => $enabledCount,
                'catalog_size' => $catalogSize,
            ],
        ];
    }

    /**
     * Build the executive summary from all sections.
     *
     * @param  list<DigestSection>  $sections
     * @return array{total_events: int, active_providers: int, overall_grade: string, highlights: list<string>, alerts: list<string>}
     */
    private function buildSummary(array $sections): array
    {
        $totalEvents = 0;
        $activeProviders = 0;
        $highlights = [];
        $alerts = [];
        $sectionGrades = [];

        foreach ($sections as $section) {
            if (isset($section['data']['total_events'])) {
                $totalEvents += (int) $section['data']['total_events'];
            }
            if (isset($section['data']['active_providers'])) {
                $activeProviders = (int) $section['data']['active_providers'];
            }
            if (isset($section['grade'])) {
                $sectionGrades[] = $section['grade'];
            }
            if (isset($section['data']['insights'])) {
                foreach ($section['data']['insights'] as $insight) {
                    $highlights[] = $insight;
                }
            }
            if (isset($section['data']['alerts'])) {
                foreach ($section['data']['alerts'] as $alert) {
                    $alerts[] = $alert;
                }
            }
        }

        // Add metric-based highlights
        if ($totalEvents > 0) {
            $highlights[] = "Tracked {$totalEvents} events this week";
        }

        if ($activeProviders >= 2) {
            $highlights[] = "{$activeProviders} analytics providers active";
        }

        // Calculate overall grade from section grades
        $overallGrade = 'N/A';
        if (! empty($sectionGrades)) {
            $gradeValues = array_map(fn (string $g): int => match ($g) {
                'A' => 4, 'B' => 3, 'C' => 2, 'D' => 1, default => 0,
            }, $sectionGrades);
            $avgGrade = array_sum($gradeValues) / count($gradeValues);
            $overallGrade = match (true) {
                $avgGrade >= 3.5 => 'A',
                $avgGrade >= 2.5 => 'B',
                $avgGrade >= 1.5 => 'C',
                $avgGrade >= 0.5 => 'D',
                default => 'F',
            };
        }

        return [
            'total_events' => $totalEvents,
            'active_providers' => $activeProviders,
            'overall_grade' => $overallGrade,
            'highlights' => array_slice($highlights, 0, 10),
            'alerts' => array_slice($alerts, 0, 10),
        ];
    }

    /**
     * Read value from cache.
     *
     * @return mixed|null
     */
    private function readCache(string $key): mixed
    {
        try {
            return cache()->get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Write value to cache.
     */
    private function writeCache(string $key, mixed $value): void
    {
        try {
            cache()->put($key, $value, self::CACHE_TTL);
        } catch (\Throwable) {
            // Cache driver not available
        }
    }
}
