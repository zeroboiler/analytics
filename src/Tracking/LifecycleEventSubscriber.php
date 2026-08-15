<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tracking;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;

/**
 * Server-side lifecycle event subscriber.
 *
 * Bridges the config-driven LifecycleEventMapper into the Laravel event
 * dispatcher during boot. Provides a unified entry point that:
 *
 * 1. Delegates to LifecycleEventMapper for config-driven mappings
 * 2. Falls back to ServerSideTracker for backward-compatible event handling
 * 3. Supports optional queued dispatch via QueuedAnalyticsDispatcher
 * 4. Exposes a diagnostic summary of all registered mappings
 *
 * Configuration is read from:
 * - `zeroboiler.analytics.lifecycle` — config-driven event mappings
 * - `zeroboiler.analytics.auto_track` — legacy event toggles
 * - `zeroboiler.analytics.queue` — async dispatch settings
 *
 * @see \ZeroBoiler\Analytics\Services\LifecycleEventMapper
 * @see \ZeroBoiler\Analytics\Tracking\ServerSideTracker
 * @see \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher
 *
 * @since 79.0.0
 * @since 152.0.0 Added LifecycleAttributionEnricher integration
 */
final class LifecycleEventSubscriber
{
    private LifecycleEventMapper $mapper;

    private ServerSideTracker $tracker;

    private QueuedAnalyticsDispatcher $queue;

    private LifecycleAttributionEnricher $attributionEnricher;

    private bool $queueLifecycleEvents;

    /** @var bool Whether to enrich lifecycle events with attribution context */
    private bool $attributionEnabled;

    /** @var array<string, bool> Track which mappings were actually registered */
    private array $registeredMappings = [];

    /** @var list<string> Errors encountered during registration */
    private array $registrationErrors = [];

    /**
     * @param  AnalyticsManager  $manager  Central analytics manager
     * @param  LifecycleEventMapper  $mapper  Config-driven event mapper
     * @param  ServerSideTracker  $tracker  Legacy event tracker (Illuminate auth events)
     * @param  QueuedAnalyticsDispatcher  $queue  Queue dispatcher for async processing
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        private readonly AnalyticsManager $manager,
        LifecycleEventMapper $mapper,
        ServerSideTracker $tracker,
        QueuedAnalyticsDispatcher $queue,
        ConfigRepository $config,
    ): void {
        $this->mapper = $mapper;
        $this->tracker = $tracker;
        $this->queue = $queue;

        $lifecycleConfig = $config->get('zeroboiler.analytics.lifecycle', []);
        /** @var array{queue_events?: bool, enrich_attribution?: bool} $lifecycleConfig */
        $this->queueLifecycleEvents = (bool) ($lifecycleConfig['queue_events'] ?? false);
        $this->attributionEnabled = (bool) ($lifecycleConfig['enrich_attribution'] ?? true);

        $this->attributionEnricher = new LifecycleAttributionEnricher($config);
    }

    /**
     * Register all lifecycle event listeners on the dispatcher.
     *
     * Delegates to both the config-driven LifecycleEventMapper and the
     * legacy ServerSideTracker for full backward compatibility.
     *
     * Captures registration status for diagnostic reporting.
     */
    public function register(EventDispatcher $dispatcher): void
    {
        // Register config-driven lifecycle mappings
        try {
            $this->mapper->register($dispatcher);
            $this->registeredMappings = $this->mapper->getRegisteredMappings();
        } catch (\Throwable $e) {
            $this->registrationErrors[] = 'LifecycleEventMapper: ' . $e->getMessage();
        }

        // Register legacy Illuminate auth event listeners
        try {
            $this->tracker->register($dispatcher);
        } catch (\Throwable $e) {
            $this->registrationErrors[] = 'ServerSideTracker: ' . $e->getMessage();
        }
    }

    /**
     * Programmatically track a lifecycle event by key name.
     *
     * Looks up the mapping in LifecycleEventMapper::DEFAULT_MAPPINGS
     * and dispatches the corresponding analytics event. Useful for
     * manual tracking when automatic listener registration is disabled.
     *
     * @param  string  $eventKey  The lifecycle event key (e.g. 'auth.login', 'subscription.created')
     * @param  array<string, mixed>  $params  Event parameters
     */
    public function track(string $eventKey, array $params = []): void
    {
        $mapping = LifecycleEventMapper::getDefaultMapping($eventKey);

        if ($mapping === null) {
            return;
        }

        $target = $mapping['target'];
        $extractor = $mapping['params_extractor'] ?? null;

        try {
            // Enrich params with attribution context (UTM, referrer, session, device)
            if ($this->attributionEnabled) {
                $params = $this->attributionEnricher->enrichWithSummary($params);
            }

            $event = $this->mapper->buildEventFromMapping($target, $params, $extractor);

            if ($this->queueLifecycleEvents && $this->queue->isEnabled()) {
                $this->queue->dispatch($event);
            } else {
                $this->manager->trackEvent($event);
            }
        } catch (\Throwable) {
            // Silent — lifecycle tracking should never break the application
        }
    }

    /**
     * Get the count of registered lifecycle mappings.
     */
    public function registeredCount(): int
    {
        return count($this->registeredMappings);
    }

    /**
     * Get all registered mapping keys.
     *
     * @return list<string>
     */
    public function registeredKeys(): array
    {
        return array_keys($this->registeredMappings);
    }

    /**
     * Get any errors encountered during registration.
     *
     * @return list<string>
     */
    public function getRegistrationErrors(): array
    {
        return $this->registrationErrors;
    }

    /**
     * Check if a specific event key is registered.
     */
    public function isRegistered(string $eventKey): bool
    {
        return isset($this->registeredMappings[$eventKey]);
    }

    /**
     * Get a diagnostic summary of the lifecycle subscriber state.
     *
     * Useful for the analytics:overview command and health checks.
     *
     * @return array{registered_count: int, keys: list<string>, errors: list<string>, queue_enabled: bool, queue_lifecycle: bool}
     */
    public function diagnosticSummary(): array
    {
        return [
            'registered_count' => $this->registeredCount(),
            'keys' => $this->registeredKeys(),
            'errors' => $this->registrationErrors,
            'queue_enabled' => $this->queue->isEnabled(),
            'queue_lifecycle' => $this->queueLifecycleEvents,
            'attribution_enabled' => $this->attributionEnabled,
            'attribution_config' => $this->attributionEnricher->diagnosticSummary(),
        ];
    }

    /**
     * Get the underlying LifecycleEventMapper instance.
     */
    public function mapper(): LifecycleEventMapper
    {
        return $this->mapper;
    }

    /**
     * Get the underlying ServerSideTracker instance.
     */
    public function tracker(): ServerSideTracker
    {
        return $this->tracker;
    }
}
