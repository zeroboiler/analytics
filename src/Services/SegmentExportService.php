<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Segment-compatible event export service.
 *
 * Converts ZeroBoiler analytics events to Segment's JSON format for
 * seamless migration, A/B testing between platforms, or dual-dispatch
 * to Segment alongside existing providers.
 *
 * Supports Segment's core event types:
 *   - Identify (userId + traits)
 *   - Track (event name + properties)
 *   - Page (page name + properties)
 *   - Group (groupId + traits)
 *   - Alias (previousId → newId)
 *
 * Output format matches Segment's HTTP API v2 JSON spec.
 *
 * @since 66.0.0
 *
 * @see https://segment.com/docs/connections/sources/catalog/libraries/server/http-api/
 *
 * @example
 *   $segment = new SegmentExportService();
 *   $payload = $segment->toIdentify($event);
 *   $payload = $segment->toTrack($event);
 *   $batch = $segment->toBatch([$event1, $event2]);
 */
final class SegmentExportService
{
    /**
     * Application write key (Segment API key).
     */
    private string $writeKey;

    /**
     * @param  string  $writeKey  Segment write key for payload validation
     */
    public function __construct(string $writeKey = ''){
        $this->writeKey = $writeKey;
    }

    /**
     * Convert an analytics event to a Segment Identify call.
     *
     * @param  AnalyticsEvent  $event  The analytics event (should be 'identify' type)
     * @return array{type: string, userId: string, traits: array<string, mixed>, timestamp: string|null, context: array<string, mixed>}
     */
    public function toIdentify(AnalyticsEvent $event): array
    {
        return [
            'type' => 'identify',
            'userId' => $event->userId ?? $event->params['user_id'] ?? '',
            'traits' => $this->extractTraits($event),
            'timestamp' => $event->timestamp?->format(\DateTimeInterface::ATOM),
            'context' => $this->buildContext($event),
        ];
    }

    /**
     * Convert an analytics event to a Segment Track call.
     *
     * Maps ZeroBoiler event name to Segment's event format with properties.
     * Automatically enriches with catalog metadata (category, provider mappings).
     *
     * @param  AnalyticsEvent  $event  The analytics event to convert
     * @return array{type: string, event: string, properties: array<string, mixed>, userId: string|null, anonymousId: string|null, timestamp: string|null, context: array<string, mixed>}
     */
    public function toTrack(AnalyticsEvent $event): array
    {
        $properties = $event->params;

        // Enrich with catalog metadata
        $catalogEntry = EventCatalog::get($event->name);

        if ($catalogEntry !== null) {
            $properties['_zb_category'] = $catalogEntry['category'] ?? null;
            $properties['_zb_ga4'] = $catalogEntry['ga4'] ?? null;
            $properties['_zb_meta'] = $catalogEntry['meta'] ?? null;
        }

        return [
            'type' => 'track',
            'event' => $this->mapEventName($event->name),
            'properties' => $properties,
            'userId' => $event->userId,
            'anonymousId' => $event->clientId,
            'timestamp' => $event->timestamp?->format(\DateTimeInterface::ATOM),
            'context' => $this->buildContext($event),
        ];
    }

    /**
     * Convert an analytics event to a Segment Page call.
     *
     * @param  AnalyticsEvent  $event  The analytics event (should be 'page_view' type)
     * @return array{type: string, name: string, properties: array<string, mixed>, userId: string|null, anonymousId: string|null, timestamp: string|null, context: array<string, mixed>}
     */
    public function toPage(AnalyticsEvent $event): array
    {
        return [
            'type' => 'page',
            'name' => (string) ($event->params['page_title'] ?? $event->name),
            'properties' => [
                'title' => (string) ($event->params['page_title'] ?? ''),
                'url' => (string) ($event->params['page_location'] ?? ''),
                'referrer' => (string) ($event->params['page_referrer'] ?? ''),
                'path' => $this->extractPath((string) ($event->params['page_location'] ?? '')),
                ...$event->params,
            ],
            'userId' => $event->userId,
            'anonymousId' => $event->clientId,
            'timestamp' => $event->timestamp?->format(\DateTimeInterface::ATOM),
            'context' => $this->buildContext($event),
        ];
    }

    /**
     * Convert an analytics event to a Segment Group call.
     *
     * @param  string  $groupId  Group/organization ID
     * @param  AnalyticsEvent  $event  The analytics event with group traits
     * @return array{type: string, groupId: string, traits: array<string, mixed>, userId: string|null, anonymousId: string|null, timestamp: string|null, context: array<string, mixed>}
     */
    public function toGroup(string $groupId, AnalyticsEvent $event): array
    {
        return [
            'type' => 'group',
            'groupId' => $groupId,
            'traits' => $this->extractTraits($event),
            'userId' => $event->userId,
            'anonymousId' => $event->clientId,
            'timestamp' => $event->timestamp?->format(\DateTimeInterface::ATOM),
            'context' => $this->buildContext($event),
        ];
    }

    /**
     * Convert an analytics event to a Segment Alias call (identity merging).
     *
     * @param  string  $previousId  Previous anonymous/user ID
     * @param  AnalyticsEvent  $event  Event with new user ID
     * @return array{type: string, previousId: string, userId: string|null, timestamp: string|null, context: array<string, mixed>}
     */
    public function toAlias(string $previousId, AnalyticsEvent $event): array
    {
        return [
            'type' => 'alias',
            'previousId' => $previousId,
            'userId' => $event->userId ?? $event->params['user_id'] ?? null,
            'timestamp' => $event->timestamp?->format(\DateTimeInterface::ATOM),
            'context' => $this->buildContext($event),
        ];
    }

    /**
     * Convert multiple events to a Segment batch payload.
     *
     * Automatically detects event type (identify, page, track) and
     * converts accordingly. Unknown types default to 'track'.
     *
     * @param  array<int, AnalyticsEvent>  $events  Events to batch
     * @return array{batch: list<array<string, mixed>>, sentAt: string, context: array<string, mixed>}
     */
    public function toBatch(array $events): array
    {
        $batch = [];

        foreach ($events as $event) {
            $segmentEvent = $this->autoConvert($event);

            if ($segmentEvent !== null) {
                $batch[] = $segmentEvent;
            }
        }

        return [
            'batch' => $batch,
            'sentAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'context' => [
                'library' => [
                    'name' => 'zeroboiler-analytics',
                    'version' => AnalyticsEvent::VERSION,
                ],
            ],
        ];
    }

    /**
     * Auto-detect event type and convert to appropriate Segment format.
     *
     * @param  AnalyticsEvent  $event  Event to convert
     * @return array<string, mixed>|null
     */
    public function autoConvert(AnalyticsEvent $event): ?array
    {
        return match ($event->name) {
            'identify' => $this->toIdentify($event),
            'page_view' => $this->toPage($event),
            default => $this->toTrack($event),
        };
    }

    /**
     * Build a full Segment HTTP API batch request payload.
     *
     * Ready to POST to https://api.segment.io/v1/batch
     *
     * @param  array<int, AnalyticsEvent>  $events  Events to export
     * @param  string  $writeKey  Override write key
     * @return array{batch: list<array<string, mixed>>, sentAt: string, context: array<string, mixed>, writeKey: string}
     */
    public function buildBatchRequest(array $events, string $writeKey = ''): array
    {
        $payload = $this->toBatch($events);
        $payload['writeKey'] = $writeKey !== '' ? $writeKey : $this->writeKey;

        return $payload;
    }

    // ── Event Name Mapping ──────────────────────────────────────────

    /**
     * Map ZeroBoiler event name to Segment-compatible event name.
     *
     * Uses snake_case conversion for catalog events, preserves custom names.
     *
     * @param  string  $name  ZeroBoiler event name
     */
    private function mapEventName(string $name): string
    {
        // Already matches common patterns
        $segmentMap = [
            'sign_up' => 'Signed Up',
            'login' => 'Logged In',
            'start_trial' => 'Trial Started',
            'trial_converted' => 'Trial Converted',
            'subscribe' => 'Subscription Created',
            'plan_upgrade' => 'Plan Upgraded',
            'cancellation' => 'Subscription Cancelled',
            'view_item' => 'Product Viewed',
            'add_to_cart' => 'Product Added',
            'begin_checkout' => 'Checkout Started',
            'purchase' => 'Order Completed',
            'refund' => 'Order Refunded',
            'search' => 'Products Searched',
            'share' => 'Product Shared',
            'form_start' => 'Form Started',
            'form_submit' => 'Form Submitted',
            'scroll_depth' => 'Page Scrolled',
            'error' => 'Error Occurred',
        ];

        return $segmentMap[$name] ?? $this->titleCase($name);
    }

    /**
     * Convert snake_case to Title Case for Segment event names.
     */
    private function titleCase(string $input): string
    {
        return str_replace('_', ' ', ucwords($input));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Extract user traits from event params.
     *
     * Filters out internal params and returns only user-facing traits.
     *
     * @param  AnalyticsEvent  $event  Source event
     * @return array<string, mixed>
     */
    private function extractTraits(AnalyticsEvent $event): array
    {
        $traits = $event->params;

        $internalKeys = [
            'user_id', 'client_id', 'session_id', 'tracking_id',
            'timestamp', 'priority', 'source', '_zb_category', '_zb_ga4', '_zb_meta',
        ];

        foreach ($internalKeys as $key) {
            unset($traits[$key]);
        }

        return $traits;
    }

    /**
     * Build the Segment context object.
     *
     * @param  AnalyticsEvent  $event  Source event
     * @return array<string, mixed>
     */
    private function buildContext(AnalyticsEvent $event): array
    {
        $context = [
            'library' => [
                'name' => 'zeroboiler-analytics',
                'version' => AnalyticsEvent::VERSION,
            ],
        ];

        if ($event->source !== null) {
            $context['source'] = $event->source;
        }

        if ($event->priority !== null) {
            $context['_zb_priority'] = $event->priority;
        }

        return $context;
    }

    /**
     * Extract path from a full URL.
     */
    private function extractPath(string $url): string
    {
        $parsed = parse_url($url);

        return $parsed['path'] ?? '/';
    }
}
