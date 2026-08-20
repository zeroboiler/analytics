<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Validates lifecycle event mappings from configuration.
 *
 * Checks custom mappings for:
 * - Target class existence and AnalyticsEvent inheritance
 * - Duplicate source events (conflicts between default and custom)
 * - Invalid extractor method names
 * - Mapping key naming conventions
 * - Event name alignment with the event catalog
 *
 * Used by the overview command and diagnostic endpoints.
 *
 * @since 271.0.0
 */
final class LifecycleMappingValidator
{
    /**
     * Known condition method names on LifecycleEventMapper.
     *
     * @var list<string>
     */
    private const KNOWN_CONDITIONS = [
        'requireAuth',
    ];

    /**
     * Recommended pattern for mapping keys.
     *
     * @var string
     */
    private const KEY_PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*){1,4}$/';

    /**
     * All known built-in extractor method names.
     *
     * @var list<string>
     */
    private const KNOWN_EXTRACTORS = [
        'extractAuthParams',
        'extractRegisterParams',
        'extractLogoutParams',
        'extractSubscriptionParams',
        'extractPlanChangeParams',
        'extractCancellationParams',
        'extractTrialParams',
        'extractFeatureParams',
        'extractPurchaseParams',
        'extractRefundParams',
        'extractFormParams',
        'extractSearchParams',
        'extractErrorParams',
        'extractSimpleUserIdParams',
        'extractTeamParams',
        'extractRoleChangeParams',
        'extractInviteParams',
        'extractPaymentParams',
        'extractIntegrationParams',
        'extractConsentParams',
        'extractGdprParams',
        'extractEngagementShareParams',
        'extractScrollDepthParams',
        'extractFileDownloadParams',
        'extractContentEngagementParams',
    ];

    /** @var list<array{severity: 'error'|'warning'|'info', key: string, message: string, suggestion?: string}> */
    private array $issues = [];

    /** @var array<string, string> Source class/string → first mapping key */
    private array $sourceRegistry = [];

    /** @var list<string> All valid event names from the catalog */
    private readonly array $catalogNames;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config)
    {
        $this->catalogNames = EventCatalog::names();

        $lifecycleConfig = $config->get('zeroboiler.analytics.lifecycle', []);
        /** @var array{custom_mappings?: array<string, mixed>, override_defaults?: bool} $lifecycleConfig */

        $customMappings = (array) ($lifecycleConfig['custom_mappings'] ?? []);
        $overrideDefaults = (bool) ($lifecycleConfig['override_defaults'] ?? false);

        // Register default source events via the public getDefaultMapping() API
        $this->registerDefaultSources();

        if ($overrideDefaults) {
            // When overriding defaults, clear the default source registry
            // so that custom mappings are the only ones validated (no duplicate warnings).
            $this->sourceRegistry = [];
        }

        $this->validateMappings($customMappings);
    }

    /**
     * Populate the source registry from built-in default mappings.
     */
    private function registerDefaultSources(): void
    {
        $defaultKeys = [
            'auth.login', 'auth.register', 'auth.logout',
            'subscription.created', 'subscription.upgraded', 'subscription.downgraded',
            'subscription.cancelled', 'subscription.renewal',
            'trial.started', 'trial.ended',
            'feature.used', 'feature.limit_reached',
            'order.completed', 'order.refunded',
            'form.submitted', 'search.performed', 'error.occurred',
            'account.activated', 'account.deactivated', 'account.email_verified',
            'account.password_changed', 'account.password_reset', 'account.profile_updated',
            'team.created', 'team.member_joined', 'team.member_removed',
            'team.role_changed', 'team.invite_sent', 'team.invite_accepted',
            'onboarding.started', 'auth.password_reset_requested',
            'billing.payment_method_removed',
            'billing.payment_succeeded', 'billing.payment_failed',
            'billing.payment_method_added', 'billing.invoice_generated', 'billing.credit_applied',
            'integration.connected', 'integration.failed',
            'trial.converted', 'subscription.value_changed', 'usage.quota_reached',
            'billing.retry', 'subscription.paused', 'workspace.created', 'milestone.reached',
            'engagement.share', 'engagement.scroll_depth', 'engagement.file_download',
            'engagement.content_engagement',
            'subscription.expiring_soon', 'subscription.trial_end_reminder',
            'account.deleted', 'consent.granted', 'consent.withdrawn',
            'gdpr.data_subject_access_request', 'gdpr.data_erasure_completed',
            'plan.changed', 'billing.payment_method_updated',
            'subscription.created_new', 'subscription.cancelled_new', 'subscription.resumed',
            'trial.expired', 'sla.breach', 'feature.adopted', 'revenue.expansion',
        ];

        foreach ($defaultKeys as $defaultKey) {
            $mapping = LifecycleEventMapper::getDefaultMapping($defaultKey);
            if ($mapping !== null) {
                $source = is_string($mapping['source'] ?? null) ? $mapping['source'] : '';
                if ($source !== '') {
                    $this->sourceRegistry[$source] = $defaultKey;
                }
            }
        }
    }

    /**
     * Validate a set of mappings.
     *
     * @param  array<string, mixed>  $mappings
     */
    private function validateMappings(array $mappings): void
    {
        foreach ($mappings as $key => $mapping) {
            if (! is_string($key) || $key === '') {
                $this->addIssue('error', '(non_string_key)', 'Mapping key must be a non-empty string.');

                continue;
            }

            $this->validateKey($key);

            if (! is_array($mapping)) {
                $this->addIssue('error', $key, "Mapping for '{$key}' must be an array with 'source' and 'target' keys.");

                continue;
            }

            $this->validateSource($key, $mapping);
            $this->validateTarget($key, $mapping);
            $this->validateExtractor($key, $mapping);
            $this->validateCondition($key, $mapping);
            $this->validateCatalogAlignment($key);
        }
    }

    /**
     * Validate a mapping key follows naming conventions.
     */
    private function validateKey(string $key): void
    {
        if (! preg_match(self::KEY_PATTERN, $key)) {
            $this->addIssue(
                'warning',
                $key,
                "Mapping key '{$key}' does not follow the recommended pattern 'domain.event_name' (lowercase, dot-separated, 2-5 segments).",
                'Example: team.invited, billing.payment_failed, feature.limit_reached',
            );
        }
    }

    /**
     * Validate the source event class/string.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $mapping
     */
    private function validateSource(string $key, array $mapping): void
    {
        $source = $mapping['source'] ?? null;

        if (! is_string($source) || $source === '') {
            $this->addIssue('error', $key, "Mapping '{$key}' is missing or has an empty 'source' field.");

            return;
        }

        // Check for duplicate source events
        if (isset($this->sourceRegistry[$source]) && $this->sourceRegistry[$source] !== $key) {
            $this->addIssue(
                'warning',
                $key,
                "Source '{$source}' is already mapped by key '{$this->sourceRegistry[$source]}'. Both listeners will fire for the same Laravel event.",
                'Consider using a single mapping key or disabling one via lifecycle.events config.',
            );
        }

        // Register this source
        $this->sourceRegistry[$source] = $key;

        // Check if source class exists (only for FQCN sources)
        if (str_contains($source, '\\') && ! class_exists($source)) {
            $this->addIssue(
                'warning',
                $key,
                "Source class '{$source}' does not exist. The listener will be registered but never fire.",
            );
        }
    }

    /**
     * Validate the target analytics event class.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $mapping
     */
    private function validateTarget(string $key, array $mapping): void
    {
        $target = $mapping['target'] ?? null;

        if (! is_string($target) || $target === '') {
            $this->addIssue('error', $key, "Mapping '{$key}' is missing or has an empty 'target' field.");

            return;
        }

        if (! class_exists($target)) {
            $this->addIssue(
                'error',
                $key,
                "Target class '{$target}' does not exist. The mapping will fail at runtime.",
            );

            return;
        }

        if (! is_subclass_of($target, AnalyticsEvent::class)) {
            $this->addIssue(
                'error',
                $key,
                "Target class '{$target}' does not extend " . AnalyticsEvent::class . '. All target classes must be AnalyticsEvent subclasses.',
            );
        }
    }

    /**
     * Validate the params_extractor method name.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $mapping
     */
    private function validateExtractor(string $key, array $mapping): void
    {
        $extractor = $mapping['params_extractor'] ?? null;

        if ($extractor === null) {
            return;
        }

        if (! is_string($extractor)) {
            $this->addIssue('error', $key, "Mapping '{$key}' has a non-string 'params_extractor'. Must be a method name string.");

            return;
        }

        if (! preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $extractor)) {
            $this->addIssue('error', $key, "Extractor '{$extractor}' is not a valid PHP method name.");

            return;
        }

        if (! in_array($extractor, self::KNOWN_EXTRACTORS, true)) {
            $this->addIssue(
                'info',
                $key,
                "Extractor '{$extractor}' is not a built-in extractor. Ensure it exists on the LifecycleEventMapper class or is handled via reflection fallback.",
            );
        }
    }

    /**
     * Validate the condition method name.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $mapping
     */
    private function validateCondition(string $key, array $mapping): void
    {
        $condition = $mapping['condition'] ?? null;

        if ($condition === null) {
            return;
        }

        if (! is_string($condition)) {
            $this->addIssue('error', $key, "Mapping '{$key}' has a non-string 'condition'. Must be a method name string.");

            return;
        }

        if (! in_array($condition, self::KNOWN_CONDITIONS, true)) {
            $this->addIssue(
                'info',
                $key,
                "Condition '{$condition}' is not a built-in condition. Ensure it exists on the LifecycleEventMapper class.",
            );
        }
    }

    /**
     * Check if the mapping key aligns with a known catalog event name.
     */
    private function validateCatalogAlignment(string $key): void
    {
        $segments = explode('.', $key);
        $eventName = end($segments);

        if ($eventName === false || ! is_string($eventName)) {
            return;
        }

        if (EventCatalog::has($eventName) || EventCatalog::has($key)) {
            return;
        }

        $this->addIssue(
            'info',
            $key,
            "Mapping key '{$key}' does not have a corresponding event in the catalog. This is fine for custom events.",
        );
    }

    /**
     * Add a validation issue.
     *
     * @param  'error'|'warning'|'info'  $severity
     * @param  string  $key
     * @param  string  $message
     * @param  string|null  $suggestion
     */
    private function addIssue(string $severity, string $key, string $message, ?string $suggestion = null): void
    {
        $entry = [
            'severity' => $severity,
            'key' => $key,
            'message' => $message,
        ];

        if ($suggestion !== null) {
            $entry['suggestion'] = $suggestion;
        }

        $this->issues[] = $entry;
    }

    /**
     * Get all validation issues.
     *
     * @return list<array{severity: string, key: string, message: string, suggestion?: string}>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * Check if the mappings are valid (no errors).
     */
    public function isValid(): bool
    {
        return $this->getErrorCount() === 0;
    }

    /**
     * Get the number of error-level issues.
     */
    public function getErrorCount(): int
    {
        return count(array_filter($this->issues, fn (array $issue): bool => $issue['severity'] === 'error'));
    }

    /**
     * Get the number of warning-level issues.
     */
    public function getWarningCount(): int
    {
        return count(array_filter($this->issues, fn (array $issue): bool => $issue['severity'] === 'warning'));
    }

    /**
     * Get the number of info-level issues.
     */
    public function getInfoCount(): int
    {
        return count(array_filter($this->issues, fn (array $issue): bool => $issue['severity'] === 'info'));
    }

    /**
     * Get a summary of the validation results.
     *
     * @return array{valid: bool, errors: int, warnings: int, info: int, total_issues: int, issues: list<array{severity: string, key: string, message: string, suggestion?: string}>}
     */
    public function summary(): array
    {
        return [
            'valid' => $this->isValid(),
            'errors' => $this->getErrorCount(),
            'warnings' => $this->getWarningCount(),
            'info' => $this->getInfoCount(),
            'total_issues' => count($this->issues),
            'issues' => $this->issues,
        ];
    }

    /**
     * Validate a single custom mapping entry (static utility).
     *
     * Useful for validating a mapping before adding it to config.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $mapping
     * @return list<array{severity: string, key: string, message: string, suggestion?: string}>
     */
    public static function validateMapping(string $key, array $mapping): array
    {
        $issues = [];

        if (! is_string($key) || $key === '') {
            $issues[] = ['severity' => 'error', 'key' => '(empty)', 'message' => 'Key must be a non-empty string.'];

            return $issues;
        }

        $source = $mapping['source'] ?? null;
        if (! is_string($source) || $source === '') {
            $issues[] = ['severity' => 'error', 'key' => $key, 'message' => "Missing or empty 'source' field."];
        }

        $target = $mapping['target'] ?? null;
        if (! is_string($target) || $target === '') {
            $issues[] = ['severity' => 'error', 'key' => $key, 'message' => "Missing or empty 'target' field."];
        } elseif (! class_exists($target)) {
            $issues[] = ['severity' => 'error', 'key' => $key, 'message' => "Target class '{$target}' does not exist."];
        } elseif (! is_subclass_of($target, AnalyticsEvent::class)) {
            $issues[] = ['severity' => 'error', 'key' => $key, 'message' => "Target class '{$target}' must extend AnalyticsEvent."];
        }

        return $issues;
    }
}
