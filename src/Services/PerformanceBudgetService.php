<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Performance budget service for analytics payload and rate management.
 *
 * Enforces configurable limits on event payload size, rate per session/user,
 * max events per page view, and total daily event quotas. Helps prevent
 * analytics from impacting application performance or incurring excessive costs.
 *
 * Configuration is read from `zeroboiler.analytics.performance_budget`.
 */
final class PerformanceBudgetService
{
    private bool $enabled;

    private int $maxPayloadBytes;

    private int $maxParamsCount;

    private int $maxEventsPerSession;

    private int $maxEventsPerUserPerDay;

    private int $maxEventsPerPageView;

    private int $maxParamValueLength;

    private bool $dropOversizedEvents;

    private bool $warnOnly;

    private const WARNINGS_CACHE_PREFIX = 'zb_analytics_perf_';

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
    {
        $perfConfig = $config->get('zeroboiler.analytics.performance_budget', []);
        /** @var array{enabled?: bool, max_payload_bytes?: int, max_params_count?: int, max_events_per_session?: int, max_events_per_user_per_day?: int, max_events_per_page_view?: int, max_param_value_length?: int, drop_oversized?: bool, warn_only?: bool} $perfConfig */

        $this->enabled = (bool) ($perfConfig['enabled'] ?? false);
        $this->maxPayloadBytes = (int) ($perfConfig['max_payload_bytes'] ?? 8192);
        $this->maxParamsCount = (int) ($perfConfig['max_params_count'] ?? 25);
        $this->maxEventsPerSession = (int) ($perfConfig['max_events_per_session'] ?? 100);
        $this->maxEventsPerUserPerDay = (int) ($perfConfig['max_events_per_user_per_day'] ?? 500);
        $this->maxEventsPerPageView = (int) ($perfConfig['max_events_per_page_view'] ?? 50);
        $this->maxParamValueLength = (int) ($perfConfig['max_param_value_length'] ?? 500);
        $this->dropOversizedEvents = (bool) ($perfConfig['drop_oversized'] ?? true);
        $this->warnOnly = (bool) ($perfConfig['warn_only'] ?? false);
    }

    /**
     * Check if the performance budget is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Validate an event against all performance budget rules.
     *
     * @return array{valid: bool, violations: list<array{rule: string, message: string, severity: string}>}
     */
    public function validate(AnalyticsEvent $event): array
    {
        if (! $this->enabled) {
            return ['valid' => true, 'violations' => []];
        }

        $violations = [];
        $payloadSize = strlen(json_encode($event->params));
        $paramsCount = count($event->params);

        // Check payload size
        if ($payloadSize > $this->maxPayloadBytes) {
            $violations[] = [
                'rule' => 'max_payload_bytes',
                'message' => "Event payload ({$payloadSize} bytes) exceeds limit ({$this->maxPayloadBytes} bytes)",
                'severity' => $this->warnOnly ? 'warning' : 'error',
            ];
        }

        // Check params count
        if ($paramsCount > $this->maxParamsCount) {
            $violations[] = [
                'rule' => 'max_params_count',
                'message' => "Event has {$paramsCount} params, exceeds limit of {$this->maxParamsCount}",
                'severity' => $this->warnOnly ? 'warning' : 'error',
            ];
        }

        // Check individual param value lengths
        $oversizedParams = $this->getOversizedParamValues($event->params);
        if ($oversizedParams !== []) {
            foreach ($oversizedParams as $key => $length) {
                $violations[] = [
                    'rule' => 'max_param_value_length',
                    'message' => "Param '{$key}' value length ({$length}) exceeds limit ({$this->maxParamValueLength})",
                    'severity' => $this->warnOnly ? 'warning' : 'error',
                ];
            }
        }

        return [
            'valid' => $violations === [] || $this->warnOnly,
            'violations' => $violations,
        ];
    }

    /**
     * Sanitize an event to fit within performance budget limits.
     *
     * Trims oversized param values and removes excess params if needed.
     */
    public function sanitize(AnalyticsEvent $event): AnalyticsEvent
    {
        if (! $this->enabled) {
            return $event;
        }

        $params = $event->params;

        // Trim oversized param values
        foreach ($params as $key => $value) {
            if (is_string($value) && mb_strlen($value) > $this->maxParamValueLength) {
                $params[$key] = mb_substr($value, 0, $this->maxParamValueLength) . '...[truncated]';
            }
        }

        // Trim excess params if over count limit
        if (count($params) > $this->maxParamsCount) {
            $params = array_slice($params, 0, $this->maxParamsCount, true);
        }

        // Check payload size — drop params until within budget
        $maxIterations = $this->maxParamsCount;
        while (strlen(json_encode($params)) > $this->maxPayloadBytes && $maxIterations-- > 0) {
            array_pop($params);
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: $params,
            clientId: $event->clientId,
            userId: $event->userId,
        );
    }

    /**
     * Check if an event should be tracked based on performance budget.
     *
     * Returns true if within budget, false if should be dropped.
     */
    public function shouldTrack(AnalyticsEvent $event, ?string $sessionId = null, ?string $userId = null): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $validation = $this->validate($event);
        if (! $validation['valid']) {
            if ($this->warnOnly) {
                $this->logViolations($event->name, $validation['violations']);

                return true;
            }

            if ($this->dropOversizedEvents) {
                $this->logViolations($event->name, $validation['violations']);

                return false;
            }
        }

        return true;
    }

    /**
     * Get the current performance budget configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'enabled' => $this->enabled,
            'max_payload_bytes' => $this->maxPayloadBytes,
            'max_params_count' => $this->maxParamsCount,
            'max_events_per_session' => $this->maxEventsPerSession,
            'max_events_per_user_per_day' => $this->maxEventsPerUserPerDay,
            'max_events_per_page_view' => $this->maxEventsPerPageView,
            'max_param_value_length' => $this->maxParamValueLength,
            'drop_oversized' => $this->dropOversizedEvents,
            'warn_only' => $this->warnOnly,
        ];
    }

    /**
     * Get the payload size of an event in bytes.
     */
    public function getPayloadSize(AnalyticsEvent $event): int
    {
        return strlen(json_encode([
            'name' => $event->name,
            'params' => $event->params,
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
        ]));
    }

    /**
     * Get oversized parameter values.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, int> Map of param key to length
     */
    private function getOversizedParamValues(array $params): array
    {
        $oversized = [];

        foreach ($params as $key => $value) {
            if (is_string($value) && mb_strlen($value) > $this->maxParamValueLength) {
                $oversized[$key] = mb_strlen($value);
            }
        }

        return $oversized;
    }

    /**
     * Log performance budget violations.
     *
     * @param  list<array{rule: string, message: string, severity: string}>  $violations
     */
    private function logViolations(string $eventName, array $violations): void
    {
        foreach ($violations as $violation) {
            Log::debug("ZeroBoiler Analytics: Performance budget [{$violation['severity']}] for event '{$eventName}': {$violation['message']}");
        }
    }
}
