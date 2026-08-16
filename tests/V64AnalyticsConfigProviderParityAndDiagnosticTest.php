<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;

beforeEach(function (): void {
    $this->config = mock(ConfigRepository::class);
    $this->analyticsConfig = new AnalyticsConfig($this->config);
});

describe('AnalyticsConfig Provider Parity', function (): void {
    it('returns empty providers list when all disabled', function (): void {
        $this->config->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andReturnFalse();

        expect($this->analyticsConfig->enabledProviders())
            ->toBe([]);
    });

    it('returns ga4 in providers list when enabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ga4.enabled', false)
            ->andReturn(true);

        $this->config->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andReturnFalse();

        expect($this->analyticsConfig->enabledProviders())
            ->toContain('ga4');
    });

    it('returns all enabled providers', function (): void {
        $this->config->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default): mixed {
                $map = [
                    'zeroboiler.analytics.ga4.enabled' => true,
                    'zeroboiler.analytics.gtm.enabled' => true,
                    'zeroboiler.analytics.meta_pixel.enabled' => false,
                    'zeroboiler.analytics.plausible.enabled' => false,
                    'zeroboiler.analytics.posthog.enabled' => false,
                    'zeroboiler.analytics.mixpanel.enabled' => true,
                    'zeroboiler.analytics.amplitude.enabled' => false,
                    'zeroboiler.analytics.tiktok.enabled' => false,
                    'zeroboiler.analytics.linkedin.enabled' => false,
                    'zeroboiler.analytics.webhook.enabled' => false,
                ];

                return $map[$key] ?? $default;
            });

        expect($this->analyticsConfig->enabledProviders())
            ->toBe(['ga4', 'gtm', 'mixpanel']);
    });
});

describe('AnalyticsConfig compactSummary', function (): void {
    it('returns flat summary with correct keys', function (): void {
        $this->config->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default): mixed {
                $map = [
                    'zeroboiler.analytics.consent.default' => 'granted',
                    'zeroboiler.analytics.queue.enabled' => true,
                    'zeroboiler.analytics.queue.queue' => 'analytics',
                    'zeroboiler.analytics.auto_track.enabled' => true,
                    'zeroboiler.analytics.api.enabled' => true,
                    'zeroboiler.analytics.api.base_url' => '/api/analytics',
                    'zeroboiler.analytics.identity.cookie_name' => 'zb_analytics_id',
                    'zeroboiler.analytics.ecommerce.currency' => 'USD',
                    'zeroboiler.analytics.sampling.enabled' => false,
                    'zeroboiler.analytics.sampling.rate' => 1.0,
                    'zeroboiler.analytics.pii_sanitization.enabled' => false,
                    'zeroboiler.analytics.debug.enabled' => false,
                    'zeroboiler.analytics.validation.strict' => false,
                    'zeroboiler.analytics.replay.enabled' => true,
                    'zeroboiler.analytics.fingerprint.enabled' => true,
                    'zeroboiler.analytics.ga4.enabled' => false,
                    'zeroboiler.analytics.gtm.enabled' => false,
                    'zeroboiler.analytics.meta_pixel.enabled' => false,
                    'zeroboiler.analytics.plausible.enabled' => false,
                    'zeroboiler.analytics.posthog.enabled' => false,
                    'zeroboiler.analytics.mixpanel.enabled' => false,
                    'zeroboiler.analytics.amplitude.enabled' => false,
                    'zeroboiler.analytics.tiktok.enabled' => false,
                    'zeroboiler.analytics.linkedin.enabled' => false,
                    'zeroboiler.analytics.webhook.enabled' => false,
                ];

                return $map[$key] ?? $default;
            });

        $summary = $this->analyticsConfig->compactSummary();

        expect($summary)->toBeArray()
            ->and($summary)->toHaveKeys([
                'version',
                'providers',
                'provider_count',
                'consent_default',
                'queue_enabled',
                'queue_name',
                'auto_track',
                'api_enabled',
                'api_base_url',
                'identity_cookie',
                'ecommerce_currency',
                'sampling_enabled',
                'sampling_rate',
                'pii_enabled',
                'debug_enabled',
                'validation_strict',
                'replay_enabled',
                'fingerprint_enabled',
                'event_count',
                'event_categories',
            ])
            ->and($summary['version'])->toBe(AnalyticsEvent::VERSION)
            ->and($summary['providers'])->toBe([])
            ->and($summary['provider_count'])->toBe(0)
            ->and($summary['consent_default'])->toBe('granted')
            ->and($summary['queue_name'])->toBe('analytics')
            ->and($summary['identity_cookie'])->toBe('zb_analytics_id')
            ->and($summary['event_count'])->toBe(EventCatalog::count())
            ->and($summary['event_categories'])->toBe(count(EventCatalog::byCategory()));
    });

    it('includes provider_count that matches providers array length', function (): void {
        $this->config->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default): mixed {
                $map = [
                    'zeroboiler.analytics.ga4.enabled' => true,
                    'zeroboiler.analytics.gtm.enabled' => true,
                    'zeroboiler.analytics.posthog.enabled' => true,
                    'zeroboiler.analytics.meta_pixel.enabled' => false,
                    'zeroboiler.analytics.plausible.enabled' => false,
                    'zeroboiler.analytics.mixpanel.enabled' => false,
                    'zeroboiler.analytics.amplitude.enabled' => false,
                    'zeroboiler.analytics.tiktok.enabled' => false,
                    'zeroboiler.analytics.linkedin.enabled' => false,
                    'zeroboiler.analytics.webhook.enabled' => false,
                    'zeroboiler.analytics.consent.default' => 'denied',
                    'zeroboiler.analytics.queue.enabled' => true,
                    'zeroboiler.analytics.queue.queue' => 'analytics',
                    'zeroboiler.analytics.auto_track.enabled' => false,
                    'zeroboiler.analytics.api.enabled' => true,
                    'zeroboiler.analytics.api.base_url' => '/api/analytics',
                    'zeroboiler.analytics.identity.cookie_name' => 'zb_id',
                    'zeroboiler.analytics.ecommerce.currency' => 'EUR',
                    'zeroboiler.analytics.sampling.enabled' => true,
                    'zeroboiler.analytics.sampling.rate' => 0.5,
                    'zeroboiler.analytics.pii_sanitization.enabled' => true,
                    'zeroboiler.analytics.debug.enabled' => true,
                    'zeroboiler.analytics.validation.strict' => true,
                    'zeroboiler.analytics.replay.enabled' => false,
                    'zeroboiler.analytics.fingerprint.enabled' => false,
                ];

                return $map[$key] ?? $default;
            });

        $summary = $this->analyticsConfig->compactSummary();

        expect($summary['provider_count'])->toBe(3)
            ->and($summary['providers'])->toBe(['ga4', 'gtm', 'posthog'])
            ->and($summary['consent_default'])->toBe('denied')
            ->and($summary['ecommerce_currency'])->toBe('EUR')
            ->and($summary['sampling_rate'])->toBe(0.5)
            ->and($summary['pii_enabled'])->toBeTrue()
            ->and($summary['debug_enabled'])->toBeTrue()
            ->and($summary['validation_strict'])->toBeTrue()
            ->and($summary['replay_enabled'])->toBeFalse()
            ->and($summary['fingerprint_enabled'])->toBeFalse();
    });
});

describe('AnalyticsConfig Mixpanel Accessors', function (): void {
    it('returns mixpanel enabled status', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.mixpanel.enabled', false)
            ->andReturnTrue();

        expect($this->analyticsConfig->mixpanelEnabled())->toBeTrue();
    });

    it('returns mixpanel token', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.mixpanel.token', '')
            ->andReturn('abc123token');

        expect($this->analyticsConfig->mixpanelToken())->toBe('abc123token');
    });

    it('returns default mixpanel host', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.mixpanel.host', 'https://api.mixpanel.com')
            ->andReturn('https://api.mixpanel.com');

        expect($this->analyticsConfig->mixpanelHost())->toBe('https://api.mixpanel.com');
    });
});

describe('AnalyticsConfig Amplitude Accessors', function (): void {
    it('returns amplitude enabled status', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.amplitude.enabled', false)
            ->andReturnTrue();

        expect($this->analyticsConfig->amplitudeEnabled())->toBeTrue();
    });

    it('returns amplitude api key', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.amplitude.api_key', '')
            ->andReturn('amp_key_456');

        expect($this->analyticsConfig->amplitudeApiKey())->toBe('amp_key_456');
    });
});

describe('AnalyticsConfig TikTok Accessors', function (): void {
    it('returns tiktok enabled status', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.tiktok.enabled', false)
            ->andReturnTrue();

        expect($this->analyticsConfig->tiktokEnabled())->toBeTrue();
    });

    it('returns tiktok pixel id', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.tiktok.pixel_id', '')
            ->andReturn('TikTokPixelID');

        expect($this->analyticsConfig->tiktokPixelId())->toBe('TikTokPixelID');
    });
});

describe('AnalyticsConfig LinkedIn Accessors', function (): void {
    it('returns linkedin enabled status', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.linkedin.enabled', false)
            ->andReturnTrue();

        expect($this->analyticsConfig->linkedinEnabled())->toBeTrue();
    });

    it('returns linkedin partner id', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.linkedin.partner_id', '')
            ->andReturn('LinkedInPartner123');

        expect($this->analyticsConfig->linkedinPartnerId())->toBe('LinkedInPartner123');
    });
});
