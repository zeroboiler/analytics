<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Http\Middleware\VerifySdkToken;
use ZeroBoiler\Analytics\Services\SdkScopeTokenService;

/**
 * Tests for the VerifySdkToken middleware (v77.0.0).
 *
 * @covers \ZeroBoiler\Analytics\Http\Middleware\VerifySdkToken
 */
final class V7700SdkAuthMiddlewareTest extends TestCase
{
    private CacheRepository $cache;

    private ConfigRepository $config;

    private SdkScopeTokenService $tokenService;

    private VerifySdkToken $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = $this->createMock(CacheRepository::class);
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('put')->willReturn(true);
        $this->cache->method('forget')->willReturn(true);

        $this->config = $this->createMock(ConfigRepository::class);
        $this->config->method('get')
            ->willReturnMap([
                ['zeroboiler.analytics.sdk_tokens', [], [
                    'enabled' => true,
                    'token_ttl' => 999999,
                    'default_rate_limit' => 100,
                    'max_tokens_per_scope' => 10,
                    'hash_algorithm' => 'sha256',
                    'signing_key' => 'test-signing-key',
                ]],
                ['zeroboiler.analytics.sdk_auth', [], [
                    'enabled' => true,
                    'required_permission' => '',
                    'enforce_rate_limit' => true,
                ]],
            ]);

        $this->tokenService = new SdkScopeTokenService($this->cache, $this->config);
        $this->middleware = new VerifySdkToken($this->tokenService, $this->config);
    }

    /** @test */
    public function it_passes_through_when_sdk_auth_is_disabled(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')
            ->willReturnMap([
                ['zeroboiler.analytics.sdk_tokens', [], ['enabled' => true]],
                ['zeroboiler.analytics.sdk_auth', [], ['enabled' => false]],
            ]);

        $service = new SdkScopeTokenService($this->cache, $config);
        $middleware = new VerifySdkToken($service, $config);

        $request = Request::create('/api/analytics/events', 'POST');
        $nextCalled = false;
        $next = function (Request $req) use (&$nextCalled): JsonResponse {
            $nextCalled = true;

            return response()->json(['ok' => true]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertTrue($nextCalled, 'Next middleware should be called when SDK auth is disabled');
        $this->assertEquals(200, $response->getStatusCode());
    }

    /** @test */
    public function it_passes_through_when_token_service_is_disabled(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')
            ->willReturnMap([
                ['zeroboiler.analytics.sdk_tokens', [], ['enabled' => false]],
                ['zeroboiler.analytics.sdk_auth', [], ['enabled' => true]],
            ]);

        $service = new SdkScopeTokenService($this->cache, $config);
        $middleware = new VerifySdkToken($service, $config);

        $request = Request::create('/api/analytics/events', 'POST');
        $nextCalled = false;
        $next = function (Request $req) use (&$nextCalled): JsonResponse {
            $nextCalled = true;

            return response()->json(['ok' => true]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertTrue($nextCalled);
    }

    /** @test */
    public function it_returns_401_when_no_token_provided(): void
    {
        $request = Request::create('/api/analytics/events', 'POST');
        $next = function (Request $req): JsonResponse {
            return response()->json(['ok' => true]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertEquals(401, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame('unauthorized', $data['error']);
        $this->assertStringContainsString('Missing SDK token', $data['message']);
    }

    /** @test */
    public function it_returns_401_for_invalid_token(): void
    {
        $request = Request::create('/api/analytics/events', 'POST');
        $request->headers->set('X-ZB-SDK-Token', 'invalid_token_value');

        $next = function (Request $req): JsonResponse {
            return response()->json(['ok' => true]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertEquals(401, $response->getStatusCode());
    }

    /** @test */
    public function it_accepts_token_via_bearer_header(): void
    {
        $generated = $this->generateTestToken();

        $request = Request::create('/api/analytics/events', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . $generated['token']);

        $nextCalled = false;
        $next = function (Request $req) use (&$nextCalled): JsonResponse {
            $nextCalled = true;
            $this->assertTrue($req->attributes->get('zb_sdk_authenticated'));

            return response()->json(['ok' => true]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertTrue($nextCalled);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /** @test */
    public function it_accepts_token_via_sdk_header(): void
    {
        $generated = $this->generateTestToken();

        $request = Request::create('/api/analytics/events', 'POST');
        $request->headers->set('X-ZB-SDK-Token', $generated['token']);

        $nextCalled = false;
        $next = function (Request $req) use (&$nextCalled): JsonResponse {
            $nextCalled = true;

            return response()->json(['ok' => true]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertTrue($nextCalled);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /** @test */
    public function it_accepts_token_via_query_parameter(): void
    {
        $generated = $this->generateTestToken();

        $request = Request::create('/api/analytics/events?zb_sdk_token=' . $generated['token'], 'POST');

        $nextCalled = false;
        $next = function (Request $req) use (&$nextCalled): JsonResponse {
            $nextCalled = true;

            return response()->json(['ok' => true]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertTrue($nextCalled);
    }

    /** @test */
    public function it_rejects_token_with_wrong_permission(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')
            ->willReturnMap([
                ['zeroboiler.analytics.sdk_tokens', [], [
                    'enabled' => true,
                    'token_ttl' => 999999,
                    'default_rate_limit' => 100,
                    'max_tokens_per_scope' => 10,
                    'hash_algorithm' => 'sha256',
                    'signing_key' => 'test-signing-key',
                ]],
                ['zeroboiler.analytics.sdk_auth', [], [
                    'enabled' => true,
                    'required_permission' => 'batch',
                    'enforce_rate_limit' => false,
                ]],
            ]);

        $service = new SdkScopeTokenService($this->cache, $config);
        $middleware = new VerifySdkToken($service, $config);

        // Generate token with only 'track' permission
        $generated = $service->generateToken(
            'test-scope',
            [SdkScopeTokenService::PERM_TRACK],
        );

        $request = Request::create('/api/analytics/events', 'POST');
        $request->headers->set('X-ZB-SDK-Token', $generated['token']);

        $next = function (Request $req): JsonResponse {
            return response()->json(['ok' => true]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertEquals(403, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame('forbidden', $data['error']);
        $this->assertStringContainsString('batch', $data['message']);
    }

    /** @test */
    public function it_attaches_rate_limit_info_to_request(): void
    {
        $generated = $this->generateTestToken();

        $request = Request::create('/api/analytics/events', 'POST');
        $request->headers->set('X-ZB-SDK-Token', $generated['token']);

        $next = function (Request $req): JsonResponse {
            $remaining = $req->attributes->get('zb_sdk_rate_remaining');
            $reset = $req->attributes->get('zb_sdk_rate_reset');

            $this->assertNotNull($remaining, 'Rate remaining should be set');
            $this->assertNotNull($reset, 'Rate reset should be set');
            $this->assertIsInt($remaining);
            $this->assertIsInt($reset);

            return response()->json(['ok' => true]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertEquals(200, $response->getStatusCode());
    }

    /** @test */
    public function it_sets_authenticated_flag_on_request(): void
    {
        $generated = $this->generateTestToken();

        $request = Request::create('/api/analytics/events', 'POST');
        $request->headers->set('X-ZB-SDK-Token', $generated['token']);

        $next = function (Request $req): JsonResponse {
            $this->assertTrue($req->attributes->get('zb_sdk_authenticated'));
            $this->assertSame($generated['token'], $req->attributes->get('zb_sdk_token_raw'));

            return response()->json(['ok' => true]);
        };

        $this->middleware->handle($request, $next);
    }

    /**
     * Generate a test SDK token with full permissions.
     *
     * @return array{token: string, scope: string, permissions: list<string>, categories: list<string>, expires_at: int}
     */
    private function generateTestToken(): array
    {
        return $this->tokenService->generateToken(
            'test-scope',
            [
                SdkScopeTokenService::PERM_TRACK,
                SdkScopeTokenService::PERM_BATCH,
                SdkScopeTokenService::PERM_IDENTIFY,
                SdkScopeTokenService::PERM_CONSENT,
                SdkScopeTokenService::PERM_PAGEVIEW,
            ],
            [
                SdkScopeTokenService::CATEGORY_ECOMMERCE,
                SdkScopeTokenService::CATEGORY_SAAS,
                SdkScopeTokenService::CATEGORY_ENGAGEMENT,
                SdkScopeTokenService::CATEGORY_CUSTOM,
            ],
        );
    }
}
