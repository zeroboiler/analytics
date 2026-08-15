<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsSnippetService;
use ZeroBoiler\Analytics\Services\DifferentialPrivacyService;
use ZeroBoiler\Analytics\Services\EventCorrelationMatrixService;

/**
 * Analytics bootstrap snippet generator command.
 *
 * Generates ready-to-paste JavaScript init snippets for all configured
 * analytics providers. Includes provider-specific script tags, Consent Mode v2,
 * ZeroBoiler client init, and consent listener integration.
 *
 * Modes:
 *   (default) — Full bootstrap snippet (head + body + init)
 *   --head     — Head section only (script tags)
 *   --body     — Body section only (noscript, etc.)
 *   --init     — Client init snippet only
 *   --summary  — Show configured provider summary
 *   --consent  — Include consent change listener
 *   --json     — Output as JSON
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsSnippetService
 *
 * @since 42.0.0
 */
final class AnalyticsSnippetCommand extends Command
{
    protected $signature = 'zb:analytics:snippet
        {--head : Output head section only}
        {--body : Output body section only}
        {--init : Output client init only}
        {--summary : Show provider summary}
        {--consent : Include consent change listener}
        {--json : Output as JSON}';

    protected $description = 'Generate analytics bootstrap snippets for all configured providers';

    private AnalyticsSnippetService $snippetService;

    private DifferentialPrivacyService $privacyService;

    private EventCorrelationMatrixService $correlationService;

    public function __construct(
        AnalyticsSnippetService $snippetService,
        DifferentialPrivacyService $privacyService,
        EventCorrelationMatrixService $correlationService,
    ): void {
        parent::__construct();
        $this->snippetService = $snippetService;
        $this->privacyService = $privacyService;
        $this->correlationService = $correlationService;
    }

    #[Override]
    public function handle(): int
    {
        $this->info('🔧 ZeroBoiler Analytics Bootstrap Snippet Generator');
        $this->newLine();

        // Summary mode
        if ($this->option('summary')) {
            return $this->showSummary();
        }

        // JSON mode
        if ($this->option('json')) {
            $this->line(json_encode($this->snippetService->fullSnippet([
                'include_consent' => $this->option('consent'),
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        // Head only
        if ($this->option('head')) {
            $head = $this->snippetService->headSnippet();

            if ($head['html'] === '') {
                $this->warn('No analytics providers configured.');
                $this->line('Set up at least one provider in config/zeroboiler.php under analytics.');

                return self::FAILURE;
            }

            $this->line('<comment><!-- Analytics Head Scripts — ZeroBoiler v42.0.0 --></comment>');
            $this->line($head['html']);
            $this->newLine();
            $this->info("Enabled providers: " . implode(', ', $head['providers']));

            return self::SUCCESS;
        }

        // Body only
        if ($this->option('body')) {
            $body = $this->snippetService->bodySnippet();

            if ($body === '') {
                $this->warn('No body scripts configured.');

                return self::SUCCESS;
            }

            $this->line($body);

            return self::SUCCESS;
        }

        // Init only
        if ($this->option('init')) {
            $init = $this->snippetService->clientInitSnippet(
                includeConsentListener: $this->option('consent'),
            );
            $this->line($init);

            return self::SUCCESS;
        }

        // Full snippet (default)
        return $this->showFullSnippet();
    }

    /**
     * Show the full bootstrap snippet.
     *
     * @return int Command exit code
     */
    private function showFullSnippet(): int
    {
        $snippet = $this->snippetService->fullSnippet([
            'include_consent' => $this->option('consent'),
        ]);

        if (empty($snippet['providers'])) {
            $this->warn('No analytics providers configured.');
            $this->line('Set up at least one provider in config/zeroboiler.php.');
            $this->newLine();
            $this->line('Available providers: GA4, GTM, Meta Pixel, Plausible, PostHog, Mixpanel, Amplitude, TikTok, LinkedIn');

            return self::FAILURE;
        }

        // Head
        $this->line('<comment>┌─────────────────────────────────────────────┐</comment>');
        $this->line('<comment>│  ZeroBoiler Analytics — Full Bootstrap       │</comment>');
        $this->line('<comment>│  v42.0.0 — Copy to your Blade/layout root    │</comment>');
        $this->line('<comment>└─────────────────────────────────────────────┘</comment>');
        $this->newLine();
        $this->line('<fg=yellow;options=bold><!-- HEAD SECTION — Paste in <head> --></>');
        $this->line($snippet['head']);
        $this->newLine();

        // Body
        if ($snippet['body'] !== '') {
            $this->line('<fg=yellow;options=bold><!-- BODY SECTION — Paste at start of <body> --></>');
            $this->line($snippet['body']);
            $this->newLine();
        }

        // Init
        $this->line('<fg=yellow;options=bold><!-- CLIENT INIT — Paste before closing </head> --></>');
        $this->line($snippet['init']);
        $this->newLine();

        // Privacy & correlation status
        $privacy = $this->privacyService->status();
        if ($privacy['enabled']) {
            $this->line("<fg=cyan>Differential Privacy: ε={$privacy['epsilon']} (budget remaining: {$privacy['remaining']})</>");
        }

        $correlation = $this->correlationService->summary();
        if ($correlation['enabled']) {
            $this->line("<fg=cyan>Event Correlation Matrix: {$correlation['tracked_pairs']} pairs tracked, {$correlation['significant_pairs']} significant</>");
        }

        $this->newLine();
        $this->info('Enabled providers: ' . implode(', ', $snippet['providers']));
        $this->newLine();
        $this->line('Run <fg=cyan>zb:analytics:snippet --summary</> for a masked provider overview.');
        $this->line('Run <fg=cyan>zb:analytics:snippet --head --json</> for machine-readable output.');

        return self::SUCCESS;
    }

    /**
     * Show the provider summary.
     *
     * @return int Command exit code
     */
    private function showSummary(): int
    {
        $summary = $this->snippetService->providerSummary();

        $this->table(
            ['Provider', 'Configured', 'ID (masked)'],
            array_map(
                fn (array $p): array => [
                    $p['name'],
                    $p['configured'] ? '<fg=green>✓</>' : '<fg=red>✗</>',
                    $p['id_masked'],
                ],
                $summary['providers'],
            ),
        );

        $configured = array_filter($summary['providers'], fn (array $p): bool => $p['configured']);
        $this->newLine();
        $this->info(count($configured) . ' of ' . count($summary['providers']) . ' providers configured');

        return self::SUCCESS;
    }
}
