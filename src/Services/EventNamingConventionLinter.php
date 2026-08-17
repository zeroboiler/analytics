<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event Naming Convention Linter & Quality Enforcer.
 *
 * Enforces consistent event naming conventions across the analytics
 * event catalog. Provides linting, suggestions, and quality scoring
 * for event names to maintain catalog hygiene at scale.
 *
 * Naming rules (configurable):
 * 1. Format: snake_case (default) or camelCase (configurable)
 * 2. Pattern: verb_noun or adjective_noun (preferred)
 * 3. Length: between 3 and 100 characters
 * 4. Prefix: no reserved prefixes (e.g., 'google_', 'fb_', 'ga_')
 * 5. Suffix: no numeric suffixes for variants (use params instead)
 * 6. Reserved words: no collision with platform event names
 * 7. Uniqueness: no duplicates (case-insensitive)
 * 8. Consistency: events in same category follow same naming pattern
 *
 * This enables SaaS teams to:
 * - Catch naming inconsistencies before they cause data quality issues
 * - Maintain a clean, discoverable event catalog
 * - Automate naming reviews in CI/CD pipelines
 * - Generate migration suggestions when renaming events
 *
 * @see \ZeroBoiler\Analytics\Services\EventSemanticClassifierService
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 *
 * @since 222.0.0
 *
 * @phpstan-type NamingViolation array{
 *     rule: string,
 *     severity: 'error'|'warning'|'info',
 *     message: string,
 *     suggestion: string|null
 * }
 *
 * @phpstan-type NamingReport array{
 *     total_events: int,
 *     total_violations: int,
 *     violations_by_severity: array{error: int, warning: int, info: int},
 *     violations_by_rule: array<string, int>,
 *     error_events: list<string>,
 *     warning_events: list<string>,
 *     quality_score: float,
 *     quality_grade: string,
 *     violations: array<string, list<NamingViolation>>
 * }
 */
final class EventNamingConventionLinter
{
    /** @var array<string, mixed> */
    private array $rules;

    private const CACHE_PREFIX = 'zb_naming_lint_';

    private const CACHE_TTL = 3600;

    /** @var list<string> */
    private const RESERVED_PREFIXES = [
        'google_', 'ga_', 'gtag_', 'fb_', 'meta_', 'tiktok_',
        'linkedin_', 'gtm_', 'mp_', 'ph_', 'amplitude_',
        'mixpanel_', 'plausible_', '_',
    ];

    /** @var list<string> */
    private const RESERVED_WORDS = [
        'event', 'track', 'page', 'click', 'impression', 'conversion',
        'session', 'user', 'debug', 'test', 'log', 'error',
    ];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ) {
        $this->rules = $this->loadRules();
    }

    /**
     * Lint a single event name against all naming conventions.
     *
     * @param  string  $eventName  Event name to lint
     * @param  string|null  $category  Optional category for category-specific rules
     * @return list<NamingViolation>
     */
    public function lint(string $eventName, ?string $category = null): array
    {
        $violations = [];

        // Rule 1: Format check (snake_case)
        $this->checkFormat($eventName, $violations);

        // Rule 2: Pattern check (verb_noun preferred)
        $this->checkPattern($eventName, $violations);

        // Rule 3: Length check
        $this->checkLength($eventName, $violations);

        // Rule 4: Reserved prefix check
        $this->checkReservedPrefix($eventName, $violations);

        // Rule 5: Numeric suffix check
        $this->checkNumericSuffix($eventName, $violations);

        // Rule 6: Reserved word check
        $this->checkReservedWords($eventName, $violations);

        // Rule 7: Uniqueness (only if event is not in catalog)
        $this->checkUniqueness($eventName, $violations);

        // Rule 8: Category consistency (if category provided)
        if ($category !== null) {
            $this->checkCategoryConsistency($eventName, $category, $violations);
        }

        // Rule 9: Namespace depth check (too many underscores)
        $this->checkNamespaceDepth($eventName, $violations);

        // Rule 10: Special characters
        $this->checkSpecialCharacters($eventName, $violations);

        return $violations;
    }

    /**
     * Lint all events in the catalog and generate a comprehensive report.
     *
     * @param  list<string>|null  $eventNames  Specific events to lint (null = all catalog events)
     * @return NamingReport
     */
    public function lintReport(?array $eventNames = null): array
    {
        $cacheKey = self::CACHE_PREFIX . 'report_' . md5(json_encode($eventNames ?? 'all'));

        /** @var NamingReport|null $cached */
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $events = $eventNames ?? EventCatalog::names();
        $allViolations = [];
        $violationsByRule = [];
        $errors = [];
        $warnings = [];
        $totalViolations = 0;

        foreach ($events as $name) {
            $category = EventCatalog::get($name)['category'] ?? null;
            $violations = $this->lint($name, $category);

            if ($violations !== []) {
                $allViolations[$name] = $violations;
                $totalViolations += count($violations);

                foreach ($violations as $v) {
                    $rule = $v['rule'];
                    $violationsByRule[$rule] = ($violationsByRule[$rule] ?? 0) + 1;

                    if ($v['severity'] === 'error') {
                        $errors[] = $name;
                    } elseif ($v['severity'] === 'warning') {
                        $warnings[] = $name;
                    }
                }
            }
        }

        $errorCount = count(array_unique($errors));
        $warningCount = count(array_unique($warnings));
        $infoCount = 0;

        foreach ($allViolations as $eventViolations) {
            foreach ($eventViolations as $v) {
                if ($v['severity'] === 'info') {
                    $infoCount++;
                }
            }
        }

        $total = count($events);
        $qualityScore = $this->calculateQualityScore($total, $totalViolations, $errorCount);
        $qualityGrade = $this->assignGrade($qualityScore);

        $report = [
            'total_events' => $total,
            'total_violations' => $totalViolations,
            'violations_by_severity' => [
                'error' => $errorCount,
                'warning' => $warningCount,
                'info' => $infoCount,
            ],
            'violations_by_rule' => $violationsByRule,
            'error_events' => array_unique($errors),
            'warning_events' => array_unique($warnings),
            'quality_score' => $qualityScore,
            'quality_grade' => $qualityGrade,
            'violations' => $allViolations,
        ];

        $this->cache->put($cacheKey, $report, self::CACHE_TTL);

        return $report;
    }

    /**
     * Suggest an improved name for an event based on naming conventions.
     *
     * @param  string  $eventName
     * @param  string|null  $category
     * @return list<array{suggestion: string, reason: string}>
     */
    public function suggestName(string $eventName, ?string $category = null): array
    {
        $suggestions = [];

        // Convert camelCase to snake_case
        $snakeCased = strtolower(
            preg_replace('/([a-z])([A-Z])/', '$1_$2', $eventName) ?? $eventName,
        );

        if ($snakeCased !== $eventName && $snakeCased !== strtolower($eventName)) {
            $suggestions[] = [
                'suggestion' => $snakeCased,
                'reason' => 'Convert camelCase to snake_case convention',
            ];
        }

        // Convert kebab-case to snake_case
        $kebabConverted = str_replace('-', '_', $eventName);

        if ($kebabConverted !== $eventName && ! str_contains($eventName, '_')) {
            $suggestions[] = [
                'suggestion' => $kebabConverted,
                'reason' => 'Convert kebab-case to snake_case convention',
            ];
        }

        // Remove leading/trailing underscores
        $trimmed = trim($eventName, '_');

        if ($trimmed !== $eventName) {
            $suggestions[] = [
                'suggestion' => $trimmed,
                'reason' => 'Remove leading/trailing underscores',
            ];
        }

        // Remove numeric suffixes
        if (preg_match('/^(.+?)_?\d+$/', $eventName, $matches)) {
            $baseName = $matches[1];

            if ($baseName !== $eventName) {
                $suggestions[] = [
                    'suggestion' => $baseName,
                    'reason' => 'Remove numeric suffix — use event params for variants',
                ];
            }
        }

        // Remove consecutive underscores
        $cleaned = preg_replace('/_+/', '_', $eventName) ?? $eventName;

        if ($cleaned !== $eventName) {
            $suggestions[] = [
                'suggestion' => $cleaned,
                'reason' => 'Replace consecutive underscores with single underscore',
            ];
        }

        // Check if it should be an alias of a catalog event
        $classifier = new EventSemanticClassifierService($this->cache, $this->config);
        $alias = $classifier->resolveAlias($eventName);

        if ($alias !== null && $alias !== $eventName) {
            $suggestions[] = [
                'suggestion' => $alias,
                'reason' => 'Use canonical catalog event name (detected as alias)',
            ];
        }

        // Category-specific suggestions
        if ($category !== null) {
            $categorySuggestions = $this->suggestCategoryName($eventName, $category);

            foreach ($categorySuggestions as $s) {
                $suggestions[] = $s;
            }
        }

        // Deduplicate suggestions
        $seen = [];
        $unique = [];

        foreach ($suggestions as $s) {
            if (! isset($seen[$s['suggestion']])) {
                $seen[$s['suggestion']] = true;
                $unique[] = $s;
            }
        }

        return $unique;
    }

    /**
     * Get the naming convention rules configuration.
     *
     * @return array{format: string, max_length: int, min_length: int, max_parts: int, allow_numeric_suffix: bool, strict_pattern: bool}
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Invalidate lint cache.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'report_all');
    }

    /**
     * Rule 1: Check format (snake_case by default).
     *
     * @param  string  $eventName
     * @param  list<NamingViolation>  $violations
     */
    private function checkFormat(string $eventName, array &$violations): void
    {
        $format = $this->rules['format'] ?? 'snake_case';

        if ($format === 'snake_case') {
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $eventName)) {
                $violations[] = [
                    'rule' => 'format',
                    'severity' => 'error',
                    'message' => "Event name '{$eventName}' is not valid snake_case. Use lowercase letters, numbers, and underscores only.",
                    'suggestion' => strtolower(
                        preg_replace('/([a-z])([A-Z])/', '$1_$2', $eventName) ?? $eventName,
                    ),
                ];
            }
        } elseif ($format === 'camelCase') {
            if (! preg_match('/^[a-z][a-zA-Z0-9]*$/', $eventName)) {
                $violations[] = [
                    'rule' => 'format',
                    'severity' => 'error',
                    'message' => "Event name '{$eventName}' is not valid camelCase.",
                    'suggestion' => null,
                ];
            }
        }
    }

    /**
     * Rule 2: Check naming pattern (verb_noun preferred).
     *
     * @param  string  $eventName
     * @param  list<NamingViolation>  $violations
     */
    private function checkPattern(string $eventName, array &$violations): void
    {
        $strict = (bool) ($this->rules['strict_pattern'] ?? false);

        if (! $strict) {
            // Only warn if pattern check is strict
            $parts = explode('_', $eventName);

            if (count($parts) < 2) {
                $violations[] = [
                    'rule' => 'pattern',
                    'severity' => 'info',
                    'message' => "Event name '{$eventName}' has only one word part. Consider using verb_noun pattern (e.g., 'user_signup', 'item_viewed').",
                    'suggestion' => null,
                ];
            }

            return;
        }

        // Strict mode: require verb_noun or adjective_noun pattern
        $parts = explode('_', $eventName);

        if (count($parts) < 2) {
            $violations[] = [
                'rule' => 'pattern',
                'severity' => 'warning',
                'message' => "Event name '{$eventName}' should follow verb_noun or adjective_noun pattern.",
                'suggestion' => null,
            ];
        }
    }

    /**
     * Rule 3: Check length constraints.
     *
     * @param  string  $eventName
     * @param  list<NamingViolation>  $violations
     */
    private function checkLength(string $eventName, array &$violations): void
    {
        $minLength = (int) ($this->rules['min_length'] ?? 3);
        $maxLength = (int) ($this->rules['max_length'] ?? 100);
        $length = strlen($eventName);

        if ($length < $minLength) {
            $violations[] = [
                'rule' => 'length',
                'severity' => 'error',
                'message' => "Event name '{$eventName}' is too short ({$length} chars, minimum {$minLength}).",
                'suggestion' => null,
            ];
        }

        if ($length > $maxLength) {
            $violations[] = [
                'rule' => 'length',
                'severity' => 'warning',
                'message' => "Event name '{$eventName}' is too long ({$length} chars, maximum {$maxLength}). Consider shortening.",
                'suggestion' => null,
            ];
        }
    }

    /**
     * Rule 4: Check for reserved prefixes.
     *
     * @param  string  $eventName
     * @param  list<NamingViolation>  $violations
     */
    private function checkReservedPrefix(string $eventName, array &$violations): void
    {
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($eventName, $prefix)) {
                $violations[] = [
                    'rule' => 'reserved_prefix',
                    'severity' => 'error',
                    'message' => "Event name '{$eventName}' uses reserved prefix '{$prefix}'. This may conflict with platform-specific events.",
                    'suggestion' => substr($eventName, strlen($prefix)),
                ];
            }
        }
    }

    /**
     * Rule 5: Check for numeric suffixes.
     *
     * @param  string  $eventName
     * @param list<NamingViolation>  $violations
     */
    private function checkNumericSuffix(string $eventName, array &$violations): void
    {
        $allowNumericSuffix = (bool) ($this->rules['allow_numeric_suffix'] ?? false);

        if ($allowNumericSuffix) {
            return;
        }

        if (preg_match('/_\d+$/', $eventName)) {
            $violations[] = [
                'rule' => 'numeric_suffix',
                'severity' => 'warning',
                'message' => "Event name '{$eventName}' has a numeric suffix. Use event params for variants instead.",
                'suggestion' => preg_replace('/_\d+$/', '', $eventName),
            ];
        }
    }

    /**
     * Rule 6: Check for reserved words.
     *
     * @param  string  $eventName
     * @param  list<NamingViolation>  $violations
     */
    private function checkReservedWords(string $eventName, array &$violations): void
    {
        $parts = explode('_', $eventName);

        foreach ($parts as $part) {
            if (in_array($part, self::RESERVED_WORDS, true)) {
                $violations[] = [
                    'rule' => 'reserved_word',
                    'severity' => 'info',
                    'message' => "Event name '{$eventName}' contains reserved word '{$part}'. Consider a more specific name.",
                    'suggestion' => null,
                ];
            }
        }
    }

    /**
     * Rule 7: Check for duplicate event names (case-insensitive).
     *
     * @param  string  $eventName
     * @param  list<NamingViolation>  $violations
     */
    private function checkUniqueness(string $eventName, array &$violations): void
    {
        // Only check if the event is NOT already in the catalog
        // (catalog events are already unique by definition)
        if (EventCatalog::has($eventName)) {
            return;
        }

        $normalized = strtolower($eventName);

        // Check against all catalog event names (case-insensitive)
        foreach (EventCatalog::names() as $catalogName) {
            if (strtolower($catalogName) === $normalized && $catalogName !== $eventName) {
                $violations[] = [
                    'rule' => 'uniqueness',
                    'severity' => 'error',
                    'message' => "Event name '{$eventName}' conflicts with catalog event '{$catalogName}' (case-insensitive match).",
                    'suggestion' => $catalogName,
                ];
            }
        }
    }

    /**
     * Rule 8: Check category-specific naming consistency.
     *
     * @param  string  $eventName
     * @param  string  $category
     * @param  list<NamingViolation>  $violations
     */
    private function checkCategoryConsistency(string $eventName, string $category, array &$violations): void
    {
        $categoryPrefixes = [
            'ecommerce' => ['view', 'add', 'remove', 'begin', 'select', 'checkout', 'purchase', 'refund', 'abandoned'],
            'saas' => ['sign', 'login', 'logout', 'start', 'trial', 'subscribe', 'plan', 'feature', 'team', 'role', 'payment', 'account', 'password', 'profile', 'email', 'invite', 'billing', 'invoice', 'credit', 'subscription', 'cohort', 'integration'],
            'engagement' => ['page', 'scroll', 'click', 'form', 'search', 'share', 'error', 'time', 'screen', 'session', 'outbound', 'file', 'video', 'web', 'js', 'timing', 'content', 'onboarding', 'feedback', 'goal', 'consent', 'client', 'copy', 'hover', 'element', 'notification', 'ab_test', 'ad', 'performance'],
        ];

        $expectedPrefixes = $categoryPrefixes[$category] ?? [];

        if ($expectedPrefixes === []) {
            return;
        }

        $firstPart = explode('_', $eventName)[0] ?? '';

        if ($firstPart === '') {
            return;
        }

        // Info-level: suggest if first word doesn't match category expectations
        if (! in_array($firstPart, $expectedPrefixes, true)) {
            $violations[] = [
                'rule' => 'category_consistency',
                'severity' => 'info',
                'message' => "Event name '{$eventName}' in category '{$category}' starts with '{$firstPart}', which is uncommon for this category.",
                'suggestion' => null,
            ];
        }
    }

    /**
     * Rule 9: Check namespace depth (too many underscore-separated parts).
     *
     * @param  string  $eventName
     * @param  list<NamingViolation>  $violations
     */
    private function checkNamespaceDepth(string $eventName, array &$violations): void
    {
        $maxParts = (int) ($this->rules['max_parts'] ?? 5);
        $parts = explode('_', $eventName);

        if (count($parts) > $maxParts) {
            $violations[] = [
                'rule' => 'namespace_depth',
                'severity' => 'warning',
                'message' => "Event name '{$eventName}' has " . count($parts) . " parts (max {$maxParts}). Consider simplifying.",
                'suggestion' => null,
            ];
        }
    }

    /**
     * Rule 10: Check for special characters.
     *
     * @param  string  $eventName
     * @param  list<NamingViolation>  $violations
     */
    private function checkSpecialCharacters(string $eventName, array &$violations): void
    {
        if (preg_match('/[^a-zA-Z0-9_]/', $eventName)) {
            $specialChars = preg_replace('/[a-zA-Z0-9_]/', '', $eventName);
            $violations[] = [
                'rule' => 'special_characters',
                'severity' => 'error',
                'message' => "Event name '{$eventName}' contains special characters: '{$specialChars}'. Only letters, numbers, and underscores are allowed.",
                'suggestion' => preg_replace('/[^a-zA-Z0-9_]/', '_', $eventName),
            ];
        }
    }

    /**
     * Suggest category-specific event names.
     *
     * @param  string  $eventName
     * @param  string  $category
     * @return list<array{suggestion: string, reason: string}>
     */
    private function suggestCategoryName(string $eventName, string $category): array
    {
        $suggestions = [];

        // Common naming mistakes per category
        $commonMistakes = [
            'ecommerce' => [
                'buy' => 'purchase',
                'order' => 'purchase',
                'pay' => 'add_payment_info',
                'item' => 'view_item',
            ],
            'saas' => [
                'register' => 'sign_up',
                'sign_in' => 'login',
                'signout' => 'logout',
                'upgrade' => 'plan_upgrade',
                'downgrade' => 'plan_downgrade',
                'cancel' => 'cancellation',
            ],
            'engagement' => [
                'navigate' => 'page_view',
                'scroll' => 'scroll_depth',
                'tap' => 'click',
                'submit' => 'form_submit',
            ],
        ];

        $categoryMistakes = $commonMistakes[$category] ?? [];
        $parts = explode('_', $eventName);

        foreach ($parts as $part) {
            if (isset($categoryMistakes[$part])) {
                $suggested = str_replace($part, $categoryMistakes[$part], $eventName);

                if ($suggested !== $eventName) {
                    $suggestions[] = [
                        'suggestion' => $suggested,
                        'reason' => "Replace '{$part}' with canonical term '{$categoryMistakes[$part]}' for category '{$category}'",
                    ];
                }
            }
        }

        return $suggestions;
    }

    /**
     * Calculate the naming quality score.
     *
     * @param  int  $totalEvents
     * @param  int  $totalViolations
     * @param  int  $errorCount
     * @return float  Quality score between 0.0 and 1.0
     */
    private function calculateQualityScore(int $totalEvents, int $totalViolations, int $errorCount): float
    {
        if ($totalEvents === 0) {
            return 1.0;
        }

        $errorPenalty = ($errorCount / $totalEvents) * 0.5;
        $warningPenalty = (($totalViolations - $errorCount) / max($totalEvents, 1)) * 0.15;

        return max(0.0, min(1.0, 1.0 - $errorPenalty - $warningPenalty));
    }

    /**
     * Assign a letter grade based on quality score.
     *
     * @param  float  $score  Quality score between 0.0 and 1.0
     * @return string  Letter grade (A+ through F)
     */
    private function assignGrade(float $score): string
    {
        return match (true) {
            $score >= 0.98 => 'A+',
            $score >= 0.95 => 'A',
            $score >= 0.90 => 'A-',
            $score >= 0.85 => 'B+',
            $score >= 0.80 => 'B',
            $score >= 0.75 => 'B-',
            $score >= 0.70 => 'C+',
            $score >= 0.60 => 'C',
            $score >= 0.50 => 'C-',
            $score >= 0.40 => 'D',
            $score >= 0.20 => 'D-',
            default => 'F',
        };
    }

    /**
     * Load naming convention rules from config.
     *
     * @return array{format: string, max_length: int, min_length: int, max_parts: int, allow_numeric_suffix: bool, strict_pattern: bool}
     */
    private function loadRules(): array
    {
        $rulesConfig = $this->config->get('zeroboiler.analytics.naming_conventions', []);

        return [
            'format' => (string) ($rulesConfig['format'] ?? 'snake_case'),
            'max_length' => (int) ($rulesConfig['max_length'] ?? 100),
            'min_length' => (int) ($rulesConfig['min_length'] ?? 3),
            'max_parts' => (int) ($rulesConfig['max_parts'] ?? 5),
            'allow_numeric_suffix' => (bool) ($rulesConfig['allow_numeric_suffix'] ?? false),
            'strict_pattern' => (bool) ($rulesConfig['strict_pattern'] ?? false),
        ];
    }
}
