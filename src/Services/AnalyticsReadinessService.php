<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
/**
 * SaaS Starter readiness validator for production deployments.
 *
 * Performs a comprehensive checklist validation covering required and
 * recommended settings for a production-ready analytics deployment.
 * Each check is scored as pass, warn, or fail with descriptive messages.
 *
 * Checks include provider configuration, consent defaults, queue setup,
 * identity tracking, validation settings, GDPR compliance, and more.
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsHealthService
 *
 * @since 1.0.0
 */
final class AnalyticsReadinessService
{
    /**
     * @var list<ReadinessCheck>
     */
    private array $requiredChecks;

    /**
     * @var list<ReadinessCheck>
     */
    private array $recommendedChecks;

    private int $minimumScore;

    private int $cacheTtl;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ){
        $readinessConfig = $config->get('zeroboiler.analytics.readiness', []);
        /** @var array{enabled?: bool, minimum_score?: int, cache_ttl?: int, required_checks?: list<string>, recommended_checks?: list<string>} $readinessConfig */

        $this->minimumScore = (int) ($readinessConfig['minimum_score'] ?? 80);
        $this->cacheTtl = (int) ($readinessConfig['cache_ttl'] ?? 300);

        $analytics = $config->get('zeroboiler.analytics', []);

        $requiredNames = $readinessConfig['required_checks'] ?? $this->defaultRequiredChecks();
        $this->requiredChecks = $this->buildChecks($requiredNames, $analytics);

        $recommendedNames = $readinessConfig['recommended_checks'] ?? $this->defaultRecommendedChecks();
        $this->recommendedChecks = $this->buildChecks($recommendedNames, $analytics);
    }

    /**
     * Run the full readiness assessment.
     *
     * @return ReadinessReport
     */
    public function assess(): ReadinessReport
    {
        $results = [];

        // Run required checks
        foreach ($this->requiredChecks as $check) {
            $results[$check->name] = $check->evaluate();
        }

        // Run recommended checks
        foreach ($this->recommendedChecks as $check) {
            $results[$check->name] = $check->evaluate();
        }

        return $this->compileReport($results);
    }

    /**
     * Run the readiness assessment with caching.
     *
     * @return ReadinessReport
     */
    public function assessCached(): ReadinessReport
    {
        try {
            $cached = $this->cache->get('zb_readiness_report');

            if (is_string($cached) && $cached !== '') {
                /** @var ReadinessReport $report */
                $report = unserialize($cached, ['allowed_classes' => [ReadinessReport::class, CheckResult::class]]);

                return $report;
            }
        } catch (\Throwable $e) {
            // Fall through to fresh assessment
        }

        $report = $this->assess();

        try {
            $this->cache->put('zb_readiness_report', serialize($report), $this->cacheTtl);
        } catch (\Throwable $e) {
            // Silently fail
        }

        return $report;
    }

    /**
     * Invalidate the cached readiness report.
     */
    public function invalidateCache(): void
    {
        try {
            $this->cache->forget('zb_readiness_report');
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Get default required check names.
     *
     * @return list<string>
     */
    private function defaultRequiredChecks(): array
    {
        return [
            'providers_configured',
            'consent_default_set',
            'queue_configured',
            'identity_cookie_set',
            'event_validation_active',
            'debug_disabled',
            'replay_enabled',
            'dedup_active',
        ];
    }

    /**
     * Get default recommended check names.
     *
     * @return list<string>
     */
    private function defaultRecommendedChecks(): array
    {
        return [
            'pii_sanitization',
            'consent_logging',
            'gdpr_ip_anonymization',
            'attribution_tracking',
            'health_score_enabled',
            'error_tracking_client',
            'performance_budget',
        ];
    }

    /**
     * Build check instances from names and config.
     *
     * @param  list<string>  $names
     * @param  array<string, mixed>  $analytics
     * @return list<ReadinessCheck>
     */
    private function buildChecks(array $names, array $analytics): array
    {
        $checks = [];

        foreach ($names as $name) {
            $checks[] = $this->createCheck($name, $analytics);
        }

        return $checks;
    }

    /**
     * Create a single readiness check instance.
     *
     * @param  string  $name
     * @param  array<string, mixed>  $analytics
     */
    private function createCheck(string $name, array $analytics): ReadinessCheck
    {
        return match ($name) {
            'providers_configured' => new ReadinessCheck(
                name: $name,
                label: 'At least one provider is enabled and configured',
                evaluator: fn (): CheckResult => $this->checkProvidersConfigured($analytics),
            ),
            'consent_default_set' => new ReadinessCheck(
                name: $name,
                label: 'Consent default is explicitly configured',
                evaluator: fn (): CheckResult => $this->checkConsentDefault($analytics),
            ),
            'queue_configured' => new ReadinessCheck(
                name: $name,
                label: 'Queue driver is available for async dispatch',
                evaluator: fn (): CheckResult => $this->checkQueueConfigured($analytics),
            ),
            'identity_cookie_set' => new ReadinessCheck(
                name: $name,
                label: 'Identity tracking cookie is configured',
                evaluator: fn (): CheckResult => $this->checkIdentityCookie($analytics),
            ),
            'event_validation_active' => new ReadinessCheck(
                name: $name,
                label: 'Event validation and deduplication are active',
                evaluator: fn (): CheckResult => $this->checkEventValidation($analytics),
            ),
            'debug_disabled' => new ReadinessCheck(
                name: $name,
                label: 'Debug mode is disabled in production',
                evaluator: fn (): CheckResult => $this->checkDebugDisabled($analytics),
            ),
            'replay_enabled' => new ReadinessCheck(
                name: $name,
                label: 'Event replay is enabled for reliability',
                evaluator: fn (): CheckResult => $this->checkReplayEnabled($analytics),
            ),
            'dedup_active' => new ReadinessCheck(
                name: $name,
                label: 'Event deduplication is active',
                evaluator: fn (): CheckResult => $this->checkDedupActive($analytics),
            ),
            'pii_sanitization' => new ReadinessCheck(
                name: $name,
                label: 'PII sanitization is enabled',
                evaluator: fn (): CheckResult => $this->checkPiiSanitization($analytics),
            ),
            'consent_logging' => new ReadinessCheck(
                name: $name,
                label: 'Consent audit logging is enabled',
                evaluator: fn (): CheckResult => $this->checkConsentLogging($analytics),
            ),
            'gdpr_ip_anonymization' => new ReadinessCheck(
                name: $name,
                label: 'GDPR IP anonymization is configured',
                evaluator: fn (): CheckResult => $this->checkGdprIpAnonymization($analytics),
            ),
            'attribution_tracking' => new ReadinessCheck(
                name: $name,
                label: 'UTM attribution tracking is enabled',
                evaluator: fn (): CheckResult => $this->checkAttributionTracking($analytics),
            ),
            'health_score_enabled' => new ReadinessCheck(
                name: $name,
                label: 'SaaS health score tracking is enabled',
                evaluator: fn (): CheckResult => $this->checkHealthScoreEnabled($analytics),
            ),
            'error_tracking_client' => new ReadinessCheck(
                name: $name,
                label: 'Client-side error tracking is configured',
                evaluator: fn (): CheckResult => $this->checkErrorTrackingClient($analytics),
            ),
            'performance_budget' => new ReadinessCheck(
                name: $name,
                label: 'Performance budget is configured',
                evaluator: fn (): CheckResult => $this->checkPerformanceBudget($analytics),
            ),
            default => new ReadinessCheck(
                name: $name,
                label: "Unknown check: {$name}",
                evaluator: fn (): CheckResult => new CheckResult(status: 'warn', message: "Unknown check: {$name}"),
            ),
        };
    }

    /**
     * Compile check results into a readiness report.
     *
     * @param  array<string, CheckResult>  $results
     */
    private function compileReport(array $results): ReadinessReport
    {
        $passCount = 0;
        $warnCount = 0;
        $failCount = 0;
        $totalChecks = count($results);
        $requiredTotal = count($this->requiredChecks);

        $requiredFails = 0;

        foreach ($results as $name => $result) {
            match ($result->status) {
                'pass' => $passCount++,
                'warn' => $warnCount++,
                'fail' => $failCount++,
                default => null,
            };

            foreach ($this->requiredChecks as $check) {
                if ($check->name === $name && $result->status === 'fail') {
                    $requiredFails++;
                }
            }
        }

        $score = $totalChecks > 0 ? (int) round(($passCount / $totalChecks) * 100) : 0;
        $grade = $this->calculateGrade($score, $requiredFails);
        $ready = $score >= $this->minimumScore && $requiredFails === 0;

        return new ReadinessReport(
            score: $score,
            grade: $grade,
            ready: $ready,
            minimumScore: $this->minimumScore,
            passCount: $passCount,
            warnCount: $warnCount,
            failCount: $failCount,
            totalChecks: $totalChecks,
            requiredChecks: $requiredTotal,
            requiredFails: $requiredFails,
            results: $results,
        );
    }

    /**
     * Calculate a letter grade from score and required failures.
     *
     * @return 'A'|'B'|'C'|'D'|'F'
     */
    private function calculateGrade(int $score, int $requiredFails): string
    {
        if ($requiredFails > 0) {
            return 'F';
        }

        if ($score >= 95) {
            return 'A';
        }

        if ($score >= 85) {
            return 'B';
        }

        if ($score >= 70) {
            return 'C';
        }

        if ($score >= 55) {
            return 'D';
        }

        return 'F';
    }

    // ── Individual Check Implementations ───────────────────────────

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkProvidersConfigured(array $analytics): CheckResult
    {
        $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'webhook'];
        $configured = 0;

        foreach ($providers as $provider) {
            $config = $analytics[$provider] ?? [];
            if (($config['enabled'] ?? false) === true) {
                $configured++;
            }
        }

        if ($configured === 0) {
            return new CheckResult(status: 'fail', message: 'No analytics providers are enabled. At least one is required.');
        }

        if ($configured === 1) {
            return new CheckResult(status: 'warn', message: "Only {$configured} provider enabled. Consider adding a backup for reliability.");
        }

        return new CheckResult(status: 'pass', message: "{$configured} provider(s) enabled and configured.");
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkConsentDefault(array $analytics): CheckResult
    {
        $default = $analytics['consent']['default'] ?? null;

        if ($default === null) {
            return new CheckResult(status: 'fail', message: 'Consent default is not configured.');
        }

        $valid = ['granted', 'denied'];
        if (! in_array($default, $valid, true)) {
            return new CheckResult(status: 'fail', message: "Invalid consent default: '{$default}'. Must be 'granted' or 'denied'.");
        }

        return new CheckResult(status: 'pass', message: "Consent default is '{$default}'.");
    }

    private function checkQueueConfigured(array $analytics): CheckResult
    {
        $queueEnabled = $analytics['queue']['enabled'] ?? true;

        if (! $queueEnabled) {
            return new CheckResult(status: 'warn', message: 'Queue dispatch is disabled. Events will be processed synchronously.');
        }

        return new CheckResult(status: 'pass', message: 'Queue dispatch is enabled for async event processing.');
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkIdentityCookie(array $analytics): CheckResult
    {
        $cookieName = $analytics['identity']['cookie_name'] ?? '';

        if ($cookieName === '') {
            return new CheckResult(status: 'fail', message: 'Identity cookie name is not configured.');
        }

        return new CheckResult(status: 'pass', message: "Identity cookie is configured: '{$cookieName}'.");
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkEventValidation(array $analytics): CheckResult
    {
        $dedupEnabled = $analytics['dedup']['enabled'] ?? true;

        if (! $dedupEnabled) {
            return new CheckResult(status: 'warn', message: 'Event deduplication is disabled. Duplicate events may be sent.');
        }

        return new CheckResult(status: 'pass', message: 'Event validation and deduplication are active.');
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkDebugDisabled(array $analytics): CheckResult
    {
        $debugEnabled = $analytics['debug']['enabled'] ?? false;

        if ($debugEnabled) {
            return new CheckResult(status: 'fail', message: 'Debug mode is enabled. Events are logged but not dispatched.');
        }

        return new CheckResult(status: 'pass', message: 'Debug mode is disabled (production-safe).');
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkReplayEnabled(array $analytics): CheckResult
    {
        $replayEnabled = $analytics['replay']['enabled'] ?? true;

        if (! $replayEnabled) {
            return new CheckResult(status: 'warn', message: 'Event replay is disabled. Failed events will be lost.');
        }

        return new CheckResult(status: 'pass', message: 'Event replay is enabled for failure recovery.');
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkDedupActive(array $analytics): CheckResult
    {
        $dedupEnabled = $analytics['dedup']['enabled'] ?? true;

        if (! $dedupEnabled) {
            return new CheckResult(status: 'warn', message: 'Deduplication is disabled.');
        }

        return new CheckResult(status: 'pass', message: 'Deduplication is active.');
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkPiiSanitization(array $analytics): CheckResult
    {
        $piiEnabled = $analytics['pii_sanitization']['enabled'] ?? false;

        if (! $piiEnabled) {
            return new CheckResult(status: 'warn', message: 'PII sanitization is not enabled. Sensitive data may be sent to providers.');
        }

        return new CheckResult(status: 'pass', message: 'PII sanitization is enabled.');
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkConsentLogging(array $analytics): CheckResult
    {
        $logEnabled = $analytics['consent']['log_enabled'] ?? false;

        if (! $logEnabled) {
            return new CheckResult(status: 'warn', message: 'Consent audit logging is disabled. Recommended for GDPR compliance.');
        }

        return new CheckResult(status: 'pass', message: 'Consent audit logging is enabled.');
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkGdprIpAnonymization(array $analytics): CheckResult
    {
        $anonymize = $analytics['gdpr']['anonymize_ip'] ?? false;

        if (! $anonymize) {
            return new CheckResult(status: 'warn', message: 'IP anonymization is not enabled. Full IP addresses may be stored.');
        }

        return new CheckResult(status: 'pass', message: 'GDPR IP anonymization is enabled.');
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkAttributionTracking(array $analytics): CheckResult
    {
        $enabled = $analytics['attribution']['enabled'] ?? true;

        if (! $enabled) {
            return new CheckResult(status: 'warn', message: 'UTM attribution tracking is disabled.');
        }

        return new CheckResult(status: 'pass', message: 'UTM attribution tracking is enabled.');
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkHealthScoreEnabled(array $analytics): CheckResult
    {
        $enabled = $analytics['health_score']['enabled'] ?? true;

        if (! $enabled) {
            return new CheckResult(status: 'warn', message: 'SaaS health score tracking is disabled.');
        }

        return new CheckResult(status: 'pass', message: 'SaaS health score tracking is enabled.');
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkErrorTrackingClient(array $analytics): CheckResult
    {
        $enabled = $analytics['client_auto_track']['error_tracking'] ?? true;

        if (! $enabled) {
            return new CheckResult(status: 'warn', message: 'Client-side error tracking is disabled.');
        }

        return new CheckResult(status: 'pass', message: 'Client-side error tracking is configured.');
    }

    /**
     * @param  array<string, mixed>  $analytics
     */
    private function checkPerformanceBudget(array $analytics): CheckResult
    {
        $enabled = $analytics['performance_budget']['enabled'] ?? false;

        if (! $enabled) {
            return new CheckResult(status: 'warn', message: 'Performance budget is not configured.');
        }

        return new CheckResult(status: 'pass', message: 'Performance budget is configured.');
    }
}

/**
 * Represents a single readiness check.
 *
 * @internal
 */
final class ReadinessCheck
{
    /**
     * @param  string  $name  Check identifier
     * @param  string  $label  Human-readable description
     * @param  callable(): CheckResult  $evaluator  Returns the check result
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly mixed $evaluator,
    ){}

    /**
     * Execute the readiness check.
     */
    public function evaluate(): CheckResult
    {
        try {
            $result = ($this->evaluator)();

            return $result;
        } catch (\Throwable $e) {
            return new CheckResult(
                status: 'fail',
                message: "Check evaluation failed: {$e->getMessage()}",
            );
        }
    }
}

/**
 * Result of a single readiness check.
 *
 * @internal
 */
final class CheckResult
{
    /**
     * @param  'pass'|'warn'|'fail'  $status  Check status
     * @param  string  $message  Descriptive message
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
    ){}

    /**
     * Convert to array representation.
     *
     * @return array{status: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}

/**
 * Comprehensive readiness report for production deployment.
 *
 * @internal
 */
final class ReadinessReport
{
    /**
     * @param  int  $score  Overall readiness score (0-100)
     * @param  'A'|'B'|'C'|'D'|'F'  $grade  Letter grade
     * @param  bool  $ready  Whether deployment meets minimum threshold
     * @param  int  $minimumScore  Required minimum score
     * @param  int  $passCount  Number of passing checks
     * @param  int  $warnCount  Number of warning checks
     * @param  int  $failCount  Number of failing checks
     * @param  int  $totalChecks  Total number of checks
     * @param  int  $requiredChecks  Number of required checks
     * @param  int  $requiredFails  Number of required check failures
     * @param  array<string, CheckResult>  $results  Individual check results
     */
    public function __construct(
        public readonly int $score,
        public readonly string $grade,
        public readonly bool $ready,
        public readonly int $minimumScore,
        public readonly int $passCount,
        public readonly int $warnCount,
        public readonly int $failCount,
        public readonly int $totalChecks,
        public readonly int $requiredChecks,
        public readonly int $requiredFails,
        public readonly array $results,
    ){}

    /**
     * Convert to array representation for API/JSON responses.
     *
     * @return array{score: int, grade: string, ready: bool, minimum_score: int, pass: int, warn: int, fail: int, total: int, required_checks: int, required_fails: int, checks: array<string, array{status: string, message: string}>}
     */
    public function toArray(): array
    {
        $checks = [];

        foreach ($this->results as $name => $result) {
            $checks[$name] = $result->toArray();
        }

        return [
            'score' => $this->score,
            'grade' => $this->grade,
            'ready' => $this->ready,
            'minimum_score' => $this->minimumScore,
            'pass' => $this->passCount,
            'warn' => $this->warnCount,
            'fail' => $this->failCount,
            'total' => $this->totalChecks,
            'required_checks' => $this->requiredChecks,
            'required_fails' => $this->requiredFails,
            'checks' => $checks,
        ];
    }
}
