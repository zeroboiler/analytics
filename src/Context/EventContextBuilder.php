<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Context;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Builds rich context from the current HTTP request for analytics events.
 *
 * Automatically collects:
 * - User identity (ID, email hash, plan/role)
 * - Client tracking ID (from cookie or header)
 * - Session data
 * - UTM campaign parameters
 * - Page/URL information
 * - Device information (user agent, IP)
 * - Custom properties
 *
 * This context can be merged into any analytics event before dispatch.
 *
 * @since 1.0.0
 */
final class EventContextBuilder
{
    /** @var array<string, mixed> */
    private array $context = [];

    private ?Request $request;

    private ?string $cookieName;

    /**
     * @param  Request|null  $request  Current HTTP request (null for CLI/queue context)
     * @param  string|null  $cookieName  Analytics client ID cookie name
     */
    public function __construct(?Request $request = null, ?string $cookieName = null): void
    {
        $this->request = $request;
        $this->cookieName = $cookieName ?? 'zb_analytics_id';
    }

    /**
     * Collect all available context automatically.
     *
     * Shorthand for calling all individual collectors.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return $this
            ->withUserIdentity()
            ->withClientId()
            ->withSession()
            ->withUTM()
            ->withPage()
            ->withDevice()
            ->getContext();
    }

    /**
     * Add user identity context (ID, email hash).
     *
     * @param  array<string, mixed>|null  $override  Override user context (e.g. for queue jobs)
     */
    public function withUserIdentity(?array $override = null): self
    {
        if ($override !== null) {
            foreach ($override as $key => $value) {
                $this->context[$key] = $value;
            }

            return $this;
        }

        $user = $this->getAuthenticatedUser();

        if ($user !== null) {
            $id = $user->getAuthIdentifier();
            $this->context['user_id'] = is_int($id) || is_string($id) ? (string) $id : null;

            // Hash email for privacy (never send raw emails to analytics)
            if (method_exists($user, 'getAttribute') && $user->getAttribute('email') !== null) {
                $email = (string) $user->getAttribute('email');
                $this->context['user_email_hash'] = hash('sha256', strtolower(trim($email)));
            }

            // Capture common user properties
            if (method_exists($user, 'getAttribute')) {
                $plan = $user->getAttribute('plan');
                if (is_string($plan) && $plan !== '') {
                    $this->context['user_plan'] = $plan;
                }

                $role = $user->getAttribute('role');
                if (is_string($role) && $role !== '') {
                    $this->context['user_role'] = $role;
                }

                $createdAt = $user->getAttribute('created_at');
                if ($createdAt instanceof \DateTimeInterface) {
                    $this->context['user_created_at'] = $createdAt->format('Y-m-d');
                }
            }
        }

        return $this;
    }

    /**
     * Add client tracking ID from cookie or header.
     */
    public function withClientId(?string $override = null): self
    {
        if ($override !== null) {
            $this->context['client_id'] = $override;

            return $this;
        }

        if ($this->request !== null) {
            // Check header first
            $header = $this->request->header('X-Analytics-Client-Id');
            if (is_string($header) && $header !== '') {
                $this->context['client_id'] = $header;

                return $this;
            }

            // Fall back to cookie
            $cookie = $this->request->cookie($this->cookieName);
            if (is_string($cookie) && $cookie !== '') {
                $this->context['client_id'] = $cookie;
            }
        }

        return $this;
    }

    /**
     * Add session context.
     */
    public function withSession(?string $sessionId = null): self
    {
        if ($sessionId !== null) {
            $this->context['session_id'] = $sessionId;

            return $this;
        }

        if ($this->request !== null && $this->request->hasSession()) {
            $this->context['session_id'] = $this->request->session()->getId();
        }

        return $this;
    }

    /**
     * Add UTM campaign parameters from the request.
     */
    public function withUTM(?array $override = null): self
    {
        if ($override !== null) {
            foreach ($override as $key => $value) {
                $this->context[$key] = $value;
            }

            return $this;
        }

        if ($this->request !== null) {
            $utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

            foreach ($utmKeys as $key) {
                $value = $this->request->query($key);
                if (is_string($value) && $value !== '') {
                    $this->context[$key] = $value;
                }
            }

            // First-touch attribution: check referrer
            $referrer = $this->request->headers->get('referer');
            if (is_string($referrer) && $referrer !== '' && ! isset($this->context['utm_source'])) {
                $this->context['referrer_source'] = parse_url($referrer, PHP_URL_HOST) ?: $referrer;
            }
        }

        return $this;
    }

    /**
     * Add page/URL context.
     */
    public function withPage(?array $override = null): self
    {
        if ($override !== null) {
            foreach ($override as $key => $value) {
                $this->context[$key] = $value;
            }

            return $this;
        }

        if ($this->request !== null) {
            $this->context['page_url'] = $this->request->fullUrl();
            $this->context['page_path'] = $this->request->path();
            $this->context['page_query'] = $this->request->getQueryString();
        }

        return $this;
    }

    /**
     * Add device/request context.
     */
    public function withDevice(?array $override = null): self
    {
        if ($override !== null) {
            foreach ($override as $key => $value) {
                $this->context[$key] = $value;
            }

            return $this;
        }

        if ($this->request !== null) {
            $this->context['ip'] = $this->request->ip();
            $this->context['user_agent'] = $this->request->userAgent() ?? '';
            $this->context['locale'] = $this->request->getLocale();
            $this->context['method'] = $this->request->method();
        }

        return $this;
    }

    /**
     * Add full referrer context with parsed components.
     *
     * Extracts referrer host, path, search terms (from query params),
     * and search engine detection for attribution analysis.
     *
     * @param  string|null  $override  Override the referrer URL
     */
    public function withReferrer(?string $override = null): self
    {
        $referrerUrl = $override;

        if ($referrerUrl === null && $this->request !== null) {
            $header = $this->request->headers->get('referer');
            if (is_string($header) && $header !== '') {
                $referrerUrl = $header;
            }
        }

        if ($referrerUrl === null || $referrerUrl === '') {
            return $this;
        }

        $this->context['referrer_url'] = $referrerUrl;

        $parsed = parse_url($referrerUrl);

        if (is_array($parsed)) {
            $this->context['referrer_host'] = $parsed['host'] ?? null;
            $this->context['referrer_path'] = $parsed['path'] ?? null;

            // Detect search engine and extract search terms
            $host = $parsed['host'] ?? '';
            $query = $parsed['query'] ?? '';

            if ($query !== '') {
                parse_str($query, $queryParams);

                $searchTerm = $this->extractSearchTerm($host, $queryParams);
                if ($searchTerm !== null) {
                    $this->context['referrer_search_term'] = $searchTerm;
                    $this->context['referrer_search_engine'] = $this->detectSearchEngine($host);
                }
            }
        }

        return $this;
    }

    /**
     * Add multi-tenant context for B2B SaaS applications.
     *
     * Extracts tenant/team/organization context from the authenticated user
     * or from a custom header. Useful for multi-tenant analytics isolation
     * and per-organization reporting.
     *
     * @param  string|null  $tenantId  Override tenant ID (e.g. from queue job payload)
     * @param  string|null  $tenantName  Override tenant name
     */
    public function withTenancy(?string $tenantId = null, ?string $tenantName = null): self
    {
        if ($tenantId !== null) {
            $this->context['tenant_id'] = $tenantId;

            if ($tenantName !== null) {
                $this->context['tenant_name'] = $tenantName;
            }

            return $this;
        }

        $user = $this->getAuthenticatedUser();

        if ($user !== null && method_exists($user, 'getAttribute')) {
            // Common tenant attribute names
            $tenantAttributes = ['tenant_id', 'team_id', 'organization_id', 'workspace_id', 'account_id'];
            $nameAttributes = ['tenant_name', 'team_name', 'organization_name', 'workspace_name', 'account_name'];

            foreach ($tenantAttributes as $attr) {
                $value = $user->getAttribute($attr);
                if (is_string($value) && $value !== '') {
                    $this->context['tenant_id'] = $value;
                    break;
                }
            }

            foreach ($nameAttributes as $attr) {
                $value = $user->getAttribute($attr);
                if (is_string($value) && $value !== '') {
                    $this->context['tenant_name'] = $value;
                    break;
                }
            }

            // Also check X-Tenant-Id header for API/queue contexts
            if (! isset($this->context['tenant_id']) && $this->request !== null) {
                $headerTenant = $this->request->header('X-Tenant-Id');
                if (is_string($headerTenant) && $headerTenant !== '') {
                    $this->context['tenant_id'] = $headerTenant;
                }
            }
        }

        return $this;
    }

    /**
     * Add custom properties to the context.
     *
     * @param  array<string, mixed>  $properties
     */
    public function withCustom(array $properties): self
    {
        foreach ($properties as $key => $value) {
            $this->context[$key] = $value;
        }

        return $this;
    }

    /**
     * Get the built context array.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set a single context value.
     */
    public function set(string $key, mixed $value): self
    {
        $this->context[$key] = $value;

        return $this;
    }

    /**
     * Remove a key from the context.
     */
    public function without(string $key): self
    {
        unset($this->context[$key]);

        return $this;
    }

    /**
     * Clear all collected context.
     */
    public function clear(): self
    {
        $this->context = [];

        return $this;
    }

    /**
     * Check if the context has a specific key.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->context);
    }

    /**
     * Get a specific context value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    /**
     * Get the authenticated user from the current request/guard.
     */
    private function getAuthenticatedUser(): ?Authenticatable
    {
        try {
            return Auth::user();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extract the search term from a referrer's query parameters.
     *
     * Supports Google, Bing, Yahoo, DuckDuckGo, Baidu, Yandex, and generic `q`/`search` params.
     *
     * @param  string  $host  Referrer host
     * @param  array<string, string>  $queryParams  Parsed query parameters
     * @return string|null  Extracted search term or null
     */
    private function extractSearchTerm(string $host, array $queryParams): ?string
    {
        $queryParamMap = [
            'google' => ['q'],
            'bing' => ['q'],
            'yahoo' => ['p'],
            'duckduckgo' => ['q'],
            'baidu' => ['wd', 'word'],
            'yandex' => ['text'],
        ];

        foreach ($queryParamMap as $engine => $params) {
            if (str_contains($host, $engine)) {
                foreach ($params as $param) {
                    $value = $queryParams[$param] ?? null;
                    if (is_string($value) && $value !== '') {
                        return $value;
                    }
                }

                return null;
            }
        }

        // Fallback: check generic query params
        foreach (['q', 'search', 'query', 'keyword'] as $param) {
            $value = $queryParams[$param] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Detect the search engine from a referrer host.
     *
     * @param  string  $host  Referrer host
     * @return string|null  Search engine name or null
     */
    private function detectSearchEngine(string $host): ?string
    {
        $engines = [
            'google' => 'Google',
            'bing' => 'Bing',
            'yahoo' => 'Yahoo',
            'duckduckgo' => 'DuckDuckGo',
            'baidu' => 'Baidu',
            'yandex' => 'Yandex',
        ];

        foreach ($engines as $domain => $name) {
            if (str_contains($host, $domain)) {
                return $name;
            }
        }

        return null;
    }
}
