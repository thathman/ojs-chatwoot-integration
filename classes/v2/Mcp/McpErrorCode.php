<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp;

/**
 * Standard JSON-RPC 2.0 error codes (the wire format every MCP HTTP
 * request/response body this build parses is framed in), plus the
 * MCP-specific codes this layer itself needs. Never a bespoke ad hoc
 * string — a client-facing error must always be one of these, so a
 * caller can branch on `code` reliably.
 */
final class McpErrorCode
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /** MCP-specific: the request named a protocol revision this server does not implement. */
    public const UNSUPPORTED_PROTOCOL_VERSION = -32001;

    /** MCP-specific: `tools/call`/`resources/read` named a tool/resource this registry does not know. */
    public const UNKNOWN_TOOL_OR_RESOURCE = -32002;

    /** MCP-specific: the caller's credential does not authorize this call. */
    public const UNAUTHORIZED = -32003;

    private function __construct()
    {
    }
}
