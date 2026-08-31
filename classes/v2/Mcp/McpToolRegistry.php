<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp;

/**
 * MCP-003: the public tool registry. Deliberately built fresh per request
 * (like KnowledgeCompiler's provider registration in
 * SupportGatewayKernel), never a process-wide singleton, since a tool
 * handler closure captures live, per-journal, per-request state (a
 * KnowledgeCompilation, a resolved identity, ...).
 *
 * Registration never exposes a tool's handler through `list()` — only the
 * name/description/inputSchema a client needs to decide whether/how to
 * call it (MCP-011: never leak implementation details through the
 * advertised tool surface).
 */
final class McpToolRegistry
{
    /** @var array<string,array{description:string,inputSchema:array<string,mixed>,handler:callable(array<string,mixed>):mixed}> */
    private array $tools = [];

    /** @param callable(array<string,mixed>):mixed $handler Must return an already-safe, allowlist-shaped result — never a raw OJS object. */
    public function register(string $name, string $description, array $inputSchema, callable $handler): void
    {
        $this->tools[$name] = ['description' => $description, 'inputSchema' => $inputSchema, 'handler' => $handler];
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** @return array<int,array{name:string,description:string,inputSchema:array<string,mixed>}> */
    public function list(): array
    {
        $result = [];
        foreach ($this->tools as $name => $tool) {
            $result[] = ['name' => $name, 'description' => $tool['description'], 'inputSchema' => $tool['inputSchema']];
        }
        return $result;
    }

    /** @param array<string,mixed> $arguments */
    public function call(string $name, array $arguments): mixed
    {
        if (!$this->has($name)) {
            throw new McpHandlerError(McpErrorCode::UNKNOWN_TOOL_OR_RESOURCE, "Unknown tool \"{$name}\".");
        }
        return ($this->tools[$name]['handler'])($arguments);
    }
}
