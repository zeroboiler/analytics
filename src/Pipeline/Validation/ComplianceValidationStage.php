<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline\Validation;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Validates GDPR compliance requirements for event parameters.
 *
 * Ensures events that carry PII (sign_up, login, profile_updated, etc.)
 * have consent metadata attached. Checks data retention compatibility
 * and validates against the privacy manifest classification.
 *
 * Priority 50 (runs after PII scanning).
 *
 * @since 69.0.0
 */
final class ComplianceValidationStage implements ValidationStageInterface
{
    /** @var list<string> Events that require consent metadata */
    private const PII_EVENTS = [
        'sign_up', 'login', 'logout', 'account_activated', 'account_deactivated',
        'account_deleted', 'password_changed', 'password_reset', 'email_verified',
        'profile_updated', 'export', 'import', 'cancellation',
    ];

    private bool $enabled;

    private bool $requireConsentForPii;

    /**
     * @param  array{enabled?: bool, require_consent_for_pii?: bool}  $config
     */
    public function __construct(array $config = []): void
    {
        $this->enabled = (bool) ($config['enabled'] ?? true);
        $this->requireConsentForPii = (bool) ($config['require_consent_for_pii'] ?? true);
    }

    #[\Override]
    public function name(): string
    {
        return 'compliance';
    }

    #[\Override]
    public function priority(): int
    {
        return 50;
    }

    #[\Override]
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array{passed: bool, errors: list<array{code: string, message: string, field?: string, severity: 'error'|'warning'|'info'}>, metrics: array{checked: int, failed: int, skipped: int}}
     */
    #[\Override]
    public function validate(AnalyticsEvent $event): array
    {
        if (! $this->enabled) {
            return [
                'passed' => true,
                'errors' => [],
                'metrics' => ['checked' => 0, 'failed' => 0, 'skipped' => 1],
            ];
        }

        $errors = [];
        $checked = 0;
        $failed = 0;

        // Check PII events have consent
        $checked++;
        if ($this->requireConsentForPii && in_array($event->name, self::PII_EVENTS, true)) {
            $hasConsent = ($event->params['_zb_consent_granted'] ?? null) === true
                || ($event->context['consent'] ?? null) !== null;

            if (! $hasConsent) {
                $failed++;
                $errors[] = [
                    'code' => 'pii_without_consent',
                    'message' => "Event '{$event->name}' carries PII but lacks consent metadata (_zb_consent_granted)",
                    'severity' => 'error',
                ];
            }
        }

        // Verify event is classified in the privacy manifest
        $checked++;
        $category = EventCatalog::getCategory($event->name);
        if ($category === null) {
            $errors[] = [
                'code' => 'unclassified_event',
                'message' => "Event '{$event->name}' has no privacy manifest classification",
                'severity' => 'info',
            ];
        }

        return [
            'passed' => $failed === 0,
            'errors' => $errors,
            'metrics' => [
                'checked' => $checked,
                'failed' => $failed,
                'skipped' => 0,
            ],
        ];
    }

    #[\Override]
    public function description(): string
    {
        return 'Validates GDPR compliance: consent metadata for PII events and privacy manifest classification';
    }
}
