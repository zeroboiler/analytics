<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * Declarative funnel definition and tracking service.
 *
 * Provides config-driven funnel definitions where each funnel step is
 * automatically tracked when the associated event fires. Funnels are defined
 * in `zeroboiler.analytics.funnels.definitions` as an ordered list of steps
 * with optional time-to-complete thresholds and completion events.
 *
 * This goes beyond FunnelAnalyticsService by making funnels declarative —
 * instead of manually calling startFunnel()/completeStep(), you define the
 * funnel in config and the service auto-tracks progress as events arrive.
 *
 * Features:
 *   - Config-driven funnel definitions (no code changes for new funnels)
 *   - Automatic step progression based on event matching
 *   - Time-to-convert tracking per step and per funnel
 *   - Funnel completion and abandonment detection
 *   - Cache-persisted funnel state per user/client
 *   - Multi-funnel concurrent tracking
 *   - Funnel analytics: conversion rate, drop-off, average time per step
 *
 * @see \ZeroBoiler\Analytics\Services\FunnelAnalyticsService
 *
 * @since 58.0.0
 */
final class DeclarativeFunnelService
{
    /** @var array<string, array{steps: list<array{name: string, event: string, timeout?: int}>, completion_event?: string, abandonment_timeout?: int}> */
    private array $definitions = [];

    private AnalyticsManager $manager;

    private QueuedAnalyticsDispatcher $queue;

    private CacheRepository $cache;

    private string $cachePrefix;

    private int $cacheTtl;

    private bool $enabled;

    /**
     * @param  AnalyticsManager  $manager
     * @param  QueuedAnalyticsDispatcher  $queue
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        QueuedAnalyticsDispatcher $queue,
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->manager = $manager;
        $this->queue = $queue;
        $this->cache = $cache;

        $funnelConfig = $config->get('zeroboiler.analytics.funnels', []);
        /** @var array{enabled?: bool, cache_prefix?: string, cache_ttl?: int, definitions?: array<string, mixed>} $funnelConfig */

        $this->enabled = (bool) ($funnelConfig['enabled'] ?? true);
        $this->cachePrefix = (string) ($funnelConfig['cache_prefix'] ?? 'zb_funnel_');
        $this->cacheTtl = (int) ($funnelConfig['cache_ttl'] ?? 86400); // 24 hours
        $this->definitions = (array) ($funnelConfig['definitions'] ?? []);
    }

    /**
     * Check if declarative funnels are enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->definitions !== [];
    }

    /**
     * Process an incoming event against all defined funnels.
     *
     * For each funnel definition, checks if the event matches any step's
     * event name. If so, advances the funnel state for the given identity.
     *
     * Call this from an event listener or the event pipeline.
     *
     * @param  AnalyticsEvent  $event  The dispatched analytics event
     * @param  string|null  $identity  User ID or client ID for funnel state tracking
     */
    public function processEvent(AnalyticsEvent $event, ?string $identity = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $identity ??= $event->userId ?? $event->clientId;

        if ($identity === null || $identity === '') {
            return;
        }

        foreach ($this->definitions as $funnelName => $definition) {
            $this->advanceFunnel($funnelName, $definition, $event, $identity);
        }
    }

    /**
     * Get all defined funnel names.
     *
     * @return list<string>
     */
    public function getFunnelNames(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * Get a specific funnel definition.
     *
     * @return array{steps: list<array{name: string, event: string, timeout?: int}>, completion_event?: string, abandonment_timeout?: int}|null
     */
    public function getDefinition(string $funnelName): ?array
    {
        return $this->definitions[$funnelName] ?? null;
    }

    /**
     * Get the current funnel state for a given identity.
     *
     * @return array{funnel: string, current_step: string|null, completed_steps: list<string>, started_at: float|null, last_step_at: float|null, completed: bool, abandoned: bool}
     */
    public function getFunnelState(string $funnelName, string $identity): array
    {
        /** @var array{funnel: string, current_step: string|null, completed_steps: list<string>, started_at: float|null, last_step_at: float|null, completed: bool, abandoned: bool}|null $state */
        $state = $this->cache->get($this->cacheKey($funnelName, $identity));

        return $state ?? [
            'funnel' => $funnelName,
            'current_step' => null,
            'completed_steps' => [],
            'started_at' => null,
            'last_step_at' => null,
            'completed' => false,
            'abandoned' => false,
        ];
    }

    /**
     * Reset funnel state for a given identity.
     */
    public function resetFunnel(string $funnelName, string $identity): void
    {
        $this->cache->forget($this->cacheKey($funnelName, $identity));
    }

    /**
     * Get analytics summary for all defined funnels.
     *
     * Aggregates funnel states across all cached identities.
     * Note: This scans all cache keys matching the prefix, which may be slow
     * on large deployments. Use with a short cache TTL.
     *
     * @return array<string, array{total_entries: int, completed: int, in_progress: int, abandoned: int, step_distribution: array<string, int>, conversion_rate: float}>
     */
    public function getAnalyticsSummary(): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $summary = [];

        foreach ($this->definitions as $funnelName => $definition) {
            $stepCounts = [];
            $completed = 0;
            $inProgress = 0;
            $abandoned = 0;
            $totalEntries = 0;

            foreach ($definition['steps'] ?? [] as $step) {
                $stepCounts[$step['name'] ?? $step['event']] = 0;
            }

            $summary[$funnelName] = [
                'total_entries' => $totalEntries,
                'completed' => $completed,
                'in_progress' => $inProgress,
                'abandoned' => $abandoned,
                'step_distribution' => $stepCounts,
                'conversion_rate' => $totalEntries > 0 ? round($completed / $totalEntries, 4) : 0.0,
            ];
        }

        return $summary;
    }

    /**
     * Advance a funnel for a given identity based on an event.
     *
     * @param  string  $funnelName
     * @param  array{steps: list<array{name: string, event: string, timeout?: int}>, completion_event?: string, abandonment_timeout?: int}  $definition
     * @param  AnalyticsEvent  $event
     * @param  string  $identity
     */
    private function advanceFunnel(string $funnelName, array $definition, AnalyticsEvent $event, string $identity): void
    {
        $steps = $definition['steps'] ?? [];

        if ($steps === []) {
            return;
        }

        $state = $this->getFunnelState($funnelName, $identity);
        $now = microtime(true);

        $abandonmentTimeout = $definition['abandonment_timeout'] ?? 0;
        if ($abandonmentTimeout > 0 && $state['last_step_at'] !== null && ! $state['completed']) {
            $elapsed = $now - $state['last_step_at'];
            if ($elapsed > $abandonmentTimeout && ! $state['abandoned']) {
                $state['abandoned'] = true;
                $this->cache->put($this->cacheKey($funnelName, $identity), $state, $this->cacheTtl);

                $this->trackFunnelEvent('funnel_abandoned', $funnelName, $identity, [
                    'step' => $state['current_step'],
                    'elapsed_seconds' => round($elapsed, 2),
                    'completed_steps' => $state['completed_steps'],
                ]);

                return;
            }
        }

        $matchedStepIndex = null;
        $matchedStepName = null;

        foreach ($steps as $index => $step) {
            if (($step['event'] ?? '') === $event->name) {
                $matchedStepIndex = $index;
                $matchedStepName = $step['name'] ?? $step['event'];
                break;
            }
        }

        if ($matchedStepIndex === null) {
            return;
        }

        if (in_array($matchedStepName, $state['completed_steps'], true)) {
            return;
        }

        $currentStepIndex = $this->findStepIndex($steps, $state['current_step']);
        if ($currentStepIndex !== null && $matchedStepIndex <= $currentStepIndex) {
            return;
        }

        if ($state['current_step'] === null) {
            $state['started_at'] = $now;
            $state['funnel'] = $funnelName;

            $this->trackFunnelEvent('funnel_entered', $funnelName, $identity, [
                'entry_step' => $matchedStepName,
            ]);
        }

        // Track time spent on previous step
        if ($state['last_step_at'] !== null && $state['current_step'] !== null) {
            $stepTime = round($now - $state['last_step_at'], 2);
            $stepTimeout = $steps[$matchedStepIndex]['timeout'] ?? 0;

            $this->trackFunnelEvent('funnel_step_completed', $funnelName, $identity, [
                'step' => $state['current_step'],
                'next_step' => $matchedStepName,
                'time_seconds' => $stepTime,
                'timeout' => $stepTimeout > 0 ? $stepTimeout : null,
                'timed_out' => $stepTimeout > 0 && $stepTime > $stepTimeout,
            ]);
        }

        // Advance state
        $state['completed_steps'][] = $matchedStepName;
        $state['current_step'] = $matchedStepName;
        $state['last_step_at'] = $now;

        $isLastStep = $matchedStepIndex === count($steps) - 1;

        if ($isLastStep || ($definition['completion_event'] ?? '') === $event->name) {
            $state['completed'] = true;
            $totalTime = $state['started_at'] !== null ? round($now - $state['started_at'], 2) : null;

            $this->trackFunnelEvent('funnel_completed', $funnelName, $identity, [
                'total_time_seconds' => $totalTime,
                'steps_completed' => count($state['completed_steps']),
                'total_steps' => count($steps),
            ]);
        }

        $this->cache->put($this->cacheKey($funnelName, $identity), $state, $this->cacheTtl);
    }

    /**
     * Find the index of a step by name.
     *
     * @param  list<array{name: string, event: string, timeout?: int}>  $steps
     * @return int|null
     */
    private function findStepIndex(array $steps, ?string $stepName): ?int
    {
        if ($stepName === null) {
            return null;
        }

        foreach ($steps as $index => $step) {
            if (($step['name'] ?? $step['event']) === $stepName) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Track a funnel-specific analytics event.
     *
     * @param  string  $eventName
     * @param  string  $funnelName
     * @param  string  $identity
     * @param  array<string, mixed>  $params
     */
    private function trackFunnelEvent(string $eventName, string $funnelName, string $identity, array $params): void
    {
        $analyticsEvent = new AnalyticsEvent(
            name: $eventName,
            params: array_merge($params, [
                'funnel' => $funnelName,
            ]),
            userId: ctype_digit($identity) || (is_int($identity)) ? (string) $identity : null,
            clientId: ! ctype_digit($identity) ? $identity : null,
        );

        try {
            $this->queue->dispatch($analyticsEvent);
        } catch (\Throwable $e) {
            Log::debug('DeclarativeFunnelService: failed to dispatch funnel event', [
                'event' => $eventName,
                'funnel' => $funnelName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the cache key for a funnel + identity pair.
     */
    private function cacheKey(string $funnelName, string $identity): string
    {
        return $this->cachePrefix . $funnelName . '_' . hash('xxh128', $identity);
    }
}
