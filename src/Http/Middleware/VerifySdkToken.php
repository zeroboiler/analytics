<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ZeroBoiler\Analytics\Services\SdkScopeTokenService;

/**
 * SDK Token Authentication Middleware.
 *
 * Validates incoming requests against scoped SDK tokens.
 * Tokens are checked via the SdkScopeTokenService which validates
 * token hash, expiration, permission scope, and rate limits.
 *
 * When SDK auth is disabled (sdk_auth.enabled = false), the middleware
 * passes requests through without checking — allowing Sanctum-based
 * auth to handle authorization instead.
 *
 * Token can be passed via:
 * - Authorization: Bearer <token> header
 * - X-ZB-SDK-Token header
 * - zb_sdk_token query parameter (for simple integrations)
 *
 * @see \ZeroBoiler\Analytics\Services\SdkScopeTokenService
 *
 * @since 77.0.0
 */
final class VerifySdkToken
{
    private const HEADER_BEARER = 'Authorization';

    private const HEADER_SDK = 'X-ZB-SDK-Token';

    private const QUERY_PARAM = 'zb_sdk_token';

    private SdkScopeTokenService $tokenService;

    private bool $enabled;

    /** @var string Permission required for the current route group */
    private string $requiredPermission;

    /** @var bool Whether to enforce rate limiting */
    private bool $enforceRateLimit;

    /**
     * Create a new VerifySdkToken middleware instance.
     *
     * @param  SdkScopeTokenService  $tokenService  SDK scope token service
     * @param  ConfigRepository  $config  Config repository
     */
    public function __construct(SdkScopeTokenService $tokenService, ConfigRepository $config){
        $this->tokenService = $tokenService;

        $sdkAuthConfig = $config->get('zeroboiler.analytics.sdk_auth', []);
        /** @var array{enabled?: bool, required_permission?: string, enforce_rate_limit?: bool} $sdkAuthConfig */

        $this->enabled = (bool) ($sdkAuthConfig['enabled'] ?? false);
        $this->requiredPermission = (string) ($sdkAuthConfig['required_permission'] ?? '');
        $this->enforceRateLimit = (bool) ($sdkAuthConfig['enforce_rate_limit'] ?? true);
    }

    /**
     * Handle an incoming request.
     *
     * Validates the SDK token (when enabled) and attaches token metadata
     * to the request for downstream use.
     *
     * @param  \Closure(Request): (Response|JsonResponse)  $next
     * @return Response|JsonResponse
     */
    #[Override]
    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        // If SDK auth is disabled, pass through
        if (! $this->enabled) {
            return $next($request);
        }

        // If SdkScopeTokenService is disabled, pass through
        if (! $this->tokenService->isEnabled()) {
            return $next($request);
        }

        $token = $this->extractToken($request);

        if ($token === null || $token === '') {
            return $this->unauthorized('Missing SDK token', $request);
        }

        // Check if token is valid (exists and not expired)
        if (! $this->tokenService->isValid($token)) {
            return $this->unauthorized('Invalid or expired SDK token', $request);
        }

        // Check required permission when specified
        if ($this->requiredPermission !== '') {
            if (! $this->tokenService->hasPermission($token, $this->requiredPermission)) {
                return $this->forbidden(
                    "SDK token lacks '{$this->requiredPermission}' permission",
                    $request,
                );
            }
        }

        // Check rate limit
        if ($this->enforceRateLimit) {
            $rateCheck = $this->tokenService->checkRateLimit($token);

            if (! $rateCheck['allowed']) {
                $this->tokenService->incrementRateLimit($token);

                return $this->rateLimited($request, $rateCheck['reset_at'] ?? 0);
            }

            // Increment rate counter after check
            $this->tokenService->incrementRateLimit($token);

            // Attach rate limit info to response headers via request attribute
            $request->attributes->set('zb_sdk_rate_remaining', $rateCheck['remaining']);
            $request->attributes->set('zb_sdk_rate_reset', $rateCheck['reset_at']);
        }

        // Attach token scope metadata to request for downstream controllers
        $request->attributes->set('zb_sdk_authenticated', true);
        $request->attributes->set('zb_sdk_token_raw', $token);

        return $next($request);
    }

    /**
     * Extract the SDK token from the request.
     *
     * Checks in order: Authorization Bearer header, X-ZB-SDK-Token header, query parameter.
     */
    private function extractToken(Request $request): ?string
    {
        // 1. Authorization: Bearer <token>
        $bearer = $request->header(self::HEADER_BEARER);

        if (is_string($bearer) && str_starts_with($bearer, 'Bearer ')) {
            $token = substr($bearer, 7);

            if ($token !== '') {
                return $token;
            }
        }

        // 2. X-ZB-SDK-Token header
        $sdkHeader = $request->header(self::HEADER_SDK);

        if (is_string($sdkHeader) && $sdkHeader !== '') {
            return $sdkHeader;
        }

        // 3. Query parameter (for simple integrations / server-to-server)
        $queryToken = $request->query(self::QUERY_PARAM);

        if (is_string($queryToken) && $queryToken !== '') {
            return $queryToken;
        }

        return null;
    }

    /**
     * Return a 401 Unauthorized JSON response.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function unauthorized(string $message, Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'unauthorized',
            'message' => $message,
            'request_id' => $request->header('X-Request-ID'),
        ], 401);
    }

    /**
     * Return a 403 Forbidden JSON response.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function forbidden(string $message, Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'forbidden',
            'message' => $message,
            'request_id' => $request->header('X-Request-ID'),
        ], 403);
    }

    /**
     * Return a 429 Too Many Requests JSON response.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function rateLimited(Request $request, int $resetAt): JsonResponse
    {
        return response()->json([
            'error' => 'rate_limited',
            'message' => 'SDK token rate limit exceeded. Retry after cooldown.',
            'reset_at' => $resetAt,
            'request_id' => $request->header('X-Request-ID'),
        ], 429)->header('Retry-After', (string) max(1, $resetAt - time()));
    }
}
