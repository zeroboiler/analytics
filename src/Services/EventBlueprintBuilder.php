<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Analytics Event Blueprint Builder for declarative event creation.
 *
 * Provides a fluent API for building analytics events with chained methods.
 * Supports parameter accumulation, type-safe defaults, validation hints,
 * and cross-provider enrichment hints (UTM, session context, device info).
 *
 * Complements EventTemplateEngine with a programmatic (non-config) approach
 * for one-off or dynamically composed events.
 *
 * @since 140.0.0
 *
 * @example
 *   $event = BlueprintBuilder::make('button_click')
 *       ->param('element', 'buy_now')
 *       ->param('page', '/pricing')
 *       ->param('variant', 'red_cta')
 *       ->withUtm()
 *       ->build();
 */
final class EventBlueprintBuilder
{
    private string $name;

    /** @var array<string, mixed> */
    private array $params = [];

    private ?string $clientId = null;

    private ?string $userId = null;

    private ?string $priority = null;

    private ?string $source = null;

    private bool $attachUtm = false;

    private bool $attachSession = false;

    private bool $attachDevice = false;

    private ConfigRepository $config;

    private function __construct(string $name, ConfigRepository $config){
        $this->name = $name;
        $this->config = $config;
    }

    /**
     * Create a new blueprint builder for the given event name.
     */
    public static function make(string $name): self
    {
        /** @var ConfigRepository $config */
        $config = app(ConfigRepository::class);

        return new self($name, $config);
    }

    /**
     * Create a blueprint builder with explicit config (for testing).
     */
    public static function makeWithConfig(string $name, ConfigRepository $config): self
    {
        return new self($name, $config);
    }

    /**
     * Set a parameter value.
     *
     * @param  string  $key  Parameter key
     * @param  mixed  $value  Parameter value
     * @return self
     */
    public function param(string $key, mixed $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Set multiple parameters at once.
     *
     * @param  array<string, mixed>  $params
     * @return self
     */
    public function params(array $params): self
    {
        foreach ($params as $key => $value) {
            $this->params[$key] = $value;
        }

        return $this;
    }

    /**
     * Set the client ID.
     *
     * @return self
     */
    public function clientId(string $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    /**
     * Set the user ID.
     *
     * @return self
     */
    public function userId(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Set the event priority.
     *
     * @return self
     */
    public function priority(string $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Mark as critical priority.
     *
     * @return self
     */
    public function critical(): self
    {
        $this->priority = 'critical';

        return $this;
    }

    /**
     * Set the event source.
     *
     * @return self
     */
    public function source(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Mark as server-side source.
     *
     * @return self
     */
    public function fromServer(): self
    {
        $this->source = 'server';

        return $this;
    }

    /**
     * Mark as API source.
     *
     * @return self
     */
    public function fromApi(): self
    {
        $this->source = 'api';

        return $this;
    }

    /**
     * Mark as client-side source.
     *
     * @return self
     */
    public function fromClient(): self
    {
        $this->source = 'client';

        return $this;
    }

    /**
     * Enable UTM parameter attachment from current request.
     *
     * @return self
     */
    public function withUtm(): self
    {
        $this->attachUtm = true;

        return $this;
    }

    /**
     * Enable session context attachment.
     *
     * @return self
     */
    public function withSession(): self
    {
        $this->attachSession = true;

        return $this;
    }

    /**
     * Enable device context attachment.
     *
     * @return self
     */
    public function withDevice(): self
    {
        $this->attachDevice = true;

        return $this;
    }

    /**
     * Enable all automatic enrichment (UTM + session + device).
     *
     * @return self
     */
    public function withAllEnrichment(): self
    {
        $this->attachUtm = true;
        $this->attachSession = true;
        $this->attachDevice = true;

        return $this;
    }

    /**
     * Build the final AnalyticsEvent instance.
     */
    public function build(): AnalyticsEvent
    {
        $params = $this->params;

        if ($this->attachUtm) {
            $params = $this->attachUtmParams($params);
        }

        if ($this->attachSession) {
            $params = $this->attachSessionContext($params);
        }

        if ($this->attachDevice) {
            $params = $this->attachDeviceContext($params);
        }

        return new AnalyticsEvent(
            name: $this->name,
            params: $params,
            clientId: $this->clientId,
            userId: $this->userId,
            priority: $this->priority,
            source: $this->source,
        );
    }

    /**
     * Build and return the event as an array (for serialization).
     *
     * @return array{name: string, params: array<string, mixed>, client_id: string|null, user_id: string|null, priority: string|null, source: string|null}
     */
    public function toArray(): array
    {
        $event = $this->build();

        return $event->toArray();
    }

    /**
     * Get the current parameter count.
     */
    public function paramCount(): int
    {
        return count($this->params);
    }

    /**
     * Get the event name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Check if a parameter key is set.
     */
    public function hasParam(string $key): bool
    {
        return array_key_exists($key, $this->params);
    }

    /**
     * Get the current accumulated parameters (without enrichment).
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Attach UTM parameters from the current request.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function attachUtmParams(array $params): array
    {
        try {
            $request = request();
            $utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

            foreach ($utmKeys as $key) {
                $value = $request->query($key);
                if ($value !== null && ! isset($params[$key])) {
                    $params[$key] = $value;
                }
            }
        } catch (\Throwable $e) {
            // Request may not be available in CLI context
        }

        return $params;
    }

    /**
     * Attach session context from the current request.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function attachSessionContext(array $params): array
    {
        try {
            $request = request();

            if (! isset($params['session_id'])) {
                $params['session_id'] = $request->session()->getId();
            }

            if (! isset($params['page_url'])) {
                $params['page_url'] = $request->fullUrl();
            }

            if (! isset($params['referrer'])) {
                $params['referrer'] = $request->headers->get('referer');
            }
        } catch (\Throwable $e) {
            // Request may not be available in CLI context
        }

        return $params;
    }

    /**
     * Attach device context from the current request.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function attachDeviceContext(array $params): array
    {
        try {
            $request = request();

            if (! isset($params['user_agent'])) {
                $params['user_agent'] = $request->userAgent();
            }

            if (! isset($params['ip'])) {
                $params['ip'] = $request->ip();
            }

            if (! isset($params['locale'])) {
                $params['locale'] = $request->locale();
            }
        } catch (\Throwable $e) {
            // Request may not be available in CLI context
        }

        return $params;
    }
}
