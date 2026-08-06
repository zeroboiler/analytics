<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
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
 */
class EventContextBuilder
{
    /** @var array<string, mixed> */
    private array $context = [];

    private ?Request $request;

    private ?string $cookieName;

    /**
     * @param  Request|null  $request  Current HTTP request (null for CLI/queue context)
     * @param  string|null  $cookieName  Analytics client ID cookie name
     */
    public function __construct(?Request $request = null, ?string $cookieName = null)
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
}
