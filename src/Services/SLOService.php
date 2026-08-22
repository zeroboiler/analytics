<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
/**
 * Service Level Objective (SLO) and Error Budget tracking service.
 *
 * Implements Google Cloud SRE error budget policy:
 * - **SLO**: Target reliability percentage per category/window (e.g., 99.9%)
 * - **Error Budget**: Allowed failures = total_events × (1 - slo_target/100)
 * - **Burn Rate**: Current consumption rate of error budget per time window
 * - **Remaining Budget**: Error budget minus consumed errors, with projection
 * - **Compliance**: Historical SLO achievement over rolling windows
 *
 * Integrates with ProviderSLAMonitor for provider-level SLI data
 * and AlertNotificationService for burn rate alerting.
 *
 * Use cases:
 * - Track SLO compliance for event delivery pipeline
 * - Monitor error budget consumption in real-time
 * - Alert when burn rate exceeds safe thresholds
 * - Generate SLO compliance reports for stakeholders
 * - Project remaining error budget based on current burn rate
 *
 * Config: `zeroboiler.analytics.slo`
 *
 * @since 157.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\ProviderSLAMonitor
 * @see \ZeroBoiler\Analytics\Services\AlertNotificationService
 */
final class SLOService
{
    private const CACHE_PREFIX = 'zb_slo_';
    private const ERROR_BUDGET_KEY = 'zb_slo_error_budgets';
    private const BURN_RATE_KEY = 'zb_slo_burn_rates';
    private const COMPLIANCE_KEY = 'zb_slo_compliance_history';

    private bool $enabled;
    private int $windowSeconds;
    private int $retentionWindows;
    private bool $alertOnBurnRate;
    private float $burnRateAlertThreshold;
    private float $burnRateCriticalThreshold;
    private int $maxComplianceHistory;

    /** @var array<string, array{target: float, error_budget_minutes: int, window: string}> */
    private array $objectives;

    /** @var array<string, float> */
    private array $categoryDefaults;

    private CacheRepository $cache;

    /**
     * @param  array<string, mixed>  $slaConfig
     * @param  array<string, mixed>  $sloConfig
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $sloConfig = $config->get('zeroboiler.analytics.slo', []);

        $this->enabled = (bool) ($sloConfig['enabled'] ?? true);
        $this->windowSeconds = (int) ($sloConfig['window_seconds'] ?? 3600);
        $this->retentionWindows = (int) ($sloConfig['retention_windows'] ?? 168);
        $this->alertOnBurnRate = (bool) ($sloConfig['alert_on_burn_rate'] ?? true);
        $this->burnRateAlertThreshold = (float) ($sloConfig['burn_rate_alert_threshold'] ?? 2.0);
        $this->burnRateCriticalThreshold = (float) ($sloConfig['burn_rate_critical_threshold'] ?? 5.0);
        $this->maxComplianceHistory = (int) ($sloConfig['max_compliance_history'] ?? 720);

        $this->objectives = (array) ($sloConfig['objectives'] ?? []);
        $this->categoryDefaults = (array) ($sloConfig['category_defaults'] ?? [
            'event_delivery' => 99.9,
            'provider_dispatch' => 99.5,
            'pipeline_processing' => 99.95,
            'api_availability' => 99.99,
        ]);
    }

    /**
     * Record a success event against an SLO objective.
     *
     * @param  string  $objective  SLO objective name (e.g., 'event_delivery', 'provider_dispatch')
     * @param  string|null  $provider  Optional provider name for provider-specific SLOs
     */
    public function recordSuccess(string $objective, ?string $provider = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = $this->objectiveKey($objective, $provider);
        $window = $this->currentWindowKey();

        /** @var array{total: int, errors: int, window: string} $data */
        $data = $this->cache->get($key, [
            'total' => 0,
            'errors' => 0,
            'window' => $window,
        ]);

        // Reset on new window
        if ($data['window'] !== $window) {
            $data = ['total' => 0, 'errors' => 0, 'window' => $window];
        }

        $data['total']++;

        $this->cache->put($key, $data, $this->windowSeconds * $this->retentionWindows);
    }

    /**
     * Record an error (failure) event against an SLO objective.
     *
     * @param  string  $objective  SLO objective name
     * @param  string|null  $provider  Optional provider name
     * @param  string|null  $reason  Failure reason for debugging
     */
    public function recordError(string $objective, ?string $provider = null, ?string $reason = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = $this->objectiveKey($objective, $provider);
        $window = $this->currentWindowKey();

        /** @var array{total: int, errors: int, window: string} $data */
        $data = $this->cache->get($key, [
            'total' => 0,
            'errors' => 0,
            'window' => $window,
        ]);

        if ($data['window'] !== $window) {
            $data = ['total' => 0, 'errors' => 0, 'window' => $window];
        }

        $data['total']++;
        $data['errors']++;

        $this->cache->put($key, $data, $this->windowSeconds * $this->retentionWindows);

        if ($reason !== null) {
            Log::debug('SLO error recorded', [
                'objective' => $objective,
                'provider' => $provider,
                'reason' => $reason,
                'window' => $window,
            ]);
        }
    }

    /**
     * Get the current error budget status for an objective.
     *
     * @param  string  $objective  SLO objective name
     * @param  string|null  $provider  Optional provider name
     * @return array{target: float, total: int, errors: int, error_budget: int, remaining: int, remaining_pct: float, burn_rate: float, status: string}
     */
    public function getErrorBudget(string $objective, ?string $provider = null): array
    {
        $target = $this->getTarget($objective);
        $key = $this->objectiveKey($objective, $provider);

        /** @var array{total: int, errors: int, window: string} $data */
        $data = $this->cache->get($key, [
            'total' => 0,
            'errors' => 0,
            'window' => $this->currentWindowKey(),
        ]);

        $total = $data['total'];
        $errors = $data['errors'];
        $errorBudget = $this->calculateErrorBudget($total, $target);
        $remaining = max(0, $errorBudget - $errors);
        $remainingPct = $errorBudget > 0 ? ($remaining / $errorBudget) * 100.0 : 100.0;

        $burnRate = $this->calculateBurnRate($objective, $provider);

        $status = $this->determineStatus($burnRate, $remainingPct);

        return [
            'target' => $target,
            'total' => $total,
            'errors' => $errors,
            'error_budget' => $errorBudget,
            'remaining' => $remaining,
            'remaining_pct' => round($remainingPct, 2),
            'burn_rate' => round($burnRate, 4),
            'status' => $status,
        ];
    }

    /**
     * Get the current SLO compliance percentage for an objective.
     *
     * @param  string  $objective  SLO objective name
     * @param  string|null  $provider  Optional provider name
     * @return float Compliance percentage (0-100)
     */
    public function getCompliance(string $objective, ?string $provider = null): float
    {
        $budget = $this->getErrorBudget($objective, $provider);

        if ($budget['total'] === 0) {
            return 100.0;
        }

        return round((1.0 - ($budget['errors'] / $budget['total'])) * 100.0, 4);
    }

    /**
     * Calculate the burn rate for an objective.
     *
     * Burn rate = (errors / error_budget) per window.
     * A burn rate of 1.0 means the budget will be fully consumed in one window.
     * A burn rate of 2.0 means the budget burns twice as fast as allowed.
     *
     * @param  string  $objective  SLO objective name
     * @param  string|null  $provider  Optional provider name
     * @return float Burn rate multiplier
     */
    public function calculateBurnRate(string $objective, ?string $provider = null): float
    {
        $budget = $this->getErrorBudget($objective, $provider);

        if ($budget['error_budget'] <= 0) {
            return 0.0;
        }

        return (float) $budget['errors'] / (float) $budget['error_budget'];
    }

    /**
     * Project remaining error budget based on current burn rate.
     *
     * @param  string  $objective  SLO objective name
     * @param  string|null  $provider  Optional provider name
     * @return array{remaining_budget: int, estimated_windows_left: float, estimated_time_left: string, burn_rate: float, status: string}
     */
    public function projectBudget(string $objective, ?string $provider = null): array
    {
        $budget = $this->getErrorBudget($objective, $provider);
        $burnRate = $budget['burn_rate'];
        $remaining = $budget['remaining'];

        if ($burnRate <= 0.0) {
            return [
                'remaining_budget' => $remaining,
                'estimated_windows_left' => PHP_FLOAT_MAX,
                'estimated_time_left' => 'unlimited',
                'burn_rate' => $burnRate,
                'status' => 'healthy',
            ];
        }

        $windowsLeft = $burnRate > 0 ? $remaining / $burnRate : PHP_FLOAT_MAX;

        return [
            'remaining_budget' => $remaining,
            'estimated_windows_left' => round($windowsLeft, 1),
            'estimated_time_left' => $this->formatTimeLeft($windowsLeft),
            'burn_rate' => $burnRate,
            'status' => $budget['status'],
        ];
    }

    /**
     * Check if the burn rate exceeds alert thresholds.
     *
     * @param  string  $objective  SLO objective name
     * @param  string|null  $provider  Optional provider name
     * @return array{exceeds_alert: bool, exceeds_critical: bool, burn_rate: float, threshold_alert: float, threshold_critical: float}
     */
    public function checkBurnRateThreshold(string $objective, ?string $provider = null): array
    {
        $burnRate = $this->calculateBurnRate($objective, $provider);

        return [
            'exceeds_alert' => $burnRate >= $this->burnRateAlertThreshold,
            'exceeds_critical' => $burnRate >= $this->burnRateCriticalThreshold,
            'burn_rate' => round($burnRate, 4),
            'threshold_alert' => $this->burnRateAlertThreshold,
            'threshold_critical' => $this->burnRateCriticalThreshold,
        ];
    }

    /**
     * Get a comprehensive SLO dashboard summary for all objectives.
     *
     * @return array{enabled: bool, window_seconds: int, objectives: array<string, array>, summary: array{healthy: int, warning: int, critical: int, total: int}}
     */
    public function dashboard(): array
    {
        $allObjectives = $this->getAllObjectiveNames();
        $results = [];
        $summary = ['healthy' => 0, 'warning' => 0, 'critical' => 0, 'total' => 0];

        foreach ($allObjectives as $name) {
            $budget = $this->getErrorBudget($name);
            $results[$name] = $budget;
            $summary['total']++;

            if ($budget['status'] === 'healthy') {
                $summary['healthy']++;
            } elseif ($budget['status'] === 'warning') {
                $summary['warning']++;
            } else {
                $summary['critical']++;
            }
        }

        return [
            'enabled' => $this->enabled,
            'window_seconds' => $this->windowSeconds,
            'objectives' => $results,
            'summary' => $summary,
        ];
    }

    /**
     * Record compliance history for trend analysis.
     *
     * @param  string  $objective  SLO objective name
     * @param  string|null  $provider  Optional provider name
     */
    public function recordComplianceHistory(string $objective, ?string $provider = null): void
    {
        $compliance = $this->getCompliance($objective, $provider);
        $key = self::COMPLIANCE_KEY . '_' . $objective . ($provider ? '_' . $provider : '');
        $window = $this->currentWindowKey();

        /** @var array<string, float> $history */
        $history = $this->cache->get($key, []);

        $history[$window] = $compliance;

        // Trim to max history length
        if (count($history) > $this->maxComplianceHistory) {
            $history = array_slice($history, -$this->maxComplianceHistory, null, true);
        }

        $this->cache->put($key, $history, $this->windowSeconds * $this->retentionWindows * 2);
    }

    /**
     * Get compliance history for an objective.
     *
     * @param  string  $objective  SLO objective name
     * @param  string|null  $provider  Optional provider name
     * @param  int  $limit  Max history points to return
     * @return array<string, float> Window key → compliance percentage
     */
    public function getComplianceHistory(string $objective, ?string $provider = null, int $limit = 24): array
    {
        $key = self::COMPLIANCE_KEY . '_' . $objective . ($provider ? '_' . $provider : '');

        /** @var array<string, float> $history */
        $history = $this->cache->get($key, []);

        return array_slice($history, -$limit, null, true);
    }

    /**
     * Calculate the 30-day rolling compliance average for an objective.
     *
     * @param  string  $objective  SLO objective name
     * @param  string|null  $provider  Optional provider name
     * @return float Rolling average compliance percentage
     */
    public function rollingCompliance(string $objective, ?string $provider = null): float
    {
        $history = $this->getComplianceHistory($objective, $provider, 720);

        if (empty($history)) {
            return 100.0;
        }

        return round(array_sum($history) / count($history), 4);
    }

    /**
     * Reset all SLO counters for an objective (useful for testing).
     *
     * @param  string  $objective  SLO objective name
     * @param  string|null  $provider  Optional provider name
     */
    public function reset(string $objective, ?string $provider = null): void
    {
        $key = $this->objectiveKey($objective, $provider);
        $this->cache->forget($key);
    }

    /**
     * Check if the SLO service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the configured SLO target for an objective.
     *
     * @param  string  $objective  SLO objective name
     * @return float Target percentage (e.g., 99.9)
     */
    public function getTarget(string $objective): float
    {
        if (isset($this->objectives[$objective]['target'])) {
            return (float) $this->objectives[$objective]['target'];
        }

        return $this->categoryDefaults[$objective] ?? 99.9;
    }

    /**
     * Get all configured objective names (custom + defaults).
     *
     * @return list<string>
     */
    public function getAllObjectiveNames(): array
    {
        $names = array_keys($this->objectives);

        foreach (array_keys($this->categoryDefaults) as $default) {
            if (! in_array($default, $names, true)) {
                $names[] = $default;
            }
        }

        return $names;
    }

    /**
     * Calculate error budget from total events and target.
     *
     * error_budget = ceil(total × (1 - target/100))
     */
    private function calculateErrorBudget(int $total, float $target): int
    {
        if ($total === 0 || $target >= 100.0) {
            return 0;
        }

        return (int) ceil($total * (1.0 - $target / 100.0));
    }

    /**
     * Determine status from burn rate and remaining budget percentage.
     *
     * @return string 'healthy'|'warning'|'critical'
     */
    private function determineStatus(float $burnRate, float $remainingPct): string
    {
        if ($burnRate >= $this->burnRateCriticalThreshold) {
            return 'critical';
        }

        if ($burnRate >= $this->burnRateAlertThreshold || $remainingPct <= 20.0) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Generate cache key for an objective.
     */
    private function objectiveKey(string $objective, ?string $provider = null): string
    {
        $suffix = $provider !== null ? '_' . $provider : '';

        return self::CACHE_PREFIX . $objective . $suffix;
    }

    /**
     * Generate current time window key.
     */
    private function currentWindowKey(): string
    {
        return (string) (int) (time() / $this->windowSeconds);
    }

    /**
     * Format estimated time left from windows to human-readable string.
     *
     * @param  float  $windows  Number of windows remaining
     * @return string Human-readable time estimate
     */
    private function formatTimeLeft(float $windows): string
    {
        $seconds = $windows * $this->windowSeconds;

        if ($seconds < 60) {
            return (int) $seconds . 's';
        }

        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }

        if ($seconds < 86400) {
            return round($seconds / 3600, 1) . 'h';
        }

        return round($seconds / 86400, 1) . 'd';
    }
}
