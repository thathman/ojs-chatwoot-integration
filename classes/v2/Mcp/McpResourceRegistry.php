<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp;

/**
 * MCP-004: the public resource registry, mirroring McpToolRegistry's shape
 * exactly. Built fresh per request for the same reason (a resource's
 * content handler closes over a live, per-journal KnowledgeCompilation).
 *
 * `list()` only ever exposes uri/name/description/mimeType — never a
 * resource's content or handler (same MCP-011 non-leak guarantee
 * McpToolRegistry::list() already gives tools).
 */
final class McpResourceRegistry
{
    /** @var array<string,array{name:string,description:string,mimeType:string,handler:callable():array<string,mixed>}> */
    private array $resources = [];

    /** @param callable():array<string,mixed> $handler Must return an already-safe, allowlist-shaped result — never a raw OJS object. */
    public function register(string $uri, string $name, string $description, string $mimeType, callable $handler): void
    {
        $this->resources[$uri] = ['name' => $name, 'description' => $description, 'mimeType' => $mimeType, 'handler' => $handler];
    }

    public function has(string $uri): bool
    {
        return isset($this->resources[$uri]);
    }

    /** @return array<int,array{uri:string,name:string,description:string,mimeType:string}> */
    public function list(): array
    {
        $result = [];
        foreach ($this->resources as $uri => $resource) {
            $result[] = ['uri' => $uri, 'name' => $resource['name'], 'description' => $resource['description'], 'mimeType' => $resource['mimeType']];
        }
        return $result;
    }

    /** @return array{uri:string,mimeType:string,text:string} */
    public function read(string $uri): array
    {
        if (!$this->has($uri)) {
            throw new McpHandlerError(McpErrorCode::UNKNOWN_TOOL_OR_RESOURCE, "Unknown resource \"{$uri}\".");
        }

        $resource = $this->resources[$uri];
        $content = ($resource['handler'])();

        return ['uri' => $uri, 'mimeType' => $resource['mimeType'], 'text' => json_encode($content, JSON_UNESCAPED_SLASHES)];
    }
}
