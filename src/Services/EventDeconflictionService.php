<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event Deconfliction Service.
 *
 * Detects and resolves event name collisions across multiple analytics
 * providers. When the same canonical event name maps to different provider-
 * specific names, or when two different canonical events map to the same
 * provider event, this service identifies and reports the conflicts.
 *
 * Useful in multi-provider deployments to ensure consistent tracking
 * across GA4, GTM, Meta Pixel, Plausible, and PostHog.
 */
final class EventDeconflictionService
{
    /** @var array<string, list<string>> */
    private array $conflicts = [];

    /** @var array<string, list<string>> */
    private array $warnings = [];

    private AnalyticsManager $manager;

    public function __construct(AnalyticsManager $manager): void
    {
        $this->manager = $manager;
    }

    /**
     * Run a full deconfliction analysis across all providers.
     *
     * @return array{conflicts: array<string, list<string>>, warnings: array<string, list<string>>, summary: array{total_conflicts: int, total_warnings: int, provider_collision_counts: array<string, int>}}
     */
    public function analyze(): array
    {
        $this->conflicts = [];
        $this->warnings = [];

        $this->detectProviderNameCollisions();
        $this->detectReverseCollisions();
        $this->detectCanonicalNameConflicts();

        return $this->buildReport();
    }

    /**
     * Check if any conflicts exist.
     */
    public function hasConflicts(): bool
    {
        return count($this->conflicts) > 0;
    }

    /**
     * Get the conflict details.
     *
     * @return array<string, list<string>>
     */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * Get the warning details.
     *
     * @return array<string, list<string>>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Detect provider-specific name collisions (two canonical events → same provider event).
     *
     * Example: 'purchase' and 'subscription' both map to 'Purchase' in Meta Pixel.
     */
    private function detectProviderNameCollisions(): void
    {
        $providers = ['ga4', 'meta', 'posthog', 'plausible'];

        foreach ($providers as $provider) {
            $providerMap = [];

            foreach (EventCatalog::all() as $canonicalName => $entry) {
                $providerEventName = $entry[$provider] ?? null;

                if ($providerEventName === null || $providerEventName === '') {
                    continue;
                }

                $providerMap[$providerEventName][] = $canonicalName;
            }

            // Find collisions (same provider event name used by multiple canonical events)
            foreach ($providerMap as $providerName => $canonicalNames) {
                if (count($canonicalNames) > 1) {
                    $key = "provider_collision:{$provider}:{$providerName}";
                    $this->conflicts[$key] = [
                        "Multiple canonical events map to the same {$provider} event '{$providerName}': " . implode(', ', $canonicalNames),
                    ];
                }
            }
        }
    }

    /**
     * Detect reverse collisions (one canonical event maps to multiple provider events
     * that have conflicting semantics).
     */
    private function detectReverseCollisions(): void
    {
        foreach (EventCatalog::all() as $canonicalName => $entry) {
            $providerNames = [];

            if (!empty($entry['ga4']) && $entry['ga4'] !== $canonicalName) {
                $providerNames['ga4'] = $entry['ga4'];
            }

            if (!empty($entry['meta']) && $entry['meta'] !== null) {
                $providerNames['meta'] = $entry['meta'];
            }

            if (!empty($entry['posthog']) && $entry['posthog'] !== null && $entry['posthog'] !== $canonicalName) {
                $providerNames['posthog'] = $entry['posthog'];
            }

            // Check if mapped provider events are actually used by other canonical events
            foreach ($providerNames as $provider => $mappedName) {
                $conflictingEvents = [];

                foreach (EventCatalog::all() as $otherCanonical => $otherEntry) {
                    if ($otherCanonical === $canonicalName) {
                        continue;
                    }

                    $otherMapped = $otherEntry[$provider] ?? null;

                    if ($otherMapped !== null && $otherMapped === $mappedName && $otherMapped !== $otherCanonical) {
                        $conflictingEvents[] = $otherCanonical;
                    }
                }

                if (count($conflictingEvents) > 0) {
                    $key = "reverse_collision:{$provider}:{$canonicalName}";
                    $this->warnings[$key] = [
                        "'{$canonicalName}' maps to '{$mappedName}' in {$provider}, which is also the target of: " . implode(', ', $conflictingEvents),
                    ];
                }
            }
        }
    }

    /**
     * Detect canonical names that are too similar (potential typos).
     */
    private function detectCanonicalNameConflicts(): void
    {
        $names = EventCatalog::names();
        $checked = [];

        foreach ($names as $name) {
            foreach ($names as $otherName) {
                if ($name === $otherName) {
                    continue;
                }

                $pair = implode('|', [$name, $otherName]);

                if (in_array($pair, $checked, true)) {
                    continue;
                }

                $pair = implode('|', [$otherName, $name]);
                $checked[] = $pair;

                $distance = $this->levenshteinDistance($name, $otherName);

                // Warn on very similar names (edit distance ≤ 2)
                if ($distance <= 2 && $distance > 0) {
                    $key = "similar_names:{$name}:{$otherName}";
                    $this->warnings[$key] = [
                        "Similar event names detected (edit distance {$distance}): '{$name}' and '{$otherName}'",
                    ];
                }
            }
        }
    }

    /**
     * Compute Levenshtein distance between two strings.
     */
    private function levenshteinDistance(string $a, string $b): int
    {
        $lenA = mb_strlen($a);
        $lenB = mb_strlen($b);

        if ($lenA === 0) {
            return $lenB;
        }

        if ($lenB === 0) {
            return $lenA;
        }

        $prevRow = range(0, $lenB);

        for ($i = 1; $i <= $lenA; $i++) {
            $currentRow = [$i];

            for ($j = 1; $j <= $lenB; $j++) {
                $cost = mb_substr($a, $i - 1, 1) === mb_substr($b, $j - 1, 1) ? 0 : 1;
                $currentRow[] = min(
                    $currentRow[$j - 1] + 1,
                    $prevRow[$j] + 1,
                    $prevRow[$j - 1] + $cost,
                );
            }

            $prevRow = $currentRow;
        }

        return $prevRow[$lenB];
    }

    /**
     * Build the analysis report.
     *
     * @return array{conflicts: array<string, list<string>>, warnings: array<string, list<string>>, summary: array{total_conflicts: int, total_warnings: int, provider_collision_counts: array<string, int>}}
     */
    private function buildReport(): array
    {
        $providerCounts = ['ga4' => 0, 'meta' => 0, 'posthog' => 0, 'plausible' => 0, 'similar_names' => 0];

        foreach (array_keys($this->conflicts) as $key) {
            if (str_starts_with($key, 'provider_collision:')) {
                $parts = explode(':', $key);
                $provider = $parts[1] ?? 'unknown';

                if (isset($providerCounts[$provider])) {
                    $providerCounts[$provider]++;
                }
            }
        }

        $similarCount = 0;
        foreach (array_keys($this->warnings) as $key) {
            if (str_starts_with($key, 'similar_names:')) {
                $similarCount++;
            }
        }
        $providerCounts['similar_names'] = $similarCount;

        return [
            'conflicts' => $this->conflicts,
            'warnings' => $this->warnings,
            'summary' => [
                'total_conflicts' => count($this->conflicts),
                'total_warnings' => count($this->warnings),
                'provider_collision_counts' => $providerCounts,
            ],
        ];
    }
}
