<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;

/**
 * First-touch UTM attribution middleware.
 *
 * Captures UTM parameters from the first visit and persists them in a
 * long-lived cookie. Subsequent visits inherit the original attribution
 * context, enabling cross-session and cross-device attribution.
 *
 * The cookie stores a JSON-encoded map of UTM parameters plus the
 * landing page URL and timestamp of the first visit.
 *
 * Register as a global middleware (before HandleInertiaAnalytics):
 *   ->middleware(\ZeroBoiler\Analytics\Middleware\FirstTouchUTMMiddleware::class)
 *
 * @since 6.3.0
 */
final class FirstTouchUTMMiddleware
{
    /** Standard UTM parameter names to capture. */
    private const UTM_PARAMS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
    ];

    /**
     * Handle an incoming request.
     *
     * Reads first-touch UTM data from cookie. If no cookie exists and the
     * current request has UTM parameters, persists them as the first touch.
     *
     * Stores the resolved first-touch data on the request attributes as
     * `_zb_first_touch` for use by other middleware and services.
     */
    #[Override]
    public function handle(Request $request, Closure $next): Response
    {
        $cookieName = $this->cookieName();
        $firstTouch = $this->resolveFirstTouch($request, $cookieName);

        $request->attributes->set('_zb_first_touch', $firstTouch);

        // Persist first-touch if this is a new UTM-bearing visit
        if ($firstTouch['is_new'] ?? false) {
            Cookie::queue(
                $cookieName,
                json_encode($firstTouch['data'], JSON_THROW_ON_ERROR),
                $this->cookieTtl(),
                '/',
                $this->cookieDomain(),
                $this->cookieSecure(),
                true,  // httpOnly
                false, // raw
                'Lax',
            );
        }

        return $next($request);
    }

    /**
     * Resolve first-touch UTM data.
     *
     * Returns existing cookie data if present, otherwise captures current
     * UTM parameters if available.
     *
     * @param  Request  $request
     * @param  string  $cookieName
     * @return array{data: array<string, mixed>, is_new: bool}
     */
    private function resolveFirstTouch(Request $request, string $cookieName): array
    {
        $existing = $request->cookie($cookieName);

        if (is_string($existing) && $existing !== '') {
            $decoded = json_decode($existing, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($decoded)) {
                return [
                    'data' => $this->normalizeFirstTouch($decoded),
                    'is_new' => false,
                ];
            }
        }

        // Capture current UTM parameters
        $currentUtm = $this->extractUtm($request);

        if (! empty($currentUtm)) {
            $firstTouch = array_merge($currentUtm, [
                '_landing_page' => $request->path(),
                '_landing_url' => $request->fullUrl(),
                '_first_seen_at' => now()->toIso8601String(),
            ]);

            return [
                'data' => $this->normalizeFirstTouch($firstTouch),
                'is_new' => true,
            ];
        }

        // No existing cookie and no UTM — return empty
        return [
            'data' => [],
            'is_new' => false,
        ];
    }

    /**
     * Extract UTM parameters from the current request.
     *
     * @param  Request  $request
     * @return array<string, string>
     */
    private function extractUtm(Request $request): array
    {
        $utm = [];

        foreach (self::UTM_PARAMS as $param) {
            $value = $request->query($param);

            if (is_string($value) && $value !== '') {
                $utm[$param] = $value;
            }
        }

        return $utm;
    }

    /**
     * Normalize first-touch data to ensure consistent structure.
     *
     * Filters out internal metadata keys from the main UTM map
     * and ensures all values are strings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function normalizeFirstTouch(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $normalized[$key] = (string) $value;
            } elseif (is_bool($value)) {
                $normalized[$key] = $value ? 'true' : 'false';
            }
        }

        return $normalized;
    }

    /**
     * Get the cookie name from config.
     */
    private function cookieName(): string
    {
        $name = config('zeroboiler.analytics.first_touch.cookie_name', 'zb_first_touch');

        return is_string($name) && $name !== '' ? $name : 'zb_first_touch';
    }

    /**
     * Get the cookie TTL in minutes from config.
     */
    private function cookieTtl(): int
    {
        return (int) config('zeroboiler.analytics.first_touch.cookie_ttl', 525600); // 365 days
    }

    /**
     * Get the cookie domain from config.
     */
    private function cookieDomain(): ?string
    {
        $domain = config('zeroboiler.analytics.first_touch.cookie_domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    /**
     * Get the cookie secure flag from config.
     */
    private function cookieSecure(): bool
    {
        return (bool) config('zeroboiler.analytics.first_touch.cookie_secure', true);
    }
}
