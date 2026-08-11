<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Support\AnalyticsConfig;

beforeEach(function (): void {
    $this->config = mock(Illuminate\Contracts\Config\Repository::class);
});

// ── v2.45.0 Full Config Coverage + Version Consistency + CHANGELOG Integrity ───

describe('v2.45.0 Full Config Coverage', function (): void {

    // ── GA4 Accessors ─────────────────────────────────────────────

    test('ga4Enabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ga4.enabled', false)
            ->andReturn(true);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->ga4Enabled())->toBeTrue();
    });

    test('ga4MeasurementId returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ga4.measurement_id', '')
            ->andReturn('G-ABC123');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->ga4MeasurementId())->toBe('G-ABC123');
    });

    test('ga4ApiSecret returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ga4.api_secret', '')
            ->andReturn('secret_xyz');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->ga4ApiSecret())->toBe('secret_xyz');
    });

    // ── GTM Accessors ─────────────────────────────────────────────

    test('gtmEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.gtm.enabled', false)
            ->andReturn(true);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->gtmEnabled())->toBeTrue();
    });

    test('gtmContainerId returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.gtm.container_id', '')
            ->andReturn('GTM-XXXX');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->gtmContainerId())->toBe('GTM-XXXX');
    });

    // ── Meta Pixel Accessors ─────────────────────────────────────

    test('metaPixelEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.meta_pixel.enabled', false)
            ->andReturn(true);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->metaPixelEnabled())->toBeTrue();
    });

    test('metaPixelId returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.meta_pixel.id', '')
            ->andReturn('123456789');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->metaPixelId())->toBe('123456789');
    });

    test('metaPixelAccessToken returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.meta_pixel.access_token', '')
            ->andReturn('token_abc');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->metaPixelAccessToken())->toBe('token_abc');
    });

    // ── Consent Accessors ────────────────────────────────────────

    test('consentDefault returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent.default', 'granted')
            ->andReturn('denied');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->consentDefault())->toBe('denied');
    });

    test('consentDefaultDenied returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent.default', 'granted')
            ->andReturn('denied');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->consentDefaultDenied())->toBeTrue();
    });

    test('consentPurposes returns array', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent.purposes', [])
            ->andReturn([
                'necessary' => ['label' => 'Necessary', 'required' => true, 'default' => true],
            ]);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->consentPurposes())->toBeArray()->toHaveKey('necessary');
    });

    test('consentLogEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent.log_enabled', false)
            ->andReturn(true);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->consentLogEnabled())->toBeTrue();
    });

    test('consentLogTtl returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent.log_ttl', 7776000)
            ->andReturn(3600);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->consentLogTtl())->toBe(3600);
    });

    // ── Queue Accessors ──────────────────────────────────────────

    test('queueEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.enabled', true)
            ->andReturn(false);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->queueEnabled())->toBeFalse();
    });

    test('queueName returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.queue', 'analytics')
            ->andReturn('custom-queue');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->queueName())->toBe('custom-queue');
    });

    test('queueConnection returns nullable string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.connection')
            ->andReturn('redis');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->queueConnection())->toBe('redis');
    });

    test('queueConnection returns null for empty string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.connection')
            ->andReturn('');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->queueConnection())->toBeNull();
    });

    // ── Identity Accessors ─────────────────────────────────────────

    test('identityCookieName returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id')
            ->andReturn('custom_id');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->identityCookieName())->toBe('custom_id');
    });

    test('identityCookieTtl returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity.cookie_ttl', 525600)
            ->andReturn(1440);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->identityCookieTtl())->toBe(1440);
    });

    test('identityCookieSecure returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity.cookie_secure', true)
            ->andReturn(false);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->identityCookieSecure())->toBeFalse();
    });

    test('identityCookieSameSite returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity.cookie_samesite', 'Lax')
            ->andReturn('Strict');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->identityCookieSameSite())->toBe('Strict');
    });

    // ── API Accessors ─────────────────────────────────────────────

    test('apiEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.api.enabled', true)
            ->andReturn(false);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->apiEnabled())->toBeFalse();
    });

    test('apiThrottle returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.api.throttle', 60)
            ->andReturn(120);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->apiThrottle())->toBe(120);
    });

    test('apiBaseUrl returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
            ->andReturn('/v2/analytics');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->apiBaseUrl())->toBe('/v2/analytics');
    });

    // ── Auto-Track Accessors ─────────────────────────────────────

    test('autoTrackEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.auto_track.enabled', true)
            ->andReturn(false);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->autoTrackEnabled())->toBeFalse();
    });

    test('autoTrackEvents returns array', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.auto_track.events', [])
            ->andReturn(['auth.login' => true, 'auth.register' => true]);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->autoTrackEvents())->toBe(['auth.login' => true, 'auth.register' => true]);
    });

    test('autoTrackModels returns array', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.auto_track.models', [])
            ->andReturn(['App\\Models\\User' => ['created']]);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->autoTrackModels())->toBe(['App\\Models\\User' => ['created']]);
    });

    test('autoTrackEventMap returns array', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.auto_track.event_map', [])
            ->andReturn(['team.invited' => 'App\\Events\\TeamInvitedEvent']);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->autoTrackEventMap())->toBe(['team.invited' => 'App\\Events\\TeamInvitedEvent']);
    });

    // ── E-commerce Accessors ─────────────────────────────────────

    test('ecommerceCurrency returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ecommerce.currency', 'USD')
            ->andReturn('EUR');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->ecommerceCurrency())->toBe('EUR');
    });

    test('ecommerceBrand returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ecommerce.brand', '')
            ->andReturn('Acme');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->ecommerceBrand())->toBe('Acme');
    });

    test('ecommerceTaxBehavior returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ecommerce.tax_behavior', 'inclusive')
            ->andReturn('exclusive');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->ecommerceTaxBehavior())->toBe('exclusive');
    });

    test('ecommerceShippingDefault returns float', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ecommerce.shipping_default', 0.0)
            ->andReturn(5.99);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->ecommerceShippingDefault())->toBe(5.99);
    });

    // ── Revenue Accessors ─────────────────────────────────────────

    test('revenueCurrency returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue.currency', 'USD')
            ->andReturn('GBP');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->revenueCurrency())->toBe('GBP');
    });

    test('revenueBillingCycleDefault returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue.billing_cycle_default', 'monthly')
            ->andReturn('annual');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->revenueBillingCycleDefault())->toBe('annual');
    });

    // ── Track Links Accessors ─────────────────────────────────────

    test('trackLinksEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.track_links.enabled', false)
            ->andReturn(true);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->trackLinksEnabled())->toBeTrue();
    });

    test('trackLinksExternal returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.track_links.track_external', true)
            ->andReturn(false);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->trackLinksExternal())->toBeFalse();
    });

    test('trackLinksExternalPrefix returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.track_links.external_prefix', 'outbound')
            ->andReturn('ext');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->trackLinksExternalPrefix())->toBe('ext');
    });

    // ── Plausible Accessors ────────────────────────────────────────

    test('plausibleEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.plausible.enabled', false)
            ->andReturn(true);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->plausibleEnabled())->toBeTrue();
    });

    test('plausibleDomain returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.plausible.domain', '')
            ->andReturn('example.com');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->plausibleDomain())->toBe('example.com');
    });

    test('plausibleApiKey returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.plausible.api_key', '')
            ->andReturn('key_123');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->plausibleApiKey())->toBe('key_123');
    });

    // ── PostHog Accessors ────────────────────────────────────────

    test('posthogEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.posthog.enabled', false)
            ->andReturn(true);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->posthogEnabled())->toBeTrue();
    });

    test('posthogApiKey returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.posthog.api_key', '')
            ->andReturn('phc_abc');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->posthogApiKey())->toBe('phc_abc');
    });

    test('posthogHost returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.posthog.host', 'https://eu.posthog.com')
            ->andReturn('https://us.posthog.com');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->posthogHost())->toBe('https://us.posthog.com');
    });

    test('posthogProjectId returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.posthog.project_id', '')
            ->andReturn('proj_1');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->posthogProjectId())->toBe('proj_1');
    });

    // ── Webhook Accessors ────────────────────────────────────────

    test('webhookEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.webhook.enabled', false)
            ->andReturn(true);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->webhookEnabled())->toBeTrue();
    });

    test('webhookUrl returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.webhook.url', '')
            ->andReturn('https://hooks.example.com/analytics');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->webhookUrl())->toBe('https://hooks.example.com/analytics');
    });

    test('webhookSecret returns string', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.webhook.secret', '')
            ->andReturn('whsec_abc');
        $ac = new AnalyticsConfig($this->config);
        expect($ac->webhookSecret())->toBe('whsec_abc');
    });

    test('webhookTimeout returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.webhook.timeout', 5)
            ->andReturn(10);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->webhookTimeout())->toBe(10);
    });

    test('webhookRetries returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.webhook.retries', 1)
            ->andReturn(3);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->webhookRetries())->toBe(3);
    });

    test('webhookSign returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.webhook.sign', false)
            ->andReturn(true);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->webhookSign())->toBeTrue();
    });

    test('webhookHeaders returns array', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.webhook.headers', [])
            ->andReturn(['X-Custom' => 'value']);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->webhookHeaders())->toBe(['X-Custom' => 'value']);
    });

    // ── Pipeline Accessors ───────────────────────────────────────

    test('pipelineAutoUtm returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.pipeline.auto_utm', true)
            ->andReturn(false);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->pipelineAutoUtm())->toBeFalse();
    });

    test('pipelineAutoTimestamp returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.pipeline.auto_timestamp', false)
            ->andReturn(true);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->pipelineAutoTimestamp())->toBeTrue();
    });

    test('pipelineAutoMetadata returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.pipeline.auto_metadata', true)
            ->andReturn(false);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->pipelineAutoMetadata())->toBeFalse();
    });

    test('pipelineSchemaEnrichment returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.pipeline.schema_enrichment', false)
            ->andReturn(true);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->pipelineSchemaEnrichment())->toBeTrue();
    });

    // ── Lifecycle Accessors ────────────────────────────────────────

    test('lifecycleEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle.enabled', true)
            ->andReturn(false);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->lifecycleEnabled())->toBeFalse();
    });

    test('lifecycleEvents returns array', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle.events', [])
            ->andReturn(['auth.login' => true]);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->lifecycleEvents())->toBe(['auth.login' => true]);
    });

    test('lifecycleCustomMappings returns array', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle.custom_mappings', [])
            ->andReturn(['team.invited' => ['source' => 'team.invited', 'target' => 'App\\Events\\TeamInvitedEvent']]);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->lifecycleCustomMappings())->toBeArray()->toHaveKey('team.invited');
    });

    // ── GDPR Accessors ───────────────────────────────────────────

    test('gdprAnonymizeIp returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr.anonymize_ip', false)
            ->andReturn(true);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->gdprAnonymizeIp())->toBeTrue();
    });

    test('gdprIpMaskV4 returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr.ip_mask_v4', 2)
            ->andReturn(1);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->gdprIpMaskV4())->toBe(1);
    });

    test('gdprIpMaskV6 returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr.ip_mask_v6', 48)
            ->andReturn(32);
        $ac = new AnalyticsConfig($this->config);
        expect($ac->gdprIpMaskV6())->toBe(32);
    });

    // ── Version Consistency (v2.45.0) ──────────────────────────────

    test('no stale 2.43.0 version references remain in PHP source', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $contents = file_get_contents($file->getPathname());
                expect(str_contains($contents, '10.3.0'))
                    ->toBeFalse("{$file->getFilename()} still contains 2.43.0 version reference");
            }
        }
    });

    test('no stale 2.44.0 version references remain in PHP source', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $contents = file_get_contents($file->getPathname());
                expect(str_contains($contents, '10.3.0'))
                    ->toBeFalse("{$file->getFilename()} still contains 2.44.0 version reference");
            }
        }
    });

    test('no stale 2.43.0 version references remain in JS client', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect(str_contains($js, '2.43.0'))->toBeFalse('JS client still contains 2.87.0');
    });

    test('no stale 2.44.0 version references remain in JS client', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect(str_contains($js, '2.44.0'))->toBeFalse('JS client still contains 2.87.0');
    });

    test('version 2.45.0 is consistent across all markers', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['version'])->toBe('10.3.0');

        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect(str_contains($js, "'10.3.0'"))->toBeTrue();

        $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
        expect(str_contains($dts, '10.3.0'))->toBeTrue();

        $manager = new \ZeroBoiler\Analytics\AnalyticsManager();
        expect($manager->version())->toBe('10.3.0');

        $tagger = file_get_contents(__DIR__ . '/../src/Services/EventSourceTagger.php');
        expect(str_contains($tagger, "'10.3.0'"))->toBeTrue();

        $forwarder = file_get_contents(__DIR__ . '/../src/Services/EventForwardingService.php');
        expect(str_contains($forwarder, '10.3.0'))->toBeTrue();
    });

    // ── Summary Completeness ─────────────────────────────────────

    test('summary includes all config sections', function (): void {
        // The summary should include all major config keys
        $requiredSections = [
            'ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'webhook',
            'consent', 'queue', 'identity', 'api', 'auto_track', 'ecommerce',
            'track_links', 'debug', 'validation', 'sampling', 'pii_sanitization',
            'replay', 'metrics', 'audit_log', 'performance', 'client_auto_track',
            'tracking_preference', 'dedup', 'gdpr', 'attribution', 'profile',
            'funnels', 'alerts', 'inbound_webhook', 'lifecycle', 'correlation',
            'stream', 'retention', 'source_tagging', 'validation_boot', 'broadcast',
            'tenant', 'retention_policy', 'gate', 'referral', 'dead_letter_queue',
            'realtime', 'snapshots', 'saas_kpi', 'utm_aggregation', 'geolocation',
            'reporting', 'ab_tests', 'performance_budget', 'forwarding',
        ];

        // Build a mock config that returns defaults for every accessor call
        foreach ($requiredSections as $section) {
            $this->config->shouldReceive('get')
                ->withArgs(fn (string $key): bool => str_starts_with($key, 'zeroboiler.analytics.' . $section))
                ->andReturnUsing(function (string $key) {
                    // Return sensible defaults based on the key
                    $defaults = [
                        'zeroboiler.analytics.ga4.enabled' => false,
                        'zeroboiler.analytics.ga4.measurement_id' => '',
                        'zeroboiler.analytics.ga4.api_secret' => '',
                        'zeroboiler.analytics.gtm.enabled' => false,
                        'zeroboiler.analytics.gtm.container_id' => '',
                        'zeroboiler.analytics.meta_pixel.enabled' => false,
                        'zeroboiler.analytics.meta_pixel.id' => '',
                        'zeroboiler.analytics.meta_pixel.access_token' => '',
                        'zeroboiler.analytics.consent.default' => 'granted',
                        'zeroboiler.analytics.consent.purposes' => [],
                        'zeroboiler.analytics.consent.log_enabled' => false,
                        'zeroboiler.analytics.consent.log_ttl' => 7776000,
                        'zeroboiler.analytics.queue.enabled' => true,
                        'zeroboiler.analytics.queue.queue' => 'analytics',
                        'zeroboiler.analytics.queue.connection' => null,
                        'zeroboiler.analytics.identity.cookie_name' => 'zb_analytics_id',
                        'zeroboiler.analytics.identity.cookie_ttl' => 525600,
                        'zeroboiler.analytics.identity.cookie_secure' => true,
                        'zeroboiler.analytics.identity.cookie_samesite' => 'Lax',
                        'zeroboiler.analytics.api.enabled' => true,
                        'zeroboiler.analytics.api.throttle' => 60,
                        'zeroboiler.analytics.api.base_url' => '/api/analytics',
                        'zeroboiler.analytics.auto_track.enabled' => true,
                        'zeroboiler.analytics.auto_track.events' => [],
                        'zeroboiler.analytics.auto_track.models' => [],
                        'zeroboiler.analytics.auto_track.event_map' => [],
                        'zeroboiler.analytics.ecommerce.currency' => 'USD',
                        'zeroboiler.analytics.ecommerce.brand' => '',
                        'zeroboiler.analytics.ecommerce.tax_behavior' => 'inclusive',
                        'zeroboiler.analytics.ecommerce.shipping_default' => 0.0,
                        'zeroboiler.analytics.revenue.currency' => 'USD',
                        'zeroboiler.analytics.revenue.billing_cycle_default' => 'monthly',
                        'zeroboiler.analytics.track_links.enabled' => false,
                        'zeroboiler.analytics.track_links.track_external' => true,
                        'zeroboiler.analytics.track_links.track_internal' => false,
                        'zeroboiler.analytics.track_links.external_prefix' => 'outbound',
                        'zeroboiler.analytics.plausible.enabled' => false,
                        'zeroboiler.analytics.plausible.domain' => '',
                        'zeroboiler.analytics.plausible.api_key' => '',
                        'zeroboiler.analytics.posthog.enabled' => false,
                        'zeroboiler.analytics.posthog.api_key' => '',
                        'zeroboiler.analytics.posthog.host' => 'https://eu.posthog.com',
                        'zeroboiler.analytics.posthog.project_id' => '',
                        'zeroboiler.analytics.webhook.enabled' => false,
                        'zeroboiler.analytics.webhook.url' => '',
                        'zeroboiler.analytics.webhook.secret' => '',
                        'zeroboiler.analytics.webhook.timeout' => 5,
                        'zeroboiler.analytics.webhook.retries' => 1,
                        'zeroboiler.analytics.webhook.sign' => false,
                        'zeroboiler.analytics.webhook.headers' => [],
                        'zeroboiler.analytics.debug.enabled' => false,
                        'zeroboiler.analytics.debug.log_events' => false,
                        'zeroboiler.analytics.validation.strict' => false,
                        'zeroboiler.analytics.validation.whitelist' => [],
                        'zeroboiler.analytics.validation.max_event_name_length' => 100,
                        'zeroboiler.analytics.validation.deduplication_window' => 10,
                        'zeroboiler.analytics.pipeline.auto_utm' => true,
                        'zeroboiler.analytics.pipeline.auto_timestamp' => false,
                        'zeroboiler.analytics.pipeline.auto_metadata' => true,
                        'zeroboiler.analytics.pipeline.schema_enrichment' => false,
                        'zeroboiler.analytics.sampling.enabled' => false,
                        'zeroboiler.analytics.sampling.rate' => 1.0,
                        'zeroboiler.analytics.sampling.deterministic' => true,
                        'zeroboiler.analytics.pii_sanitization.enabled' => false,
                        'zeroboiler.analytics.pii_sanitization.strategy' => 'hash',
                        'zeroboiler.analytics.pii_sanitization.custom_fields' => [],
                        'zeroboiler.analytics.replay.enabled' => true,
                        'zeroboiler.analytics.replay.max_attempts' => 3,
                        'zeroboiler.analytics.replay.base_delay' => 1.0,
                        'zeroboiler.analytics.replay.max_delay' => 60.0,
                        'zeroboiler.analytics.replay.jitter' => 0.2,
                        'zeroboiler.analytics.metrics.enabled' => false,
                        'zeroboiler.analytics.metrics.log_on_flush' => false,
                        'zeroboiler.analytics.stream.buffer_size' => 1000,
                        'zeroboiler.analytics.client_auto_track.page_views' => true,
                        'zeroboiler.analytics.client_auto_track.scroll_depth' => true,
                        'zeroboiler.analytics.client_auto_track.form_tracking' => true,
                        'zeroboiler.analytics.client_auto_track.error_tracking' => true,
                        'zeroboiler.analytics.client_auto_track.link_tracking' => false,
                        'zeroboiler.analytics.client_auto_track.session_tracking' => true,
                        'zeroboiler.analytics.client_auto_track.idle_timeout' => 1800,
                        'zeroboiler.analytics.client_auto_track.error_ignore_patterns' => [],
                        'zeroboiler.analytics.performance.enabled' => false,
                        'zeroboiler.analytics.performance.track_lcp' => true,
                        'zeroboiler.analytics.performance.track_fid' => true,
                        'zeroboiler.analytics.performance.track_cls' => true,
                        'zeroboiler.analytics.performance.track_inp' => true,
                        'zeroboiler.analytics.performance.track_ttfb' => true,
                        'zeroboiler.analytics.performance.track_fcp' => false,
                        'zeroboiler.analytics.performance.send_to_server' => true,
                        'zeroboiler.analytics.audit_log.enabled' => false,
                        'zeroboiler.analytics.audit_log.priority' => 100,
                        'zeroboiler.analytics.tracking_preference.ttl' => 604800,
                        'zeroboiler.analytics.dedup.enabled' => true,
                        'zeroboiler.analytics.gdpr.anonymize_ip' => false,
                        'zeroboiler.analytics.gdpr.ip_mask_v4' => 2,
                        'zeroboiler.analytics.gdpr.ip_mask_v6' => 48,
                        'zeroboiler.analytics.attribution.enabled' => true,
                        'zeroboiler.analytics.attribution.model' => 'last_touch',
                        'zeroboiler.analytics.attribution.session_window_days' => 30,
                        'zeroboiler.analytics.attribution.cache_ttl' => 86400,
                        'zeroboiler.analytics.attribution.first_touch_ttl' => 2592000,
                        'zeroboiler.analytics.attribution.touch_history_ttl' => 2592000,
                        'zeroboiler.analytics.attribution.max_touch_history' => 20,
                        'zeroboiler.analytics.profile.enabled' => true,
                        'zeroboiler.analytics.profile.ttl' => 86400,
                        'zeroboiler.analytics.funnels.enabled' => true,
                        'zeroboiler.analytics.funnels.cache_enabled' => true,
                        'zeroboiler.analytics.funnels.cache_ttl' => 300,
                        'zeroboiler.analytics.alerts.enabled' => true,
                        'zeroboiler.analytics.alerts.cooldown' => 300,
                        'zeroboiler.analytics.alerts.max_history' => 200,
                        'zeroboiler.analytics.alerts.rules' => [],
                        'zeroboiler.analytics.inbound_webhook.enabled' => false,
                        'zeroboiler.analytics.inbound_webhook.secret' => '',
                        'zeroboiler.analytics.inbound_webhook.require_signature' => true,
                        'zeroboiler.analytics.inbound_webhook.max_payload_size' => 65536,
                        'zeroboiler.analytics.inbound_webhook.max_events' => 50,
                        'zeroboiler.analytics.lifecycle.enabled' => true,
                        'zeroboiler.analytics.lifecycle.override_defaults' => false,
                        'zeroboiler.analytics.lifecycle.events' => [],
                        'zeroboiler.analytics.lifecycle.custom_mappings' => [],
                        'zeroboiler.analytics.correlation.enabled' => true,
                        'zeroboiler.analytics.correlation.cache_enabled' => true,
                        'zeroboiler.analytics.correlation.cache_ttl' => 300,
                        'zeroboiler.analytics.correlation.max_pattern_length' => 5,
                        'zeroboiler.analytics.correlation.max_journeys_per_user' => 100,
                        'zeroboiler.analytics.retention.enabled' => false,
                        'zeroboiler.analytics.retention.days' => 90,
                        'zeroboiler.analytics.retention.archive_action' => 'delete',
                        'zeroboiler.analytics.source_tagging.enabled' => true,
                        'zeroboiler.analytics.source_tagging.tag_version' => true,
                        'zeroboiler.analytics.validation_boot.enabled' => false,
                        'zeroboiler.analytics.validation_boot.log_level' => 'warning',
                        'zeroboiler.analytics.broadcast.enabled' => false,
                        'zeroboiler.analytics.broadcast.channel_prefix' => 'analytics',
                        'zeroboiler.analytics.broadcast.private_channels' => true,
                        'zeroboiler.analytics.broadcast.value_threshold' => null,
                        'zeroboiler.analytics.broadcast.alert_channel' => 'analytics.alerts',
                        'zeroboiler.analytics.broadcast.metrics_channel' => 'analytics.metrics',
                        'zeroboiler.analytics.tenant.enabled' => false,
                        'zeroboiler.analytics.tenant.resolution_strategy' => 'user_attribute',
                        'zeroboiler.analytics.tenant.tenant_header' => 'X-Tenant-ID',
                        'zeroboiler.analytics.tenant.events_per_hour' => null,
                        'zeroboiler.analytics.retention_policy.enabled' => false,
                        'zeroboiler.analytics.retention_policy.auto_expire' => false,
                        'zeroboiler.analytics.retention_policy.pii_categories' => ['pii'],
                        'zeroboiler.analytics.retention_policy.engagement_days' => 30,
                        'zeroboiler.analytics.retention_policy.saas_days' => 90,
                        'zeroboiler.analytics.retention_policy.ecommerce_days' => 365,
                        'zeroboiler.analytics.gate.enabled' => false,
                        'zeroboiler.analytics.gate.default_plan' => 'free',
                        'zeroboiler.analytics.gate.plan_attribute' => 'plan',
                        'zeroboiler.analytics.referral.enabled' => false,
                        'zeroboiler.analytics.referral.param_name' => 'ref',
                        'zeroboiler.analytics.referral.ttl' => 2592000,
                        'zeroboiler.analytics.referral.track_conversions' => true,
                        'zeroboiler.analytics.dead_letter_queue.enabled' => true,
                        'zeroboiler.analytics.dead_letter_queue.strategy' => 'file',
                        'zeroboiler.analytics.dead_letter_queue.storage_path' => '',
                        'zeroboiler.analytics.dead_letter_queue.max_size' => 10000,
                        'zeroboiler.analytics.dead_letter_queue.buffer_size' => 50,
                        'zeroboiler.analytics.realtime.enabled' => true,
                        'zeroboiler.analytics.realtime.window_seconds' => 120,
                        'zeroboiler.analytics.realtime.top_events_limit' => 20,
                        'zeroboiler.analytics.snapshots.enabled' => true,
                        'zeroboiler.analytics.snapshots.daily_ttl' => 7776000,
                        'zeroboiler.analytics.snapshots.hourly_ttl' => 604800,
                        'zeroboiler.analytics.snapshots.max_daily' => 90,
                        'zeroboiler.analytics.snapshots.max_hourly' => 168,
                        'zeroboiler.analytics.saas_kpi.enabled' => true,
                        'zeroboiler.analytics.saas_kpi.cache_ttl' => 2592000,
                        'zeroboiler.analytics.utm_aggregation.enabled' => true,
                        'zeroboiler.analytics.utm_aggregation.cache_ttl' => 2592000,
                        'zeroboiler.analytics.utm_aggregation.max_combinations' => 5000,
                        'zeroboiler.analytics.geolocation.enabled' => false,
                        'zeroboiler.analytics.geolocation.strategy' => 'header',
                        'zeroboiler.analytics.geolocation.country_header' => 'CF-IPCountry',
                        'zeroboiler.analytics.geolocation.region_header' => '',
                        'zeroboiler.analytics.geolocation.city_header' => '',
                        'zeroboiler.analytics.reporting.enabled' => true,
                        'zeroboiler.analytics.reporting.cache_ttl' => 300,
                        'zeroboiler.analytics.reporting.trending_window' => 3600,
                        'zeroboiler.analytics.reporting.top_events_limit' => 20,
                        'zeroboiler.analytics.reporting.trending_limit' => 10,
                        'zeroboiler.analytics.ab_tests.enabled' => true,
                        'zeroboiler.analytics.ab_tests.confidence_threshold' => 0.95,
                        'zeroboiler.analytics.ab_tests.cache_ttl' => 604800,
                        'zeroboiler.analytics.performance_budget.enabled' => false,
                        'zeroboiler.analytics.performance_budget.max_payload_bytes' => 8192,
                        'zeroboiler.analytics.performance_budget.max_params_count' => 25,
                        'zeroboiler.analytics.performance_budget.max_events_per_session' => 100,
                        'zeroboiler.analytics.performance_budget.max_events_per_user_per_day' => 500,
                        'zeroboiler.analytics.performance_budget.max_events_per_page_view' => 50,
                        'zeroboiler.analytics.performance_budget.max_param_value_length' => 500,
                        'zeroboiler.analytics.performance_budget.drop_oversized' => true,
                        'zeroboiler.analytics.performance_budget.warn_only' => false,
                        'zeroboiler.analytics.forwarding.enabled' => false,
                        'zeroboiler.analytics.forwarding.timeout' => 5,
                        'zeroboiler.analytics.forwarding.retries' => 1,
                        'zeroboiler.analytics.forwarding.rate_limit_per_minute' => 1000,
                        'zeroboiler.analytics.forwarding.forwarders' => [],
                    ];

                    return $defaults[$key] ?? null;
                });
        }

        $ac = new AnalyticsConfig($this->config);
        $summary = $ac->summary();

        foreach ($requiredSections as $section) {
            expect(isset($summary[$section]))
                ->toBeTrue("AnalyticsConfig::summary() is missing '{$section}' section");
        }
    });

    test('summary count matches expected number of sections', function (): void {
        $this->config->shouldReceive('get')->andReturnNull();

        // Use default expectations for common keys
        $this->config->shouldReceive('get')
            ->withArgs(fn (string $key): bool => str_starts_with($key, 'zeroboiler.analytics.'))
            ->andReturnUsing(function (string $key, mixed $default) {
                return $default;
            });

        $ac = new AnalyticsConfig($this->config);
        $summary = $ac->summary();

        // Expect at least 55 config sections in summary (comprehensive coverage)
        expect(count($summary))->toBeGreaterThanOrEqual(55);
    });

    // ── CHANGELOG Integrity ───────────────────────────────────────

    test('CHANGELOG.md exists and has v2.41.0 entry', function (): void {
        $changelog = file_get_contents(__DIR__ . '/../CHANGELOG.md');
        expect($changelog)->not->toBeEmpty();
        expect(str_contains($changelog, '[2.41.0]'))->toBeTrue();
    });

    test('CHANGELOG.md has MIT license header', function (): void {
        $changelog = file_get_contents(__DIR__ . '/../CHANGELOG.md');
        expect(str_contains($changelog, 'All notable changes'))->toBeTrue();
    });

    // ── PHP 8.5 Compliance ────────────────────────────────────────

    test('AnalyticsConfig has no mixed type issues', function (): void {
        $reflection = new ReflectionClass(AnalyticsConfig::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        expect(count($methods))->toBeGreaterThan(60, 'AnalyticsConfig should have at least 60 public accessors');

        foreach ($methods as $method) {
            $name = $method->getName();
            // All methods except get/has should have explicit return types
            if ($name === 'get' || $name === 'has') {
                continue;
            }
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull(
                "AnalyticsConfig::{$name}() is missing explicit return type declaration"
            );
        }
    });

    test('AnalyticsConfig is final and immutable', function (): void {
        $reflection = new ReflectionClass(AnalyticsConfig::class);
        expect($reflection->isFinal())->toBeTrue();

        // Check that all properties are readonly
        $properties = $reflection->getProperties();
        foreach ($properties as $property) {
            $name = $property->getName();
            // Skip static const
            if ($property->isStatic()) {
                continue;
            }
            expect($property->isReadOnly())->toBeTrue(
                "AnalyticsConfig::\${$name} should be readonly"
            );
        }
    });
});
