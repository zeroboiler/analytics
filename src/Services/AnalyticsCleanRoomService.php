<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
/**
 * Privacy Data Clean Room Service — Privacy-Safe Cross-Party Analytics.
 *
 * Implements a data clean room pattern that enables privacy-safe aggregate
 * analytics queries between multiple parties without exposing individual
 * event data or personally identifiable information (PII).
 *
 * Clean rooms are widely used in SaaS analytics (Snowflake Clean Rooms,
 * Google Ads Data Hub, Habu, InfoSum) to allow:
 * - Advertiser → publisher attribution matching
 * - Multi-tenant SaaS cohort overlap analysis
 * - Third-party data enrichment without raw data sharing
 * - Investor/stakeholder aggregate reporting without user exposure
 *
 * Architecture:
 * - Each clean room is defined by an agreement (participants, scope, TTL)
 * - Participants submit aggregate sketches (counts, histograms, frequency caps)
 * - Queries run against sketches, never raw events
 * - k-anonymity threshold enforced on all results (minimum group size)
 * - Differential privacy noise optionally added to results
 * - Full audit trail of all queries and results
 *
 * Configuration: `zeroboiler.analytics.clean_room`
 *
 * @since 198.0.0
 */
final class AnalyticsCleanRoomService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_clean_room_';

    /** @var string Audit log cache key */
    private const AUDIT_KEY = 'zb_clean_room_audit';

    /** @var int Default k-anonymity threshold */
    private const DEFAULT_K_ANONYMITY = 5;

    /** @var int Default agreement TTL in seconds (7 days) */
    private const DEFAULT_AGREEMENT_TTL = 604800;

    /** @var int Default result cache TTL in seconds (1 hour) */
    private const DEFAULT_RESULT_TTL = 3600;

    /** @var int Default max active agreements */
    private const DEFAULT_MAX_AGREEMENTS = 50;

    /** @var int Default max queries per agreement per hour */
    private const DEFAULT_QUERY_RATE_LIMIT = 100;

    /** @var int Default max aggregate dimensions */
    private const DEFAULT_MAX_DIMENSIONS = 10;

    /** @var int Default audit trail retention (seconds, 90 days) */
    private const DEFAULT_AUDIT_RETENTION = 7776000;

    private CacheRepository $cache;

    private ConfigRepository $config;

    private bool $enabled;

    private int $kAnonymity;

    private int $agreementTtl;

    private int $resultTtl;

    private int $maxAgreements;

    private int $queryRateLimit;

    private int $maxDimensions;

    private bool $differentialPrivacy;

    private float $privacyBudget;

    private int $auditRetention;

    /** @var array<string, int> Query rate tracking (agreement_id => count) */
    private array $queryCounts = [];

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Config repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $roomConfig = $config->get('zeroboiler.analytics.clean_room', []);
        /** @var array{enabled?: bool, k_anonymity?: int, agreement_ttl?: int, result_ttl?: int, max_agreements?: int, query_rate_limit?: int, max_dimensions?: int, differential_privacy?: bool, privacy_budget?: float, audit_retention?: int} $roomConfig */

        $this->enabled = (bool) ($roomConfig['enabled'] ?? false);
        $this->kAnonymity = (int) ($roomConfig['k_anonymity'] ?? self::DEFAULT_K_ANONYMITY);
        $this->agreementTtl = (int) ($roomConfig['agreement_ttl'] ?? self::DEFAULT_AGREEMENT_TTL);
        $this->resultTtl = (int) ($roomConfig['result_ttl'] ?? self::DEFAULT_RESULT_TTL);
        $this->maxAgreements = (int) ($roomConfig['max_agreements'] ?? self::DEFAULT_MAX_AGREEMENTS);
        $this->queryRateLimit = (int) ($roomConfig['query_rate_limit'] ?? self::DEFAULT_QUERY_RATE_LIMIT);
        $this->maxDimensions = (int) ($roomConfig['max_dimensions'] ?? self::DEFAULT_MAX_DIMENSIONS);
        $this->differentialPrivacy = (bool) ($roomConfig['differential_privacy'] ?? true);
        $this->privacyBudget = (float) ($roomConfig['privacy_budget'] ?? 1.0);
        $this->auditRetention = (int) ($roomConfig['audit_retention'] ?? self::DEFAULT_AUDIT_RETENTION);
    }

    /**
     * Check if the clean room service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the k-anonymity threshold.
     */
    public function getKAnonymity(): int
    {
        return $this->kAnonymity;
    }

    /**
     * Enable the clean room service.
     */
    public function enable(): void
    {
        $this->enabled = true;
        $this->cache->put(self::CACHE_PREFIX . 'enabled', true, 86400);
        Log::info('Analytics clean room service enabled.');
    }

    /**
     * Disable the clean room service.
     */
    public function disable(): void
    {
        $this->enabled = false;
        $this->cache->put(self::CACHE_PREFIX . 'enabled', false, 86400);
        Log::info('Analytics clean room service disabled.');
    }

    /**
     * Create a new clean room agreement.
     *
     * An agreement defines the participants, data scope, and constraints
     * for privacy-safe analytics between two or more parties.
     *
     * @param  string  $agreementId  Unique agreement identifier
     * @param  list<string>  $participants  List of participant identifiers
     * @param  array{scope: list<string>, dimensions: list<string>, allowed_aggregations: list<string>, expires_at?: int|null}  $terms  Agreement terms
     * @return array{agreement_id: string, participants: list<string>, scope: list<string>, dimensions: list<string>, created_at: string, expires_at: string, status: string}
     */
    public function createAgreement(string $agreementId, array $participants, array $terms): array
    {
        if (! $this->enabled) {
            throw new \RuntimeException('Clean room service is disabled.');
        }

        if ($agreementId === '') {
            throw new \InvalidArgumentException('Agreement ID cannot be empty.');
        }

        if (count($participants) < 2) {
            throw new \InvalidArgumentException('Clean room requires at least 2 participants.');
        }

        $activeCount = $this->countActiveAgreements();
        if ($activeCount >= $this->maxAgreements) {
            throw new \RuntimeException("Maximum active agreements ({$this->maxAgreements}) reached.");
        }

        $dimensions = $terms['dimensions'] ?? [];
        if (count($dimensions) > $this->maxDimensions) {
            throw new \InvalidArgumentException("Maximum {$this->maxDimensions} dimensions allowed.");
        }

        $now = time();
        $expiresAt = $terms['expires_at'] ?? ($now + $this->agreementTtl);

        $agreement = [
            'agreement_id' => $agreementId,
            'participants' => $participants,
            'scope' => $terms['scope'] ?? ['event_counts'],
            'dimensions' => $dimensions,
            'allowed_aggregations' => $terms['allowed_aggregations'] ?? ['count', 'sum', 'avg'],
            'created_at' => date('Y-m-d\TH:i:s\Z', $now),
            'expires_at' => date('Y-m-d\TH:i:s\Z', $expiresAt),
            'status' => 'active',
            'k_anonymity' => $this->kAnonymity,
        ];

        $this->cache->put(
            self::CACHE_PREFIX . "agreement:{$agreementId}",
            $agreement,
            $this->agreementTtl,
        );

        $this->recordAudit('agreement_created', $agreementId, null, [
            'participants' => count($participants),
            'dimensions' => count($dimensions),
        ]);

        return $agreement;
    }

    /**
     * Get a clean room agreement by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getAgreement(string $agreementId): ?array
    {
        /** @var array<string, mixed>|null $agreement */
        $agreement = $this->cache->get(self::CACHE_PREFIX . "agreement:{$agreementId}");

        if ($agreement === null) {
            return null;
        }

        return $agreement;
    }

    /**
     * List all active clean room agreements.
     *
     * @return list<array<string, mixed>>
     */
    public function listAgreements(): array
    {
        $keys = $this->cache->get(self::CACHE_PREFIX . 'agreement_keys', []);

        if (! is_array($keys)) {
            return [];
        }

        $agreements = [];
        foreach ($keys as $key) {
            if (! is_string($key)) {
                continue;
            }
            $agreement = $this->cache->get(self::CACHE_PREFIX . "agreement:{$key}");
            if (is_array($agreement)) {
                $agreements[] = $agreement;
            }
        }

        return $agreements;
    }

    /**
     * Revoke a clean room agreement.
     */
    public function revokeAgreement(string $agreementId): bool
    {
        $agreement = $this->getAgreement($agreementId);

        if ($agreement === null) {
            return false;
        }

        $agreement['status'] = 'revoked';
        $agreement['revoked_at'] = date('Y-m-d\TH:i:s\Z');

        $this->cache->put(
            self::CACHE_PREFIX . "agreement:{$agreementId}",
            $agreement,
            86400, // Keep revoked agreement for 24h for audit
        );

        $this->cache->forget(self::CACHE_PREFIX . "results:{$agreementId}");

        $this->recordAudit('agreement_revoked', $agreementId, null, [
            'participants' => $agreement['participants'] ?? [],
        ]);

        return true;
    }

    /**
     * Count active clean room agreements.
     */
    public function countActiveAgreements(): int
    {
        return count($this->listAgreements());
    }

    /**
     * Submit an aggregate sketch for a participant.
     *
     * Participants submit pre-aggregated data sketches (counts, histograms)
     * rather than raw events. Sketches are validated against the agreement scope.
     *
     * @param  string  $agreementId  Agreement identifier
     * @param  string  $participantId  Participant identifier
     * @param  array<string, mixed>  $sketch  Aggregate sketch data
     * @return array{status: string, participant: string, accepted_dimensions: int, rejected_dimensions: list<string>}
     */
    public function submitSketch(string $agreementId, string $participantId, array $sketch): array
    {
        $agreement = $this->getAgreement($agreementId);

        if ($agreement === null) {
            throw new \RuntimeException("Agreement '{$agreementId}' not found.");
        }

        if ($agreement['status'] !== 'active') {
            throw new \RuntimeException("Agreement '{$agreementId}' is not active (status: {$agreement['status']}).");
        }

        if (! in_array($participantId, $agreement['participants'], true)) {
            throw new \RuntimeException("Participant '{$participantId}' is not part of agreement '{$agreementId}'.");
        }

        $allowedDimensions = $agreement['dimensions'] ?? [];
        $submittedDimensions = array_keys($sketch);
        $acceptedDimensions = [];
        $rejectedDimensions = [];

        foreach ($submittedDimensions as $dimension) {
            if (in_array($dimension, $allowedDimensions, true)) {
                $acceptedDimensions[] = $dimension;
            } else {
                $rejectedDimensions[] = $dimension;
            }
        }

        // Store validated sketch
        $validSketch = [];
        foreach ($acceptedDimensions as $dimension) {
            $validSketch[$dimension] = $sketch[$dimension];
        }

        $sketchKey = self::CACHE_PREFIX . "sketch:{$agreementId}:{$participantId}";
        $this->cache->put($sketchKey, $validSketch, $this->agreementTtl);

        $this->recordAudit('sketch_submitted', $agreementId, $participantId, [
            'accepted_dimensions' => count($acceptedDimensions),
            'rejected_dimensions' => $rejectedDimensions,
        ]);

        return [
            'status' => 'accepted',
            'participant' => $participantId,
            'accepted_dimensions' => count($acceptedDimensions),
            'rejected_dimensions' => $rejectedDimensions,
        ];
    }

    /**
     * Execute a privacy-safe aggregate query across clean room sketches.
     *
     * Queries operate on aggregate sketches submitted by participants.
     * Raw event data is never accessible. Results are filtered through
     * k-anonymity enforcement and optionally differential privacy noise.
     *
     * @param  string  $agreementId  Agreement identifier
     * @param  string  $queryType  Type of aggregate query (cohort_overlap, frequency, funnel, histogram)
     * @param  array<string, mixed>  $query  Query parameters
     * @return array{status: string, query_type: string, result: array<string, mixed>, k_anonymity_applied: bool, privacy_noise_applied: bool, computed_at: string}
     */
    public function executeQuery(string $agreementId, string $queryType, array $query = []): array
    {
        $agreement = $this->getAgreement($agreementId);

        if ($agreement === null) {
            throw new \RuntimeException("Agreement '{$agreementId}' not found.");
        }

        if ($agreement['status'] !== 'active') {
            throw new \RuntimeException("Agreement '{$agreementId}' is not active.");
        }

        // Rate limiting
        $this->enforceQueryRateLimit($agreementId);

        $allowedAggregations = $agreement['allowed_aggregations'] ?? ['count', 'sum', 'avg'];
        if (! in_array($queryType, $allowedAggregations, true)) {
            throw new \InvalidArgumentException("Query type '{$queryType}' is not allowed by this agreement.");
        }

        // Collect sketches from all participants
        $sketches = [];
        foreach ($agreement['participants'] as $participantId) {
            $sketchKey = self::CACHE_PREFIX . "sketch:{$agreementId}:{$participantId}";
            /** @var array<string, mixed>|null $sketch */
            $sketch = $this->cache->get($sketchKey);
            if ($sketch !== null) {
                $sketches[$participantId] = $sketch;
            }
        }

        if (count($sketches) < 2) {
            throw new \RuntimeException('At least 2 participant sketches are required to execute a query.');
        }

        // Compute aggregate result
        $result = $this->computeAggregate($queryType, $sketches, $query);

        // Enforce k-anonymity on results
        $kAnonymityApplied = $this->enforceKAnonymity($result);

        // Optionally add differential privacy noise
        $privacyNoiseApplied = false;
        if ($this->differentialPrivacy && $this->privacyBudget > 0) {
            $privacyNoiseApplied = $this->applyDifferentialPrivacy($result);
        }

        // Cache result
        $this->cache->put(
            self::CACHE_PREFIX . "results:{$agreementId}",
            $result,
            $this->resultTtl,
        );

        $this->recordAudit('query_executed', $agreementId, null, [
            'query_type' => $queryType,
            'participant_count' => count($sketches),
            'k_anonymity_applied' => $kAnonymityApplied,
            'privacy_noise_applied' => $privacyNoiseApplied,
        ]);

        return [
            'status' => 'ok',
            'query_type' => $queryType,
            'result' => $result,
            'k_anonymity_applied' => $kAnonymityApplied,
            'privacy_noise_applied' => $privacyNoiseApplied,
            'computed_at' => date('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Get cached query results for an agreement.
     *
     * @return array<string, mixed>|null
     */
    public function getQueryResults(string $agreementId): ?array
    {
        /** @var array<string, mixed>|null $results */
        $results = $this->cache->get(self::CACHE_PREFIX . "results:{$agreementId}");

        return $results;
    }

    /**
     * Compute aggregate results from participant sketches.
     *
     * @param  string  $queryType  Type of aggregate computation
     * @param  array<string, array<string, mixed>>  $sketches  Participant sketches
     * @param  array<string, mixed>  $query  Query parameters
     * @return array<string, mixed>
     */
    private function computeAggregate(string $queryType, array $sketches, array $query): array
    {
        switch ($queryType) {
            case 'cohort_overlap':
                return $this->computeCohortOverlap($sketches);

            case 'frequency':
                return $this->computeFrequencyAnalysis($sketches, $query);

            case 'funnel':
                return $this->computeFunnelAggregate($sketches, $query);

            case 'histogram':
                return $this->computeHistogramAggregate($sketches, $query);

            case 'count':
                return $this->computeCountAggregate($sketches, $query);

            case 'sum':
                return $this->computeSumAggregate($sketches, $query);

            case 'avg':
                return $this->computeAvgAggregate($sketches, $query);

            default:
                return $this->computeCountAggregate($sketches, $query);
        }
    }

    /**
     * Compute cohort overlap between participant sketches.
     *
     * @param  array<string, array<string, mixed>>  $sketches
     * @return array<string, mixed>
     */
    private function computeCohortOverlap(array $sketches): array
    {
        $participantIds = array_keys($sketches);

        // Extract cohort sets from sketches
        $cohortSets = [];
        foreach ($sketches as $participantId => $sketch) {
            $cohorts = $sketch['cohorts'] ?? [];
            if (is_array($cohorts)) {
                $cohortSets[$participantId] = array_map(
                    fn (mixed $v): string => (string) $v,
                    array_keys($cohorts),
                );
            }
        }

        // Compute intersection sizes (using aggregate counts, not raw IDs)
        $overlaps = [];
        for ($i = 0; $i < count($participantIds); $i++) {
            for ($j = $i + 1; $j < count($participantIds); $j++) {
                $pA = $participantIds[$i];
                $pB = $participantIds[$j];

                $setA = $cohortSets[$pA] ?? [];
                $setB = $cohortSets[$pB] ?? [];

                $intersection = array_intersect($setA, $setB);
                $union = array_unique(array_merge($setA, $setB));
                $jaccardIndex = count($union) > 0 ? count($intersection) / count($union) : 0.0;

                $pairKey = "{$pA}_x_{$pB}";
                $overlaps[$pairKey] = [
                    'common_cohorts' => count($intersection),
                    'total_unique_cohorts' => count($union),
                    'jaccard_similarity' => round($jaccardIndex, 4),
                    'intersection' => array_values($intersection),
                ];
            }
        }

        return [
            'type' => 'cohort_overlap',
            'participants' => $participantIds,
            'pairwise_overlaps' => $overlaps,
            'total_participants' => count($sketches),
        ];
    }

    /**
     * Compute frequency analysis from sketches.
     *
     * @param  array<string, array<string, mixed>>  $sketches
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function computeFrequencyAnalysis(array $sketches, array $query): array
    {
        $dimension = (string) ($query['dimension'] ?? 'event_name');
        $mergedFrequencies = [];

        foreach ($sketches as $participantId => $sketch) {
            $frequencies = $sketch[$dimension] ?? $sketch['frequencies'] ?? [];
            if (is_array($frequencies)) {
                foreach ($frequencies as $key => $count) {
                    $key = (string) $key;
                    if (! isset($mergedFrequencies[$key])) {
                        $mergedFrequencies[$key] = ['total' => 0, 'participants' => 0];
                    }
                    $mergedFrequencies[$key]['total'] += (int) $count;
                    $mergedFrequencies[$key]['participants'] += 1;
                }
            }
        }

        // Sort by frequency descending
        uasort($mergedFrequencies, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'type' => 'frequency',
            'dimension' => $dimension,
            'items' => array_slice($mergedFrequencies, 0, 100, true),
            'unique_values' => count($mergedFrequencies),
        ];
    }

    /**
     * Compute funnel aggregate across participants.
     *
     * @param  array<string, array<string, mixed>>  $sketches
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function computeFunnelAggregate(array $sketches, array $query): array
    {
        $steps = $query['steps'] ?? [];
        if (! is_array($steps) || $steps === []) {
            return ['type' => 'funnel', 'error' => 'No steps provided', 'steps' => []];
        }

        $funnelSteps = [];
        $previousCount = 0;

        foreach ($steps as $step) {
            $stepName = (string) $step;
            $totalForStep = 0;

            foreach ($sketches as $sketch) {
                $counts = $sketch['funnel'] ?? $sketch['event_counts'] ?? [];
                $totalForStep += (int) ($counts[$stepName] ?? 0);
            }

            $conversionRate = $previousCount > 0
                ? round(($totalForStep / $previousCount) * 100, 2)
                : null;

            $funnelSteps[] = [
                'step' => $stepName,
                'count' => $totalForStep,
                'conversion_from_previous' => $conversionRate,
            ];

            $previousCount = $totalForStep;
        }

        $overallConversion = isset($funnelSteps[0], $funnelSteps[count($funnelSteps) - 1])
            && $funnelSteps[0]['count'] > 0
            ? round(($funnelSteps[count($funnelSteps) - 1]['count'] / $funnelSteps[0]['count']) * 100, 2)
            : 0.0;

        return [
            'type' => 'funnel',
            'steps' => $funnelSteps,
            'overall_conversion' => $overallConversion,
            'total_steps' => count($funnelSteps),
        ];
    }

    /**
     * Compute histogram aggregate from sketches.
     *
     * @param  array<string, array<string, mixed>>  $sketches
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function computeHistogramAggregate(array $sketches, array $query): array
    {
        $dimension = (string) ($query['dimension'] ?? 'value');
        $bins = (int) ($query['bins'] ?? 10);
        $mergedValues = [];

        foreach ($sketches as $sketch) {
            $values = $sketch['histogram'] ?? $sketch[$dimension] ?? [];
            if (is_array($values)) {
                foreach ($values as $value) {
                    $mergedValues[] = is_numeric($value) ? (float) $value : 0.0;
                }
            }
        }

        if ($mergedValues === []) {
            return ['type' => 'histogram', 'dimension' => $dimension, 'bins' => [], 'total_values' => 0];
        }

        $min = min($mergedValues);
        $max = max($mergedValues);
        $binWidth = ($max - $min) > 0 ? ($max - $min) / $bins : 1.0;

        $histogramBins = [];
        for ($i = 0; $i < $bins; $i++) {
            $lower = $min + ($i * $binWidth);
            $upper = $lower + $binWidth;
            $count = 0;

            foreach ($mergedValues as $value) {
                if ($i === $bins - 1) {
                    if ($value >= $lower && $value <= $upper) {
                        $count++;
                    }
                } elseif ($value >= $lower && $value < $upper) {
                    $count++;
                }
            }

            $histogramBins[] = [
                'bin' => $i,
                'lower' => round($lower, 4),
                'upper' => round($upper, 4),
                'count' => $count,
            ];
        }

        return [
            'type' => 'histogram',
            'dimension' => $dimension,
            'bins' => $histogramBins,
            'total_values' => count($mergedValues),
            'min' => round($min, 4),
            'max' => round($max, 4),
            'mean' => round(array_sum($mergedValues) / count($mergedValues), 4),
        ];
    }

    /**
     * Compute count aggregate from sketches.
     *
     * @param  array<string, array<string, mixed>>  $sketches
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function computeCountAggregate(array $sketches, array $query): array
    {
        $dimension = (string) ($query['dimension'] ?? 'total');
        $filteredDimension = (string) ($query['filter'] ?? '');

        $counts = [];
        foreach ($sketches as $participantId => $sketch) {
            $data = $filteredDimension !== ''
                ? ($sketch[$filteredDimension] ?? [])
                : $sketch;

            $count = 0;
            if ($dimension === 'total') {
                $count = is_array($data) ? count($data) : (is_int($data) || is_float($data) ? (int) $data : 0);
            } elseif (is_array($data) && isset($data[$dimension])) {
                $count = (int) $data[$dimension];
            }

            $counts[$participantId] = $count;
        }

        return [
            'type' => 'count',
            'dimension' => $dimension,
            'per_participant' => $counts,
            'total' => array_sum($counts),
            'participants' => count($sketches),
        ];
    }

    /**
     * Compute sum aggregate from sketches.
     *
     * @param  array<string, array<string, mixed>>  $sketches
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function computeSumAggregate(array $sketches, array $query): array
    {
        $dimension = (string) ($query['dimension'] ?? 'value');
        $sums = [];

        foreach ($sketches as $participantId => $sketch) {
            $data = $sketch[$dimension] ?? $sketch;
            $sum = 0;

            if (is_numeric($data)) {
                $sum = (float) $data;
            } elseif (is_array($data)) {
                foreach ($data as $value) {
                    if (is_numeric($value)) {
                        $sum += (float) $value;
                    }
                }
            }

            $sums[$participantId] = $sum;
        }

        return [
            'type' => 'sum',
            'dimension' => $dimension,
            'per_participant' => $sums,
            'total' => array_sum($sums),
            'participants' => count($sketches),
        ];
    }

    /**
     * Compute average aggregate from sketches.
     *
     * @param  array<string, array<string, mixed>>  $sketches
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function computeAvgAggregate(array $sketches, array $query): array
    {
        $dimension = (string) ($query['dimension'] ?? 'value');
        $averages = [];

        foreach ($sketches as $participantId => $sketch) {
            $data = $sketch[$dimension] ?? $sketch;
            $values = [];

            if (is_numeric($data)) {
                $values[] = (float) $data;
            } elseif (is_array($data)) {
                foreach ($data as $value) {
                    if (is_numeric($value)) {
                        $values[] = (float) $value;
                    }
                }
            }

            $averages[$participantId] = $values !== []
                ? round(array_sum($values) / count($values), 4)
                : 0.0;
        }

        $allValues = array_values($averages);

        return [
            'type' => 'avg',
            'dimension' => $dimension,
            'per_participant' => $averages,
            'overall_avg' => $allValues !== [] ? round(array_sum($allValues) / count($allValues), 4) : 0.0,
            'participants' => count($sketches),
        ];
    }

    /**
     * Enforce k-anonymity on aggregate results.
     *
     * Filters out any result values where the underlying group size
     * is below the k-anonymity threshold. Prevents re-identification
     * of individuals through small-group inference attacks.
     *
     * @param  array<string, mixed>  $result  Aggregate result to filter
     * @return bool Whether any filtering was applied
     */
    private function enforceKAnonymity(array &$result): bool
    {
        $filtered = false;

        // If result contains per-item counts, suppress counts below k
        if (isset($result['items']) && is_array($result['items'])) {
            foreach ($result['items'] as $key => &$item) {
                if (is_array($item) && isset($item['total']) && (int) $item['total'] < $this->kAnonymity) {
                    $item['total'] = 0;
                    $item['suppressed'] = true;
                    $filtered = true;
                }
            }
        }

        // If result contains per_participant counts, suppress low counts
        if (isset($result['per_participant']) && is_array($result['per_participant'])) {
            foreach ($result['per_participant'] as $participant => &$count) {
                if ((int) $count < $this->kAnonymity) {
                    $count = 0;
                    $filtered = true;
                }
            }
        }

        // Suppress pairwise overlaps below k
        if (isset($result['pairwise_overlaps']) && is_array($result['pairwise_overlaps'])) {
            foreach ($result['pairwise_overlaps'] as $pair => &$overlap) {
                if (is_array($overlap) && isset($overlap['common_cohorts']) && (int) $overlap['common_cohorts'] < $this->kAnonymity) {
                    $overlap['common_cohorts'] = 0;
                    $overlap['suppressed'] = true;
                    $filtered = true;
                }
            }
        }

        $result['k_anonymity_threshold'] = $this->kAnonymity;

        return $filtered;
    }

    /**
     * Apply differential privacy noise to numeric results.
     *
     * Adds Laplace noise calibrated to the privacy budget (epsilon).
     * Noise magnitude is proportional to the sensitivity of the query
     * divided by epsilon.
     *
     * @param  array<string, mixed>  $result  Aggregate result
     * @return bool Whether noise was applied
     */
    private function applyDifferentialPrivacy(array &$result): bool
    {
        $epsilon = $this->privacyBudget;
        if ($epsilon <= 0) {
            return false;
        }

        $applied = false;

        // Apply noise to total counts
        if (isset($result['total']) && is_numeric($result['total'])) {
            $result['total'] = $result['total'] + $this->laplaceNoise(1.0 / $epsilon);
            $applied = true;
        }

        // Apply noise to overall_avg
        if (isset($result['overall_avg']) && is_numeric($result['overall_avg'])) {
            $result['overall_avg'] = $result['overall_avg'] + $this->laplaceNoise(1.0 / $epsilon);
            $applied = true;
        }

        // Apply noise to overall_conversion
        if (isset($result['overall_conversion']) && is_numeric($result['overall_conversion'])) {
            $result['overall_conversion'] = $result['overall_conversion'] + $this->laplaceNoise(1.0 / $epsilon);
            $applied = true;
        }

        if ($applied) {
            $result['privacy_epsilon'] = $epsilon;
            $result['privacy_mechanism'] = 'laplace';
        }

        return $applied;
    }

    /**
     * Generate Laplace noise with a given scale parameter.
     *
     * Uses the inverse CDF method for Laplace distribution sampling.
     *
     * @param  float  $scale  Scale parameter (b = sensitivity / epsilon)
     * @return float Noise value
     */
    private function laplaceNoise(float $scale): float
    {
        $u = mt_rand() / mt_getrandmax() - 0.5;

        return $scale * (-1.0) * log(1.0 - 2.0 * abs($u));
    }

    /**
     * Enforce query rate limit for an agreement.
     */
    private function enforceQueryRateLimit(string $agreementId): void
    {
        $rateKey = self::CACHE_PREFIX . "rate:{$agreementId}";
        $currentCount = (int) $this->cache->get($rateKey, 0);

        if ($currentCount >= $this->queryRateLimit) {
            throw new \RuntimeException("Query rate limit ({$this->queryRateLimit}/hour) exceeded for agreement '{$agreementId}'.");
        }

        $this->cache->put($rateKey, $currentCount + 1, 3600);
    }

    /**
     * Record an audit entry for a clean room operation.
     *
     * @param  string  $action  Audit action type
     * @param  string  $agreementId  Agreement identifier
     * @param  string|null  $participantId  Participant identifier (nullable)
     * @param  array<string, mixed>  $metadata  Additional metadata
     */
    private function recordAudit(string $action, string $agreementId, ?string $participantId, array $metadata): void
    {
        $auditKey = self::AUDIT_KEY;
        /** @var list<array<string, mixed>> $auditLog */
        $auditLog = $this->cache->get($auditKey, []);

        $entry = [
            'action' => $action,
            'agreement_id' => $agreementId,
            'participant_id' => $participantId,
            'metadata' => $metadata,
            'timestamp' => date('Y-m-d\TH:i:s\Z'),
        ];

        $auditLog[] = $entry;

        // Keep only the last 1000 entries in cache
        if (count($auditLog) > 1000) {
            $auditLog = array_slice($auditLog, -1000);
        }

        $this->cache->put($auditKey, $auditLog, $this->auditRetention);
    }

    /**
     * Get the clean room audit trail.
     *
     * @param  int  $limit  Maximum entries to return
     * @return list<array<string, mixed>>
     */
    public function getAuditTrail(int $limit = 100): array
    {
        /** @var list<array<string, mixed>> $auditLog */
        $auditLog = $this->cache->get(self::AUDIT_KEY, []);

        return array_slice($auditLog, -$limit);
    }

    /**
     * Get clean room service statistics.
     *
     * @return array{enabled: bool, k_anonymity: int, active_agreements: int, max_agreements: int, query_rate_limit: int, differential_privacy: bool, privacy_budget: float, agreement_ttl: int, audit_entries: int}
     */
    public function stats(): array
    {
        /** @var list<array<string, mixed>> $auditLog */
        $auditLog = $this->cache->get(self::AUDIT_KEY, []);

        return [
            'enabled' => $this->enabled,
            'k_anonymity' => $this->kAnonymity,
            'active_agreements' => $this->countActiveAgreements(),
            'max_agreements' => $this->maxAgreements,
            'query_rate_limit' => $this->queryRateLimit,
            'differential_privacy' => $this->differentialPrivacy,
            'privacy_budget' => $this->privacyBudget,
            'agreement_ttl' => $this->agreementTtl,
            'audit_entries' => count($auditLog),
        ];
    }

    /**
     * Validate configuration.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validateConfig(): array
    {
        $errors = [];
        $warnings = [];

        if ($this->kAnonymity < 2) {
            $errors[] = 'k_anonymity must be >= 2 for meaningful privacy protection.';
        }

        if ($this->kAnonymity > 100) {
            $warnings[] = 'Very high k_anonymity threshold may suppress most query results.';
        }

        if ($this->maxAgreements < 1) {
            $errors[] = 'max_agreements must be >= 1.';
        }

        if ($this->queryRateLimit < 1) {
            $errors[] = 'query_rate_limit must be >= 1.';
        }

        if ($this->maxDimensions < 1) {
            $errors[] = 'max_dimensions must be >= 1.';
        }

        if ($this->maxDimensions > 50) {
            $warnings[] = 'High dimension count may impact query performance.';
        }

        if ($this->differentialPrivacy && $this->privacyBudget <= 0) {
            $errors[] = 'privacy_budget must be > 0 when differential_privacy is enabled.';
        }

        if ($this->differentialPrivacy && $this->privacyBudget > 10) {
            $warnings[] = 'Very high privacy budget (epsilon) provides weak privacy guarantees.';
        }

        if ($this->agreementTtl < 3600) {
            $warnings[] = 'Short agreement TTL (< 1 hour) may cause frequent re-negotiation.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Clear all clean room data from cache.
     */
    public function flush(): void
    {
        $keys = $this->cache->get(self::CACHE_PREFIX . 'agreement_keys', []);

        if (is_array($keys)) {
            foreach ($keys as $key) {
                if (is_string($key)) {
                    $this->cache->forget(self::CACHE_PREFIX . "agreement:{$key}");
                    $this->cache->forget(self::CACHE_PREFIX . "results:{$key}");
                    $this->cache->forget(self::CACHE_PREFIX . "rate:{$key}");
                }
            }
        }

        $this->cache->forget(self::CACHE_PREFIX . 'agreement_keys');
        $this->cache->forget(self::CACHE_PREFIX . 'enabled');
        $this->cache->forget(self::AUDIT_KEY);
    }
}
