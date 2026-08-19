<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tracking;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\AnalyticsQueueService;

/**
 * Config-driven server-side lifecycle event tracker.
 *
 * Maps Laravel framework events (auth.login, subscription.created, etc.)
 * to analytics events and dispatches them through the analytics pipeline.
 *
 * Built-in mappings cover the standard SaaS lifecycle:
 *   - auth.login        → login
 *   - auth.register     → sign_up
 *   - auth.logout       → logout
 *   - trial.started     → start_trial
 *   - trial.ended       → trial_end
 *   - subscription.created   → subscribe
 *   - subscription.upgraded  → plan_upgrade
 *   - subscription.downgraded → plan_downgrade
 *   - subscription.cancelled → cancellation
 *   - feature.used      → feature_used
 *
 * Custom mappings are merged from `zeroboiler.analytics.lifecycle.custom_mappings`
 * and from `zeroboiler.analytics.auto_track.event_map`.
 *
 * @since 255.0.0
 */
final class LifecycleEventTracker
{
 /**
  * The number of built-in event mappings.
  */
 public const BUILTIN_MAPPING_COUNT = 10;

 /** @var array<string, string> Built-in Laravel event → analytics event name mapping */
 private const BUILTIN_MAP = [
  'auth.login'          => 'login',
  'auth.register'       => 'sign_up',
  'auth.logout'         => 'logout',
  'trial.started'       => 'start_trial',
  'trial.ended'         => 'trial_end',
  'subscription.created'   => 'subscribe',
  'subscription.upgraded'  => 'plan_upgrade',
  'subscription.downgraded' => 'plan_downgrade',
  'subscription.cancelled' => 'cancellation',
  'feature.used'        => 'feature_used',
 ];

 /** @var array<string, string> Resolved mapping (built-in + config custom) */
 private array $mapping;

 private bool $enabled;

 private bool $queueEvents;

 private bool $enrichAttribution;

 /** @var array<string, bool> Toggle map for individual events */
 private array $eventToggles;

 public function __construct(
  private AnalyticsQueueService $queueService,
  ConfigRepository $config,
 ) {
  $lifecycleConfig = $config->get('zeroboiler.analytics.lifecycle', []);
  /** @var array{enabled?: bool, queue_events?: bool, enrich_attribution?: bool, custom_mappings?: array<string, string>} $lifecycleConfig */
  $this->enabled = (bool) ($lifecycleConfig['enabled'] ?? true);
  $this->queueEvents = (bool) ($lifecycleConfig['queue_events'] ?? false);
  $this->enrichAttribution = (bool) ($lifecycleConfig['enrich_attribution'] ?? true);

  // Build event toggles from auto_track config
  $autoTrackConfig = $config->get('zeroboiler.analytics.auto_track', []);
  /** @var array{enabled?: bool, events?: array<string, bool>, event_map?: array<string, string>} $autoTrackConfig */
  $this->eventToggles = $autoTrackConfig['events'] ?? [];

  // Merge built-in + custom mappings
  $customMappings = (array) ($lifecycleConfig['custom_mappings'] ?? []);
  $eventMapOverrides = (array) ($autoTrackConfig['event_map'] ?? []);

  $this->mapping = array_merge(self::BUILTIN_MAP, $customMappings, $eventMapOverrides);
 }

 /**
  * Register all lifecycle event listeners on the event dispatcher.
  *
  * Called from the service provider boot method.
  */
 public function registerListeners(EventDispatcher $dispatcher): void
 {
  if (! $this->enabled) {
   return;
  }

  foreach ($this->mapping as $laravelEvent => $analyticsEvent) {
   // Skip if explicitly disabled in event toggles
   if (($this->eventToggles[$laravelEvent] ?? true) === false) {
    continue;
   }

   $dispatcher->listen($laravelEvent, $this->createListener($laravelEvent, $analyticsEvent));
  }
 }

 /**
  * Create a closure listener for a lifecycle event.
  *
  * @return callable(mixed): void
  */
 private function createListener(string $laravelEvent, string $analyticsEvent): callable
 {
  return function (mixed $eventPayload) use ($laravelEvent, $analyticsEvent): void {
   $params = $this->extractParams($eventPayload);
   $params['_source'] = 'lifecycle';
   $params['_laravel_event'] = $laravelEvent;

   if ($this->enrichAttribution) {
    $params = $this->enrichWithAttribution($params);
   }

   $analyticsEventObj = new AnalyticsEvent(
    name: $analyticsEvent,
    params: $params,
    category: $this->guessCategory($analyticsEvent),
   );

   if ($this->queueEvents) {
    $this->queueService->dispatch($analyticsEventObj);
   } else {
    $this->queueService->dispatch($analyticsEventObj);
   }
  };
 }

 /**
  * Extract parameters from a Laravel event payload.
  *
  * Supports:
  *  - Eloquent model events (model instance)
  *  - Objects with `toArray()` method
  *  - Objects with public properties
  *  - Arrays
  *
  * @param  mixed  $payload
  * @return array<string, mixed>
  */
 private function extractParams(mixed $payload): array
 {
  if ($payload instanceof \Illuminate\Database\Eloquent\Model) {
   return $payload->toArray();
  }

  if (is_object($payload) && method_exists($payload, 'toArray')) {
   return $payload->toArray();
  }

  if (is_array($payload)) {
   return $payload;
  }

  if (is_object($payload)) {
   $params = [];
   foreach (get_object_vars($payload) as $key => $value) {
    // Skip closures and resources
    if ($value instanceof \Closure || is_resource($value)) {
     continue;
    }
    $params[$key] = $value;
   }

   return $params;
  }

  return [];
 }

 /**
  * Enrich event params with attribution context.
  *
  * Adds UTM parameters, referrer, session ID, and device context
  * from the current HTTP request.
  *
  * @param  array<string, mixed>  $params
  * @return array<string, mixed>
  */
 private function enrichWithAttribution(array $params): array
 {
  $request = request();

  if ($request === null) {
   return $params;
  }

  // UTM parameters
  $utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
  foreach ($utmKeys as $key) {
   $value = $request->query($key);
   if (is_string($value) && $value !== '') {
    $params[$key] = $value;
   }
  }

  // Referrer
  $referrer = $request->headers->get('referer');
  if (is_string($referrer) && $referrer !== '') {
   $params['referrer'] = $referrer;
  }

  // Session ID
  $session = $request->getSession();
  if ($session !== null) {
   $params['session_id'] = $session->getId();
  }

  // Page URL
  $params['page_url'] = $request->fullUrl();
  $params['page_path'] = $request->path();

  // IP (hashed for privacy)
  $ip = $request->ip();
  if (is_string($ip)) {
   $params['ip_hash'] = hash('sha256', $ip);
  }

  return $params;
 }

 /**
  * Guess the event category from the analytics event name.
  */
 private function guessCategory(string $eventName): string
 {
  return match (true) {
   in_array($eventName, ['view_item', 'add_to_cart', 'remove_from_cart', 'view_cart', 'begin_checkout', 'add_payment_info', 'purchase', 'refund', 'add_to_wishlist', 'select_item', 'select_promotion', 'view_promotion', 'checkout_step', 'abandoned_cart', 'checkout_abandon'], true) => 'ecommerce',
   in_array($eventName, ['sign_up', 'login', 'logout', 'start_trial', 'trial_end', 'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation', 'feature_used', 'revenue_tracked', 'payment_failed', 'payment_succeeded', 'team_created', 'team_member_joined', 'role_changed'], true) => 'saas',
   default => 'engagement',
  };
 }

 /**
  * Get the current mapping (built-in + custom).
  *
  * @return array<string, string>
  */
 public function getMapping(): array
 {
  return $this->mapping;
 }

 /**
  * Get the number of registered mappings.
  */
 public function getMappingCount(): int
 {
  return count($this->mapping);
 }

 /**
  * Check if lifecycle tracking is enabled.
  */
 public function isEnabled(): bool
 {
  return $this->enabled;
 }
}
