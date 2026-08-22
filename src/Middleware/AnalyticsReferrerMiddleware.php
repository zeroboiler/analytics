<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\ReferrerTrackingService;

/**
 * Referrer tracking middleware for automatic conversion attribution.
 *
 * Captures referral source information from incoming requests and
 * attaches it to analytics events dispatched during the request lifecycle.
 * Works with the ReferrerTrackingService to detect social, organic,
 * paid, email, and direct traffic sources.
 *
 * Register as a global middleware or route group middleware:
 *   ->middleware(\ZeroBoiler\Analytics\Middleware\AnalyticsReferrerMiddleware::class)
 *
 * @see \ZeroBoiler\Analytics\Services\ReferrerTrackingService
 *
 * @since 1.0.0
 */
final class AnalyticsReferrerMiddleware
{
    private ReferrerTrackingService $referrerService;

    private AnalyticsManager $manager;

    public function __construct(
        ReferrerTrackingService $referrerService,
        AnalyticsManager $manager,
    ){
        $this->referrerService = $referrerService;
        $this->manager = $manager;
    }

    /**
     * Capture referrer data and register an after-dispatch interceptor
     * to tag all events with referrer context.
     */
    #[Override]
    public function handle(Request $request, Closure $next): Response
    {
        $referrer = $this->referrerService->extractReferrer($request);
        $utm = $this->referrerService->extractUtm($request);

        $request->attributes->set('_zb_referrer', $referrer);
        $request->attributes->set('_zb_utm', $utm);

        $this->manager->interceptBefore(function (\ZeroBoiler\Analytics\DTO\AnalyticsEvent $event) use ($referrer, $utm): \ZeroBoiler\Analytics\DTO\AnalyticsEvent {
            $params = $event->params;

            // Only attach referrer data if not already present
            if (! isset($params['_referrer_source'])) {
                $params['_referrer_source'] = $referrer['source'];
                $params['_referrer_medium'] = $referrer['medium'];
                $params['_referrer_domain'] = $referrer['domain'];
                $params['_referrer_campaign'] = $referrer['campaign'];
            }

            foreach ($utm as $key => $value) {
                if (! isset($params[$key])) {
                    $params[$key] = $value;
                }
            }

            return new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: $event->name,
                params: $params,
                clientId: $event->clientId,
                userId: $event->userId,
            );
        });

        return $next($request);
    }
}
