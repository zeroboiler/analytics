<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\Services\UtmParameterManager;

// ── V5500 UTM Parameter Manager Test Suite ───────────────────────────

describe('V5500 UTM Parameter Manager', function () {

    // ── Helper: create manager with config ──────────────────────────

    function createManager(array $overrides = []): UtmParameterManager
    {
        $config = array_merge([
            'enabled' => true,
            'max_value_length' => 500,
            'max_key_length' => 100,
            'lowercase_source_medium' => true,
            'trim_values' => true,
            'strip_html' => true,
            'aliases' => [],
            'required_for_completeness' => ['utm_source', 'utm_medium', 'utm_campaign'],
            'internal_params' => [],
        ], $overrides);

        $repo = new Repository(['zeroboiler' => ['analytics' => ['utm_manager' => $config]]]);

        return new UtmParameterManager($repo);
    }

    // ── 1. Construction & Configuration ──────────────────────────

    describe('construction & configuration', function () {
        test('constructs with default config', function (): void {
            $manager = createManager();
            expect($manager->isEnabled())->toBeTrue();
            expect($manager->configSummary())->toHaveKey('enabled');
            expect($manager->configSummary()['enabled'])->toBeTrue();
        });

        test('constructs as disabled', function (): void {
            $manager = createManager(['enabled' => false]);
            expect($manager->isEnabled())->toBeFalse();
        });

        test('config summary has all keys', function (): void {
            $summary = createManager()->configSummary();
            expect($summary)->toHaveKeys([
                'enabled',
                'max_value_length',
                'max_key_length',
                'lowercase_source_medium',
                'trim_values',
                'strip_html',
                'aliases',
                'required_for_completeness',
                'internal_params_count',
                'standard_params_count',
            ]);
        });

        test('standard params returns 6 params', function (): void {
            $manager = createManager();
            expect($manager->standardParams())->toHaveCount(6);
            expect($manager->standardParams())->toContain('utm_source');
            expect($manager->standardParams())->toContain('utm_medium');
            expect($manager->standardParams())->toContain('utm_campaign');
            expect($manager->standardParams())->toContain('utm_term');
            expect($manager->standardParams())->toContain('utm_content');
            expect($manager->standardParams())->toContain('utm_id');
        });

        test('internal params returns built-in + custom', function (): void {
            $manager = createManager(['internal_params' => ['custom_param']]);
            $params = $manager->internalParams();
            expect($params)->toContain('fbclid');
            expect($params)->toContain('gclid');
            expect($params)->toContain('custom_param');
            expect(count($params))->toBeGreaterThan(25);
        });
    });

    // ── 2. UTM Extraction ────────────────────────────────────────

    describe('UTM extraction from URL', function () {
        test('extracts all standard UTM params from URL', function (): void {
            $manager = createManager();
            $url = 'https://example.com/page?utm_source=google&utm_medium=cpc&utm_campaign=spring_sale&utm_term=shoes&utm_content=banner_v2';
            $result = $manager->extractFromUrl($url);

            expect($result)->toHaveCount(5);
            expect($result['utm_source'])->toBe('google');
            expect($result['utm_medium'])->toBe('cpc');
            expect($result['utm_campaign'])->toBe('spring_sale');
            expect($result['utm_term'])->toBe('shoes');
            expect($result['utm_content'])->toBe('banner_v2');
        });

        test('returns empty for URL with no UTM params', function (): void {
            $manager = createManager();
            $result = $manager->extractFromUrl('https://example.com/page?foo=bar');

            expect($result)->toBeEmpty();
        });

        test('returns empty for URL with no query string', function (): void {
            $manager = createManager();
            $result = $manager->extractFromUrl('https://example.com/page');

            expect($result)->toBeEmpty();
        });

        test('returns empty for malformed URL', function (): void {
            $manager = createManager();
            $result = $manager->extractFromUrl('not-a-url');

            expect($result)->toBeEmpty();
        });

        test('extracts only UTM params, ignores non-UTM', function (): void {
            $manager = createManager();
            $url = 'https://example.com?utm_source=google&foo=bar&utm_medium=email&baz=qux';
            $result = $manager->extractFromUrl($url);

            expect($result)->toHaveCount(2);
            expect($result)->toHaveKey('utm_source');
            expect($result)->toHaveKey('utm_medium');
            expect($result)->not->toHaveKey('foo');
            expect($result)->not->toHaveKey('baz');
        });
    });

    // ── 3. UTM Extraction from Array ─────────────────────────────

    describe('UTM extraction from array', function () {
        test('extracts UTM params from array', function (): void {
            $manager = createManager();
            $params = [
                'utm_source' => 'twitter',
                'utm_medium' => 'social',
                'utm_campaign' => 'launch',
                'page' => 'home',
                'lang' => 'en',
            ];
            $result = $manager->extractFromArray($params);

            expect($result)->toHaveCount(3);
            expect($result['utm_source'])->toBe('twitter');
        });

        test('skips non-string values', function (): void {
            $manager = createManager();
            $params = [
                'utm_source' => 'google',
                'utm_medium' => 123,
                'utm_campaign' => null,
            ];
            $result = $manager->extractFromArray($params);

            expect($result)->toHaveCount(1);
            expect($result['utm_source'])->toBe('google');
        });
    });

    // ── 4. Alias Resolution ──────────────────────────────────────

    describe('alias resolution', function () {
        test('resolves configured aliases', function (): void {
            $manager = createManager(['aliases' => [
                'source' => 'utm_source',
                'medium' => 'utm_medium',
            ]]);
            $params = [
                'source' => 'facebook',
                'medium' => 'paid',
            ];
            $result = $manager->extractFromArray($params);

            expect($result)->toHaveCount(2);
            expect($result['utm_source'])->toBe('facebook');
            expect($result['utm_medium'])->toBe('paid');
        });

        test('resolveAlias returns key when no alias exists', function (): void {
            $manager = createManager();
            expect($manager->resolveAlias('utm_source'))->toBe('utm_source');
            expect($manager->resolveAlias('unknown_key'))->toBe('unknown_key');
        });

        test('getAliases returns configured aliases', function (): void {
            $aliases = ['source' => 'utm_source', 'campaign' => 'utm_campaign'];
            $manager = createManager(['aliases' => $aliases]);
            expect($manager->getAliases())->toBe($aliases);
        });

        test('defaultAliases returns standard mappings', function (): void {
            $defaults = UtmParameterManager::defaultAliases();
            expect($defaults)->toHaveKey('source');
            expect($defaults)->toHaveKey('medium');
            expect($defaults)->toHaveKey('campaign');
            expect($defaults)->toHaveKey('term');
            expect($defaults)->toHaveKey('content');
        });
    });

    // ── 5. Validation ────────────────────────────────────────────

    describe('validation', function () {
        test('validates complete UTM set', function (): void {
            $manager = createManager();
            $params = [
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'spring',
            ];
            $result = $manager->validate($params);

            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
        });

        test('fails when required params missing', function (): void {
            $manager = createManager();
            $result = $manager->validate(['utm_source' => 'google']);

            expect($result['valid'])->toBeFalse();
            expect($result['errors'])->not->toBeEmpty();
        });

        test('reports error for empty required param', function (): void {
            $manager = createManager();
            $result = $manager->validate([
                'utm_source' => 'google',
                'utm_medium' => '',
                'utm_campaign' => 'test',
            ]);

            expect($result['valid'])->toBeFalse();
        });

        test('reports warning for empty non-required param', function (): void {
            $manager = createManager();
            $result = $manager->validate([
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'test',
                'utm_term' => '',
            ]);

            expect($result['valid'])->toBeTrue();
            expect($result['warnings'])->not->toBeEmpty();
        });

        test('detects oversized values', function (): void {
            $manager = createManager(['max_value_length' => 10]);
            $result = $manager->validate([
                'utm_source' => str_repeat('a', 20),
                'utm_medium' => 'cpc',
                'utm_campaign' => 'test',
            ]);

            expect($result['valid'])->toBeFalse();
            expect($result['errors'])->not->toBeEmpty();
        });

        test('detects oversized keys', function (): void {
            $manager = createManager(['max_key_length' => 5]);
            $result = $manager->validate([
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'test',
                str_repeat('x', 20) => 'value',
            ]);

            expect($result['valid'])->toBeFalse();
        });
    });

    // ── 6. Sanitization ──────────────────────────────────────────

    describe('sanitization', function () {
        test('sanitizes single value', function (): void {
            $manager = createManager();
            expect($manager->sanitizeValue('  Google  '))->toBe('Google');
        });

        test('strips HTML from values', function (): void {
            $manager = createManager();
            expect($manager->sanitizeValue('<b>Google</b>'))->toBe('Google');
        });

        test('truncates oversized values', function (): void {
            $manager = createManager(['max_value_length' => 10]);
            $result = $manager->sanitizeValue(str_repeat('a', 100));
            expect($result)->toBe(str_repeat('a', 10));
        });

        test('sanitizeParams lowercases source and medium', function (): void {
            $manager = createManager(['lowercase_source_medium' => true]);
            $params = [
                'utm_source' => 'GOOGLE',
                'utm_medium' => 'CPC',
                'utm_campaign' => 'Spring Sale',
            ];
            $result = $manager->sanitizeParams($params);

            expect($result['utm_source'])->toBe('google');
            expect($result['utm_medium'])->toBe('cpc');
            expect($result['utm_campaign'])->toBe('Spring Sale');
        });

        test('sanitizeParams preserves case when lowercase disabled', function (): void {
            $manager = createManager(['lowercase_source_medium' => false]);
            $params = [
                'utm_source' => 'GOOGLE',
                'utm_medium' => 'CPC',
                'utm_campaign' => 'Spring Sale',
            ];
            $result = $manager->sanitizeParams($params);

            expect($result['utm_source'])->toBe('GOOGLE');
            expect($result['utm_medium'])->toBe('CPC');
        });

        test('extractAndSanitizeUrl combines both operations', function (): void {
            $manager = createManager();
            $url = 'https://example.com?utm_source=GOOGLE&utm_medium=CPC&utm_campaign=Spring';
            $result = $manager->extractAndSanitizeUrl($url);

            expect($result['utm_source'])->toBe('google');
            expect($result['utm_medium'])->toBe('cpc');
            expect($result['utm_campaign'])->toBe('Spring');
        });
    });

    // ── 7. URL Decoration ────────────────────────────────────────

    describe('URL decoration', function () {
        test('decorates URL with UTM params', function (): void {
            $manager = createManager();
            $url = 'https://example.com/landing';
            $utm = [
                'utm_source' => 'newsletter',
                'utm_medium' => 'email',
                'utm_campaign' => 'weekly_digest',
            ];
            $result = $manager->decorateUrl($url, $utm);

            expect($result)->toContain('utm_source=newsletter');
            expect($result)->toContain('utm_medium=email');
            expect($result)->toContain('utm_campaign=weekly_digest');
        });

        test('merges with existing query params', function (): void {
            $manager = createManager();
            $url = 'https://example.com/page?ref=home&utm_source=old';
            $utm = ['utm_source' => 'new'];
            $result = $manager->decorateUrl($url, $utm);

            expect($result)->toContain('utm_source=new');
            expect($result)->toContain('ref=home');
        });

        test('returns original URL when no params given', function (): void {
            $manager = createManager();
            $url = 'https://example.com/page';
            expect($manager->decorateUrl($url, []))->toBe($url);
        });

        test('returns original URL for malformed URL', function (): void {
            $manager = createManager();
            $url = 'not-valid';
            expect($manager->decorateUrl($url, ['utm_source' => 'test']))->toBe($url);
        });
    });

    // ── 8. URL Cleaning ──────────────────────────────────────────

    describe('URL cleaning', function () {
        test('strips fbclid from URL', function (): void {
            $manager = createManager();
            $url = 'https://example.com/page?utm_source=google&fbclid=abc123';
            $result = $manager->cleanUrl($url);

            expect($result)->toContain('utm_source=google');
            expect($result)->not->toContain('fbclid');
        });

        test('strips gclid from URL', function (): void {
            $manager = createManager();
            $url = 'https://example.com/page?gclid=xyz&utm_source=google';
            $result = $manager->cleanUrl($url);

            expect($result)->not->toContain('gclid');
            expect($result)->toContain('utm_source=google');
        });

        test('strips multiple internal params', function (): void {
            $manager = createManager();
            $url = 'https://example.com?fbclid=a&gclid=b&msclkid=c&utm_source=google&utm_medium=cpc';
            $result = $manager->cleanUrl($url);

            expect($result)->not->toContain('fbclid');
            expect($result)->not->toContain('gclid');
            expect($result)->not->toContain('msclkid');
            expect($result)->toContain('utm_source=google');
            expect($result)->toContain('utm_medium=cpc');
        });

        test('strips custom internal params', function (): void {
            $manager = createManager(['internal_params' => ['custom_tracker']]);
            $url = 'https://example.com?custom_tracker=abc&utm_source=google';
            $result = $manager->cleanUrl($url);

            expect($result)->not->toContain('custom_tracker');
            expect($result)->toContain('utm_source=google');
        });

        test('returns URL unchanged if no query string', function (): void {
            $manager = createManager();
            $url = 'https://example.com/page';
            expect($manager->cleanUrl($url))->toBe($url);
        });

        test('handles URL with only internal params', function (): void {
            $manager = createManager();
            $url = 'https://example.com?fbclid=abc';
            $result = $manager->cleanUrl($url);

            expect($result)->toBe('https://example.com');
        });
    });

    // ── 9. Clean & Decorate ───────────────────────────────────────

    describe('clean and decorate', function () {
        test('strips internal params then adds UTM', function (): void {
            $manager = createManager();
            $url = 'https://example.com/page?fbclid=abc&old_source=legacy';
            $utm = ['utm_source' => 'newsletter', 'utm_medium' => 'email'];
            $result = $manager->cleanAndDecorate($url, $utm);

            expect($result)->not->toContain('fbclid');
            expect($result)->toContain('utm_source=newsletter');
            expect($result)->toContain('utm_medium=email');
            expect($result)->toContain('old_source=legacy');
        });
    });

    // ── 10. Completeness Score ───────────────────────────────────

    describe('completeness score', function () {
        test('score 100 for complete set', function (): void {
            $manager = createManager();
            $params = [
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'spring',
            ];
            $score = $manager->completenessScore($params);

            expect($score['score'])->toBe(100);
            expect($score['present'])->toBe(3);
            expect($score['total'])->toBe(3);
            expect($score['missing'])->toBeEmpty();
        });

        test('score 66 for partial set', function (): void {
            $manager = createManager();
            $params = [
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
            ];
            $score = $manager->completenessScore($params);

            expect($score['score'])->toBe(67); // round(2/3 * 100)
            expect($score['present'])->toBe(2);
            expect($score['missing'])->toContain('utm_campaign');
        });

        test('score 0 for empty set', function (): void {
            $manager = createManager();
            $score = $manager->completenessScore([]);

            expect($score['score'])->toBe(0);
            expect($score['missing'])->toHaveCount(3);
        });

        test('score with custom required params', function (): void {
            $manager = createManager(['required_for_completeness' => ['utm_source']]);
            $score = $manager->completenessScore(['utm_source' => 'google']);

            expect($score['score'])->toBe(100);
            expect($score['total'])->toBe(1);
        });
    });

    // ── 11. isUtmParam ──────────────────────────────────────────

    describe('isUtmParam', function () {
        test('returns true for standard params', function (): void {
            $manager = createManager();
            expect($manager->isUtmParam('utm_source'))->toBeTrue();
            expect($manager->isUtmParam('utm_medium'))->toBeTrue();
            expect($manager->isUtmParam('utm_campaign'))->toBeTrue();
            expect($manager->isUtmParam('utm_term'))->toBeTrue();
            expect($manager->isUtmParam('utm_content'))->toBeTrue();
            expect($manager->isUtmParam('utm_id'))->toBeTrue();
        });

        test('returns false for non-UTM params', function (): void {
            $manager = createManager();
            expect($manager->isUtmParam('source'))->toBeFalse();
            expect($manager->isUtmParam('page'))->toBeFalse();
            expect($manager->isUtmParam('fbclid'))->toBeFalse();
        });
    });

    // ── 12. Version Consistency ─────────────────────────────────

    describe('version consistency', function () {
        test('UtmParameterManager class exists and is final', function (): void {
            $ref = new ReflectionClass(UtmParameterManager::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('standard params are constant', function (): void {
            $ref = new ReflectionClass(UtmParameterManager::class);
            expect($ref->getConstant('STANDARD_PARAMS'))->not->toBeNull();
        });

        test('internal params constant is non-empty', function (): void {
            $ref = new ReflectionClass(UtmParameterManager::class);
            $internal = $ref->getConstant('INTERNAL_PARAMS');
            expect($internal)->not->toBeEmpty();
            expect(count($internal))->toBeGreaterThanOrEqual(20);
        });
    });
});
