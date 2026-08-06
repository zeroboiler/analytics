<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ZeroBoiler\Analytics\AnalyticsManager;

class InjectAnalyticsScripts
{
    public function __construct(
        protected AnalyticsManager $analytics,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $this->shouldInject($request, $response)) {
            return $response;
        }

        $content = $response->getContent();

        if ($content === false) {
            return $response;
        }

        $headScripts = $this->analytics->headScripts();
        $bodyScripts = $this->analytics->bodyScripts();

        if ($headScripts !== '') {
            $content = $this->injectIntoHead($content, $headScripts);
        }

        if ($bodyScripts !== '') {
            $content = $this->injectIntoBody($content, $bodyScripts);
        }

        $response->setContent($content);

        return $response;
    }

    /**
     * Determine if scripts should be injected into the response.
     */
    private function shouldInject(Request $request, Response $response): bool
    {
        if ($request->expectsJson()) {
            return false;
        }

        if ($response->getStatusCode() >= 400) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        if (! str_contains($contentType, 'text/html')) {
            return false;
        }

        return true;
    }

    /**
     * Inject scripts before the closing </head> tag.
     */
    private function injectIntoHead(string $content, string $scripts): string
    {
        $position = stripos($content, '</head>');

        if ($position === false) {
            return $content;
        }

        return substr($content, 0, $position)."\n".$scripts."\n".substr($content, $position);
    }

    /**
     * Inject scripts right after the opening <body> tag.
     */
    private function injectIntoBody(string $content, string $scripts): string
    {
        $position = stripos($content, '<body');

        if ($position === false) {
            return $content;
        }

        // Find the closing > of the body tag
        $tagEnd = strpos($content, '>', $position);

        if ($tagEnd === false) {
            return $content;
        }

        $insertPosition = $tagEnd + 1;

        return substr($content, 0, $insertPosition)."\n".$scripts."\n".substr($content, $insertPosition);
    }
}
