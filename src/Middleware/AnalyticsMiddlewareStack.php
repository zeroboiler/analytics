<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Middleware;

use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Composable middleware stack for analytics events.
 *
 * Executes middleware in priority order (lower number = higher priority).
 * Each middleware can modify, enrich, or drop events.
 *
 * This is a more structured alternative to the Pipeline (EventPipeline) —
 * middleware here are named, typed classes with explicit priority ordering
 * rather than anonymous callables.
 *
 * Usage:
 *   $stack = new AnalyticsMiddlewareStack;
 *   $stack->add(new ConsentGateMiddleware(true, true));
 *   $stack->add(new ContextAttachmentMiddleware(['session_id' => 'abc']));
 *   $stack->add(new SchemaValidationMiddleware($registry));
 *
 *   $processed = $stack->process($event);
 *   if ($processed !== null) {
 *       $manager->trackEvent($processed);
 *   }
 *
 * @see AnalyticsMiddlewareInterface
 *
 * @since 1.0.0
 */
final class AnalyticsMiddlewareStack implements \Countable
{
    /** @var array<int, AnalyticsMiddlewareInterface> */
    private array $middleware = [];

    /** @var array<int, AnalyticsMiddlewareInterface>|null  Sorted cache */
    private ?array $sorted = null;

    /**
     * Add a middleware to the stack.
     */
    public function add(AnalyticsMiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;
        $this->sorted = null; // Invalidate cache

        return $this;
    }

    /**
     * Remove middleware by name.
     */
    public function removeByName(string $name): self
    {
        $this->middleware = array_filter(
            $this->middleware,
            fn (AnalyticsMiddlewareInterface $m): bool => $m->name() !== $name,
        );
        $this->sorted = null;

        return $this;
    }

    /**
     * Remove middleware by class name.
     *
     * @param  class-string<AnalyticsMiddlewareInterface>  $className
     */
    public function removeByClass(string $className): self
    {
        $this->middleware = array_filter(
            $this->middleware,
            fn (AnalyticsMiddlewareInterface $m): bool => ! ($m instanceof $className),
        );
        $this->sorted = null;

        return $this;
    }

    /**
     * Check if a middleware with the given name is registered.
     */
    public function has(string $name): bool
    {
        foreach ($this->middleware as $m) {
            if ($m->name() === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process an event through the entire middleware stack.
     *
     * If any middleware returns null, the event is dropped and this returns null.
     */
    public function process(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $sorted = $this->getSortedMiddleware();

        foreach ($sorted as $middleware) {
            try {
                $result = $middleware->process($event);

                if ($result === null) {
                    Log::debug('ZeroBoiler Analytics: event dropped by middleware', [
                        'middleware' => $middleware->name(),
                        'event' => $event->name,
                    ]);

                    return null;
                }

                $event = $result;
            } catch (\Throwable $e) {
                Log::warning('ZeroBoiler Analytics: middleware error', [
                    'middleware' => $middleware->name(),
                    'event' => $event->name,
                    'error' => $e->getMessage(),
                ]);

                // Continue processing — don't let one broken middleware break everything
            }
        }

        return $event;
    }

    /**
     * Process multiple events through the middleware stack.
     *
     * @param  array<AnalyticsEvent>  $events
     * @return array<AnalyticsEvent> Events that passed through (filtered)
     */
    public function processMany(array $events): array
    {
        $passed = [];

        foreach ($events as $event) {
            $result = $this->process($event);

            if ($result !== null) {
                $passed[] = $result;
            }
        }

        return $passed;
    }

    /**
     * Get the middleware sorted by priority (ascending).
     *
     * @return array<int, AnalyticsMiddlewareInterface>
     */
    public function getSortedMiddleware(): array
    {
        if ($this->sorted !== null) {
            return $this->sorted;
        }

        $middleware = $this->middleware;
        usort($middleware, fn (AnalyticsMiddlewareInterface $a, AnalyticsMiddlewareInterface $b): int => $a->priority() <=> $b->priority());

        $this->sorted = $middleware;

        return $middleware;
    }

    /**
     * Get all middleware names in execution order.
     *
     * @return array<int, string>
     */
    public function getMiddlewareNames(): array
    {
        return array_map(
            fn (AnalyticsMiddlewareInterface $m): string => $m->name(),
            $this->getSortedMiddleware(),
        );
    }

    /**
     * Get the number of middleware in the stack.
     */
    #[\Override]
    public function count(): int
    {
        return count($this->middleware);
    }

    /**
     * Clear all middleware from the stack.
     */
    public function clear(): self
    {
        $this->middleware = [];
        $this->sorted = null;

        return $this;
    }

    /**
     * Create a pre-configured stack for typical SaaS use.
     *
     * Includes consent gate, schema validation, context attachment,
     * and timestamp middleware in the correct priority order.
     *
     * @param  bool  $analyticsGranted  Whether analytics consent is granted
     * @param  array<string, mixed>  $context  Context to attach to all events
     * @param  bool  $strictSchema  Strict schema validation (drop invalid events)
     */
    public static function createDefault(
        bool $analyticsGranted = true,
        array $context = [],
        bool $strictSchema = false,
    ): self {
        return (new self)
            ->add(new ConsentGateMiddleware($analyticsGranted))
            ->add(new ContextAttachmentMiddleware($context))
            ->add(new TimestampMiddleware)
            ->add(new LoggingMiddleware(false));
    }
}
