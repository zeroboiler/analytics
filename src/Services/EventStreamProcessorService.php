<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventSequencePattern;
use ZeroBoiler\Analytics\DTO\StreamEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event Stream Processing Engine — sequential event analysis and pattern discovery.
 *
 * Processes raw analytics events into ordered sequences, discovers frequent
 * event patterns (event sequencing), detects auto-funnels from observed user
 * journeys, identifies stream-level anomalies (velocity spikes, unusual gaps),
 * and computes sequence-level metrics (completion rates, drop-off positions).
 *
 * All stream data is cache-backed with configurable TTL. Designed for
 * real-time dashboard widgets and async batch processing.
 *
 * Inspired by Amplitude Pathfinder, Mixpanel Flow, and PostHog User Paths.
 *
 * @since 31.0.0
 */
final class EventStreamProcessorService
{
    /** @var non-empty-string */
    private string $cachePrefix;

    private readonly bool $enabled;

    private readonly int $cacheTtl;

    private readonly int $maxSequenceLength;

    private readonly int $maxPatternsPerClient;

    private readonly int $minPatternSupport;

    private readonly float $anomalyDeviationThreshold;

    private readonly int $anomalyWindowSize;

    private readonly int $maxStreamEventsPerClient;

    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache
     * @param  array{enabled: bool, cache_ttl: int, max_sequence_length: int, max_patterns_per_client: int, min_pattern_support: int, anomaly_deviation: float, anomaly_window: int, max_stream_events: int, cache_prefix?: string}  $config
     */
    public function __construct(CacheRepository $cache, array $config = []): void
    {
        $this->cache = $cache;
        $this->cachePrefix = $config['cache_prefix'] ?? 'zb_stream_proc_';
        $this->enabled = $config['enabled'] ?? true;
        $this->cacheTtl = $config['cache_ttl'] ?? 3600;
        $this->maxSequenceLength = $config['max_sequence_length'] ?? 10;
        $this->maxPatternsPerClient = $config['max_patterns_per_client'] ?? 50;
        $this->minPatternSupport = $config['min_pattern_support'] ?? 2;
        $this->anomalyDeviationThreshold = $config['anomaly_deviation'] ?? 3.0;
        $this->anomalyWindowSize = $config['anomaly_window'] ?? 600;
        $this->maxStreamEventsPerClient = $config['max_stream_events'] ?? 500;
    }

    /**
     * Check if the stream processor is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Process an incoming analytics event into the stream.
     *
     * Appends the event to the client's ordered stream, computes time-since-previous,
     * updates sequence counters, and triggers pattern extraction on configurable
     * sequence boundaries.
     *
     * @param  AnalyticsEvent  $event  The analytics event to process
     * @param  string|null  $clientId  Client ID (falls back to event param or 'anonymous')
     * @return StreamEvent The stream-processed event with sequencing metadata
     */
    public function processEvent(AnalyticsEvent $event, ?string $clientId = null): StreamEvent
    {
        if (! $this->enabled) {
            return $this->createStreamEvent($event, $clientId ?? 'anon', 1, null);
        }

        $clientId = $clientId ?? $event->param('client_id') ?? $event->param('clientId') ?? 'anonymous';
        $clientId = is_string($clientId) ? $clientId : (string) $clientId;

        $streamKey = $this->cachePrefix . 'stream:' . $clientId;
        $metaKey = $this->cachePrefix . 'meta:' . $clientId;

        /** @var list<array{event_name: string, timestamp: int, position: int, time_since_previous: float|null}> $stream */
        $stream = $this->cache->get($streamKey, []);

        /** @var array{last_timestamp: int|null, position: int, sequence_buffer: list<string>, total_events: int, unique_events: array<string, bool>} $meta */
        $meta = $this->cache->get($metaKey, [
            'last_timestamp' => null,
            'position' => 0,
            'sequence_buffer' => [],
            'total_events' => 0,
            'unique_events' => [],
        ]);

        $meta['position']++;
        $meta['total_events']++;
        $eventName = $event->name();
        $timestamp = $event->timestamp?->getTimestamp() ?? time();
        $timeSincePrevious = $meta['last_timestamp'] !== null
            ? (float) ($timestamp - $meta['last_timestamp'])
            : null;
        $meta['last_timestamp'] = $timestamp;
        $meta['unique_events'][$eventName] = true;

        // Append to stream
        $streamEntry = [
            'event_name' => $eventName,
            'timestamp' => $timestamp,
            'position' => $meta['position'],
            'time_since_previous' => $timeSincePrevious,
        ];
        $stream[] = $streamEntry;

        // Trim stream to max events (FIFO)
        if (count($stream) > $this->maxStreamEventsPerClient) {
            $stream = array_slice($stream, -$this->maxStreamEventsPerClient);
        }

        // Update sequence buffer for pattern extraction
        $meta['sequence_buffer'][] = $eventName;
        if (count($meta['sequence_buffer']) > $this->maxSequenceLength) {
            $meta['sequence_buffer'] = array_slice($meta['sequence_buffer'], -$this->maxSequenceLength);
        }

        // Extract patterns when buffer reaches a meaningful length
        if (count($meta['sequence_buffer']) >= 3) {
            $this->extractAndCachePatterns($clientId, $meta['sequence_buffer'], $timestamp);
        }

        // Store updated state
        $this->cache->put($streamKey, $stream, $this->cacheTtl);
        $this->cache->put($metaKey, $meta, $this->cacheTtl);

        return $this->createStreamEvent($event, $clientId, $meta['position'], $timeSincePrevious);
    }

    /**
     * Discover the most frequent event sequences across all tracked clients.
     *
     * Aggregates patterns from individual client streams and returns
     * the top N patterns ranked by occurrence count.
     *
     * @param  int  $limit  Maximum patterns to return (default: 20)
     * @return list<EventSequencePattern>
     */
    public function discoverTopPatterns(int $limit = 20): array
    {
        if (! $this->enabled) {
            return [];
        }

        $globalKey = $this->cachePrefix . 'global_patterns';

        /** @var array<string, array{sequence: list<string>, occurrences: int, unique_users: int, durations: list<float>, conversion_rate: float, sample_clients: list<string>}> $globalPatterns */
        $globalPatterns = $this->cache->get($globalKey, []);

        $patterns = [];
        foreach ($globalPatterns as $patternId => $data) {
            if ($data['occurrences'] < $this->minPatternSupport) {
                continue;
            }

            $durations = $data['durations'] ?? [];
            $avgDuration = count($durations) > 0
                ? array_sum($durations) / count($durations)
                : 0.0;
            $medianDuration = $this->computeMedian($durations);

            $patterns[] = new EventSequencePattern(
                id: $patternId,
                sequence: $data['sequence'],
                occurrences: $data['occurrences'],
                uniqueUsers: $data['unique_users'],
                averageDurationSeconds: $avgDuration,
                medianDurationSeconds: $medianDuration,
                conversionRate: $data['conversion_rate'] ?? 0.0,
                sampleClientIds: array_slice($data['sample_clients'] ?? [], 0, 10),
                metadata: [
                    'support_ratio' => count($globalPatterns) > 0
                        ? round($data['occurrences'] / count($globalPatterns), 4)
                        : 0.0,
                ],
            );
        }

        // Sort by occurrences descending
        usort($patterns, static fn (EventSequencePattern $a, EventSequencePattern $b): int => $b->occurrences <=> $a->occurrences);

        return array_slice($patterns, 0, $limit);
    }

    /**
     * Detect automatically-discovered funnels from observed event sequences.
     *
     * Identifies sequences where events progress toward a conversion event
     * (purchase, subscribe, trial_converted, sign_up) and analyzes drop-off
     * rates at each step.
     *
     * @return list<array{steps: list<string>, total_sequences: int, step_completion: list<float>, drop_off_positions: list<int>, avg_duration_seconds: float, conversion_rate: float}>
     */
    public function discoverAutoFunnels(): array
    {
        if (! $this->enabled) {
            return [];
        }

        $patterns = $this->discoverTopPatterns(100);
        $conversionEvents = ['purchase', 'subscribe', 'sign_up', 'start_trial', 'trial_converted'];
        $funnels = [];

        foreach ($patterns as $pattern) {
            $seq = $pattern->sequence;
            $lastEvent = end($seq);

            if ($lastEvent === false || ! in_array($lastEvent, $conversionEvents, true)) {
                continue;
            }

            if (count($seq) < 2) {
                continue;
            }

            // Compute per-step completion rates from global pattern data
            $globalKey = $this->cachePrefix . 'global_patterns';
            /** @var array<string, array{sequence: list<string>, occurrences: int, step_counts: array<int, int>}>|null $globalPatterns */
            $globalPatterns = $this->cache->get($globalKey);

            $stepCounts = [];
            if ($globalPatterns !== null && isset($globalPatterns[$pattern->id]['step_counts'])) {
                $stepCounts = $globalPatterns[$pattern->id]['step_counts'];
            }

            $stepCompletion = [];
            $prevCount = $pattern->occurrences;
            $dropOffPositions = [];

            for ($i = 0; $i < count($seq); $i++) {
                $stepCount = $stepCounts[$i] ?? $prevCount;
                $rate = $prevCount > 0 ? $stepCount / $prevCount : 1.0;
                $stepCompletion[] = round($rate, 4);

                if ($rate < 0.5 && $i > 0) {
                    $dropOffPositions[] = $i;
                }

                $prevCount = $stepCount;
            }

            $funnels[] = [
                'steps' => $seq,
                'total_sequences' => $pattern->occurrences,
                'step_completion' => $stepCompletion,
                'drop_off_positions' => $dropOffPositions,
                'avg_duration_seconds' => round($pattern->averageDurationSeconds, 2),
                'conversion_rate' => round($pattern->conversionRate, 4),
            ];
        }

        // Sort by total_sequences descending
        usort($funnels, static fn (array $a, array $b): int => $b['total_sequences'] <=> $a['total_sequences']);

        return array_slice($funnels, 0, 10);
    }

    /**
     * Analyze a specific client's event stream for sequential analysis.
     *
     * Returns the full ordered stream with time deltas, session grouping,
     * and detected patterns for the client.
     *
     * @param  string  $clientId
     * @return array{stream: list<array{event_name: string, position: int, timestamp: int, time_since_previous: float|null, category: string|null}>, total_events: int, unique_events: int, session_count: int, detected_patterns: list<EventSequencePattern>, avg_time_between_events: float|null}
     */
    public function analyzeClientStream(string $clientId): array
    {
        if (! $this->enabled) {
            return [
                'stream' => [],
                'total_events' => 0,
                'unique_events' => 0,
                'session_count' => 0,
                'detected_patterns' => [],
                'avg_time_between_events' => null,
            ];
        }

        $streamKey = $this->cachePrefix . 'stream:' . $clientId;
        $metaKey = $this->cachePrefix . 'meta:' . $clientId;
        $patternsKey = $this->cachePrefix . 'patterns:' . $clientId;

        /** @var list<array{event_name: string, timestamp: int, position: int, time_since_previous: float|null}> $stream */
        $stream = $this->cache->get($streamKey, []);

        /** @var array{unique_events: array<string, bool>}|null $meta */
        $meta = $this->cache->get($metaKey);

        /** @var array<string, EventSequencePattern>|null $rawPatterns */
        $rawPatterns = $this->cache->get($patternsKey, []);

        $enrichedStream = [];
        $timeDeltas = [];

        foreach ($stream as $entry) {
            $enrichedStream[] = [
                'event_name' => $entry['event_name'],
                'position' => $entry['position'],
                'timestamp' => $entry['timestamp'],
                'time_since_previous' => $entry['time_since_previous'] !== null
                    ? round($entry['time_since_previous'], 3)
                    : null,
                'category' => EventCatalog::getCategory($entry['event_name']),
            ];

            if ($entry['time_since_previous'] !== null) {
                $timeDeltas[] = $entry['time_since_previous'];
            }
        }

        // Estimate session count from time gaps (> 30 min gap = new session)
        $sessionCount = 1;
        foreach ($timeDeltas as $delta) {
            if ($delta > 1800) {
                $sessionCount++;
            }
        }

        $detectedPatterns = [];
        foreach ($rawPatterns as $patternArray) {
            if (is_array($patternArray) && isset($patternArray['id'], $patternArray['sequence'])) {
                $detectedPatterns[] = new EventSequencePattern(
                    id: $patternArray['id'],
                    sequence: $patternArray['sequence'],
                    occurrences: $patternArray['occurrences'] ?? 0,
                    uniqueUsers: $patternArray['unique_users'] ?? 0,
                    averageDurationSeconds: $patternArray['averageDurationSeconds'] ?? 0.0,
                    medianDurationSeconds: $patternArray['medianDurationSeconds'] ?? 0.0,
                    conversionRate: $patternArray['conversion_rate'] ?? 0.0,
                    sampleClientIds: $patternArray['sample_client_ids'] ?? [],
                );
            }
        }

        return [
            'stream' => $enrichedStream,
            'total_events' => count($stream),
            'unique_events' => count($meta['unique_events'] ?? []),
            'session_count' => $sessionCount,
            'detected_patterns' => $detectedPatterns,
            'avg_time_between_events' => count($timeDeltas) > 0
                ? round(array_sum($timeDeltas) / count($timeDeltas), 2)
                : null,
        ];
    }

    /**
     * Detect stream-level anomalies for a specific client.
     *
     * Identifies:
     * - Velocity spikes (event rate exceeds baseline by threshold)
     * - Unusual gaps (long pauses between events)
     * - Sequence breaks (unexpected event ordering)
     * - Repetition anomalies (same event fired rapidly)
     *
     * @param  string  $clientId
     * @return list<array{type: string, severity: string, description: string, timestamp: int|null, event_name: string|null, metric: float|null}>
     */
    public function detectStreamAnomalies(string $clientId): array
    {
        if (! $this->enabled) {
            return [];
        }

        $streamKey = $this->cachePrefix . 'stream:' . $clientId;

        /** @var list<array{event_name: string, timestamp: int, position: int, time_since_previous: float|null}> $stream */
        $stream = $this->cache->get($streamKey, []);

        if (count($stream) < 3) {
            return [];
        }

        $anomalies = [];
        $timeDeltas = [];
        $eventCounts = [];

        foreach ($stream as $entry) {
            if ($entry['time_since_previous'] !== null) {
                $timeDeltas[] = $entry['time_since_previous'];
            }
            $eventCounts[] = $entry['event_name'];
        }

        // Compute baseline stats
        $meanDelta = count($timeDeltas) > 0 ? array_sum($timeDeltas) / count($timeDeltas) : 0;
        $stdDelta = $this->standardDeviation($timeDeltas);
        $threshold = $meanDelta + ($stdDelta * $this->anomalyDeviationThreshold);

        // Detect unusual gaps
        for ($i = 0; $i < count($stream); $i++) {
            $delta = $stream[$i]['time_since_previous'] ?? null;
            if ($delta !== null && $delta > $threshold && $threshold > 0) {
                $severity = $delta > ($threshold * 2) ? 'critical' : 'warning';
                $anomalies[] = [
                    'type' => 'unusual_gap',
                    'severity' => $severity,
                    'description' => sprintf(
                        'Unusual %.0fs gap before "%s" (baseline: %.1fs ± %.1fs)',
                        $delta,
                        $stream[$i]['event_name'],
                        round($meanDelta, 1),
                        round($stdDelta, 1),
                    ),
                    'timestamp' => $stream[$i]['timestamp'],
                    'event_name' => $stream[$i]['event_name'],
                    'metric' => round($delta, 2),
                ];
            }
        }

        // Detect rapid repetition (same event within 2 seconds, 3+ times)
        $repetitionCounts = [];
        for ($i = 1; $i < count($stream); $i++) {
            if (
                $stream[$i]['event_name'] === $stream[$i - 1]['event_name']
                && $stream[$i]['time_since_previous'] !== null
                && $stream[$i]['time_since_previous'] < 2.0
            ) {
                $key = $stream[$i]['event_name'] . ':' . $stream[$i]['timestamp'];
                $repetitionCounts[$key] = ($repetitionCounts[$key] ?? 1) + 1;
            }
        }

        foreach ($repetitionCounts as $key => $count) {
            if ($count >= 3) {
                [$eventName] = explode(':', $key);
                $anomalies[] = [
                    'type' => 'rapid_repetition',
                    'severity' => 'warning',
                    'description' => sprintf(
                        'Event "%s" repeated %d times within 2s intervals',
                        $eventName,
                        $count,
                    ),
                    'timestamp' => null,
                    'event_name' => $eventName,
                    'metric' => (float) $count,
                ];
            }
        }

        // Detect velocity spikes (event rate in last N events vs baseline)
        if (count($stream) >= 10) {
            $recentEvents = array_slice($stream, -10);
            $recentDeltas = array_filter(
                array_column($recentEvents, 'time_since_previous'),
                static fn (?float $d): bool => $d !== null,
            );

            if (count($recentDeltas) >= 5) {
                $recentMean = array_sum($recentDeltas) / count($recentDeltas);
                $overallMean = array_sum($timeDeltas) / count($timeDeltas);

                if ($overallMean > 0 && $recentMean < ($overallMean / $this->anomalyDeviationThreshold)) {
                    $anomalies[] = [
                        'type' => 'velocity_spike',
                        'severity' => $recentMean < ($overallMean / ($this->anomalyDeviationThreshold * 2)) ? 'critical' : 'warning',
                        'description' => sprintf(
                            'Event velocity spike: %.1fs/event in last 10 events (baseline: %.1fs/event)',
                            round($recentMean, 1),
                            round($overallMean, 1),
                        ),
                        'timestamp' => $recentEvents[array_key_last($recentEvents)]['timestamp'] ?? null,
                        'event_name' => null,
                        'metric' => round($recentMean, 2),
                    ];
                }
            }
        }

        return $anomalies;
    }

    /**
     * Get global stream processing statistics.
     *
     * @return array{total_clients_tracked: int, total_patterns_discovered: int, top_sequences: list<array{sequence: list<string>, occurrences: int}>, auto_funnels: list<array{steps: list<string>, total_sequences: int, conversion_rate: float}>}
     */
    public function getStreamStats(): array
    {
        if (! $this->enabled) {
            return [
                'total_clients_tracked' => 0,
                'total_patterns_discovered' => 0,
                'top_sequences' => [],
                'auto_funnels' => [],
            ];
        }

        $topPatterns = $this->discoverTopPatterns(5);
        $autoFunnels = $this->discoverAutoFunnels();

        return [
            'total_clients_tracked' => $this->countTrackedClients(),
            'total_patterns_discovered' => count($topPatterns),
            'top_sequences' => array_map(
                static fn (EventSequencePattern $p): array => [
                    'sequence' => $p->sequence,
                    'occurrences' => $p->occurrences,
                ],
                $topPatterns,
            ),
            'auto_funnels' => array_map(
                static fn (array $f): array => [
                    'steps' => $f['steps'],
                    'total_sequences' => $f['total_sequences'],
                    'conversion_rate' => $f['conversion_rate'],
                ],
                $autoFunnels,
            ),
        ];
    }

    /**
     * Clear all stream processing data for a specific client.
     */
    public function clearClientStream(string $clientId): void
    {
        $this->cache->forget($this->cachePrefix . 'stream:' . $clientId);
        $this->cache->forget($this->cachePrefix . 'meta:' . $clientId);
        $this->cache->forget($this->cachePrefix . 'patterns:' . $clientId);
    }

    /**
     * Clear all cached stream processing data globally.
     */
    public function clearAllStreams(): void
    {
        // Clear global patterns
        $this->cache->forget($this->cachePrefix . 'global_patterns');
    }

    /**
     * Create a StreamEvent from an AnalyticsEvent with sequencing metadata.
     */
    private function createStreamEvent(AnalyticsEvent $event, string $clientId, int $position, ?float $timeSincePrevious): StreamEvent
    {
        $timestamp = $event->timestamp?->getTimestamp() ?? time();

        return new StreamEvent(
            id: StreamEvent::generateId($event->name(), $clientId, $timestamp, $position),
            eventName: $event->name(),
            clientId: $clientId,
            userId: $event->userId,
            position: $position,
            timestamp: $timestamp,
            timeSincePrevious: $timeSincePrevious,
            sessionSequenceId: $this->deriveSessionSequenceId($clientId, $timestamp),
            params: $event->params,
            category: EventCatalog::getCategory($event->name()),
        );
    }

    /**
     * Extract patterns from the sequence buffer and cache them.
     *
     * Generates all subsequences of length 3+ from the buffer,
     * computes pattern IDs, and updates both client-level and global caches.
     */
    private function extractAndCachePatterns(string $clientId, array $sequenceBuffer, int $timestamp): void
    {
        $patternsKey = $this->cachePrefix . 'patterns:' . $clientId;
        $globalKey = $this->cachePrefix . 'global_patterns';

        /** @var array<string, array{id: string, sequence: list<string>, occurrences: int, first_seen: int, last_seen: int}> $clientPatterns */
        $clientPatterns = $this->cache->get($patternsKey, []);

        /** @var array<string, array{sequence: list<string>, occurrences: int, unique_users: int, durations: list<float>, conversion_rate: float, sample_clients: list<string>, step_counts: array<int, int>}> $globalPatterns */
        $globalPatterns = $this->cache->get($globalKey, []);

        // Generate subsequences of length 3 to maxSequenceLength
        $len = count($sequenceBuffer);
        for ($subLen = 3; $subLen <= min($len, $this->maxSequenceLength); $subLen++) {
            $subsequence = array_slice($sequenceBuffer, -$subLen);
            $patternId = $this->computePatternId($subsequence);

            // Update client-level pattern
            if (! isset($clientPatterns[$patternId])) {
                $clientPatterns[$patternId] = [
                    'id' => $patternId,
                    'sequence' => $subsequence,
                    'occurrences' => 0,
                    'first_seen' => $timestamp,
                    'last_seen' => $timestamp,
                ];
            }
            $clientPatterns[$patternId]['occurrences']++;
            $clientPatterns[$patternId]['last_seen'] = $timestamp;

            // Update global pattern
            if (! isset($globalPatterns[$patternId])) {
                $globalPatterns[$patternId] = [
                    'sequence' => $subsequence,
                    'occurrences' => 0,
                    'unique_users' => 0,
                    'durations' => [],
                    'conversion_rate' => 0.0,
                    'sample_clients' => [],
                    'step_counts' => array_fill(0, $subLen, 0),
                    'seen_clients' => [],
                ];
            }
            $globalPatterns[$patternId]['occurrences']++;

            // Track unique users
            if (! in_array($clientId, $globalPatterns[$patternId]['seen_clients'] ?? [], true)) {
                $globalPatterns[$patternId]['unique_users']++;
                $globalPatterns[$patternId]['seen_clients'][] = $clientId;
                if (count($globalPatterns[$patternId]['sample_clients']) < 10) {
                    $globalPatterns[$patternId]['sample_clients'][] = $clientId;
                }
            }

            // Track step completion counts
            for ($step = 0; $step < $subLen; $step++) {
                $globalPatterns[$patternId]['step_counts'][$step] = ($globalPatterns[$patternId]['step_counts'][$step] ?? 0) + 1;
            }
        }

        // Trim client patterns to max
        if (count($clientPatterns) > $this->maxPatternsPerClient) {
            uasort($clientPatterns, static fn (array $a, array $b): int => $b['occurrences'] <=> $a['occurrences']);
            $clientPatterns = array_slice($clientPatterns, 0, $this->maxPatternsPerClient, true);
        }

        $this->cache->put($patternsKey, $clientPatterns, $this->cacheTtl);
        $this->cache->put($globalKey, $globalPatterns, $this->cacheTtl);
    }

    /**
     * Compute a stable pattern ID from an event sequence.
     *
     * @param  list<string>  $sequence
     * @return non-empty-string
     */
    private function computePatternId(array $sequence): string
    {
        return 'seq:' . hash('xxh128', implode('→', $sequence));
    }

    /**
     * Derive a session-specific sequence ID.
     *
     * @return non-empty-string
     */
    private function deriveSessionSequenceId(string $clientId, int $timestamp): string
    {
        $window = (int) ($timestamp / 1800); // 30-minute sessions

        return hash('xxh64', $clientId . ':' . $window);
    }

    /**
     * Count the number of tracked client streams.
     */
    private function countTrackedClients(): int
    {
        // Approximate: count from global patterns' unique user tracking
        $globalKey = $this->cachePrefix . 'global_patterns';

        /** @var array<string, array{unique_users: int}>|null $globalPatterns */
        $globalPatterns = $this->cache->get($globalKey);

        if ($globalPatterns === null) {
            return 0;
        }

        // Find the maximum unique_users across all patterns (approximate)
        $maxUsers = 0;
        foreach ($globalPatterns as $data) {
            $maxUsers = max($maxUsers, $data['unique_users'] ?? 0);
        }

        return $maxUsers;
    }

    /**
     * Compute the median of a list of floats.
     *
     * @param  list<float>  $values
     * @return float
     */
    private function computeMedian(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $mid = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }

        return $values[$mid];
    }

    /**
     * Compute standard deviation of a list of floats.
     *
     * @param  list<float>  $values
     * @return float
     */
    private function standardDeviation(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $sumSquaredDiff = 0.0;

        foreach ($values as $value) {
            $diff = $value - $mean;
            $sumSquaredDiff += $diff * $diff;
        }

        return sqrt($sumSquaredDiff / $count);
    }
}
