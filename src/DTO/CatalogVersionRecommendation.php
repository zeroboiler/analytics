<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing a semantic version recommendation for catalog changes.
 *
 * Produced by the EventCatalogVersioningEngine to communicate the recommended
 * version bump based on catalog diff analysis. Follows SemVer 2.0.0 rules:
 *
 * - MAJOR: Breaking changes (removed events, changed provider mappings, category moves)
 * - MINOR: New features (added events, new provider mappings)
 * - PATCH: Non-breaking fixes (documentation changes, internal refactors)
 *
 * @since 216.0.0
 */
final readonly class CatalogVersionRecommendation
{
    /**
     * @param  string  $recommended  Recommended version bump: 'major', 'minor', 'patch', 'none'
     * @param  string  $currentVersion  Current catalog version string (e.g. '215.0.0')
     * @param  string  $nextVersion  Computed next version string (e.g. '216.0.0')
     * @param  list<CatalogChangeImpact>  $changes  All detected changes with severity
     * @param  array{major: int, minor: int, patch: int}  $summary  Change count by severity
     * @param  string  $rationale  Human-readable explanation of the version decision
     * @param  bool  $hasBreaking  Whether any breaking changes were detected
     * @param  string|null  $releaseNotes  Auto-generated release notes markdown
     */
    public function __construct(
        public string $recommended,
        public string $currentVersion,
        public string $nextVersion,
        public array $changes,
        public array $summary,
        public string $rationale,
        public bool $hasBreaking,
        public ?string $releaseNotes = null,
    ): void {}

    /**
     * Create a no-change recommendation.
     *
     * @param  string  $currentVersion
     * @return self
     */
    public static function noChange(string $currentVersion): self
    {
        return new self(
            recommended: 'none',
            currentVersion: $currentVersion,
            nextVersion: $currentVersion,
            changes: [],
            summary: ['major' => 0, 'minor' => 0, 'patch' => 0],
            rationale: 'No catalog changes detected between snapshots.',
            hasBreaking: false,
            releaseNotes: null,
        );
    }

    /**
     * Serialize to array for JSON output.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'recommended' => $this->recommended,
            'current_version' => $this->currentVersion,
            'next_version' => $this->nextVersion,
            'changes' => array_map(fn(CatalogChangeImpact $c): array => $c->toArray(), $this->changes),
            'summary' => $this->summary,
            'rationale' => $this->rationale,
            'has_breaking' => $this->hasBreaking,
            'release_notes' => $this->releaseNotes,
        ];
    }

    /**
     * Deserialize from array.
     *
     * @param  array<string, mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $changes = array_map(
            fn(array $c): CatalogChangeImpact => CatalogChangeImpact::fromArray($c),
            (array) ($data['changes'] ?? []),
        );

        return new self(
            recommended: (string) ($data['recommended'] ?? 'none'),
            currentVersion: (string) ($data['current_version'] ?? ''),
            nextVersion: (string) ($data['next_version'] ?? ''),
            changes: $changes,
            summary: (array) ($data['summary'] ?? ['major' => 0, 'minor' => 0, 'patch' => 0]),
            rationale: (string) ($data['rationale'] ?? ''),
            hasBreaking: (bool) ($data['has_breaking'] ?? false),
            releaseNotes: isset($data['release_notes']) ? (string) $data['release_notes'] : null,
        );
    }

    /**
     * Get the release notes, or a default message if none generated.
     */
    public function releaseNotesOrEmpty(): string
    {
        return $this->releaseNotes ?? 'No changes detected.';
    }
}
