<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a user account deletion (GDPR right-to-erasure).
 *
 * Dispatched when a user permanently deletes their account.
 * Critical for GDPR compliance audit trails and churn analysis.
 * Unlike account_deactivated (reversible), deletion is permanent.
 *
 * GA4: account_deleted
 * Meta: CustomEvent
 * PostHog: account_deleted
 *
 * @since 1.0.0
 */
final readonly class AccountDeletedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $reason  Deletion reason (e.g. 'gdpr_request', 'self_service', 'admin_action')
     * @param  string|null  $method  Deletion method (e.g. 'self_service', 'support_ticket')
     * @param  int|null  $accountAgeDays  Age of the account in days at deletion time
     * @param  string|null  $lastPlan  The user's plan at time of deletion
     */
    public function __construct(
        ?string $reason = null,
        ?string $method = null,
        ?int $accountAgeDays = null,
        ?string $lastPlan = null,
    ){
        parent::__construct('account_deleted', array_filter([
            'reason' => $reason,
            'method' => $method,
            'account_age_days' => $accountAgeDays,
            'last_plan' => $lastPlan,
        ]));
    }
}
