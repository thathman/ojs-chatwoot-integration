<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp;

/**
 * A handler throws this to report a specific, deterministic MCP error
 * (e.g. McpErrorCode::UNKNOWN_TOOL_OR_RESOURCE) — McpDispatcher preserves
 * the code/message. Any other exception a handler throws is treated as an
 * unexpected internal failure and collapses to the generic
 * McpErrorCode::INTERNAL_ERROR, never leaking its own message.
 */
final class McpHandlerError extends \RuntimeException
{
    public function __construct(private int $mcpErrorCode, string $message)
    {
        parent::__construct($message);
    }

    public function mcpErrorCode(): int
    {
        return $this->mcpErrorCode;
    }
}
