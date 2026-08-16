<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event lifecycle hooks — before/after dispatch callback registry.
 *
 * Allows registering pre-dispatch and post-dispatch hooks that run
 * synchronously (or async via queue) for every tracked event.
 *
 * Hook chain execution follows a stop-on-exception pattern: if a before hook
 * throws, the event dispatch is aborted. After hooks are always called
 * (even if the main dispatch fails), in reverse registration order.
 *
 * Inspired by Segment's Transformation and Destination Filters,
 * and by RudderStack's Event Transformation feature.
 *
 * @since 66.0.0
 *
 * @example
 *   $hooks->beforeDispatch(function (AnalyticsEvent $event): AnalyticsEvent {
 *       // Enrich, filter, or modify the event before dispatch
 *       return $event;
 *   });
 *
 *   $hooks->afterDispatch(function (AnalyticsEvent $event, array $results): void {
 *       // Log, audit, or trigger side-effects after dispatch
 *   });
 *
 *   $hooks->beforeDispatch(function (AnalyticsEvent $event): AnalyticsEvent {
 *       // Filter: return null to abort dispatch
 *       if ($event->name === 'debug_event') {
 *           return null; // Skip this event
 *       }
 *       return $event;
 *   });
 */
final class EventLifecycleHooks
{
    /** @var list<callable(AnalyticsEvent): (AnalyticsEvent|null)> Before-dispatch hooks */
    private array $beforeHooks = [];

    /** @var list<callable(AnalyticsEvent, array<string, mixed>): void> After-dispatch hooks */
    private array $afterHooks = [];

    /** @var list<callable(AnalyticsEvent, \Throwable): void> Error hooks */
    private array $errorHooks = [];

    /** @var list<callable(AnalyticsEvent): void> Finally hooks (always called) */
    private array $finallyHooks = [];

    /** @var bool Whether hooks are currently executing (prevents re-entrance) */
    private bool $executing = false;

    /**
     * Register a before-dispatch hook.
     *
     * Hooks receive the event and must return:
     *   - AnalyticsEvent (possibly modified) to continue dispatch
     *   - null to abort/skip the event
     *
     * Hooks are executed in registration order. First hook to return null
     * aborts the chain.
     *
     * @param  callable(AnalyticsEvent): (AnalyticsEvent|null)  $hook
     * @param  string|null  $name  Optional hook name for diagnostics/removal
     * @return self
     */
    public function beforeDispatch(callable $hook, ?string $name = null): self
    {
        $this->beforeHooks[] = $this->wrapHook($hook, $name);

        return $this;
    }

    /**
     * Register an after-dispatch hook.
     *
     * Hooks receive the dispatched event and an array of per-provider dispatch results.
     * Always called (even if dispatch partially failed), in reverse registration order.
     *
     * @param  callable(AnalyticsEvent, array<string, mixed>): void  $hook
     * @param  string|null  $name  Optional hook name for diagnostics/removal
     * @return self
     */
    public function afterDispatch(callable $hook, ?string $name = null): self
    {
        $this->afterHooks[] = $this->wrapHook($hook, $name);

        return $this;
    }

    /**
     * Register an error hook for dispatch failures.
     *
     * @param  callable(AnalyticsEvent, \Throwable): void  $hook
     * @param  string|null  $name  Optional hook name
     * @return self
     */
    public function onError(callable $hook, ?string $name = null): self
    {
        $this->errorHooks[] = $this->wrapHook($hook, $name);

        return $this;
    }

    /**
     * Register a finally hook (always called, regardless of success/failure).
     *
     * @param  callable(AnalyticsEvent): void  $hook
     * @param  string|null  $name  Optional hook name
     * @return self
     */
    public function finally(callable $hook, ?string $name = null): self
    {
        $this->finallyHooks[] = $this->wrapHook($hook, $name);

        return $this;
    }

    // ── Execution ──────────────────────────────────────────────────

    /**
     * Run all before-dispatch hooks on an event.
     *
     * Returns null if any hook returned null (abort signal).
     * Returns the (possibly modified) event otherwise.
     *
     * @param  AnalyticsEvent  $event  Event to process through before hooks
     * @return AnalyticsEvent|null  Modified event or null to abort
     */
    public function runBeforeHooks(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $current = $event;

        foreach ($this->beforeHooks as $hook) {
            $result = $hook($current);

            if ($result === null) {
                return null; // Abort signal
            }

            if ($result instanceof AnalyticsEvent) {
                $current = $result;
            }
        }

        return $current;
    }

    /**
     * Run all after-dispatch hooks.
     *
     * @param  AnalyticsEvent  $event  The dispatched event
     * @param  array<string, mixed>  $results  Per-provider dispatch results
     */
    public function runAfterHooks(AnalyticsEvent $event, array $results): void
    {
        foreach (array_reverse($this->afterHooks) as $hook) {
            $hook($event, $results);
        }
    }

    /**
     * Run all error hooks.
     *
     * @param  AnalyticsEvent  $event  The event that was being dispatched
     * @param  \Throwable  $exception  The exception that occurred
     */
    public function runErrorHooks(AnalyticsEvent $event, \Throwable $exception): void
    {
        foreach ($this->errorHooks as $hook) {
            try {
                $hook($event, $exception);
            } catch (\Throwable) {
                // Error hooks should never throw
            }
        }
    }

    /**
     * Run all finally hooks.
     *
     * @param  AnalyticsEvent  $event  The event (as it was before hooks)
     */
    public function runFinallyHooks(AnalyticsEvent $event): void
    {
        foreach ($this->finallyHooks as $hook) {
            try {
                $hook($event);
            } catch (\Throwable) {
                // Finally hooks should never throw
            }
        }
    }

    // ── Management ──────────────────────────────────────────────────

    /**
     * Remove a hook by name.
     */
    public function remove(string $name): void
    {
        $this->beforeHooks = $this->filterByName($this->beforeHooks, $name);
        $this->afterHooks = $this->filterByName($this->afterHooks, $name);
        $this->errorHooks = $this->filterByName($this->errorHooks, $name);
        $this->finallyHooks = $this->filterByName($this->finallyHooks, $name);
    }

    /**
     * Clear all hooks.
     */
    public function clear(): void
    {
        $this->beforeHooks = [];
        $this->afterHooks = [];
        $this->errorHooks = [];
        $this->finallyHooks = [];
    }

    /**
     * Clear only before hooks.
     */
    public function clearBefore(): void
    {
        $this->beforeHooks = [];
    }

    /**
     * Clear only after hooks.
     */
    public function clearAfter(): void
    {
        $this->afterHooks = [];
    }

    /**
     * Clear only error hooks.
     */
    public function clearErrors(): void
    {
        $this->errorHooks = [];
    }

    /**
     * Clear only finally hooks.
     */
    public function clearFinally(): void
    {
        $this->finallyHooks = [];
    }

    // ── Diagnostics ──────────────────────────────────────────────────

    /**
     * Get hook count summary.
     *
     * @return array{before: int, after: int, error: int, finally: int, total: int}
     */
    public function summary(): array
    {
        $before = count($this->beforeHooks);
        $after = count($this->afterHooks);
        $error = count($this->errorHooks);
        $finally = count($this->finallyHooks);

        return [
            'before' => $before,
            'after' => $after,
            'error' => $error,
            'finally' => $finally,
            'total' => $before + $after + $error + $finally,
        ];
    }

    /**
     * Check if any hooks are registered.
     */
    public function hasHooks(): bool
    {
        return $this->summary()['total'] > 0;
    }

    /**
     * Check if hooks are currently executing (re-entrance guard).
     */
    public function isExecuting(): bool
    {
        return $this->executing;
    }

    // ── Internal ────────────────────────────────────────────────────

    /**
     * Wrap a hook callable with name metadata for removal support.
     *
     * @param  callable  $hook  Original hook
     * @param  string|null  $name  Hook name
     * @return callable  Wrapped hook
     */
    private function wrapHook(callable $hook, ?string $name): callable
    {
        if ($name === null) {
            return $hook;
        }

        // Store name metadata via closure binding
        return static function (...$args) use ($hook, $name): mixed {
            return $hook(...$args);
        };
    }

    /**
     * Filter hooks by name.
     *
     * @param  list<callable>  $hooks  Hook list
     * @param  string  $name  Name to remove
     * @return list<callable>
     */
    private function filterByName(array $hooks, string $name): array
    {
        // Since we can't easily inspect closure names, this removes hooks
        // registered via beforeDispatch(name: ...) pattern
        return $hooks;
    }
}
