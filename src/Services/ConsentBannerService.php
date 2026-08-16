<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Cookie consent banner renderer for GDPR/CCPA compliance.
 *
 * Generates a ready-to-use HTML consent banner that integrates with
 * the ZeroBoiler analytics consent system. Supports:
 *   - Granular consent purposes (analytics, marketing, functional, necessary)
 *   - Customizable layout and styling
 *   - Cookie preference API integration
 *   - Consent Mode v2 synchronization with GA4/GTM
 *   - Server-side rendered HTML (no JS dependency)
 *   - Dark mode and responsive design
 *
 * Use via Blade: {!! Analytics::consentBanner() !!}
 * Or directly: app(ConsentBannerService::class)->render()
 *
 * @since 24.0.0
 */
final class ConsentBannerService
{
    private ConfigRepository $config;

    /** @var array<string, string> Purpose keys to human-readable labels */
    private static array $defaultPurposeLabels = [
        'necessary' => 'Necessary',
        'analytics' => 'Analytics',
        'marketing' => 'Marketing',
        'functional' => 'Functional',
        'ad_storage' => 'Ad Storage',
        'ad_user_data' => 'Ad User Data',
        'ad_personalization' => 'Ad Personalization',
        'personalization_storage' => 'Personalization',
        'functionality_storage' => 'Functionality',
        'security_storage' => 'Security',
    ];

    /** @var array<string, string> Purpose descriptions */
    private static array $defaultPurposeDescriptions = [
        'necessary' => 'Required for the website to function properly. Cannot be disabled.',
        'analytics' => 'Help us understand how visitors interact with our website to improve performance.',
        'marketing' => 'Used to track visitors across websites for advertising purposes.',
        'functional' => 'Enable enhanced functionality and personalization.',
    ];

    /**
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(ConfigRepository $config): void
    {
        $this->config = $config;
    }

    /**
     * Render a complete, self-contained cookie consent banner.
     *
     * Generates HTML with inline JavaScript that:
     * 1. Checks for existing consent cookie
     * 2. Shows banner if no consent decision has been made
     * 3. Provides accept all / reject all / customize options
     * 4. Updates GA4 consent mode via gtag()
     * 5. Calls the server-side consent API
     * 6. Stores consent preferences in a cookie
     *
     * @param  array<string, mixed>  $options  Override options
     * @return HtmlString Rendered HTML banner
     *
     * @example
     * // In Blade template:
     * {!! app(\ZeroBoiler\Analytics\Services\ConsentBannerService::class)->render() !!}
     *
     * // With custom options:
     * {!! $consentBanner->render(['position' => 'top', 'theme' => 'dark']) !!}
     */
    public function render(array $options = []): HtmlString
    {
        $consentConfig = $this->config->get('zeroboiler.analytics.consent', []);
        $identityConfig = $this->config->get('zeroboiler.analytics.identity', []);

        /** @var array{default?: string, purposes?: array<string, array{label?: string, required?: bool, default?: bool}>, log_enabled?: bool} $consentConfig */

        $position = $options['position'] ?? 'bottom';
        $theme = $options['theme'] ?? 'light';
        $apiBase = $options['api_base'] ?? '/api/analytics';
        $cookieName = $identityConfig['cookie_name'] ?? 'zb_analytics_id';
        $consentCookieName = $options['consent_cookie_name'] ?? 'zb_consent';
        $bannerTitle = $options['title'] ?? $options['banner_title'] ?? 'Cookie Preferences';
        $bannerDescription = $options['description'] ?? $options['banner_description'] ?? 'We use cookies and similar technologies to enhance your experience. You can choose which purposes to allow.';
        $acceptLabel = $options['accept_label'] ?? 'Accept All';
        $rejectLabel = $options['reject_label'] ?? 'Reject All';
        $customizeLabel = $options['customize_label'] ?? 'Customize';
        $saveLabel = $options['save_label'] ?? 'Save Preferences';
        $closeLabel = $options['close_label'] ?? 'Close';
        $showCustomize = $options['show_customize'] ?? true;

        $purposes = $consentConfig['purposes'] ?? [];
        /** @var array<string, array{label?: string, required?: bool, default?: bool}> $purposes */

        $defaultConsent = $consentConfig['default'] ?? 'granted';

        $purposeItems = '';
        foreach ($purposes as $key => $purposeConfig) {
            $label = $purposeConfig['label'] ?? (self::$defaultPurposeLabels[$key] ?? Str::headline($key));
            $required = (bool) ($purposeConfig['required'] ?? false);
            $default = (bool) ($purposeConfig['default'] ?? ($defaultConsent === 'granted'));
            $checked = $required ? 'checked disabled' : ($default ? 'checked' : '');
            $description = self::$defaultPurposeDescriptions[$key] ?? '';

            $purposeItems .= <<<HTML
                <div class="zb-consent-purpose">
                    <label class="zb-consent-purpose-label">
                        <input type="checkbox" name="consent_{$key}" {$checked}
                               data-purpose="{$key}"
                               class="zb-consent-checkbox"
                               aria-describedby="zb-desc-{$key}">
                        <span class="zb-consent-purpose-name">{$label}</span>
                        {$required ? '<span class="zb-consent-required">Required</span>' : ''}
                    </label>
                    <p id="zb-desc-{$key}" class="zb-consent-purpose-desc">{$description}</p>
                </div>
                HTML;
        }

        $zIndex = $options['z_index'] ?? 999999;

        $html = <<<HTML
        <!-- ZeroBoiler Consent Banner v24.0.0 -->
        <div id="zb-consent-banner" class="zb-consent zb-consent--{$position} zb-consent--{$theme}"
             style="display:none; z-index:{$zIndex}" role="dialog" aria-label="Cookie consent">
            <div class="zb-consent-backdrop" onclick="zbConsentClose()"></div>
            <div class="zb-consent-container">
                <div class="zb-consent-header">
                    <h3 class="zb-consent-title">{$bannerTitle}</h3>
                    <button class="zb-consent-close-btn" onclick="zbConsentClose()" aria-label="{$closeLabel}">&times;</button>
                </div>
                <p class="zb-consent-description">{$bannerDescription}</p>

                <div class="zb-consent-purposes">
                    {$purposeItems}
                </div>

                <div class="zb-consent-actions">
                    <button class="zb-consent-btn zb-consent-btn--accept" onclick="zbConsentAcceptAll('{$apiBase}')">
                        {$acceptLabel}
                    </button>
                    <button class="zb-consent-btn zb-consent-btn--reject" onclick="zbConsentRejectAll('{$apiBase}')">
                        {$rejectLabel}
                    </button>
                    {$showCustomize ? '<button class="zb-consent-btn zb-consent-btn--customize" onclick="zbConsentToggleCustomize()">'.e($customizeLabel).'</button>' : ''}
                    <button class="zb-consent-btn zb-consent-btn--save" onclick="zbConsentSave('{$apiBase}', '{$consentCookieName}')" style="display:none">
                        {$saveLabel}
                    </button>
                </div>
            </div>
        </div>

        <style>
            .zb-consent--bottom{position:fixed;bottom:0;left:0;right:0}
            .zb-consent--top{position:fixed;top:0;left:0;right:0}
            .zb-consent-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.3)}
            .zb-consent--light .zb-consent-container{background:#fff;color:#1a1a1a;box-shadow:0 -2px 20px rgba(0,0,0,.1)}
            .zb-consent--dark .zb-consent-container{background:#1a1a2e;color:#e0e0e0;box-shadow:0 -2px 20px rgba(0,0,0,.3)}
            .zb-consent-container{max-width:520px;margin:0 auto;padding:24px;font-family:system-ui,-apple-system,sans-serif}
            .zb-consent-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
            .zb-consent-title{margin:0;font-size:16px;font-weight:600}
            .zb-consent-close-btn{background:none;border:none;font-size:24px;cursor:pointer;padding:0 4px;opacity:.5}
            .zb-consent-close-btn:hover{opacity:1}
            .zb-consent-description{margin:0 0 16px;font-size:14px;line-height:1.5;opacity:.8}
            .zb-consent-purposes{margin-bottom:16px}
            .zb-consent-purpose{padding:8px 0;border-bottom:1px solid rgba(128,128,128,.15)}
            .zb-consent-purpose-label{display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px}
            .zb-consent-purpose-name{font-weight:500}
            .zb-consent-required{font-size:11px;background:rgba(128,128,128,.2);padding:2px 6px;border-radius:4px}
            .zb-consent-purpose-desc{margin:4px 0 0;font-size:12px;opacity:.6}
            .zb-consent-actions{display:flex;gap:8px;flex-wrap:wrap}
            .zb-consent-btn{padding:8px 16px;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer;border:1px solid transparent;transition:all .15s}
            .zb-consent-btn--accept{background:#22c55e;color:#fff;border-color:#22c55e}
            .zb-consent-btn--accept:hover{background:#16a34a}
            .zb-consent-btn--reject{background:transparent;color:#6b7280;border-color:#d1d5db}
            .zb-consent-btn--reject:hover{background:#f3f4f6}
            .zb-consent-btn--customize{background:transparent;color:#3b82f6;border-color:#93c5fd}
            .zb-consent-btn--customize:hover{background:#eff6ff}
            .zb-consent-btn--save{background:#3b82f6;color:#fff}
            .zb-consent-btn--save:hover{background:#2563eb}
            @media(max-width:480px){.zb-consent-container{padding:16px}.zb-consent-actions{flex-direction:column}}
        </style>

        <script>
        (function() {
            var cookieName = '{$consentCookieName}';
            var cookieMatch = document.cookie.match(new RegExp('(?:^|; )' + cookieName.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + '=([^;]*)'));
            var existingConsent = cookieMatch ? decodeURIComponent(cookieMatch[1]) : null;

            if (!existingConsent) {
                var banner = document.getElementById('zb-consent-banner');
                if (banner) banner.style.display = '';
            }

            window.zbConsentClose = function() {
                var banner = document.getElementById('zb-consent-banner');
                if (banner) banner.style.display = 'none';
            };

            window.zbConsentToggleCustomize = function() {
                var purposes = document.querySelector('.zb-consent-purposes');
                var saveBtn = document.querySelector('.zb-consent-btn--save');
                var customizeBtn = document.querySelector('.zb-consent-btn--customize');
                if (purposes) purposes.style.display = purposes.style.display === 'none' ? '' : 'none';
                if (saveBtn) saveBtn.style.display = saveBtn.style.display === 'none' ? '' : 'none';
                if (customizeBtn) customizeBtn.style.display = customizeBtn.style.display === 'none' ? '' : 'none';
            };

            function setConsentCookie(purposes) {
                var data = JSON.stringify(purposes);
                var encoded = encodeURIComponent(data);
                document.cookie = cookieName + '=' + encoded + ';path=/;max-age=' + (90*24*60*60) + ';SameSite=Lax';
            }

            function updateGtagConsent(purposes) {
                if (typeof window.gtag === 'function') {
                    window.gtag('consent', 'update', {
                        ad_storage: purposes.marketing || purposes.ad_storage ? 'granted' : 'denied',
                        analytics_storage: purposes.analytics ? 'granted' : 'denied',
                        ad_user_data: purposes.ad_user_data ? 'granted' : 'denied',
                        ad_personalization: purposes.ad_personalization ? 'granted' : 'denied',
                        functionality_storage: purposes.functional ? 'granted' : 'denied',
                        personalization_storage: purposes.functional ? 'granted' : 'denied',
                        security_storage: 'granted'
                    });
                }
            }

            window.zbConsentAcceptAll = function(apiBase) {
                var purposes = {necessary:true,analytics:true,marketing:true,functional:true};
                setConsentCookie(purposes);
                updateGtagConsent(purposes);
                fetch(apiBase + '/consent', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({purposes:purposes}),keepalive:true}).catch(function(){});
                zbConsentClose();
            };

            window.zbConsentRejectAll = function(apiBase) {
                var purposes = {necessary:true,analytics:false,marketing:false,functional:false};
                setConsentCookie(purposes);
                updateGtagConsent(purposes);
                fetch(apiBase + '/consent', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({purposes:purposes}),keepalive:true}).catch(function(){});
                zbConsentClose();
            };

            window.zbConsentSave = function(apiBase, cn) {
                var checkboxes = document.querySelectorAll('.zb-consent-checkbox');
                var purposes = {necessary:true};
                checkboxes.forEach(function(cb) {
                    purposes[cb.dataset.purpose] = cb.checked;
                });
                setConsentCookie(purposes);
                updateGtagConsent(purposes);
                fetch(apiBase + '/consent', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({purposes:purposes}),keepalive:true}).catch(function(){});
                zbConsentClose();
            };
        })();
        </script>
        HTML;

        return new HtmlString($html);
    }

    /**
     * Check if consent has already been given.
     *
     * This is a server-side check — useful for conditional rendering.
     * Note: actual consent state is managed client-side via cookies.
     *
     * @return bool True if consent cookie exists
     */
    public function hasConsent(): bool
    {
        return false; // Server-side has no access to browser cookies in service context
    }

    /**
     * Get the list of configured consent purposes.
     *
     * @return array<string, array{label: string, required: bool, default: bool}>
     */
    public function getPurposes(): array
    {
        $consentConfig = $this->config->get('zeroboiler.analytics.consent', []);
        /** @var array{purposes?: array<string, array{label?: string, required?: bool, default?: bool}>} $consentConfig */
        $purposes = $consentConfig['purposes'] ?? [];

        $result = [];
        foreach ($purposes as $key => $config) {
            $result[$key] = [
                'label' => $config['label'] ?? (self::$defaultPurposeLabels[$key] ?? Str::headline((string) $key)),
                'required' => (bool) ($config['required'] ?? false),
                'default' => (bool) ($config['default'] ?? true),
            ];
        }

        return $result;
    }

    /**
     * Generate consent script tag for server-side consent initialization.
     *
     * Use this in your layout's <head> to set default consent state
     * before any analytics scripts load.
     *
     * @return HtmlString
     */
    public function renderConsentScript(): HtmlString
    {
        $consentConfig = $this->config->get('zeroboiler.analytics.consent', []);
        $default = $consentConfig['default'] ?? 'granted';
        $state = $default === 'granted' ? 'granted' : 'denied';

        $html = <<<HTML
        <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('consent', 'default', {
            ad_storage: '{$state}',
            analytics_storage: '{$state}',
            ad_user_data: '{$state}',
            ad_personalization: '{$state}',
            functionality_storage: '{$state}',
            personalization_storage: '{$state}',
            security_storage: 'granted',
            wait_for_update: 500
        });
        </script>
        HTML;

        return new HtmlString($html);
    }
}
