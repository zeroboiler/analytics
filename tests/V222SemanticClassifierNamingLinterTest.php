<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mockery;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventNamingConventionLinter;
use ZeroBoiler\Analytics\Services\EventSemanticClassifierService;

/**
 * Tests for Event Semantic Classifier & Naming Convention Linter.
 *
 * @covers \ZeroBoiler\Analytics\Services\EventSemanticClassifierService
 * @covers \ZeroBoiler\Analytics\Services\EventNamingConventionLinter
 *
 * @since 222.0.0
 */
final class V222SemanticClassifierNamingLinterTest extends \PHPUnit\Framework\TestCase
{
    private CacheRepository $cache;

    private ConfigRepository $config;

    protected function setUp(): void
    {
        $this->cache = Mockery::mock(CacheRepository::class);
        $this->cache->shouldReceive('get')->andReturn(null);
        $this->cache->shouldReceive('put')->andReturn(true);
        $this->cache->shouldReceive('forget')->andReturn(true);

        $this->config = Mockery::mock(ConfigRepository::class);
        $this->config->shouldReceive('get')->andReturnNull();
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    // ── EventSemanticClassifierService ─────────────────────────────────

    public function test_classifier_exact_match_ecommerce(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $result = $classifier->classify('purchase');

        expect($result['event_name'])->toBe('purchase');
        expect($result['category'])->toBe('ecommerce');
        expect($result['confidence'])->toBe(1.0);
        expect($result['method'])->toBe('exact');
        expect($result['catalog_match'])->toBeTrue();
        expect($result['is_custom'])->toBeFalse();
    }

    public function test_classifier_exact_match_saas(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $result = $classifier->classify('sign_up');

        expect($result['event_name'])->toBe('sign_up');
        expect($result['category'])->toBe('saas');
        expect($result['confidence'])->toBe(1.0);
        expect($result['method'])->toBe('exact');
        expect($result['catalog_match'])->toBeTrue();
    }

    public function test_classifier_exact_match_engagement(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $result = $classifier->classify('page_view');

        expect($result['category'])->toBe('engagement');
        expect($result['confidence'])->toBe(1.0);
        expect($result['method'])->toBe('exact');
    }

    public function test_classifier_pattern_match_ecommerce(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $result = $classifier->classify('product_viewed');

        expect($result['category'])->toBe('ecommerce');
        expect($result['method'])->toBe('pattern');
        expect($result['confidence'])->toBeGreaterThan(0.0);
        expect($result['is_custom'])->toBeTrue();
    }

    public function test_classifier_pattern_match_saas(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $result = $classifier->classify('trial_started');

        expect($result['category'])->toBe('saas');
        expect($result['method'])->toBe('pattern');
    }

    public function test_classifier_pattern_match_engagement(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $result = $classifier->classify('interaction_click');

        expect($result['category'])->toBe('engagement');
        expect($result['method'])->toBe('pattern');
    }

    public function test_classifier_payload_based_ecommerce(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $result = $classifier->classify('my_custom_event', [
            'item_id' => 'SKU-123',
            'price' => 29.99,
            'currency' => 'USD',
        ]);

        expect($result['category'])->toBe('ecommerce');
        expect($result['method'])->toBe('payload');
        expect($result['confidence'])->toBeGreaterThan(0.0);
    }

    public function test_classifier_payload_based_saas(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $result = $classifier->classify('custom_action', [
            'plan' => 'pro',
            'subscription_id' => 'sub_123',
        ]);

        expect($result['category'])->toBe('saas');
        expect($result['method'])->toBe('payload');
    }

    public function test_classifier_heuristic_fallback(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $result = $classifier->classify('unknown_random_event_xyz');

        // Should fall to heuristic or unknown
        expect($result['method'])->toBeIn(['heuristic', 'unknown']);
        expect($result['confidence'])->toBeLessThanOrEqual(0.3);
    }

    public function test_classifier_heuristic_ecommerce_verb(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $result = $classifier->classify('purchase_custom');

        expect($result['category'])->toBe('ecommerce');
        expect($result['method'])->toBe('heuristic');
    }

    public function test_classifier_batch(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $results = $classifier->classifyBatch([
            'purchase' => [],
            'sign_up' => [],
            'page_view' => [],
            'unknown_xyz' => [],
        ]);

        expect($results)->toHaveCount(4);
        expect($results['purchase']['category'])->toBe('ecommerce');
        expect($results['sign_up']['category'])->toBe('saas');
        expect($results['page_view']['category'])->toBe('engagement');
    }

    public function test_classifier_classification_report_structure(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $report = $classifier->classificationReport(['purchase', 'sign_up', 'page_view']);

        expect($report)->toHaveKey('total_events');
        expect($report)->toHaveKey('classified');
        expect($report)->toHaveKey('unclassified');
        expect($report)->toHaveKey('by_category');
        expect($report)->toHaveKey('by_method');
        expect($report)->toHaveKey('average_confidence');
        expect($report)->toHaveKey('low_confidence');
        expect($report)->toHaveKey('uncategorized');
        expect($report)->toHaveKey('misnamed');
        expect($report)->toHaveKey('overlap_events');
        expect($report)->toHaveKey('quality_score');

        expect($report['total_events'])->toBe(3);
        expect($report['quality_score'])->toBeGreaterThan(0.0);
    }

    public function test_classifier_quality_score_perfect(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $report = $classifier->classificationReport(['purchase', 'sign_up']);

        // All exact matches → high quality score
        expect($report['quality_score'])->toBeGreaterThan(0.9);
        expect($report['unclassified'])->toBeEmpty();
    }

    public function test_classifier_quality_score_with_unknowns(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $report = $classifier->classificationReport(['purchase', 'xyz_totally_unknown']);

        // 1 unknown out of 2 → lower quality
        expect($report['unclassified'])->toContain('xyz_totally_unknown');
    }

    public function test_classifier_resolve_alias_user_signup(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $alias = $classifier->resolveAlias('user_signup');

        expect($alias)->toBe('sign_up');
    }

    public function test_classifier_resolve_alias_product_view(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $alias = $classifier->resolveAlias('product_viewed');

        expect($alias)->toBe('view_item');
    }

    public function test_classifier_resolve_alias_no_match(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $alias = $classifier->resolveAlias('totally_unknown_xyz');

        expect($alias)->toBeNull();
    }

    public function test_classifier_suggest_category(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $suggestions = $classifier->suggestCategory('product_detail_viewed', [
            'item_id' => 'SKU-123',
        ]);

        expect($suggestions)->not->toBeEmpty();

        $topSuggestion = $suggestions[0];
        expect($topSuggestion)->toHaveKey('category');
        expect($topSuggestion)->toHaveKey('confidence');
        expect($topSuggestion)->toHaveKey('reason');
        expect($topSuggestion['category'])->toBe('ecommerce');
    }

    public function test_classifier_competing_categories(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $result = $classifier->classify('checkout_viewed', [
            'item_id' => 'SKU-123',
            'page_url' => '/checkout',
        ]);

        // This could match both ecommerce (pattern + payload) and engagement (payload)
        // The result should have a best category and possibly competing
        expect($result['category'])->toBeString();
        expect($result['confidence'])->toBeGreaterThan(0.0);
    }

    public function test_classifier_all_catalog_events_classifiable(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $catalogNames = \ZeroBoiler\Analytics\Events\EventCatalog::names();

        // Sample 20 random catalog events
        $sample = array_slice($catalogNames, 0, min(20, count($catalogNames)));

        foreach ($sample as $name) {
            $result = $classifier->classify($name);
            expect($result['catalog_match'])->toBeTrue(
                "Catalog event '{$name}' should have catalog_match=true"
            );
        }
    }

    // ── EventNamingConventionLinter ────────────────────────────────────

    public function test_linter_valid_snake_case(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $violations = $linter->lint('sign_up');

        $formatViolations = array_filter($violations, static fn (array $v): bool => $v['rule'] === 'format');
        expect($formatViolations)->toBeEmpty('sign_up should have no format violations');
    }

    public function test_linter_camel_case_violation(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $violations = $linter->lint('signUp');

        $formatViolations = array_filter($violations, static fn (array $v): bool => $v['rule'] === 'format');
        expect($formatViolations)->not->toBeEmpty('signUp should have a format violation');
    }

    public function test_linter_kebab_case_violation(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $violations = $linter->lint('sign-up');

        $specialCharViolations = array_filter($violations, static fn (array $v): bool => $v['rule'] === 'special_characters');
        expect($specialCharViolations)->not->toBeEmpty('sign-up should have a special_characters violation');
    }

    public function test_linter_reserved_prefix_ga(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $violations = $linter->lint('ga_custom_event');

        $prefixViolations = array_filter($violations, static fn (array $v): bool => $v['rule'] === 'reserved_prefix');
        expect($prefixViolations)->not->toBeEmpty('ga_ prefix should trigger reserved_prefix violation');
    }

    public function test_linter_reserved_prefix_fb(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $violations = $linter->lint('fb_pixel_event');

        $prefixViolations = array_filter($violations, static fn (array $v): bool => $v['rule'] === 'reserved_prefix');
        expect($prefixViolations)->not->toBeEmpty();
    }

    public function test_linter_numeric_suffix_warning(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $violations = $linter->lint('button_click_2');

        $suffixViolations = array_filter($violations, static fn (array $v): bool => $v['rule'] === 'numeric_suffix');
        expect($suffixViolations)->not->toBeEmpty('Numeric suffix should trigger warning');
    }

    public function test_linter_too_short(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $violations = $linter->lint('ab');

        $lengthViolations = array_filter($violations, static fn (array $v): bool => $v['rule'] === 'length');
        expect($lengthViolations)->not->toBeEmpty('Event name "ab" is too short');
    }

    public function test_linter_leading_underscore(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $violations = $linter->lint('_private_event');

        $formatViolations = array_filter($violations, static fn (array $v): bool => $v['rule'] === 'format');
        expect($formatViolations)->not->toBeEmpty('Leading underscore should trigger format violation');
    }

    public function test_linter_valid_purchase_event(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $violations = $linter->lint('purchase');

        $errors = array_filter($violations, static fn (array $v): bool => $v['severity'] === 'error');
        expect($errors)->toBeEmpty('purchase should have no error-level violations');
    }

    public function test_linter_violation_structure(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $violations = $linter->lint('GA_Event');

        foreach ($violations as $v) {
            expect($v)->toHaveKey('rule');
            expect($v)->toHaveKey('severity');
            expect($v)->toHaveKey('message');
            expect($v['severity'])->toBeIn(['error', 'warning', 'info']);
        }
    }

    public function test_linter_lint_report_structure(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $report = $linter->lintReport(['purchase', 'sign_up', 'page_view']);

        expect($report)->toHaveKey('total_events');
        expect($report)->toHaveKey('total_violations');
        expect($report)->toHaveKey('violations_by_severity');
        expect($report)->toHaveKey('violations_by_rule');
        expect($report)->toHaveKey('error_events');
        expect($report)->toHaveKey('warning_events');
        expect($report)->toHaveKey('quality_score');
        expect($report)->toHaveKey('quality_grade');
        expect($report)->toHaveKey('violations');

        expect($report['total_events'])->toBe(3);
        expect($report['quality_score'])->toBeGreaterThanOrEqual(0.0);
        expect($report['quality_score'])->toBeLessThanOrEqual(1.0);
        expect($report['quality_grade'])->toMatch('/^[A-F][+-]?$/');
    }

    public function test_linter_perfect_score_for_valid_events(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $report = $linter->lintReport(['purchase', 'sign_up', 'page_view', 'scroll_depth']);

        expect($report['quality_score'])->toBe(1.0);
        expect($report['quality_grade'])->toBe('A+');
        expect($report['total_violations'])->toBe(0);
    }

    public function test_linter_quality_grade_scale(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $report = $linter->lintReport(['GA_Event', 'fb_click', 'ab']);

        expect($report['quality_grade'])->toBeLessThan('B');
        expect($report['total_violations'])->toBeGreaterThan(0);
    }

    public function test_linter_suggest_name_camel_to_snake(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $suggestions = $linter->suggestName('buttonClick');

        $names = array_column($suggestions, 'suggestion');
        expect($names)->toContain('button_click');
    }

    public function test_linter_suggest_name_kebab_to_snake(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $suggestions = $linter->suggestName('button-click');

        $names = array_column($suggestions, 'suggestion');
        expect($names)->toContain('button_click');
    }

    public function test_linter_suggest_name_numeric_suffix(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $suggestions = $linter->suggestName('click_2');

        $names = array_column($suggestions, 'suggestion');
        expect($names)->toContain('click');
    }

    public function test_linter_suggest_name_alias(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $suggestions = $linter->suggestName('user_signup');

        $names = array_column($suggestions, 'suggestion');
        expect($names)->toContain('sign_up');
    }

    public function test_linter_suggest_name_consecutive_underscores(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $suggestions = $linter->suggestName('button__click');

        $names = array_column($suggestions, 'suggestion');
        expect($names)->toContain('button_click');
    }

    public function test_linter_suggest_name_no_suggestions_for_valid(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $suggestions = $linter->suggestName('purchase');

        // Valid snake_case, no aliases, no numeric suffix
        $names = array_column($suggestions, 'suggestion');
        expect($names)->toBeEmpty();
    }

    public function test_linter_suggestion_structure(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $suggestions = $linter->suggestName('buttonClick');

        foreach ($suggestions as $s) {
            expect($s)->toHaveKey('suggestion');
            expect($s)->toHaveKey('reason');
            expect($s['suggestion'])->toBeString();
            expect($s['reason'])->toBeString();
        }
    }

    public function test_linter_get_rules(): void
    {
        $linter = new EventNamingConventionLinter($this->cache, $this->config);
        $rules = $linter->getRules();

        expect($rules)->toHaveKey('format');
        expect($rules)->toHaveKey('max_length');
        expect($rules)->toHaveKey('min_length');
        expect($rules)->toHaveKey('max_parts');
        expect($rules)->toHaveKey('allow_numeric_suffix');
        expect($rules)->toHaveKey('strict_pattern');
        expect($rules['format'])->toBe('snake_case');
    }

    public function test_linter_custom_format_from_config(): void
    {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.naming_conventions', [])
            ->andReturn(['format' => 'camelCase']);
        $config->shouldReceive('get')
            ->andReturnNull();

        $linter = new EventNamingConventionLinter($this->cache, $config);
        $violations = $linter->lint('buttonClick');

        // camelCase should NOT trigger format violation
        $formatViolations = array_filter($violations, static fn (array $v): bool => $v['rule'] === 'format');
        expect($formatViolations)->toBeEmpty('camelCase should be valid with format=camelCase');
    }

    // ── Integration: Classifier + Linter ───────────────────────────────

    public function test_valid_catalog_event_passes_both(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $linter = new EventNamingConventionLinter($this->cache, $this->config);

        $classification = $classifier->classify('purchase');
        $violations = $linter->lint('purchase');

        expect($classification['catalog_match'])->toBeTrue();
        expect($classification['confidence'])->toBe(1.0);

        $errors = array_filter($violations, static fn (array $v): bool => $v['severity'] === 'error');
        expect($errors)->toBeEmpty();
    }

    public function test_misnamed_event_detected_by_both(): void
    {
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $linter = new EventNamingConventionLinter($this->cache, $this->config);

        // 'user_signup' is an alias for 'sign_up'
        $alias = $classifier->resolveAlias('user_signup');
        expect($alias)->toBe('sign_up');

        // 'user_signup' itself has no naming violations (valid snake_case)
        $violations = $linter->lint('user_signup');
        $errors = array_filter($violations, static fn (array $v): bool => $v['severity'] === 'error');
        expect($errors)->toBeEmpty();
    }

    public function test_version_constant_exists(): void
    {
        expect(AnalyticsEvent::VERSION)->toBeString();
        expect(AnalyticsEvent::VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
    }
}
