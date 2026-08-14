/**
 * ZeroBoiler Analytics — Event Name Constants
 *
 * Centralized event name constants for use in TypeScript/JavaScript code.
 * Prevents typos, enables IDE autocomplete, and provides a single source
 * of truth for all tracked event names.
 *
 * @package ZeroBoiler Analytics
 * @version 131.0.0
 */

// ─── E-Commerce Events ───────────────────────────────────────────────

/**
 * E-commerce event names.
 * @readonly
 */
export const EcommerceEvents = {
    VIEW_ITEM: 'view_item',
    ADD_TO_CART: 'add_to_cart',
    REMOVE_FROM_CART: 'remove_from_cart',
    VIEW_CART: 'view_cart',
    BEGIN_CHECKOUT: 'begin_checkout',
    ADD_PAYMENT_INFO: 'add_payment_info',
    PURCHASE: 'purchase',
    REFUND: 'refund',
    ADD_TO_WISHLIST: 'add_to_wishlist',
    SELECT_ITEM: 'select_item',
    SELECT_PROMOTION: 'select_promotion',
    VIEW_PROMOTION: 'view_promotion',
    CHECKOUT_STEP: 'checkout_step',
    ABANDONED_CART: 'abandoned_cart',
    CHECKOUT_ABANDON: 'checkout_abandon',
};
Object.freeze(EcommerceEvents);

// ─── SaaS Events ──────────────────────────────────────────────────────

/**
 * SaaS lifecycle event names.
 * @readonly
 */
export const SaaSEvents = {
    // Authentication
    SIGN_UP: 'sign_up',
    LOGIN: 'login',
    LOGOUT: 'logout',
    EMAIL_VERIFIED: 'email_verified',

    // Subscription
    SUBSCRIPTION_CREATED: 'subscription_created',
    SUBSCRIPTION_RENEWAL: 'subscription_renewal',
    CANCELLATION: 'cancellation',
    SUBSCRIPTION_CANCELLED: 'subscription_cancelled',
    SUBSCRIPTION_PAUSED: 'subscription_paused',
    SUBSCRIPTION_RESUMED: 'subscription_resumed',
    SUBSCRIPTION_VALUE_CHANGED: 'subscription_value_changed',

    // Plans
    PLAN_UPGRADE: 'plan_upgrade',
    PLAN_DOWNGRADE: 'plan_downgrade',
    PLAN_CHANGED: 'plan_changed',

    // Trial
    TRIAL_START: 'start_trial',
    TRIAL_END: 'trial_end',
    TRIAL_CONVERTED: 'trial_converted',
    TRIAL_EXPIRED: 'trial_expired',

    // Billing
    PAYMENT_SUCCEEDED: 'payment_succeeded',
    PAYMENT_FAILED: 'payment_failed',
    PAYMENT_METHOD_ADDED: 'payment_method_added',
    PAYMENT_METHOD_UPDATED: 'payment_method_updated',
    PAYMENT_METHOD_REMOVED: 'payment_method_removed',
    INVOICE_GENERATED: 'invoice_generated',
    BILLING_RETRY: 'billing_retry',
    CREDIT_APPLIED: 'credit_applied',

    // Account
    ACCOUNT_ACTIVATED: 'account_activated',
    ACCOUNT_DEACTIVATED: 'account_deactivated',
    ACCOUNT_DELETED: 'account_deleted',
    PASSWORD_CHANGED: 'password_changed',
    PASSWORD_RESET: 'password_reset',
    PASSWORD_RESET_REQUESTED: 'password_reset_requested',
    PROFILE_UPDATED: 'profile_updated',

    // Onboarding
    ONBOARDING_STARTED: 'onboarding_started',

    // Feature
    FEATURE_USED: 'feature_used',
    FEATURE_LIMIT_REACHED: 'feature_limit_reached',
    FEATURE_ADOPTED: 'feature_adopted',

    // Team / B2B
    TEAM_CREATED: 'team_created',
    TEAM_MEMBER_JOINED: 'team_member_joined',
    TEAM_MEMBER_REMOVED: 'team_member_removed',
    ROLE_CHANGED: 'role_changed',
    INVITE_SENT: 'invite_sent',
    INVITE_ACCEPTED: 'invite_accepted',
    WORKSPACE_CREATED: 'workspace_created',

    // Growth
    MILESTONE_REACHED: 'milestone_reached',
    EXPANSION_REVENUE: 'expansion_revenue',
    FIRST_VALUE: 'first_value',
    USAGE_QUOTA_REACHED: 'usage_quota_reached',

    // Integrations
    INTEGRATION_CONNECTED: 'integration_connected',
    INTEGRATION_FAILED: 'integration_failed',
    INTEGRATION_USED: 'integration_used',

    // GDPR
    DATA_SUBJECT_ACCESS_REQUEST: 'data_subject_access_request',
    DATA_ERASURE_COMPLETED: 'data_erasure_completed',
};
Object.freeze(SaaSEvents);

// ─── Engagement Events ────────────────────────────────────────────────

/**
 * Engagement event names.
 * @readonly
 */
export const EngagementEvents = {
    PAGE_VIEW: 'page_view',
    SCROLL_DEPTH: 'scroll_depth',
    CLICK: 'click',
    FORM_START: 'form_start',
    FORM_SUBMIT: 'form_submit',
    SEARCH: 'search',
    SHARE: 'share',
    ERROR: 'error',
    FILE_DOWNLOAD: 'file_download',
    VIDEO_PLAY: 'video_play',
    OUTBOUND_CLICK: 'outbound_click',
    NOTIFICATION: 'notification',
    CONTENT_ENGAGEMENT: 'content_engagement',
    ONBOARDING_STEP: 'onboarding_step',
    ONBOARDING_COMPLETED: 'onboarding_completed',
    SESSION_START: 'session_start',
    SESSION_END: 'session_end',
    SCREEN_VIEW: 'screen_view',
    TIME_ON_PAGE: 'time_on_page',
    TIMING: 'timing',
    AB_TEST_EXPOSURE: 'ab_test_exposure',
    CAMPAIGN_ATTRIBUTION: 'campaign_attribution',
    AD_CLICK: 'ad_click',
    CONSENT_GRANTED: 'consent_granted',
    CONSENT_WITHDRAWN: 'consent_withdrawn',
    GOAL_CONVERSION: 'goal_conversion',
    COPY_TEXT: 'copy_text',
    ELEMENT_VISIBILITY: 'element_visibility',
    HOVER: 'hover',
    FEATURE_REQUEST: 'feature_request',
    FEEDBACK: 'feedback',
    PERFORMANCE_SCORE: 'performance_score',
    WEB_VITALS: 'web_vitals',
    CLIENT_ERROR: 'client_error',
};
Object.freeze(EngagementEvents);

// ─── Security Events ──────────────────────────────────────────────

/**
 * Security event names.
 * @readonly
 */
export const SecurityEvents = {
    LOGIN_ATTEMPT: 'login_attempt',
    MFA_CHALLENGE: 'mfa_challenge',
    RATE_LIMIT_EXCEEDED: 'rate_limit_exceeded',
    SUSPICIOUS_ACTIVITY: 'suspicious_activity',
    DATA_ACCESS_AUDIT: 'data_access_audit',
    AI_AGENT_ACCESS: 'ai_agent_access',
};
Object.freeze(SecurityEvents);

// ─── Uptime Events ──────────────────────────────────────────────────

/**
 * Uptime/service health event names.
 * @readonly
 */
export const UptimeEvents = {
    API_LATENCY: 'api_latency',
    DEPLOYMENT: 'deployment',
    ERROR_SPIKE: 'error_spike',
    SERVICE_DOWN: 'service_down',
    SERVICE_UP: 'service_up',
};
Object.freeze(UptimeEvents);

// ─── Infrastructure Events ─────────────────────────────────────────

/**
 * Infrastructure/platform event names.
 * @readonly
 */
export const InfrastructureEvents = {
    DEPLOYMENT_ROLLED_BACK: 'deployment_rolled_back',
    ERROR_BUDGET_BURNED: 'error_budget_burned',
    EXPERIMENT_EXPOSED: 'experiment_exposed',
    FEATURE_FLAG_EVALUATED: 'feature_flag_evaluated',
    INCIDENT_STARTED: 'incident_started',
    INCIDENT_RESOLVED: 'incident_resolved',
    MAINTENANCE_STARTED: 'maintenance_started',
    MAINTENANCE_ENDED: 'maintenance_ended',
    PIPELINE_FAILURE: 'pipeline_failure',
    SLO_BREACH: 'slo_breach',
};
Object.freeze(InfrastructureEvents);

// ─── Unified Event Names ────────────────────────────────────────────

/**
 * All event names from all categories merged into a single object.
 * Use for validation, type unions, or generic event handling.
 */
export const AllEventNames = Object.freeze({
    ...EcommerceEvents,
    ...SaaSEvents,
    ...EngagementEvents,
    ...SecurityEvents,
    ...UptimeEvents,
    ...InfrastructureEvents,
});

/**
 * Type guard: check if a string is a valid event name.
 *
 * @param {string} name
 * @returns {boolean}
 */
export function isValidEventName(name) {
    return Object.values(AllEventNames).includes(name);
}

/**
 * Get event names by category.
 *
 * @param {'ecommerce'|'saas'|'engagement'|'security'|'uptime'|'infrastructure'} category
 * @returns {string[]}
 */
export function getEventNamesByCategory(category) {
    switch (category) {
        case 'ecommerce': return Object.values(EcommerceEvents);
        case 'saas': return Object.values(SaaSEvents);
        case 'engagement': return Object.values(EngagementEvents);
        case 'security': return Object.values(SecurityEvents);
        case 'uptime': return Object.values(UptimeEvents);
        case 'infrastructure': return Object.values(InfrastructureEvents);
        default: return [];
    }
}

/**
 * Get total count of all events across all categories.
 *
 * @returns {number}
 */
export function getTotalEventCount() {
    return Object.keys(AllEventNames).length;
}

/**
 * Get all supported category names.
 *
 * @returns {readonly string[]}
 */
export function getCategoryNames() {
    return ['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure'];
}
