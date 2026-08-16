<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Infrastructure;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Static catalog of all infrastructure/DevOps analytics events.
 *
 * Provides a central registry for infrastructure event names, classes, and metadata.
 * Covers feature flags, experiments, SRE/error budgets, incidents, deployments,
 * maintenance windows, and pipeline reliability.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, posthog: string, plausible: string|null, mixpanel: string, amplitude: string, tiktok: string|null, linkedin: string|null}
 *
 * @since 46.0.0
 */
final class InfrastructureEvents
{
    /** @var array<string, EventEntry> */
    private static array $catalog = [];

    /**
     * Build the event catalog (lazy initialization).
     *
     * @return array<string, EventEntry>
     */
    private static function catalog(): array
    {
        if (self::$catalog !== []) {
            return self::$catalog;
        }

        self::$catalog = [
            'feature_flag_evaluated' => [
                'name' => 'feature_flag_evaluated',
                'class' => FeatureFlagEvaluatedEvent::class,
                'ga4' => 'feature_flag_evaluated',
                'meta' => null,
                'posthog' => 'feature_flag_evaluated',
                'plausible' => null,
                'mixpanel' => 'Feature Flag Evaluated',
                'amplitude' => 'Feature Flag Evaluated',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'experiment_exposed' => [
                'name' => 'experiment_exposed',
                'class' => ExperimentExposedEvent::class,
                'ga4' => 'experiment_exposed',
                'meta' => null,
                'posthog' => '$experiment_exposed',
                'plausible' => null,
                'mixpanel' => 'Experiment Exposed',
                'amplitude' => 'Experiment Exposed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'error_budget_burned' => [
                'name' => 'error_budget_burned',
                'class' => ErrorBudgetBurnedEvent::class,
                'ga4' => 'error_budget_burned',
                'meta' => null,
                'posthog' => 'error_budget_burned',
                'plausible' => null,
                'mixpanel' => 'Error Budget Burned',
                'amplitude' => 'Error Budget Burned',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'slo_breach' => [
                'name' => 'slo_breach',
                'class' => SLOBreachEvent::class,
                'ga4' => 'slo_breach',
                'meta' => null,
                'posthog' => 'slo_breach',
                'plausible' => null,
                'mixpanel' => 'SLO Breach',
                'amplitude' => 'SLO Breach',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'deployment_rolled_back' => [
                'name' => 'deployment_rolled_back',
                'class' => DeploymentRolledBackEvent::class,
                'ga4' => 'deployment_rolled_back',
                'meta' => null,
                'posthog' => 'deployment_rolled_back',
                'plausible' => null,
                'mixpanel' => 'Deployment Rolled Back',
                'amplitude' => 'Deployment Rolled Back',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'incident_started' => [
                'name' => 'incident_started',
                'class' => IncidentStartedEvent::class,
                'ga4' => 'incident_started',
                'meta' => null,
                'posthog' => 'incident_started',
                'plausible' => null,
                'mixpanel' => 'Incident Started',
                'amplitude' => 'Incident Started',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'incident_resolved' => [
                'name' => 'incident_resolved',
                'class' => IncidentResolvedEvent::class,
                'ga4' => 'incident_resolved',
                'meta' => null,
                'posthog' => 'incident_resolved',
                'plausible' => null,
                'mixpanel' => 'Incident Resolved',
                'amplitude' => 'Incident Resolved',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'maintenance_started' => [
                'name' => 'maintenance_started',
                'class' => MaintenanceStartedEvent::class,
                'ga4' => 'maintenance_started',
                'meta' => null,
                'posthog' => 'maintenance_started',
                'plausible' => null,
                'mixpanel' => 'Maintenance Started',
                'amplitude' => 'Maintenance Started',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'maintenance_ended' => [
                'name' => 'maintenance_ended',
                'class' => MaintenanceEndedEvent::class,
                'ga4' => 'maintenance_ended',
                'meta' => null,
                'posthog' => 'maintenance_ended',
                'plausible' => null,
                'mixpanel' => 'Maintenance Ended',
                'amplitude' => 'Maintenance Ended',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'pipeline_failure' => [
                'name' => 'pipeline_failure',
                'class' => PipelineFailureEvent::class,
                'ga4' => 'pipeline_failure',
                'meta' => null,
                'posthog' => 'pipeline_failure',
                'plausible' => null,
                'mixpanel' => 'Pipeline Failure',
                'amplitude' => 'Pipeline Failure',
                'tiktok' => null,
                'linkedin' => null,
            ],
        ];

        return self::$catalog;
    }

    /**
     * Get all infrastructure events.
     *
     * @return array<string, EventEntry>
     */
    public static function all(): array
    {
        return self::catalog();
    }

    /**
     * Get all event names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Get all GA4 event names.
     *
     * @return list<string>
     */
    public static function ga4Names(): array
    {
        return array_values(array_map(
            fn (array $entry): string => $entry['ga4'],
            self::catalog(),
        ));
    }

    /**
     * Get all Meta Pixel event names (excluding nulls).
     *
     * @return list<string>
     */
    public static function metaNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['meta'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all PostHog event names.
     *
     * @return list<string>
     */
    public static function posthogNames(): array
    {
        return array_values(array_map(
            fn (array $entry): string => $entry['posthog'],
            self::catalog(),
        ));
    }

    /**
     * Get all Plausible event names (excluding nulls).
     *
     * @return list<string>
     */
    public static function plausibleNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['plausible'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all Mixpanel event names.
     *
     * @return list<string>
     */
    public static function mixpanelNames(): array
    {
        return array_values(array_map(
            fn (array $entry): string => $entry['mixpanel'],
            self::catalog(),
        ));
    }

    /**
     * Get all Amplitude event names.
     *
     * @return list<string>
     */
    public static function amplitudeNames(): array
    {
        return array_values(array_map(
            fn (array $entry): string => $entry['amplitude'],
            self::catalog(),
        ));
    }

    /**
     * Get all TikTok event names in this category (non-null only).
     *
     * @return list<string>
     */
    public static function tiktokNames(): array
    {
        return [];
    }

    /**
     * Get all LinkedIn event names in this category (non-null only).
     *
     * @return list<string>
     */
    public static function linkedinNames(): array
    {
        return [];
    }

    /**
     * Check if an event name exists in this catalog.
     */
    public static function has(string $name): bool
    {
        return isset(self::catalog()[$name]);
    }

    /**
     * Get a specific event entry.
     *
     * @return EventEntry|null
     */
    public static function get(string $name): ?array
    {
        return self::catalog()[$name] ?? null;
    }

    /**
     * Get the event class for a given event name.
     *
     * @return class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>|null
     */
    public static function classFor(string $name): ?string
    {
        return self::catalog()[$name]['class'] ?? null;
    }

    /**
     * Get the total number of events in this catalog.
     */
    public static function count(): int
    {
        return count(self::catalog());
    }

    /**
     * Get the category name for this catalog.
     */
    public static function category(): string
    {
        return 'infrastructure';
    }

    /**
     * Build a typed feature_flag_evaluated event.
     *
     * @param  array{flag_name?: string, variant?: string, enabled?: bool}  $params
     * @return AnalyticsEvent
     */
    public static function featureFlagEvaluated(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'feature_flag_evaluated', params: $params, category: 'infrastructure');
    }

    /**
     * Build a typed experiment_exposed event.
     *
     * @param  array{experiment_name?: string, variant?: string, cohort?: string}  $params
     * @return AnalyticsEvent
     */
    public static function experimentExposed(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'experiment_exposed', params: $params, category: 'infrastructure');
    }

    /**
     * Build a typed error_budget_burned event.
     *
     * @param  array{service?: string, budget_consumed?: float, window?: string}  $params
     * @return AnalyticsEvent
     */
    public static function errorBudgetBurned(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'error_budget_burned', params: $params, category: 'infrastructure');
    }

    /**
     * Build a typed slo_breach event.
     *
     * @param  array{slo_name?: string, target?: float, actual?: float}  $params
     * @return AnalyticsEvent
     */
    public static function sloBreach(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'slo_breach', params: $params, category: 'infrastructure');
    }

    /**
     * Build a typed deployment_rolled_back event.
     *
     * @param  array{version?: string, reason?: string, environment?: string}  $params
     * @return AnalyticsEvent
     */
    public static function deploymentRolledBack(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'deployment_rolled_back', params: $params, category: 'infrastructure');
    }

    /**
     * Build a typed incident_started event.
     *
     * @param  array{incident_id?: string, severity?: string, description?: string}  $params
     * @return AnalyticsEvent
     */
    public static function incidentStarted(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'incident_started', params: $params, category: 'infrastructure');
    }

    /**
     * Build a typed incident_resolved event.
     *
     * @param  array{incident_id?: string, duration_seconds?: int, severity?: string}  $params
     * @return AnalyticsEvent
     */
    public static function incidentResolved(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'incident_resolved', params: $params, category: 'infrastructure');
    }

    /**
     * Build a typed maintenance_started event.
     *
     * @param  array{window?: string, reason?: string, estimated_duration?: int}  $params
     * @return AnalyticsEvent
     */
    public static function maintenanceStarted(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'maintenance_started', params: $params, category: 'infrastructure');
    }

    /**
     * Build a typed maintenance_ended event.
     *
     * @param  array{window?: string, duration_seconds?: int, success?: bool}  $params
     * @return AnalyticsEvent
     */
    public static function maintenanceEnded(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'maintenance_ended', params: $params, category: 'infrastructure');
    }

    /**
     * Build a typed pipeline_failure event.
     *
     * @param  array{pipeline?: string, stage?: string, error?: string}  $params
     * @return AnalyticsEvent
     */
    public static function pipelineFailure(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'pipeline_failure', params: $params, category: 'infrastructure');
    }

    /**
     * Build a typed AnalyticsEvent from any catalog entry by name.
     *
     * Generic factory — validates the event name against the catalog.
     *
     * @param  array<string, mixed>  $params
     * @return AnalyticsEvent
     *
     * @throws \InvalidArgumentException
     */
    public static function build(string $name, array $params = []): AnalyticsEvent
    {
        if (!self::has($name)) {
            throw new \InvalidArgumentException(
                "Unknown infrastructure event: {$name}. Available: ".implode(', ', self::names()),
            );
        }

        return new AnalyticsEvent(name: $name, params: $params, category: 'infrastructure');
    }
}
