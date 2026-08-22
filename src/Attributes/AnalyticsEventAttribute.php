<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Attributes;

use Attribute;

/**
 * PHP 8.5 attribute for declarative analytics event metadata.
 *
 * Apply to event DTO classes to provide provider mappings, category,
 * display label, and priority without requiring static catalog arrays.
 *
 * The EventCatalog reads these attributes to build its registry,
 * merging attribute-defined events with the static catalogs.
 *
 * @since 19.0.0
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AnalyticsEventAttribute
{
    /**
     * @param  string  $name  Canonical event name (e.g. 'purchase', 'sign_up')
     * @param  string  $category  Event category (ecommerce, saas, engagement, security, uptime, custom)
     * @param  string  $ga4  GA4 Measurement Protocol event name
     * @param  string|null  $meta  Meta Pixel event name (null if not supported)
     * @param  string  $posthog  PostHog event name
     * @param  string|null  $plausible  Plausible event name (null if not supported)
     * @param  string  $mixpanel  Mixpanel event name
     * @param  string  $amplitude  Amplitude event name
     * @param  string  $label  Human-readable display label
     * @param  'critical'|'high'|'medium'|'low'  $priority  Business impact priority
     * @param  list<string>  $aliases  Alternative event name aliases
     * @param  string|null  $description  Event description for documentation
     * @param  list<string>  $tags  Tags for categorization and filtering
     */
    public function __construct(
        public string $name,
        public string $category = 'custom',
        public string $ga4 = '',
        public ?string $meta = null,
        public string $posthog = '',
        public ?string $plausible = null,
        public string $mixpanel = '',
        public string $amplitude = '',
        public string $label = '',
        public string $priority = 'medium',
        public array $aliases = [],
        public ?string $description = null,
        public array $tags = [],
    ){}

    /**
     * Convert to catalog entry format compatible with EventCatalog.
     *
     * @return array{name: string, class: string, ga4: string, meta: string|null, category: string, posthog: string, plausible: string|null, mixpanel: string, amplitude: string}
     */
    public function toCatalogEntry(string $className): array
    {
        return [
            'name' => $this->name,
            'class' => $className,
            'ga4' => $this->ga4,
            'meta' => $this->meta,
            'category' => $this->category,
            'posthog' => $this->posthog,
            'plausible' => $this->plausible,
            'mixpanel' => $this->mixpanel,
            'amplitude' => $this->amplitude,
        ];
    }

    /**
     * Check if this attribute defines a valid primary GA4 event name.
     */
    public function hasGa4Mapping(): bool
    {
        return $this->ga4 !== '';
    }

    /**
     * Check if this attribute defines a Meta Pixel mapping.
     */
    public function hasMetaMapping(): bool
    {
        return $this->meta !== null;
    }
}
