<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function httpHandlerPublicAccessCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * TST-014 (second finding from the same live-verification session): the
 * plugin's own three page handlers extended
 * `PKP\controllers\page\PageHandler`, whose own `authorize()` override
 * only grants anonymous access to its two built-in ops (`tasks`/`css`)
 * (verified against the real pkp-lib source,
 * `lib/pkp/controllers/page/PageHandler.php`) — every other op, including
 * every real op these handlers define, fell through to a login redirect.
 * Confirmed live on ojs-demo.airixmedia.com after the first TST-014 fix:
 * `/ajdsi/ojsMcpGateway` and `/ajdsi/support-knowledge/about` both
 * returned `302` to `/login?source=...` instead of running the endpoint,
 * even though both are designed to run their own auth (Bearer token/CSRF)
 * without ever requiring an OJS user login.
 *
 * Fix: extend the plain app `Handler` instead, matching the real,
 * already-proven-working pattern `contributorUserSync`'s
 * `ContributorApprovalHandler` uses for its own public custom page on the
 * same OJS install (confirmed via source read on ojs-demo.airixmedia.com's
 * bind-mounted plugin sources — that handler extends `Handler` directly
 * and declares no `authorize()` override at all).
 */

$handlerFiles = [
    'classes/v2/Http/McpGatewayPageHandler.php' => 'McpGatewayPageHandler',
    'classes/v2/Http/SupportKnowledgePageHandler.php' => 'SupportKnowledgePageHandler',
    'classes/v2/Http/SupportGatewayPageHandler.php' => 'SupportGatewayPageHandler',
];

foreach ($handlerFiles as $relativePath => $className) {
    $source = (string) file_get_contents($root . '/' . $relativePath);
    httpHandlerPublicAccessCheck(str_contains($source, 'use APP\handler\Handler;'), "{$className} must import the plain app Handler, not PageHandler");
    httpHandlerPublicAccessCheck(str_contains($source, "class {$className} extends Handler"), "{$className} must extend the plain app Handler directly");
    httpHandlerPublicAccessCheck(!str_contains($source, 'use PKP\controllers\page\PageHandler;'), "{$className} must never import PKP\\controllers\\page\\PageHandler again — its own authorize() silently blocks every non-tasks/css op with a login redirect");
    httpHandlerPublicAccessCheck(!str_contains($source, 'extends PageHandler'), "{$className} must never extend PageHandler again");
}

fwrite(STDOUT, "HTTP handler public access tests passed\n");
