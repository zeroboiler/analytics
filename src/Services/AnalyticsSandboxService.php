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
 * Analytics sandbox for non-production environments.
 *
 * Captures events locally without dispatching to external providers.
 * Events are stored in the cache store for inspection, replay, or export.
 * Automatically activates based on APP_ENV or explicit config.
 *
 * Useful for:
 * - Development: inspect event payloads without provider credentials
 * - Testing: verify event dispatch without side effects
 * - Staging: log events for review before provider dispatch
 * - Debugging: replay captured events against live providers
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsReadinessService
 */
final class AnalyticsSandboxService
{
    private const CACHE_KEY_EVENTS = 'sandbox_events';
    private const CACHE_KEY_META = 'sandbox_meta';
    private const CACHE_KEY_REPLAY = 'sandbox_replay_log';

    private bool $active;

    private readonly int $maxEvents;

    private readonly int $cacheTtl;

    private readonly string $cachePrefix;

    private readonly bool $includeContext;

    private readonly bool $allowReplay;

    private readonly bool $stagingLogOnly;

    private readonly string $appEnv;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ) {
        $sandboxConfig = $config->get('zeroboiler.analytics.sandbox', []);
        /** @var array{enabled?: bool|null, auto_local?: bool, auto_testing?: bool, staging_log_only?: bool, max_events?: int, cache_ttl?: int, cache_prefix?: string, include_context?: bool, allow_replay?: bool} $sandboxConfig */

        $this->appEnv = (string) ($config->get('app.env') ?? 'production');
        $this->maxEvents = (int) ($sandboxConfig['max_events'] ?? 5000);
        $this->cacheTtl = (int) ($sandboxConfig['cache_ttl'] ?? 86400);
        $this->cachePrefix = (string) ($sandboxConfig['cache_prefix'] ?? 'zb_sandbox_');
        $this->includeContext = (bool) ($sandboxConfig['include_context'] ?? true);
        $this->allowReplay = (bool) ($sandboxConfig['allow_replay'] ?? true);
        $this->stagingLogOnly = (bool) ($sandboxConfig['staging_log_only'] ?? true);

        $explicitEnabled = $sandboxConfig['enabled'] ?? null;
        $autoLocal = (bool) ($sandboxConfig['auto_local'] ?? true);
        $autoTesting = (bool) ($sandboxConfig['auto_testing'] ?? true);

        $this->active = $this->resolveActiveState($explicitEnabled, $autoLocal, $autoTesting);
    }

    /**
     * Resolve whether the sandbox should be active based on config and environment.
     *
     * @param  bool|null  $explicitEnabled  Explicit override (null = auto-detect)
     * @param  bool  $autoLocal  Auto-enable in local environment
     * @param  bool  $autoTesting  Auto-enable in testing environment
     */
    private function resolveActiveState(?bool $explicitEnabled, bool $autoLocal, bool $autoTesting): bool
    {
        // Explicit override takes precedence
        if ($explicitEnabled !== null) {
            return $explicitEnabled;
        }

        $env = strtolower($this->appEnv);

        // Auto-enable in local and testing environments
        if ($autoLocal && $env === 'local') {
            return true;
        }

        if ($autoTesting && ($env === 'testing' || $env === 'test')) {
            return true;
        }

        // Staging: log only (don't capture)
        if ($this->stagingLogOnly && $env === 'staging') {
            return false;
        }

        return false;
    }

    /**
     * Check if the sandbox is currently active.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Get the current application environment.
     */
    public function getAppEnv(): string
    {
        return $this->appEnv;
    }

    /**
     * Capture an event in the sandbox store.
     *
     * Stores the event data in cache for later inspection.
     * Silently fails if capture fails (sandbox should never break the app).
     *
     * @param  AnalyticsEvent  $event  The event to capture
     * @param  array<string, mixed>  $context  Optional context data (request, user, etc.)
     */
    public function capture(AnalyticsEvent $event, array $context = []): void
    {
        if (! $this->active) {
            return;
        }

        try {
            $events = $this->getEvents();
            $events[] = $this->serializeEvent($event, $context);

            // Trim to max size (FIFO)
            if (count($events) > $this->maxEvents) {
                $events = array_slice($events, -$this->maxEvents);
            }

            $this->cache->put(
                $this->cachePrefix.self::CACHE_KEY_EVENTS,
                json_encode($events, JSON_UNESCAPED_UNICODE),
                $this->cacheTtl,
            );

            $this->incrementCount();
        } catch (\Throwable $e) {
            try {
                Log::debug('AnalyticsSandbox: failed to capture event', [
                    'error' => $e->getMessage(),
                    'event' => $event->name,
                ]);
            } catch (\Throwable) {
                // Silently fail
            }
        }
    }

    /**
     * Get all captured events.
     *
     * @return list<array{name: string, params: array<string, mixed>, context?: array<string, mixed>, captured_at: string}>
     */
    public function getEvents(): array
    {
        try {
            $raw = $this->cache->get($this->cachePrefix.self::CACHE_KEY_EVENTS);

            if (! is_string($raw) || $raw === '') {
                return [];
            }

            /** @var list<array{name: string, params: array<string, mixed>, context?: array<string, mixed>, captured_at: string}> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get sandbox metadata (total captured, environment, timestamps).
     *
     * @return array{active: bool, environment: string, total_captured: int, max_events: int, cache_ttl: int, allow_replay: bool}
     */
    public function getMeta(): array
    {
        return [
            'active' => $this->active,
            'environment' => $this->appEnv,
            'total_captured' => $this->getCount(),
            'max_events' => $this->maxEvents,
            'cache_ttl' => $this->cacheTtl,
            'allow_replay' => $this->allowReplay,
        ];
    }

    /**
     * Get the total number of captured events.
     */
    public function getCount(): int
    {
        try {
            return (int) $this->cache->get($this->cachePrefix.self::CACHE_KEY_META, 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Replay a specific captured event against live providers.
     *
     * @param  int  $index  The event index in the captured events list
     * @param  callable(AnalyticsEvent): void  $dispatcher  Callback that dispatches to providers
     * @return array{success: bool, error?: string}
     */
    public function replayEvent(int $index, callable $dispatcher): array
    {
        if (! $this->allowReplay) {
            return ['success' => false, 'error' => 'Replay is disabled in sandbox config'];
        }

        $events = $this->getEvents();

        if (! isset($events[$index])) {
            return ['success' => false, 'error' => "Event index {$index} not found"];
        }

        try {
            $eventData = $events[$index];
            $event = new AnalyticsEvent(
                name: $eventData['name'],
                params: $eventData['params'] ?? [],
            );

            $dispatcher($event);

            $this->logReplay($index, true);

            return ['success' => true];
        } catch (\Throwable $e) {
            $this->logReplay($index, false, $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Replay all captured events against live providers.
     *
     * @param  callable(AnalyticsEvent): void  $dispatcher  Callback that dispatches to providers
     * @return array{replayed: int, failed: int, errors: list<string>}
     */
    public function replayAll(callable $dispatcher): array
    {
        if (! $this->allowReplay) {
            return ['replayed' => 0, 'failed' => 0, 'errors' => ['Replay is disabled in sandbox config']];
        }

        $events = $this->getEvents();
        $replayed = 0;
        $failed = 0;
        $errors = [];

        foreach ($events as $index => $eventData) {
            try {
                $event = new AnalyticsEvent(
                    name: $eventData['name'],
                    params: $eventData['params'] ?? [],
                );

                $dispatcher($event);
                $replayed++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "[{$index}] {$e->getMessage()}";
            }
        }

        return [
            'replayed' => $replayed,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Clear all captured events and reset counters.
     */
    public function clear(): void
    {
        try {
            $this->cache->forget($this->cachePrefix.self::CACHE_KEY_EVENTS);
            $this->cache->forget($this->cachePrefix.self::CACHE_KEY_META);
            $this->cache->forget($this->cachePrefix.self::CACHE_KEY_REPLAY);
        } catch (\Throwable) {
            // Silently fail
        }
    }

    /**
     * Get recent replay log entries.
     *
     * @return list<array{index: int, success: bool, error?: string, replayed_at: string}>
     */
    public function getReplayLog(): array
    {
        try {
            $raw = $this->cache->get($this->cachePrefix.self::CACHE_KEY_REPLAY);

            if (! is_string($raw) || $raw === '') {
                return [];
            }

            /** @var list<array{index: int, success: bool, error?: string, replayed_at: string}> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Serialize an event for storage.
     *
     * @return array{name: string, params: array<string, mixed>, context?: array<string, mixed>, captured_at: string}
     */
    private function serializeEvent(AnalyticsEvent $event, array $context): array
    {
        $serialized = [
            'name' => $event->name,
            'params' => $event->params,
            'captured_at' => date('c'),
        ];

        if ($this->includeContext && ! empty($context)) {
            $serialized['context'] = $context;
        }

        return $serialized;
    }

    /**
     * Increment the captured event counter.
     */
    private function incrementCount(): void
    {
        try {
            $current = $this->getCount();
            $this->cache->put(
                $this->cachePrefix.self::CACHE_KEY_META,
                $current + 1,
                $this->cacheTtl,
            );
        } catch (\Throwable) {
            // Silently fail
        }
    }

    /**
     * Log a replay attempt.
     *
     * @param  int  $index
     * @param  bool  $success
     * @param  string|null  $error
     */
    private function logReplay(int $index, bool $success, ?string $error = null): void
    {
        try {
            $log = $this->getReplayLog();
            $log[] = array_filter([
                'index' => $index,
                'success' => $success,
                'error' => $error,
                'replayed_at' => date('c'),
            ]);

            // Keep last 100 replay entries
            if (count($log) > 100) {
                $log = array_slice($log, -100);
            }

            $this->cache->put(
                $this->cachePrefix.self::CACHE_KEY_REPLAY,
                json_encode($log, JSON_UNESCAPED_UNICODE),
                $this->cacheTtl,
            );
        } catch (\Throwable) {
            // Silently fail
        }
    }
}
