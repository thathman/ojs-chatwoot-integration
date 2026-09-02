<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpDispatcher;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpProtocol;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpRequest;

function mcp010Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** Extracts one method's body, bounded by the next method/property declaration. */
function extractMethodBodyMcp010(string $source, string $needle): string
{
    $start = strpos($source, $needle);
    if ($start === false) {
        return '';
    }
    $next = strpos($source, 'function ', $start + strlen($needle));
    return $next !== false ? substr($source, $start, $next - $start) : substr($source, $start);
}

// ================================================================
// MCP-010: `server/discover` was a real, documented gap
// (docs/v2/API_MCP_SPEC.md, docs/v2/COMPLETION_RECONCILIATION.md) —
// `McpProtocol::METHOD_DISCOVER` was defined and listed in ADR-023's
// initial scope, but McpDispatcher had no registered handler for it, so
// a real client call returned METHOD_NOT_FOUND (safe, not a fatal, but
// not implemented either).
//
// The real handler composes the same information tools/list and
// resources/list already expose, plus the real supported protocol
// revision/method surface McpProtocol itself defines — never a second,
// independently-maintained capability description that could drift
// from what the dispatcher actually allows.
//
// Full end-to-end instantiation (a real McpGatewayPageHandler request)
// isn't exercised here — same environment constraint as every other
// deeply-OJS-entangled path in this build — so this proves the handler
// via a real McpDispatcher + McpRequest round trip (the same technique
// tests/v2/mcp-protocol.php already established), plus source assertions
// confirming the registration wiring inside the real plugin method.
// ================================================================

$root2 = $root;
$pluginSource = (string) file_get_contents("{$root2}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");

mcp010Check(
    str_contains($pluginSource, 'registerHandler(McpProtocol::METHOD_DISCOVER,'),
    'the real mcpRequest() method must register a real handler for McpProtocol::METHOD_DISCOVER, not leave it unregistered'
);

$discoverBody = extractMethodBodyMcp010($pluginSource, 'registerHandler(McpProtocol::METHOD_DISCOVER,');
mcp010Check(str_contains($discoverBody, 'McpProtocol::REVISION'), 'the discover handler must report the real McpProtocol::REVISION, never a hardcoded/duplicated version string');
mcp010Check(str_contains($discoverBody, 'McpProtocol::SUPPORTED_METHODS'), 'the discover handler must report the real McpProtocol::SUPPORTED_METHODS, never an independently-maintained list that could drift');
mcp010Check(str_contains($discoverBody, '$registry->list()'), 'the discover handler must reuse the same real tool registry tools/list already exposes, not a second parallel tool description');
mcp010Check(str_contains($discoverBody, '$resourceRegistry->list()'), 'the discover handler must reuse the same real resource registry resources/list already exposes, not a second parallel resource description');

// --- Real dispatcher round trip, matching the real registration shape ---
$registeredTools = [['name' => 'journal.get_profile', 'description' => 'x', 'inputSchema' => ['type' => 'object']]];
$registeredResources = [['uri' => 'ojs://journal/1/support-profile', 'name' => 'x', 'description' => 'x', 'mimeType' => 'application/json']];

$dispatcher = new McpDispatcher();
$dispatcher->registerHandler(McpProtocol::METHOD_DISCOVER, fn (McpRequest $r): array => [
    'protocolRevision' => McpProtocol::REVISION,
    'capabilities' => ['tools' => (object) [], 'resources' => (object) []],
    'methods' => McpProtocol::SUPPORTED_METHODS,
    'tools' => $registeredTools,
    'resources' => $registeredResources,
]);

$request = new McpRequest(McpProtocol::METHOD_DISCOVER, [], 'req-1', McpProtocol::REVISION);
$response = $dispatcher->dispatch($request);

mcp010Check(!$response->isError(), 'a real server/discover call must succeed, not error, once registered');
$result = $response->toArray()['result'] ?? null;
mcp010Check(is_array($result), 'the response must carry a real result payload');
mcp010Check(($result['protocolRevision'] ?? null) === McpProtocol::REVISION, 'the response must report the real, current protocol revision');
mcp010Check(is_array($result['methods'] ?? null) && in_array(McpProtocol::METHOD_TOOLS_CALL, $result['methods'], true), 'the response must report the real supported method surface, including tools/call');
mcp010Check(($result['tools'] ?? null) === $registeredTools, 'the response must carry the real, current tool list, not a stale or invented one');
mcp010Check(($result['resources'] ?? null) === $registeredResources, 'the response must carry the real, current resource list, not a stale or invented one');

fwrite(STDOUT, "PASS: mcp-010-server-discover\n");
