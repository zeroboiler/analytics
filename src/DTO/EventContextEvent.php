<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Fully-qualified analytics event with rich context envelope.
 *
 * Wraps a base AnalyticsEvent with session, device, geolocation, identity,
 * UTM attribution, referrer, and consent context. Designed for SaaS
 * observability dashboards and comprehensive audit trails.
 *
 * Use EventEnvelopeService::build() to construct instances automatically
 * from request context, or create manually for server-side tracking.
 *
 * @see \ZeroBoiler\Analytics\Services\EventEnvelopeService
 *
 * @since 1.0.0
 */
final readonly class EventContextEvent
{
    /**
     * @param  AnalyticsEvent  $event  The base analytics event
     * @param  array<string, mixed>  $session  Session context (id, duration, events_count, is_new, started_at)
     * @param  array<string, mixed>  $device  Device context (browser, browser_version, os, os_version, device_type, device_brand)
     * @param  array<string, mixed>  $geo  Geolocation context (country, region, city, strategy)
     * @param  array<string, mixed>  $identity  Identity context (user_id, client_id, anonymous_id, is_authenticated)
     * @param  array<string, mixed>  $utm  UTM attribution context (source, medium, campaign, term, content, first_touch, is_first_touch)
     * @param  array<string, mixed>  $referrer  Referrer context (url, domain, is_internal, is_search_engine)
     * @param  array<string, mixed>  $consent  Consent context (analytics_granted, ad_granted, purpose_grants, consent_source)
     * @param  array<string, mixed>  $metadata  Additional metadata (source_tag, version, timestamp_iso, environment, tenant_id)
     */
    public function __construct(
        public AnalyticsEvent $event,
        public array $session = [],
        public array $device = [],
        public array $geo = [],
        public array $identity = [],
        public array $utm = [],
        public array $referrer = [],
        public array $consent = [],
        public array $metadata = [],
    ){}

    /**
     * Create from a base AnalyticsEvent with optional context arrays.
     *
     * Shorthand for constructing a fully-qualified event.
     *
     * @param  AnalyticsEvent  $event  Base analytics event
     * @param  array<string, mixed>  $context  Merged context data (session, device, geo, etc.)
     */
    public static function fromEvent(AnalyticsEvent $event, array $context = []): self
    {
        return new self(
            event: $event,
            session: $context['session'] ?? [],
            device: $context['device'] ?? [],
            geo: $context['geo'] ?? [],
            identity: $context['identity'] ?? [],
            utm: $context['utm'] ?? [],
            referrer: $context['referrer'] ?? [],
            consent: $context['consent'] ?? [],
            metadata: $context['metadata'] ?? [],
        );
    }

    /**
     * Convert to a flat array representation for serialization.
     *
     * Flattens all context into the base event params with
     * underscore-prefixed keys to avoid collision with user params.
     *
     * @return array{event: array<string, mixed>, session: array<string, mixed>, device: array<string, mixed>, geo: array<string, mixed>, identity: array<string, mixed>, utm: array<string, mixed>, referrer: array<string, mixed>, consent: array<string, mixed>, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'event' => [
                'name' => $this->event->name,
                'params' => $this->event->params,
                'client_id' => $this->event->clientId,
                'user_id' => $this->event->userId,
                'timestamp' => $this->event->timestamp?->getTimestamp(),
            ],
            'session' => $this->session,
            'device' => $this->device,
            'geo' => $this->geo,
            'identity' => $this->identity,
            'utm' => $this->utm,
            'referrer' => $this->referrer,
            'consent' => $this->consent,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get a flattened representation merged into event params.
     *
     * Context keys are prefixed with underscore to avoid collision.
     * Useful for dispatching to providers that accept flat params.
     *
     * @return array<string, mixed>
     */
    public function flattenedParams(): array
    {
        $params = $this->event->params;
        $flatten = fn (string $prefix, array $data): array => array_filter(
            array_combine(
                array_map(static fn (string $key): string => "_{$prefix}_{$key}", array_keys($data)),
                array_values($data),
            ),
            static fn (mixed $v): bool => $v !== null && $v !== '',
        );

        return array_merge($params, ...[
            $flatten('session', $this->session),
            $flatten('device', $this->device),
            $flatten('geo', $this->geo),
            $flatten('identity', $this->identity),
            $flatten('utm', $this->utm),
            $flatten('referrer', $this->referrer),
            $flatten('consent', $this->consent),
            $flatten('meta', $this->metadata),
        ]);
    }

    /**
     * Check if a specific context section is populated.
     */
    public function hasContext(string $section): bool
    {
        return ! empty($this->{$section});
    }

    /**
     * Check if the event has full identity context.
     */
    public function hasFullIdentity(): bool
    {
        return ! empty($this->identity['user_id']) && ! empty($this->identity['client_id']);
    }
}
