<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp;

/**
 * One parsed, well-formed MCP request. Reaching this object at all already
 * proves the raw body was valid JSON-RPC 2.0 shape with a string `method`
 * — McpRequestParser is solely responsible for that; this class never
 * re-validates it.
 */
final class McpRequest
{
    /** @param array<string,mixed> $params */
    public function __construct(
        private string $method,
        private array $params,
        private string|int|null $id,
        private string $protocolVersion
    ) {
    }

    public function method(): string
    {
        return $this->method;
    }

    /** @return array<string,mixed> */
    public function params(): array
    {
        return $this->params;
    }

    public function id(): string|int|null
    {
        return $this->id;
    }

    public function protocolVersion(): string
    {
        return $this->protocolVersion;
    }
}
