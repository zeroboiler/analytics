<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event source tagging service.
 *
 * Automatically tags all dispatched events with metadata about their origin:
 * how they were dispatched, from where, and by whom. This enables downstream
 * analysis of event provenance, debugging dispatch pipelines, and building
 * analytics about analytics (meta-analytics).
 *
 * Source values: 'api' (JS client), 'server' (server-side), 'cron' (scheduled),
 * 'webhook_inbound' (external webhooks), 'lifecycle' (auto-mapped), 'test' (tests).
 *
 * Tags are prefixed with `_` to avoid collision with user params.
 */
final class EventSourceTagger
{
    private const SOURCE_API = 'api';
    private const SOURCE_SERVER = 'server';
    private const SOURCE_CRON = 'cron';
    private const SOURCE_WEBHOOK = 'webhook_inbound';
    private const SOURCE_LIFECYCLE = 'lifecycle';
    private const SOURCE_TEST = 'test';
    private const SOURCE_BATCH = 'batch';

    private const VALID_SOURCES = [
        self::SOURCE_API,
        self::SOURCE_SERVER,
        self::SOURCE_CRON,
        self::SOURCE_WEBHOOK,
        self::SOURCE_LIFECYCLE,
        self::SOURCE_TEST,
        self::SOURCE_BATCH,
    ];

    /**
     * Tag an event with its source metadata.
     *
     * Returns a new AnalyticsEvent with source tags merged into params.
     * The original event is never modified (immutable DTO).
     *
     * @param  AnalyticsEvent  $event  The event to tag
     * @param  string  $source  Source identifier (api, server, cron, etc.)
     * @param  array<string, mixed>  $extra  Additional source context (request_path, user_agent, etc.)
     */
    public function tag(AnalyticsEvent $event, string $source, array $extra = []): AnalyticsEvent
    {
        if (! in_array($source, self::VALID_SOURCES, true)) {
            $source = self::SOURCE_SERVER;
        }

        $tags = [
            '_source' => $source,
            '_timestamp' => now()->toIso8601String(),
            '_version' => '2.57.0',
        ];

        $mergedParams = array_merge($event->params, $tags, $extra);

        return new AnalyticsEvent(
            name: $event->name,
            params: $mergedParams,
            clientId: $event->clientId,
            userId: $event->userId,
        );
    }

    /**
     * Tag an event as originating from the API (JS client).
     *
     * @param  AnalyticsEvent  $event
     * @param  string|null  $requestPath  The API endpoint path
     * @param  string|null  $userAgent  Client user agent
     */
    public function tagAsApi(AnalyticsEvent $event, ?string $requestPath = null, ?string $userAgent = null): AnalyticsEvent
    {
        $extra = [];

        if ($requestPath !== null && $requestPath !== '') {
            $extra['_request_path'] = $requestPath;
        }

        if ($userAgent !== null && $userAgent !== '') {
            $extra['_user_agent'] = $userAgent;
        }

        return $this->tag($event, self::SOURCE_API, $extra);
    }

    /**
     * Tag an event as originating from the server-side (PHP).
     *
     * @param  AnalyticsEvent  $event
     * @param  string|null  $caller  The calling method or service name
     */
    public function tagAsServer(AnalyticsEvent $event, ?string $caller = null): AnalyticsEvent
    {
        $extra = [];

        if ($caller !== null && $caller !== '') {
            $extra['_caller'] = $caller;
        }

        return $this->tag($event, self::SOURCE_SERVER, $extra);
    }

    /**
     * Tag an event as originating from a cron job / scheduled task.
     *
     * @param  AnalyticsEvent  $event
     * @param  string|null  $command  The artisan command name
     */
    public function tagAsCron(AnalyticsEvent $event, ?string $command = null): AnalyticsEvent
    {
        $extra = [];

        if ($command !== null && $command !== '') {
            $extra['_command'] = $command;
        }

        return $this->tag($event, self::SOURCE_CRON, $extra);
    }

    /**
     * Tag an event as originating from an inbound webhook.
     *
     * @param  AnalyticsEvent  $event
     * @param  string|null  $webhookUrl  The source webhook URL
     */
    public function tagAsWebhook(AnalyticsEvent $event, ?string $webhookUrl = null): AnalyticsEvent
    {
        $extra = [];

        if ($webhookUrl !== null && $webhookUrl !== '') {
            $extra['_webhook_url'] = $webhookUrl;
        }

        return $this->tag($event, self::SOURCE_WEBHOOK, $extra);
    }

    /**
     * Tag an event as originating from the lifecycle mapper.
     *
     * @param  AnalyticsEvent  $event
     * @param  string|null  $mappingKey  The lifecycle mapping key (e.g. 'auth.login')
     */
    public function tagAsLifecycle(AnalyticsEvent $event, ?string $mappingKey = null): AnalyticsEvent
    {
        $extra = [];

        if ($mappingKey !== null && $mappingKey !== '') {
            $extra['_mapping_key'] = $mappingKey;
        }

        return $this->tag($event, self::SOURCE_LIFECYCLE, $extra);
    }

    /**
     * Tag an event as originating from a batch request.
     *
     * @param  AnalyticsEvent  $event
     * @param  int  $batchIndex  Index within the batch
     * @param  int  $batchSize  Total number of events in the batch
     */
    public function tagAsBatch(AnalyticsEvent $event, int $batchIndex, int $batchSize): AnalyticsEvent
    {
        return $this->tag($event, self::SOURCE_BATCH, [
            '_batch_index' => $batchIndex,
            '_batch_size' => $batchSize,
        ]);
    }

    /**
     * Extract the source tag from an event's params.
     */
    public function extractSource(AnalyticsEvent $event): string
    {
        return $event->params['_source'] ?? 'unknown';
    }

    /**
     * Check if an event has been source-tagged.
     */
    public function isTagged(AnalyticsEvent $event): bool
    {
        return isset($event->params['_source']);
    }

    /**
     * Get all valid source identifiers.
     *
     * @return list<string>
     */
    public static function validSources(): array
    {
        return self::VALID_SOURCES;
    }

    /**
     * Get aggregate source statistics from metrics.
     *
     * Counts events dispatched per source from the metrics counters.
     *
     * @param  AnalyticsMetrics  $metrics
     * @return array<string, int>
     */
    public function sourceStats(AnalyticsMetrics $metrics): array
    {
        $summary = $metrics->summary();
        $stats = [];

        foreach (self::VALID_SOURCES as $source) {
            $key = 'source_' . $source;
            $stats[$source] = $summary[$key] ?? 0;
        }

        return $stats;
    }
}
