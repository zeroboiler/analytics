<?php
declare(strict_types=1);

use ZeroBoiler\Analytics\Services\TrackingPreferenceService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

beforeEach(function () {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->service = new TrackingPreferenceService($this->cache, 3600);
});

afterEach(function () {
    Mockery::close();
});

describe('TrackingPreferenceService', function () {
    it('constructs with default TTL', function () {
        $cache = Mockery::mock(CacheRepository::class);
        $service = new TrackingPreferenceService($cache);

        expect($service->getTtl())->toBe(604800);
    });

    it('constructs with custom TTL', function () {
        expect($this->service->getTtl())->toBe(3600);
    });

    it('opts out a user', function () {
        $this->cache->shouldReceive('put')
            ->once()
            ->with('zb_tracking_pref_user_123', true, 3600);

        $this->service->optOut('user_123');
    });

    it('opts in a user', function () {
        $this->cache->shouldReceive('put')
            ->once()
            ->with('zb_tracking_pref_user_123', 'opt_in', 3600);

        $this->service->optIn('user_123');
    });

    it('checks if user is opted out', function () {
        $this->cache->shouldReceive('get')
            ->with('zb_tracking_pref_user_123', false)
            ->andReturn(true);

        expect($this->service->isOptedOut('user_123'))->toBeTrue();
    });

    it('checks if user is opted in', function () {
        $this->cache->shouldReceive('get')
            ->with('zb_tracking_pref_user_123', false)
            ->andReturn('opt_in');

        expect($this->service->isOptedIn('user_123'))->toBeTrue();
    });

    it('returns false for opted out user checking isOptedIn', function () {
        $this->cache->shouldReceive('get')
            ->with('zb_tracking_pref_user_123', false)
            ->andReturn(true);

        expect($this->service->isOptedIn('user_123'))->toBeFalse();
    });

    it('returns false when no preference is set', function () {
        $this->cache->shouldReceive('get')
            ->with('zb_tracking_pref_user_456', false)
            ->andReturn(false);

        expect($this->service->isOptedOut('user_456'))->toBeFalse();
        expect($this->service->isOptedIn('user_456'))->toBeFalse();
    });

    it('clears a user preference', function () {
        $this->cache->shouldReceive('forget')
            ->once()
            ->with('zb_tracking_pref_user_123');

        $this->service->clearPreference('user_123');
    });

    it('checks if preference exists', function () {
        $this->cache->shouldReceive('has')
            ->with('zb_tracking_pref_user_123')
            ->andReturn(true);

        expect($this->service->hasPreference('user_123'))->toBeTrue();
    });

    it('suppresses a client ID', function () {
        $this->cache->shouldReceive('put')
            ->once()
            ->with('zb_tracking_client_client_xyz', true, 3600);

        $this->service->suppressClient('client_xyz');
    });

    it('checks if client is suppressed', function () {
        $this->cache->shouldReceive('get')
            ->with('zb_tracking_client_client_xyz', false)
            ->andReturn(true);

        expect($this->service->isClientSuppressed('client_xyz'))->toBeTrue();
    });

    it('transfers client suppression to user', function () {
        $this->cache->shouldReceive('get')
            ->with('zb_tracking_client_client_xyz', false)
            ->andReturn(true);

        $this->cache->shouldReceive('put')
            ->once()
            ->with('zb_tracking_pref_user_123', true, 3600);

        $this->cache->shouldReceive('forget')
            ->once()
            ->with('zb_tracking_client_client_xyz');

        expect($this->service->transferClientToUser('client_xyz', 'user_123'))->toBeTrue();
    });

    it('does not opt out user when client was not suppressed', function () {
        $this->cache->shouldReceive('get')
            ->with('zb_tracking_client_client_xyz', false)
            ->andReturn(false);

        expect($this->service->transferClientToUser('client_xyz', 'user_123'))->toBeFalse();
    });

    it('shouldTrack returns true when neither user nor client is opted out', function () {
        $this->cache->shouldReceive('get')
            ->twice()
            ->andReturn(false);

        expect($this->service->shouldTrack('user_1', 'client_1'))->toBeTrue();
    });

    it('shouldTrack returns false when user is opted out', function () {
        $this->cache->shouldReceive('get')
            ->with('zb_tracking_pref_user_1', false)
            ->andReturn(true);

        expect($this->service->shouldTrack('user_1', 'client_1'))->toBeFalse();
    });

    it('shouldTrack returns false when client is suppressed', function () {
        $this->cache->shouldReceive('get')
            ->with('zb_tracking_pref_null', false)
            ->andReturn(false);

        $this->cache->shouldReceive('get')
            ->with('zb_tracking_client_client_1', false)
            ->andReturn(true);

        expect($this->service->shouldTrack(null, 'client_1'))->toBeFalse();
    });

    it('shouldTrack returns false when both are suppressed', function () {
        $this->cache->shouldReceive('get')
            ->with('zb_tracking_pref_user_1', false)
            ->andReturn(true);

        // User opt-out takes priority — client check should not be reached
        expect($this->service->shouldTrack('user_1', 'client_1'))->toBeFalse();
    });

    it('clears client suppression', function () {
        $this->cache->shouldReceive('forget')
            ->once()
            ->with('zb_tracking_client_client_xyz');

        $this->service->clearClientSuppression('client_xyz');
    });

    it('setTtl returns self for chaining', function () {
        $result = $this->service->setTtl(7200);

        expect($result)->toBeInstanceOf(TrackingPreferenceService::class);
        expect($this->service->getTtl())->toBe(7200);
    });

    it('getOptedOutUsers returns empty array', function () {
        expect($this->service->getOptedOutUsers())->toBe([]);
    });
});
