<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpDispatcher;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpErrorCode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpProtocol;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpRequest;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpRequestParser;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpResponse;

function mcpProtocolCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$header = ['Mcp-Protocol-Version' => McpProtocol::REVISION];

// ================================================================
// MCP-001 test requirement 2: malformed JSON fails safely.
// ================================================================
$malformed = McpRequestParser::parse('{not valid json', $header);
mcpProtocolCheck($malformed instanceof McpResponse && $malformed->isError(), 'malformed JSON must produce an error response, never throw or partially parse');
mcpProtocolCheck($malformed->errorCode() === McpErrorCode::PARSE_ERROR, 'malformed JSON must produce PARSE_ERROR specifically');

$notAnObject = McpRequestParser::parse('"just a string"', $header);
mcpProtocolCheck($notAnObject instanceof McpResponse && $notAnObject->errorCode() === McpErrorCode::PARSE_ERROR, 'valid JSON that is not an object/array must still fail as a parse error, never be guessed into a request');

$missingMethod = McpRequestParser::parse(json_encode(['id' => 1]), $header);
mcpProtocolCheck($missingMethod instanceof McpResponse && $missingMethod->errorCode() === McpErrorCode::INVALID_REQUEST, 'a request with no method must fail as INVALID_REQUEST');

$emptyMethod = McpRequestParser::parse(json_encode(['id' => 1, 'method' => '']), $header);
mcpProtocolCheck($emptyMethod instanceof McpResponse && $emptyMethod->errorCode() === McpErrorCode::INVALID_REQUEST, 'an empty-string method must fail as INVALID_REQUEST, never be treated as a real method name');

$badId = McpRequestParser::parse(json_encode(['id' => ['not' => 'scalar'], 'method' => 'tools/list']), $header);
mcpProtocolCheck($badId instanceof McpResponse && $badId->errorCode() === McpErrorCode::INVALID_REQUEST, 'a non-scalar id must fail as INVALID_REQUEST');

$badParams = McpRequestParser::parse(json_encode(['method' => 'tools/list', 'params' => 'not-an-array']), $header);
mcpProtocolCheck($badParams instanceof McpResponse && $badParams->errorCode() === McpErrorCode::INVALID_PARAMS, 'non-array params must fail as INVALID_PARAMS');

// ================================================================
// MCP-001 test requirement 3: unsupported protocol revision fails
// deterministically.
// ================================================================
$noVersion = McpRequestParser::parse(json_encode(['method' => 'tools/list']), []);
mcpProtocolCheck($noVersion instanceof McpResponse && $noVersion->errorCode() === McpErrorCode::UNSUPPORTED_PROTOCOL_VERSION, 'a request declaring no protocol revision at all must fail deterministically, never silently default to one');

$wrongVersion = McpRequestParser::parse(json_encode(['method' => 'tools/list']), ['Mcp-Protocol-Version' => '2024-01-01']);
mcpProtocolCheck($wrongVersion instanceof McpResponse && $wrongVersion->errorCode() === McpErrorCode::UNSUPPORTED_PROTOCOL_VERSION, 'an old/wrong protocol revision must fail deterministically, never be silently accepted or downgraded');

$versionViaParams = McpRequestParser::parse(json_encode(['method' => 'tools/list', 'params' => ['protocolVersion' => McpProtocol::REVISION]]), []);
mcpProtocolCheck($versionViaParams instanceof McpRequest, 'the protocol revision may be declared via params.protocolVersion when no header is present (each request is self-describing)');

// ================================================================
// A well-formed request parses into a real McpRequest carrying exactly
// what was sent.
// ================================================================
$wellFormed = McpRequestParser::parse(json_encode(['id' => 'req-1', 'method' => McpProtocol::METHOD_TOOLS_LIST, 'params' => ['cursor' => null]]), $header);
mcpProtocolCheck($wellFormed instanceof McpRequest, 'a well-formed request must parse into a real McpRequest');
mcpProtocolCheck($wellFormed->method() === McpProtocol::METHOD_TOOLS_LIST, 'the parsed method must match exactly what was sent');
mcpProtocolCheck($wellFormed->id() === 'req-1', 'the parsed id must match exactly what was sent');
mcpProtocolCheck($wellFormed->protocolVersion() === McpProtocol::REVISION, 'the parsed protocol version must match the declared header');

// ================================================================
// MCP-001 test requirement 4: unknown method fails safely.
// ================================================================
$dispatcher = new McpDispatcher();
$dispatcher->registerHandler(McpProtocol::METHOD_TOOLS_LIST, fn (McpRequest $r) => ['tools' => []]);

$unknownMethodRequest = new McpRequest('totally/unknown/method', [], 'x', McpProtocol::REVISION);
$unknownResponse = $dispatcher->dispatch($unknownMethodRequest);
mcpProtocolCheck($unknownResponse->isError() && $unknownResponse->errorCode() === McpErrorCode::METHOD_NOT_FOUND, 'dispatching an unsupported method must fail as METHOD_NOT_FOUND, never be routed anywhere');

$unregisteredButSupported = new McpRequest(McpProtocol::METHOD_RESOURCES_READ, [], 'y', McpProtocol::REVISION);
$unregisteredResponse = $dispatcher->dispatch($unregisteredButSupported);
mcpProtocolCheck($unregisteredResponse->isError() && $unregisteredResponse->errorCode() === McpErrorCode::METHOD_NOT_FOUND, 'a supported method with no registered handler yet must still fail safely as METHOD_NOT_FOUND, never a fatal error');

// registerHandler() must itself refuse an unsupported method name, so the
// advertised method surface can never silently grow.
$refused = false;
try {
    $dispatcher->registerHandler('made/up/method', fn () => null);
} catch (\InvalidArgumentException $e) {
    $refused = true;
}
mcpProtocolCheck($refused, 'registerHandler() must refuse to register a handler for a method outside McpProtocol::SUPPORTED_METHODS');

// A handler that throws must never leak its exception message to the client.
$dispatcher->registerHandler(McpProtocol::METHOD_RESOURCES_LIST, function (McpRequest $r): array {
    throw new \RuntimeException('some internal DB connection string or stack detail');
});
$thrownResponse = $dispatcher->dispatch(new McpRequest(McpProtocol::METHOD_RESOURCES_LIST, [], 'z', McpProtocol::REVISION));
mcpProtocolCheck($thrownResponse->isError() && $thrownResponse->errorCode() === McpErrorCode::INTERNAL_ERROR, 'a throwing handler must produce a generic INTERNAL_ERROR response');
mcpProtocolCheck(!str_contains(json_encode($thrownResponse->toArray()), 'DB connection string'), 'the thrown exception\'s real message must never leak into the client-facing response');

// A registered, successful handler must round-trip its result untouched
// inside the JSON-RPC success envelope.
$successResponse = $dispatcher->dispatch(new McpRequest(McpProtocol::METHOD_TOOLS_LIST, [], 42, McpProtocol::REVISION));
$successArray = $successResponse->toArray();
mcpProtocolCheck(!$successResponse->isError(), 'a successful handler call must not be reported as an error');
mcpProtocolCheck($successArray['id'] === 42 && $successArray['jsonrpc'] === '2.0' && $successArray['result'] === ['tools' => []], 'a successful response must carry the real id, jsonrpc version, and the handler\'s own result verbatim');
mcpProtocolCheck(!array_key_exists('error', $successArray), 'a successful response must never also carry an error key');

fwrite(STDOUT, "MCP protocol tests passed\n");
