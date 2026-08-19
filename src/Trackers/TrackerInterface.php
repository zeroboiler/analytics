<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Trackers;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;

/**
 * Contract for all analytics tracker implementations.
 *
 * Every provider (GA4, GTM, Meta, Plausible, PostHog, Mixpanel, Amplitude,
 * TikTok, LinkedIn, Webhook) must implement this interface.
 *
 * @since 1.0.0
 */
interface TrackerInterface
{
    /**
     * Track an analytics event.
     */
    public function track(AnalyticsEvent $event): void;

    /**
     * Check if the tracker is enabled and properly configured.
     */
    public function isEnabled(): bool;

    /**
     * Get the script tags for head section.
     */
    public function headScripts(): string;

    /**
     * Get the script tags for body section.
     */
    public function bodyScripts(): string;

    /**
     * Update the tracker's consent state.
     */
    public function setConsent(ConsentState $state): void;

    /**
     * Get the current consent state applied to this tracker.
     */
    public function getConsent(): ConsentState;

    /**
     * Track multiple analytics events in a single batch dispatch.
     *
     * Providers that support batch APIs (GA4 Measurement Protocol, Meta CAPI,
     * PostHog /batch, Plausible /api/v2/event batch) will send all events
     * in one HTTP request. Providers without native batch support fall back
     * to sequential track() calls.
     *
     * @param  list<AnalyticsEvent>  $events  Events to dispatch
     * @return int  Number of events successfully dispatched
     *
     * @since 243.0.0
     */
    public function trackBatch(array $events): int;

    /**
     * Identify a user with this provider.
     *
     * Associates user traits with a user ID in the provider's identity system.
     * Providers with native identity APIs (Amplitude, PostHog, Mixpanel) will
     * call their server-side identify endpoint. Providers without native identity
     * support (GTM, Plausible) will no-op gracefully.
     *
     * @param  string  $userId  The authenticated user ID
     * @param  array<string, mixed>  $traits  User properties (name, email_hash, plan, etc.)
     *
     * @since 258.0.0
     */
    public function identify(string $userId, array $traits = []): void;

    /**
     * Get the machine-readable provider name for this tracker.
     *
     * Used by AnalyticsManager for targeted dispatch, observability logging,
     * error routing, and provider-specific health checks.
     *
     * Must return a lowercase snake_case string matching the config key
     * and AnalyticsManager property name (e.g. 'ga4', 'posthog', 'meta').
     *
     * @since 258.0.0
     */
    public function providerName(): string;
}
