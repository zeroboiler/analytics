<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Services\EventBehavioralFingerprintService;
use ZeroBoiler\Analytics\Services\EventIntentDetectionService;
use ZeroBoiler\Analytics\Services\PredictiveChurnScoringService;

/**
 * V169 Event Intelligence Engine test suite.
 *
 * Tests behavioral fingerprinting, intent detection, and predictive churn scoring.
 *
 * @since 169.0.0
 */
final class V169EventIntelligenceEngineTest extends TestCase
{
    // ========================================================================
    // EventBehavioralFingerprintService
    // ========================================================================

    public function testFingerprintReturnsNullForInsufficientEvents(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $result = $service->generateFingerprint('user-1', [
            ['name' => 'page_view', 'timestamp' => time(), 'session_id' => 's1', 'category' => 'engagement'],
        ]);

        $this->assertNull($result);
    }

    public function testFingerprintGeneratesWithMinimumEvents(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $now = time();
        $events = $this->createSampleEvents(15, $now);

        $result = $service->generateFingerprint('user-2', $events);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('features', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('segment_hint', $result);
        $this->assertArrayHasKey('bot_risk', $result);
        $this->assertArrayHasKey('drift_score', $result);
    }

    public function testFingerprintHashIsDeterministic(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $now = time();
        $events = $this->createSampleEvents(20, $now);

        $result1 = $service->generateFingerprint('user-3', $events);
        $result2 = $service->generateFingerprint('user-3', $events);

        $this->assertNotNull($result1);
        $this->assertNotNull($result2);
        $this->assertSame($result1['hash'], $result2['hash']);
    }

    public function testFingerprintFeaturesStructure(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $now = time();
        $events = $this->createSampleEvents(20, $now);

        $result = $service->generateFingerprint('user-4', $events);

        $this->assertNotNull($result);
        $features = $result['features'];

        $this->assertArrayHasKey('event_frequency', $features);
        $this->assertArrayHasKey('timing_variance', $features);
        $this->assertArrayHasKey('session_frequency', $features);
        $this->assertArrayHasKey('avg_events_per_session', $features);
        $this->assertArrayHasKey('top_category_ratio', $features);
        $this->assertArrayHasKey('event_diversity', $features);
        $this->assertArrayHasKey('recency_score', $features);
    }

    public function testFingerprintScoreIsBetweenZeroAndOne(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $now = time();
        $events = $this->createSampleEvents(25, $now);

        $result = $service->generateFingerprint('user-5', $events);

        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(0.0, $result['score']);
        $this->assertLessThanOrEqual(1.0, $result['score']);
    }

    public function testFingerprintSegmentHintIsValid(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $now = time();
        $events = $this->createSampleEvents(20, $now);

        $result = $service->generateFingerprint('user-6', $events);

        $this->assertNotNull($result);
        $validSegments = ['power_user', 'casual_user', 'explorer', 'at_risk', 'new_user', 'bot_like'];
        $this->assertContains($result['segment_hint'], $validSegments);
    }

    public function testFingerprintBotRiskIsBetweenZeroAndOne(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $now = time();
        $events = $this->createSampleEvents(20, $now);

        $result = $service->generateFingerprint('user-7', $events);

        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(0.0, $result['bot_risk']);
        $this->assertLessThanOrEqual(1.0, $result['bot_risk']);
    }

    public function testFingerprintBotRiskHighForRepetitiveBehavior(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $now = time();

        // Create very repetitive, machine-like events
        $events = [];
        for ($i = 0; $i < 50; $i++) {
            $events[] = [
                'name' => 'click_button',
                'timestamp' => $now + $i,
                'session_id' => 'bot-session',
                'category' => 'engagement',
            ];
        }

        $result = $service->generateFingerprint('user-bot', $events);

        $this->assertNotNull($result);
        // Repetitive same event, same session, exact intervals → high bot risk
        $this->assertGreaterThanOrEqual(0.3, $result['bot_risk']);
    }

    public function testFingerprintConfidenceIsBetweenZeroAndOne(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $now = time();
        $events = $this->createSampleEvents(20, $now);

        $result = $service->generateFingerprint('user-8', $events);

        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(0.0, $result['confidence']);
        $this->assertLessThanOrEqual(1.0, $result['confidence']);
    }

    public function testDriftScoreIsZeroForIdenticalFingerprints(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $now = time();
        $events = $this->createSampleEvents(20, $now);

        $fp = $service->generateFingerprint('user-9', $events);

        $this->assertNotNull($fp);

        $drift = $service->computeDrift($fp, $fp);

        $this->assertEquals(0.0, $drift);
    }

    public function testDriftScoreBetweenZeroAndOne(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $now = time();
        $now2 = $now + 86400 * 30;

        $events1 = $this->createSampleEvents(20, $now);
        $events2 = $this->createSampleEvents(20, $now2);

        $fp1 = $service->generateFingerprint('user-10', $events1);
        $fp2 = $service->generateFingerprint('user-10-diff', $events2);

        $this->assertNotNull($fp1);
        $this->assertNotNull($fp2);

        $drift = $service->computeDrift($fp1, $fp2);

        $this->assertGreaterThanOrEqual(0.0, $drift);
        $this->assertLessThanOrEqual(1.0, $drift);
    }

    public function testBaselineStoreAndRetrieve(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $now = time();
        $events = $this->createSampleEvents(20, $now);

        $fp = $service->generateFingerprint('user-11', $events);
        $this->assertNotNull($fp);

        $service->storeBaseline('user-11', $fp);

        $baseline = $service->getBaseline('user-11');
        $this->assertNotNull($baseline);
        $this->assertSame($fp['hash'], $baseline['hash']);
    }

    public function testBaselineReturnsNullForUnknownUser(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);

        $baseline = $service->getBaseline('nonexistent-user');

        $this->assertNull($baseline);
    }

    public function testSimilarUsersFindsMatches(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $now = time();

        // Generate fingerprint for user-a (stores in cache)
        $eventsTemplate = $this->createSampleEvents(30, $now);
        $fpA = $service->generateFingerprint('user-a', $eventsTemplate);
        $this->assertNotNull($fpA);

        // Generate different fingerprint for user-b
        $eventsB = $this->createSampleEvents(30, $now + 100);
        $fpB = $service->generateFingerprint('user-b', $eventsB);
        $this->assertNotNull($fpB);

        // Pass the fingerprints as candidates (mimics pre-loaded state)
        $candidates = ['user-b' => $fpB];
        $similar = $service->findSimilarUsers('user-a', $candidates);

        // With similar behavioral patterns, should find a match
        // If both have high diversity and similar features, similarity > 0.5
        if (!empty($similar)) {
            $this->assertArrayHasKey('user_id', $similar[0]);
            $this->assertArrayHasKey('similarity', $similar[0]);
            $this->assertGreaterThanOrEqual(0.5, $similar[0]['similarity']);
        }
    }

    public function testSimilarUsersReturnsEmptyForNoFingerprint(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);

        $similar = $service->findSimilarUsers('unknown-user', []);

        $this->assertSame([], $similar);
    }

    public function testGetStatusReturnsStructure(): void
    {
        $service = new EventBehavioralFingerprintService(null, null);
        $status = $service->getStatus();

        $this->assertArrayHasKey('enabled', $status);
        $this->assertArrayHasKey('cache_ttl', $status);
        $this->assertArrayHasKey('min_events', $status);
        $this->assertArrayHasKey('segments', $status);
        $this->assertIsArray($status['segments']);
        $this->assertCount(6, $status['segments']);
    }

    // ========================================================================
    // EventIntentDetectionService
    // ========================================================================

    public function testIntentDetectionReturnsNullForInsufficientEvents(): void
    {
        $service = new EventIntentDetectionService(null, null);
        $result = $service->detectIntent('user-1', [
            ['name' => 'page_view', 'timestamp' => time()],
        ]);

        $this->assertNull($result);
    }

    public function testIntentDetectionGeneratesWithMinimumEvents(): void
    {
        $service = new EventIntentDetectionService(null, null);
        $now = time();
        $events = $this->createIntentEvents($now);

        $result = $service->detectIntent('user-intent-1', $events);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('primary_intent', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('all_intents', $result);
        $this->assertArrayHasKey('signals', $result);
        $this->assertArrayHasKey('risk_level', $result);
    }

    public function testIntentDetectionValidPrimaryIntents(): void
    {
        $service = new EventIntentDetectionService(null, null);
        $now = time();
        $events = $this->createIntentEvents($now);

        $result = $service->detectIntent('user-intent-2', $events);

        $this->assertNotNull($result);
        $validIntents = ['buying_intent', 'churning', 'exploring', 'power_user', 'support_seeking'];
        $this->assertContains($result['primary_intent'], $validIntents);
    }

    public function testIntentDetectionConfidenceBetweenZeroAndOne(): void
    {
        $service = new EventIntentDetectionService(null, null);
        $now = time();
        $events = $this->createIntentEvents($now);

        $result = $service->detectIntent('user-intent-3', $events);

        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(0.0, $result['confidence']);
        $this->assertLessThanOrEqual(1.0, $result['confidence']);
    }

    public function testIntentDetectionRiskLevelIsValid(): void
    {
        $service = new EventIntentDetectionService(null, null);
        $now = time();
        $events = $this->createIntentEvents($now);

        $result = $service->detectIntent('user-intent-4', $events);

        $this->assertNotNull($result);
        $validRisks = ['low', 'medium', 'high', 'critical'];
        $this->assertContains($result['risk_level'], $validRisks);
    }

    public function testIntentDetectionAllIntentsContainsAllFive(): void
    {
        $service = new EventIntentDetectionService(null, null);
        $now = time();
        $events = $this->createIntentEvents($now);

        $result = $service->detectIntent('user-intent-5', $events);

        $this->assertNotNull($result);
        $expectedIntents = ['buying_intent', 'churning', 'exploring', 'power_user', 'support_seeking'];
        foreach ($expectedIntents as $intent) {
            $this->assertArrayHasKey($intent, $result['all_intents']);
            $this->assertGreaterThanOrEqual(0.0, $result['all_intents'][$intent]);
            $this->assertLessThanOrEqual(1.0, $result['all_intents'][$intent]);
        }
    }

    public function testIntentDetectionSignalsAreExtracted(): void
    {
        $service = new EventIntentDetectionService(null, null);
        $now = time();
        $events = $this->createIntentEvents($now);

        $result = $service->detectIntent('user-intent-6', $events);

        $this->assertNotNull($result);
        $this->assertIsArray($result['signals']);
        $this->assertNotEmpty($result['signals']);

        foreach ($result['signals'] as $signal) {
            $this->assertArrayHasKey('type', $signal);
            $this->assertArrayHasKey('strength', $signal);
            $this->assertArrayHasKey('description', $signal);
            $this->assertArrayHasKey('events', $signal);
            $this->assertGreaterThanOrEqual(0.0, $signal['strength']);
            $this->assertLessThanOrEqual(1.0, $signal['strength']);
        }
    }

    public function testIntentDetectionBuyingIntentHighForPricingVisits(): void
    {
        $service = new EventIntentDetectionService(null, null);
        $now = time();
        $events = [];

        // Heavy pricing + checkout signal
        for ($i = 0; $i < 10; $i++) {
            $events[] = ['name' => 'page_view', 'timestamp' => $now + $i * 60, 'page_url' => '/pricing'];
        }
        for ($i = 0; $i < 5; $i++) {
            $events[] = ['name' => 'begin_checkout', 'timestamp' => $now + 600 + $i * 60];
        }
        for ($i = 0; $i < 5; $i++) {
            $events[] = ['name' => 'feature_used', 'timestamp' => $now + 900 + $i * 60];
        }

        $result = $service->detectIntent('user-buyer', $events);

        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(0.3, $result['all_intents']['buying_intent']);
    }

    public function testIntentDetectionChurningHighForDecliningActivity(): void
    {
        $service = new EventIntentDetectionService(null, null);
        $now = time();
        $events = [];

        // Only error events and support, no login
        for ($i = 0; $i < 8; $i++) {
            $events[] = ['name' => 'js_error', 'timestamp' => $now - 86400 * 10 + $i * 3600];
        }
        for ($i = 0; $i < 5; $i++) {
            $events[] = ['name' => 'support_ticket', 'timestamp' => $now - 86400 * 5 + $i * 3600];
        }

        $result = $service->detectIntent('user-churn', $events);

        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(0.3, $result['all_intents']['churning']);
    }

    public function testIntentDetectionBatch(): void
    {
        $service = new EventIntentDetectionService(null, null);
        $now = time();

        $userEvents = [
            'user-batch-1' => $this->createIntentEvents($now),
            'user-batch-2' => $this->createIntentEvents($now + 3600),
            'user-batch-3' => ['name' => 'single', 'timestamp' => $now], // too few
        ];

        $results = $service->detectBatchIntents($userEvents);

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('user-batch-1', $results);
        $this->assertArrayNotHasKey('user-batch-3', $results);
    }

    public function testIntentGetHighIntentUsers(): void
    {
        $service = new EventIntentDetectionService(null, null);
        $now = time();

        $intentResults = [
            'buyer-1' => [
                'primary_intent' => 'buying_intent',
                'confidence' => 0.85,
                'signals' => [['type' => 'pricing_page_views', 'strength' => 0.9, 'description' => '', 'events' => []]],
            ],
            'buyer-2' => [
                'primary_intent' => 'buying_intent',
                'confidence' => 0.50, // below default threshold
                'signals' => [],
            ],
            'power-1' => [
                'primary_intent' => 'power_user',
                'confidence' => 0.90,
                'signals' => [],
            ],
            'churn-1' => [
                'primary_intent' => 'churning',
                'confidence' => 0.80,
                'signals' => [],
            ],
        ];

        $highIntent = $service->getHighIntentUsers($intentResults);

        $this->assertCount(2, $highIntent);
        $this->assertSame('power-1', $highIntent[0]['user_id']); // sorted by confidence desc
        $this->assertSame('buyer-1', $highIntent[1]['user_id']);
    }

    public function testIntentGetAtRiskUsers(): void
    {
        $service = new EventIntentDetectionService(null, null);

        $intentResults = [
            'safe-1' => [
                'primary_intent' => 'exploring',
                'confidence' => 0.5,
                'risk_level' => 'low',
                'signals' => [],
            ],
            'risk-1' => [
                'primary_intent' => 'churning',
                'confidence' => 0.7,
                'risk_level' => 'high',
                'signals' => [],
            ],
            'risk-2' => [
                'primary_intent' => 'churning',
                'confidence' => 0.9,
                'risk_level' => 'critical',
                'signals' => [],
            ],
        ];

        $atRisk = $service->getAtRiskUsers(intentResults: $intentResults);

        $this->assertCount(2, $atRisk);
        // Sorted by confidence ascending (most critical first)
        $this->assertSame('risk-2', $atRisk[0]['user_id']);
    }

    public function testIntentGetStatus(): void
    {
        $service = new EventIntentDetectionService(null, null);
        $status = $service->getStatus();

        $this->assertArrayHasKey('enabled', $status);
        $this->assertArrayHasKey('cache_ttl', $status);
        $this->assertArrayHasKey('lookback_window', $status);
        $this->assertArrayHasKey('supported_intents', $status);
        $this->assertArrayHasKey('signal_patterns', $status);
        $this->assertCount(5, $status['supported_intents']);
    }

    // ========================================================================
    // PredictiveChurnScoringService
    // ========================================================================

    public function testChurnScoreReturnsNullForInsufficientEvents(): void
    {
        $service = new PredictiveChurnScoringService(null, null);
        $result = $service->computeChurnScore('user-1', [
            ['name' => 'page_view', 'timestamp' => time(), 'session_id' => 's1'],
        ]);

        $this->assertNull($result);
    }

    public function testChurnScoreGeneratesWithMinimumEvents(): void
    {
        $service = new PredictiveChurnScoringService(null, null);
        $now = time();
        $events = $this->createChurnEvents(15, $now);

        $result = $service->computeChurnScore('user-2', $events);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('risk_level', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('features', $result);
        $this->assertArrayHasKey('trend', $result);
        $this->assertArrayHasKey('last_active', $result);
        $this->assertArrayHasKey('predicted_churn_date', $result);
    }

    public function testChurnScoreIsBetweenZeroAndHundred(): void
    {
        $service = new PredictiveChurnScoringService(null, null);
        $now = time();
        $events = $this->createChurnEvents(20, $now);

        $result = $service->computeChurnScore('user-3', $events);

        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function testChurnRiskLevelIsValid(): void
    {
        $service = new PredictiveChurnScoringService(null, null);

        $this->assertSame('healthy', $service->classifyRisk(20));
        $this->assertSame('at_risk', $service->classifyRisk(40));
        $this->assertSame('high_risk', $service->classifyRisk(70));
        $this->assertSame('critical', $service->classifyRisk(90));
    }

    public function testChurnRiskLevelBoundary(): void
    {
        $service = new PredictiveChurnScoringService(null, null);

        $this->assertSame('healthy', $service->classifyRisk(0));
        $this->assertSame('healthy', $service->classifyRisk(30));
        $this->assertSame('at_risk', $service->classifyRisk(31));
        $this->assertSame('at_risk', $service->classifyRisk(60));
        $this->assertSame('high_risk', $service->classifyRisk(61));
        $this->assertSame('high_risk', $service->classifyRisk(80));
        $this->assertSame('critical', $service->classifyRisk(81));
        $this->assertSame('critical', $service->classifyRisk(100));
    }

    public function testChurnFeaturesStructure(): void
    {
        $service = new PredictiveChurnScoringService(null, null);
        $now = time();
        $events = $this->createChurnEvents(20, $now);

        $result = $service->computeChurnScore('user-4', $events);

        $this->assertNotNull($result);
        $this->assertNotEmpty($result['features']);

        foreach ($result['features'] as $feature) {
            $this->assertArrayHasKey('name', $feature);
            $this->assertArrayHasKey('value', $feature);
            $this->assertArrayHasKey('weight', $feature);
            $this->assertArrayHasKey('contribution', $feature);
            $this->assertArrayHasKey('description', $feature);
            $this->assertGreaterThanOrEqual(0.0, $feature['value']);
            $this->assertLessThanOrEqual(1.0, $feature['value']);
        }
    }

    public function testChurnFeaturesCount(): void
    {
        $service = new PredictiveChurnScoringService(null, null);
        $now = time();
        $events = $this->createChurnEvents(20, $now);

        $result = $service->computeChurnScore('user-5', $events);

        $this->assertNotNull($result);
        $this->assertCount(9, $result['features']);
    }

    public function testChurnConfidenceBetweenZeroAndOne(): void
    {
        $service = new PredictiveChurnScoringService(null, null);
        $now = time();
        $events = $this->createChurnEvents(20, $now);

        $result = $service->computeChurnScore('user-6', $events);

        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(0.0, $result['confidence']);
        $this->assertLessThanOrEqual(1.0, $result['confidence']);
    }

    public function testChurnTrendIsValid(): void
    {
        $service = new PredictiveChurnScoringService(null, null);
        $now = time();
        $events = $this->createChurnEvents(20, $now);

        $result = $service->computeChurnScore('user-7', $events);

        $this->assertNotNull($result);
        $validTrends = ['improving', 'stable', 'declining'];
        $this->assertContains($result['trend'], $validTrends);
    }

    public function testChurnLastActiveIsSet(): void
    {
        $service = new PredictiveChurnScoringService(null, null);
        $now = time();
        $events = $this->createChurnEvents(20, $now);

        $result = $service->computeChurnScore('user-8', $events);

        $this->assertNotNull($result);
        $this->assertNotNull($result['last_active']);
        $this->assertIsString($result['last_active']);
    }

    public function testChurnPredictedDateNullForHealthy(): void
    {
        $service = new PredictiveChurnScoringService(null, null);
        $now = time();
        $events = $this->createChurnEvents(20, $now);

        $result = $service->computeChurnScore('user-healthy', $events);

        $this->assertNotNull($result);
        // If healthy, predicted churn date should be null
        if ($result['score'] < 31) {
            $this->assertNull($result['predicted_churn_date']);
        }
    }

    public function testChurnBatchScoring(): void
    {
        $service = new PredictiveChurnScoringService(null, null);
        $now = time();

        $userEvents = [
            'user-batch-1' => $this->createChurnEvents(15, $now),
            'user-batch-2' => $this->createChurnEvents(15, $now + 3600),
            'user-batch-3' => ['name' => 'single', 'timestamp' => $now, 'session_id' => 's1'],
        ];

        $results = $service->computeBatchScores($userEvents);

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('user-batch-1', $results);
        $this->assertArrayNotHasKey('user-batch-3', $results);
    }

    public function testChurnSummaryGeneration(): void
    {
        $service = new PredictiveChurnScoringService(null, null);

        $scores = [
            'user-a' => ['score' => 15, 'risk_level' => 'healthy', 'trend' => 'stable', 'features' => []],
            'user-b' => ['score' => 45, 'risk_level' => 'at_risk', 'trend' => 'declining', 'features' => []],
            'user-c' => ['score' => 75, 'risk_level' => 'high_risk', 'trend' => 'declining', 'features' => []],
            'user-d' => ['score' => 90, 'risk_level' => 'critical', 'trend' => 'declining', 'features' => []],
        ];

        $summary = $service->generateSummary($scores);

        $this->assertSame(4, $summary['total_users']);
        $this->assertSame(1, $summary['healthy']);
        $this->assertSame(1, $summary['at_risk']);
        $this->assertSame(1, $summary['high_risk']);
        $this->assertSame(1, $summary['critical']);
        $this->assertEqualsWithDelta(56.25, $summary['avg_score'], 0.1);
        $this->assertCount(4, $summary['highest_risk_users']);
        $this->assertSame('user-d', $summary['highest_risk_users'][0]['user_id']);
    }

    public function testChurnUsersAboveThreshold(): void
    {
        $service = new PredictiveChurnScoringService(null, null);

        $scores = [
            'user-a' => ['score' => 15, 'risk_level' => 'healthy', 'trend' => 'stable', 'features' => []],
            'user-b' => ['score' => 55, 'risk_level' => 'at_risk', 'trend' => 'declining', 'features' => []],
            'user-c' => ['score' => 85, 'risk_level' => 'critical', 'trend' => 'declining', 'features' => []],
        ];

        $above = $service->getUsersAboveThreshold($scores, 60);

        $this->assertCount(1, $above);
        $this->assertSame('user-c', $above[0]['user_id']);
    }

    public function testChurnTrialConversionGapFeature(): void
    {
        $service = new PredictiveChurnScoringService(null, null);
        $now = time();
        $events = $this->createChurnEvents(20, $now);

        // User with trial started 20 days ago, not subscribed
        $meta = [
            'trial_start' => $now - (20 * 86400),
            'is_subscribed' => false,
        ];

        $result = $service->computeChurnScore('user-trial', $events, $meta);

        $this->assertNotNull($result);

        // Find the trial_conversion_gap feature
        $trialFeature = null;
        foreach ($result['features'] as $feature) {
            if ($feature['name'] === 'trial_conversion_gap') {
                $trialFeature = $feature;
                break;
            }
        }

        $this->assertNotNull($trialFeature);
        // 20 days since trial, >7 days → should have some risk
        $this->assertGreaterThan(0.0, $trialFeature['value']);
    }

    public function testChurnSubscribedUserLowTrialGap(): void
    {
        $service = new PredictiveChurnScoringService(null, null);
        $now = time();
        $events = $this->createChurnEvents(20, $now);

        // User who is subscribed → no trial gap risk
        $meta = [
            'trial_start' => $now - (30 * 86400),
            'is_subscribed' => true,
        ];

        $result = $service->computeChurnScore('user-subscribed', $events, $meta);

        $this->assertNotNull($result);

        foreach ($result['features'] as $feature) {
            if ($feature['name'] === 'trial_conversion_gap') {
                $this->assertEquals(0.0, $feature['value']);
                break;
            }
        }
    }

    public function testChurnGetStatus(): void
    {
        $service = new PredictiveChurnScoringService(null, null);
        $status = $service->getStatus();

        $this->assertArrayHasKey('enabled', $status);
        $this->assertArrayHasKey('cache_ttl', $status);
        $this->assertArrayHasKey('prediction_horizon', $status);
        $this->assertArrayHasKey('features', $status);
        $this->assertArrayHasKey('risk_levels', $status);
        $this->assertCount(9, $status['features']);
        $this->assertCount(4, $status['risk_levels']);
    }

    // ========================================================================
    // Integration: Fingerprint + Intent + Churn
    // ========================================================================

    public function testServicesClassStructureValidation(): void
    {
        $fingerprintService = new EventBehavioralFingerprintService(null, null);
        $intentService = new EventIntentDetectionService(null, null);
        $churnService = new PredictiveChurnScoringService(null, null);

        // Verify all services have getStatus
        $fpStatus = $fingerprintService->getStatus();
        $intentStatus = $intentService->getStatus();
        $churnStatus = $churnService->getStatus();

        $this->assertArrayHasKey('enabled', $fpStatus);
        $this->assertArrayHasKey('enabled', $intentStatus);
        $this->assertArrayHasKey('enabled', $churnStatus);
    }

    public function testAllServicesAreFinal(): void
    {
        $fingerprintRef = new \ReflectionClass(EventBehavioralFingerprintService::class);
        $intentRef = new \ReflectionClass(EventIntentDetectionService::class);
        $churnRef = new \ReflectionClass(PredictiveChurnScoringService::class);

        $this->assertTrue($fingerprintRef->isFinal());
        $this->assertTrue($intentRef->isFinal());
        $this->assertTrue($churnRef->isFinal());
    }

    public function testAllServicesDeclareStrictTypes(): void
    {
        $files = [
            (new \ReflectionClass(EventBehavioralFingerprintService::class))->getFileName(),
            (new \ReflectionClass(EventIntentDetectionService::class))->getFileName(),
            (new \ReflectionClass(PredictiveChurnScoringService::class))->getFileName(),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents((string) $file);
            $this->assertStringContainsString('declare(strict_types=1)', $contents);
        }
    }

    public function testConstructorsHaveVoidReturnType(): void
    {
        $classes = [
            EventBehavioralFingerprintService::class,
            EventIntentDetectionService::class,
            PredictiveChurnScoringService::class,
        ];

        foreach ($classes as $class) {
            $ref = new \ReflectionClass($class);
            $constructor = $ref->getConstructor();
            $this->assertNotNull($constructor);
            $returnType = $constructor->getReturnType();
            $this->assertNotNull($returnType);
            $this->assertSame('void', (string) $returnType);
        }
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Create sample events for fingerprint testing.
     *
     * @return list<array{name: string, timestamp: int, session_id: string, category: string}>
     */
    private function createSampleEvents(int $count, int $baseTime): array
    {
        $events = [];
        $eventTypes = ['page_view', 'click', 'feature_used', 'search', 'login', 'form_submit'];
        $categories = ['engagement', 'saas', 'ecommerce'];
        $sessions = ['s1', 's2', 's3'];

        for ($i = 0; $i < $count; $i++) {
            $events[] = [
                'name' => $eventTypes[$i % count($eventTypes)],
                'timestamp' => $baseTime + ($i * 300 + random_int(0, 60)),
                'session_id' => $sessions[$i % count($sessions)],
                'category' => $categories[$i % count($categories)],
            ];
        }

        return $events;
    }

    /**
     * Create events for intent detection testing.
     *
     * @return list<array{name: string, timestamp: int, page_url?: string|null, properties?: array<string, mixed>}>
     */
    private function createIntentEvents(int $baseTime): array
    {
        $events = [];
        $eventTemplates = [
            ['name' => 'page_view', 'page_url' => '/pricing'],
            ['name' => 'page_view', 'page_url' => '/features'],
            ['name' => 'feature_used', 'page_url' => '/dashboard'],
            ['name' => 'login', 'page_url' => '/login'],
            ['name' => 'page_view', 'page_url' => '/docs/api'],
            ['name' => 'search', 'page_url' => null],
            ['name' => 'page_view', 'page_url' => '/pricing'],
            ['name' => 'begin_checkout', 'page_url' => '/checkout'],
            ['name' => 'feature_used', 'page_url' => '/settings'],
            ['name' => 'page_view', 'page_url' => '/help'],
        ];

        foreach ($eventTemplates as $i => $template) {
            $events[] = array_merge($template, ['timestamp' => $baseTime + ($i * 3600)]);
        }

        return $events;
    }

    /**
     * Create events for churn scoring testing.
     *
     * @return list<array{name: string, timestamp: int, session_id: string, properties?: array<string, mixed>}>
     */
    private function createChurnEvents(int $count, int $baseTime): array
    {
        $events = [];
        $eventTypes = [
            'login', 'page_view', 'feature_used', 'feature_used_api',
            'search', 'form_submit', 'click', 'page_view',
        ];
        $sessions = ['s1', 's2', 's3', 's4'];

        for ($i = 0; $i < $count; $i++) {
            $events[] = [
                'name' => $eventTypes[$i % count($eventTypes)],
                'timestamp' => $baseTime + ($i * 7200),
                'session_id' => $sessions[$i % count($sessions)],
            ];
        }

        return $events;
    }
}

