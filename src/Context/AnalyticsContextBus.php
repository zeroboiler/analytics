<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Context;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

/**
 * Request-scoped analytics context bus.
 *
 * Collects and stores contextual data for the current HTTP request that
 * should be automatically attached to all analytics events dispatched
 * during this request lifecycle. Inspired by the Segment/RudderStack
 * "context" object pattern.
 *
 * Context data includes:
 * - Session info (ID, start time)
 * - Device info (user agent, OS, browser, screen)
 * - Geo info (IP, country, region, city — when geo service available)
 * - UTM parameters (first-touch and current-touch)
 * - Referrer information
 * - Tenant/Workspace context (multi-tenant SaaS)
 * - Feature flags (when feature flag service available)
 * - App context (version, environment, framework)
 *
 * Context is lazy-collected on first access and cached for the request.
 * Use `Analytics::withContext($overrides)` to add custom context.
 *
 * @since 17.0.0
 */
final class AnalyticsContextBus
{
    /** @var array<string, mixed> Collected context data */
    private array $context = [];

    /** @var array<string, mixed> User-provided context overrides */
    private array $overrides = [];

    /** @var bool Whether context has been initialized for this request */
    private bool $initialized = false;

    private ConfigRepository $config;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config){
        $this->config = $config;
    }

    /**
     * Initialize context from the current HTTP request.
     *
     * Called once per request lifecycle. Safe to call multiple times —
     * subsequent calls are no-ops.
     *
     * @param  Request  $request
     * @return void
     */
    public function initialize(Request $request): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        $this->context = [
            // App context
            'app' => [
                'name' => $this->config->get('app.name', 'Laravel'),
                'env' => $this->config->get('app.env', 'production'),
                'version' => $this->config->get('app.version', 'unknown'),
                'framework' => 'Laravel',
                'analytics_version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            ],

            // Device context
            'device' => $this->buildDeviceContext($request),

            // Session context
            'session' => $this->buildSessionContext($request),

            // Page/URL context
            'page' => [
                'url' => $request->fullUrl(),
                'path' => $request->path(),
                'referrer' => $request->headers->get('referer'),
                'search' => $request->query(),
            ],

            // UTM context
            'utm' => $this->buildUtmContext($request),

            // Geo context (IP only — geo resolution via external service)
            'geo' => $this->buildGeoContext($request),

            // Locale
            'locale' => $request->locale(),

            // Timestamp
            'initialized_at' => now()->toIso8601String(),
        ];

        // Tenant context (multi-tenant SaaS)
        $tenant = $this->buildTenantContext($request);
        if ($tenant !== []) {
            $this->context['tenant'] = $tenant;
        }

        // Feature flags context (lazy — only when service available)
        $flags = $this->buildFeatureFlagContext();
        if ($flags !== []) {
            $this->context['features'] = $flags;
        }
    }

    /**
     * Get the full context array (merged with overrides).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->context, $this->overrides);
    }

    /**
     * Get a specific context value by dot-notation key.
     *
     * @param  string  $key  Dot-notation key (e.g. 'device.userAgent')
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $context = $this->all();

        $parts = explode('.', $key);
        $current = $context;

        foreach ($parts as $part) {
            if (! is_array($current) || ! array_key_exists($part, $current)) {
                return $default;
            }

            $current = $current[$part];
        }

        return $current;
    }

    /**
     * Set a context override value.
     *
     * Overrides are merged on top of auto-collected context.
     * Does NOT modify the base context.
     *
     * @param  string  $key  Dot-notation key
     * @param  mixed  $value
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->overrides[$key] = $value;
    }

    /**
     * Merge an array of context overrides.
     *
     * @param  array<string, mixed>  $data
     * @return void
     */
    public function merge(array $data): void
    {
        $this->overrides = array_merge($this->overrides, $data);
    }

    /**
     * Remove a context key (including from overrides).
     *
     * @param  string  $key  Dot-notation key
     * @return void
     */
    public function remove(string $key): void
    {
        unset($this->overrides[$key]);
    }

    /**
     * Clear all overrides and reset to base context.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->overrides = [];
    }

    /**
     * Check if context has been initialized.
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * Get the collected context without overrides.
     *
     * @return array<string, mixed>
     */
    public function base(): array
    {
        return $this->context;
    }

    /**
     * Get only the overrides.
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        return $this->overrides;
    }

    /**
     * Build context as event parameters for auto-enrichment.
     *
     * Returns a flat array of params prefixed with `_ctx_` that can be
     * merged into any AnalyticsEvent params without conflicting with
     * user-defined parameters.
     *
     * @return array<string, mixed>
     */
    public function asEventParams(): array
    {
        $context = $this->all();
        $params = [];

        $this->flattenContext($context, $params, '_ctx_');

        return $params;
    }

    /**
     * Get a summary of the context for diagnostic output.
     *
     * @return array{initialized: bool, has_device: bool, has_session: bool, has_utm: bool, has_tenant: bool, has_features: bool, override_count: int, app_env: string|null, app_name: string|null}
     */
    public function summary(): array
    {
        $context = $this->all();

        return [
            'initialized' => $this->initialized,
            'has_device' => isset($context['device']),
            'has_session' => isset($context['session']),
            'has_utm' => ! empty($context['utm']),
            'has_tenant' => isset($context['tenant']),
            'has_features' => isset($context['features']),
            'override_count' => count($this->overrides),
            'app_env' => $context['app']['env'] ?? null,
            'app_name' => $context['app']['name'] ?? null,
        ];
    }

    /**
     * Build device context from the request.
     *
     * @param  Request  $request
     * @return array{userAgent: string, ip: string, acceptLanguage: string|null}
     */
    private function buildDeviceContext(Request $request): array
    {
        return [
            'userAgent' => $request->userAgent() ?? '',
            'ip' => $request->ip() ?? '',
            'acceptLanguage' => $request->headers->get('accept-language'),
        ];
    }

    /**
     * Build session context from the request.
     *
     * @param  Request  $request
     * @return array{id: string|null}
     */
    private function buildSessionContext(Request $request): array
    {
        return [
            'id' => $request->session()->getId(),
        ];
    }

    /**
     * Build UTM context from the request.
     *
     * @param  Request  $request
     * @return array<string, string|null>
     */
    private function buildUtmContext(Request $request): array
    {
        return [
            'source' => $request->query('utm_source'),
            'medium' => $request->query('utm_medium'),
            'campaign' => $request->query('utm_campaign'),
            'term' => $request->query('utm_term'),
            'content' => $request->query('utm_content'),
            'id' => $request->query('utm_id'),
        ];
    }

    /**
     * Build geo context (IP-based, no external resolution).
     *
     * @param  Request  $request
     * @return array{ip: string}
     */
    private function buildGeoContext(Request $request): array
    {
        return [
            'ip' => $request->ip() ?? '',
        ];
    }

    /**
     * Build tenant context from the authenticated user.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    private function buildTenantContext(Request $request): array
    {
        $tenantContext = $this->config->get('zeroboiler.analytics.tenant_context', []);

        if (empty($tenantContext) || ! (bool) ($tenantContext['enabled'] ?? false)) {
            return [];
        }

        $user = $request->user();

        if ($user === null) {
            return [];
        }

        $context = [];

        $tenantIdField = $tenantContext['tenant_id_field'] ?? 'tenant_id';
        $tenantNameField = $tenantContext['tenant_name_field'] ?? 'tenant_name';

        if (method_exists($user, 'getAttribute')) {
            $tenantId = $user->getAttribute($tenantIdField);
            $tenantName = $user->getAttribute($tenantNameField);

            if ($tenantId !== null) {
                $context['id'] = (string) $tenantId;

                if ($tenantName !== null) {
                    $context['name'] = (string) $tenantName;
                }
            }
        }

        return $context;
    }

    /**
     * Build feature flag context.
     *
     * Lazy — only populated when a feature flag service is available.
     *
     * @return array<string, bool>
     */
    private function buildFeatureFlagContext(): array
    {
        $featureConfig = $this->config->get('zeroboiler.analytics.feature_flags', []);

        if (empty($featureConfig) || ! (bool) ($featureConfig['enabled'] ?? false)) {
            return [];
        }

        try {
            // Attempt to resolve feature flag service
            $resolver = $featureConfig['resolver'] ?? null;

            if ($resolver !== null && is_string($resolver) && class_exists($resolver)) {
                $service = app($resolver);

                if (method_exists($service, 'all')) {
                    $flags = $service->all();

                    if (is_array($flags)) {
                        return array_map(
                            static fn (mixed $value): bool => (bool) $value,
                            $flags,
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            // Feature flag service not available
        }

        return [];
    }

    /**
     * Flatten a nested context array into dot-notation keys.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $result
     * @param  string  $prefix
     * @return void
     */
    private function flattenContext(array $data, array &$result, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? $key : "{$prefix}{$key}";

            if (is_array($value) && ! empty($value)) {
                $this->flattenContext($value, $result, "{$fullKey}.");
            } elseif (is_scalar($value) || is_null($value)) {
                $result[$fullKey] = $value;
            }
        }
    }
}
