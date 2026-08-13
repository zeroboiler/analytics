<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Infrastructure;

/**
 * Static catalog of all infrastructure/DevOps analytics events.
 *
 * Provides a central registry for infrastructure event names, classes, and metadata.
 * Covers feature flags, experiments, SRE/error budgets, incidents, deployments,
 * maintenance windows, and pipeline reliability.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, posthog: string, plausible: string|null, mixpanel: string, amplitude: string}
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
}
