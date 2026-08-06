<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Trackers;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;

class GTMTracker implements TrackerInterface
{
    private string $containerId;

    private bool $enabled;

    /** @var array<int, array<string, mixed>> */
    private array $dataLayer = [];

    use TrackerHelpers;

    public function __construct(string $containerId, bool $enabled = false)
    {
        $this->containerId = $containerId;
        $this->enabled = $enabled;
        $this->consent = ConsentState::granted();
    }

    public function track(AnalyticsEvent $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        // Respect analytics_storage consent
        if ($this->isAnalyticsDenied()) {
            return;
        }

        $this->push([
            'event' => $event->name,
            'eventParams' => $event->params,
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->isValidContainerId($this->containerId);
    }

    public function headScripts(): string
    {
        if (! $this->isValidContainerId($this->containerId)) {
            return '';
        }

        $consentInit = $this->renderConsentDefault();
        $dataLayerInit = '';
        if (! empty($this->dataLayer)) {
            $dataLayerInit = "\n  window.dataLayer = window.dataLayer || [];\n".$this->renderDataLayer();
        }

        return <<<HTML
<!-- Google Tag Manager -->{$consentInit}{$dataLayerInit}
<script>(function(w,d,s,l,i){{w[l]=w[l]||[];w[l].push({{'gtm.start':new Date().getTime(),event:'gtm.js'}});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);}})(window,document,'script','dataLayer','{$this->containerId}');</script>
<!-- End Google Tag Manager -->
HTML;
    }

    public function bodyScripts(): string
    {
        if (! $this->isValidContainerId($this->containerId)) {
            return '';
        }

        return <<<HTML
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id={$this->containerId}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
HTML;
    }

    /**
     * Push data to the dataLayer.
     *
     * @param  array<string, mixed>  $data
     */
    public function push(array $data): void
    {
        $this->dataLayer[] = $data;
    }

    /**
     * Get the current dataLayer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDataLayer(): array
    {
        return $this->dataLayer;
    }

    public function getContainerId(): string
    {
        return $this->containerId;
    }

    public function setConsent(ConsentState $state): void
    {
        $this->consent = $state;
    }

    public function getConsent(): ConsentState
    {
        return $this->consent;
    }

    /**
     * Validate GTM Container ID format (GTM-XXXXXXX).
     */
    public function isValidContainerId(string $id): bool
    {
        return preg_match('/^GTM-[A-Z0-9]{5,}$/', $id) === 1;
    }

    /**
     * Render the dataLayer as JavaScript push statements.
     */
    private function renderDataLayer(): string
    {
        $output = '';
        foreach ($this->dataLayer as $item) {
            $json = json_encode($item, JSON_THROW_ON_ERROR);
            $output .= "  window.dataLayer.push({$json});\n";
        }

        return $output;
    }
}
