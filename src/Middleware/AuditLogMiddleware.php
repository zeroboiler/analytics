<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Middleware;

use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event audit log middleware — records event dispatch to the audit trail.
 *
 * When enabled, every event that passes through the middleware stack is
 * logged with structured context. Useful for compliance, debugging, and
 * building custom analytics dashboards from Laravel logs.
 *
 * Configure in zeroboiler.analytics.audit_log.enabled.
 *
 * @see \ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareInterface
 *
 * @since 1.0.0
 */
final class AuditLogMiddleware implements AnalyticsMiddlewareInterface
{
    private int $priority;

    private bool $enabled;

    /**
     * @param  bool  $enabled  Whether audit logging is active
     * @param  int  $priority  Middleware execution priority (lower = earlier)
     */
    public function __construct(bool $enabled = false, int $priority = 100): void
    {
        $this->enabled = $enabled;
        $this->priority = $priority;
    }

    public function process(AnalyticsEvent $event, callable $next): ?AnalyticsEvent
    {
        $result = $next($event);

        if ($this->enabled && $result !== null) {
            $this->logAudit($result);
        }

        return $result;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Enable or disable audit logging at runtime.
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * Check if audit logging is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Log the event to the audit channel.
     */
    private function logAudit(AnalyticsEvent $event): void
    {
        try {
            Log::channel('daily')->info('ZeroBoiler Analytics Audit', [
                'event' => $event->name,
                'client_id' => $event->clientId,
                'user_id' => $event->userId,
                'params' => $event->params,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Throwable) {
            // Audit logging must never throw
        }
    }
}
