<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * Eloquent model observer that auto-tracks model events as analytics events.
 *
 * Map your Eloquent model CRUD operations to analytics events without
 * writing any tracking code. Configured via zeroboiler.analytics.auto_track.models.
 *
 * Usage in your model's boot() method:
 *   protected static function booted(): void
 *   {
 *       AnalyticsEventObserver::observe(static::class, [
 *           'created' => ['event' => 'workspace_created', 'category' => 'saas'],
 *           'deleted' => ['event' => 'workspace_deleted', 'category' => 'saas'],
 *       ]);
 *   }
 *
 * Or via config:
 *   'auto_track' => [
 *       'models' => [
 *           \App\Models\Workspace::class => ['created', 'deleted'],
 *           \App\Models\Subscription::class => ['created', 'updated', 'deleted'],
 *       ],
 *   ],
 *
 * @since 168.0.0
 */
final class AnalyticsEventObserver
{
    /**
     * Supported Eloquent model events.
     *
     * @var list<string>
     */
    private const MODEL_EVENTS = [
        'created',
        'updated',
        'deleted',
        'restored',
        'forceDeleted',
    ];

    /**
     * Registered model-to-event mappings.
     *
     * @var array<class-string<\Illuminate\Database\Eloquent\Model>, array<string, array{event: string, category?: string, param_keys?: list<string>, condition?: callable(\Illuminate\Database\Eloquent\Model): bool}>>
     */
    private static array $mappings = [];

    /**
     * Register analytics event mappings for a model class.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  array<string, array{event: string, category?: string, param_keys?: list<string>, condition?: callable(\Illuminate\Database\Eloquent\Model): bool}>  $eventMappings
     */
    public static function observe(string $modelClass, array $eventMappings): void
    {
        foreach ($eventMappings as $eloquentEvent => $config) {
            self::$mappings[$modelClass][$eloquentEvent] = $config;
        }

        $modelClass::observe(new self);
    }

    /**
     * Register model tracking from config (called by ServiceProvider).
     *
     * @param  array<string, list<string>|array<string, array{event?: string, category?: string}>>  $modelConfig
     */
    public static function registerFromConfig(array $modelConfig): void
    {
        foreach ($modelConfig as $modelClass => $events) {
            $mappings = [];

            foreach ($events as $event => $config) {
                if (is_int($event)) {
                    // Simple format: ['created', 'deleted']
                    $eventName = self::deriveEventName($config, $modelClass);
                    $mappings[$config] = ['event' => $eventName];
                } else {
                    // Extended format: ['created' => ['event' => 'workspace_created', 'category' => 'saas']]
                    $mappings[$event] = array_merge(['event' => $event], $config);
                }
            }

            self::observe($modelClass, $mappings);
        }
    }

    /**
     * Handle model "created" event.
     */
    public function created(Model $model): void
    {
        $this->trackModelEvent($model, 'created');
    }

    /**
     * Handle model "updated" event.
     */
    public function updated(Model $model): void
    {
        $this->trackModelEvent($model, 'updated');
    }

    /**
     * Handle model "deleted" event.
     */
    public function deleted(Model $model): void
    {
        $this->trackModelEvent($model, 'deleted');
    }

    /**
     * Handle model "restored" event.
     */
    public function restored(Model $model): void
    {
        $this->trackModelEvent($model, 'restored');
    }

    /**
     * Handle model "force deleted" event.
     */
    public function forceDeleted(Model $model): void
    {
        $this->trackModelEvent($model, 'forceDeleted');
    }

    /**
     * Track an analytics event from a model lifecycle event.
     */
    private function trackModelEvent(Model $model, string $eloquentEvent): void
    {
        $modelClass = $model::class;
        $mapping = self::$mappings[$modelClass][$eloquentEvent] ?? null;

        if ($mapping === null) {
            return;
        }

        // Check optional condition
        $condition = $mapping['condition'] ?? null;
        if ($condition !== null && is_callable($condition) && ! $condition($model)) {
            return;
        }

        $eventName = $mapping['event'] ?? self::deriveEventName($eloquentEvent, $modelClass);
        $category = $mapping['category'] ?? self::guessCategory($modelClass);

        $params = $this->extractParams($model, $mapping['param_keys'] ?? []);

        try {
            $manager = app(AnalyticsManager::class);
            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: $eventName,
                params: $params,
                category: $category,
                source: 'observer',
            );
            $manager->trackEvent($event);
        } catch (\Throwable $e) {
            Log::debug('AnalyticsEventObserver: Failed to track event', [
                'event' => $eventName,
                'model' => $modelClass,
                'eloquent_event' => $eloquentEvent,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract event parameters from the model.
     *
     * @param  list<string>  $paramKeys  Model attribute keys to include
     * @return array<string, mixed>
     */
    private function extractParams(Model $model, array $paramKeys): array
    {
        $params = [];

        // Always include model identity
        $keyName = $model->getKeyName();
        $params['model_id'] = (string) $model->getAttribute($keyName);
        $params['model_type'] = $model::class;

        // Extract specific attribute keys
        foreach ($paramKeys as $key) {
            $value = $model->getAttribute($key);
            if ($value !== null) {
                $params[$key] = is_scalar($value) ? $value : (string) $value;
            }
        }

        return $params;
    }

    /**
     * Derive a snake_case event name from model class and Eloquent event.
     */
    private static function deriveEventName(string $eloquentEvent, string $modelClass): string
    {
        $shortName = (string) preg_replace('#^.*\\\\([^\\\\]+)$#', '$1', $modelClass);
        $snakeModel = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $shortName));

        return $snakeModel . '_' . strtolower($eloquentEvent);
    }

    /**
     * Guess the analytics category from model class namespace.
     */
    private static function guessCategory(string $modelClass): string
    {
        $namespace = (string) preg_replace('#^.*\\\\Models\\\\([^\\\\]+).*$#', '$1', $modelClass);

        return match (strtolower($namespace)) {
            'billing', 'payment', 'invoice', 'subscription' => 'saas',
            'ecommerce', 'product', 'order', 'cart' => 'ecommerce',
            'user', 'account', 'team', 'workspace' => 'saas',
            'marketing', 'campaign', 'lead' => 'marketing',
            default => 'saas',
        };
    }

    /**
     * Get all registered mappings (for testing and diagnostics).
     *
     * @return array<class-string, array<string, array{event: string, category?: string}>>
     */
    public static function getMappings(): array
    {
        return self::$mappings;
    }

    /**
     * Clear all registered mappings (for testing).
     */
    public static function clearMappings(): void
    {
        self::$mappings = [];
    }
}
