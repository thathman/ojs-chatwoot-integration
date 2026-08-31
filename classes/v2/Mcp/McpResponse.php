<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp;

/**
 * JSON-RPC 2.0-shaped success/error response. `result` is never a raw OJS
 * object or an unfiltered array from deeper in the stack — every caller
 * building one is responsible for having already passed the result
 * through the same allowlist serializers REST uses (MCP-006/011).
 */
final class McpResponse
{
    private function __construct(
        private string|int|null $id,
        private mixed $result,
        private ?array $error
    ) {
    }

    public static function success(string|int|null $id, mixed $result): self
    {
        return new self($id, $result, null);
    }

    public static function error(string|int|null $id, int $code, string $message): self
    {
        return new self($id, null, ['code' => $code, 'message' => $message]);
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    public function errorCode(): ?int
    {
        return $this->error['code'] ?? null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $payload = ['jsonrpc' => '2.0', 'id' => $this->id];
        if ($this->error !== null) {
            $payload['error'] = $this->error;
        } else {
            $payload['result'] = $this->result;
        }
        return $payload;
    }
}
