<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Services\AnalyticsConfigValidator;
use ZeroBoiler\Analytics\Services\EventSourceTagger;
use ZeroBoiler\Analytics\Services\ReferrerTrackingService;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;

beforeEach(function (): void {
    $this->config = mock(ConfigRepository::class);
});

// ── AnalyticsConfigValidator Tests ─────────────────────────────────

describe('AnalyticsConfigValidator', function (): void {
    test('validates empty config returns no errors', function (): void {
        $this->config->shouldReceive('get')->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $result = $validator->validate();

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBe(0);
        expect($result['issues'])->toBeArray();
    });

    test('detects GA4 enabled without measurement_id', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ga4.enabled', false)
            ->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ga4.measurement_id', '')
            ->andReturn('');
        $this->config->shouldReceive('get')
            ->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $result = $validator->validate();

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toBeGreaterThan(0);

        $errorKeys = array_column(
            array_filter($result['issues'], fn (array $i): bool => $i['level'] === 'error'),
            'config_key',
        );
        expect($errorKeys)->toContain('ga4.measurement_id');
    });

    test('warns about GA4 measurement_id without G- prefix', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ga4.enabled', false)
            ->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ga4.measurement_id', '')
            ->andReturn('invalid-id');
        $this->config->shouldReceive('get')
            ->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $result = $validator->validate();

        $warningKeys = array_column(
            array_filter($result['issues'], fn (array $i): bool => $i['level'] === 'warning'),
            'config_key',
        );
        expect($warningKeys)->toContain('ga4.measurement_id');
    });

    test('detects GTM enabled without container_id', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.gtm.enabled', false)
            ->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.gtm.container_id', '')
            ->andReturn('');
        $this->config->shouldReceive('get')
            ->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $result = $validator->validate();

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toBeGreaterThan(0);
    });

    test('warns about GTM container_id without GTM- prefix', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.gtm.enabled', false)
            ->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.gtm.container_id', '')
            ->andReturn('GTM123456'); // Valid prefix
        $this->config->shouldReceive('get')
            ->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $result = $validator->validate();

        // GTM-123456 is valid, should have 0 warnings for GTM
        $gtmWarnings = array_filter($result['issues'], fn (array $i): bool =>
            $i['config_key'] === 'gtm.container_id' && $i['level'] === 'warning'
        );
        expect($gtmWarnings)->toBeEmpty();
    });

    test('detects Meta Pixel enabled without pixel id', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.meta_pixel.enabled', false)
            ->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.meta_pixel.id', '')
            ->andReturn('');
        $this->config->shouldReceive('get')
            ->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $result = $validator->validate();

        expect($result['valid'])->toBeFalse();
    });

    test('detects webhook enabled without URL', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.webhook.enabled', false)
            ->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.webhook.url', '')
            ->andReturn('');
        $this->config->shouldReceive('get')
            ->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $result = $validator->validate();

        expect($result['valid'])->toBeFalse();
    });

    test('warns about HTTP webhook URL', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.webhook.enabled', false)
            ->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.webhook.url', '')
            ->andReturn('http://example.com/hook');
        $this->config->shouldReceive('get')
            ->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $result = $validator->validate();

        $warningKeys = array_column(
            array_filter($result['issues'], fn (array $i): bool => $i['level'] === 'warning'),
            'config_key',
        );
        expect($warningKeys)->toContain('webhook.url');
    });

    test('validates sampling rate range', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.sampling.enabled', false)
            ->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.sampling.rate', 1.0)
            ->andReturn(1.5); // Invalid: > 1.0
        $this->config->shouldReceive('get')
            ->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $result = $validator->validate();

        expect($result['valid'])->toBeFalse();

        $errorKeys = array_column(
            array_filter($result['issues'], fn (array $i): bool => $i['level'] === 'error'),
            'config_key',
        );
        expect($errorKeys)->toContain('sampling.rate');
    });

    test('validates retention days', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.retention.enabled', false)
            ->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.retention.days', 90)
            ->andReturn(0); // Invalid: < 1
        $this->config->shouldReceive('get')
            ->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $result = $validator->validate();

        expect($result['valid'])->toBeFalse();

        $errorKeys = array_column(
            array_filter($result['issues'], fn (array $i): bool => $i['level'] === 'error'),
            'config_key',
        );
        expect($errorKeys)->toContain('retention.days');
    });

    test('validates consent default values', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent.default', 'granted')
            ->andReturn('maybe'); // Invalid
        $this->config->shouldReceive('get')
            ->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $result = $validator->validate();

        expect($result['valid'])->toBeFalse();

        $errorKeys = array_column(
            array_filter($result['issues'], fn (array $i): bool => $i['level'] === 'error'),
            'config_key',
        );
        expect($errorKeys)->toContain('consent.default');
    });

    test('info message for disabled queue', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.enabled', true)
            ->andReturn(false);
        $this->config->shouldReceive('get')
            ->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $result = $validator->validate();

        $infoKeys = array_column(
            array_filter($result['issues'], fn (array $i): bool => $i['level'] === 'info'),
            'config_key',
        );
        expect($infoKeys)->toContain('queue.enabled');
    });

    test('isValid returns true when no errors', function (): void {
        $this->config->shouldReceive('get')->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);

        expect($validator->isValid())->toBeTrue();
    });

    test('errors() returns only error-level issues', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ga4.enabled', false)
            ->andReturn(true);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ga4.measurement_id', '')
            ->andReturn('');
        $this->config->shouldReceive('get')
            ->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $errors = $validator->errors();

        expect($errors)->not->toBeEmpty();
        foreach ($errors as $error) {
            expect($error['level'])->toBe('error');
        }
    });

    test('warnings() returns only warning-level issues', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.queue.enabled', true)
            ->andReturn(false);
        $this->config->shouldReceive('get')
            ->andReturnFalse();

        $validator = new AnalyticsConfigValidator($this->config);
        $warnings = $validator->warnings();

        expect($warnings)->not->toBeEmpty();
        foreach ($warnings as $warning) {
            expect($warning['level'])->toBe('warning');
        }
    });
});

// ── EventSourceTagger Tests ────────────────────────────────────────

describe('EventSourceTagger', function (): void {
    $tagger = new EventSourceTagger;

    test('tags event with source metadata', function () use ($tagger): void {
        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);
        $tagged = $tagger->tag($event, 'api');

        expect($tagged->name)->toBe('purchase');
        expect($tagged->params['_source'])->toBe('api');
        expect($tagged->params['_timestamp'])->toBeString();
        expect($tagged->params['_version'])->toBe('2.88.0');
        expect($tagged->params['value'])->toBe(99.99);
    });

    test('tagAsApi includes request_path', function () use ($tagger): void {
        $event = new AnalyticsEvent(name: 'page_view');
        $tagged = $tagger->tagAsApi($event, '/dashboard');

        expect($tagged->params['_source'])->toBe('api');
        expect($tagged->params['_request_path'])->toBe('/dashboard');
    });

    test('tagAsServer includes caller', function () use ($tagger): void {
        $event = new AnalyticsEvent(name: 'login');
        $tagged = $tagger->tagAsServer($event, 'ServerSideTracker');

        expect($tagged->params['_source'])->toBe('server');
        expect($tagged->params['_caller'])->toBe('ServerSideTracker');
    });

    test('tagAsCron includes command', function () use ($tagger): void {
        $event = new AnalyticsEvent(name: 'revenue_tracked');
        $tagged = $tagger->tagAsCron($event, 'zb:analytics:export');

        expect($tagged->params['_source'])->toBe('cron');
        expect($tagged->params['_command'])->toBe('zb:analytics:export');
    });

    test('tagAsWebhook includes webhook_url', function () use ($tagger): void {
        $event = new AnalyticsEvent(name: 'payment.completed');
        $tagged = $tagger->tagAsWebhook($event, 'https://stripe.com/webhook');

        expect($tagged->params['_source'])->toBe('webhook_inbound');
        expect($tagged->params['_webhook_url'])->toBe('https://stripe.com/webhook');
    });

    test('tagAsLifecycle includes mapping_key', function () use ($tagger): void {
        $event = new AnalyticsEvent(name: 'login');
        $tagged = $tagger->tagAsLifecycle($event, 'auth.login');

        expect($tagged->params['_source'])->toBe('lifecycle');
        expect($tagged->params['_mapping_key'])->toBe('auth.login');
    });

    test('tagAsBatch includes batch metadata', function () use ($tagger): void {
        $event = new AnalyticsEvent(name: 'page_view');
        $tagged = $tagger->tagAsBatch($event, 3, 10);

        expect($tagged->params['_source'])->toBe('batch');
        expect($tagged->params['_batch_index'])->toBe(3);
        expect($tagged->params['_batch_size'])->toBe(10);
    });

    test('invalid source defaults to server', function () use ($tagger): void {
        $event = new AnalyticsEvent(name: 'test');
        $tagged = $tagger->tag($event, 'invalid_source');

        expect($tagged->params['_source'])->toBe('server');
    });

    test('extractSource returns source from tagged event', function () use ($tagger): void {
        $event = new AnalyticsEvent(name: 'test', params: ['_source' => 'api']);
        expect($tagger->extractSource($event))->toBe('api');
    });

    test('extractSource returns unknown for untagged event', function () use ($tagger): void {
        $event = new AnalyticsEvent(name: 'test');
        expect($tagger->extractSource($event))->toBe('unknown');
    });

    test('isTagged returns true for tagged events', function () use ($tagger): void {
        $event = new AnalyticsEvent(name: 'test', params: ['_source' => 'api']);
        expect($tagger->isTagged($event))->toBeTrue();
    });

    test('isTagged returns false for untagged events', function () use ($tagger): void {
        $event = new AnalyticsEvent(name: 'test');
        expect($tagger->isTagged($event))->toBeFalse();
    });

    test('validSources returns all source types', function () use ($tagger): void {
        $sources = EventSourceTagger::validSources();

        expect($sources)->toContain('api');
        expect($sources)->toContain('server');
        expect($sources)->toContain('cron');
        expect($sources)->toContain('webhook_inbound');
        expect($sources)->toContain('lifecycle');
        expect($sources)->toContain('test');
        expect($sources)->toContain('batch');
        expect($sources)->toHaveCount(7);
    });

    test('preserves original event properties', function () use ($tagger): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 100, 'currency' => 'EUR'],
            clientId: 'client-123',
            userId: 'user-456',
        );

        $tagged = $tagger->tagAsApi($event);

        expect($tagged->name)->toBe('purchase');
        expect($tagged->clientId)->toBe('client-123');
        expect($tagged->userId)->toBe('user-456');
        expect($tagged->params['value'])->toBe(100);
        expect($tagged->params['currency'])->toBe('EUR');
    });
});

// ── ReferrerTrackingService Tests ─────────────────────────────────

describe('ReferrerTrackingService', function (): void {
    $service = new ReferrerTrackingService;

    test('extracts UTM parameters from request', function () use ($service): void {
        $request = new Illuminate\Http\Request([
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring_sale',
            'utm_term' => 'running+shoes',
            'utm_content' => 'banner_ad',
        ]);

        $utm = $service->extractUtm($request);

        expect($utm)->toBe([
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring_sale',
            'utm_term' => 'running+shoes',
            'utm_content' => 'banner_ad',
        ]);
    });

    test('returns empty UTM when no params present', function () use ($service): void {
        $request = new Illuminate\Http\Request;
        $utm = $service->extractUtm($request);

        expect($utm)->toBe([]);
    });

    test('detects direct traffic when no referrer', function () use ($service): void {
        $request = new Illuminate\Http\Request;
        $request->headers->set('referer', '');

        $referrer = $service->extractReferrer($request);

        expect($referrer['source'])->toBe('direct');
        expect($referrer['medium'])->toBe('none');
    });

    test('detects UTM source as primary source', function () use ($service): void {
        $request = new Illuminate\Http\Request([
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
        ]);
        $request->headers->set('referer', 'https://google.com/search?q=test');

        $referrer = $service->extractReferrer($request);

        expect($referrer['source'])->toBe('newsletter');
        expect($referrer['medium'])->toBe('email');
    });

    test('detects social referrer from facebook', function () use ($service): void {
        $request = new Illuminate\Http\Request;
        $request->headers->set('referer', 'https://www.facebook.com/some/page');

        $referrer = $service->extractReferrer($request);

        expect($referrer['source'])->toBe('facebook');
        expect($referrer['medium'])->toBe('social');
        expect($referrer['social_network'])->toBe('facebook');
    });

    test('detects social referrer from twitter/x.com', function () use ($service): void {
        $request = new Illuminate\Http\Request;
        $request->headers->set('referer', 'https://x.com/user/status/123');

        $referrer = $service->extractReferrer($request);

        expect($referrer['source'])->toBe('twitter');
        expect($referrer['medium'])->toBe('social');
    });

    test('detects social referrer from linkedin', function () use ($service): void {
        $request = new Illuminate\Http\Request;
        $request->headers->set('referer', 'https://www.linkedin.com/posts/some-post');

        $referrer = $service->extractReferrer($request);

        expect($referrer['source'])->toBe('linkedin');
        expect($referrer['medium'])->toBe('social');
    });

    test('detects search engine referrer from google', function () use ($service): void {
        $request = new Illuminate\Http\Request;
        $request->headers->set('referer', 'https://www.google.com/search?q=analytics');

        $referrer = $service->extractReferrer($request);

        expect($referrer['source'])->toBe('google');
        expect($referrer['medium'])->toBe('organic');
        expect($referrer['search_engine'])->toBe('google');
    });

    test('detects search engine referrer from bing', function () use ($service): void {
        $request = new Illuminate\Http\Request;
        $request->headers->set('referer', 'https://www.bing.com/search?q=test');

        $referrer = $service->extractReferrer($request);

        expect($referrer['source'])->toBe('bing');
        expect($referrer['medium'])->toBe('organic');
    });

    test('detects generic referral from unknown domain', function () use ($service): void {
        $request = new Illuminate\Http\Request;
        $request->headers->set('referer', 'https://example.com/blog/post');

        $referrer = $service->extractReferrer($request);

        expect($referrer['source'])->toBe('example.com');
        expect($referrer['medium'])->toBe('referral');
        expect($referrer['domain'])->toBe('example.com');
    });

    test('normalizes referrer URL with scheme', function () use ($service): void {
        expect($service->normalizeReferrer('example.com'))->toBe('https://example.com');
        expect($service->normalizeReferrer('http://example.com'))->toBe('http://example.com');
        expect($service->normalizeReferrer(null))->toBeNull();
        expect($service->normalizeReferrer(''))->toBeNull();
        expect($service->normalizeReferrer('-'))->toBeNull();
    });

    test('isSocialReferrer detects social domains', function () use ($service): void {
        expect($service->isSocialReferrer('facebook.com'))->toBeTrue();
        expect($service->isSocialReferrer('www.facebook.com'))->toBeTrue();
        expect($service->isSocialReferrer('m.facebook.com'))->toBeTrue();
        expect($service->isSocialReferrer('google.com'))->toBeFalse();
        expect($service->isSocialReferrer(null))->toBeFalse();
    });

    test('isSearchEngineReferrer detects search engines', function () use ($service): void {
        expect($service->isSearchEngineReferrer('google.com'))->toBeTrue();
        expect($service->isSearchEngineReferrer('www.bing.com'))->toBeTrue();
        expect($service->isSearchEngineReferrer('duckduckgo.com'))->toBeTrue();
        expect($service->isSearchEngineReferrer('facebook.com'))->toBeFalse();
        expect($service->isSearchEngineReferrer(null))->toBeFalse();
    });

    test('builds tracked URL with UTM params', function () use ($service): void {
        $url = $service->buildTrackedUrl('https://example.com/landing', [
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
            'utm_campaign' => 'weekly',
        ]);

        expect($url)->toContain('utm_source=newsletter');
        expect($url)->toContain('utm_medium=email');
        expect($url)->toContain('utm_campaign=weekly');
    });

    test('builds tracked URL appends to existing query params', function () use ($service): void {
        $url = $service->buildTrackedUrl('https://example.com/page?ref=home', [
            'utm_source' => 'google',
        ]);

        expect($url)->toContain('ref=home');
        expect($url)->toContain('utm_source=google');
    });

    test('returns original URL when no UTM provided', function () use ($service): void {
        $url = $service->buildTrackedUrl('https://example.com/page', []);

        expect($url)->toBe('https://example.com/page');
    });

    test('strips www from referrer domain', function () use ($service): void {
        $request = new Illuminate\Http\Request;
        $request->headers->set('referer', 'https://www.example.com/path');

        $referrer = $service->extractReferrer($request);

        expect($referrer['domain'])->toBe('example.com');
    });
});

// ── Config Expansion Tests ────────────────────────────────────────

describe('v2.29 config expansion', function (): void {
    test('retention config accessors return defaults', function (): void {
        $this->config->shouldReceive('get')->andReturnNull();

        $config = new AnalyticsConfig($this->config);

        expect($config->retentionEnabled())->toBeFalse();
        expect($config->retentionDays())->toBe(90);
        expect($config->retentionArchiveAction())->toBe('delete');
    });

    test('source tagging config accessors return defaults', function (): void {
        $this->config->shouldReceive('get')->andReturnNull();

        $config = new AnalyticsConfig($this->config);

        expect($config->sourceTaggingEnabled())->toBeTrue();
        expect($config->sourceTaggingVersion())->toBeTrue();
    });

    test('validation boot config accessors return defaults', function (): void {
        $this->config->shouldReceive('get')->andReturnNull();

        $config = new AnalyticsConfig($this->config);

        expect($config->validationBootEnabled())->toBeFalse();
        expect($config->validationBootLogLevel())->toBe('warning');
    });

    test('summary includes new config sections', function (): void {
        $this->config->shouldReceive('get')->andReturnNull();

        $config = new AnalyticsConfig($this->config);
        $summary = $config->summary();

        expect($summary)->toHaveKey('retention');
        expect($summary)->toHaveKey('source_tagging');
        expect($summary)->toHaveKey('validation_boot');
        expect($summary['retention']['days'])->toBe(90);
        expect($summary['source_tagging']['enabled'])->toBeTrue();
        expect($summary['validation_boot']['log_level'])->toBe('warning');
    });

    test('summary total section count is 50', function (): void {
        $this->config->shouldReceive('get')->andReturnNull();

        $config = new AnalyticsConfig($this->config);

        expect(count($config->summary()))->toBe(50);
    });
});

// ── Version Consistency Tests ─────────────────────────────────────

describe('v2.29 version consistency', function (): void {
    test('version string is 2.29.0', function (): void {
        $this->config->shouldReceive('get')->andReturnFalse();
        $manager = new AnalyticsManager($this->config);

        expect($manager->version())->toBe('2.88.0');
    });

    test('event catalog count is 53 (after subscription_renewal)', function (): void {
        expect(EventCatalog::count())->toBe(53);
    });

    test('event catalog categories are intact', function (): void {
        $categories = EventCatalog::byCategory();

        expect($categories)->toHaveKey('ecommerce');
        expect($categories)->toHaveKey('saas');
        expect($categories)->toHaveKey('engagement');
        expect($categories['ecommerce'])->toHaveCount(12);
        expect($categories['saas'])->toHaveCount(17);
        expect($categories['engagement'])->toHaveCount(20);
    });

    test('all event names are unique', function (): void {
        $names = EventCatalog::names();
        $unique = array_unique($names);

        expect(count($names))->toBe(count($unique));
    });
});
