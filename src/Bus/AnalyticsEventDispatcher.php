<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Bus;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\DTO\EventCollection;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * Unified event dispatcher with consent, priority, sampling, and queue awareness.
 *
 * Provides a single entry point for dispatching analytics events through
 * the full processing pipeline: consent check → priority gate → dedup →
 * enrichment → queue/sync dispatch.
 *
 * This is the recommended way to dispatch events from application code,
 * replacing direct calls to AnalyticsManager::trackEvent().
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 *
 * @since 1.0.0
 */
final class AnalyticsEventDispatcher
{
    private AnalyticsManager $manager;

    private QueuedAnalyticsDispatcher $queue;

    private ConfigRepository $config;

    private bool $consentAware;

    private bool $dedupEnabled;

    private float $samplingRate;

    private bool $debug;

    /**
     * @param  AnalyticsManager  $manager
     * @param  QueuedAnalyticsDispatcher  $queue
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        QueuedAnalyticsDispatcher $queue,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->queue = $queue;
        $this->config = $config;

        $dispatcherConfig = $config->get('zeroboiler.analytics.dispatcher', []);
        /** @var array{consent_aware?: bool, dedup_enabled?: bool, sampling_rate?: float, debug?: bool} $dispatcherConfig */
        $this->consentAware = (bool) ($dispatcherConfig['consent_aware'] ?? true);
        $this->dedupEnabled = (bool) ($dispatcherConfig['dedup_enabled'] ?? true);
        $this->samplingRate = (float) ($dispatcherConfig['sampling_rate'] ?? 1.0);
        $this->debug = (bool) ($dispatcherConfig['debug'] ?? false);
    }

    /**
     * Dispatch a single analytics event.
     *
     * Applies consent, priority, dedup, and sampling checks before dispatching.
     *
     * @param  AnalyticsEvent  $event
     * @param  array{queue?: bool, immediate?: bool, consent_bypass?: bool}  $options
     */
    public function dispatch(AnalyticsEvent $event, array $options = []): bool
    {
        // 1. Consent check
        if ($this->consentAware && !($options['consent_bypass'] ?? false)) {
            $consent = $this->manager->getConsent();
            if (! $this->isConsentGranted($consent, $event->name)) {
                if ($this->debug) {
                    Log::debug('AnalyticsEventDispatcher: event blocked by consent', [
                        'event' => $event->name,
                    ]);
                }

                return false;
            }
        }

        // 2. Sampling check
        if ($this->samplingRate < 1.0 && ! $this->passesSampling()) {
            if ($this->debug) {
                Log::debug('AnalyticsEventDispatcher: event sampled out', [
                    'event' => $event->name,
                    'rate' => $this->samplingRate,
                ]);
            }

            return false;
        }

        // 3. Dedup check (in-memory)
        if ($this->dedupEnabled && $this->isDuplicate($event)) {
            if ($this->debug) {
                Log::debug('AnalyticsEventDispatcher: event deduplicated', [
                    'event' => $event->name,
                ]);
            }

            return false;
        }

        // 4. Track the dedup fingerprint
        if ($this->dedupEnabled) {
            $this->trackFingerprint($event);
        }

        // 5. Queue or sync dispatch
        $useQueue = $options['queue'] ?? $this->queue->isEnabled();
        $immediate = $options['immediate'] ?? false;

        if ($useQueue && ! $immediate) {
            $this->queue->dispatch($event);
        } else {
            $this->manager->trackEvent($event);
        }

        return true;
    }

    /**
     * Dispatch a collection of events as a batch.
     *
     * @param  EventCollection  $collection
     * @param  array{queue?: bool, immediate?: bool, consent_bypass?: bool}  $options
     * @return array{dispatched: int, filtered: int, total: int}
     */
    public function dispatchCollection(EventCollection $collection, array $options = []): array
    {
        $dispatched = 0;
        $filtered = 0;

        foreach ($collection as $event) {
            if ($this->dispatch($event, $options)) {
                $dispatched++;
            } else {
                $filtered++;
            }
        }

        return [
            'dispatched' => $dispatched,
            'filtered' => $filtered,
            'total' => $collection->count(),
        ];
    }

    /**
     * Dispatch a collection using batch queue dispatch (single job).
     *
     * All events are sent in one queue job for efficiency.
     * Consent and sampling checks still apply before queueing.
     *
     * @param  EventCollection  $collection
     * @return array{queued: int, filtered: int, total: int}
     */
    public function dispatchBatch(EventCollection $collection): array
    {
        $passed = [];
        $filtered = 0;

        foreach ($collection as $event) {
            // Apply consent check
            if ($this->consentAware) {
                $consent = $this->manager->getConsent();
                if (! $this->isConsentGranted($consent, $event->name)) {
                    $filtered++;
                    continue;
                }
            }

            // Apply sampling check
            if ($this->samplingRate < 1.0 && ! $this->passesSampling()) {
                $filtered++;
                continue;
            }

            $passed[] = $event;
        }

        if (! empty($passed)) {
            $this->queue->dispatchBatch($passed);
        }

        return [
            'queued' => count($passed),
            'filtered' => $filtered,
            'total' => $collection->count(),
        ];
    }

    /**
     * Check if consent is granted for a specific event.
     *
     * Analytics events require analytics_storage consent.
     * E-commerce events also require ad_storage consent.
     * SaaS lifecycle events always pass (server-side, no consent needed).
     */
    private function isConsentGranted(ConsentState $consent, string $eventName): bool
    {
        $analyticsGranted = $consent->analyticsStorage === 'granted';

        // Server-side SaaS events always pass consent
        $saasEvents = [
            'sign_up', 'login', 'logout', 'start_trial', 'trial_end',
            'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation',
            'account_activated', 'account_deactivated', 'account_deleted',
        ];

        if (in_array($eventName, $saasEvents, true)) {
            return true;
        }

        return $analyticsGranted;
    }

    /**
     * Check if the event passes sampling.
     */
    private function passesSampling(): bool
    {
        if ($this->samplingRate >= 1.0) {
            return true;
        }

        return (mt_rand() / mt_getrandmax()) < $this->samplingRate;
    }

    /**
     * In-memory deduplication check using event fingerprint.
     */
    private function isDuplicate(AnalyticsEvent $event): bool
    {
        $fingerprint = $this->buildFingerprint($event);
        $window = (int) ($this->config->get('zeroboiler.analytics.dedup.window_seconds', 10) * 1000);
        $now = (int) (microtime(true) * 1000);

        $key = 'dedup_' . $fingerprint;

        if (! isset($GLOBALS['__zb_dedup'][$key])) {
            $GLOBALS['__zb_dedup'][$key] = $now;

            return false;
        }

        return ($now - $GLOBALS['__zb_dedup'][$key]) < $window;
    }

    /**
     * Track a fingerprint for future dedup checks.
     */
    private function trackFingerprint(AnalyticsEvent $event): void
    {
        $fingerprint = $this->buildFingerprint($event);
        $now = (int) (microtime(true) * 1000);

        $GLOBALS['__zb_dedup'][$fingerprint] = $now;

        // Prune old entries every 100 calls
        if (count($GLOBALS['__zb_dedup'] ?? []) > 1000) {
            $window = (int) ($this->config->get('zeroboiler.analytics.dedup.window_seconds', 10) * 1000);
            $GLOBALS['__zb_dedup'] = array_filter(
                $GLOBALS['__zb_dedup'],
                fn (int $ts): bool => ($now - $ts) < $window,
            );
        }
    }

    /**
     * Build a fingerprint for deduplication.
     *
     * Uses event name + clientId + param hash for uniqueness.
     */
    private function buildFingerprint(AnalyticsEvent $event): string
    {
        $params = $event->params;
        // Remove volatile params for fingerprinting
        unset($params['timestamp'], $params['_priority'], $params['session_id']);

        return md5($event->name . ':' . ($event->clientId ?? 'anon') . ':' . json_encode($params, JSON_THROW_ON_ERROR));
    }

    /**
     * Get the dispatch configuration.
     *
     * @return array{consent_aware: bool, dedup_enabled: bool, sampling_rate: float, debug: bool}
     */
    public function getConfig(): array
    {
        return [
            'consent_aware' => $this->consentAware,
            'dedup_enabled' => $this->dedupEnabled,
            'sampling_rate' => $this->samplingRate,
            'debug' => $this->debug,
        ];
    }
}
