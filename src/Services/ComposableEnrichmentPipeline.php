<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Config-driven, ordered event enrichment pipeline.
 *
 * Provides a composable system for enriching analytics events before dispatch.
 * Each enrichment stage is a named step that can add, transform, or remove
 * event parameters in a defined order.
 *
 * Built-in enrichment stages:
 * - `utm_source` — Attach UTM parameters from the current request
 * - `device_context` — Attach user agent, IP, locale, screen info
 * - `session_context` — Attach session ID, session duration, page count
 * - `timestamp_normalize` — Normalize timestamp to UTC ISO 8601
 * - `pii_scrub` — Remove or hash personally identifiable information
 * - `tenant_tag` — Attach tenant ID for multi-tenant isolation
 * - `identity_link` — Attach client_id ↔ user_id mapping data
 * - `cost_tag` — Attach estimated dispatch cost for budget tracking
 * - `source_tag` — Attach event source origin metadata
 * - `consent_filter` — Filter parameters based on consent state
 *
 * Custom stages can be registered via config using callable references.
 *
 * Configuration is read from `zeroboiler.analytics.enrichment_pipeline`.
 *
 * @since 21.0.0
 */
final class ComposableEnrichmentPipeline
{
    /** @var array<int, array{stage: string, enabled: bool, priority: int, config: array<string, mixed>}> */
    private array $stages = [];

    /** @var array<string, callable(AnalyticsEvent, array<string, mixed>): AnalyticsEvent> */
    private array $customHandlers = [];

    private bool $enabled;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config){
        $pipelineConfig = $config->get('zeroboiler.analytics.enrichment_pipeline', []);
        /** @var array{enabled?: bool, stages?: list<array{stage: string, enabled?: bool, priority?: int, config?: array<string, mixed>}>} $pipelineConfig */

        $this->enabled = (bool) ($pipelineConfig['enabled'] ?? true);

        $stages = $pipelineConfig['stages'] ?? $this->defaultStages();
        foreach ($stages as $stageDef) {
            $this->stages[] = [
                'stage' => (string) ($stageDef['stage'] ?? ''),
                'enabled' => (bool) ($stageDef['enabled'] ?? true),
                'priority' => (int) ($stageDef['priority'] ?? 0),
                'config' => (array) ($stageDef['config'] ?? []),
            ];
        }

        usort($this->stages, fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);
    }

    /**
     * Process an event through all enabled enrichment stages.
     *
     * Returns the enriched event. If the pipeline is disabled or no stages
     * are configured, returns the event unchanged.
     */
    public function enrich(AnalyticsEvent $event): AnalyticsEvent
    {
        if (! $this->enabled) {
            return $event;
        }

        $current = $event;

        foreach ($this->stages as $stageDef) {
            if (! $stageDef['enabled']) {
                continue;
            }

            $current = $this->processStage($current, $stageDef['stage'], $stageDef['config']);
        }

        return $current;
    }

    /**
     * Process a batch of events through the enrichment pipeline.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return list<AnalyticsEvent>
     */
    public function enrichBatch(array $events): array
    {
        if (! $this->enabled) {
            return $events;
        }

        return array_map(
            fn (AnalyticsEvent $event): AnalyticsEvent => $this->enrich($event),
            $events,
        );
    }

    /**
     * Register a custom enrichment handler.
     *
     * @param  callable(AnalyticsEvent, array<string, mixed>): AnalyticsEvent  $handler
     */
    public function registerHandler(string $stageName, callable $handler): void
    {
        $this->customHandlers[$stageName] = $handler;
    }

    /**
     * Get all configured stages.
     *
     * @return list<array{stage: string, enabled: bool, priority: int, config: array<string, mixed>}>
     */
    public function stages(): array
    {
        return $this->stages;
    }

    /**
     * Get the list of enabled stage names.
     *
     * @return list<string>
     */
    public function enabledStages(): array
    {
        return array_values(array_map(
            fn (array $s): string => $s['stage'],
            array_filter($this->stages, fn (array $s): bool => $s['enabled']),
        ));
    }

    /**
     * Check if the pipeline is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Process a single enrichment stage.
     *
     * @param  array<string, mixed>  $config
     */
    private function processStage(AnalyticsEvent $event, string $stageName, array $config): AnalyticsEvent
    {
        if (isset($this->customHandlers[$stageName])) {
            return ($this->customHandlers[$stageName])($event, $config);
        }

        return match ($stageName) {
            'utm_source' => $this->enrichUtmSource($event, $config),
            'device_context' => $this->enrichDeviceContext($event, $config),
            'session_context' => $this->enrichSessionContext($event, $config),
            'timestamp_normalize' => $this->enrichTimestampNormalize($event, $config),
            'pii_scrub' => $this->enrichPiiScrub($event, $config),
            'tenant_tag' => $this->enrichTenantTag($event, $config),
            'identity_link' => $this->enrichIdentityLink($event, $config),
            'cost_tag' => $this->enrichCostTag($event, $config),
            'source_tag' => $this->enrichSourceTag($event, $config),
            'consent_filter' => $this->enrichConsentFilter($event, $config),
            default => $event,
        };
    }

    /**
     * Enrich with UTM parameters from the current request.
     *
     * @param  array<string, mixed>  $config
     */
    private function enrichUtmSource(AnalyticsEvent $event, array $config): AnalyticsEvent
    {
        $request = request();
        if ($request === null) {
            return $event;
        }

        $utmParams = [];
        $utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

        foreach ($utmKeys as $key) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '') {
                $utmParams[$key] = $value;
            }
        }

        if ($utmParams === []) {
            return $event;
        }

        $prefix = (string) ($config['param_prefix'] ?? '');

        $enrichedParams = $event->params;
        foreach ($utmParams as $key => $value) {
            $enrichedParams[$prefix . $key] = $value;
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: $enrichedParams,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    /**
     * Enrich with device context from the current request.
     *
     * @param  array<string, mixed>  $config
     */
    private function enrichDeviceContext(AnalyticsEvent $event, array $config): AnalyticsEvent
    {
        $request = request();
        if ($request === null) {
            return $event;
        }

        $includeKeys = $config['include'] ?? ['user_agent', 'ip', 'locale'];
        $prefix = (string) ($config['param_prefix'] ?? '_device_');

        $deviceParams = [];

        if (in_array('user_agent', $includeKeys, true)) {
            $ua = $request->userAgent();
            if (is_string($ua) && $ua !== '') {
                $deviceParams[$prefix . 'user_agent'] = $ua;
            }
        }

        if (in_array('ip', $includeKeys, true)) {
            $deviceParams[$prefix . 'ip'] = $request->ip();
        }

        if (in_array('locale', $includeKeys, true)) {
            $deviceParams[$prefix . 'locale'] = $request->locale();
        }

        if (in_array('screen_width', $includeKeys, true)) {
            $sw = $request->query('screen_width');
            if (is_numeric($sw)) {
                $deviceParams[$prefix . 'screen_width'] = (int) $sw;
            }
        }

        if (in_array('screen_height', $includeKeys, true)) {
            $sh = $request->query('screen_height');
            if (is_numeric($sh)) {
                $deviceParams[$prefix . 'screen_height'] = (int) $sh;
            }
        }

        if ($deviceParams === []) {
            return $event;
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, $deviceParams),
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    /**
     * Enrich with session context.
     *
     * @param  array<string, mixed>  $config
     */
    private function enrichSessionContext(AnalyticsEvent $event, array $config): AnalyticsEvent
    {
        $request = request();
        if ($request === null || ! $request->hasSession()) {
            return $event;
        }

        $prefix = (string) ($config['param_prefix'] ?? '_session_');
        $session = $request->session();

        $sessionParams = [
            $prefix . 'id' => $session->getId(),
        ];

        $pageCount = (int) ($session->get('_zb_page_count', 0)) + 1;
        $session->put('_zb_page_count', $pageCount);
        $sessionParams[$prefix . 'page_count'] = $pageCount;

        // Session duration (approximate)
        $startedAt = $session->get('_zb_session_started');
        if ($startedAt instanceof \DateTimeImmutable) {
            $duration = time() - $startedAt->getTimestamp();
            $sessionParams[$prefix . 'duration_seconds'] = $duration;
        } else {
            $session->put('_zb_session_started', new \DateTimeImmutable());
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, $sessionParams),
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    /**
     * Normalize timestamp to UTC ISO 8601 string.
     *
     * @param  array<string, mixed>  $config
     */
    private function enrichTimestampNormalize(AnalyticsEvent $event, array $config): AnalyticsEvent
    {
        $paramName = (string) ($config['param_name'] ?? '_timestamp_iso');

        $timestamp = $event->timestamp ?? new \DateTimeImmutable();
        if ($timestamp->getTimezone()->getName() !== 'UTC') {
            $timestamp = $timestamp->setTimezone(new \DateTimeZone('UTC'));
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, [
                $paramName => $timestamp->format('Y-m-d\TH:i:s\Z'),
            ]),
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    /**
     * Scrub PII from event parameters.
     *
     * @param  array<string, mixed>  $config
     */
    private function enrichPiiScrub(AnalyticsEvent $event, array $config): AnalyticsEvent
    {
        $piiKeys = $config['pii_keys'] ?? ['email', 'password', 'phone', 'credit_card', 'ssn', 'social_security'];
        $mode = (string) ($config['mode'] ?? 'hash'); // 'hash' or 'remove'

        $params = $event->params;
        $scrubbed = false;

        foreach ($params as $key => $value) {
            foreach ($piiKeys as $piiKey) {
                if (str_contains(strtolower((string) $key), $piiKey)) {
                    if ($mode === 'hash') {
                        $params[$key] = hash('sha256', (string) $value);
                    } else {
                        unset($params[$key]);
                    }
                    $scrubbed = true;
                    break;
                }
            }
        }

        if (! $scrubbed) {
            return $event;
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: $params,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    /**
     * Tag event with tenant ID for multi-tenant isolation.
     *
     * @param  array<string, mixed>  $config
     */
    private function enrichTenantTag(AnalyticsEvent $event, array $config): AnalyticsEvent
    {
        $paramName = (string) ($config['param_name'] ?? '_tenant_id');
        $user = auth()->user();

        if ($user === null || ! method_exists($user, 'getAttribute')) {
            return $event;
        }

        $tenantId = $user->getAttribute('tenant_id');
        if (! is_string($tenantId) || $tenantId === '') {
            return $event;
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, [$paramName => $tenantId]),
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    /**
     * Enrich with client_id ↔ user_id identity link metadata.
     *
     * @param  array<string, mixed>  $config
     */
    private function enrichIdentityLink(AnalyticsEvent $event, array $config): AnalyticsEvent
    {
        if ($event->clientId === null && $event->userId === null) {
            return $event;
        }

        $identityParams = [];
        $prefix = (string) ($config['param_prefix'] ?? '');

        if ($event->clientId !== null) {
            $identityParams[$prefix . 'client_id'] = $event->clientId;
        }

        if ($event->userId !== null) {
            $identityParams[$prefix . 'user_id'] = $event->userId;
        }

        $identityParams[$prefix . 'has_identity'] = true;

        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, $identityParams),
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    /**
     * Tag event with estimated dispatch cost.
     *
     * @param  array<string, mixed>  $config
     */
    private function enrichCostTag(AnalyticsEvent $event, array $config): AnalyticsEvent
    {
        $costPerEvent = (float) ($config['cost_per_event'] ?? 0.0001);
        $payloadBytes = strlen(json_encode($event->params));

        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, [
                '_dispatch_cost_estimate' => round($costPerEvent * (1 + ($payloadBytes / 10000)), 8),
                '_payload_bytes' => $payloadBytes,
            ]),
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    /**
     * Tag event with source origin metadata.
     *
     * @param  array<string, mixed>  $config
     */
    private function enrichSourceTag(AnalyticsEvent $event, array $config): AnalyticsEvent
    {
        $source = $event->source ?? 'unknown';

        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, [
                '_event_source' => $source,
                '_dispatched_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
            ]),
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    /**
     * Filter parameters based on consent state.
     *
     * @param  array<string, mixed>  $config
     */
    private function enrichConsentFilter(AnalyticsEvent $event, array $config): AnalyticsEvent
    {
        $analyticsConsent = $config['analytics_consent'] ?? true;
        $marketingConsent = $config['marketing_consent'] ?? false;

        $params = $event->params;
        $filtered = false;

        // If analytics consent is denied, remove non-essential params
        if ($analyticsConsent === false) {
            foreach ($params as $key => $value) {
                if (str_starts_with($key, '_')) {
                    unset($params[$key]);
                    $filtered = true;
                }
            }
        }

        // If marketing consent is denied, remove UTM and campaign params
        if ($marketingConsent === false) {
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $utmKey) {
                if (array_key_exists($utmKey, $params)) {
                    unset($params[$utmKey]);
                    $filtered = true;
                }
            }
        }

        if (! $filtered) {
            return $event;
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: $params,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    /**
     * Get default enrichment stages configuration.
     *
     * @return list<array{stage: string, enabled: bool, priority: int, config: array<string, mixed>}>
     */
    private function defaultStages(): array
    {
        return [
            ['stage' => 'pii_scrub', 'enabled' => true, 'priority' => 100, 'config' => []],
            ['stage' => 'consent_filter', 'enabled' => true, 'priority' => 90, 'config' => []],
            ['stage' => 'utm_source', 'enabled' => true, 'priority' => 80, 'config' => []],
            ['stage' => 'device_context', 'enabled' => true, 'priority' => 70, 'config' => []],
            ['stage' => 'session_context', 'enabled' => true, 'priority' => 60, 'config' => []],
            ['stage' => 'tenant_tag', 'enabled' => false, 'priority' => 50, 'config' => []],
            ['stage' => 'identity_link', 'enabled' => true, 'priority' => 40, 'config' => []],
            ['stage' => 'timestamp_normalize', 'enabled' => true, 'priority' => 30, 'config' => []],
            ['stage' => 'cost_tag', 'enabled' => false, 'priority' => 20, 'config' => []],
            ['stage' => 'source_tag', 'enabled' => true, 'priority' => 10, 'config' => []],
        ];
    }
}
