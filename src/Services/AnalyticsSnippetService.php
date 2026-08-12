<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Analytics bootstrap snippet generator.
 *
 * Generates ready-to-paste JavaScript initialization snippets with all
 * configured provider IDs pre-filled. Supports GA4 (gtag), GTM (dataLayer),
 * Meta Pixel (fbq), Plausible, PostHog, Mixpanel, Amplitude, TikTok, and LinkedIn.
 *
 * Each snippet is self-contained and includes:
 * - Provider-specific script tags (head + body where applicable)
 * - Consent Mode v2 default command (GA4)
 * - ZeroBoiler client init call with provider config
 * - Optional cookie consent listener integration
 *
 * Use the `zb:analytics:snippet` Artisan command or call this service directly
 * from your frontend scaffolding tooling.
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager::headScripts()
 * @see \ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts
 *
 * @since 42.0.0
 */
final class AnalyticsSnippetService
{
    private ConfigRepository $config;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config)
    {
        $this->config = $config;
    }

    /**
     * Generate the full HTML head snippet for all enabled providers.
     *
     * Includes script tags for each provider and the ZeroBoiler client init.
     *
     * @return array{html: string, providers: list<string>, consent_mode: bool}
     */
    public function headSnippet(): array
    {
        $parts = [];
        $providers = [];
        $hasConsentMode = false;

        // GA4 (gtag.js)
        $ga4Id = $this->config->get('zeroboiler.analytics.ga4.measurement_id', '');
        if ($ga4Id !== '' && $this->isEnabled('ga4')) {
            $parts[] = $this->ga4Snippet($ga4Id);
            $providers[] = 'ga4';
            $hasConsentMode = true;
        }

        // GTM
        $gtmId = $this->config->get('zeroboiler.analytics.gtm.container_id', '');
        if ($gtmId !== '' && $this->isEnabled('gtm')) {
            $parts[] = $this->gtmHeadSnippet($gtmId);
            $providers[] = 'gtm';
        }

        // Meta Pixel
        $metaId = $this->config->get('zeroboiler.analytics.meta_pixel.id', '');
        if ($metaId !== '' && $this->isEnabled('meta_pixel')) {
            $parts[] = $this->metaPixelSnippet($metaId);
            $providers[] = 'meta';
        }

        // Plausible
        $plausibleDomain = $this->config->get('zeroboiler.analytics.plausible.domain', '');
        if ($plausibleDomain !== '' && $this->isEnabled('plausible')) {
            $parts[] = $this->plausibleSnippet($plausibleDomain);
            $providers[] = 'plausible';
        }

        // PostHog
        $posthogKey = $this->config->get('zeroboiler.analytics.posthog.api_key', '');
        $posthogHost = $this->config->get('zeroboiler.analytics.posthog.host', 'https://eu.posthog.com');
        if ($posthogKey !== '' && $this->isEnabled('posthog')) {
            $parts[] = $this->posthogSnippet($posthogKey, $posthogHost);
            $providers[] = 'posthog';
        }

        // Mixpanel
        $mixpanelToken = $this->config->get('zeroboiler.analytics.mixpanel.token', '');
        if ($mixpanelToken !== '' && $this->isEnabled('mixpanel')) {
            $parts[] = $this->mixpanelSnippet($mixpanelToken);
            $providers[] = 'mixpanel';
        }

        // Amplitude
        $amplitudeKey = $this->config->get('zeroboiler.analytics.amplitude.api_key', '');
        if ($amplitudeKey !== '' && $this->isEnabled('amplitude')) {
            $parts[] = $this->amplitudeSnippet($amplitudeKey);
            $providers[] = 'amplitude';
        }

        // TikTok Pixel
        $tiktokId = $this->config->get('zeroboiler.analytics.tiktok.pixel_id', '');
        if ($tiktokId !== '' && $this->isEnabled('tiktok')) {
            $parts[] = $this->tiktokSnippet($tiktokId);
            $providers[] = 'tiktok';
        }

        // LinkedIn Insight Tag
        $linkedinId = $this->config->get('zeroboiler.analytics.linkedin.partner_id', '');
        if ($linkedinId !== '' && $this->isEnabled('linkedin')) {
            $parts[] = $this->linkedinSnippet($linkedinId);
            $providers[] = 'linkedin';
        }

        return [
            'html' => implode("\n\n", $parts),
            'providers' => $providers,
            'consent_mode' => $hasConsentMode,
        ];
    }

    /**
     * Generate the full HTML body snippet (GTM noscript, Meta body code).
     *
     * @return string
     */
    public function bodySnippet(): string
    {
        $parts = [];

        // GTM noscript
        $gtmId = $this->config->get('zeroboiler.analytics.gtm.container_id', '');
        if ($gtmId !== '' && $this->isEnabled('gtm')) {
            $parts[] = $this->gtmBodySnippet($gtmId);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Generate the ZeroBoiler client initialization snippet.
     *
     * @param  string|null  $apiBase  Override API base URL
     * @param  bool  $includeConsentListener  Include consent change listener
     * @return string
     */
    public function clientInitSnippet(?string $apiBase = null, bool $includeConsentListener = false): string
    {
        $baseUrl = $apiBase ?? '/api/analytics';

        $snippet = <<<JS
<script type="module">
// ZeroBoiler Analytics — Client Init
// @version 42.0.0
import { init, trackPageView, flushQueue } from './resources/js/analytics.js';

// Wait for Inertia page props to be available
const waitForProps = () => {
    const props = window.__inertia?.page?.props;
    if (props?.zbAnalytics) {
        init(props);
        trackPageView();
        return true;
    }
    return false;
};

// Retry with backoff (max 5 attempts)
let attempts = 0;
const tryInit = setInterval(() => {
    if (waitForProps() || ++attempts >= 5) {
        clearInterval(tryInit);
    }
}, 200);

// Flush on page unload
window.addEventListener('beforeunload', () => flushQueue());
</script>
JS;

        if ($includeConsentListener) {
            $snippet .= "\n" . $this->consentListenerSnippet();
        }

        return $snippet;
    }

    /**
     * Generate the complete standalone HTML snippet (head + body + init).
     *
     * For use in documentation, scaffolding tools, or copy-paste setup.
     *
     * @param  array{api_base?: string, include_consent?: bool, title?: string}  $options
     * @return array{head: string, body: string, init: string, providers: list<string>}
     */
    public function fullSnippet(array $options = []): array
    {
        $head = $this->headSnippet();
        $body = $this->bodySnippet();
        $init = $this->clientInitSnippet(
            apiBase: $options['api_base'] ?? null,
            includeConsentListener: $options['include_consent'] ?? false,
        );

        return [
            'head' => $head['html'],
            'body' => $body,
            'init' => $init,
            'providers' => $head['providers'],
        ];
    }

    /**
     * Generate a summary of configured providers with IDs (masked for display).
     *
     * @return array{providers: list<array{name: string, configured: bool, id_masked: string|null}>}
     */
    public function providerSummary(): array
    {
        $map = [
            'ga4' => ['label' => 'GA4', 'config_key' => 'ga4.measurement_id'],
            'gtm' => ['label' => 'GTM', 'config_key' => 'gtm.container_id'],
            'meta_pixel' => ['label' => 'Meta Pixel', 'config_key' => 'meta_pixel.id'],
            'plausible' => ['label' => 'Plausible', 'config_key' => 'plausible.domain'],
            'posthog' => ['label' => 'PostHog', 'config_key' => 'posthog.api_key'],
            'mixpanel' => ['label' => 'Mixpanel', 'config_key' => 'mixpanel.token'],
            'amplitude' => ['label' => 'Amplitude', 'config_key' => 'amplitude.api_key'],
            'tiktok' => ['label' => 'TikTok', 'config_key' => 'tiktok.pixel_id'],
            'linkedin' => ['label' => 'LinkedIn', 'config_key' => 'linkedin.partner_id'],
            'webhook' => ['label' => 'Webhook', 'config_key' => 'webhook.url'],
        ];

        $result = [];
        foreach ($map as $key => $info) {
            $id = $this->config->get("zeroboiler.analytics.{$info['config_key']}", '');
            $configured = $id !== '' && $this->isEnabled($key);

            $result[] = [
                'name' => $info['label'],
                'configured' => $configured,
                'id_masked' => $this->maskId($id),
            ];
        }

        return ['providers' => $result];
    }

    // ── Provider-Specific Snippets ──────────────────────────────────

    /**
     * Generate GA4 gtag.js snippet with Consent Mode v2.
     *
     * @param  string  $measurementId  GA4 Measurement ID (G-XXXXXXXXXX)
     * @return string
     */
    private function ga4Snippet(string $measurementId): string
    {
        return <<<HTML
<!-- Google Analytics 4 (GA4) — ZeroBoiler Analytics v42.0.0 -->
<script async src="https://www.googletagmanager.com/gtag/js?id={$measurementId}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  // Consent Mode v2 — GDPR-safe defaults
  gtag('consent', 'default', {
    'analytics_storage': 'denied',
    'ad_storage': 'denied',
    'ad_user_data': 'denied',
    'ad_personalization': 'denied',
    'functionality_storage': 'granted',
    'security_storage': 'granted',
    'wait_for_update': 500
  });

  gtag('config', '{$measurementId}', {
    'send_page_view': false
  });
</script>
HTML;
    }

    /**
     * Generate GTM head snippet.
     *
     * @param  string  $containerId  GTM Container ID (GTM-XXXXXXX)
     * @return string
     */
    private function gtmHeadSnippet(string $containerId): string
    {
        return <<<HTML
<!-- Google Tag Manager (GTM) — ZeroBoiler Analytics v42.0.0 -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','{$containerId}');</script>
HTML;
    }

    /**
     * Generate GTM body noscript snippet.
     *
     * @param  string  $containerId  GTM Container ID (GTM-XXXXXXX)
     * @return string
     */
    private function gtmBodySnippet(string $containerId): string
    {
        return <<<HTML
<!-- Google Tag Manager (noscript) — ZeroBoiler Analytics v42.0.0 -->
<noscript>
<iframe src="https://www.googletagmanager.com/ns.html?id={$containerId}"
height="0" width="0" style="display:none;visibility:hidden"></iframe>
</noscript>
HTML;
    }

    /**
     * Generate Meta Pixel (fbq) snippet.
     *
     * @param  string  $pixelId  Meta Pixel ID
     * @return string
     */
    private function metaPixelSnippet(string $pixelId): string
    {
        return <<<HTML
<!-- Meta Pixel — ZeroBoiler Analytics v42.0.0 -->
<script>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window, document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', '{$pixelId}');
  fbq('track', 'PageView');
</script>
<noscript>
<img height="1" width="1" style="display:none"
     src="https://www.facebook.com/tr?id={$pixelId}&ev=PageView&noscript=1"/>
</noscript>
HTML;
    }

    /**
     * Generate Plausible Analytics snippet.
     *
     * @param  string  $domain  Plausible domain
     * @return string
     */
    private function plausibleSnippet(string $domain): string
    {
        return <<<HTML
<!-- Plausible Analytics — ZeroBoiler Analytics v42.0.0 -->
<script async defer data-domain="{$domain}"
        src="https://plausible.io/js/script.js"></script>
HTML;
    }

    /**
     * Generate PostHog snippet.
     *
     * @param  string  $apiKey  PostHog API key
     * @param  string  $host  PostHog host URL
     * @return string
     */
    private function posthogSnippet(string $apiKey, string $host): string
    {
        return <<<HTML
<!-- PostHog — ZeroBoiler Analytics v42.0.0 -->
<script>
  !function(t,e){var o,n,p,r;e.__SV||((window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.async=!0,p.src=s.api_host+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="capture identify alias people.set people.set_once reset unregister on".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
  posthog.init('{$apiKey}', {api_host: '{$host}'});
</script>
HTML;
    }

    /**
     * Generate Mixpanel snippet.
     *
     * @param  string  $token  Mixpanel project token
     * @return string
     */
    private function mixpanelSnippet(string $token): string
    {
        return <<<HTML
<!-- Mixpanel — ZeroBoiler Analytics v42.0.0 -->
<script>
  (function(c,a){if(!a.__SV){var b=window;try{var d,m,j,k=b.location,f=k.hash;try{d=function(){try{var a={};if(b.navigator&&void 0!==b.navigator.userAgent){var c=b.navigator.userAgent;a.ch=a.userAgent=c}return a}catch(b){}}();d&&d.ch&&(f=d.ch+f)}catch(g){}m=f.match(/[^A-Za-z0-9_]/g);m&&(m=m.map(function(a){return"$"+a.charCodeAt(0).toString(16)}).join(""),f=m+"#"+f);var h=a.__SV=1.0}catch(g){}e=""+Math.random().toString(36).slice(2,8);a.fc=""+Math.random().toString(36).slice(2,8);try{j=JSON.parse(localStorage.getItem("__mpdef"))}catch(g){j=null}try{k=j||JSON.parse(localStorage.getItem("__mpa")||"[]")}catch(g){k=[]}try{k=k.filter(function(a){return a.rg.test(b.location.host)})}catch(g){k=[]}var l;k.length?(l=k[0].t,l!==e&&(localStorage.removeItem("__mpa"),localStorage.removeItem("__mpdef"),l=null)):l=null;var i={lib:"v2-ecommerce",i18n:{},};try{var n=JSON.parse(localStorage.getItem("__mpauth_"+l));n&&(i.userId=n.uid,i.deviceId=n.did)}catch(g){}c.init('{$token}',{debug:!1,track_links_timeout:300,persistence:"localStorage+cookie",cookie_name:"__mpa",ignore_dnt:!0},i);
  })(document,window.mixpanel||[]);
</script>
HTML;
    }

    /**
     * Generate Amplitude snippet.
     *
     * @param  string  $apiKey  Amplitude API key
     * @return string
     */
    private function amplitudeSnippet(string $apiKey): string
    {
        return <<<HTML
<!-- Amplitude — ZeroBoiler Analytics v42.0.0 -->
<script type="text/javascript">
  (function(e,t){var r=e.amplitude||{_q:[],_iq:{}};var n=t.createElement("script");n.type="text/javascript";n.async=true;n.src="https://cdn.amplitude.com/libs/amplitude-8.21.0-min.gz.js";n.onload=function(){if(!e.amplitude.runQueuedFunctions){e.amplitude.runQueuedFunctions=function(){for(var t=0;t<e.amplitude._q.length;t++){var n=e.amplitude._q[t];e.amplitude[n].apply(e.amplitude,arguments)}};for(var r=0;r<r._iq.length;r++){n=r._iq[r];e.amplitude.init(n.apiKey,n.config).invoke(n.name)}}};var s=t.getElementsByTagName("script")[0];s.parentNode.insertBefore(n,s);function i(e,t){e.prototype[t]=function(){this._q.push([t].concat(Array.prototype.slice.call(arguments,0)));return this}}var o=function(){this._q=[];return this};var a=["add","append","clearAll","prepend","set","setOnce","unset","preInsert","postInsert","remove","getUserProperties"];for(var u=0;u<a.length;u++){i(o,a[u])}r.Identify=o;var c=function(){this._q=[];return this};var p=["setProductId","setQuantity","setPrice","setRevenueType","setEventProperties"];for(var l=0;l<p.length;l++){i(c,p[l])}r.Revenue=c;var d=["init","logEvent","logRevenue","setUserId","setUserProperties","setOptOut","setVersionName","setDomain","setDeviceId","enableTracking","setGlobalUserProperties","identify","clearUserProperties","setGroup","logEventWithTimestamp","logEventWithGroups","setSessionId","resetSessionId"];for(var f=0;f<d.length;f++){i(r,d[f])}r.getInstance=function(e){return e||(e=r._iq.length),r._iq[e]};e.amplitude=r})(window,document);
  amplitude.init('{$apiKey}', null, {includeUtm: true, includeReferrer: true, includeGclid: true});
</script>
HTML;
    }

    /**
     * Generate TikTok Pixel snippet.
     *
     * @param  string  $pixelId  TikTok Pixel ID
     * @return string
     */
    private function tiktokSnippet(string $pixelId): string
    {
        return <<<HTML
<!-- TikTok Pixel — ZeroBoiler Analytics v42.0.0 -->
<script>
  !function (w, d, t) {
    w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];
    ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"],
    ttq.setAndDefer=function(t,e){ttq[e]=function(){ttq.push([e,t])}};
    for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq.methods[i],ttq.methods[i]);
    ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);
    return e};ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._n=i;
    var a=document.createElement("script");a.type="text/javascript",a.async=!0,a.src=i+"?sdkid="+e+"&lib="+t;
    var s=document.getElementsByTagName("script")[0];s.parentNode.insertBefore(a,s)};
    ttq.load('{$pixelId}');
    ttq.page();
  }(window, document, 'ttq');
</script>
HTML;
    }

    /**
     * Generate LinkedIn Insight Tag snippet.
     *
     * @param  string  $partnerId  LinkedIn Partner ID
     * @return string
     */
    private function linkedinSnippet(string $partnerId): string
    {
        return <<<HTML
<!-- LinkedIn Insight Tag — ZeroBoiler Analytics v42.0.0 -->
<script type="text/javascript">
  _linkedin_partner_id = "{$partnerId}";
  window._linkedin_data_partner_ids = window._linkedin_data_partner_ids || [];
  window._linkedin_data_partner_ids.push(_linkedin_partner_id);
  (function(l) {
    if (!l){window.lintrk = function(a,b){window.lintrk.q.push([a,b])};
    window.lintrk.q=[]}
    var s = document.getElementsByTagName("script")[0];
    var b = document.createElement("script");
    b.type = "text/javascript";b.async = true;
    b.src = "https://snap.licdn.com/li.lms-analytics/insight.min.js";
    s.parentNode.insertBefore(b, s);
  })(window.lintrk);
</script>
<noscript>
<img height="1" width="1" style="display:none;" alt=""
     src="https://px.ads.linkedin.com/collect/?pid={$partnerId}&fmt=gif"/>
</noscript>
HTML;
    }

    /**
     * Generate consent change listener snippet.
     *
     * Listens for consent state changes and updates GA4 Consent Mode
     * and Meta Pixel consent accordingly.
     *
     * @return string
     */
    private function consentListenerSnippet(): string
    {
        return <<<JS
<script>
// ZeroBoiler Consent Listener — updates providers on consent change
window.addEventListener('zb:consent', (event) => {
    const consent = event.detail;
    const granted = consent.analytics === true;

    // Update GA4 Consent Mode v2
    if (typeof gtag === 'function') {
        gtag('consent', 'update', {
            'analytics_storage': granted ? 'granted' : 'denied',
            'ad_storage': consent.marketing ? 'granted' : 'denied',
            'ad_user_data': consent.marketing ? 'granted' : 'denied',
            'ad_personalization': consent.marketing ? 'granted' : 'denied',
        });
    }

    // Update Meta Pixel consent
    if (typeof fbq === 'function') {
        fbq('consent', granted ? 'grant' : 'revoke');
    }
});
</script>
JS;
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Check if a provider is enabled in config.
     *
     * @param  string  $provider  Config key (e.g., 'ga4', 'gtm', 'meta_pixel')
     */
    private function isEnabled(string $provider): bool
    {
        return (bool) ($this->config->get("zeroboiler.analytics.{$provider}.enabled", false));
    }

    /**
     * Mask an ID for safe display (show first 4 and last 4 chars).
     *
     * @param  string  $id  Full identifier
     * @return string  Masked identifier or '(not configured)'
     */
    private function maskId(string $id): string
    {
        if ($id === '') {
            return '(not configured)';
        }

        $length = strlen($id);

        if ($length <= 8) {
            return str_repeat('•', $length - 2) . substr($id, -2);
        }

        return substr($id, 0, 4) . str_repeat('•', $length - 8) . substr($id, -4);
    }
}
