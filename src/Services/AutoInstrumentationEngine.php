<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Auto-instrumentation engine for Laravel model events.
 *
 * Automatically tracks analytics events when Eloquent model events fire
 * (created, updated, deleted, restored). Configuration-driven — no code
 * changes needed to instrument standard CRUD operations.
 *
 * This is the Laravel equivalent of Segment's "Source" auto-instrumentation:
 * it listens for framework-level events and translates them into analytics
 * events with proper context (model attributes as params, user identity
 * from auth, UTM from request).
 *
 * Configuration (config/analytics.php):
 *
 *   'auto_instrument' => [
 *       'enabled' => true,
 *       'models' => [
 *           'App\\Models\\User' => [
 *               'created' => 'sign_up',
 *               'updated' => null,           // disabled
 *               'deleted' => 'cancellation',
 *               'restored' => null,
 *               'param_map' => [
 *                   'name' => 'full_name',
 *                   'email' => 'email',
 *                   'plan' => 'plan_name',
 *               ],
 *               'exclude_params' => ['password', 'remember_token'],
 *           ],
 *           'App\\Models\\Order' => [
 *               'created' => 'purchase',
 *               'param_map' => [
 *                   'total' => 'value',
 *                   'currency' => 'currency',
 *                   'id' => 'transaction_id',
 *               ],
 *           ],
 *       ],
 *       'extract_user_id' => true,
 *       'include_utm' => true,
 *   ],
 *
 * @since 50.0.0
 */
final class AutoInstrumentationEngine
{
    /** @var list<string> Events this engine listens for on each model */
    private const MODEL_EVENTS = ['created', 'updated', 'deleted', 'restored'];

    /** @var array<string, mixed> */
    private readonly array $config;

    private readonly AnalyticsManager $manager;

    private readonly EventDispatcher $dispatcher;

    /** @var bool Whether the engine has been booted */
    private bool $booted = false;

    /** @var list<callable(Model, string): array<string, mixed>> Custom param extractors */
    private array $paramExtractors = [];

    /** @var list<callable(AnalyticsEvent): ?AnalyticsEvent> Event transformers */
    private array $eventTransformers = [];

    /**
     * @param  ConfigRepository  $config
     * @param  AnalyticsManager  $manager
     * @param  EventDispatcher  $dispatcher
     */
    public function __construct(
        ConfigRepository $config,
        AnalyticsManager $manager,
        EventDispatcher $dispatcher,
    ): void {
        $this->config = $config->get('zeroboiler.analytics.auto_instrument', []);
        $this->manager = $manager;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Boot the auto-instrumentation engine.
     *
     * Registers Eloquent model event listeners based on configuration.
     * Should be called once during service provider boot.
     *
     * @return self
     */
    public function boot(): self
    {
        if ($this->booted || ! $this->isEnabled()) {
            return $this;
        }

        $models = $this->config['models'] ?? [];

        /** @var array<class-string<Model>, array<string, mixed>> $models */
        foreach ($models as $modelClass => $modelConfig) {
            if (! class_exists($modelClass)) {
                continue;
            }

            /** @var array{created?: string|null, updated?: string|null, deleted?: string|null, restored?: string|null, param_map?: array<string, string>, exclude_params?: list<string>} $modelConfig */
            foreach (self::MODEL_EVENTS as $event) {
                $analyticsEvent = $modelConfig[$event] ?? null;

                if ($analyticsEvent === null || $analyticsEvent === '') {
                    continue;
                }

                $this->registerListener($modelClass, $event, (string) $analyticsEvent, $modelConfig);
            }
        }

        $this->booted = true;

        return $this;
    }

    /**
     * Register a custom param extractor for specific models.
     *
     * The extractor receives the model and Eloquent event name, and should
     * return additional params to merge into the analytics event.
     *
     * @param  callable(Model, string): array<string, mixed>  $extractor
     * @return self
     */
    public function addParamExtractor(callable $extractor): self
    {
        $this->paramExtractors[] = $extractor;

        return $this;
    }

    /**
     * Register an event transformer.
     *
     * Receives the built AnalyticsEvent before dispatch and may return
     * a modified event or null to skip dispatch.
     *
     * @param  callable(AnalyticsEvent): ?AnalyticsEvent  $transformer
     * @return self
     */
    public function addEventTransformer(callable $transformer): self
    {
        $this->eventTransformers[] = $transformer;

        return $this;
    }

    /**
     * Check if auto-instrumentation is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /**
     * Check if the engine has been booted.
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Get the list of configured model classes.
     *
     * @return list<string>
     */
    public function getConfiguredModels(): array
    {
        return array_keys($this->config['models'] ?? []);
    }

    /**
     * Get the list of registered model event mappings.
     *
     * @return array<class-string<Model>, array<string, string|null>>
     */
    public function getMappings(): array
    {
        $mappings = [];
        $models = $this->config['models'] ?? [];

        foreach ($models as $modelClass => $modelConfig) {
            if (! is_array($modelConfig)) {
                continue;
            }

            $mappings[$modelClass] = [
                'created' => $modelConfig['created'] ?? null,
                'updated' => $modelConfig['updated'] ?? null,
                'deleted' => $modelConfig['deleted'] ?? null,
                'restored' => $modelConfig['restored'] ?? null,
            ];
        }

        return $mappings;
    }

    /**
     * Manually trigger an instrumentation event for a model.
     *
     * Useful for testing or for non-Eloquent events (e.g., login, logout).
     *
     * @param  string  $analyticsEventName  Target analytics event name
     * @param  Model  $model  The model to extract params from
     * @param  array<string, mixed>  $extraParams  Additional params
     */
    public function trigger(string $analyticsEventName, Model $model, array $extraParams = []): void
    {
        $params = $this->extractParams($model, $analyticsEventName, $extraParams);
        $userId = $this->extractUserId($model);
        $clientId = $this->extractClientId();

        $event = new AnalyticsEvent(
            name: $analyticsEventName,
            params: $params,
            clientId: $clientId,
            userId: $userId,
            source: 'auto_instrument',
        );

        $event = $this->applyTransformers($event);

        if ($event !== null) {
            $this->manager->trackEvent($event);
        }
    }

    /**
     * Register an Eloquent model event listener.
     *
     * @param  class-string<Model>  $modelClass
     * @param  string  $eloquentEvent  created|updated|deleted|restored
     * @param  string  $analyticsEventName
     * @param  array<string, mixed>  $modelConfig
     */
    private function registerListener(
        string $modelClass,
        string $eloquentEvent,
        string $analyticsEventName,
        array $modelConfig,
    ): void {
        $eventName = "eloquent.{$eloquentEvent}: {$modelClass}";

        $this->dispatcher->listen($eventName, function (Model $model) use (
            $eloquentEvent,
            $analyticsEventName,
            $modelConfig,
        ): void {
            try {
                // Check consent before dispatching
                $consent = $this->manager->getConsent();
                if (! $consent->isGranted('analytics')) {
                    return;
                }

                $params = $this->extractParams($model, $eloquentEvent, []);
                $userId = $this->extractUserId($model);
                $clientId = $this->extractClientId();

                $event = new AnalyticsEvent(
                    name: $analyticsEventName,
                    params: $params,
                    clientId: $clientId,
                    userId: $userId,
                    source: 'auto_instrument',
                );

                $event = $this->applyTransformers($event);

                if ($event !== null) {
                    $this->manager->trackEvent($event);
                }
            } catch (\Throwable $e) {
                Log::debug('ZeroBoiler Analytics: AutoInstrumentation failed', [
                    'model' => $modelClass,
                    'eloquent_event' => $eloquentEvent,
                    'analytics_event' => $analyticsEventName,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Extract analytics event params from a model.
     *
     * @param  Model  $model
     * @param  string  $eloquentEvent
     * @param  array<string, mixed>  $extraParams
     * @return array<string, mixed>
     */
    private function extractParams(Model $model, string $eloquentEvent, array $extraParams): array
    {
        /** @var array<string, string> $paramMap */
        $paramMap = [];
        $modelConfig = $this->config['models'][get_class($model)] ?? [];
        $paramMap = $modelConfig['param_map'] ?? [];

        /** @var list<string> $excludeParams */
        $excludeParams = $modelConfig['exclude_params'] ?? ['password', 'password_hash', 'remember_token', 'two_factor_secret'];

        $params = [
            '_model' => get_class($model),
            '_model_event' => $eloquentEvent,
            '_model_id' => $model->getKey(),
        ];

        $attributes = $model->getAttributes();

        foreach ($attributes as $key => $value) {
            // Skip excluded params
            if (in_array($key, $excludeParams, true)) {
                continue;
            }

            // Map param names using the configured mapping
            $analyticsKey = $paramMap[$key] ?? $key;
            $params[$analyticsKey] = $value;
        }

        // Include UTM params if configured
        if ($this->shouldIncludeUtm()) {
            $utmParams = $this->extractUtmParams();
            $params = array_merge($params, $utmParams);
        }

        // Run custom param extractors
        foreach ($this->paramExtractors as $extractor) {
            $additional = $extractor($model, $eloquentEvent);

            if (is_array($additional)) {
                $params = array_merge($params, $additional);
            }
        }

        // Merge extra params (highest priority)
        $params = array_merge($params, $extraParams);

        return $params;
    }

    /**
     * Extract user ID from the model or current auth context.
     *
     * @param  Model  $model
     * @return string|null
     */
    private function extractUserId(Model $model): ?string
    {
        if (! $this->shouldExtractUserId()) {
            return null;
        }

        // If the model itself is the User model (or has getAuthIdentifier)
        if (method_exists($model, 'getAuthIdentifier')) {
            $id = $model->getAuthIdentifier();

            if ($id !== null) {
                return is_string($id) ? $id : (string) $id;
            }
        }

        // Check for a user_id attribute on the model
        $userIdAttr = $model->getAttribute('user_id');

        if ($userIdAttr !== null && (is_int($userIdAttr) || is_string($userIdAttr))) {
            return (string) $userIdAttr;
        }

        // Fall back to the currently authenticated user
        $user = auth()->user();

        if ($user !== null && method_exists($user, 'getAuthIdentifier')) {
            $id = $user->getAuthIdentifier();

            return $id !== null ? (string) $id : null;
        }

        return null;
    }

    /**
     * Extract the analytics client ID from the current request.
     */
    private function extractClientId(): ?string
    {
        $request = request();

        // Check header first
        $header = $request->header('X-Analytics-Client-Id');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        // Check cookie
        $cookieName = $this->config['cookie_name'] ?? 'zb_analytics_id';
        $cookie = $request->cookie($cookieName);
        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        return null;
    }

    /**
     * Extract UTM parameters from the current request.
     *
     * @return array<string, mixed>
     */
    private function extractUtmParams(): array
    {
        $request = request();
        $utmParams = [];

        $utmFields = [
            'utm_source' => 'utm_source',
            'utm_medium' => 'utm_medium',
            'utm_campaign' => 'utm_campaign',
            'utm_term' => 'utm_term',
            'utm_content' => 'utm_content',
        ];

        foreach ($utmFields as $field => $param) {
            $value = $request->query($field);

            if (is_string($value) && $value !== '') {
                $utmParams[$param] = $value;
            }
        }

        return $utmParams;
    }

    /**
     * Check if user ID extraction is configured.
     */
    private function shouldExtractUserId(): bool
    {
        return (bool) ($this->config['extract_user_id'] ?? true);
    }

    /**
     * Check if UTM params should be included.
     */
    private function shouldIncludeUtm(): bool
    {
        return (bool) ($this->config['include_utm'] ?? true);
    }

    /**
     * Apply registered event transformers.
     *
     * @param  AnalyticsEvent  $event
     * @return AnalyticsEvent|null  Returns null if a transformer cancels the event
     */
    private function applyTransformers(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $result = $event;

        foreach ($this->eventTransformers as $transformer) {
            $result = $transformer($result);

            if ($result === null) {
                return null;
            }
        }

        return $result;
    }
}
