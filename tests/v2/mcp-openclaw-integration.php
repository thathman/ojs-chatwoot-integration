<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpDispatcher;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpErrorCode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpProtocol;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpRequestParser;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpResourceRegistry;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpResponse;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpToolRegistry;

function mcpOpenClawCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * MCP-008: no OpenClaw-specific SDK or wire format exists anywhere in this
 * codebase or its docs — "OpenClaw" in docs/v2/BUILD_PLAN.md and
 * docs/v2/ARCHITECTURE.md names one example of a generic external MCP
 * client/agent, not a dedicated integration point. Fabricating an
 * OpenClaw-specific API here would test something that does not exist.
 *
 * What this test proves instead, honestly: a generic, spec-compliant MCP
 * client — exactly what OpenClaw or any other MCP-capable agent actually
 * is from this server's point of view — can complete a real round trip
 * against the same McpRequestParser -> McpDispatcher pipeline
 * mcpRequest() assembles per request, using only raw JSON-RPC bytes over
 * the wire, never a PHP-level shortcut into the tool/resource registry.
 * This exercises the actual external contract (MCP-001's whole point),
 * not per-tool business logic — that is already covered per-tool in
 * tests/v2/mcp-tools.php and tests/v2/mcp-identity.php.
 */

function mcpOpenClawDispatch(McpDispatcher $dispatcher, string $rawBody): array
{
    $parsed = McpRequestParser::parse($rawBody, ['Mcp-Protocol-Version' => McpProtocol::REVISION]);
    $response = $parsed instanceof McpResponse ? $parsed : $dispatcher->dispatch($parsed);
    return $response->toArray();
}

// One representative tool and one representative resource — enough to
// prove the wire protocol works end to end; not a re-test of every
// already-built tool/resource's business logic.
$toolRegistry = new McpToolRegistry();
$toolRegistry->register(
    'journal.get_profile',
    'Returns public journal support facts.',
    ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    fn (array $arguments): array => ['journal.name' => 'A Safe Journal']
);

$resourceRegistry = new McpResourceRegistry();
$resourceRegistry->register(
    'ojs://journal/7/support-profile',
    'Journal support profile',
    'Public journal.* support facts.',
    'application/json',
    fn (): array => ['journal.name' => 'A Safe Journal']
);

$dispatcher = new McpDispatcher();
$dispatcher->registerHandler(McpProtocol::METHOD_TOOLS_LIST, fn ($r): array => ['tools' => $toolRegistry->list()]);
$dispatcher->registerHandler(McpProtocol::METHOD_TOOLS_CALL, function ($r) use ($toolRegistry): array {
    $name = $r->params()['name'] ?? null;
    $arguments = $r->params()['arguments'] ?? [];
    return ['content' => $toolRegistry->call((string) $name, (array) $arguments)];
});
$dispatcher->registerHandler(McpProtocol::METHOD_RESOURCES_LIST, fn ($r): array => ['resources' => $resourceRegistry->list()]);
$dispatcher->registerHandler(McpProtocol::METHOD_RESOURCES_READ, function ($r) use ($resourceRegistry): array {
    $uri = $r->params()['uri'] ?? null;
    return ['contents' => [$resourceRegistry->read((string) $uri)]];
});

// ================================================================
// 1. tools/list — a client discovers the tool surface before calling
//    anything, exactly as MCP-001's stateless-per-request model expects.
// ================================================================
$listToolsBody = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => McpProtocol::METHOD_TOOLS_LIST, 'params' => []]);
$listToolsResult = mcpOpenClawDispatch($dispatcher, $listToolsBody);
mcpOpenClawCheck(!isset($listToolsResult['error']), 'a real generic MCP client must be able to list tools without any prior handshake');
mcpOpenClawCheck($listToolsResult['id'] === 1 && $listToolsResult['jsonrpc'] === '2.0', 'the response must echo back the real JSON-RPC id and version, so a client can correlate it with its own request');
mcpOpenClawCheck(count($listToolsResult['result']['tools']) === 1 && $listToolsResult['result']['tools'][0]['name'] === 'journal.get_profile', 'tools/list must advertise the real registered tool');

// ================================================================
// 2. tools/call — the client then invokes the tool it discovered, using
//    only the name/arguments shape tools/list told it about.
// ================================================================
$callToolBody = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => McpProtocol::METHOD_TOOLS_CALL, 'params' => ['name' => 'journal.get_profile', 'arguments' => []]]);
$callToolResult = mcpOpenClawDispatch($dispatcher, $callToolBody);
mcpOpenClawCheck(!isset($callToolResult['error']), 'a real generic MCP client must be able to call an advertised tool');
mcpOpenClawCheck($callToolResult['result']['content'] === ['journal.name' => 'A Safe Journal'], 'tools/call must return the real tool output over the wire, unmodified');

// ================================================================
// 3. An unknown tool name must fail safely with a specific, correlatable
//    error — a real client relies on this to distinguish "not found" from
//    a transport-level failure.
// ================================================================
$callUnknownBody = json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => McpProtocol::METHOD_TOOLS_CALL, 'params' => ['name' => 'does.not.exist', 'arguments' => []]]);
$callUnknownResult = mcpOpenClawDispatch($dispatcher, $callUnknownBody);
mcpOpenClawCheck(($callUnknownResult['error']['code'] ?? null) === McpErrorCode::UNKNOWN_TOOL_OR_RESOURCE, 'calling an unadvertised tool must fail with UNKNOWN_TOOL_OR_RESOURCE, never a generic/opaque failure a client cannot act on');
mcpOpenClawCheck($callUnknownResult['id'] === 3, 'even an error response must echo back the real request id, so a client can correlate it');

// ================================================================
// 4. resources/list and resources/read — the same round trip for the
//    resource surface (MCP-004).
// ================================================================
$listResourcesBody = json_encode(['jsonrpc' => '2.0', 'id' => 4, 'method' => McpProtocol::METHOD_RESOURCES_LIST, 'params' => []]);
$listResourcesResult = mcpOpenClawDispatch($dispatcher, $listResourcesBody);
mcpOpenClawCheck(count($listResourcesResult['result']['resources']) === 1 && $listResourcesResult['result']['resources'][0]['uri'] === 'ojs://journal/7/support-profile', 'resources/list must advertise the real registered resource');

$readResourceBody = json_encode(['jsonrpc' => '2.0', 'id' => 5, 'method' => McpProtocol::METHOD_RESOURCES_READ, 'params' => ['uri' => 'ojs://journal/7/support-profile']]);
$readResourceResult = mcpOpenClawDispatch($dispatcher, $readResourceBody);
mcpOpenClawCheck(json_decode($readResourceResult['result']['contents'][0]['text'], true) === ['journal.name' => 'A Safe Journal'], 'resources/read must return the real resource content over the wire, unmodified');

// ================================================================
// 5. A wrong/missing protocol revision must fail deterministically before
//    a client ever reaches a handler — a generic client that gets this
//    wrong must get a clear, specific signal, not a silent best-effort
//    downgrade.
// ================================================================
$wrongRevisionBody = json_encode(['jsonrpc' => '2.0', 'id' => 6, 'method' => McpProtocol::METHOD_TOOLS_LIST, 'params' => ['protocolVersion' => '1999-01-01']]);
$wrongRevisionParsed = McpRequestParser::parse($wrongRevisionBody, []);
mcpOpenClawCheck($wrongRevisionParsed instanceof McpResponse, 'a client on the wrong protocol revision must be rejected before ever reaching the dispatcher');
$wrongRevisionResult = $wrongRevisionParsed->toArray();
mcpOpenClawCheck(($wrongRevisionResult['error']['code'] ?? null) === McpErrorCode::UNSUPPORTED_PROTOCOL_VERSION, 'a client on the wrong protocol revision must fail with UNSUPPORTED_PROTOCOL_VERSION, never be silently routed');

fwrite(STDOUT, "MCP OpenClaw-style generic-client integration tests passed\n");
