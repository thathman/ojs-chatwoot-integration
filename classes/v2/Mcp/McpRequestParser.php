<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp;

/**
 * Parses one raw stateless Streamable HTTP request body (+ headers) into a
 * well-formed McpRequest, or an McpResponse::error() describing exactly
 * why it could not — malformed JSON, a non-JSON-RPC shape, a missing/
 * unsupported protocol revision, or wrong-typed fields must all fail
 * safely and deterministically, never partially construct a request or
 * guess a default for a missing required field.
 */
final class McpRequestParser
{
    /**
     * @param array<string,string> $headers Header names are matched
     *                                       case-insensitively by the caller
     *                                       (this class expects them
     *                                       already normalized to the exact
     *                                       keys it looks up).
     */
    public static function parse(string $rawBody, array $headers = []): McpRequest|McpResponse
    {
        $decoded = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return McpResponse::error(null, McpErrorCode::PARSE_ERROR, 'Request body is not valid JSON.');
        }

        $id = $decoded['id'] ?? null;
        if ($id !== null && !is_string($id) && !is_int($id)) {
            return McpResponse::error(null, McpErrorCode::INVALID_REQUEST, 'id must be a string, an integer, or absent.');
        }

        $method = $decoded['method'] ?? null;
        if (!is_string($method) || $method === '') {
            return McpResponse::error($id, McpErrorCode::INVALID_REQUEST, 'method must be a non-empty string.');
        }

        $params = $decoded['params'] ?? [];
        if (!is_array($params)) {
            return McpResponse::error($id, McpErrorCode::INVALID_PARAMS, 'params must be an object/array when present.');
        }

        $protocolVersion = $headers['Mcp-Protocol-Version'] ?? $params['protocolVersion'] ?? null;
        if (!is_string($protocolVersion) || $protocolVersion === '') {
            return McpResponse::error($id, McpErrorCode::UNSUPPORTED_PROTOCOL_VERSION, 'A protocol revision must be declared (Mcp-Protocol-Version header or params.protocolVersion).');
        }
        if ($protocolVersion !== McpProtocol::REVISION) {
            return McpResponse::error($id, McpErrorCode::UNSUPPORTED_PROTOCOL_VERSION, "Unsupported MCP protocol revision \"{$protocolVersion}\"; this server implements \"" . McpProtocol::REVISION . '".');
        }

        return new McpRequest($method, $params, $id, $protocolVersion);
    }
}
