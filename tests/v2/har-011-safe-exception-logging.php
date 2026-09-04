<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\SafeExceptionMessage;

function har011Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * HAR-011: raw `$e->getMessage()` from an external HTTP/SMTP/provider
 * exception can embed the full request URI (query-string tokens
 * included), response bodies, or other operator-visible-but-sensitive
 * data. Proves the real behavior of SafeExceptionMessage::describe():
 * a raw message containing a token-bearing URL and a response body is
 * never present in the output, only a safe class-name summary.
 */
$sensitive = new \RuntimeException('GET https://support.airixmedia.com/api/v1/accounts/2/profile?api_access_token=Sc3fddUvxiYwG8TEsL7QnMUU resulted in a 401 Unauthorized response: {"error":"Invalid access token","internal_ip":"10.0.4.12"}');
$described = SafeExceptionMessage::describe($sensitive);

har011Check(!str_contains($described, 'Sc3fddUvxiYwG8TEsL7QnMUU'), 'describe() must never leak an access token embedded in a raw exception message');
har011Check(!str_contains($described, 'support.airixmedia.com'), 'describe() must never leak a request URL embedded in a raw exception message');
har011Check(!str_contains($described, 'internal_ip'), 'describe() must never leak response-body detail embedded in a raw exception message');
har011Check($described === 'RuntimeException', 'describe() must fall back to a safe class-name label when there is no HTTP response context to extract a status code from');

// ================================================================
// Real wiring: every real call site the audit named must actually
// use the safe describer, not the raw exception message.
// ================================================================
$callSites = [
    "{$root}/ChatwootApiService.php" => ['$e->getMessage()'],
    "{$root}/ChatwootIntegrationBasePlugin.php" => ['$e->getMessage()'],
    "{$root}/classes/v2/Provider/SupportProviderRegistry.php" => ['$e->getMessage()'],
    "{$root}/classes/v2/Knowledge/KnowledgeCompiler.php" => ['$e->getMessage()'],
    "{$root}/classes/v2/Task/CaptainSyncScheduledTask.php" => ['$e->getMessage()'],
    "{$root}/classes/v2/Task/FaqCacheSyncScheduledTask.php" => ['$e->getMessage()'],
    "{$root}/classes/v2/Task/ProcessLegacyRetryQueueScheduledTask.php" => ['$e->getMessage()'],
    "{$root}/classes/v2/Task/DeliverQueuedSupportEventsTask.php" => ['$e->getMessage()'],
    "{$root}/classes/v2/Task/PurgeExpiredSupportDataTask.php" => ['$e->getMessage()'],
];
foreach ($callSites as $path => $forbidden) {
    $source = (string) file_get_contents($path);
    har011Check(str_contains($source, 'SafeExceptionMessage::describe('), "{$path} must use SafeExceptionMessage::describe() somewhere — it previously logged a raw exception message");
    foreach ($forbidden as $pattern) {
        har011Check(!str_contains($source, $pattern), "{$path} must never call {$pattern} directly anymore — every logging/error-reporting call site must go through SafeExceptionMessage");
    }
}

// McpDispatcher's one remaining $e->getMessage() call is safe by
// construction: every McpHandlerError in this codebase is built from a
// hardcoded, developer-authored string literal (or a SupportApiFailure
// whose own message field is always a hardcoded literal too) — never a
// raw external exception's message. Confirm that assumption still
// holds structurally: the generic \Throwable catch (any *other*
// exception type) must never expose its message to the MCP client.
$mcpDispatcherSource = (string) file_get_contents("{$root}/classes/v2/Mcp/McpDispatcher.php");
har011Check(
    (bool) preg_match('/catch \(\\\\Throwable \$e\) \{[^}]*McpErrorCode::INTERNAL_ERROR,\s*\'The request could not be completed\.\'/s', $mcpDispatcherSource),
    'McpDispatcher must never expose a generic (non-McpHandlerError) exception message to an MCP client — only the curated, hardcoded McpHandlerError message path is allowed to reach the client'
);

fwrite(STDOUT, "HAR-011 safe-exception-logging tests passed\n");
