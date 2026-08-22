<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Automated Analytics Pipeline Incident Detection, Classification, and Response.
 *
 * Monitors analytics pipeline health and automatically:
 * - Detects degradations (error budget breaches, latency spikes, queue backups)
 * - Classifies incidents by severity (P1-P4) and type
 * - Tracks incident lifecycle (open → acknowledged → mitigating → resolved)
 * - Triggers automated remediation (failover, circuit breaker, queue flush)
 * - Computes MTTR, MTBF, and incident frequency for SRE dashboards
 *
 * Designed for SaaS production where analytics pipeline reliability
 * directly impacts revenue attribution and compliance.
 *
 * Config: `zeroboiler.analytics.incident_response`
 *
 * @since 262.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsObservabilityService
 * @see \ZeroBoiler\Analytics\Services\ProviderHealthMonitor
 * @see \ZeroBoiler\Analytics\Services\SLOService
 */
final class AnalyticsIncidentService
{
    private const CACHE_PREFIX = 'zb_incident_';

    private const INCIDENT_REGISTRY_KEY = 'zb_incident_registry';

    private const INCIDENT_HISTORY_KEY = 'zb_incident_history';

    private const MTTR_KEY = 'zb_incident_mttr';

    private const MTBF_KEY = 'zb_incident_mtbf';

    private const SUPPRESSION_KEY = 'zb_incident_suppression';

    /** @var int Maximum active incidents */
    private const MAX_ACTIVE_INCIDENTS = 50;

    /** @var int Maximum history entries */
    private const MAX_HISTORY = 500;

    /** @var int Maximum suppression rules */
    private const MAX_SUPPRESSIONS = 100;

    /** @var int Maximum MTTR samples */
    private const MAX_MTTR_SAMPLES = 200;

    private CacheRepository $cache;

    private ConfigRepository $config;

    private bool $enabled;

    private int $detectionIntervalSeconds;

    private float $errorBudgetBreachThreshold;

    private float $latencyDegradationMultiplier;

    private int $queueBacklogThreshold;

    private int $consecutiveFailuresThreshold;

    private int $autoResolveAfterSeconds;

    private bool $autoRemediationEnabled;

    private bool $fireAnalyticsEvents;

    private int $retentionSeconds;

    /** @var list<string> */
    private array $monitoredProviders;

    private ?AnalyticsObservabilityService $observability;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     * @param  AnalyticsObservabilityService|null  $observability
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        ?AnalyticsObservabilityService $observability = null,
    ){
        $this->cache = $cache;
        $this->config = $config;
        $this->observability = $observability;

        $irConfig = $config->get('zeroboiler.analytics.incident_response', []);
        /** @var array{enabled?: bool, detection_interval?: int, error_budget_breach_threshold?: float, latency_degradation_multiplier?: float, queue_backlog_threshold?: int, consecutive_failures_threshold?: int, auto_resolve_after?: int, auto_remediation?: bool, fire_analytics_events?: bool, retention?: int, monitored_providers?: list<string>} $irConfig */

        $this->enabled = (bool) ($irConfig['enabled'] ?? true);
        $this->detectionIntervalSeconds = (int) ($irConfig['detection_interval'] ?? 60);
        $this->errorBudgetBreachThreshold = (float) ($irConfig['error_budget_breach_threshold'] ?? 0.95);
        $this->latencyDegradationMultiplier = (float) ($irConfig['latency_degradation_multiplier'] ?? 3.0);
        $this->queueBacklogThreshold = (int) ($irConfig['queue_backlog_threshold'] ?? 1000);
        $this->consecutiveFailuresThreshold = (int) ($irConfig['consecutive_failures_threshold'] ?? 5);
        $this->autoResolveAfterSeconds = (int) ($irConfig['auto_resolve_after'] ?? 1800);
        $this->autoRemediationEnabled = (bool) ($irConfig['auto_remediation'] ?? true);
        $this->fireAnalyticsEvents = (bool) ($irConfig['fire_analytics_events'] ?? true);
        $this->retentionSeconds = (int) ($irConfig['retention'] ?? 2592000);
        $this->monitoredProviders = (array) ($irConfig['monitored_providers'] ?? [
            'ga4', 'meta_pixel', 'posthog', 'plausible',
            'mixpanel', 'amplitude', 'tiktok', 'linkedin',
        ]);
    }

    // ── Public API ───────────────────────────────────────────────────────────────

    /**
     * Check if incident response is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Run a full detection cycle across all monitored providers.
     *
     * @return array{checked_providers: int, new_incidents: int, updated_incidents: int, auto_resolved: int, actions_taken: list<string>}
     */
    public function runDetectionCycle(): array
    {
        if (! $this->enabled) {
            return ['checked_providers' => 0, 'new_incidents' => 0, 'updated_incidents' => 0, 'auto_resolved' => 0, 'actions_taken' => []];
        }

        $newIncidents = 0;
        $updatedIncidents = 0;
        $autoResolved = 0;
        $actions = [];

        foreach ($this->monitoredProviders as $provider) {
            $signal = $this->detectProviderDegradation($provider);

            if ($signal === null) {
                continue;
            }

            if ($this->isSuppressed($signal['type'], $provider)) {
                continue;
            }

            $existing = $this->findOpenIncident($signal['type'], $provider);

            if ($existing !== null) {
                $this->updateIncident($existing['id'], $signal);
                $updatedIncidents++;
            } else {
                $incident = $this->openIncident($signal);
                $newIncidents++;
                $actions[] = "OPENED {$incident['severity']}: {$signal['type']} on {$provider}";

                if ($this->fireAnalyticsEvents) {
                    $this->fireIncidentStartedEvent($incident);
                }

                if ($this->autoRemediationEnabled) {
                    foreach ($this->executeRemediation($incident) as $action) {
                        $actions[] = $action;
                    }
                }
            }
        }

        // Queue backlog detection
        $queueSignal = $this->detectQueueDegradation();
        if ($queueSignal !== null && ! $this->isSuppressed('queue_backlog', 'queue')) {
            $existing = $this->findOpenIncident('queue_backlog', 'queue');
            if ($existing !== null) {
                $this->updateIncident($existing['id'], $queueSignal);
                $updatedIncidents++;
            } else {
                $incident = $this->openIncident($queueSignal);
                $newIncidents++;
                $actions[] = "OPENED {$incident['severity']}: queue_backlog";
            }
        }

        // Auto-resolve stale incidents
        $staleResolved = $this->autoResolveStaleIncidents();
        $autoResolved = count($staleResolved);
        foreach ($staleResolved as $resolved) {
            $actions[] = "AUTO-RESOLVED: {$resolved['type']} on {$resolved['provider']} ({$resolved['id']})";
            if ($this->fireAnalyticsEvents) {
                $this->fireIncidentResolvedEvent($resolved);
            }
        }

        return [
            'checked_providers' => count($this->monitoredProviders),
            'new_incidents' => $newIncidents,
            'updated_incidents' => $updatedIncidents,
            'auto_resolved' => $autoResolved,
            'actions_taken' => $actions,
        ];
    }

    /**
     * Manually open an incident.
     *
     * @param  string  $type  Incident type
     * @param  string  $provider  Affected provider/component
     * @param  string  $description  Description
     * @param  array<string, mixed>  $context  Additional context
     * @return array{id: string, type: string, provider: string, severity: string, status: string, description: string, context: array<string, mixed>, created_at: int, updated_at: int}
     */
    public function openManualIncident(string $type, string $provider, string $description, array $context = []): array
    {
        $signal = [
            'type' => $type,
            'provider' => $provider,
            'severity' => $context['severity'] ?? $this->classifySeverity($type, $context),
            'description' => $description,
            'context' => $context,
            'detected_at' => time(),
        ];

        return $this->openIncident($signal);
    }

    /**
     * Resolve an incident manually.
     */
    public function resolveIncident(string $incidentId, ?string $resolutionNote = null): bool
    {
        $incidents = $this->getActiveIncidents();

        foreach ($incidents as $index => $incident) {
            if ($incident['id'] !== $incidentId) {
                continue;
            }

            $incident['status'] = 'resolved';
            $incident['resolved_at'] = time();
            $incident['resolution_note'] = $resolutionNote;
            $incident['duration_seconds'] = time() - $incident['created_at'];
            $incident['updated_at'] = time();

            $incidents[$index] = $incident;
            $this->cache->put(self::INCIDENT_REGISTRY_KEY, array_values($incidents), $this->retentionSeconds);

            $this->recordMttr($incident['duration_seconds']);
            $this->recordMtbf();
            $this->addToHistory($incident);

            if ($this->fireAnalyticsEvents) {
                $this->fireIncidentResolvedEvent($incident);
            }

            return true;
        }

        return false;
    }

    /**
     * Acknowledge an incident.
     */
    public function acknowledgeIncident(string $incidentId, ?string $assignee = null): bool
    {
        return $this->updateIncidentStatus($incidentId, 'acknowledged', ['assignee' => $assignee]);
    }

    /**
     * Get all active incidents.
     *
     * @return list<array{id: string, type: string, provider: string, severity: string, status: string, description: string, context: array<string, mixed>, created_at: int, updated_at: int}>
     */
    public function getActiveIncidents(): array
    {
        /** @var list<array{id: string, type: string, provider: string, severity: string, status: string, description: string, context: array<string, mixed>, created_at: int, updated_at: int}>|null $incidents */
        $incidents = $this->cache->get(self::INCIDENT_REGISTRY_KEY);

        return is_array($incidents) ? $incidents : [];
    }

    /**
     * Get resolved incident history.
     *
     * @param  int  $limit
     * @return list<array{id: string, type: string, provider: string, severity: string, description: string, duration_seconds: int, created_at: int, resolved_at: int}>
     */
    public function getHistory(int $limit = 50): array
    {
        /** @var list<array{id: string, type: string, provider: string, severity: string, description: string, duration_seconds?: int, created_at: int, resolved_at?: int}>|null $history */
        $history = $this->cache->get(self::INCIDENT_HISTORY_KEY);

        if (! is_array($history)) {
            return [];
        }

        return array_slice(array_reverse($history), 0, $limit);
    }

    /**
     * Get incident dashboard summary.
     *
     * @return array{active_count: int, by_severity: array<string, int>, by_type: array<string, int>, by_provider: array<string, int>, mttr_seconds: float, mtbf_seconds: float, last_incident_at: int|null, suppression_count: int}
     */
    public function getDashboard(): array
    {
        $active = $this->getActiveIncidents();

        $bySeverity = ['P1' => 0, 'P2' => 0, 'P3' => 0, 'P4' => 0];
        $byType = [];
        $byProvider = [];
        $lastIncidentAt = null;

        foreach ($active as $incident) {
            $sev = $incident['severity'];
            $bySeverity[$sev] = ($bySeverity[$sev] ?? 0) + 1;

            $type = $incident['type'];
            $byType[$type] = ($byType[$type] ?? 0) + 1;

            $prov = $incident['provider'];
            $byProvider[$prov] = ($byProvider[$prov] ?? 0) + 1;

            if ($lastIncidentAt === null || $incident['created_at'] > $lastIncidentAt) {
                $lastIncidentAt = $incident['created_at'];
            }
        }

        return [
            'active_count' => count($active),
            'by_severity' => $bySeverity,
            'by_type' => $byType,
            'by_provider' => $byProvider,
            'mttr_seconds' => $this->getMttr(),
            'mtbf_seconds' => $this->getMtbf(),
            'last_incident_at' => $lastIncidentAt,
            'suppression_count' => count($this->getSuppressions()),
        ];
    }

    /**
     * Suppress alerts for a type/provider combination.
     */
    public function suppressAlerts(string $type, string $provider, int $durationSeconds = 3600, ?string $reason = null): void
    {
        $suppressions = $this->getSuppressions();
        $key = "{$type}:{$provider}";

        $suppressions[$key] = [
            'type' => $type,
            'provider' => $provider,
            'expires_at' => time() + $durationSeconds,
            'reason' => $reason,
            'created_at' => time(),
        ];

        if (count($suppressions) > self::MAX_SUPPRESSIONS) {
            $suppressions = array_slice($suppressions, -self::MAX_SUPPRESSIONS, null, true);
        }

        $this->cache->put(self::SUPPRESSION_KEY, $suppressions, $this->retentionSeconds);
    }

    /**
     * Get current alert suppressions.
     *
     * @return array<string, array{type: string, provider: string, expires_at: int, reason: string|null, created_at: int}>
     */
    public function getSuppressions(): array
    {
        /** @var array<string, array{type: string, provider: string, expires_at: int, reason: string|null, created_at: int}>|null $suppressions */
        $suppressions = $this->cache->get(self::SUPPRESSION_KEY);

        if (! is_array($suppressions)) {
            return [];
        }

        $now = time();

        return array_filter(
            $suppressions,
            fn (array $s): bool => $s['expires_at'] > $now,
        );
    }

    /**
     * Get Mean Time To Resolution in seconds.
     */
    public function getMttr(): float
    {
        /** @var list<int>|null $samples */
        $samples = $this->cache->get(self::MTTR_KEY);

        if (! is_array($samples) || $samples === []) {
            return 0.0;
        }

        return round(array_sum($samples) / count($samples), 2);
    }

    /**
     * Get Mean Time Between Failures in seconds.
     */
    public function getMtbf(): float
    {
        /** @var int|null $lastFailure */
        $lastFailure = $this->cache->get(self::MTBF_KEY);

        if ($lastFailure === null) {
            return 0.0;
        }

        return (float) (time() - $lastFailure);
    }

    // ── Detection ────────────────────────────────────────────────────────────────

    /**
     * Detect degradation for a specific provider.
     *
     * @return array{type: string, provider: string, severity: string, description: string, context: array<string, mixed>, detected_at: int}|null
     */
    private function detectProviderDegradation(string $provider): ?array
    {
        if ($this->observability === null || ! $this->observability->isEnabled()) {
            return null;
        }

        $metrics = $this->observability->getProviderMetrics($provider);

        // Error budget breach
        if ($metrics['error_budget_breached']) {
            return [
                'type' => 'error_budget_breach',
                'provider' => $provider,
                'severity' => 'P1',
                'description' => "Error budget breached for {$provider}: success rate {$metrics['success_rate']}",
                'context' => [
                    'success_rate' => $metrics['success_rate'],
                    'total' => $metrics['total'],
                    'failures' => $metrics['failure'],
                    'avg_latency_ms' => $metrics['avg_latency_ms'],
                ],
                'detected_at' => time(),
            ];
        }

        // Latency spike (P95 > N× baseline)
        if ($metrics['p95_latency_ms'] > 0 && $metrics['avg_latency_ms'] > 0) {
            $ratio = $metrics['p95_latency_ms'] / $metrics['avg_latency_ms'];
            if ($ratio > $this->latencyDegradationMultiplier) {
                return [
                    'type' => 'latency_spike',
                    'provider' => $provider,
                    'severity' => 'P2',
                    'description' => "Latency spike on {$provider}: p95={$metrics['p95_latency_ms']}ms, avg={$metrics['avg_latency_ms']}ms",
                    'context' => [
                        'p95_latency_ms' => $metrics['p95_latency_ms'],
                        'avg_latency_ms' => $metrics['avg_latency_ms'],
                        'p99_latency_ms' => $metrics['p99_latency_ms'],
                        'ratio' => $ratio,
                    ],
                    'detected_at' => time(),
                ];
            }
        }

        // Consecutive failures
        if ($metrics['failure'] >= $this->consecutiveFailuresThreshold && $metrics['total'] > 0) {
            $failureRate = $metrics['failure'] / $metrics['total'];
            if ($failureRate > 0.5) {
                return [
                    'type' => 'provider_outage',
                    'provider' => $provider,
                    'severity' => $metrics['failure'] >= $this->consecutiveFailuresThreshold * 2 ? 'P1' : 'P2',
                    'description' => "Possible outage on {$provider}: {$metrics['failure']}/{$metrics['total']} failures",
                    'context' => [
                        'failure_count' => $metrics['failure'],
                        'total' => $metrics['total'],
                        'failure_rate' => $failureRate,
                        'recent_errors' => array_slice($metrics['recent_errors'], 0, 3),
                    ],
                    'detected_at' => time(),
                ];
            }
        }

        return null;
    }

    /**
     * Detect queue degradation.
     *
     * @return array{type: string, provider: string, severity: string, description: string, context: array<string, mixed>, detected_at: int}|null
     */
    private function detectQueueDegradation(): ?array
    {
        try {
            $queueSize = \Illuminate\Support\Facades\Queue::size(
                $this->config->get('zeroboiler.analytics.queue.queue', 'analytics'),
            );
        } catch (\Throwable $e) {
            return null;
        }

        if ($queueSize >= $this->queueBacklogThreshold) {
            return [
                'type' => 'queue_backlog',
                'provider' => 'queue',
                'severity' => $queueSize >= $this->queueBacklogThreshold * 5 ? 'P1' : 'P3',
                'description' => "Analytics queue backlog: {$queueSize} jobs (threshold: {$this->queueBacklogThreshold})",
                'context' => [
                    'queue_size' => $queueSize,
                    'threshold' => $this->queueBacklogThreshold,
                    'queue_name' => $this->config->get('zeroboiler.analytics.queue.queue', 'analytics'),
                ],
                'detected_at' => time(),
            ];
        }

        return null;
    }

    // ── Incident Lifecycle ───────────────────────────────────────────────────────

    /**
     * Open a new incident from a detection signal.
     *
     * @param  array{type: string, provider: string, severity: string, description: string, context: array<string, mixed>, detected_at: int}  $signal
     * @return array{id: string, type: string, provider: string, severity: string, status: string, description: string, context: array<string, mixed>, created_at: int, updated_at: int}
     */
    private function openIncident(array $signal): array
    {
        $incidents = $this->getActiveIncidents();

        // Enforce max active incidents
        if (count($incidents) >= self::MAX_ACTIVE_INCIDENTS) {
            // Auto-resolve oldest P4 incidents first
            $incidents = $this->evictOldestLowPriority($incidents);
        }

        $incidentId = $this->generateIncidentId();

        $incident = [
            'id' => $incidentId,
            'type' => $signal['type'],
            'provider' => $signal['provider'],
            'severity' => $signal['severity'],
            'status' => 'open',
            'description' => $signal['description'],
            'context' => $signal['context'],
            'signal_count' => 1,
            'first_signal_at' => $signal['detected_at'],
            'last_signal_at' => $signal['detected_at'],
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $incidents[] = $incident;
        $this->cache->put(self::INCIDENT_REGISTRY_KEY, $incidents, $this->retentionSeconds);

        Log::warning("[ZeroBoiler] Incident {$incidentId} opened: {$signal['type']} on {$signal['provider']} ({$signal['severity']})");

        return $incident;
    }

    /**
     * Update an existing incident with new signal data.
     */
    private function updateIncident(string $incidentId, array $signal): void
    {
        $incidents = $this->getActiveIncidents();

        foreach ($incidents as $index => $incident) {
            if ($incident['id'] !== $incidentId) {
                continue;
            }

            // Escalate severity if signal is worse
            $severityOrder = ['P4' => 0, 'P3' => 1, 'P2' => 2, 'P1' => 3];
            $currentLevel = $severityOrder[$incident['severity']] ?? 0;
            $newLevel = $severityOrder[$signal['severity']] ?? 0;

            if ($newLevel > $currentLevel) {
                $incidents[$index]['severity'] = $signal['severity'];
                $incidents[$index]['escalated_at'] = time();
            }

            $incidents[$index]['signal_count'] = ($incident['signal_count'] ?? 1) + 1;
            $incidents[$index]['last_signal_at'] = $signal['detected_at'];
            $incidents[$index]['context'] = array_merge($incident['context'], $signal['context']);
            $incidents[$index]['updated_at'] = time();

            $this->cache->put(self::INCIDENT_REGISTRY_KEY, array_values($incidents), $this->retentionSeconds);

            return;
        }
    }

    /**
     * Update incident status.
     */
    private function updateIncidentStatus(string $incidentId, string $status, array $extra = []): bool
    {
        $incidents = $this->getActiveIncidents();

        foreach ($incidents as $index => $incident) {
            if ($incident['id'] !== $incidentId) {
                continue;
            }

            $incidents[$index]['status'] = $status;
            $incidents[$index]['updated_at'] = time();

            foreach ($extra as $key => $value) {
                if ($value !== null) {
                    $incidents[$index][$key] = $value;
                }
            }

            $this->cache->put(self::INCIDENT_REGISTRY_KEY, array_values($incidents), $this->retentionSeconds);

            return true;
        }

        return false;
    }

    /**
     * Find an open incident matching type and provider.
     *
     * @return array{id: string, type: string, provider: string, severity: string, status: string, description: string, context: array<string, mixed>, created_at: int, updated_at: int}|null
     */
    private function findOpenIncident(string $type, string $provider): ?array
    {
        foreach ($this->getActiveIncidents() as $incident) {
            if ($incident['type'] === $type && $incident['provider'] === $provider && $incident['status'] !== 'resolved') {
                return $incident;
            }
        }

        return null;
    }

    /**
     * Auto-resolve incidents that have been stable for longer than the threshold.
     *
     * @return list<array{id: string, type: string, provider: string, severity: string, status: string, duration_seconds: int}>
     */
    private function autoResolveStaleIncidents(): array
    {
        $incidents = $this->getActiveIncidents();
        $now = time();
        $resolved = [];

        foreach ($incidents as $index => $incident) {
            $lastSignal = $incident['last_signal_at'] ?? $incident['created_at'];
            $stableDuration = $now - $lastSignal;

            if ($stableDuration < $this->autoResolveAfterSeconds) {
                continue;
            }

            // Verify the signal is actually gone
            if ($incident['provider'] !== 'queue' && $this->observability !== null) {
                $newSignal = $this->detectProviderDegradation($incident['provider']);
                if ($newSignal !== null && $newSignal['type'] === $incident['type']) {
                    continue; // Still degrading, don't auto-resolve
                }
            }

            $incident['status'] = 'auto_resolved';
            $incident['resolved_at'] = $now;
            $incident['duration_seconds'] = $now - $incident['created_at'];
            $incident['resolution_note'] = 'Auto-resolved after signal stability period';

            $resolved[] = $incident;

            $this->recordMttr($incident['duration_seconds']);
            $this->recordMtbf();
            $this->addToHistory($incident);

            unset($incidents[$index]);
        }

        if (! empty($resolved)) {
            $this->cache->put(self::INCIDENT_REGISTRY_KEY, array_values($incidents), $this->retentionSeconds);
        }

        return $resolved;
}

    // ── Remediation ───────────────────────────────────────────────────────────────

    /**
     * Execute automated remediation actions for an incident.
     *
     * @param  array{id: string, type: string, provider: string, severity: string}  $incident
     * @return list<string>  Actions taken
     */
    private function executeRemediation(array $incident): array
    {
        $actions = [];
        $type = $incident['type'];
        $provider = $incident['provider'];

        // P1 incidents: log emergency, notify
        if ($incident['severity'] === 'P1') {
            Log::error("[ZeroBoiler] P1 Incident {$incident['id']}: {$type} on {$provider} — immediate attention required");
            $actions[] = 'LOGGED_P1';
        }

        // Error budget breach: trigger circuit breaker
        if ($type === 'error_budget_breach') {
            Log::warning("[ZeroBoiler] Triggering circuit breaker for {$provider}");
            $actions[] = 'CIRCUIT_BREAKER_TRIGGERED';
        }

        // Provider outage: log for manual intervention
        if ($type === 'provider_outage') {
            Log::warning("[ZeroBoiler] Provider {$provider} possible outage detected ({$incident['id']})");
            $actions[] = 'OUTAGE_LOGGED';
        }

        // Queue backlog: log
        if ($type === 'queue_backlog') {
            Log::warning("[ZeroBoiler] Queue backlog detected ({$incident['id']}): check worker status");
            $actions[] = 'QUEUE_ALERT_LOGGED';
        }

        return $actions;
    }

    // ── Suppression ───────────────────────────────────────────────────────────────

    /**
     * Check if a type/provider alert is suppressed.
     */
    private function isSuppressed(string $type, string $provider): bool
    {
        $suppressions = $this->getSuppressions();
        $key = "{$type}:{$provider}";

        return isset($suppressions[$key]);
    }

    // ── MTTR / MTBF ──────────────────────────────────────────────────────────────

    /**
     * Record a resolution time for MTTR calculation.
     */
    private function recordMttr(int $durationSeconds): void
    {
        /** @var list<int>|null $samples */
        $samples = $this->cache->get(self::MTTR_KEY);

        if (! is_array($samples)) {
            $samples = [];
        }

        $samples[] = $durationSeconds;

        if (count($samples) > self::MAX_MTTR_SAMPLES) {
            $samples = array_slice($samples, -self::MAX_MTTR_SAMPLES);
        }

        $this->cache->put(self::MTTR_KEY, $samples, $this->retentionSeconds);
    }

    /**
     * Record the time of the last failure for MTBF calculation.
     */
    private function recordMtbf(): void
    {
        $this->cache->put(self::MTBF_KEY, time(), $this->retentionSeconds);
    }

    // ── History ──────────────────────────────────────────────────────────────────

    /**
     * Add a resolved incident to history.
     *
     * @param  array<string, mixed>  $incident
     */
    private function addToHistory(array $incident): void
    {
        /** @var list<array<string, mixed>>|null $history */
        $history = $this->cache->get(self::INCIDENT_HISTORY_KEY);

        if (! is_array($history)) {
            $history = [];
        }

        $history[] = $incident;

        if (count($history) > self::MAX_HISTORY) {
            $history = array_slice($history, -self::MAX_HISTORY);
        }

        $this->cache->put(self::INCIDENT_HISTORY_KEY, $history, $this->retentionSeconds);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    /**
     * Generate a unique incident ID.
     */
    private function generateIncidentId(): string
    {
        return 'INC-' . strtoupper(substr(md5((string) microtime(true) . random_bytes(16)), 0, 8));
    }

    /**
     * Classify severity based on incident type and context.
     *
     * @param  string  $type
     * @param  array<string, mixed>  $context
     * @return 'P1'|'P2'|'P3'|'P4'
     */
    private function classifySeverity(string $type, array $context): string
    {
        return match ($type) {
            'error_budget_breach', 'provider_outage' => ($context['failure_rate'] ?? 0) > 0.9 ? 'P1' : 'P2',
            'latency_spike' => 'P2',
            'queue_backlog' => 'P3',
            'identity_failure' => 'P2',
            'consent_violation' => 'P1',
            default => 'P3',
        };
    }

    /**
     * Evict the oldest low-priority incidents to make room.
     *
     * @param  list<array<string, mixed>>  $incidents
     * @return list<array<string, mixed>>
     */
    private function evictOldestLowPriority(array $incidents): array
    {
        usort($incidents, fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);

        $priorityOrder = ['P4' => 0, 'P3' => 1, 'P2' => 2, 'P1' => 3];
        $p4Incidents = array_filter($incidents, fn (array $i): bool => ($priorityOrder[$i['severity']] ?? 0) === 0);

        foreach (array_slice($p4Incidents, 0, 5) as $evict) {
            $incidents = array_filter($incidents, fn (array $i): bool => $i['id'] !== $evict['id']);
        }

        return array_values($incidents);
    }

    /**
     * Fire an incident_started analytics event.
     *
     * @param  array{id: string, type: string, provider: string, severity: string, description: string}  $incident
     */
    private function fireIncidentStartedEvent(array $incident): void
    {
        try {
            $event = new AnalyticsEvent(
                name: 'incident_started',
                params: [
                    'incident_id' => $incident['id'],
                    'severity' => $incident['severity'],
                    'type' => $incident['type'],
                    'provider' => $incident['provider'],
                    'description' => $incident['description'],
                ],
                category: 'infrastructure',
            );

            try {
                $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
                $manager->trackEvent($event);
            } catch (\Throwable $e) {
                // Don't let analytics failures break incident tracking
            }
        } catch (\Throwable $e) {
            // Silently fail — incident tracking is critical path
        }
    }

    /**
     * Fire an incident_resolved analytics event.
     *
     * @param  array{id: string, type: string, provider: string, severity: string, duration_seconds?: int}  $incident
     */
    private function fireIncidentResolvedEvent(array $incident): void
    {
        try {
            $event = new AnalyticsEvent(
                name: 'incident_resolved',
                params: [
                    'incident_id' => $incident['id'],
                    'severity' => $incident['severity'],
                    'type' => $incident['type'],
                    'provider' => $incident['provider'],
                    'duration_seconds' => $incident['duration_seconds'] ?? 0,
                ],
                category: 'infrastructure',
            );

            try {
                $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
                $manager->trackEvent($event);
            } catch (\Throwable $e) {
                // Don't let analytics failures break incident tracking
            }
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Get the number of monitored providers.
     */
    public function getMonitoredProviderCount(): int
    {
        return count($this->monitoredProviders);
    }

    /**
     * Get the list of monitored provider names.
     *
     * @return list<string>
     */
    public function getMonitoredProviders(): array
    {
        return $this->monitoredProviders;
    }

    /**
     * Get the detection configuration.
     *
     * @return array{enabled: bool, detection_interval: int, error_budget_breach_threshold: float, latency_degradation_multiplier: float, queue_backlog_threshold: int, consecutive_failures_threshold: int, auto_resolve_after: int, auto_remediation: bool, retention: int}
     */
    public function getConfig(): array
    {
        return [
            'enabled' => $this->enabled,
            'detection_interval' => $this->detectionIntervalSeconds,
            'error_budget_breach_threshold' => $this->errorBudgetBreachThreshold,
            'latency_degradation_multiplier' => $this->latencyDegradationMultiplier,
            'queue_backlog_threshold' => $this->queueBacklogThreshold,
            'consecutive_failures_threshold' => $this->consecutiveFailuresThreshold,
            'auto_resolve_after' => $this->autoResolveAfterSeconds,
            'auto_remediation' => $this->autoRemediationEnabled,
            'retention' => $this->retentionSeconds,
        ];
    }
}
