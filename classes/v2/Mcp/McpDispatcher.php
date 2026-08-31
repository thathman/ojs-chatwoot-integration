<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp;

/**
 * Routes one well-formed McpRequest to its registered handler and always
 * returns an McpResponse — never lets a handler's return value or a thrown
 * exception escape unwrapped. A method outside McpProtocol::SUPPORTED_METHODS
 * is rejected before handler lookup even runs, so this server's advertised
 * method surface (MCP-001's whole point) can never silently grow just
 * because something registered a handler for it.
 */
final class McpDispatcher
{
    /** @var array<string,callable(McpRequest):mixed> */
    private array $handlers = [];

    /** @param callable(McpRequest):mixed $handler Must return an already-safe, allowlist-serialized result. */
    public function registerHandler(string $method, callable $handler): void
    {
        if (!in_array($method, McpProtocol::SUPPORTED_METHODS, true)) {
            throw new \InvalidArgumentException("Refusing to register a handler for unsupported method \"{$method}\" — add it to McpProtocol::SUPPORTED_METHODS first if it's genuinely needed.");
        }
        $this->handlers[$method] = $handler;
    }

    public function dispatch(McpRequest $request): McpResponse
    {
        if (!in_array($request->method(), McpProtocol::SUPPORTED_METHODS, true)) {
            return McpResponse::error($request->id(), McpErrorCode::METHOD_NOT_FOUND, "Unknown method \"{$request->method()}\".");
        }

        $handler = $this->handlers[$request->method()] ?? null;
        if ($handler === null) {
            return McpResponse::error($request->id(), McpErrorCode::METHOD_NOT_FOUND, "Method \"{$request->method()}\" is not currently available.");
        }

        try {
            $result = $handler($request);
        } catch (\Throwable $e) {
            // Never leak an internal exception message to an MCP client —
            // same principle as every REST endpoint's generic 500 shape.
            return McpResponse::error($request->id(), McpErrorCode::INTERNAL_ERROR, 'The request could not be completed.');
        }

        return McpResponse::success($request->id(), $result);
    }
}
