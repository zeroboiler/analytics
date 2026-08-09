<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Privacy-first data minimization service for GDPR Article 5(1)(c) compliance.
 *
 * Enforces data minimization principles by stripping unnecessary parameters
 * from events before dispatch. Unlike PII sanitization (which masks specific
 * fields), data minimization reduces the overall data footprint by removing
 * optional parameters that aren't required for the analytics use case.
 *
 * Supports per-event, per-category, and global parameter allowlists.
 * When an allowlist is configured, only listed parameters are retained;
 * all others are stripped. This is more restrictive than blocklist-based
 * PII sanitization and is recommended for privacy-first SaaS deployments.
 *
 * Config-driven via `zeroboiler.analytics.data_minimization`.
 *
 * @see \ZeroBoiler\Analytics\Middleware\PiiSanitizationMiddleware
 * @see \ZeroBoiler\Analytics\Services\AnalyticsAnonymizationService
 */
final class DataMinimizationService
{
    private bool $enabled;

    /** @var list<string> Global parameter allowlist (empty = allow all) */
    private array $globalAllowlist;

    /** @var array<string, list<string>> Per-event allowlists (event_name => [param, ...]) */
    private array $eventAllowlists;

    /** @var array<string, list<string>> Per-category allowlists (category => [param, ...]) */
    private array $categoryAllowlists;

    /** @var list<string> Parameters to always strip regardless of allowlists */
    private array $stripParams;

    /** @var bool Whether to log stripped parameters for audit */
    private bool $auditLog;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
    {
        $dmConfig = $config->get('zeroboiler.analytics.data_minimization', []);
        /** @var array{enabled?: bool, global_allowlist?: list<string>, event_allowlists?: array<string, list<string>>, category_allowlists?: array<string, list<string>>, strip_params?: list<string>, audit_log?: bool} $dmConfig */

        $this->enabled = (bool) ($dmConfig['enabled'] ?? false);
        $this->globalAllowlist = (array) ($dmConfig['global_allowlist'] ?? []);
        $this->eventAllowlists = (array) ($dmConfig['event_allowlists'] ?? []);
        $this->categoryAllowlists = (array) ($dmConfig['category_allowlists'] ?? []);
        $this->stripParams = (array) ($dmConfig['strip_params'] ?? ['user_agent', 'ip_address', 'raw_query']);
        $this->auditLog = (bool) ($dmConfig['audit_log'] ?? false);
    }

    /**
     * Minimize an event by removing unnecessary parameters.
     *
     * Applies the following rules in order:
     * 1. Strip globally-blocked parameters (strip_params)
     * 2. Apply per-event allowlist (if configured for this event)
     * 3. Apply per-category allowlist (if configured and no event allowlist)
     * 4. Apply global allowlist (if configured)
     *
     * Returns a new AnalyticsEvent with minimized parameters.
     * The original event is never modified.
     */
    public function minimize(AnalyticsEvent $event): AnalyticsEvent
    {
        if (! $this->enabled) {
            return $event;
        }

        $params = $event->params;
        $eventName = $event->name;

        // Step 1: Strip globally-blocked parameters
        foreach ($this->stripParams as $key) {
            unset($params[$key]);
        }

        // Step 2: Apply per-event allowlist
        $eventAllowlist = $this->eventAllowlists[$eventName] ?? null;
        if ($eventAllowlist !== null && $eventAllowlist !== []) {
            $params = $this->applyAllowlist($params, $eventAllowlist);

            return new AnalyticsEvent(
                name: $event->name,
                params: $params,
                clientId: $event->clientId,
                userId: $event->userId,
                timestamp: $event->timestamp,
            );
        }

        // Step 3: Apply per-category allowlist
        $category = \ZeroBoiler\Analytics\Events\EventCatalog::getCategory($eventName);
        if ($category !== null && isset($this->categoryAllowlists[$category])) {
            $categoryAllowlist = $this->categoryAllowlists[$category];
            if ($categoryAllowlist !== []) {
                $params = $this->applyAllowlist($params, $categoryAllowlist);

                return new AnalyticsEvent(
                    name: $event->name,
                    params: $params,
                    clientId: $event->clientId,
                    userId: $event->userId,
                    timestamp: $event->timestamp,
                );
            }
        }

        // Step 4: Apply global allowlist
        if ($this->globalAllowlist !== []) {
            $params = $this->applyAllowlist($params, $this->globalAllowlist);
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: $params,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
        );
    }

    /**
     * Filter parameters to only include those in the allowlist.
     *
     * Parameters prefixed with '_' (internal/system params) are always preserved
     * unless explicitly listed in the strip_params config.
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>  $allowlist
     * @return array<string, mixed>
     */
    private function applyAllowlist(array $params, array $allowlist): array
    {
        $result = [];

        foreach ($params as $key => $value) {
            // Preserve internal metadata params (_source, _timestamp, etc.)
            if (str_starts_with((string) $key, '_')) {
                $result[$key] = $value;
                continue;
            }

            if (in_array((string) $key, $allowlist, true)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get the list of parameters that would be stripped from an event.
     *
     * Useful for audit logging and debugging without actually modifying the event.
     *
     * @param  AnalyticsEvent  $event
     * @return list<string> Parameter keys that would be removed
     */
    public function previewStripped(AnalyticsEvent $event): array
    {
        if (! $this->enabled) {
            return [];
        }

        $originalKeys = array_keys($event->params);
        $minimized = $this->minimize($event);
        $minimizedKeys = array_keys($minimized->params);

        return array_values(array_diff($originalKeys, $minimizedKeys));
    }

    /**
     * Check if the data minimization service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if audit logging is enabled.
     */
    public function isAuditLogEnabled(): bool
    {
        return $this->auditLog;
    }

    /**
     * Get the global allowlist.
     *
     * @return list<string>
     */
    public function getGlobalAllowlist(): array
    {
        return $this->globalAllowlist;
    }

    /**
     * Get the globally-blocked parameter list.
     *
     * @return list<string>
     */
    public function getStripParams(): array
    {
        return $this->stripParams;
    }

    /**
     * Get the configured event allowlists.
     *
     * @return array<string, list<string>>
     */
    public function getEventAllowlists(): array
    {
        return $this->eventAllowlists;
    }

    /**
     * Get the configured category allowlists.
     *
     * @return array<string, list<string>>
     */
    public function getCategoryAllowlists(): array
    {
        return $this->categoryAllowlists;
    }

    /**
     * Get a summary of the minimization configuration.
     *
     * @return array{enabled: bool, has_global_allowlist: bool, event_allowlist_count: int, category_allowlist_count: int, strip_params_count: int, audit_log: bool}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'has_global_allowlist' => $this->globalAllowlist !== [],
            'event_allowlist_count' => count($this->eventAllowlists),
            'category_allowlist_count' => count($this->categoryAllowlists),
            'strip_params_count' => count($this->stripParams),
            'audit_log' => $this->auditLog,
        ];
    }
}
