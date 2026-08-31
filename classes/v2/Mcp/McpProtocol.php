<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp;

/**
 * MCP-001 (docs/v2/ADRS.md ADR-023): the one supported MCP protocol
 * revision and the method surface this build implements. Stateless
 * Streamable HTTP — no `initialize`/`initialized` handshake, no
 * `Mcp-Session-Id`; every request is self-describing.
 *
 * A client requesting any other revision, or calling a method outside
 * `SUPPORTED_METHODS`, fails deterministically (McpErrorCode::
 * UNSUPPORTED_PROTOCOL_VERSION / METHOD_NOT_FOUND) — never silently
 * downgraded or best-effort routed.
 */
final class McpProtocol
{
    public const REVISION = '2026-07-28';

    public const METHOD_DISCOVER = 'server/discover';
    public const METHOD_TOOLS_LIST = 'tools/list';
    public const METHOD_TOOLS_CALL = 'tools/call';
    public const METHOD_RESOURCES_LIST = 'resources/list';
    public const METHOD_RESOURCES_READ = 'resources/read';

    public const SUPPORTED_METHODS = [
        self::METHOD_DISCOVER,
        self::METHOD_TOOLS_LIST,
        self::METHOD_TOOLS_CALL,
        self::METHOD_RESOURCES_LIST,
        self::METHOD_RESOURCES_READ,
    ];

    private function __construct()
    {
    }
}
