<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AnalyticsDataQualityScorer;
use ZeroBoiler\Analytics\Services\EventClassificationService;

describe('AnalyticsDataQualityScorer', function (): void {
    beforeEach(function (): void {
        $this->scorer = new AnalyticsDataQualityScorer;
    });

    it('scores a well-formed catalog event highly', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99, 'currency' => 'USD'],
            clientId: 'client_abc',
            userId: 'user_123',
        );

        $result = $this->scorer->score($event);

        expect($result['score'])->toBeGreaterThanOrEqual(80.0);
        expect($result['grade'])->toBeOneOf(['A', 'B']);
        expect($result['dimensions'])->toHaveKey('schema_compliance');
        expect($result['dimensions'])->toHaveKey('provider_coverage');
        expect($result['dimensions'])->toHaveKey('payload_health');
        expect($result['dimensions'])->toHaveKey('naming_convention');
        expect($result['dimensions'])->toHaveKey('identity_completeness');
        expect($result['dimensions'])->toHaveKey('timestamp_accuracy');
        expect($result['recommendations'])->toBeList();
    });

    it('penalizes events without identity context', function (): void {
        $event = new AnalyticsEvent(name: 'page_view', params: []);

        $result = $this->scorer->score($event);
        $identityDim = $result['dimensions']['identity_completeness'];

        expect($identityDim['score'])->toBeLessThan(100.0);
        expect($identityDim['issues'])->not->toBeEmpty();
    });

    it('penalizes events with bad naming convention', function (): void {
        $event = new AnalyticsEvent(name: 'MyBadEvent!', params: []);

        $result = $this->scorer->score($event);
        $namingDim = $result['dimensions']['naming_convention'];

        expect($namingDim['score'])->toBeLessThan(100.0);
        expect($namingDim['issues'])->toContain("Event name 'MyBadEvent!' does not follow snake_case convention");
    });

    it('penalizes oversized payloads', function (): void {
        $largeParams = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeParams["param_{$i}"] = str_repeat('x', 100);
        }

        $event = new AnalyticsEvent(name: 'custom_event', params: $largeParams);
        $result = $this->scorer->score($event);

        $payloadDim = $result['dimensions']['payload_health'];
        expect($payloadDim['score'])->toBeLessThan(100.0);
        expect($payloadDim['issues'])->not->toBeEmpty();
    });

    it('penalizes non-catalog events', function (): void {
        $event = new AnalyticsEvent(name: 'custom_event_xyz', params: []);

        $result = $this->scorer->score($event);
        $schemaDim = $result['dimensions']['schema_compliance'];

        expect($schemaDim['score'])->toBeLessThan(100.0);
        expect($schemaDim['issues'])->toContain("Event 'custom_event_xyz' is not registered in the catalog");
    });

    it('batch scores multiple events with aggregate stats', function (): void {
        $events = [
            new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99], clientId: 'c1', userId: 'u1'),
            new AnalyticsEvent(name: 'page_view', params: []),
            new AnalyticsEvent(name: 'sign_up', params: ['method' => 'email'], clientId: 'c2'),
        ];

        $result = $this->scorer->scoreBatch($events);

        expect($result['total_events'])->toBe(3);
        expect($result['average_score'])->toBeGreaterThan(0.0);
        expect($result['min_score'])->toBeLessThanOrEqual($result['max_score']);
        expect($result['grade_distribution'])->toHaveKey('A');
        expect($result['dimension_averages'])->toHaveKey('schema_compliance');
        expect($result['dimension_averages'])->toHaveKey('identity_completeness');
    });

    it('returns zeros for empty batch', function (): void {
        $result = $this->scorer->scoreBatch([]);

        expect($result['total_events'])->toBe(0);
        expect($result['average_score'])->toBe(0.0);
        expect($result['grade_distribution'])->toBeEmpty();
    });

    it('computes catalog-level quality score', function (): void {
        $result = $this->scorer->catalogScore();

        expect($result['catalog_score'])->toBeGreaterThan(0.0);
        expect($result['grade'])->toBeString();
        expect($result['total_events'])->toBeGreaterThan(0);
        expect($result['dimensions'])->toHaveKey('naming_convention');
    });

    it('scores each dimension with weight and max_score', function (): void {
        $event = new AnalyticsEvent(name: 'click', params: ['button' => 'submit']);
        $result = $this->scorer->score($event);

        foreach ($result['dimensions'] as $name => $dim) {
            expect($dim)->toHaveKey('score');
            expect($dim)->toHaveKey('weight');
            expect($dim)->toHaveKey('issues');
            expect($dim)->toHaveKey('max_score');
            expect($dim['weight'])->toBeGreaterThan(0);
            expect($dim['max_score'])->toBe(100.0);
            expect($dim['score'])->toBeGreaterThanOrEqual(0.0);
            expect($dim['score'])->toBeLessThanOrEqual(100.0);
        }
    });

    it('generates grade A through F', function (): void {
        // High quality event
        $good = new AnalyticsEvent(name: 'purchase', params: ['value' => 100], clientId: 'c', userId: 'u');
        $goodResult = $this->scorer->score($good);
        expect(in_array($goodResult['grade'], ['A', 'B', 'C', 'D', 'F'], true))->toBeTrue();

        // Bad event
        $bad = new AnalyticsEvent(name: 'BAD-NAME!!!', params: []);
        $badResult = $this->scorer->score($bad);
        expect(in_array($badResult['grade'], ['A', 'B', 'C', 'D', 'F'], true))->toBeTrue();
    });
});

describe('EventClassificationService', function (): void {
    beforeEach(function (): void {
        $this->classifier = new EventClassificationService;
    });

    it('classifies purchase event as transaction/conversion', function (): void {
        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99, 'transaction_id' => 'tx_123']);
        $result = $this->classifier->classify($event);

        expect($result['primary_category'])->toBeOneOf(['transaction', 'conversion']);
        expect($result['confidence'])->toBeGreaterThan(0.0);
        expect($result['tags'])->toContain('has_monetary_value');
    });

    it('classifies login event as identity', function (): void {
        $event = new AnalyticsEvent(name: 'login', params: ['auth_method' => 'email']);
        $result = $this->classifier->classify($event);

        expect($result['primary_category'])->toBe('identity');
        expect($result['confidence'])->toBeGreaterThan(0.0);
        expect($result['categories'])->toHaveKey('identity');
    });

    it('classifies error event as error category', function (): void {
        $event = new AnalyticsEvent(name: 'error', params: ['error_message' => 'Something broke', 'error_code' => 500]);
        $result = $this->classifier->classify($event);

        expect($result['primary_category'])->toBe('error');
        expect($result['tags'])->toContain('source:unknown');
    });

    it('classifies search event as search category', function (): void {
        $event = new AnalyticsEvent(name: 'search', params: ['search_term' => 'analytics']);
        $result = $this->classifier->classify($event);

        expect($result['primary_category'])->toBe('search');
    });

    it('applies catalog membership tags', function (): void {
        $catalogEvent = new AnalyticsEvent(name: 'purchase', params: []);
        $result = $this->classifier->classify($catalogEvent);

        expect($result['tags'])->toContain('catalog:registered');
        expect($result['tags'])->toContain('catalog:ecommerce');
    });

    it('tags custom events as catalog:custom', function (): void {
        $customEvent = new AnalyticsEvent(name: 'my_custom_event', params: []);
        $result = $this->classifier->classify($customEvent);

        expect($result['tags'])->toContain('catalog:custom');
    });

    it('applies identity tags', function (): void {
        $event = new AnalyticsEvent(name: 'page_view', params: [], clientId: 'c123', userId: 'u456');
        $result = $this->classifier->classify($event);

        expect($result['tags'])->toContain('has_user_id');
        expect($result['tags'])->toContain('has_client_id');
    });

    it('applies has_revenue tag when revenue param exists', function (): void {
        $event = new AnalyticsEvent(name: 'purchase', params: ['revenue' => 199.99]);
        $result = $this->classifier->classify($event);

        expect($result['tags'])->toContain('has_revenue');
    });

    it('applies source tag from event source', function (): void {
        $event = new AnalyticsEvent(name: 'click', params: [], source: 'client');
        $result = $this->classifier->classify($event);

        expect($result['tags'])->toContain('source:client');
    });

    it('includes metadata in classification result', function (): void {
        $event = new AnalyticsEvent(name: 'click', params: ['button' => 'submit']);
        $result = $this->classifier->classify($event);

        expect($result['metadata'])->toHaveKey('event_name');
        expect($result['metadata'])->toHaveKey('param_count');
        expect($result['metadata'])->toHaveKey('in_catalog');
        expect($result['metadata'])->toHaveKey('classified_at');
        expect($result['metadata'])->toHaveKey('classifier_version');
        expect($result['metadata'])->toHaveKey('is_high_confidence');
    });

    it('batch classifies with distribution stats', function (): void {
        $events = [
            new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]),
            new AnalyticsEvent(name: 'login', params: []),
            new AnalyticsEvent(name: 'page_view', params: []),
            new AnalyticsEvent(name: 'search', params: ['search_term' => 'test']),
            new AnalyticsEvent(name: 'error', params: []),
        ];

        $result = $this->classifier->classifyBatch($events);

        expect($result['total_events'])->toBe(5);
        expect($result['category_distribution'])->not->toBeEmpty();
        expect($result['tag_frequency'])->not->toBeEmpty();
        expect($result['avg_confidence'])->toBeGreaterThan(0.0);
    });

    it('returns empty batch result for no events', function (): void {
        $result = $this->classifier->classifyBatch([]);

        expect($result['total_events'])->toBe(0);
        expect($result['category_distribution'])->toBeEmpty();
        expect($result['avg_confidence'])->toBe(0.0);
    });

    it('returns all category definitions', function (): void {
        $categories = $this->classifier->categories();

        expect($categories)->toHaveKey('conversion');
        expect($categories)->toHaveKey('intent');
        expect($categories)->toHaveKey('engagement');
        expect($categories)->toHaveKey('navigation');
        expect($categories)->toHaveKey('transaction');
        expect($categories)->toHaveKey('identity');
        expect($categories)->toHaveKey('error');
        expect($categories)->toHaveKey('search');

        foreach ($categories as $name => $def) {
            expect($def)->toHaveKey('description');
            expect($def)->toHaveKey('example_events');
            expect($def)->toHaveKey('example_params');
        }
    });

    it('provides per-category confidence breakdown', function (): void {
        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99, 'transaction_id' => 'tx_1']);
        $result = $this->classifier->categoryConfidence($event, 'transaction');

        expect($result['category'])->toBe('transaction');
        expect($result['confidence'])->toBeGreaterThan(0.0);
        expect($result['match_source'])->toBeString();
        expect($result['matched_patterns'])->toBeList();
    });

    it('zero confidence for unrelated category', function (): void {
        $event = new AnalyticsEvent(name: 'login', params: []);
        $result = $this->classifier->categoryConfidence($event, 'transaction');

        expect($result['confidence'])->toBe(0.0);
        expect($result['matched_patterns'])->toBeEmpty();
    });

    it('extracts tags independently', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 50],
            clientId: 'c1',
            userId: 'u1',
            source: 'server',
        );

        $tags = $this->classifier->extractTags($event, ['conversion' => 0.8, 'transaction' => 0.6]);

        expect($tags)->toContain('cat:conversion');
        expect($tags)->toContain('cat:transaction');
        expect($tags)->toContain('source:server');
        expect($tags)->toContain('has_user_id');
        expect($tags)->toContain('has_client_id');
        expect($tags)->toContain('has_monetary_value');
    });
});
